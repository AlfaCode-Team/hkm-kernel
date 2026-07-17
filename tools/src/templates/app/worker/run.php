<?php

declare(strict_types=1);

/**
 * =============================================================================
 *  WORKER ENTRY POINT  (app/worker/run.php)
 * =============================================================================
 *
 * The background-job surface of your application. It is a long-running process
 * that pops jobs off a queue and executes them, separate from web traffic — use
 * it for work that should not block an HTTP response (emails, image processing,
 * IndexNow submissions, report generation, ...).
 *
 * It boots the SAME kernel as the web/CLI entries (kernel-autoload →
 * bootstrap/app.php, which loads .env and installs the error net), then drives
 * the kernel's WorkerLoop. The loop repeatedly:
 *
 *   1. calls $puller() to fetch the next job (returns null when the queue is empty),
 *   2. rebuilds it into a JobPayload,
 *   3. resolves the job handler inside its OWNING module's scope (full DI), and
 *   4. runs handle(); on success it acknowledges, on a thrown error it retries
 *      per the job's retry strategy, calling failed() after the last attempt.
 *
 * How jobs get ENQUEUED: application code pushes them via QueuePort::push(...)
 * (e.g. the SEO module enqueues 'seo.indexnow'). This process is the consumer.
 *
 * Usage
 * -----
 *   php app/worker/run.php                                   # drain 'default' forever
 *   WORKER_QUEUE=indexing WORKER_MAX_ITERATIONS=50 php app/worker/run.php
 *
 * Environment
 *   WORKER_QUEUE           default   queue name to consume
 *   WORKER_MAX_ITERATIONS  0         stop after N iterations (0 = run forever)
 *
 * Graceful shutdown: SIGTERM/SIGINT stop the loop after the current job finishes
 * (no half-processed jobs), so it is safe to run under systemd / supervisor /
 * Kubernetes, which send SIGTERM on stop or redeploy.
 * =============================================================================
 */

// 1. Autoloaders. The bootstrap (required below) loads .env + installs ErrorGuard.
require_once __DIR__ . '/../bootstrap/kernel-autoload.php';
psp_require_kernel_autoload();

use AlfacodeTeam\PhpServicePlatform\Kernel\Kernel;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\JobPayload;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\QueuePort;
use Project\Infrastructure\FileQueue;

// 2. Build the application — same Kernel object the web and CLI entries use.
/** @var Kernel $kernel */
$kernel = require __DIR__ . '/../bootstrap/app.php';

// 3. Read which queue to drain and how many jobs to process before exiting.
$queue         = (string) (getenv('WORKER_QUEUE') ?: 'default');
$maxIterations = (int) (getenv('WORKER_MAX_ITERATIONS') ?: 0);

// 4. Resolve the active QueuePort. This is whatever bootstrap/app.php bound —
//    FileQueue by default, or the Redis-backed adapter when RedisCache is active.
//    (Swapping the backend needs no change here: only the FileQueue->pop() shape
//    below is backend-specific; a Redis adapter would BLPOP instead.)
$queueAdapter = $kernel->container()->make(QueuePort::class);

// 5. The $puller: the loop calls this to fetch the next job. Returning null means
//    "nothing to do right now" and the loop idles/backs off. Here we pop one raw
//    record off the file queue and rehydrate it into a typed JobPayload.
$puller = static function () use ($queue, $queueAdapter): ?JobPayload {
    if (!$queueAdapter instanceof FileQueue) {
        return null;   // unknown backend — stay idle rather than guess its API
    }

    $record = $queueAdapter->pop($queue);
    if ($record === null) {
        return null;   // queue empty
    }

    return new JobPayload(
        jobId:       (string) $record['jobId'],
        jobClass:    (string) $record['jobClass'],
        data:        (array) $record['data'],
        queue:       (string) $record['queue'],
        attempts:    (int) $record['attempts'],
        maxAttempts: (int) $record['maxAttempts'],
        enqueuedAt:  new \DateTimeImmutable((string) $record['enqueuedAt']),
        signature:   '',
    );
};

// 6. The kernel's worker loop — materialises the Worker pipeline on first call.
$loop = $kernel->workerLoop();

// 7. Graceful shutdown. With pcntl available, trap SIGTERM/SIGINT and ask the
//    loop to stop AFTER the in-flight job completes (no partial processing).
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    $stop = static function () use ($loop): void {
        echo "[{{PROJECT_NAME}}] Worker stopping...\n";
        $loop->stop();
    };
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
}

echo "[{{PROJECT_NAME}}] Worker loop started  queue={$queue}"
    . ($maxIterations > 0 ? "  maxIterations={$maxIterations}" : '  (forever)') . "\n";

// 8. Run until stopped (signal) or until maxIterations jobs have been processed.
$loop->run($puller, $maxIterations);

echo "[{{PROJECT_NAME}}] Worker finished. Remaining in '{$queue}': " . $queueAdapter->size($queue) . "\n";
