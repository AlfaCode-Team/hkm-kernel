<?php

declare(strict_types=1);

namespace Tests\Feature\Support\Fakes;

use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\JobPayload;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\QueuePort;

/** An in-memory QueuePort: pushes are inspectable, pops are real FIFO. */
final class ArrayQueue implements QueuePort
{
    /** @var array<string, list<array{job: string, payload: array<string, mixed>, delay: int, id: string}>> */
    public array $queues = [];

    /** @var list<string> ids acked */
    public array $acked = [];

    /** @var list<string> ids failed (dead-lettered) */
    public array $failed = [];

    /** @var list<array{id: string, delay: int}> */
    public array $released = [];

    private int $sequence = 0;

    public function push(string $jobClass, array $payload, string $queue = 'default', int $delay = 0): string
    {
        $id = 'job-' . (++$this->sequence);
        $this->queues[$queue][] = ['job' => $jobClass, 'payload' => $payload, 'delay' => $delay, 'id' => $id];

        return $id;
    }

    public function later(int $seconds, string $jobClass, array $payload, string $queue = 'default'): string
    {
        return $this->push($jobClass, $payload, $queue, $seconds);
    }

    public function size(string $queue = 'default'): int
    {
        return count($this->queues[$queue] ?? []);
    }

    public function pop(string $queue = 'default'): ?JobPayload
    {
        $next = array_shift($this->queues[$queue]);

        if ($next === null) {
            return null;
        }

        return new JobPayload(
            jobId:       $next['id'],
            jobClass:    $next['job'],
            data:        $next['payload'],
            queue:       $queue,
            attempts:    1,
            maxAttempts: 3,
            enqueuedAt:  new \DateTimeImmutable(),
            // Unsigned. A worker built with a signing secret will reject and
            // dead-letter this, which is the correct behaviour to test against —
            // a fake that forged a valid signature would hide exactly the bug
            // signing exists to catch.
            signature:   '',
        );
    }

    public function ack(JobPayload $payload): void
    {
        $this->acked[] = $payload->jobId();
    }

    public function release(JobPayload $payload, int $delay = 0): void
    {
        $this->released[] = ['id' => $payload->jobId(), 'delay' => $delay];
    }

    public function fail(JobPayload $payload, ?\Throwable $reason = null): void
    {
        $this->failed[] = $payload->jobId();
    }

    /** @return list<string> job classes pushed onto $queue, in order */
    public function pushed(string $queue = 'default'): array
    {
        return array_column($this->queues[$queue] ?? [], 'job');
    }
}
