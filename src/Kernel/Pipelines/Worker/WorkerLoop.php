<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\{CoreContainer, ModuleContainer};
use AlfacodeTeam\PhpServicePlatform\Kernel\Error\{ErrorPipeline, ErrorContext};
use AlfacodeTeam\PhpServicePlatform\Kernel\Loading\{DependencyGraphCalculator, OnDemandLoader};
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\Contracts\JobContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\Retry\{ExponentialRetryStrategy, RetryStrategyContract};
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

    public function __construct(
        private readonly CoreContainer $core,
        private readonly ErrorPipeline $errorPipeline,
        private readonly WorkerPipeline $pipeline,
        private readonly string $signingSecret = '',
        /** Backoff applied by release() in port mode. */
        private readonly RetryStrategyContract $retry = new ExponentialRetryStrategy(),
    ) {
    }

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
     */
    public function run(?callable $puller = null, int $maxIterations = 0, string $queue = 'default'): void
    {
        $port = $puller === null ? $this->queuePort() : null;

        if ($puller === null && $port === null) {
            throw new \RuntimeException(
                'WorkerLoop::run() needs either a QueuePort bound in the container '
                . 'or an explicit $puller callable.'
            );
        }

        $iterations = 0;
        while (!$this->shouldStop) {
            if ($maxIterations > 0 && $iterations++ >= $maxIterations) {
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
        } catch (\Throwable $e) {
            if ($payload->hasExceededMaxAttempts()) {
                $port->fail($payload, $e);

                return;
            }

            $port->release($payload, $this->retry->delayFor($payload->attempts() + 1));
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
        if ($this->signingSecret !== '' && !$payload->isSignatureValid($this->signingSecret)) {
            return JobResult::skipped('Invalid job signature — payload rejected.');
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
            return $job->handle($payload);
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
