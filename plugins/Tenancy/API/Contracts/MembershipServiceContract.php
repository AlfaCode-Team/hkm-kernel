<?php

declare(strict_types=1);

namespace Plugins\Tenancy\API\Contracts;

use Plugins\Tenancy\API\DTOs\TenantSelection;
use Plugins\Tenancy\API\DTOs\TenantSummary;

/**
 * MembershipServiceContract — the tenant-selection flow over central
 * `user_tenants`.
 *
 * This is the authority the Auth flow consults to turn an authenticated (but
 * unscoped) user into a tenant-scoped session. It NEVER trusts a client-supplied
 * tenant id without confirming an active membership in the central database.
 */
interface MembershipServiceContract
{
    /**
     * The active tenants a user may switch into (the picker list).
     *
     * @return list<TenantSummary>
     */
    public function myTenants(string $userId): array;

    /**
     * True when the user has an active, routable seat in the tenant.
     */
    public function isActiveMember(string $userId, string $tenantId): bool;

    /**
     * The user's active membership in the tenant — seat AND tenant routable —
     * or null when they hold none. Carries the seat's role, so authentication
     * can hydrate the user's tenant role from the membership record.
     */
    public function activeMember(string $userId, string $tenantId): ?TenantSummary;

    /**
     * Select a tenant: verify active membership + routable tenant, then mint a
     * tenant-scoped access token (the `tnt` claim). Records `tenant.switch` in
     * the audit log.
     *
     * @throws \Plugins\Tenancy\Domain\Exceptions\NotAMemberException (→ 403)
     */
    public function selectTenant(string $userId, string $tenantId, ?string $ip = null): TenantSelection;
}
