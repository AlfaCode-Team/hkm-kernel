<?php

declare(strict_types=1);

namespace Plugins\RedisCache\Infrastructure;

use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\JobPayload;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\QueuePort;

/**
 * Redis-backed QueuePort adapter (GDA rewrite of the 0.3 Redis queue layer).
 *
 * Immediate jobs are pushed onto a Redis list (LPUSH); the worker pulls with
 * RPOP/BRPOP. Delayed jobs go onto a per-queue sorted set scored by their ready
 * timestamp — the WorkerPipeline promotes due jobs onto the list when it drains
 * (promoteDue() is exposed for that puller). Each job carries a generated id.
 */
final class RedisQueueAdapter implements QueuePort
{
    public function __construct(private readonly RedisConnection $connection) {}

    public function push(string $jobClass, array $payload, string $queue = 'default', int $delay = 0): string
    {
        return $delay > 0
            ? $this->later($delay, $jobClass, $payload, $queue)
            : $this->dispatch($jobClass, $payload, $queue, readyAt: null);
    }

    public function later(int $seconds, string $jobClass, array $payload, string $queue = 'default'): string
    {
        return $this->dispatch($jobClass, $payload, $queue, readyAt: time() + max(0, $seconds));
    }

    public function size(string $queue = 'default'): int
    {
        $client = $this->connection->client();
        $ready  = (int) $client->lLen($this->listKey($queue));
        $delayed = (int) $client->zCard($this->delayedKey($queue));
        return $ready + $delayed;
    }

    /**
     * Move any delayed jobs that are now due onto the ready list. Returns the
     * count promoted. Intended to be called by the worker puller each tick.
     */
    public function promoteDue(string $queue = 'default'): int
    {
        $client    = $this->connection->client();
        $delayedKey = $this->delayedKey($queue);
        $due = $client->zRangeByScore($delayedKey, '-inf', (string) time());
        if ($due === false || $due === []) {
            return 0;
        }
        $promoted = 0;
        foreach ($due as $job) {
            // Remove first so a concurrent worker cannot double-promote.
            if ((int) $client->zRem($delayedKey, $job) === 1) {
                $client->lPush($this->listKey($queue), $job);
                $promoted++;
            }
        }
        return $promoted;
    }

    /**
     * Reserve the next due job.
     *
     * Promotes any due delayed jobs first, then RPOPs one envelope. Non-blocking
     * by contract — WorkerLoop owns the idle backoff, so BRPOP here would defeat
     * both its stop() and its iteration cap.
     */
    public function pop(string $queue = 'default'): ?JobPayload
    {
        $this->promoteDue($queue);

        $raw = $this->connection->client()->rPop($this->listKey($queue));
        if ($raw === false || !is_string($raw) || $raw === '') {
            return null;
        }

        $env = json_decode($raw, true);
        if (!is_array($env)) {
            return null; // unparseable envelope — drop rather than crash the worker
        }

        return new JobPayload(
            jobId:       (string) ($env['id'] ?? ''),
            jobClass:    (string) ($env['jobClass'] ?? ''),
            data:        (array) ($env['payload'] ?? []),
            queue:       $queue,
            attempts:    (int) ($env['attempts'] ?? 0),
            maxAttempts: (int) ($env['maxAttempts'] ?? 3),
            enqueuedAt:  new \DateTimeImmutable('@' . (int) ($env['enqueuedAt'] ?? time())),
            signature:   (string) ($env['signature'] ?? ''),
        );
    }

    /**
     * Nothing to do: pop() already removed the envelope from the list, so a
     * completed job is gone. Kept explicit so the four-verb lifecycle reads the
     * same across adapters.
     */
    public function ack(JobPayload $payload): void
    {
    }

    /** Re-enqueue with an incremented attempt count, delayed by $delay seconds. */
    public function release(JobPayload $payload, int $delay = 0): void
    {
        $env = $this->envelope($payload, $payload->attempts() + 1);

        $client = $this->connection->client();
        if ($delay > 0) {
            $client->zAdd($this->delayedKey($payload->queue()), time() + $delay, $env);

            return;
        }

        $client->lPush($this->listKey($payload->queue()), $env);
    }

    /**
     * Move to the dead-letter list rather than dropping. A permanently-failing
     * job that simply vanishes is the hardest kind of bug to notice.
     */
    public function fail(JobPayload $payload, ?\Throwable $reason = null): void
    {
        $this->connection->client()->lPush(
            $this->failedKey($payload->queue()),
            $this->envelope($payload, $payload->attempts(), $reason?->getMessage()),
        );
    }

    private function envelope(JobPayload $payload, int $attempts, ?string $error = null): string
    {
        return json_encode(array_filter([
            'id'          => $payload->jobId(),
            'jobClass'    => $payload->jobClass(),
            'payload'     => $payload->data(),
            'queue'       => $payload->queue(),
            'attempts'    => $attempts,
            'maxAttempts' => $payload->maxAttempts(),
            'enqueuedAt'  => $payload->enqueuedAt()->getTimestamp(),
            'signature'   => $payload->signature(),
            'error'       => $error,
        ], static fn($v): bool => $v !== null), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function dispatch(string $jobClass, array $payload, string $queue, ?int $readyAt): string
    {
        $id  = bin2hex(random_bytes(16));
        $env = json_encode([
            'id'          => $id,
            'jobClass'    => $jobClass,
            'payload'     => $payload,
            'queue'       => $queue,
            'attempts'    => 0,
            'maxAttempts' => 3,
            'enqueuedAt'  => time(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';

        $client = $this->connection->client();
        if ($readyAt === null) {
            $client->lPush($this->listKey($queue), $env);
        } else {
            $client->zAdd($this->delayedKey($queue), $readyAt, $env);
        }
        return $id;
    }

    private function listKey(string $queue): string
    {
        return $this->connection->prefix('queue:' . $queue);
    }

    private function delayedKey(string $queue): string
    {
        return $this->connection->prefix('queue:' . $queue . ':delayed');
    }

    private function failedKey(string $queue): string
    {
        return $this->connection->prefix('queue:' . $queue . ':failed');
    }
}
