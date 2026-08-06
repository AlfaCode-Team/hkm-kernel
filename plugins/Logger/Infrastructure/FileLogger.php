<?php

declare(strict_types=1);

namespace Plugins\Logger\Infrastructure;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LogLevel;

/**
 * FileLogger — appends one JSON-ish line per record to a log file.
 *
 * Format:  [2026-08-06T11:22:33+00:00] warning: tenant failover {"tenant":"acme"}
 *
 * Human-scannable first (timestamp, level, message), machine-parseable second
 * (the trailing JSON context). Same shape as the ErrorPipeline's FileNotifier
 * writes, so both land readably in one file.
 *
 * Appends with LOCK_EX so concurrent FPM workers cannot interleave a partial
 * line. Never throws: a logger that fails must not take down the operation it
 * was only observing.
 */
final class FileLogger extends AbstractLogger
{
    public function __construct(
        private readonly string $file,
        private readonly LogLevel $minimum = LogLevel::Debug,
    ) {}

    public function log(string $level, string|\Stringable $message, array $context = []): void
    {
        $parsed = LogLevel::parse($level);

        if (!$parsed->passes($this->minimum)) {
            return;
        }

        $line = sprintf(
            '[%s] %s: %s %s',
            date(DATE_ATOM),
            $parsed->value,
            $this->interpolate((string) $message, $context),
            $this->encodeContext($context),
        );

        $this->append(rtrim($line) . PHP_EOL);
    }

    private function append(string $line): void
    {
        try {
            $dir = dirname($this->file);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                return;
            }

            @file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // Swallow — see the class docblock. There is nowhere left to report to.
        }
    }
}
