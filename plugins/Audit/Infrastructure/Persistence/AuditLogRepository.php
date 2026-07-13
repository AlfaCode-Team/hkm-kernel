<?php

declare(strict_types=1);

namespace Plugins\Audit\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Audit\API\Contracts\AuditReaderContract;
use Plugins\Audit\Domain\Entities\AuditEntry;

/**
 * AuditLogRepository — read/query side of the central `audit_log` trail.
 *
 * Access rule: DatabasePort ONLY (the CENTRAL connection — the trail lives in
 * the control-plane DB, never a tenant DB). Writes go through {@see AuditTrail};
 * this is the read counterpart, leaning on the
 * (tenant_id|user_id|action, occurred_at) indexes.
 *
 * Listings are keyset-paginated by descending id (monotonic with occurred_at):
 * pass the last id seen as $beforeId for the next page. LIMIT is clamped and
 * inlined as an integer — it cannot be a bound parameter with emulated prepares
 * disabled — while all filter VALUES stay parameter-bound.
 */
final class AuditLogRepository implements AuditReaderContract
{
    private const SELECT =
        'SELECT id, event_id, user_id, tenant_id, action, ip, meta, occurred_at FROM audit_log';

    private const MAX_LIMIT = 200;

    public function __construct(
        private readonly DatabasePort $central,
    ) {}

    public function recent(int $limit = 50, ?int $beforeId = null): array
    {
        return $this->page('', [], $limit, $beforeId);
    }

    public function forTenant(string $tenantId, int $limit = 50, ?int $beforeId = null): array
    {
        return $this->page('tenant_id = :tenant_id', ['tenant_id' => $tenantId], $limit, $beforeId);
    }

    public function forUser(string $userId, int $limit = 50, ?int $beforeId = null): array
    {
        return $this->page('user_id = :user_id', ['user_id' => $userId], $limit, $beforeId);
    }

    public function byAction(string $action, int $limit = 50, ?int $beforeId = null): array
    {
        return $this->page('action = :action', ['action' => $action], $limit, $beforeId);
    }

    public function find(string $eventId): ?AuditEntry
    {
        try {
            $row = $this->central->queryOne(
                self::SELECT . ' WHERE event_id = :event_id LIMIT 1',
                ['event_id' => $eventId],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to load audit entry.', layer: 'repository.audit', previous: $e);
        }

        return $row === null ? null : AuditEntry::fromRow($row);
    }

    public function countForTenant(string $tenantId): int
    {
        try {
            $row = $this->central->queryOne(
                'SELECT COUNT(*) AS c FROM audit_log WHERE tenant_id = :tenant_id',
                ['tenant_id' => $tenantId],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to count audit entries.', layer: 'repository.audit', previous: $e);
        }

        return (int) ($row['c'] ?? 0);
    }

    public function purgeOlderThan(\DateTimeImmutable $cutoff): int
    {
        try {
            return $this->central->execute(
                'DELETE FROM audit_log WHERE occurred_at < :cutoff',
                ['cutoff' => $cutoff->format('Y-m-d H:i:s')],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to purge audit entries.', layer: 'repository.audit', previous: $e);
        }
    }

    /**
     * Run a keyset-paginated listing with an optional WHERE filter.
     *
     * @param array<string, scalar> $params
     * @return list<AuditEntry>
     */
    private function page(string $where, array $params, int $limit, ?int $beforeId): array
    {
        $limit   = max(1, min(self::MAX_LIMIT, $limit));
        $clauses = $where !== '' ? [$where] : [];

        if ($beforeId !== null) {
            $clauses[] = 'id < ' . (int) $beforeId; // validated int — keyset cursor
        }

        $sql = self::SELECT
            . ($clauses !== [] ? ' WHERE ' . implode(' AND ', $clauses) : '')
            . ' ORDER BY id DESC LIMIT ' . $limit;

        try {
            $rows = $this->central->query($sql, $params);
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to list audit entries.', layer: 'repository.audit', previous: $e);
        }

        return array_map(static fn (array $r): AuditEntry => AuditEntry::fromRow($r), $rows);
    }
}
