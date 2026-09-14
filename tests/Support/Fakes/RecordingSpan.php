<?php

declare(strict_types=1);

namespace Tests\Support\Fakes;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Span;

final class RecordingSpan implements Span
{
    /** @var array<string, string|int|float|bool> */
    public array $attributes = [];
    public ?\Throwable $exception = null;
    public ?string $error = null;
    public int $ended = 0;

    public function setAttribute(string $key, string|int|float|bool $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function setAttributes(array $attributes): void
    {
        $this->attributes = [...$this->attributes, ...$attributes];
    }

    public function recordException(\Throwable $e): void { $this->exception = $e; }

    public function setError(string $description = ''): void { $this->error = $description; }

    public function end(): void { $this->ended++; }

    public function traceparent(): string { return '00-trace-span-01'; }
}
