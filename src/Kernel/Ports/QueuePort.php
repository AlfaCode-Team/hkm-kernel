<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Ports;

use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\JobPayload;

/**
 * QueuePort — the ONLY way modules enqueue AND consume background work.
 *
 * The kernel defines this interface; the project provides the adapter.
 *
 * WHY THE READ SIDE EXISTS
 * ------------------------
 * This port used to be write-only (push/later/size). Consumption happened
 * through a `callable $puller` the PROJECT passed to WorkerLoop, which meant the
 * abstraction leaked in the worst possible way — the shipped worker template
 * contained:
 *
 *     if (!$queueAdapter instanceof FileQueue) {
 *         return null;   // unknown backend — stay idle rather than guess its API
 *     }
 *
 * So swapping FileQueue for Redis did not fail: the worker silently processed
 * nothing, forever. A port whose consumer must type-check the concrete adapter
 * is not a port. pop()/ack()/release()/fail() close that.
 *
 * THE FOUR-VERB LIFECYCLE
 * -----------------------
 *   pop      reserve the next due job (or null when idle)
 *   ack      it succeeded — remove it permanently
 *   release  it failed but may be retried — return it to the queue after $delay
 *   fail     it exhausted its attempts — move it to the dead-letter store
 *
 * Every popped job MUST reach exactly one of ack/release/fail. An adapter that
 * deletes on pop() loses jobs when a worker dies mid-handle; one that only peeks
 * hands the same job to every worker at once. Reserve-then-resolve is the only
 * shape that survives a crashing consumer.
 */
interface QueuePort
{
    /** @param array<string, mixed> $payload @return string job id */
    public function push(string $jobClass, array $payload, string $queue = 'default', int $delay = 0): string;

    /** @param array<string, mixed> $payload @return string job id */
    public function later(int $seconds, string $jobClass, array $payload, string $queue = 'default'): string;

    /** Ready + delayed jobs waiting on the queue. */
    public function size(string $queue = 'default'): int;

    /**
     * Reserve the next due job, or null when the queue is empty.
     *
     * Must NOT block: WorkerLoop owns the idle backoff, so an adapter that
     * blocked here would defeat the loop's stop() and its iteration cap.
     */
    public function pop(string $queue = 'default'): ?JobPayload;

    /** The job completed. Remove it permanently. */
    public function ack(JobPayload $payload): void;

    /**
     * The job failed but has attempts left. Return it to the queue, available
     * again after $delay seconds, with its attempt count incremented.
     */
    public function release(JobPayload $payload, int $delay = 0): void;

    /**
     * The job exhausted its attempts. Move it out of the queue for inspection —
     * never silently drop it, or a permanently-failing job disappears without
     * anyone learning why.
     */
    public function fail(JobPayload $payload, ?\Throwable $reason = null): void;
}
