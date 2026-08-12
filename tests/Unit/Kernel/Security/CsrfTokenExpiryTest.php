<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Security;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SystemClock;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Layers\CsrfTokenLayer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * CSRF token expiry, tested with a frozen clock.
 *
 * These assertions were previously impossible to make cheaply: the token window
 * is derived from time(), so proving "a token from 13 hours ago is rejected"
 * meant actually waiting 13 hours. Security behaviour that cannot be tested
 * cheaply does not stay tested — which is the whole argument for ClockPort.
 */
#[CoversClass(CsrfTokenLayer::class)]
#[CoversClass(SystemClock::class)]
final class CsrfTokenExpiryTest extends TestCase
{
    private const SECRET   = 'csrf-test-secret-0123456789abcdef';
    private const LIFETIME = 43200; // 12h — the shipped default

    private function clockAt(int $timestamp): ClockPort
    {
        return new class($timestamp) implements ClockPort {
            public function __construct(private readonly int $at) {}

            public function now(): \DateTimeImmutable
            {
                return (new \DateTimeImmutable())->setTimestamp($this->at);
            }

            public function timestamp(): int
            {
                return $this->at;
            }
        };
    }

    public function test_a_freshly_minted_token_is_valid(): void
    {
        $now   = 1_800_000_000;
        $clock = $this->clockAt($now);

        $token = CsrfTokenLayer::make(self::SECRET, '', self::LIFETIME, '', $clock);

        self::assertTrue(CsrfTokenLayer::valid(self::SECRET, $token, '', self::LIFETIME, $clock));
    }

    public function test_a_token_is_still_valid_in_the_next_half_life_window(): void
    {
        $now   = 1_800_000_000;
        $token = CsrfTokenLayer::make(self::SECRET, '', self::LIFETIME, '', $this->clockAt($now));

        // 7 hours later — past one half-life (6h), inside the grace window.
        $later = $this->clockAt($now + 7 * 3600);

        self::assertTrue(CsrfTokenLayer::valid(self::SECRET, $token, '', self::LIFETIME, $later));
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $now   = 1_800_000_000;
        $token = CsrfTokenLayer::make(self::SECRET, '', self::LIFETIME, '', $this->clockAt($now));

        // A full day later — well past the lifetime plus its grace window.
        $later = $this->clockAt($now + 24 * 3600);

        self::assertFalse(CsrfTokenLayer::valid(self::SECRET, $token, '', self::LIFETIME, $later));
    }

    public function test_a_token_from_the_future_is_rejected(): void
    {
        $now   = 1_800_000_000;
        $token = CsrfTokenLayer::make(self::SECRET, '', self::LIFETIME, '', $this->clockAt($now + 24 * 3600));

        self::assertFalse(CsrfTokenLayer::valid(self::SECRET, $token, '', self::LIFETIME, $this->clockAt($now)));
    }

    public function test_a_token_bound_to_one_client_does_not_validate_for_another(): void
    {
        $clock = $this->clockAt(1_800_000_000);
        $token = CsrfTokenLayer::make(self::SECRET, 'session-aaa', self::LIFETIME, '', $clock);

        self::assertTrue(CsrfTokenLayer::valid(self::SECRET, $token, 'session-aaa', self::LIFETIME, $clock));
        self::assertFalse(CsrfTokenLayer::valid(self::SECRET, $token, 'session-bbb', self::LIFETIME, $clock));
    }

    public function test_a_token_signed_with_a_different_secret_is_rejected(): void
    {
        $clock = $this->clockAt(1_800_000_000);
        $token = CsrfTokenLayer::make(self::SECRET, '', self::LIFETIME, '', $clock);

        self::assertFalse(CsrfTokenLayer::valid('a-different-secret', $token, '', self::LIFETIME, $clock));
    }

    public function test_the_default_clock_is_the_real_one(): void
    {
        // Omitting the clock must behave exactly as before it was injectable.
        $token = CsrfTokenLayer::make(self::SECRET);

        self::assertTrue(CsrfTokenLayer::valid(self::SECRET, $token));
    }

    public function test_system_clock_reports_the_current_time(): void
    {
        $clock = new SystemClock();

        self::assertEqualsWithDelta(time(), $clock->timestamp(), 2.0);
        self::assertEqualsWithDelta(time(), $clock->now()->getTimestamp(), 2.0);
    }
}
