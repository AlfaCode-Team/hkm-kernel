<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports;

/**
 * RFC 5424 severity levels, in descending severity.
 *
 * An enum rather than PSR-3's string constants so a level cannot be misspelled:
 * `LogLevel::Warning` either exists or fails to compile, whereas `'warnings'`
 * silently becomes an unroutable level at runtime.
 *
 * The backed values match PSR-3's strings exactly, so adapters can hand a level
 * straight to a PSR-3 logger, and {@see LoggerPort::log()} accepts either.
 */
enum LogLevel: string
{
    case Emergency = 'emergency';
    case Alert     = 'alert';
    case Critical  = 'critical';
    case Error     = 'error';
    case Warning   = 'warning';
    case Notice    = 'notice';
    case Info      = 'info';
    case Debug     = 'debug';

    /** Lower is more severe — used to compare against a minimum-level threshold. */
    public function severity(): int
    {
        return match ($this) {
            self::Emergency => 0,
            self::Alert     => 1,
            self::Critical  => 2,
            self::Error     => 3,
            self::Warning   => 4,
            self::Notice    => 5,
            self::Info      => 6,
            self::Debug     => 7,
        };
    }

    /** True when this level is at least as severe as $minimum. */
    public function passes(self $minimum): bool
    {
        return $this->severity() <= $minimum->severity();
    }

    /** Parse a level string, falling back to Debug for anything unrecognised. */
    public static function parse(string $level): self
    {
        return self::tryFrom(strtolower($level)) ?? self::Debug;
    }
}
