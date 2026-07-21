<?php

declare(strict_types=1);

namespace Plugins\User\Infrastructure\Outbox;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\User\Application\Ports\OutboxPort;

/**
 * Writes integration events into the central `user_outbox` table.
 *
 * MUST be called from inside the SAME transaction that mutates the user, so the
 * event row and the state change commit (or roll back) atomically. A separate
 * relay (`user:outbox:relay`) later dispatches pending rows to the EventBus —
 * guaranteeing at-least-once delivery even across crashes.
 *
 * The injected DatabasePort is the CENTRAL connection (the `users` table is the
 * global identity store and lives in the central DB). The Provider pins it via
 * the ConnectionManager default so identity writes always target the central
 * database.
 */
final class OutboxWriter implements OutboxPort
{
    public function __construct(
        private readonly DatabasePort $db,
    ) {}

    public function write(IntegrationEventContract $event): void
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
        } catch (\Throwable $e) {
            throw new RepositoryException(
                'Failed to enqueue outbox event.',
                layer: 'repository.user.outbox',
                context: ['event' => $event->name()],
                previous: $e,
            );
        }
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
