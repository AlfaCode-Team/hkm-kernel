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
     */
    public function plan(bool $all = false): EdgePlan;

    /**
     * Write the rendered config, sync local domains to /etc/hosts, then
     * (optionally) validate + reload the server.
     *
     * @return array{
     *   ok: bool, strategy: string, path?: string, sites?: int,
     *   dry_run?: bool, contents?: string, steps?: list<string>,
     *   hosts?: array<string, mixed>|null, message?: string
     * }
     */
    public function apply(bool $reload = true, bool $dryRun = false, ?bool $manageHosts = null, bool $all = false): array;

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
