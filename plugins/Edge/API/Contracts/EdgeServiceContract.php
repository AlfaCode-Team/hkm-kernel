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
     * Write the rendered config, then (optionally) validate + reload the server.
     *
     * @return array{
     *   ok: bool, strategy: string, path?: string, domains?: int,
     *   dry_run?: bool, contents?: string, steps?: list<string>, message?: string
     * }
     */
    public function apply(bool $reload = true, bool $dryRun = false): array;
}
