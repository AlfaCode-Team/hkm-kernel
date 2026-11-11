<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Commands\Daemon;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use AlfacodeTeam\PhpServicePlatform\Kernel\Daemon\DaemonPaths;
use AlfacodeTeam\PhpServicePlatform\Kernel\Daemon\DaemonRegistry;
use AlfacodeTeam\PhpServicePlatform\Kernel\Daemon\Exceptions\DaemonException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Daemon\Platform;
use AlfacodeTeam\PhpServicePlatform\Kernel\Daemon\Process;
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;

/**
 * `daemon:status` — what is provisioned, and whether it matches what is pinned.
 *
 * The question this answers is the one a deploy needs: does the binary on disk
 * correspond to the version this commit declares? A provisioned daemon that is
 * three versions behind looks completely healthy from the outside, because it
 * IS healthy — it is just not the one the code was tested against.
 *
 * It states plainly what it cannot see. Whether the daemon is RUNNING is a
 * supervisor question, and asking launchd or systemd would mean guessing which
 * scope the unit was installed into — so it reports the command to ask instead
 * of a tick that might be wrong.
 */
final class DaemonStatusCommand extends AbstractCommand
{
    protected function configure(): void
    {
        $this->name        = 'daemon:status';
        $this->description = 'Report which declared daemons are provisioned, and at what version';
    }

    protected function handle(): int
    {
        $root = rtrim(Paths::base(), '/');

        try {
            $declared = DaemonRegistry::all($root);
        } catch (DaemonException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->section('Daemons');

        if ($declared === []) {
            $this->muted('No installed plugin declares a `daemon` block.');

            return self::SUCCESS;
        }

        $platform = null;
        $supervisor = 'systemd';

        try {
            $platform   = Platform::current();
            $supervisor = $platform->supervisor();
            $this->muted("Platform : {$platform->key()} ({$supervisor})");
        } catch (DaemonException $e) {
            $this->warning($e->getMessage());
        }

        $this->newLine();

        $problems = 0;

        foreach ($declared as $plugin => $entry) {
            $spec  = $entry['spec'];
            $paths = DaemonPaths::for($root, $spec, $supervisor);

            $this->info("{$plugin} → {$spec->name}");
            $this->muted('  declared  ' . $spec->version);

            if (!$paths->isInstalled()) {
                $this->error('  installed (nothing) — run `hkm cli daemon:install ' . $plugin . '`');
                ++$problems;

                continue;
            }

            $record  = $this->record($paths);
            $onDisk  = $record['version'] ?? '(unrecorded)';
            $matches = $onDisk === $spec->version;

            if ($matches) {
                $this->success("  installed {$onDisk}");
            } else {
                // Not an error — it runs. It is just not the version this
                // commit was tested against, which is exactly the drift a
                // status command exists to surface.
                $this->warning(
                    "  installed {$onDisk} — the plugin pins {$spec->version}. It will run, but it is "
                    . 'not the version this commit was tested against. '
                    . '`daemon:install --force` replaces it.',
                );
                ++$problems;
            }

            if (\is_string($record['platform'] ?? null) && $platform !== null
                && $record['platform'] !== $platform->key()) {
                $this->error(
                    '  built for ' . $record['platform'] . ' but this machine is ' . $platform->key()
                    . ' — it cannot execute here. This usually means var/ was copied between hosts '
                    . 'instead of provisioned on each.',
                );
                ++$problems;
            }

            // Ask the binary itself. The recorded version is what we WROTE;
            // this is what is actually there, and they differ if somebody
            // replaced the file by hand.
            $reported = $this->reportedVersion($paths->binary);

            if ($reported !== null) {
                $this->muted('  reports   ' . $reported);
            }

            $this->muted('  config    ' . ($paths->hasConfig() ? $this->relative($paths->config, $root) : '(none)'));

            if (is_file($paths->config . '.new')) {
                $this->warning(
                    '  a newer generated config is waiting as ' . basename($paths->config) . '.new — '
                    . 'the live one was left alone because it holds the running credential',
                );
            }

            $this->muted('  secret    ' . (is_file($paths->secretFile) ? 'present (0600)' : 'not generated'));
        }

        $this->newLine();
        $this->section('Not checked from here');
        $this->muted(
            'Whether any of these are RUNNING. That is a supervisor question and the unit may be '
            . 'installed system-wide or per-user, so a tick here could be wrong. Ask directly: '
            . ($supervisor === 'systemd'
                ? 'systemctl status hkm-<name>'
                : 'launchctl print gui/$(id -u)/hkm-<name>') . '.',
        );
        $this->muted(
            'Whether the application can reach them. A provisioned, running daemon the app has the '
            . 'wrong credential for looks identical to a healthy one from here — the plugin\'s own '
            . 'status command is what answers that.',
        );

        // A non-zero exit on drift, so this is usable as a deploy gate. The
        // states it reports — nothing installed, a version behind what the
        // commit pins, a binary built for another architecture — are all
        // things that should stop a release rather than appear in its log.
        return $problems > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function record(DaemonPaths $paths): array
    {
        $json = @file_get_contents($paths->dir . '/installed.json');

        if (!\is_string($json)) {
            return [];
        }

        $decoded = json_decode($json, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * What the binary says its own version is.
     *
     * Best effort: `--version` is near-universal for Go daemons but not
     * guaranteed, and a daemon that does not understand it must not turn a
     * status report into a failure.
     */
    private function reportedVersion(string $binary): ?string
    {
        $run = Process::run([$binary, '--version'], timeoutSeconds: 10);

        if (!$run->ok()) {
            return null;
        }

        $line = trim(explode("\n", $run->stdout . $run->stderr)[0] ?? '');

        return $line === '' ? null : $line;
    }

    private function relative(string $path, string $root): string
    {
        return str_starts_with($path, $root . '/') ? substr($path, \strlen($root) + 1) : $path;
    }
}
