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
 *   1. pops the next JobPayload off the bound QueuePort (null = queue empty),
 *   2. resolves the job handler inside its OWNING module's scope (full DI),
 *   3. runs handle(), then ACKs it, and
 *   4. on a thrown error releases it for retry with backoff — or, once attempts
 *      are exhausted, calls failed() and dead-letters it.
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
hkm_require_kernel_autoload();

use AlfacodeTeam\PhpServicePlatform\Kernel\Kernel;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\QueuePort;

// 2. Build the application — same Kernel object the web and CLI entries use.
/** @var Kernel $kernel */
$kernel = require __DIR__ . '/../bootstrap/app.php';

// 3. Read which queue to drain and how many jobs to process before exiting.
//    env(), NOT getenv(): LoadEnvironment injects .env into $_ENV/$_SERVER and
//    deliberately does not call putenv() (it is the injection bottleneck and is
//    coroutine-unsafe), so getenv() cannot see a .env value. Using it here meant
//    WORKER_QUEUE in .env was silently ignored and every worker drained
//    'default' — the wrong queue, with no error to say so.
$queue         = (string) (env('WORKER_QUEUE') ?: 'default');
$maxIterations = (int) (env('WORKER_MAX_ITERATIONS') ?: 0);

// 4. The kernel's worker loop — materialises the Worker pipeline on first call.
//
//    There is no $puller to write. The loop resolves the bound QueuePort itself
//    and owns the whole lifecycle: pop → handle → ack on success, release with
//    backoff on a retryable failure, fail (dead-letter) once attempts run out.
//
//    Swapping the backend — FileQueue, Redis, anything else — needs no change
//    here. (This file used to carry a hand-written puller that type-checked the
//    adapter and returned null for anything it did not recognise, so switching
//    to Redis made the worker silently process nothing at all.)
$loop = $kernel->workerLoop();

// 5. Graceful shutdown. With pcntl available, trap SIGTERM/SIGINT and ask the
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

// 6. Run until stopped (signal) or until maxIterations jobs have been processed.
// Port mode: no puller. Drains $queue until stop() or the iteration cap.
$loop->run(maxIterations: $maxIterations, queue: $queue);

echo "[{{PROJECT_NAME}}] Worker finished. Remaining in '{$queue}': "
    . $kernel->container()->make(QueuePort::class)->size($queue) . "\n";
