<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Tenancy\Application\Ports\MembershipReader;
use Plugins\Tenancy\Application\Ports\MembershipWriter;
use Plugins\Tenancy\Domain\Entities\Membership;
use Plugins\Tenancy\Domain\ValueObjects\MembershipStatus;

/**
 * MembershipRepository — reads central `user_tenants` joined with `tenants`.
 *
 * Access rule: DatabasePort ONLY. The injected port is the CENTRAL connection
 * (the ConnectionManager default) — membership and tenant registry both live in
 * the control-plane database, never in a tenant DB.
 */
final class MembershipRepository implements MembershipReader, MembershipWriter
{
    private const SELECT =
        'SELECT ut.user_id, ut.tenant_id, ut.role, ut.joined_at, ut.status,
                t.name, t.slug, t.status AS tenant_status
           FROM user_tenants ut
           JOIN tenants t ON t.tenant_id = ut.tenant_id AND t.deleted_at IS NULL';

    public function __construct(
        private readonly DatabasePort $central,
    ) {}

    public function activeForUser(string $userId): array
    {
        try {
            $rows = $this->central->query(
                self::SELECT . '
                 WHERE ut.user_id = :uid
                   AND ut.status = 1          -- active seat
                   AND t.status = 1           -- active tenant
                 ORDER BY t.name ASC',
                ['uid' => $userId],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to list memberships.', layer: 'repository.tenancy', previous: $e);
        }

        return array_map(static fn (array $r): Membership => Membership::fromRow($r), $rows);
    }

    public function find(string $userId, string $tenantId): ?Membership
    {
        try {
            $row = $this->central->queryOne(
                self::SELECT . '
                 WHERE ut.user_id = :uid AND ut.tenant_id = :tid
                 LIMIT 1',
                ['uid' => $userId, 'tid' => $tenantId],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to load membership.', layer: 'repository.tenancy', previous: $e);
        }

        return $row === null ? null : Membership::fromRow($row);
    }

    public function upsertActive(string $userId, string $tenantId, string $role): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        try {
            // Single atomic, driver-portable upsert (no UPDATE-then-INSERT race).
            // joined_at is inserted but NOT in the update set, so an existing
            // seat keeps its original join time while role/status are refreshed.
            $this->central->upsert(
                'user_tenants',
                [
                    'user_id'    => $userId,
                    'tenant_id'  => $tenantId,
                    'role'       => $role,
                    'status'     => MembershipStatus::Active->value,
                    'joined_at'  => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                conflictColumns: ['user_id', 'tenant_id'],
                updateColumns:   ['role', 'status', 'updated_at'],
            );
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to upsert membership.', layer: 'repository.tenancy', previous: $e);
        }
    }
}
