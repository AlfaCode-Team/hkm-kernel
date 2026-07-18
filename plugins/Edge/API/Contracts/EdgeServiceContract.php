<?php

declare(strict_types=1);

namespace Plugins\Edge\API\Contracts;

use Plugins\Edge\Domain\EdgePlan;
use Plugins\Edge\Domain\ServerStack;

/**
 * Published contract for the Edge plugin — detect the host stack, plan the
 * config, and apply it (write + optionally test & reload).
 */
interface EdgeServiceContract
{
    /** Probe the host and return the detected web-server stack. */
    public function detect(): ServerStack;

    /**
     * PHP-FPM binding info for the CLI PHP version running the command.
     *
     * @return array{version: string, socket: string, active: list<string>}
     */
    public function phpFpm(): array;

    /**
     * Detect + collect sites + render — WITHOUT touching the filesystem.
     * $all=false (default) scopes to the CURRENT project; true = every project.
     *
     * TLS overrides (null = use config): $tlsMode is one of ssl|none|both,
     * $sslCert/$sslKey override the certificate paths. $appEnv overrides APP_ENV
     * (local|development|production), which is what the cache profile derives from.
     */
    public function plan(bool $all = false, ?string $tlsMode = null, ?string $sslCert = null, ?string $sslKey = null, ?string $appEnv = null): EdgePlan;

    /**
     * Write the rendered config, sync local domains to /etc/hosts, then
     * (optionally) validate + reload the server.
     *
     * @return array{
     *   ok: bool, strategy: string, path?: string, sites?: int,
     *   dry_run?: bool, contents?: string, steps?: list<string>,
     *   hosts?: array<string, mixed>|null, message?: string
     * }
     *
     * TLS overrides (null = use config): $tlsMode is one of ssl|none|both,
     * $sslCert/$sslKey override the certificate paths.
     */
    public function apply(bool $reload = true, bool $dryRun = false, ?bool $manageHosts = null, bool $all = false, ?string $tlsMode = null, ?string $sslCert = null, ?string $sslKey = null, ?string $appEnv = null): array;

    /**
     * Render process-manager units (systemd | supervisor) for the OpenSwoole
     * projects in scope. PHP-FPM projects yield nothing — php-fpm supervises them.
     *
     * @return array<string, string> unit/program name => file contents
     */
    public function serviceUnits(string $format = 'systemd', bool $all = false, ?string $appEnv = null, string $user = 'www-data'): array;

    /**
     * Sync LOCAL domains (.local / .test / …) into /etc/hosts (pointing at the
     * loopback), or remove the managed block with $remove. $all=false (default)
     * scopes to the current project.
     *
     * DEV ONLY: refuses unless the launcher ran with `--dev` (HKM_DEV=1), or
     * $force is passed — a live server uses DNS, not /etc/hosts. A hostname
     * already mapped elsewhere in the file is skipped, never duplicated.
     *
     * @return array{ok: bool, changed?: bool, dry_run?: bool, path: string, count: int, skipped?: list<string>, block?: string, message?: string}
     */
    public function syncHosts(bool $remove = false, bool $dryRun = false, bool $all = false, bool $force = false): array;
}
