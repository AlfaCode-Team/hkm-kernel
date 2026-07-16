<?php

declare(strict_types=1);

namespace Plugins\Audit\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Audit\Application\Ports\AuditWriter;

/**
 * AuditTrail — append-only writer for the central `audit_log` table.
 *
 * Access rule: DatabasePort ONLY (central connection). Records identifiers +
 * structured meta only — never passwords/tokens/PII payloads. Write half of the
 * table; the read/query half is {@see AuditLogRepository}.
 *
 * Pure persistence: a failure is translated to RepositoryException (like the
 * read sibling) and rethrown. The best-effort policy — never letting an audit
 * write break the action it records — lives one layer up in
 * {@see \Plugins\Audit\Application\Services\AuditService}.
 */
final class AuditTrail implements AuditWriter
{
    public function __construct(
        private readonly DatabasePort $central,
    ) {}

    public function write(
        string $action,
        ?string $userId = null,
        ?string $tenantId = null,
        array $meta = [],
        ?string $ip = null,
    ): void {
        try {
            $this->central->execute(
                'INSERT INTO audit_log (event_id, user_id, tenant_id, action, ip, meta, occurred_at)
                 VALUES (:eid, :uid, :tid, :action, :ip, :meta, :ts)',
                [
                    'eid'    => self::eventId(),
                    'uid'    => $userId,
                    'tid'    => $tenantId,
                    'action' => $action,
                    'ip'     => $ip,
                    'meta'   => $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_SLASHES),
                    'ts'     => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException(
                'Failed to write audit entry.',
                layer: 'repository.audit',
                context: ['action' => $action],
                previous: $e,
            );
        }
    }

    /** Unique event id for the row (fits the char(31) column). */
    private static function eventId(): string
    {
        return bin2hex(random_bytes(15)); // 30 hex chars
    }
}
