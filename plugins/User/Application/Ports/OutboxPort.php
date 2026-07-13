<?php

declare(strict_types=1);

namespace Plugins\User\Application\Ports;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;

/**
 * Internal port for the transactional outbox (DIP seam — lets the service be
 * tested without a database).
 *
 * write() enqueues an event inside the state-change transaction and returns its
 * row id. After commit the service dispatches synchronously and calls
 * markDispatched($id) so the relay only re-delivers rows that never made it
 * out (a crash between commit and dispatch) — at-least-once, no double-fire in
 * the happy path.
 */
interface OutboxPort
{
    /** @return int the new outbox row id */
    public function write(IntegrationEventContract $event): int;

    public function markDispatched(int $id): void;
}
