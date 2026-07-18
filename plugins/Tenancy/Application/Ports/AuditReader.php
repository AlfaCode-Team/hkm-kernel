<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Application\Ports;

use Plugins\Tenancy\Domain\Entities\AuditEntry;

/**
 * Read/query seam for the central `audit_log` trail (the counterpart to the
 * write-side {@see AuditSink}). Lets the control plane surface audit history —
 * per tenant, per user, per action — and prune it for retention, without
 * coupling callers to SQL.
 *
 * All listings are keyset-paginated by descending id (id is monotonic with
 * occurred_at) — pass the last seen id as $beforeId to fetch the next page.
 */
interface AuditReader
{
    /** @return list<AuditEntry> Newest first across the whole trail. */
    public function recent(int $limit = 50, ?int $beforeId = null): array;

    /** @return list<AuditEntry> Newest first for one tenant. */
    public function forTenant(string $tenantId, int $limit = 50, ?int $beforeId = null): array;

    /** @return list<AuditEntry> Newest first for one user. */
    public function forUser(string $userId, int $limit = 50, ?int $beforeId = null): array;

    /** @return list<AuditEntry> Newest first for one action (e.g. 'tenant.switch'). */
    public function byAction(string $action, int $limit = 50, ?int $beforeId = null): array;

    /** A single entry by its public event id, or null. */
    public function find(string $eventId): ?AuditEntry;

    /** How many entries a tenant has accrued. */
    public function countForTenant(string $tenantId): int;

    /**
     * Delete entries strictly older than the cutoff (retention / GDPR purge).
     *
     * @return int rows removed
     */
    public function purgeOlderThan(\DateTimeImmutable $cutoff): int;
}
