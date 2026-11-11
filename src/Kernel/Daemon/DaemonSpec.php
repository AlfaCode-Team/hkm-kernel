<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Daemon;

use AlfacodeTeam\PhpServicePlatform\Kernel\Daemon\Exceptions\DaemonException;

/**
 * The `daemon` block of a plugin's `module.json`, parsed and validated.
 *
 * WHY THE KERNEL HOLDS THE MECHANISM AND THE PLUGIN HOLDS THE FACTS
 * -----------------------------------------------------------------
 * Some capabilities are a client for a server that has to exist. A pub/sub
 * plugin is a NATS client; an MQTT plugin is an MQTT client; a search plugin
 * talks to something that indexes. In each case installing the plugin leaves a
 * deployment one process short, and the symptom is the same one this codebase
 * keeps running into: everything appears to work and nothing arrives.
 *
 * So the kernel grows ONE generic mechanism and no knowledge of any daemon.
 * Which repository, which version, which checksum, which config template, which
 * arguments — all of it is declared by the plugin, in the plugin's own
 * manifest, versioned with the plugin. Adding a second daemon is a manifest
 * block in a second plugin and no kernel change, which is the rule this
 * codebase is built on: *a correct plugin needs zero kernel changes.*
 *
 * THE VERSION IS PINNED AND THE CHECKSUM IS PINNED. BOTH, SEPARATELY.
 * ------------------------------------------------------------------
 * `version` being fixed rather than "latest" is what makes a deployment
 * reproducible. The checksum is what makes it *verified*, and the two are not
 * the same promise: a release tag can be re-cut, and a mirror can serve
 * whatever it likes.
 *
 * The checksums live HERE, in a file that is committed, reviewed and diffed —
 * not in a `SHA256SUMS` fetched beside the download. That distinction is the
 * single most important decision in this class, so it is worth being blunt
 * about: **fetching the checksum file from the same server that served the
 * binary verifies nothing.** Whoever can replace one can replace the other.
 * It detects corruption in transit, which TLS already did, and it reads in a
 * commit diff exactly like real verification.
 *
 * A platform with no pinned digest is REFUSED rather than downloaded
 * unverified. See {@see self::digestFor()}.
 */
final readonly class DaemonSpec
{
    private const SHA256 = '/^[0-9a-f]{64}$/';

    /**
     * @param string                $name      the binary's name, e.g. `nats-server`
     * @param string                $version   exact, never a range or `latest`
     * @param string                $why       one sentence an operator reads when asked to
     *                                         approve a download; a plugin that cannot say why
     *                                         it needs a daemon should not be asking for one
     * @param string                $url       template over {version} {os} {arch}
     * @param string                $member    the path INSIDE the archive to extract, templated
     * @param array<string, string> $platforms `darwin-arm64` => sha256 of the ARCHIVE
     * @param ?string               $template  config template, relative to the plugin root
     * @param string                $configName the rendered config's filename
     * @param list<string>          $verify    argv to syntax-check the config, templated
     * @param list<string>          $serve     argv to run the daemon, templated
     * @param list<string>          $reload    argv to reload it without dropping connections
     * @param array<string, string> $env       env var => templated value, seeded into `.env`
     * @param array<string, string> $defaults  extra tokens the template may use, with defaults
     */
    private function __construct(
        public string $name,
        public string $version,
        public string $why,
        public string $url,
        public string $member,
        public array $platforms,
        public ?string $template,
        public string $configName,
        public array $verify,
        public array $serve,
        public array $reload,
        public array $env,
        public array $defaults,
    ) {}

    /**
     * Read the block, or null when the plugin declares no daemon.
     *
     * Null is the overwhelmingly common answer — almost no plugin needs a
     * sidecar — so it is a return value rather than an exception.
     *
     * @param  array<string, mixed> $manifest the decoded module.json
     * @throws DaemonException when a block is present but unusable
     */
    public static function fromManifest(array $manifest, string $plugin): ?self
    {
        $block = $manifest['daemon'] ?? null;

        if ($block === null) {
            return null;
        }

        if (!\is_array($block)) {
            throw DaemonException::malformed($plugin, '`daemon` is not an object');
        }

        /** @var array<string, mixed> $block */
        $name    = self::requiredString($block, 'name', $plugin);
        $version = self::requiredString($block, 'version', $plugin);
        $url     = self::requiredString($block, 'url', $plugin);
        $member  = self::requiredString($block, 'member', $plugin);

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $name) !== 1) {
            throw DaemonException::malformed(
                $plugin,
                '`daemon.name` must be a plain filename — it becomes a directory and a binary path',
            );
        }

        // A range or `latest` would make two deployments of the same commit
        // install different software, which is the thing a lock file exists to
        // prevent. Refused rather than resolved.
        if (preg_match('/^[0-9][0-9A-Za-z.+-]*$/', $version) !== 1) {
            throw DaemonException::malformed(
                $plugin,
                "`daemon.version` is [{$version}]; it must be an exact version. A range or `latest` "
                . 'would make two deployments of the same commit install different software, and the '
                . 'pinned checksum could not possibly match it',
            );
        }

        if (!str_starts_with($url, 'https://')) {
            throw DaemonException::malformed(
                $plugin,
                '`daemon.url` must be https — a plain-http download of a binary that will run as a '
                . 'service is modifiable by anyone on the path, and the digest check is a second '
                . 'line of defence rather than a licence to skip the first',
            );
        }

        $archive = self::optionalString($block, 'archive') ?? 'tar.gz';

        if ($archive !== 'tar.gz') {
            throw DaemonException::unsupportedArchive($archive);
        }

        $platforms = [];

        foreach (self::objectOf($block, 'platforms', $plugin) as $key => $digest) {
            if (!\is_string($key) || !\is_string($digest)) {
                throw DaemonException::malformed($plugin, '`daemon.platforms` must map strings to strings');
            }

            $normalised = strtolower(trim($digest));

            if (preg_match(self::SHA256, $normalised) !== 1) {
                throw DaemonException::malformed(
                    $plugin,
                    "the checksum pinned for [{$key}] is not 64 hex characters, so it is not a sha256",
                );
            }

            $platforms[$key] = $normalised;
        }

        if ($platforms === []) {
            throw DaemonException::malformed(
                $plugin,
                '`daemon.platforms` is empty, so nothing could ever be verified and nothing will be '
                . 'downloaded',
            );
        }

        $config = $block['config'] ?? [];
        $config = \is_array($config) ? $config : [];

        /** @var array<string, mixed> $config */
        $service = $block['service'] ?? [];
        $service = \is_array($service) ? $service : [];

        /** @var array<string, mixed> $service */
        return new self(
            name: $name,
            version: $version,
            why: self::optionalString($block, 'why') ?? '',
            url: $url,
            member: $member,
            platforms: $platforms,
            template: self::optionalString($config, 'template'),
            configName: self::optionalString($config, 'target') ?? ($name . '.conf'),
            verify: self::stringList($config, 'verify', $plugin),
            serve: self::stringList($service, 'args', $plugin),
            reload: self::stringList($service, 'reload', $plugin),
            env: self::stringMap($block, 'env', $plugin),
            defaults: self::stringMap($block, 'defaults', $plugin),
        );
    }

    /**
     * The pinned digest for $platform, or a refusal.
     *
     * THERE IS NO UNVERIFIED PATH, and that is deliberate rather than strict.
     * The tempting shape is "use the digest when one is pinned, otherwise fetch
     * `SHA256SUMS` / otherwise just install it" — and the moment that branch
     * exists it becomes the branch every un-pinned platform takes, silently, on
     * the machines nobody tested. A refusal naming the platform is one line of
     * work for whoever hits it and it cannot degrade.
     *
     * @throws DaemonException
     */
    public function digestFor(Platform $platform): string
    {
        $digest = $this->platforms[$platform->key()] ?? null;

        if ($digest === null) {
            throw DaemonException::platformNotPinned(
                $this->name,
                $platform->key(),
                array_keys($this->platforms),
            );
        }

        return $digest;
    }

    public function supports(Platform $platform): bool
    {
        return isset($this->platforms[$platform->key()]);
    }

    /** The download URL for this platform. */
    public function urlFor(Platform $platform): string
    {
        return $this->expand($this->url, $platform);
    }

    /** The archive member to extract for this platform. */
    public function memberFor(Platform $platform): string
    {
        return $this->expand($this->member, $platform);
    }

    /** Substitute the platform tokens only. Config tokens are resolved later. */
    private function expand(string $template, Platform $platform): string
    {
        return str_replace(
            ['{version}', '{os}', '{arch}'],
            [$this->version, $platform->os, $platform->arch],
            $template,
        );
    }

    /** @param array<string, mixed> $block */
    private static function requiredString(array $block, string $key, string $plugin): string
    {
        $value = $block[$key] ?? null;

        if (!\is_string($value) || trim($value) === '') {
            throw DaemonException::malformed($plugin, "`daemon.{$key}` is required and must be a non-empty string");
        }

        return trim($value);
    }

    /** @param array<string, mixed> $block */
    private static function optionalString(array $block, string $key): ?string
    {
        $value = $block[$key] ?? null;

        return \is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string, mixed> $block
     * @return array<array-key, mixed>
     */
    private static function objectOf(array $block, string $key, string $plugin): array
    {
        $value = $block[$key] ?? null;

        if (!\is_array($value)) {
            throw DaemonException::malformed($plugin, "`daemon.{$key}` is required and must be an object");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed> $block
     * @return list<string>
     */
    private static function stringList(array $block, string $key, string $plugin): array
    {
        $value = $block[$key] ?? [];

        if (!\is_array($value)) {
            throw DaemonException::malformed($plugin, "`daemon.{$key}` must be a list of strings");
        }

        $out = [];

        foreach ($value as $item) {
            if (!\is_string($item)) {
                throw DaemonException::malformed($plugin, "`daemon.{$key}` must contain only strings");
            }

            $out[] = $item;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed> $block
     * @return array<string, string>
     */
    private static function stringMap(array $block, string $key, string $plugin): array
    {
        $value = $block[$key] ?? [];

        if (!\is_array($value)) {
            throw DaemonException::malformed($plugin, "`daemon.{$key}` must be an object of strings");
        }

        $out = [];

        foreach ($value as $name => $item) {
            if (!\is_string($name) || !\is_string($item)) {
                throw DaemonException::malformed($plugin, "`daemon.{$key}` must map strings to strings");
            }

            $out[$name] = $item;
        }

        return $out;
    }
}
