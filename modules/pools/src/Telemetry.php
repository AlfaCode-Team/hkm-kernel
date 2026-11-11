<?php

declare(strict_types=1);

namespace AlfacodeTeam\Pools;

/**
 * The two measurements a pool can make about itself.
 *
 * Deliberately two methods and no vendor. This package has no dependencies, by
 * the same reasoning as every other package under `modules/`: a pool that
 * required a metrics library would make that library a dependency of the
 * framework. The HOST adapts whatever it already has — the kernel's
 * `MetricsPort`, OpenTelemetry, StatsD — to this, in about fifteen lines.
 *
 * {@see Telemetry\NullTelemetry} is the default, so telemetry is opt-in and
 * costs two no-op calls per checkout when nobody wants it.
 */
interface Telemetry
{
    /**
     * A duration in SECONDS.
     *
     * Seconds rather than milliseconds because that is what both OpenTelemetry
     * and Prometheus expect, and converting at the boundary is the host's one
     * line rather than every reader's.
     *
     * @param array<string, int|string> $labels
     */
    public function histogram(string $name, float $seconds, array $labels = []): void;

    /**
     * A point-in-time count.
     *
     * Pushed on every state change rather than observed lazily at collection
     * time, because a push-model port (the kernel's `MetricsPort`) cannot
     * register an observer. The cost is one call per pop/push; the benefit is
     * that this interface fits every backend rather than only the pull ones.
     *
     * @param array<string, int|string> $labels
     */
    public function gauge(string $name, int|float $value, array $labels = []): void;
}
