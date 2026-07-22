# HKM Kernel — Worker Pipeline

> The Worker pipeline handles **background job processing**. It shares the same kernel,
> module system, and port abstractions as the HTTP pipeline. Business logic in a Job behaves
> identically whether triggered from HTTP or from a queue.

---

## Worker Pipeline Stages

```
Queue (Redis / Beanstalkd / SQS)
    │
    ▼
DequeueStage
    │   Attaches JobId as CorrelationId for tracing
    ▼
ValidateSignatureStage
    │   Verifies HMAC payload signature — rejects tampered jobs
    ▼
ValidatePayloadStage
    │   Parses and validates job payload as typed DTO
    ▼
OnDemandLoaderStage
    │   Resolves dep graph from JobManifest → loads only needed modules
    ▼
ExecuteJobStage
    │   Calls job->handle(JobPayload) inside a transaction boundary
    ▼
AcknowledgeStage
    │   Removes job from queue on success
    │
    ├── On failure →
    │       RetryStage: exponential backoff (attempt 1: 30s, 2: 900s, 3: 3600s)
    │
    └── After max retries →
            DeadLetterStage: move to DLQ + notify ErrorPipeline
```

---

## JobContract — Every Job Implements This

```php
interface JobContract
{
    /**
     * Execute the job. Return JobResult on success.
     * Throw any Throwable on failure — the pipeline handles retry.
     */
    public function handle(JobPayload $payload): JobResult;

    /**
     * Called after max retries are exhausted — before moving to dead-letter.
     * Use to mark the operation as permanently failed in the database.
     */
    public function failed(JobPayload $payload, \Throwable $e): void;
}
```

---

## JobPayload Contract

```php
interface JobPayloadContract
{
    public function jobId(): string;                  // CorrelationId for this job
    public function jobClass(): string;               // e.g. SendInvoiceEmailJob
    public function data(): array;                    // typed payload data
    public function queue(): string;                  // which queue it came from
    public function attempts(): int;                  // how many times tried so far
    public function maxAttempts(): int;               // from module.json retry.max
    public function enqueuedAt(): \DateTimeImmutable; // when originally queued
    public function signature(): string;              // HMAC for integrity check
    public function isSignatureValid(string $secret): bool;
}
```

---

## Canonical Job Implementation

```php
<?php
declare(strict_types=1);

namespace InvoiceModule\Infrastructure\Jobs;

use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\JobContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Worker\JobPayload;
use AlfacodeTeam\PhpServicePlatform\Kernel\Worker\JobResult;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use InvoiceModule\API\Contracts\InvoiceServiceContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\MailPort;

final class SendInvoiceEmailJob implements JobContract
{
    public function __construct(
        private readonly InvoiceServiceContract $invoices, // via published contract
        private readonly MailPort               $mail,
    ) {}

    public function handle(JobPayload $payload): JobResult
    {
        $dto = SendInvoiceEmailPayload::from($payload->data());

        // Business logic — same patterns as in HTTP services
        $invoice = $this->invoices->find($dto->invoiceId);

        if ($invoice->status() !== 'issued') {
            // Skip — invoice is no longer in the right state. Do NOT retry.
            return JobResult::success([
                'skipped' => true,
                'reason'  => "Invoice status is [{$invoice->status()}], not 'issued'",
            ]);
        }

        $this->mail->send(
            to:      $invoice->clientEmail(),
            subject: "Invoice #{$invoice->number()} is ready",
            view:    'invoice-email',
            data:    ['invoice' => $invoice],
        );

        return JobResult::success(['invoiceId' => $dto->invoiceId, 'sent' => true]);
    }

    public function failed(JobPayload $payload, \Throwable $e): void
    {
        // Called after max retries — mark invoice delivery as permanently failed
        $dto = SendInvoiceEmailPayload::from($payload->data());
        // Could update a delivery_status column, alert support, etc.
    }
}
```

---

## Job module.json

```json
{
  "name":     "job-send-invoice-email",
  "version":  "1.0.0",
  "solves":   "job.send-invoice-email",
  "type":     "job",

  "queue":    "emails",
  "retry": {
    "max":      3,
    "strategy": "exponential",
    "jitter":   true
  },
  "timeout":  30,

  "requires": [
    "mail.port",
    "invoice.generation"
  ],

  "config": [
    "INVOICE_FROM_EMAIL"
  ]
}
```

---

## Job Dispatch — From Service Layer

```php
// In a Service — dispatch a job after committing the transaction
// (same rule as integration events: only after commit)

$this->transaction->begin();
try {
    $invoice = $this->createInvoice($dto);
    $this->repository->save($invoice);
    $this->transaction->commit();
} catch (\Throwable $e) {
    $this->transaction->rollback();
    throw $e;
}

// After commit — dispatch job
$this->queue->push(
    jobClass: SendInvoiceEmailJob::class,
    payload:  ['invoiceId' => $invoice->id()->value()],
    queue:    'emails',
);
```

---

## Retry Strategies

```php
// Exponential backoff with jitter — prevents thundering herd
// base=30, max_delay=3600, jitter=±25%

// Attempt 1: ~30s   (30 ± 7s)
// Attempt 2: ~900s  (900 ± 225s)
// Attempt 3: ~3600s (capped at 3600 ± 900s)

class ExponentialRetryStrategy implements RetryStrategyContract
{
    public function delay(int $attempt, array $config): int
    {
        $base  = $config['base_delay'] ?? 30;
        $max   = $config['max_delay']  ?? 3600;
        $delay = min((int) ($base ** $attempt), $max);

        if ($config['jitter'] ?? true) {
            $range = (int) ($delay * 0.25);
            $delay += random_int(-$range, $range);
        }

        return max(1, $delay);
    }
}
```

---

## Bulk / Long-Running Jobs

```php
final class GenerateMonthlyReportsJob implements JobContract
{
    private const BATCH_SIZE = 50;

    public function handle(JobPayload $payload): JobResult
    {
        $dto       = GenerateReportsPayload::from($payload->data());
        $tenantIds = $this->db->query('SELECT id FROM tenants WHERE plan = :plan',
                                       ['plan' => $dto->plan]);

        $processed = $failed = 0;

        foreach (array_chunk($tenantIds, self::BATCH_SIZE) as $batch) {
            foreach ($batch as $tenant) {
                try {
                    $this->reportService->generateMonthly($tenant['id'], $dto->month);
                    $processed++;
                } catch (\Throwable $e) {
                    $failed++;
                    // Log individually, continue processing other tenants
                    $this->errorConsumer->log($e, 'warning');
                }
            }

            // Update progress after each batch
            $this->operations->updateProgress(
                id:    $payload->jobId(),
                done:  $processed + $failed,
                total: count($tenantIds),
            );

            // Check for graceful shutdown signal
            if ($this->loop->shouldStop()) {
                return JobResult::success(['processed' => $processed, 'stopped_early' => true]);
            }
        }

        return JobResult::success(['processed' => $processed, 'failed' => $failed]);
    }

    public function failed(JobPayload $payload, \Throwable $e): void {}
}
```

---

## JobResult

```php
final class JobResult
{
    private function __construct(
        private readonly bool  $success,
        private readonly array $data,
    ) {}

    public static function success(array $data = []): self
    {
        return new self(true, $data);
    }

    // Use when the job should be considered done but nothing was processed
    public static function skipped(string $reason): self
    {
        return new self(true, ['skipped' => true, 'reason' => $reason]);
    }

    public function isSuccess(): bool { return $this->success; }
    public function data(): array     { return $this->data; }
}
```

---

## Queue Configuration

```php
// config/jobs.php
return [
    'connection'  => env('QUEUE_DRIVER', 'redis'),
    'queues'      => ['critical', 'high', 'default', 'emails', 'reports'],
    'concurrency' => (int) env('WORKER_CONCURRENCY', 4),
    'memory_limit'=> (int) env('WORKER_MEMORY_LIMIT', 128),  // MB
    'poll_interval'=> (int) env('WORKER_SLEEP', 1),           // seconds
    'max_jobs'    => (int) env('WORKER_MAX_JOBS', 1000),      // restart after N jobs
    'timeout'     => (int) env('JOB_TIMEOUT', 60),            // default seconds
];
```

---

## Dead-Letter Queue Management

```bash
# View dead-letter jobs
php cli.php queue:dead-letter

# Retry a specific failed job
php cli.php queue:retry {job-id}

# Retry all failed jobs (dangerous — check why they failed first)
php cli.php queue:retry --all

# Flush the dead-letter queue (destructive — requires --force)
php cli.php queue:flush --queue=dead-letter --force
```

---

## Supervisor Configuration

```ini
[program:sentinel-worker-critical]
command=php /var/www/worker.php --queue=critical --concurrency=2 --max-jobs=500
numprocs=1
autostart=true
autorestart=true
stdout_logfile=/var/log/sentinel/worker-critical.log
stderr_logfile=/var/log/sentinel/worker-critical-error.log
stopwaitsecs=30

[program:sentinel-worker-default]
command=php /var/www/worker.php --queue=default,emails --concurrency=4 --max-jobs=1000
numprocs=2
autostart=true
autorestart=true
stdout_logfile=/var/log/sentinel/worker-default.log
stderr_logfile=/var/log/sentinel/worker-default-error.log
stopwaitsecs=60
```

---

## Rules for Worker / Job Code

When writing or reviewing job code:

- **DO** return `JobResult::skipped($reason)` when the job should not retry (e.g. wrong state)
- **DO** implement `failed()` to record permanent failure in the database
- **DO** process bulk jobs in batches — check `$loop->shouldStop()` between batches
- **DO** dispatch jobs AFTER the transaction commits — same rule as integration events
- **DON'T** throw an exception to skip a job — that triggers retry; return `JobResult::skipped()`
- **DON'T** use infinite loops in jobs — use batch processing with progress tracking
- **DON'T** directly call service methods that dispatch further jobs in a chain longer than 3 hops
- **DON'T** put business logic in the retry/failed handlers — delegate to a service
- **DON'T** access `$_SERVER`, `$_GET`, or any HTTP globals in a job — it runs in a worker process
