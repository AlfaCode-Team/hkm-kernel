<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Ports;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LogLevel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The kernel owns the LEVEL contract; the adapters that consume it live in
 * plugins/Logger and are tested there. This covers only what the kernel ships.
 */
#[CoversClass(LogLevel::class)]
final class LogLevelTest extends TestCase
{
    public function test_levels_are_ordered_by_rfc5424_severity(): void
    {
        self::assertTrue(LogLevel::Emergency->passes(LogLevel::Error), 'emergency is more severe than error');
        self::assertFalse(LogLevel::Debug->passes(LogLevel::Error), 'debug is less severe than error');
        self::assertTrue(LogLevel::Error->passes(LogLevel::Error), 'a level always passes its own threshold');
    }

    public function test_severity_is_ascending_as_urgency_falls(): void
    {
        self::assertSame(0, LogLevel::Emergency->severity());
        self::assertSame(7, LogLevel::Debug->severity());
    }

    public function test_an_unknown_level_string_parses_to_debug(): void
    {
        // Never throw on a bad level — losing one line's severity beats losing
        // the operation that was being logged.
        self::assertSame(LogLevel::Debug, LogLevel::parse('not-a-level'));
    }

    public function test_parsing_is_case_insensitive(): void
    {
        self::assertSame(LogLevel::Warning, LogLevel::parse('WARNING'));
        self::assertSame(LogLevel::Warning, LogLevel::parse('Warning'));
    }

    public function test_backed_values_match_psr3_strings(): void
    {
        // Adapters hand these straight to a PSR-3 logger, so they must match.
        self::assertSame('emergency', LogLevel::Emergency->value);
        self::assertSame('info', LogLevel::Info->value);
    }
}
