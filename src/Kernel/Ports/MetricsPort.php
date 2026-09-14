<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports;

/**
 * MetricsPort — counters, gauges and distributions.
 *
 * WHY IT EXISTS
 * -------------
 * The kernel already emits the two things an operator needs to correlate a
 * request across services — an X-Correlation-ID on every surface, and a
 * LoggerPort to attach it to. What it had no seam for is the AGGREGATE: how many
 * requests, how slow, how many jobs failed, how deep the queue is. Those are not
 * log lines; a log line per increment is the wrong shape and the wrong cost.
 *
 * A framework that pitches itself at services cannot leave that to each project
 * to invent, because the interesting numbers are produced INSIDE the kernel —
 * pipeline stage duration, module-graph size, worker outcomes — where project
 * code cannot reach.
 *
 * THE CONTRACT IS STATSD/OTEL SHAPED, NOT DEPENDENT
 * -------------------------------------------------
 * counter / gauge / histogram is the intersection of StatsD, Prometheus and
 * OpenTelemetry, so an adapter for any of them is a thin translation. The kernel
 * defines it here for the same reason it defines DatabasePort instead of typing
 * against PDO.
 *
 * LABELS ARE LOW-CARDINALITY, ALWAYS
 * ----------------------------------
 * `$labels` becomes a time series per distinct value in every backend that
 * matters. A route TEMPLATE ('/users/{id}') is a label; a route PATH
 * ('/users/8134') is an outage — it mints an unbounded series set and takes the
 * metrics store down with it. The kernel only ever passes templates, and an
 * adapter is entitled to drop or truncate what it is given.
 *
 * AN ADAPTER MUST NOT THROW. Same rule as LoggerPort, and for the same reason:
 * failing the operation you were only meant to observe is worse than losing the
 * observation.
 */
interface MetricsPort
{
    /**
     * Add to a monotonically increasing count.
     *
     * @param non-empty-string      $name   dotted, e.g. 'http.requests'
     * @param array<string, string> $labels low-cardinality dimensions
     */
    public function counter(string $name, int|float $by = 1, array $labels = []): void;

    /**
     * Record the CURRENT value of something that moves in both directions —
     * queue depth, pool size, memory.
     *
     * @param non-empty-string      $name
     * @param array<string, string> $labels
     */
    public function gauge(string $name, int|float $value, array $labels = []): void;

    /**
     * Record one observation into a distribution — the backend keeps the
     * quantiles. Use for anything you will later want a p95 of.
     *
     * @param non-empty-string      $name
     * @param array<string, string> $labels
     */
    public function histogram(string $name, int|float $value, array $labels = []): void;

    /**
     * A duration, in MILLISECONDS. Separate from histogram() because most
     * backends have a dedicated timing type with its own unit metadata, and
     * because a millisecond is the one unit worth standardising across a
     * framework — a mix of seconds and microseconds in one dashboard is how
     * latency graphs come to be silently wrong.
     *
     * @param non-empty-string      $name
     * @param array<string, string> $labels
     */
    public function timing(string $name, float $milliseconds, array $labels = []): void;
}
