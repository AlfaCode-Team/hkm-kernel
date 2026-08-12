<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports;

/**
 * The real clock. Ships in the kernel (alongside {@see HttpClientResponse}) so
 * the default needs no plugin and no wiring — a component taking `?ClockPort`
 * can fall back to `new SystemClock()` and behave exactly as it did when it
 * called time() directly.
 *
 * Tests substitute a frozen implementation; nothing else should implement this.
 */
final class SystemClock implements ClockPort
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable();
    }

    public function timestamp(): int
    {
        return time();
    }
}
