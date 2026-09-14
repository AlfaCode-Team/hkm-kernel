<?php

declare(strict_types=1);

namespace Tests\Support\Fakes;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\{Span, TracerPort};

final class RecordingTracer implements TracerPort
{
    public ?RecordingSpan $span = null;
    public string $continued = '';

    /** @var list<string> */
    public array $started = [];

    public function start(string $name, array $attributes = []): Span
    {
        $this->started[] = $name;
        $span = new RecordingSpan();
        $span->setAttributes($attributes);

        return $this->span = $span;
    }

    public function measure(string $name, callable $work, array $attributes = []): mixed
    {
        return $work($this->start($name, $attributes));
    }

    public function continueFrom(string $traceparent): void { $this->continued = $traceparent; }

    public function traceparent(): string { return $this->span?->traceparent() ?? ''; }
}
