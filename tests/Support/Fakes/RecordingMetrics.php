<?php

declare(strict_types=1);

namespace Tests\Support\Fakes;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\MetricsPort;

/**
 * Records every signal so a test can assert on LABELS rather than on calls.
 *
 * Shared between the unit test (does the stage emit what it is told?) and the
 * feature test (does the pipeline produce a route template rather than a raw
 * path?) — the second question is the one that matters, and it can only be asked
 * of a real request.
 */
final class RecordingMetrics implements MetricsPort
{
    /** @var list<array{type: string, name: string, value: int|float, labels: array<string, string>}> */
    public array $seen = [];

    public function counter(string $name, int|float $by = 1, array $labels = []): void
    {
        $this->seen[] = ['type' => 'counter', 'name' => $name, 'value' => $by, 'labels' => $labels];
    }

    public function gauge(string $name, int|float $value, array $labels = []): void
    {
        $this->seen[] = ['type' => 'gauge', 'name' => $name, 'value' => $value, 'labels' => $labels];
    }

    public function histogram(string $name, int|float $value, array $labels = []): void
    {
        $this->seen[] = ['type' => 'histogram', 'name' => $name, 'value' => $value, 'labels' => $labels];
    }

    public function timing(string $name, float $milliseconds, array $labels = []): void
    {
        $this->seen[] = ['type' => 'timing', 'name' => $name, 'value' => $milliseconds, 'labels' => $labels];
    }

    /** @return list<array<string, string>> labels of every signal with this name */
    public function labelsFor(string $name): array
    {
        return array_values(array_map(
            static fn(array $s): array => $s['labels'],
            array_filter($this->seen, static fn(array $s): bool => $s['name'] === $name),
        ));
    }
}
