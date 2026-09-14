<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports;

/**
 * NullTracer — the default when no tracing adapter is bound.
 *
 * measure() still RUNS the work and still lets an exception propagate; it simply
 * records nothing. That is what makes it safe to write kernel code against the
 * tracer unconditionally: turning tracing off must change what is observed, never
 * what happens.
 */
final class NullTracer implements TracerPort
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function start(string $name, array $attributes = []): Span
    {
        return NullSpan::instance();
    }

    public function measure(string $name, callable $work, array $attributes = []): mixed
    {
        // No try/finally: there is no span to close, and wrapping the call would
        // add a frame to every stack trace in an application that opted out of
        // tracing entirely.
        return $work(NullSpan::instance());
    }

    public function continueFrom(string $traceparent): void {}

    public function traceparent(): string
    {
        return '';
    }
}
