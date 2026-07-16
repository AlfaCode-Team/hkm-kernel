<?php

declare(strict_types=1);

namespace Plugins\Mail\Infrastructure\Transport;

/**
 * Writes each message to a log file instead of sending — handy in dev so you can
 * read exactly what would have gone out (headers, MIME, recipients).
 */
final class LogTransport implements Transport
{
    /** @param (callable(string):void)|null $sink where to write; defaults to error_log */
    public function __construct(
        private $sink = null,
    ) {
        $this->sink ??= static fn(string $line): bool => error_log($line);
    }

    public function send(string $envelopeFrom, array $recipients, string $mime): void
    {
        $entry = '[mail] from=' . $envelopeFrom
            . ' to=' . implode(',', $recipients) . "\n" . $mime;

        ($this->sink)($entry);
    }
}
