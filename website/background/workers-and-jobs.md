# Workers and jobs — background task execution

The kernel provides a `WorkerLoop` that drains a queue and executes `JobContract` implementations. Jobs run in a `ModuleContainer` with full access to services and the dependency graph, making them first-class citizens with the same infrastructure as HTTP requests.

## WorkerLoop

Long-running consumer that executes queued jobs. Runs under supervisor (systemd, Docker, etc.) and processes jobs one at a time until stopped or memory-limited.

```php
final class WorkerLoop
{
    public function run(
        ?callable $puller = null,
        int $maxIterations = 0,
        string $queue = 'default',
        int $memoryLimitMb = 0,
    ): void
}
```

- `$puller` — optional callable returning the next `JobPayload` or null. When null, the loop uses the bound `QueuePort`.
- `$maxIterations` — max jobs to process before exiting (0 = infinite).
- `$queue` — which queue to drain (used only in port mode).
- `$memoryLimitMb` — stop the loop when process resident size exceeds this (0 = disabled). A supervised worker is expected to exit and be restarted; this lets it exit between jobs rather than being OOM-killed.

### Running a worker

```php
// In a worker entry point (e.g., bin/worker)
$kernel = require __DIR__ . '/../bootstrap/app.php';
$kernel->workerLoop()->run();
```

Or with iteration limits:

```php
$kernel->workerLoop()->run(
    maxIterations: 100,  // process 100 jobs then exit
    memoryLimitMb: 512,  // exit if resident size > 512MB
);
```

### Graceful shutdown

Catch SIGTERM/SIGINT and stop the loop:

```php
$loop = $kernel->workerLoop();

pcntl_signal(SIGTERM, function () use ($loop) {
    $loop->stop();
});

$loop->run();
// Finishes the current job, ack/release/fail, then exits
```

## JobContract

Every background job implements this interface:

```php
interface JobContract
{
    public function handle(JobPayload $payload): JobResult;
    public function failed(JobPayload $payload, \Throwable $e): void;
}
```

- `handle($payload)` — the main job logic. Throw to trigger retry (if attempts remain). Return `JobResult::success()` or `JobResult::skipped()`.
- `failed($payload, $e)` — called once max attempts are exhausted. Use it for cleanup or dead-letter logging.

### Example job

```php
<?php declare(strict_types=1);
namespace App\Jobs;

use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\{
    Contracts\JobContract,
    JobPayload,
    JobResult,
};
use App\Invoice\API\Contracts\InvoiceServiceContract;

final class GenerateInvoicePdf implements JobContract
{
    public function __construct(
        private readonly InvoiceServiceContract $invoiceService,
    ) {}

    public function handle(JobPayload $payload): JobResult
    {
        $invoiceId = $payload->data()['invoice_id'];
        
        // Check idempotency — if already processed, skip
        // (cache, database flag, or deduplication table)
        
        $invoice = $this->invoiceService->find($invoiceId);
        $pdf = $invoice->generatePdf();
        $pdf->store(storage_path("invoices/$invoiceId.pdf"));

        return JobResult::success(['pdf_path' => $pdf->path()]);
    }

    public function failed(JobPayload $payload, \Throwable $e): void
    {
        $invoiceId = $payload->data()['invoice_id'];
        \error_log("Failed to generate PDF for invoice $invoiceId: " . $e->getMessage());
    }
}
```

## JobPayload

A dequeued unit of work, as the queue handed it over.

```php
final readonly class JobPayload
{
    public function jobId(): string;
    public function jobClass(): string;
    public function data(): array;
    public function queue(): string;
    public function attempts(): int;
    public function maxAttempts(): int;
    public function enqueuedAt(): \DateTimeImmutable;
    public function signature(): string;
    public function signatureFor(string $secret): string;
    public function isSignatureValid(string $secret): bool;
    public function hasExceededMaxAttempts(): bool;
}
```

The signature covers the producer-authored fields (`jobId`, `jobClass`, `queue`, `maxAttempts`, `data`), not `attempts` (which the queue increments). An unsigned job with a configured signing secret is rejected.

## JobResult

Outcome of a job's `handle()` method.

```php
public static function success(array $data = []): JobResult;
public static function skipped(string $reason): JobResult;

public function isSuccess(): bool;
public function isSkipped(): bool;
public function skipReason(): string;
public function data(): array;
```

- `success($data)` — job completed. The loop ack's the payload and optionally logs the $data.
- `skipped($reason)` — job did not need to run (idempotency check, prerequisite not met). Treated as success but logged as skipped.

Throw an exception from `handle()` to fail the job and trigger retry (if attempts remain).

## RejectedJobException

Thrown when a job fails signature verification or payload validation before `handle()` runs.

```php
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\RejectedJobException;

try {
    $loop->run();
} catch (RejectedJobException $e) {
    // Signature invalid, payload malformed, class not found
    // The job is dead-lettered without retry
}
```

## Retry strategies

When `handle()` throws and attempts remain, the job is released back to the queue after a delay computed by a `RetryStrategyContract`:

```php
interface RetryStrategyContract
{
    public function delayFor(int $attempt): int;   // seconds before the next attempt
}
```

Three strategies ship with the kernel:

| Class | Constructor (defaults) | `delayFor($attempt)` |
|---|---|---|
| `ExponentialRetryStrategy` | `(int $baseSeconds = 5, int $capSeconds = 3600, bool $jitter = true)` | `min(base * 2^(attempt-1), cap)`; with jitter, a random value between half of that and all of it |
| `LinearRetryStrategy` | `(int $baseSeconds = 30, int $capSeconds = 600)` | `min(base * attempt, cap)` |
| `FixedRetryStrategy` | `(int $seconds = 60)` | always `seconds` |

```php
new ExponentialRetryStrategy(baseSeconds: 60, capSeconds: 3600, jitter: true);
// attempt 1: 30–60s, 2: 60–120s, 3: 120–240s, ... never above 3600s
```

Jitter spreads retries out, so a burst of failures does not retry in lockstep against a recovering dependency.

**Which strategy a job gets:** a job that declares `retry` in `module.json` gets the strategy built from that declaration (below). A job that declares nothing uses the loop-wide strategy passed to `WorkerLoop`, which defaults to `new ExponentialRetryStrategy()` (5s base, 1h cap, jitter on). To use your own algorithm loop-wide, implement `RetryStrategyContract` and pass it to the `WorkerLoop` constructor.

## Job declarations in module.json

Each `jobs[]` entry is either a string (the job name, which is also its handler class) or an object:

```jsonc
{
  "name": "invoicing",
  "solves": "invoice.generation",
  "jobs": [
    "Shop\\Invoicing\\Jobs\\SendInvoiceEmail",
    {
      "name": "invoices.generate-pdf",
      "handler": "Shop\\Invoicing\\Jobs\\GenerateInvoicePdf",
      "queue": "reports",
      "retry": { "max": 5, "strategy": "exponential", "base": 60, "jitter": true },
      "timeout": 30
    }
  ]
}
```

| Key | Default | Meaning |
|---|---|---|
| `name` | required | The job's name, the key producers push and the manifest is indexed by. **An object entry without `name` is silently skipped.** |
| `handler` | the `name` | Class implementing `JobContract`. |
| `queue` | `default` | Queue the job belongs to. |
| `retry` | none (loop default) | See below. `"retry": 5` is shorthand for `{ "max": 5 }`. |
| `timeout` | none (unbounded) | Seconds one attempt may run, enforced with `pcntl_alarm`. |

`retry` object keys, as compiled by `CompileJobManifestStage`:

| Key | Default | Notes |
|---|---|---|
| `max` | `3` | Total attempts; values below 1 become 1. |
| `strategy` | `exponential` | `exponential`, `linear` or `fixed`; anything else becomes `exponential`. |
| `base` | `1` | Seconds; `delay` is accepted as an alias. Minimum 1. For `fixed` it is the constant delay. |
| `jitter` | `false` | Exponential only. |

The cap is not configurable from `module.json`: declared strategies use the class defaults (`3600`s exponential, `600`s linear). A module whose `type` is `job` may also put `retry` and `timeout` at the top level of `module.json`; they apply to its entries that declare none.

`timeout` is best effort: `SIGALRM` is dispatched between opcodes, so it stops a runaway loop but not a job blocked inside one long database query or socket read. Without `ext-pcntl`, jobs run unbounded. A hard limit belongs in the driver (statement or socket timeout).

The `retry` and `timeout` entries are compiled into `job-manifest.php` and read by the worker per job.

## Enqueueing jobs

From a service or controller:

```php
$queue->push(
    jobClass: GenerateInvoicePdf::class,
    payload: ['invoice_id' => $invoiceId],
    queue: 'reports',
    delay: 0,
);

// Schedule for later:
$queue->later(
    seconds: 3600,
    jobClass: GenerateInvoicePdf::class,
    payload: ['invoice_id' => $invoiceId],
);
```

## Job signing — optional but recommended

Every job signed with a secret so the worker verifies the producer's authenticity. Turned on via:

```php
Kernel::configure()->withWorkerSecret(env('JOB_SIGNING_SECRET'))
// or
Kernel::configure()->withWorkerSecret(env('APP_KEY'))
```

Signing is OFF by default (historical behaviour). Turning it on requires the `QueuePort` adapter to stamp `JobPayload::signatureFor(secret)` onto the envelope at `push()` time. An unsigned job is rejected as `RejectedJobException` and dead-lettered without retry.

The signature covers: `jobId | jobClass | queue | maxAttempts | canonical(data)`. The `attempts` counter is NOT signed (it's incremented by the queue on release, and re-signing would invalidate).

## Essential modules in jobs

Essential modules (declared in `proj.json` `essentials[]` or `Kernel::withEssentialModules()`) are registered into every job's container, just like HTTP requests. This ensures tenancy, session, or other request-scoped infrastructure is available to the job:

```php
// proj.json
{
  "essentials": ["tenancy.routing", "session"]
}
```

Without this, a job would see no tenant context and try to connect to the shared database — silently wrong.

## Memory safety

Each job gets a fresh `ModuleContainer` from the `OnDemandLoader`, and it is dropped when the job finishes, so module bindings, the job's `Identity` and transaction state cannot carry over to the next job. What can leak in a long-running worker is state you keep yourself: `static` properties, globals, and anything bound into the `CoreContainer`. Pass a memory limit to `run()` so a worker exits between jobs before growth turns into an OOM kill.

## Testing jobs

Create a fake queue:

```php
<?php declare(strict_types=1);
namespace Tests\Fixtures;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\QueuePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\JobPayload;

final class InMemoryQueue implements QueuePort
{
    private array $jobs = [];
    private int $nextId = 1;

    public function push(string $jobClass, array $payload, string $queue = 'default', int $delay = 0): string
    {
        $id = (string) $this->nextId++;
        $this->jobs[] = [
            'id' => $id,
            'class' => $jobClass,
            'payload' => $payload,
            'queue' => $queue,
        ];
        return $id;
    }

    public function assertPushed(string $jobClass): void
    {
        $classes = array_column($this->jobs, 'class');
        if (!in_array($jobClass, $classes, true)) {
            throw new \Exception("Job $jobClass was not pushed");
        }
    }

    // ... other methods
}
```

Test that a service enqueues the right job:

```php
public function testEnqueuesJobOnIssue()
{
    $queue = new InMemoryQueue();
    $service = new InvoiceService(..., queue: $queue);
    
    $service->issue($invoiceId);
    
    $queue->assertPushed(GenerateInvoicePdf::class);
}
```

## Common patterns

### Idempotency via database flag

Mark a job as processed to survive retries:

```php
public function handle(JobPayload $payload): JobResult
{
    $invoiceId = $payload->data()['invoice_id'];
    
    $invoice = $this->repository->find($invoiceId);
    if ($invoice->pdfGenerated) {
        return JobResult::skipped('PDF already generated');
    }
    
    // Generate PDF...
    
    $invoice->markPdfGenerated();
    $this->repository->save($invoice);
    
    return JobResult::success();
}
```

### Cascading failures via dead-letter inspection

The `failed()` callback is where you log permanently-failing jobs:

```php
public function failed(JobPayload $payload, \Throwable $e): void
{
    $this->logger->critical('Job failed', [
        'job_class' => $payload->jobClass(),
        'job_id' => $payload->jobId(),
        'error' => $e->getMessage(),
        'payload' => $payload->data(),
    ]);
    
    // Optional: send alert
    $this->slack->notify("Critical job failure: {$e->getMessage()}");
}
```

### Rate-limited job processing

Use a cache lock to prevent parallel processing:

```php
public function handle(JobPayload $payload): JobResult
{
    $lock = $this->cache->lock('batch:process', 600);
    
    if (!$lock->acquire()) {
        throw new \RuntimeException('Batch already processing');
    }
    
    try {
        // Process...
    } finally {
        $lock->release();
    }
}
```

## Source

- [WorkerLoop](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Pipelines/Worker/WorkerLoop.php)
- [JobContract](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Pipelines/Worker/Contracts/JobContract.php)
- [JobPayload, JobResult](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Pipelines/Worker/)
- [Retry strategies](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Pipelines/Worker/Retry/)
