<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Domain\Entities;

use Plugins\Tenancy\Domain\ValueObjects\MembershipStatus;
use Plugins\Tenancy\Domain\ValueObjects\TenantStatus;
use Project\Support\Entity\Entity;

/**
 * Membership — a view of one `user_tenants` row joined with the tenant it grants
 * access to. Carries everything the selection flow needs to decide routing and
 * mint a tenant-scoped token.
 *
 * Built on the shared {@see Entity} attribute-bag base, keyed by the public
 * property names consumers already read (Entity::__get exposes the bag).
 *
 * Domain layer: zero external imports beyond Domain/ and the Project Entity base.
 */
final class Membership extends Entity
{
    /** @var array<string, string> */
    protected array $casts = [
        'joinedAt' => 'datetime'
    ];
    public static function of(
        string $userId,
        string $tenantId,
        string $tenantName,
        string $tenantSlug,
        string $role,
        MembershipStatus $status,
        TenantStatus $tenantStatus,
    ): self {
        $m = (new self())->forceFill([
            'userId' => $userId,
            'tenantId' => $tenantId,
            'tenantName' => $tenantName,
            'tenantSlug' => $tenantSlug,
            'role' => $role,
            'status' => $status,
            'tenantStatus' => $tenantStatus,
        ]);
        $m->syncOriginal();

        return $m;
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
      

        $m = (new self([
            'joinedAt' => $row['joined_at']
        ]))->forceFill([
            'userId' => (string) $row['user_id'],
            'tenantId' => (string) $row['tenant_id'],
            'tenantName' => (string) ($row['name'] ?? ''),
            'tenantSlug' => (string) ($row['slug'] ?? ''),
            'role' => (string) $row['role'],
            'status' => MembershipStatus::from((int) $row['status']),
            'tenantStatus' => TenantStatus::from((int) ($row['tenant_status'] ?? TenantStatus::Active->value)),
        ]);
        $m->syncOriginal();

        return $m;
    }

    /** Routable only when BOTH the seat and the tenant are active. */
    public function isRoutable(): bool
    {
        return $this->status->isActive() && $this->tenantStatus->isRoutable();
    }
    public function joinedAt(): ?\DateTimeImmutable
    {
        return $this->getDate('joinedAt');
    }
}
