<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports;

/**
 * Span — one timed unit of work inside a trace.
 *
 * Obtained from {@see TracerPort::start()} and ALWAYS ended, which is why the
 * tracer offers {@see TracerPort::measure()}: a span left open by an early
 * return or a thrown exception is worse than no span, because the trace then
 * shows the work as still running.
 *
 * Every method returns void and none may throw — a span is an observation, and
 * an observation that can break the thing it observes is a liability.
 */
interface Span
{
    /**
     * Attach a dimension to this span. Unlike a metric label, span attributes
     * may be HIGH cardinality (a user id, a full path, a SQL statement) — they
     * are stored per span, not per series.
     */
    public function setAttribute(string $key, string|int|float|bool $value): void;

    /** Attach several at once. @param array<string, string|int|float|bool> $attributes */
    public function setAttributes(array $attributes): void;

    /**
     * Mark this span as failed and attach the throwable.
     *
     * Recording an exception does NOT end the span: the caller may still be
     * about to add a fallback result to it.
     */
    public function recordException(\Throwable $e): void;

    /**
     * Mark the outcome explicitly. A span that neither records an exception nor
     * sets a status is reported as OK.
     */
    public function setError(string $description = ''): void;

    /** Stop the clock and hand the span to the exporter. Idempotent. */
    public function end(): void;

    /**
     * This span's id in W3C traceparent form, or '' when the tracer is not
     * recording. Propagate it on outbound calls; never parse it.
     */
    public function traceparent(): string;
}
