<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports;

/**
 * NullSpan — what {@see NullTracer} hands back.
 *
 * Every method is empty and traceparent() returns '', which callers already
 * treat as "not recording" (the value is only ever forwarded as a header, and an
 * empty header is not sent).
 */
final class NullSpan implements Span
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function setAttribute(string $key, string|int|float|bool $value): void {}

    public function setAttributes(array $attributes): void {}

    public function recordException(\Throwable $e): void {}

    public function setError(string $description = ''): void {}

    public function end(): void {}

    public function traceparent(): string
    {
        return '';
    }
}
