<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\User;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\SecurityException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\User\API\DTOs\UpdateNotificationPreferencesDTO;
use Plugins\User\API\DTOs\UpdatePreferencesDTO;
use Plugins\User\API\DTOs\UpdatePrivacyDTO;
use Plugins\User\API\DTOs\UpdateProfileDTO;
use Plugins\User\Application\Services\UserSettingsService;
use Plugins\Audit\Application\Services\AuditService;
use Plugins\User\Infrastructure\Persistence\UserSettingsRepository;
use Tests\Unit\Plugins\User\Support\InMemoryDatabasePort;

/**
 * Exercises the consolidated settings service through the REAL repository backed
 * by a stateful in-memory DatabasePort, so save→get round-trips are verified
 * (not just mocked).
 */
#[CoversClass(UserSettingsService::class)]
#[CoversClass(UserSettingsRepository::class)]
final class UserSettingsServiceTest extends TestCase
{
    private function service(Identity $identity): UserSettingsService
    {
        return new UserSettingsService(
            new UserSettingsRepository(new InMemoryDatabasePort()),
            $identity,
            new AuditService(writer: null, sink: static fn(string $l) => null, actorId: 'actor'),
        );
    }

    private function user(string $id = 'user-A'): Identity
    {
        return new Identity($id, 'tenant-1', [], [], 'jwt');
    }

    // ── auth scoping ────────────────────────────────────────────────────────────

    public function test_guest_is_rejected_everywhere(): void
    {
        $this->expectException(SecurityException::class);
        $this->service(Identity::guest())->getProfile();
    }

    // ── profile ───────────────────────────────────────────────────────────────

    public function test_profile_defaults_then_round_trips(): void
    {
        $svc = $this->service($this->user());

        $defaults = $svc->getProfile()->toArray();
        $this->assertSame('UTC', $defaults['timezone']);
        $this->assertNull($defaults['firstName']);

        $svc->updateProfile(UpdateProfileDTO::fromRequest(FakeRequest::with([
            'firstName' => 'Jane', 'timezone' => 'Africa/Kampala', 'locale' => 'en_GB',
        ], 'PUT')));

        $saved = $svc->getProfile()->toArray();
        $this->assertSame('Jane', $saved['firstName']);
        $this->assertSame('Africa/Kampala', $saved['timezone']);
    }

    public function test_profile_invalid_timezone_rejected(): void
    {
        $this->expectException(ValidationException::class);
        UpdateProfileDTO::fromRequest(FakeRequest::with(['timezone' => 'Mars/Phobos'], 'PUT'));
    }

    public function test_profile_non_http_avatar_rejected(): void
    {
        $this->expectException(ValidationException::class);
        UpdateProfileDTO::fromRequest(FakeRequest::with(['avatarUrl' => 'ftp://x/a.png'], 'PUT'));
    }

    // ── preferences ─────────────────────────────────────────────────────────────

    public function test_preferences_round_trip_and_currency_normalised(): void
    {
        $svc = $this->service($this->user());
        $svc->updatePreferences(UpdatePreferencesDTO::fromRequest(FakeRequest::with([
            'currency' => 'usd', 'theme' => 'dark', 'reduceMotion' => true,
        ], 'PUT')));

        $saved = $svc->getPreferences()->toArray();
        $this->assertSame('USD', $saved['currency']);
        $this->assertSame('dark', $saved['theme']);
        $this->assertTrue($saved['reduceMotion']);
    }

    public function test_preferences_invalid_theme_rejected(): void
    {
        $this->expectException(ValidationException::class);
        UpdatePreferencesDTO::fromRequest(FakeRequest::with(['theme' => 'neon'], 'PUT'));
    }

    // ── privacy ─────────────────────────────────────────────────────────────────

    public function test_privacy_defaults_and_round_trip(): void
    {
        $svc = $this->service($this->user());

        $defaults = $svc->getPrivacy()->toArray();
        $this->assertSame('public', $defaults['profileVisibility']);
        $this->assertTrue($defaults['analyticsOptIn']);

        $svc->updatePrivacy(UpdatePrivacyDTO::fromRequest(FakeRequest::with([
            'profileVisibility' => 'private', 'marketingOptIn' => true,
        ], 'PUT')));

        $saved = $svc->getPrivacy()->toArray();
        $this->assertSame('private', $saved['profileVisibility']);
        $this->assertTrue($saved['marketingOptIn']);
    }

    // ── notifications ─────────────────────────────────────────────────────────────

    public function test_notifications_partial_update_keeps_security_on(): void
    {
        $svc = $this->service($this->user());
        $svc->updateNotifications(UpdateNotificationPreferencesDTO::fromRequest(FakeRequest::with([
            'flags' => ['push' => ['promotions' => true]],
        ], 'PUT')));

        $flags = $svc->getNotifications()->flags();
        $this->assertTrue($flags['push_promotions'], 'changed flag applied');
        $this->assertTrue($flags['push_security'], 'security stays on');
        $this->assertTrue($flags['email_payments'], 'untouched default preserved');
    }
}
