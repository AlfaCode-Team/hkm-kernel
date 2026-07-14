<?php

declare(strict_types=1);

namespace Plugins\Tenancy\API\Contracts;

use Plugins\Tenancy\API\DTOs\TenantSummary;

/**
 * MembershipServiceContract — the tenant-selection flow over central
 * `user_tenants`.
 *
 * This is the authority the Auth flow consults to turn an authenticated (but
 * unscoped) user into a tenant-scoped session. It NEVER trusts a client-supplied
 * tenant id without confirming an active membership in the central database.
 *
 * Control plane ONLY: it verifies seats and audits — it never mints
 * credentials. The caller (TenantController) takes the verified seat returned
 * by selectTenant() and asks the Auth module to issue the tenant-scoped token.
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
     * Select a tenant: verify active membership + routable tenant and record
     * `tenant.switch` in the audit log. Returns the verified seat; the caller
     * mints the tenant-scoped token (`tnt` claim) from it via the Auth module.
     *
     * @throws \Plugins\Tenancy\Domain\Exceptions\NotAMemberException (→ 403)
     */
    public function selectTenant(string $userId, string $tenantId, ?string $ip = null): TenantSummary;
}
