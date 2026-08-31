<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Fakes;

use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\JobPayload;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\QueuePort;

/**
 * An in-memory QueuePort that runs nothing.
 *
 * Pushed jobs stay pushed. That is what a service test wants to assert — "this
 * enqueued seo.indexnow with these URLs" — without a worker, a Redis, or the job
 * class even existing yet. Draining is explicit (`pop()`), so a test that means
 * to exercise the handler does so deliberately.
 */
final class FakeQueue implements QueuePort
{
    /** The secret FakeQueue signs payloads with — pass it to any signature check under test. */
    public const SECRET = 'ground-fake-queue-secret';

    /**
     * The attempt budget stamped on every pushed payload.
     *
     * It is part of the SIGNED material, so it cannot be an inline literal in
     * two places: push() signing one number while the payload carries another
     * produces an envelope that verifies against nothing.
     */
    public const MAX_ATTEMPTS = 3;

    /** @var array<string, list<JobPayload>> queue name => pending payloads */
    private array $queues = [];

    /** @var list<array{job: string, payload: array<string, mixed>, queue: string, delay: int}> */
    public array $pushed = [];

    /** @var list<JobPayload> */
    public array $acked = [];

    /** @var list<array{payload: JobPayload, delay: int}> */
    public array $released = [];

    /** @var list<array{payload: JobPayload, reason: ?\Throwable}> */
    public array $failed = [];

    private int $counter = 0;

    public function push(string $jobClass, array $payload, string $queue = 'default', int $delay = 0): string
    {
        $id = 'job-' . (++$this->counter);

        $this->pushed[] = ['job' => $jobClass, 'payload' => $payload, 'queue' => $queue, 'delay' => $delay];

        $this->queues[$queue][] = new JobPayload(
            jobId:       $id,
            jobClass:    $jobClass,
            data:        $payload,
            queue:       $queue,
            attempts:    0,
            maxAttempts: self::MAX_ATTEMPTS,
            enqueuedAt:  new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            // Signed THROUGH THE KERNEL's own signer, never a hand-rolled HMAC.
            // The kernel signs jobId|jobClass|queue|maxAttempts|canonical(data);
            // an HMAC over the data alone verifies against nothing, so every
            // payload this fake produced was silently signature-INVALID and a
            // plugin testing a signed queue could not get a passing run.
            signature:   JobPayload::sign(self::SECRET, $id, $jobClass, $payload, $queue, self::MAX_ATTEMPTS),
        );

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
        if (($this->queues[$queue] ?? []) === []) {
            return null;
        }

        return array_shift($this->queues[$queue]);
    }

    public function ack(JobPayload $payload): void
    {
        $this->acked[] = $payload;
    }

    public function release(JobPayload $payload, int $delay = 0): void
    {
        $this->released[] = ['payload' => $payload, 'delay' => $delay];

        // A real driver INCREMENTS the attempt counter when it re-queues, and
        // JobPayload is `final readonly`, so a new one must be built.
        //
        // Re-queuing the same instance was a silent, load-bearing bug: attempts
        // stayed at 0 forever, so hasExceededMaxAttempts() never became true,
        // WorkerLoop released instead of dead-lettering, and a failing job could
        // be drained a hundred times without ever exhausting. Any test asserting
        // "this job eventually gives up" could not fail.
        $this->queues[$payload->queue()][] = new JobPayload(
            jobId:       $payload->jobId(),
            jobClass:    $payload->jobClass(),
            data:        $payload->data(),
            queue:       $payload->queue(),
            attempts:    $payload->attempts() + 1,
            maxAttempts: $payload->maxAttempts(),
            enqueuedAt:  $payload->enqueuedAt(),
            signature:   $payload->signature(),
        );
    }

    public function fail(JobPayload $payload, ?\Throwable $reason = null): void
    {
        $this->failed[] = ['payload' => $payload, 'reason' => $reason];
    }

    // ── Asserting ─────────────────────────────────────────────────────────────

    /** True when a job of this class was pushed at least once. */
    public function wasPushed(string $jobClass): bool
    {
        foreach ($this->pushed as $p) {
            if ($p['job'] === $jobClass) {
                return true;
            }
        }

        return false;
    }

    /** How many times a job class was pushed. */
    public function pushCount(string $jobClass): int
    {
        return count(array_filter($this->pushed, static fn(array $p): bool => $p['job'] === $jobClass));
    }

    /** Every payload pushed for a job class, in order. */
    public function payloadsFor(string $jobClass): array
    {
        return array_values(array_map(
            static fn(array $p): array => $p['payload'],
            array_filter($this->pushed, static fn(array $p): bool => $p['job'] === $jobClass),
        ));
    }

    public function reset(): void
    {
        $this->queues   = [];
        $this->pushed   = [];
        $this->acked    = [];
        $this->released = [];
        $this->failed   = [];
    }
}
