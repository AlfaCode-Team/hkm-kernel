<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\{CoreContainer, ModuleContainer};
use AlfacodeTeam\PhpServicePlatform\Kernel\Error\{ErrorPipeline, ErrorContext};
use AlfacodeTeam\PhpServicePlatform\Kernel\Loading\{DependencyGraphCalculator, OnDemandLoader};
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\Contracts\JobContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\Retry\{
    ExponentialRetryStrategy,
    FixedRetryStrategy,
    LinearRetryStrategy,
    RetryStrategyContract
};
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\QueuePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;

/**
 * WorkerLoop — long-running consumer that executes queued jobs.
 *
 * The loop is intentionally simple and synchronous per worker process; scale by
 * running multiple worker processes (matching the OpenSwoole model). A driver
 * supplies dequeued JobPayloads via the $puller callable so the loop stays
 * decoupled from any specific QueuePort implementation.
 *
 * Lifecycle per message:
 *   validate signature → resolve job class → build scoped container → handle()
 *   On unhandled throwable past max attempts → failed() + dead-letter.
 *
 * Each job gets its own request-scoped ModuleContainer (built from the compiled
 * job-manifest.php and service-manifest.php). This gives jobs access to
 * TransactionManager, DomainEventCollector, and all module DI bindings —
 * the same infrastructure available to HTTP controllers.
 */
final class WorkerLoop
{
    private bool $shouldStop = false;

    // Manifest-backed collaborators are built lazily on first job so a kernel
    // materialized for a non-worker surface (HTTP/CLI) pays no disk I/O for the
    // service/job manifests it will never read.
    /** @var array<string, array<string, mixed>>|null */
    private ?array $jobManifest = null;
    private ?DependencyGraphCalculator $calculator = null;
    private ?OnDemandLoader $loader = null;

    /**
     * Per-job retry strategies, built once from the compiled manifest.
     *
     * @var array<string, RetryStrategyContract>
     */
    private array $retryByJob = [];

    /** Whether a shutdown signal has already been trapped for this loop. */
    private bool $signalsInstalled = false;

    public function __construct(
        private readonly CoreContainer $core,
        private readonly ErrorPipeline $errorPipeline,
        private readonly WorkerPipeline $pipeline,
        /**
         * HMAC key every dequeued payload must be signed with. EMPTY = no
         * verification, which is the historical behaviour and is only safe when
         * nothing but the application can write to the queue.
         *
         * Set it via Kernel::withWorkerSecret() (or JOB_SIGNING_SECRET). Turning
         * it on requires the QueuePort adapter to stamp
         * {@see JobPayload::signatureFor()} onto the envelope at push() time —
         * otherwise every job is rejected as unsigned, which is the correct
         * behaviour but a surprising way to discover the requirement.
         */
        private readonly string $signingSecret = '',
        /** Backoff applied by release() when a job declares no retry of its own. */
        private readonly RetryStrategyContract $retry = new ExponentialRetryStrategy(),
    ) {
    }

    /**
     * Ask the loop to finish the job it is on and then exit.
     *
     * Safe to call from a signal handler: it only sets a flag, so the current
     * job still reaches its ack/release/fail instead of being torn down
     * mid-flight.
     */
    public function stop(): void
    {
        $this->shouldStop = true;
    }

    /**
     * Run the loop.
     *
     * Two modes:
     *
     *  1. PORT MODE (preferred) — pass no $puller. The loop resolves QueuePort
     *     from the core container and owns the full lifecycle:
     *     pop → handle → ack / release / fail. Swapping the queue backend then
     *     needs no code change anywhere.
     *
     *  2. PULLER MODE (legacy/exotic) — supply a callable returning the next
     *     JobPayload or null. The CALLER owns ack/retry semantics; the loop only
     *     executes. Kept for transports that cannot express the port (and for
     *     tests), but a project should not need it.
     *
     * @param (callable():?JobPayload)|null $puller null = use the bound QueuePort
     * @param int    $maxIterations 0 = run forever (until stop()).
     * @param string $queue         which queue to drain in port mode
     * @param int    $memoryLimitMb stop the loop once the process exceeds this
     *        resident size, 0 to disable. A long-running PHP process accumulates
     *        fragmentation no amount of correctness prevents, so a supervised
     *        worker is EXPECTED to exit and be restarted; the only question is
     *        whether it does so between jobs or by being OOM-killed mid-job.
     */
    public function run(
        ?callable $puller = null,
        int $maxIterations = 0,
        string $queue = 'default',
        int $memoryLimitMb = 0,
    ): void {
        $port = $puller === null ? $this->queuePort() : null;

        if ($puller === null && $port === null) {
            throw new \RuntimeException(
                'WorkerLoop::run() needs either a QueuePort bound in the container '
                . 'or an explicit $puller callable.'
            );
        }

        $this->trapShutdownSignals();

        $iterations = 0;
        while (true) {
            if ($maxIterations > 0 && $iterations++ >= $maxIterations) {
                break;
            }

            // Deliver any SIGTERM/SIGINT that arrived while the last job ran,
            // BEFORE deciding whether to take another one. Checking shouldStop
            // only in the `while` condition would let one more job start after
            // the signal — which is the opposite of a graceful shutdown.
            if (\function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            if ($this->shouldStop) {
                break;
            }

            if ($memoryLimitMb > 0 && memory_get_usage(true) >= $memoryLimitMb * 1048576) {
                break;
            }

            $payload = $port !== null ? $port->pop($queue) : $puller();
            if ($payload === null) {
                usleep(100_000); // idle backoff
                continue;
            }

            if ($port === null) {
                // Puller mode: the caller's driver owns retry/ack. Preserve the
                // original contract, including letting a throw propagate.
                $this->process($payload);
                continue;
            }

            $this->processWithPort($port, $payload);
        }
    }

    /**
     * Port mode: run the job and resolve its queue state exactly once.
     *
     * process() rethrows when a job failed but still has attempts left, and
     * returns a result once it has been dead-lettered by its own failed() hook.
     * That distinction is what decides release vs fail here.
     */
    private function processWithPort(QueuePort $port, JobPayload $payload): void
    {
        try {
            $this->process($payload);

            // Completed, skipped, or already dead-lettered by process() — either
            // way it must not come back. Removing it is the whole point of ack.
            $port->ack($payload);
        } catch (RejectedJobException $e) {
            // A payload the worker refuses to run at all. Retrying cannot help —
            // a bad signature never becomes good — so it goes straight to the
            // dead-letter queue, where an operator can see it. It must NOT be
            // acked: silently deleting the one piece of evidence that someone is
            // writing to your queue is the worst possible response to it.
            $this->report($e, $payload);
            $port->fail($payload, $e);
        } catch (\Throwable $e) {
            if ($payload->hasExceededMaxAttempts()) {
                $port->fail($payload, $e);

                return;
            }

            $port->release(
                $payload,
                $this->retryFor($payload->jobClass())->delayFor($payload->attempts() + 1),
            );
        }
    }

    /**
     * The retry strategy a job DECLARED in its module.json, or the loop-wide
     * default when it declared none.
     *
     * Built once per job class and reused — the manifest is a deploy-time
     * artefact, so the strategy cannot change under a running worker.
     */
    private function retryFor(string $jobName): RetryStrategyContract
    {
        if (isset($this->retryByJob[$jobName])) {
            return $this->retryByJob[$jobName];
        }

        // Load it here too: a job that threw before resolveContainer() ran (an
        // unknown class, a container failure) still needs a backoff.
        $this->jobManifest ??= $this->loadManifest('job-manifest.php', []);

        $spec = $this->jobManifest[$jobName]['retry'] ?? null;

        if (!is_array($spec)) {
            return $this->retryByJob[$jobName] = $this->retry;
        }

        $base   = (int) ($spec['base'] ?? 1);
        $jitter = (bool) ($spec['jitter'] ?? false);

        return $this->retryByJob[$jobName] = match ($spec['strategy'] ?? 'exponential') {
            'linear' => new LinearRetryStrategy($base),
            'fixed'  => new FixedRetryStrategy($base),
            default  => new ExponentialRetryStrategy($base, jitter: $jitter),
        };
    }

    /**
     * Run a job under the `timeout` its module.json declares.
     *
     * BEST EFFORT, AND THE LIMITS MATTER. pcntl_alarm delivers SIGALRM, which
     * PHP dispatches between opcodes — so it interrupts a runaway loop, but a
     * job blocked inside a single long DB query or socket read is not preempted
     * until that call returns. A timeout that must hold regardless belongs in
     * the driver (a statement timeout, a socket timeout), not here.
     *
     * It is still worth having: the failure this catches — a job that spins and
     * pins a worker until someone notices — is the one that takes a queue down.
     *
     * Without ext-pcntl, or with no declared timeout, the job runs unbounded,
     * exactly as before.
     */
    private function runWithTimeout(JobContract $job, JobPayload $payload): JobResult
    {
        $seconds = ($this->jobManifest ?? [])[$payload->jobClass()]['timeout'] ?? null;

        if (!is_int($seconds) || $seconds <= 0 || !\function_exists('pcntl_alarm')) {
            return $job->handle($payload);
        }

        $previous = pcntl_signal_get_handler(\SIGALRM);

        pcntl_signal(\SIGALRM, static function () use ($payload, $seconds): never {
            throw new \RuntimeException(sprintf(
                'Job [%s] (id %s) exceeded its declared timeout of %ds.',
                $payload->jobClass(),
                $payload->jobId() !== '' ? $payload->jobId() : '?',
                $seconds,
            ));
        });

        pcntl_alarm($seconds);

        try {
            return $job->handle($payload);
        } finally {
            // Cancel first, THEN restore: an alarm that fires during teardown
            // would surface as a timeout for whatever job runs next.
            pcntl_alarm(0);
            pcntl_signal(\SIGALRM, $previous);
        }
    }

    /** Route a rejected payload through the error pipeline so it is not silent. */
    private function report(\Throwable $e, JobPayload $payload): void
    {
        $this->errorPipeline->consume(ErrorContext::fromThrowable(
            $e,
            requestPath:   'job:' . $payload->jobClass(),
            requestMethod: 'WORKER',
        ));
    }

    /**
     * Trap SIGTERM/SIGINT so a shutdown finishes the current job first.
     *
     * Without this, the signal every process supervisor and container runtime
     * sends to stop a worker (SIGTERM, then SIGKILL after a grace period) killed
     * PHP outright — including in the window between handle() returning and
     * ack() removing the message. A job that had already run its side effects
     * came back on the next boot and ran them AGAIN. Trapping the signal turns
     * that from luck into a guarantee.
     *
     * ext-pcntl is optional and absent on some builds; without it the loop keeps
     * its old behaviour rather than refusing to start.
     */
    private function trapShutdownSignals(): void
    {
        if ($this->signalsInstalled || !\function_exists('pcntl_signal')) {
            return;
        }

        $this->signalsInstalled = true;

        // Without this, PHP only runs a signal handler where the script calls
        // pcntl_signal_dispatch() — which the loop does between jobs, but which
        // is no help to the per-job alarm in runWithTimeout(): a job that hangs
        // never reaches the next dispatch point. Async delivery is what makes
        // the timeout able to interrupt anything at all.
        if (\function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }

        $stop = function (): void {
            $this->stop();
        };

        foreach ([\SIGTERM, \SIGINT, \SIGQUIT] as $signal) {
            pcntl_signal($signal, $stop);
        }
    }

    /** The bound QueuePort, or null when the project wired none. */
    private function queuePort(): ?QueuePort
    {
        try {
            $port = $this->core->has(QueuePort::class) ? $this->core->make(QueuePort::class) : null;

            return $port instanceof QueuePort ? $port : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function process(JobPayload $payload): JobResult
    {
        // 1. Signature check — never run an unsigned/tampered payload.
        //
        // This used to return skipped(), which processWithPort then ACKED: a
        // payload that failed authentication was deleted without a trace, so a
        // misconfigured producer and an active attacker looked identical, and
        // both looked like nothing at all. Throwing routes it to the dead-letter
        // queue and through the error pipeline instead.
        if ($this->signingSecret !== '' && !$payload->isSignatureValid($this->signingSecret)) {
            throw new RejectedJobException(sprintf(
                'Rejected job [%s] (id %s) on queue [%s]: %s. '
                . 'The producing QueuePort adapter must stamp JobPayload::signatureFor() '
                . 'onto the envelope at push() time.',
                $payload->jobClass(),
                $payload->jobId() !== '' ? $payload->jobId() : '?',
                $payload->queue(),
                $payload->signature() === '' ? 'payload is unsigned' : 'signature does not verify',
            ));
        }

        $jobClass = $this->pipeline->resolve($payload->jobClass())
            ?? (class_exists($payload->jobClass()) ? $payload->jobClass() : null);

        if ($jobClass === null) {
            return JobResult::skipped("Unknown job [{$payload->jobClass()}].");
        }

        // 2. Build a module-scoped container so the job can use TransactionManager,
        //    DomainEventCollector, and its module's DI bindings.
        $container = $this->resolveContainer($payload->jobClass());

        /** @var JobContract $job */
        $job = $container !== null
            ? ($container->has($jobClass) ? $container->make($jobClass) : new $jobClass())
            : ($this->core->has($jobClass) ? $this->core->make($jobClass) : new $jobClass());

        try {
            return $this->runWithTimeout($job, $payload);
        } catch (\Throwable $e) {
            $this->errorPipeline->consume(ErrorContext::fromThrowable(
                $e,
                requestPath:   'job:' . $payload->jobClass(),
                requestMethod: 'WORKER',
            ));

            if ($payload->hasExceededMaxAttempts()) {
                $job->failed($payload, $e);
                return JobResult::skipped('Max attempts exhausted — moved to dead-letter.');
            }

            throw $e; // signal the driver to requeue per its retry strategy
        }
    }

    /**
     * Build a request-scoped ModuleContainer for the job's domain.
     * Returns null if the job's domain cannot be resolved — falls back to CoreContainer.
     * Worker jobs always receive a guest Identity (no HTTP request context).
     */
    private function resolveContainer(string $jobName): ?ModuleContainer
    {
        // First real job: load the manifests now (not at construction time).
        $this->jobManifest ??= $this->loadManifest('job-manifest.php', []);
        $this->calculator  ??= new DependencyGraphCalculator(
            $this->loadManifest('service-manifest.php', ['services' => []])
        );
        $this->loader ??= new OnDemandLoader($this->core);

        $entry  = $this->jobManifest[$jobName] ?? null;
        $domain = $entry['solves'] ?? null;

        if ($domain === null) {
            return null;
        }

        try {
            $graph = $this->calculator->resolve($domain);
            return $this->loader->loadWithIdentity($graph, null); // guest Identity for worker context
        } catch (\Throwable) {
            return null; // fall back to CoreContainer if graph resolution fails
        }
    }

    /**
     * Load a compiled manifest file from the manifests directory.
     * Returns $default if the file does not exist (e.g. before first boot).
     *
     * @param array<mixed> $default
     * @return array<mixed>
     */
    private function loadManifest(string $filename, array $default): array
    {
        $path = Paths::cache('manifests/' . $filename);
        if (!is_file($path)) {
            return $default;
        }
        $data = require $path;
        return is_array($data) ? $data : $default;
    }
}
