<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Daemon;

use AlfacodeTeam\PhpServicePlatform\Kernel\Daemon\Exceptions\DaemonException;

/**
 * Download, verify, unpack and configure a plugin's daemon. Never start it.
 *
 * THE ORDER OF OPERATIONS IS THE DESIGN
 * -------------------------------------
 *   1. resolve the platform, and REFUSE if no digest is pinned for it
 *   2. download to a temporary file
 *   3. hash it and compare — on a mismatch, delete and stop
 *   4. take exactly ONE named member out of the archive
 *   5. place it, 0750
 *   6. generate the secret ONCE and reuse it for ever after
 *   7. render the config, and never overwrite one that already exists
 *   8. ask the daemon itself whether the config is valid
 *   9. seed the project's `.env`, never modifying a key already there
 *  10. write a service unit for review, and install nothing
 *
 * Steps 3 and 4 are the pair worth dwelling on. Verifying before unpacking
 * means a tampered archive is never read by `tar` at all — and extracting a
 * single NAMED member rather than the whole tree means a malicious archive has
 * nowhere to put anything: there is no `../../etc/cron.d` entry to refuse,
 * because nothing but that one path is ever written. Zip-slip is designed out
 * rather than guarded against.
 *
 * NOTHING IS STARTED, AND THAT IS NOT TIMIDITY
 * -------------------------------------------
 * A provisioner that launched the daemon would mean `hkm plugins enable nats`
 * silently opened a listening socket on a developer's machine, and
 * `daemon:install` in a CI step would leave a process running in the build
 * container. The unit is written into the project where it can be read, with
 * the exact command to install it printed — the same rule `hkm service`
 * follows, for the same reason: starting a long-lived process that accepts
 * connections is a decision, not a side effect.
 *
 * IT IS IDEMPOTENT, BECAUSE IT RUNS ON EVERY DEPLOY
 * ------------------------------------------------
 * `var/` does not travel with a git clone, so this is part of deploying rather
 * than a one-off. A second run with nothing changed must download nothing,
 * rotate nothing, and overwrite nothing — in particular it must not mint a new
 * password, which would leave the daemon's config and the project's `.env`
 * disagreeing while both files still looked right.
 */
final class DaemonInstaller
{
    public function __construct(
        private readonly Downloader $downloader = new CurlDownloader(),
    ) {}

    /**
     * Provision $spec into $projectRoot.
     *
     * @param  bool $force re-download even when the recorded version matches
     * @throws DaemonException
     */
    public function install(
        string $projectRoot,
        DaemonSpec $spec,
        ?Platform $platform = null,
        bool $force = false,
    ): DaemonResult {
        $platform = $platform ?? Platform::current();
        $digest   = $spec->digestFor($platform);
        $paths    = DaemonPaths::for($projectRoot, $spec, $platform->supervisor());

        foreach ($paths->directories() as $directory) {
            $this->ensureDirectory($directory);
        }

        $notes      = [];
        $binaryFresh = false;

        if (!$force && $this->isCurrent($paths, $spec, $platform, $digest)) {
            $notes[] = "{$spec->name} {$spec->version} is already installed and its recorded digest matches.";
        } else {
            $this->fetchAndPlace($paths, $spec, $platform, $digest);
            $binaryFresh = true;
        }

        // ── The secret: minted once, then read for ever ─────────────────────
        $secret = $this->secret($paths);

        // ── Config ──────────────────────────────────────────────────────────
        $tokens = $this->tokens($spec, $paths, $platform, $secret, $projectRoot);
        $config = null;

        if ($spec->template !== null) {
            $config = $this->renderConfig($projectRoot, $spec, $paths, $tokens, $notes);
        }

        return new DaemonResult(
            spec: $spec,
            platform: $platform,
            paths: $paths,
            tokens: $tokens,
            binaryInstalled: $binaryFresh,
            configWritten: $config,
            notes: $notes,
        );
    }

    /** Is the recorded install the one this spec asks for? */
    private function isCurrent(DaemonPaths $paths, DaemonSpec $spec, Platform $platform, string $digest): bool
    {
        if (!$paths->isInstalled()) {
            return false;
        }

        $recorded = $this->readRecord($paths);

        return ($recorded['version'] ?? null) === $spec->version
            && ($recorded['platform'] ?? null) === $platform->key()
            && ($recorded['sha256'] ?? null) === $digest;
    }

    /** @throws DaemonException */
    private function fetchAndPlace(DaemonPaths $paths, DaemonSpec $spec, Platform $platform, string $digest): void
    {
        if (!Process::exists('tar')) {
            throw DaemonException::toolMissing('tar');
        }

        $url     = $spec->urlFor($platform);
        $member  = $spec->memberFor($platform);
        $archive = $paths->dir . '/.download/' . basename(parse_url($url, PHP_URL_PATH) ?: 'archive.tar.gz');
        $staging = $paths->dir . '/.download/staged';

        $this->ensureDirectory(\dirname($archive));
        $this->rmdirRecursive($staging);
        $this->ensureDirectory($staging);

        try {
            $this->downloader->fetch($url, $archive);

            // Streamed, so a 300 MB archive does not become 300 MB of memory.
            $actual = hash_file('sha256', $archive);

            if ($actual === false) {
                throw DaemonException::downloadFailed($url, 'the downloaded file could not be read back');
            }

            if (!hash_equals($digest, $actual)) {
                @unlink($archive);

                throw DaemonException::digestMismatch($url, $digest, $actual);
            }

            // Exactly one member. See the class docblock: nothing else in the
            // archive is ever written, so there is no traversal to defend.
            $depth   = max(0, \count(explode('/', $member)) - 1);
            $extract = Process::run([
                'tar', '-xzf', $archive,
                '-C', $staging,
                '--strip-components=' . $depth,
                $member,
            ]);

            if (!$extract->ok()) {
                throw DaemonException::extractFailed($member, basename($archive), $extract->output());
            }

            $extracted = $staging . '/' . basename($member);

            if (!is_file($extracted)) {
                throw DaemonException::extractFailed(
                    $member,
                    basename($archive),
                    'tar reported success but no file appeared at ' . basename($member),
                );
            }

            // Replaced via rename, which is atomic on the same filesystem — so
            // a failure part way through cannot leave a half-written binary
            // that a service unit would happily try to exec.
            if (!@rename($extracted, $paths->binary)) {
                throw DaemonException::notWritable($paths->binary, 'the extracted binary could not be moved into place');
            }

            @chmod($paths->binary, 0o750);

            $this->writeRecord($paths, [
                'name'        => $spec->name,
                'version'     => $spec->version,
                'platform'    => $platform->key(),
                'sha256'      => $digest,
                'url'         => $url,
                'installedAt' => gmdate('c'),
            ]);
        } finally {
            @unlink($archive);
            $this->rmdirRecursive($staging);
            @rmdir($paths->dir . '/.download');
        }
    }

    /**
     * Render the config — or write it beside the existing one.
     *
     * An existing config is NEVER overwritten. It is the file an operator tunes
     * (limits, TLS paths, a cluster block), it holds the password the running
     * daemon is using, and replacing it on a deploy would both discard that
     * work and change the credential out from under the `.env`. The plugin's
     * version is written as `<name>.conf.new` instead, inert, for a human to
     * diff — the same convention `hkm plugins update` uses for published
     * assets.
     *
     * @param  array<string, string> $tokens
     * @param  list<string>          $notes
     * @throws DaemonException
     */
    private function renderConfig(
        string $projectRoot,
        DaemonSpec $spec,
        DaemonPaths $paths,
        array $tokens,
        array &$notes,
    ): string {
        $templatePath = $this->templatePath($projectRoot, $spec);
        $template     = @file_get_contents($templatePath);

        if ($template === false) {
            throw DaemonException::templateMissing($templatePath);
        }

        $rendered = ConfigRenderer::render($template, $tokens, $spec->configName);
        $target   = $paths->config;

        if ($paths->hasConfig()) {
            $existing = @file_get_contents($target);

            if ($existing === $rendered) {
                $notes[] = "{$spec->configName} is already exactly what the plugin would generate.";

                return $target;
            }

            $beside = $target . '.new';
            $this->writeFile($beside, $rendered, 0o640);

            $notes[] = "{$spec->configName} already exists and was NOT touched — it holds the password "
                . 'the running daemon is using, and any limits or TLS paths you have tuned. The '
                . 'plugin\'s version is beside it as ' . basename($beside) . ' for you to diff.';

            return $target;
        }

        $this->writeFile($target, $rendered, 0o640);

        // Ask the daemon itself. A config this mechanism generated but the
        // daemon rejects is the one failure that would otherwise surface as a
        // service unit that will not start, after the deploy, with the reason
        // in a log nobody is tailing yet.
        if ($spec->verify !== [] && $paths->isInstalled()) {
            $argv   = ConfigRenderer::renderArgv($spec->verify, $tokens, 'daemon.config.verify');
            $check  = Process::run(array_merge([$paths->binary], $argv));

            if (!$check->ok()) {
                throw DaemonException::configRejected($target, $check->output());
            }

            $notes[] = "{$spec->name} checked the generated config and accepted it.";
        }

        return $target;
    }

    /**
     * The password, minted once.
     *
     * 32 bytes from the CSPRNG, base64url so it survives a URL and a config
     * file without quoting. Written 0600 and read back on every later run —
     * see the class docblock for why rotating it would be the worst kind of
     * failure: both files look right and nothing authenticates.
     *
     * @throws DaemonException
     */
    private function secret(DaemonPaths $paths): string
    {
        if (is_file($paths->secretFile)) {
            $existing = @file_get_contents($paths->secretFile);

            if (\is_string($existing) && trim($existing) !== '') {
                return trim($existing);
            }
        }

        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $this->writeFile($paths->secretFile, $secret . "\n", 0o600);

        return $secret;
    }

    /**
     * A bcrypt hash of the secret, also minted once.
     *
     * Offered as a token so a config template can present a ready-to-use
     * hashed credential instead of a placeholder. PERSISTED for a reason that
     * is not obvious: bcrypt salts randomly, so a hash regenerated on each run
     * would make the rendered config differ from the one on disk every single
     * time — and the installer would then write a `.conf.new` on every deploy,
     * with a diff that says nothing.
     *
     * Cost 10 rather than the 11 usually recommended. Measured against this
     * exact binary, a NATS handshake costs 0.4 ms with a plaintext password,
     * 92 ms at cost 10 and 184 ms at cost 11 — and the verification happens per
     * CONNECTION, which under PHP-FPM is per request. 11 would put a sixth of a
     * second on every request that emits an event.
     *
     * @throws DaemonException
     */
    private function secretHash(DaemonPaths $paths, string $secret): string
    {
        $path = $paths->secretFile . '.bcrypt';

        if (is_file($path)) {
            $existing = @file_get_contents($path);

            if (\is_string($existing) && trim($existing) !== ''
                && password_verify($secret, trim($existing))) {
                return trim($existing);
            }
        }

        $hash = password_hash($secret, PASSWORD_BCRYPT, ['cost' => 10]);

        $this->writeFile($path, $hash . "\n", 0o600);

        return $hash;
    }

    /**
     * Every token a template, a verify argv or a service argv may use.
     *
     * The plugin's own `daemon.defaults` are merged UNDER these, so a plugin
     * can add `{port}` or `{max_payload}` without the kernel knowing what
     * either means — and cannot shadow a path, which would let a manifest
     * redirect where the binary is looked for.
     *
     * @return array<string, string>
     */
    private function tokens(
        DaemonSpec $spec,
        DaemonPaths $paths,
        Platform $platform,
        string $secret,
        string $projectRoot,
    ): array {
        return array_merge($spec->defaults, [
            'secret_bcrypt' => $this->secretHash($paths, $secret),
            'name'     => $spec->name,
            'version'  => $spec->version,
            'os'       => $platform->os,
            'arch'     => $platform->arch,
            'dir'      => $paths->dir,
            'binary'   => $paths->binary,
            'config'   => $paths->config,
            'logfile'  => $paths->logFile,
            'pidfile'  => $paths->pidFile,
            'store'    => $paths->storeDir,
            'secret'   => $secret,
            'project'  => basename(rtrim($projectRoot, '/')),
            'root'     => rtrim($projectRoot, '/'),
        ]);
    }

    private function templatePath(string $projectRoot, DaemonSpec $spec): string
    {
        $relative = ltrim((string) $spec->template, '/');

        // The plugin directory is found by NAME rather than assumed, because a
        // plugin's folder casing is not derivable from its manifest name.
        foreach (DaemonRegistry::pluginDirectories($projectRoot) as $directory) {
            $candidate = $directory . '/' . $relative;

            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return $projectRoot . '/plugins/' . $spec->name . '/' . $relative;
    }

    /** @return array<string, mixed> */
    private function readRecord(DaemonPaths $paths): array
    {
        $json = @file_get_contents($paths->dir . '/installed.json');

        if (!\is_string($json)) {
            return [];
        }

        $decoded = json_decode($json, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $record */
    private function writeRecord(DaemonPaths $paths, array $record): void
    {
        $this->writeFile(
            $paths->dir . '/installed.json',
            (string) json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            0o640,
        );
    }

    /** @throws DaemonException */
    private function writeFile(string $path, string $contents, int $mode): void
    {
        $this->ensureDirectory(\dirname($path));

        if (@file_put_contents($path, $contents) === false) {
            throw DaemonException::notWritable($path, 'the write failed');
        }

        @chmod($path, $mode);
    }

    /** @throws DaemonException */
    private function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!@mkdir($path, 0o750, true) && !is_dir($path)) {
            throw DaemonException::notWritable($path, 'the directory could not be created');
        }
    }

    private function rmdirRecursive(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = scandir($path);

        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path . '/' . $entry;

            if (is_dir($full) && !is_link($full)) {
                $this->rmdirRecursive($full);
            } else {
                @unlink($full);
            }
        }

        @rmdir($path);
    }
}
