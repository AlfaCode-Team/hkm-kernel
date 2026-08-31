<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Fakes;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;

/**
 * A LoggerPort that keeps every record in memory.
 *
 * Binding one is worth doing even when the test asserts nothing about logging:
 * EventBus swallows a listener exception and reports it ONLY through the logger,
 * so without one a listener that throws is indistinguishable from an event that
 * was never dispatched. The ground binds this by default for that reason.
 */
final class FakeLogger implements LoggerPort
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function emergency(string|\Stringable $message, array $context = []): void { $this->log('emergency', $message, $context); }
    public function alert(string|\Stringable $message, array $context = []): void     { $this->log('alert', $message, $context); }
    public function critical(string|\Stringable $message, array $context = []): void  { $this->log('critical', $message, $context); }
    public function error(string|\Stringable $message, array $context = []): void     { $this->log('error', $message, $context); }
    public function warning(string|\Stringable $message, array $context = []): void   { $this->log('warning', $message, $context); }
    public function notice(string|\Stringable $message, array $context = []): void    { $this->log('notice', $message, $context); }
    public function info(string|\Stringable $message, array $context = []): void      { $this->log('info', $message, $context); }
    public function debug(string|\Stringable $message, array $context = []): void     { $this->log('debug', $message, $context); }

    public function log(string $level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    // ── Asserting ─────────────────────────────────────────────────────────────

    /** True when any record at $level contains $needle. */
    public function logged(string $level, string $needle = ''): bool
    {
        foreach ($this->records as $record) {
            if ($record['level'] === $level && ($needle === '' || str_contains($record['message'], $needle))) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{level: string, message: string, context: array<string, mixed>}> */
    public function at(string $level): array
    {
        return array_values(array_filter($this->records, static fn(array $r): bool => $r['level'] === $level));
    }

    /**
     * Every message at error severity or worse, as plain strings.
     *
     * Designed for a failure message: when a ground request 500s, printing these
     * usually says why in one line.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        return $this->problemsSince(0);
    }

    /**
     * The same, but only for records added after $offset entries.
     *
     * The ground uses this to attach ONLY the current request's failures to a
     * response — carrying every earlier error into each later assertion message
     * makes the one that matters harder to find.
     *
     * @return list<string>
     */
    public function problemsSince(int $offset): array
    {
        $severe = ['emergency', 'alert', 'critical', 'error'];

        return array_values(array_map(
            static fn(array $r): string => strtoupper($r['level']) . ': ' . $r['message'],
            array_filter(
                \array_slice($this->records, max(0, $offset)),
                static fn(array $r): bool => \in_array($r['level'], $severe, true),
            ),
        ));
    }

    public function reset(): void
    {
        $this->records = [];
    }
}
