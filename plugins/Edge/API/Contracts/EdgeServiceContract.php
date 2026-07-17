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

    /** Detect + collect domains + render — WITHOUT touching the filesystem. */
    public function plan(): EdgePlan;

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
    public function apply(bool $reload = true, bool $dryRun = false, ?bool $manageHosts = null): array;

    /**
     * Sync the platform's LOCAL domains (.local / .test / …) into /etc/hosts
     * (pointing at the loopback), or remove the managed block with $remove.
     *
     * @return array{ok: bool, changed?: bool, dry_run?: bool, path: string, count: int, block?: string, message?: string}
     */
    public function syncHosts(bool $remove = false, bool $dryRun = false): array;
}
