<?php

declare(strict_types=1);

namespace Plugins\User\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\SecurityException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\User\API\DTOs\UpdateNotificationPreferencesDTO;
use Plugins\User\API\DTOs\UpdatePreferencesDTO;
use Plugins\User\API\DTOs\UpdatePrivacyDTO;
use Plugins\User\API\DTOs\UpdateProfileDTO;
use Plugins\User\Domain\Entities\UserNotificationPreferences;
use Plugins\User\Domain\Entities\UserPreferences;
use Plugins\User\Domain\Entities\UserPrivacySettings;
use Plugins\User\Domain\Entities\UserProfile;
use Plugins\Audit\API\Contracts\AuditServiceContract;
use Plugins\User\Infrastructure\Persistence\UserSettingsRepository;

/**
 * UserSettingsService — the authenticated user's own settings (profile,
 * preferences, privacy, notifications).
 *
 * Security: EVERY operation is scoped to the Identity via requireUser() — the
 * user id is taken from the Identity, never the request body, so a user can only
 * ever read/write their OWN settings; guests are rejected. Input is validated by
 * the Update DTOs and re-guarded by the entity invariants (defense in depth).
 * Each write is a single tenant-scoped upsert (atomic) and is audited.
 */
final class UserSettingsService
{
    public function __construct(
        private readonly UserSettingsRepository $repository,
        private readonly Identity $identity,
        private readonly AuditServiceContract $audit,
    ) {}

    // ── profile ───────────────────────────────────────────────────────────────

    public function getProfile(): UserProfile
    {
        $userId = $this->requireUser();

        return $this->repository->findProfile($userId) ?? UserProfile::defaults($userId);
    }

    public function updateProfile(UpdateProfileDTO $dto): UserProfile
    {
        $userId = $this->requireUser();

        try {
            $profile = UserProfile::fromInput(
                userId:    $userId,
                firstName: $dto->firstName,
                lastName:  $dto->lastName,
                avatarUrl: $dto->avatarUrl,
                timezone:  $dto->timezone,
                locale:    $dto->locale,
                phone:     $dto->phone,
            );
        } catch (\DomainException $e) {
            throw new ValidationException(['profile' => $e->getMessage()]);
        }

        $this->repository->saveProfile($profile);
        $this->audit->record('user.profile.updated', userId: $userId);

        return $profile;
    }

    // ── preferences ─────────────────────────────────────────────────────────────

    public function getPreferences(): UserPreferences
    {
        $userId = $this->requireUser();

        return $this->repository->findPreferences($userId) ?? UserPreferences::defaults($userId);
    }

    public function updatePreferences(UpdatePreferencesDTO $dto): UserPreferences
    {
        $userId = $this->requireUser();

        try {
            $prefs = UserPreferences::fromInput(
                userId:            $userId,
                language:          $dto->language,
                currency:          $dto->currency,
                theme:             $dto->theme,
                reduceMotion:      $dto->reduceMotion,
                largerText:        $dto->largerText,
                highContrast:      $dto->highContrast,
                screenReaderHints: $dto->screenReaderHints,
            );
        } catch (\DomainException $e) {
            throw new ValidationException(['preferences' => $e->getMessage()]);
        }

        $this->repository->savePreferences($prefs);
        $this->audit->record('user.preferences.updated', userId: $userId);

        return $prefs;
    }

    // ── privacy ─────────────────────────────────────────────────────────────────

    public function getPrivacy(): UserPrivacySettings
    {
        $userId = $this->requireUser();

        return $this->repository->findPrivacy($userId) ?? UserPrivacySettings::defaults($userId);
    }

    public function updatePrivacy(UpdatePrivacyDTO $dto): UserPrivacySettings
    {
        $userId = $this->requireUser();

        $settings = UserPrivacySettings::fromInput(
            userId:            $userId,
            profileVisibility: $dto->profileVisibility,
            showPhone:         $dto->showPhone,
            showEmail:         $dto->showEmail,
            marketingOptIn:    $dto->marketingOptIn,
            analyticsOptIn:    $dto->analyticsOptIn,
        );

        $this->repository->savePrivacy($settings);
        // Privacy/marketing toggles are compliance-relevant — record the change.
        $this->audit->record('user.privacy.updated', userId: $userId, meta: [
            'marketingOptIn' => $dto->marketingOptIn,
            'analyticsOptIn' => $dto->analyticsOptIn,
        ]);

        return $settings;
    }

    // ── notification preferences ──────────────────────────────────────────────────

    public function getNotifications(): UserNotificationPreferences
    {
        $userId = $this->requireUser();

        return $this->repository->findNotifications($userId) ?? UserNotificationPreferences::defaults($userId);
    }

    public function updateNotifications(UpdateNotificationPreferencesDTO $dto): UserNotificationPreferences
    {
        $userId = $this->requireUser();

        // Merge the provided flags over the user's current set (or defaults), so
        // a partial payload only changes what it names (security topics stay on).
        $current = $this->repository->findNotifications($userId) ?? UserNotificationPreferences::defaults($userId);

        try {
            $prefs = UserNotificationPreferences::fromInput($userId, [...$current->flags(), ...$dto->flags]);
        } catch (\DomainException $e) {
            throw new ValidationException(['flags' => $e->getMessage()]);
        }

        $this->repository->saveNotifications($prefs);
        $this->audit->record('user.notification_preferences.updated', userId: $userId);

        return $prefs;
    }

    // ── internals ────────────────────────────────────────────────────────────────

    private function requireUser(): string
    {
        if ($this->identity->isGuest()) {
            throw new SecurityException('user_settings.unauthenticated', layer: 'service.user_settings');
        }
        return $this->identity->userId;
    }
}
