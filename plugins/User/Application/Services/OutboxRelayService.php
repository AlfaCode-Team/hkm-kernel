<?php

declare(strict_types=1);

namespace Plugins\User\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use Plugins\User\API\IntegrationEvents\GenericIntegrationEvent;
use Plugins\User\Infrastructure\Persistence\OutboxRepository;

/**
 * OutboxRelayService — drains pending rows from the outbox to the EventBus.
 *
 * Layering: this is the SERVICE that owns the delivery policy; it consumes the
 * {@see OutboxRepository} for all persistence and the {@see EventBus} for
 * dispatch — it never touches DatabasePort directly.
 *
 * Delivery is AT-LEAST-ONCE: an event is dispatched first, then marked
 * dispatched. If the process dies between the two, the row stays pending and is
 * re-sent on the next run — consumers must dedupe on the event_id (the UUID is
 * the idempotency key). After the repository's max attempts a row is parked as
 * failed.
 */
final class OutboxRelayService
{
    public function __construct(
        private readonly OutboxRepository $outbox,
        private readonly EventBus $eventBus,
    ) {}

    /** Relay up to $limit pending events. Returns the number dispatched. */
    public function relayBatch(int $limit = 100): int
    {
        $dispatched = 0;

        foreach ($this->outbox->pending($limit) as $row) {
            try {
                $payload = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);

                $this->eventBus->dispatch(new GenericIntegrationEvent(
                    name:    (string) $row['event_name'],
                    version: (string) $row['event_version'],
                    payload: is_array($payload) ? $payload : [],
                ));

                $this->outbox->markDispatched((int) $row['id']);
                $dispatched++;
            } catch (\Throwable $e) {
                $this->outbox->markFailed((int) $row['id'], (int) $row['attempts'] + 1, $e->getMessage());
            }
        }

        return $dispatched;
    }
}
