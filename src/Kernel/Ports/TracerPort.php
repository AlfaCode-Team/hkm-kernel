<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports;

/**
 * TracerPort — distributed tracing.
 *
 * WHY THE KERNEL DEFINES THIS
 * ---------------------------
 * The hard half of tracing is already done here: CorrelationIdStage propagates
 * an id across HTTP, CLI and the worker, so a request that fans out through
 * three services can already be reassembled from logs. What is missing is the
 * SHAPE — where the time went, and which call failed. That information exists
 * only at the pipeline boundaries the kernel owns (security, resolve, load,
 * execute; dequeue, handle, ack), so a project cannot add it from outside.
 *
 * OPENTELEMETRY-SHAPED, DEPENDENCY-FREE
 * -------------------------------------
 * start / attributes / recordException / end is the OTel span lifecycle minus
 * its object graph, so `open-telemetry/api` adapts in a few dozen lines. The
 * kernel imports nothing: an application that wants no tracing binds nothing and
 * pays for nothing.
 *
 * NOT BOUND IS THE NORMAL CASE
 * ----------------------------
 * Nothing in the kernel requires this port. Call sites use {@see NullTracer} when
 * the container has no binding, so tracing is genuinely opt-in and the no-op path
 * is two method calls on a shared immutable object — cheap enough to leave in
 * production code unguarded.
 *
 * An adapter MUST NOT throw. See {@see LoggerPort} for the reasoning.
 */
interface TracerPort
{
    /**
     * Begin a span and return it. The caller MUST end it.
     *
     * Prefer {@see measure()} unless the span genuinely outlives one callable —
     * a manual start() paired with an end() that an exception can skip is the
     * standard way traces come to contain work that never finishes.
     *
     * @param non-empty-string                    $name       e.g. 'http.request', 'db.query'
     * @param array<string, string|int|float|bool> $attributes
     */
    public function start(string $name, array $attributes = []): Span;

    /**
     * Run $work inside a span, ending it on BOTH paths and recording anything
     * thrown before rethrowing it. This is the form nearly every call site wants.
     *
     * @template T
     * @param  non-empty-string                     $name
     * @param  callable(Span): T                    $work
     * @param  array<string, string|int|float|bool> $attributes
     * @return T
     */
    public function measure(string $name, callable $work, array $attributes = []): mixed;

    /**
     * Adopt an inbound W3C `traceparent` so this process's spans join the
     * caller's trace instead of starting a new one. Called by the entry points
     * from the request header; safe to pass '' or a malformed value.
     */
    public function continueFrom(string $traceparent): void;

    /**
     * The active span's `traceparent`, or '' when nothing is recording. Put it on
     * outbound requests — that single header is what makes a trace distributed.
     */
    public function traceparent(): string;
}
