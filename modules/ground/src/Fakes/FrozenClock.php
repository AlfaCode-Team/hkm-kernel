<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Fakes;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;

/**
 * A ClockPort that only moves when the test moves it.
 *
 * Expiry, throttling and scheduling are the behaviours most often left untested
 * because testing them appears to require sleeping. Injecting this instead turns
 * "wait five minutes" into one method call, and removes the real reason those
 * paths go unverified.
 */
final class FrozenClock implements ClockPort
{
    private \DateTimeImmutable $now;

    /** @param string $at any format DateTimeImmutable accepts; defaults to a fixed, readable instant. */
    public function __construct(string $at = '2026-01-01 00:00:00')
    {
        // UTC explicitly: a clock that silently adopted the machine's timezone
        // would make the same assertion pass locally and fail in CI.
        $this->now = new \DateTimeImmutable($at, new \DateTimeZone('UTC'));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function timestamp(): int
    {
        return $this->now->getTimestamp();
    }

    /**
     * Move forward by a relative interval — `travel('+5 minutes')`.
     *
     * On PHP 8.3+ an unparseable interval raises DateMalformedStringException
     * from modify() itself, so there is no false return to guard against.
     */
    public function travel(string $interval): self
    {
        $this->now = $this->now->modify($interval);

        return $this;
    }

    public function travelSeconds(int $seconds): self
    {
        $this->now = $this->now->modify(($seconds >= 0 ? '+' : '-') . abs($seconds) . ' seconds');

        return $this;
    }

    /** Jump to an absolute instant. */
    public function setTo(string $at): self
    {
        $this->now = new \DateTimeImmutable($at, new \DateTimeZone('UTC'));

        return $this;
    }
}
