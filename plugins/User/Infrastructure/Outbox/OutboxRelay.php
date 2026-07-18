<?php

declare(strict_types=1);

namespace Plugins\User\Infrastructure\Outbox;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\User\API\IntegrationEvents\GenericIntegrationEvent;

/**
 * Drains pending rows from `user_outbox` to the EventBus.
 *
 * Delivery is AT-LEAST-ONCE: an event is dispatched first, then marked
 * dispatched. If the process dies between the two, the row stays pending and is
 * re-sent on the next run — consumers must dedupe on the event_id (the UUID is
 * the idempotency key). After MAX_ATTEMPTS failures a row is parked as failed.
 */
final class OutboxRelay
{
    private const MAX_ATTEMPTS = 10;

    public function __construct(
        private readonly DatabasePort $db,
        private readonly EventBus $eventBus,
    ) {}

    /** Relay up to $limit pending events. Returns the number dispatched. */
    public function relayBatch(int $limit = 100): int
    {
        $rows = $this->pending($limit);
        $dispatched = 0;

        foreach ($rows as $row) {
            try {
                $payload = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);

                $this->eventBus->dispatch(new GenericIntegrationEvent(
                    name:    (string) $row['event_name'],
                    version: (string) $row['event_version'],
                    payload: is_array($payload) ? $payload : [],
                ));

                $this->markDispatched((int) $row['id']);
                $dispatched++;
            } catch (\Throwable $e) {
                $this->markFailed((int) $row['id'], (int) $row['attempts'] + 1, $e->getMessage());
            }
        }

        return $dispatched;
    }

    /** @return list<array<string,mixed>> */
    private function pending(int $limit): array
    {
        try {
            return $this->db->query(
                'SELECT id, event_name, event_version, payload, attempts
                 FROM user_outbox
                 WHERE status = 0
                 ORDER BY occurred_at ASC, id ASC
                 LIMIT :limit',
                ['limit' => max(1, $limit)],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to read outbox.', layer: 'repository.user.outbox', previous: $e);
        }
    }

    private function markDispatched(int $id): void
    {
        $this->db->execute(
            'UPDATE user_outbox SET status = 1, dispatched_at = :now, attempts = attempts + 1
             WHERE id = :id AND status = 0',
            ['now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'id' => $id],
        );
    }

    private function markFailed(int $id, int $attempts, string $error): void
    {
        $status = $attempts >= self::MAX_ATTEMPTS ? 2 : 0; // park as failed, else retry next run
        $this->db->execute(
            'UPDATE user_outbox SET status = :status, attempts = :attempts, last_error = :err
             WHERE id = :id',
            ['status' => $status, 'attempts' => $attempts, 'err' => mb_substr($error, 0, 1000), 'id' => $id],
        );
    }
}
