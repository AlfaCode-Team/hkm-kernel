<?php

declare(strict_types=1);

namespace Plugins\User\Application\Services;

use Plugins\User\Domain\Entities\UserProfile;
use Plugins\User\Infrastructure\Persistence\UserSettingsRepository;

/**
 * Creates the initial per-tenant user_profiles row from an at-signup profile
 * block. Application service: it consumes the repository ONLY — it never touches
 * a DatabasePort. The repository (constructed against the resolved tenant
 * connection) owns all SQL, keeping the access rule intact:
 *
 *     listener → THIS service → UserSettingsRepository → DatabasePort
 *
 * The write is a full upsert on user_id, so an outbox replay is idempotent.
 */
final class TenantProfileProvisioner
{
    public function __construct(
        private readonly UserSettingsRepository $profiles,
    ) {}

    /**
     * @param array<string, string> $profile whitelisted primitive fields
     *        (first_name, last_name, phone, timezone, locale) from the event.
     */
    public function provision(string $userId, array $profile): void
    {
        if ($userId === '' || $profile === []) {
            return;
        }

        // Named constructor validates lengths/locale/timezone; omitted fields
        // fall back to the table's defaults inside the entity.
        $entity = UserProfile::fromInput(
            userId:    $userId,
            firstName: $profile['first_name'] ?? null,
            lastName:  $profile['last_name'] ?? null,
            avatarUrl: null,
            timezone:  $profile['timezone'] ?? null,
            locale:    $profile['locale'] ?? null,
            phone:     $profile['phone'] ?? null,
        );

        $this->profiles->saveProfile($entity);
    }
}
