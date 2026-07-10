<?php

declare(strict_types=1);

namespace Plugins\Mail\Infrastructure\Transport;

/**
 * Captures messages in memory instead of sending them — for tests and local dev.
 * Assert on {@see messages()} / {@see last()}.
 */
final class ArrayTransport implements Transport
{
    /** @var list<array{from: string, recipients: list<string>, mime: string}> */
    private array $sent = [];

    public function send(string $envelopeFrom, array $recipients, string $mime): void
    {
        $this->sent[] = ['from' => $envelopeFrom, 'recipients' => $recipients, 'mime' => $mime];
    }

    /** @return list<array{from: string, recipients: list<string>, mime: string}> */
    public function messages(): array
    {
        return $this->sent;
    }

    /** @return array{from: string, recipients: list<string>, mime: string}|null */
    public function last(): ?array
    {
        return $this->sent[array_key_last($this->sent)] ?? null;
    }

    public function count(): int
    {
        return count($this->sent);
    }

    public function flush(): void
    {
        $this->sent = [];
    }
}
