<?php

declare(strict_types=1);

namespace Plugins\User\Application\Services;

use Plugins\Tenancy\API\Contracts\TenantConnectionResolverContract;
use Plugins\User\API\Contracts\TenantProfileReaderContract;
use Plugins\User\Domain\Entities\UserProfile;
use Plugins\User\Infrastructure\Persistence\UserSettingsRepository;

/**
 * The tenant user-profile service: WRITES the initial per-tenant user_profiles
 * row from an at-signup profile block, and READS profile display data (the
 * published TenantProfileReaderContract) for consumers like Tenancy's
 * tenant-selection flow.
 *
 * Application service: it consumes the repository ONLY — it never touches a
 * DatabasePort. The repository owns all SQL, keeping the access rule intact:
 *
 *     listener / MembershipService → THIS service → UserSettingsRepository → DatabasePort
 *
 * COMPOSITION: the tenant connection is only known per call (the tenantId
 * arrives with the event or the selection), so — exactly like
 * ProvisionTenantProfileListener — this service is the composition point: it
 * resolves the tenant connection through Tenancy's PUBLISHED resolver contract
 * and wires the repository against it. Two construction modes:
 *
 *   - pinned:   new TenantProfileProvisioner(profiles: $repo)      (listener path,
 *               repository already built against the resolved tenant connection)
 *   - resolver: new TenantProfileProvisioner(connections: $resolver) (container
 *               binding — resolves the tenant DB per call from $tenantId)
 *
 * The write is a full upsert on user_id, so an outbox replay is idempotent.
 * Reads are BEST-EFFORT and never throw (display data only).
 */
final class TenantProfileProvisioner implements TenantProfileReaderContract
{
    public function __construct(
        private readonly ?UserSettingsRepository $profiles = null,
        private readonly ?TenantConnectionResolverContract $connections = null,
    ) {
    }

    /**
     * @param array<string, string> $profile whitelisted primitive fields
     *        (first_name, last_name, phone, timezone, locale) from the event.
     * @param string $tenantId tenant to write to — required in resolver mode,
     *        ignored when a pinned repository was injected.
     */
    public function provision(string $userId, array $profile, string $tenantId = ''): void
    {
        if ($userId === '' || $profile === []) {
            return;
        }

        $repository = $this->repositoryFor($tenantId);
        if ($repository === null) {
            return;
        }

        // Named constructor validates lengths/locale/timezone; omitted fields
        // fall back to the table's defaults inside the entity.
        $entity = UserProfile::fromInput(
            userId: $userId,
            firstName: $profile['first_name'] ?? null,
            lastName: $profile['last_name'] ?? null,
            avatarUrl: null,
            timezone: $profile['timezone'] ?? null,
            locale: $profile['locale'] ?? null,
            phone: $profile['phone'] ?? null,
        );

        $repository->saveProfile($entity);
    }

    /**
     * "First Last" from the tenant's user_profiles row. Best-effort: a missing
     * profile, unreachable tenant DB, or unresolvable connection yields '' —
     * display data never fails the calling flow (contract guarantee).
     */
    public function fullName(string $userId, string $tenantId = ''): string
    {
        if ($userId === '') {
            return '';
        }

        try {
            $profile = $this->repositoryFor($tenantId)?->findProfile($userId);
        } catch (\Throwable) {
            return '';
        }

        if ($profile === null) {
            return '';
        }

        return trim(trim((string) $profile->firstName()) . ' ' . trim((string) $profile->lastName()));
    }

    public function getProfile(string $userId, string $tenantId = ''): ?UserProfile
    {
        if ($userId === '') {
            return null;
        }
        try {
            $profile = $this->repositoryFor($tenantId)?->findProfile($userId);
            return $profile;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The repository for this call: the pinned one when injected, else one
     * composed against the tenant connection resolved from $tenantId.
     */
    private function repositoryFor(string $tenantId): ?UserSettingsRepository
    {
        if ($this->profiles !== null) {
            return $this->profiles;
        }

        if ($this->connections === null || $tenantId === '') {
            return null;
        }

        return new UserSettingsRepository($this->connections->for($tenantId));
    }
}
