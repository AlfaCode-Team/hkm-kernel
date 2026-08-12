<?php

declare(strict_types=1);

namespace Project\Infrastructure;

use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\JobPayload;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\QueuePort;

/**
 * FileQueue — a dependency-free, cross-process QueuePort adapter.
 *
 * Jobs are appended as JSON lines to one file per queue under var/queue/. Unlike
 * an in-memory queue, this survives between processes, so a job pushed by a web
 * request can be popped by a separate `php app/worker/run.php` process — enough
 * to run the worker end-to-end without Redis/SQS. Swap for RedisQueueAdapter in
 * production (it overrides this when REDIS_HOST is set).
 *
 * Not for high throughput: writes take an exclusive lock and pop rewrites the
 * file. It is a correct, simple default — not a broker.
 *
 * Implements the full QueuePort lifecycle (push/pop/ack/release/fail) on this
 * concrete adapter and rebuilds a JobPayload from the record.
 */
final class FileQueue implements QueuePort
{
    public function __construct(
        private readonly string $dir,
        private readonly int $defaultMaxAttempts = 3,
    ) {
    }

    public function push(string $jobClass, array $payload, string $queue = 'default', int $delay = 0): string
    {
        $jobId = bin2hex(random_bytes(8));

        $record = [
            'jobId'       => $jobId,
            'jobClass'    => $jobClass,
            'data'        => $payload,
            'queue'       => $queue,
            'attempts'    => 0,
            'maxAttempts' => $this->defaultMaxAttempts,
            'enqueuedAt'  => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'availableAt' => time() + max(0, $delay),
        ];

        $this->append($queue, $record);

        return $jobId;
    }

    public function later(int $seconds, string $jobClass, array $payload, string $queue = 'default'): string
    {
        return $this->push($jobClass, $payload, $queue, $seconds);
    }

    public function size(string $queue = 'default'): int
    {
        $file = $this->file($queue);

        if (!is_file($file)) {
            return 0;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return $lines === false ? 0 : count($lines);
    }

    /**
     * Reserve the next due job, or null when the queue is empty.
     *
     * Implements the QueuePort read side. Removes the record from the file under
     * an exclusive lock, so two workers cannot take the same job.
     */
    public function pop(string $queue = 'default'): ?JobPayload
    {
        $record = $this->popRecord($queue);

        return $record === null ? null : $this->hydrate($record, $queue);
    }

    /**
     * The job completed — nothing to do, popRecord() already removed the line.
     * Explicit so the four-verb lifecycle reads the same across adapters.
     */
    public function ack(JobPayload $payload): void
    {
    }

    /** Re-append with an incremented attempt count, available again after $delay. */
    public function release(JobPayload $payload, int $delay = 0): void
    {
        $this->append($payload->queue(), [
            'jobId'       => $payload->jobId(),
            'jobClass'    => $payload->jobClass(),
            'data'        => $payload->data(),
            'queue'       => $payload->queue(),
            'attempts'    => $payload->attempts() + 1,
            'maxAttempts' => $payload->maxAttempts(),
            'enqueuedAt'  => $payload->enqueuedAt()->format(\DateTimeInterface::ATOM),
            'availableAt' => time() + max(0, $delay),
        ]);
    }

    /**
     * Write to a sibling dead-letter file rather than dropping the job. A
     * permanently-failing job that simply vanishes is the hardest kind of bug
     * to notice.
     */
    public function fail(JobPayload $payload, ?\Throwable $reason = null): void
    {
        $this->append($payload->queue() . '.failed', [
            'jobId'       => $payload->jobId(),
            'jobClass'    => $payload->jobClass(),
            'data'        => $payload->data(),
            'queue'       => $payload->queue(),
            'attempts'    => $payload->attempts(),
            'maxAttempts' => $payload->maxAttempts(),
            'enqueuedAt'  => $payload->enqueuedAt()->format(\DateTimeInterface::ATOM),
            'failedAt'    => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'error'       => $reason?->getMessage(),
        ]);
    }

    /** @param array<string, mixed> $record */
    private function hydrate(array $record, string $queue): JobPayload
    {
        return new JobPayload(
            jobId:       (string) ($record['jobId'] ?? ''),
            jobClass:    (string) ($record['jobClass'] ?? ''),
            data:        (array) ($record['data'] ?? []),
            queue:       (string) ($record['queue'] ?? $queue),
            attempts:    (int) ($record['attempts'] ?? 0),
            maxAttempts: (int) ($record['maxAttempts'] ?? $this->defaultMaxAttempts),
            enqueuedAt:  new \DateTimeImmutable((string) ($record['enqueuedAt'] ?? 'now')),
            signature:   (string) ($record['signature'] ?? ''),
        );
    }

    /**
     * Pop the next due record (FIFO), or null when the queue is empty. Rewrites
     * the file without the popped line under an exclusive lock.
     *
     * @return array<string, mixed>|null
     */
    private function popRecord(string $queue = 'default'): ?array
    {
        $file = $this->file($queue);

        if (!is_file($file)) {
            return null;
        }

        $handle = fopen($file, 'c+');
        if ($handle === false) {
            return null;
        }

        try {
            flock($handle, LOCK_EX);

            $contents = stream_get_contents($handle) ?: '';
            $lines = array_values(array_filter(explode("\n", $contents), static fn(string $l): bool => trim($l) !== ''));

            $now = time();
            foreach ($lines as $i => $line) {
                $record = json_decode($line, true);
                if (!is_array($record)) {
                    unset($lines[$i]);
                    continue;
                }
                if (($record['availableAt'] ?? 0) > $now) {
                    continue;   // delayed — not due yet
                }

                // Remove this line and rewrite.
                unset($lines[$i]);
                $this->rewrite($handle, $lines);

                return $record;
            }

            return null;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @param array<string, mixed> $record */
    private function append(string $queue, array $record): void
    {
        $file = $this->file($queue);
        $line = json_encode($record, JSON_UNESCAPED_SLASHES) . "\n";

        file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * @param resource     $handle
     * @param list<string> $lines
     */
    private function rewrite($handle, array $lines): void
    {
        ftruncate($handle, 0);
        rewind($handle);
        if ($lines !== []) {
            fwrite($handle, implode("\n", $lines) . "\n");
        }
    }

    private function file(string $queue): string
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new \RuntimeException("Cannot create queue directory: {$this->dir}");
        }

        $safe = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $queue) ?? 'default';

        return rtrim($this->dir, '/') . '/' . $safe . '.jsonl';
    }
}
