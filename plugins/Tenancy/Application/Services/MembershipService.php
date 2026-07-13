<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Application\Services;

use Plugins\Auth\API\Contracts\AuthServiceContract;
use Plugins\Tenancy\API\Contracts\MembershipServiceContract;
use Plugins\Tenancy\API\DTOs\TenantSelection;
use Plugins\Tenancy\API\DTOs\TenantSummary;
use Plugins\Audit\API\Contracts\AuditServiceContract;
use Plugins\Tenancy\Application\Ports\MembershipReader;
use Plugins\Tenancy\Domain\Exceptions\NotAMemberException;

/**
 * MembershipService — the tenant-selection flow.
 *
 * Turns an authenticated (unscoped) user into a tenant-scoped session:
 *   1. list the tenants they may switch into (active seats, active tenants),
 *   2. on selection, RE-VERIFY the membership against central `user_tenants`
 *      (never trust a client-supplied tenant id), then mint a tenant-scoped
 *      access token via the Auth module (`tnt` claim set),
 *   3. audit the switch.
 *
 * The re-verification on selection — and the per-request re-check that the Auth
 * layer/TenantContextStage performs — is what makes a revoked seat lose access
 * before the previously-issued token would expire.
 */
final class MembershipService implements MembershipServiceContract
{
    public function __construct(
        private readonly MembershipReader $memberships,
        private readonly AuthServiceContract $auth,
        private readonly AuditServiceContract $audit,
        private readonly int $tokenTtl = 3600,
    ) {}

    public function myTenants(string $userId): array
    {
        return array_map(
            static fn ($m): TenantSummary => TenantSummary::fromMembership($m),
            $this->memberships->activeForUser($userId),
        );
    }

    public function isActiveMember(string $userId, string $tenantId): bool
    {
        return $this->activeMember($userId, $tenantId) !== null;
    }

    public function activeMember(string $userId, string $tenantId): ?TenantSummary
    {
        $membership = $this->memberships->find($userId, $tenantId);

        return $membership !== null && $membership->isRoutable()
            ? TenantSummary::fromMembership($membership)
            : null;
    }

    public function selectTenant(string $userId, string $tenantId, ?string $ip = null): TenantSelection
    {
        $membership = $this->memberships->find($userId, $tenantId);
 
        if ($membership === null || !$membership->isRoutable()) {
            $this->audit->record('tenant.switch_denied', $userId, $tenantId, [], $ip);
            throw NotAMemberException::for($userId, $tenantId);
        }

        $token = $this->auth->issueJwt(
            $userId,
            ['tnt' => $tenantId, 'roles' => [$membership->role]],
            $this->tokenTtl,
        );

        $this->audit->record('tenant.switch', $userId, $tenantId, ['role' => $membership->role], $ip);

        return new TenantSelection(
            token:     $token,
            tenantId:  $tenantId,
            role:      $membership->role,
            expiresIn: $this->tokenTtl,
        );
    }
}
