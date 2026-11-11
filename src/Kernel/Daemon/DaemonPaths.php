<?php

declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Daemon;

/**
 * Where a provisioned daemon's files live inside a project.
 *
 * EVERYTHING UNDER `var/`, FOR THREE REASONS THAT ALL BITE
 * -------------------------------------------------------
 * `var/` is already gitignored, already mode-fixed by `hkm install --production`,
 * and already understood to be runtime state rather than source. A downloaded
 * binary is exactly that: machine-specific, architecture-specific, and
 * reproducible from the manifest. Committing a 15 MB Mach-O into a repository
 * that also deploys to Linux is the obvious alternative and it is wrong twice
 * over — wrong architecture on the server, and a repository that doubles in size
 * per daemon.
 *
 * The CONFIG lives there too, which is the less obvious call. It is generated,
 * it contains a generated password, and it is per-machine — so it is state, not
 * source. The cost is that it does not travel with a git clone, which is why
 * {@see DaemonInstaller} is idempotent and `daemon:install` is part of a deploy
 * rather than a one-off.
 *
 * The SECRET is persisted separately and never regenerated. That is not a
 * detail: a provisioner that minted a new password on each run would leave the
 * daemon's config and the project's `.env` disagreeing, and every publish would
 * fail authentication while both files looked perfectly reasonable.
 */
final readonly class DaemonPaths
{
    private function __construct(
        /** `<project>/var/daemon/<name>` */
        public string $dir,
        public string $binary,
        public string $config,
        public string $logFile,
        public string $pidFile,
        /** Durable data (JetStream, a queue's spool). Created only if used. */
        public string $storeDir,
        /** The generated password, 0600, written once and reused. */
        public string $secretFile,
        /** Where a generated service unit is written for review. */
        public string $serviceFile,
    ) {}

    public static function for(string $projectRoot, DaemonSpec $spec, string $supervisor): self
    {
        $root = rtrim($projectRoot, '/') . '/var/daemon/' . $spec->name;

        return new self(
            dir: $root,
            binary: $root . '/bin/' . $spec->name,
            config: $root . '/' . $spec->configName,
            logFile: $root . '/logs/' . $spec->name . '.log',
            pidFile: $root . '/run/' . $spec->name . '.pid',
            storeDir: $root . '/store',
            secretFile: $root . '/secret',
            serviceFile: $root . '/' . self::unitName($spec, $supervisor),
        );
    }

    private static function unitName(DaemonSpec $spec, string $supervisor): string
    {
        return $supervisor === 'launchd'
            ? 'hkm-' . $spec->name . '.plist'
            : 'hkm-' . $spec->name . '.service';
    }

    /** Every directory that must exist before anything is written. */
    public function directories(): array
    {
        return [
            $this->dir,
            \dirname($this->binary),
            \dirname($this->logFile),
            \dirname($this->pidFile),
        ];
    }

    public function isInstalled(): bool
    {
        return is_file($this->binary) && is_executable($this->binary);
    }

    public function hasConfig(): bool
    {
        return is_file($this->config);
    }
}
