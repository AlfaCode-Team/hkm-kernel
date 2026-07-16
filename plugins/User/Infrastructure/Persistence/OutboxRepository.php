<?php

declare(strict_types=1);

namespace Plugins\User\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\User\Application\Ports\OutboxPort;

/**
 * OutboxRepository — the SOLE data-access seam for the central `user_outbox`
 * table (write + relay read/update). Access rule: DatabasePort ONLY.
 *
 * Write half enqueues integration events inside the SAME transaction that mutates
 * the user, so the event row and the state change commit (or roll back)
 * atomically. Relay half (pending/markDispatched/markFailed) is consumed by
 * {@see \Plugins\User\Application\Services\OutboxRelayService}, which owns the
 * dispatch policy — this class never touches the EventBus.
 *
 * The injected DatabasePort is the CENTRAL connection (the `users`/`user_outbox`
 * tables are the global identity store); the Provider pins it via the
 * ConnectionManager default.
 */
final class OutboxRepository implements OutboxPort
{
    private const MAX_ATTEMPTS = 10;

    public function __construct(
        private readonly DatabasePort $db,
    ) {}

    // ── write side (in-transaction enqueue) ──────────────────────────────────

    public function write(IntegrationEventContract $event): int
    {
        try {
            $this->db->execute(
                'INSERT INTO user_outbox
                    (event_id, event_name, event_version, payload,
                     status, attempts, occurred_at, created_at)
                 VALUES
                    (:event_id, :event_name, :event_version, :payload,
                     0, 0, :occurred_at, :created_at)',
                [
                    'event_id'      => self::uuid(),
                    'event_name'    => $event->name(),
                    'event_version' => $event->version(),
                    'payload'       => json_encode($event->payload(), JSON_THROW_ON_ERROR),
                    'occurred_at'   => self::now(),
                    'created_at'    => self::now(),
                ],
            );

            return (int) $this->db->lastInsertId();
        } catch (\Throwable $e) {
            throw new RepositoryException(
                'Failed to enqueue outbox event.',
                layer: 'repository.user.outbox',
                context: ['event' => $event->name()],
                previous: $e,
            );
        }
    }

    // ── relay side (read + status transitions) ───────────────────────────────

    /** @return list<array<string,mixed>> Pending rows, oldest first. */
    public function pending(int $limit): array
    {
        // LIMIT must be inlined as a validated integer: bound params are sent as
        // strings (execute($params) → PDO::PARAM_STR), and native prepares
        // (EMULATE_PREPARES=false) reject `LIMIT '100'` as a syntax error.
        $limit = max(1, min(1000, $limit));

        try {
            return $this->db->query(
                'SELECT id, event_name, event_version, payload, attempts
                 FROM user_outbox
                 WHERE status = 0
                 ORDER BY occurred_at ASC, id ASC
                 LIMIT ' . $limit,
            );
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to read outbox.', layer: 'repository.user.outbox', previous: $e);
        }
    }

    public function markDispatched(int $id): void
    {
        $this->db->execute(
            'UPDATE user_outbox SET status = 1, dispatched_at = :now, attempts = attempts + 1
             WHERE id = :id AND status = 0',
            ['now' => self::now(), 'id' => $id],
        );
    }

    public function markFailed(int $id, int $attempts, string $error): void
    {
        $status = $attempts >= self::MAX_ATTEMPTS ? 2 : 0; // park as failed, else retry next run
        $this->db->execute(
            'UPDATE user_outbox SET status = :status, attempts = :attempts, last_error = :err
             WHERE id = :id',
            ['status' => $status, 'attempts' => $attempts, 'err' => mb_substr($error, 0, 1000), 'id' => $id],
        );
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
