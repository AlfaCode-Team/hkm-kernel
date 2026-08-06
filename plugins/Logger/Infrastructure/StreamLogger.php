<?php

declare(strict_types=1);

namespace Plugins\Logger\Infrastructure;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LogLevel;

/**
 * StreamLogger — writes to a stream (stdout/stderr by default).
 *
 * The right adapter for containers, where the platform collects stdout rather
 * than reading files out of the image. Warnings and worse go to stderr so an
 * orchestrator's error stream stays meaningful; everything else to stdout.
 */
final class StreamLogger extends AbstractLogger
{
    /**
     * @param resource|null $out  defaults to STDOUT (opened lazily — the
     *                            constant is absent under some SAPIs)
     * @param resource|null $err  defaults to STDERR
     */
    public function __construct(
        private $out = null,
        private $err = null,
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

        // Warning and worse to stderr, so `docker logs` error streams are useful.
        $stream = $parsed->passes(LogLevel::Warning) ? $this->errStream() : $this->outStream();

        if (is_resource($stream)) {
            @fwrite($stream, rtrim($line) . PHP_EOL);
        }
    }

    /** @return resource|null */
    private function outStream()
    {
        return $this->out ??= (defined('STDOUT') ? STDOUT : @fopen('php://stdout', 'w')) ?: null;
    }

    /** @return resource|null */
    private function errStream()
    {
        return $this->err ??= (defined('STDERR') ? STDERR : @fopen('php://stderr', 'w')) ?: null;
    }
}
