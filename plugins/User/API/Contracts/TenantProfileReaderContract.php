<?php

declare(strict_types=1);

namespace Plugins\User\API\Contracts;

use Plugins\User\Domain\Entities\UserProfile;

/**
 * Published read surface over the TENANT-scoped `user_profiles` table.
 *
 * The User plugin OWNS user_profiles (its tenant-template migration creates it),
 * so any cross-module read of profile data goes through this contract — never
 * through raw SQL in another module. Consumed by Tenancy at tenant selection to
 * mint the `name` JWT claim (Identity.fullName).
 *
 * Implementations are BEST-EFFORT display readers: they never throw. A missing
 * profile, an unreachable tenant database, or an unwired connection resolver
 * yields '' — display data must never fail an auth or selection flow.
 */
interface TenantProfileReaderContract
{
    /**
     * "First Last" from the given tenant's user_profiles row, '' when unknown.
     *
     * $tenantId selects the tenant database (via the Tenancy connection
     * resolver). Pass '' only when the implementation was constructed against
     * an already-pinned tenant connection.
     */
    public function fullName(string $userId, string $tenantId = ''): string;
    public function getProfile(string $userId, string $tenantId = ''): ?UserProfile;
}
