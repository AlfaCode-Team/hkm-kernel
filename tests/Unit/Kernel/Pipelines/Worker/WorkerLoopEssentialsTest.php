<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Pipelines\Worker;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\CoreContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Error\ErrorPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\Contracts\JobContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\JobPayload;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\JobResult;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerLoop;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\QueuePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Essential modules must reach a queued job.
 *
 * HttpPipeline passed its essentials into OnDemandLoader; WorkerLoop built its
 * loader with none, and Kernel::materialize() never handed them over. So a
 * module the project declared app-wide in proj.json was app-wide for requests
 * and absent from every job — and for an essential that rebinds a port per
 * scope (tenancy rebinding DatabasePort) the failure is silent rather than
 * loud: the binding still resolves, just to the wrong connection.
 */
#[CoversClass(WorkerLoop::class)]
final class WorkerLoopEssentialsTest extends TestCase
{
    private string $root;
    private ?string $previousBase = null;
    private ?string $previousProject = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hkm-worker-essentials-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/var/cache/manifests', 0775, true);

        $this->previousBase    = Paths::base();
        $this->previousProject = Paths::project();
        Paths::setBase($this->root);
        Paths::setProject($this->root);

        EssentialFixtureProvider::$registered = 0;
        EssentialSpyJob::$handled = 0;
    }

    protected function tearDown(): void
    {
        Paths::setBase((string) $this->previousBase);
        Paths::setProject($this->previousProject);

        foreach (glob($this->root . '/var/cache/manifests/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->root . '/var/cache/manifests');
        @rmdir($this->root . '/var/cache');
        @rmdir($this->root . '/var');
        @rmdir($this->root);
    }

    // ── The fix ─────────────────────────────────────────────────────────────

    public function test_an_essential_module_registers_for_a_queued_job(): void
    {
        $this->writeManifests();

        $this->runOnce([EssentialFixtureProvider::class]);

        self::assertSame(1, EssentialSpyJob::$handled, 'the job must still run');
        self::assertSame(
            1,
            EssentialFixtureProvider::$registered,
            'an essential module must register into the job container, as it does for every request',
        );
    }

    public function test_an_essential_registers_even_when_the_job_has_no_manifest_entry(): void
    {
        // A job the manifest does not know used to fall back to the CoreContainer
        // with no module container at all. "Essential" means every unit of work,
        // including this one.
        $this->writeManifests(withJobEntry: false);

        $this->runOnce([EssentialFixtureProvider::class]);

        self::assertSame(1, EssentialSpyJob::$handled);
        self::assertSame(1, EssentialFixtureProvider::$registered);
    }

    // ── And it stays opt-in ─────────────────────────────────────────────────

    public function test_a_module_that_is_not_essential_does_not_register(): void
    {
        // The loop must not register every module in the service manifest — only
        // the job's own graph plus what the project declared essential.
        $this->writeManifests();

        $this->runOnce(essentials: []);

        self::assertSame(1, EssentialSpyJob::$handled);
        self::assertSame(0, EssentialFixtureProvider::$registered);
    }

    // ── harness ─────────────────────────────────────────────────────────────

    /**
     * @param list<class-string<ModuleContract>> $essentials
     */
    private function runOnce(array $essentials): void
    {
        $core  = new CoreContainer();
        $queue = new EssentialQueue([self::payload()]);
        $core->instance(QueuePort::class, $queue);

        $loop = new WorkerLoop(
            $core,
            ErrorPipeline::notifiers([]),
            new WorkerPipeline(),
            '',
            essentialModules: $essentials,
        );

        $loop->run(maxIterations: 1);
    }

    /**
     * A service manifest naming both the job's own domain and the essential's,
     * plus (optionally) the job manifest entry that maps the job to its domain.
     */
    private function writeManifests(bool $withJobEntry = true): void
    {
        $this->writeManifest('service-manifest.php', [
            'services' => [
                'job.testing' => [
                    'module'   => JobFixtureProvider::class,
                    'requires' => [],
                ],
                'essential.testing' => [
                    'module'   => EssentialFixtureProvider::class,
                    'requires' => [],
                ],
            ],
        ]);

        $this->writeManifest('job-manifest.php', $withJobEntry ? [
            EssentialSpyJob::class => [
                'handler' => EssentialSpyJob::class,
                'queue'   => 'default',
                'module'  => JobFixtureProvider::class,
                'solves'  => 'job.testing',
            ],
        ] : []);
    }

    /** @param array<string, mixed> $data */
    private function writeManifest(string $file, array $data): void
    {
        file_put_contents(
            $this->root . '/var/cache/manifests/' . $file,
            '<?php return ' . var_export($data, true) . ';' . PHP_EOL,
        );
    }

    private static function payload(): JobPayload
    {
        return new JobPayload(
            jobId:       'job-1',
            jobClass:    EssentialSpyJob::class,
            data:        [],
            queue:       'default',
            attempts:    0,
            maxAttempts: 3,
            enqueuedAt:  new \DateTimeImmutable('@1700000000'),
            signature:   '',
        );
    }
}

/** Counts how many times the loader ran its register(). */
final class EssentialFixtureProvider implements ModuleContract
{
    public static int $registered = 0;

    public function solves(): string { return 'essential.testing'; }

    /** @return list<string> */
    public function requires(): array { return []; }

    /** @return list<string> */
    public function exposes(): array { return []; }

    public function register(ModuleContainer $container): void
    {
        self::$registered++;
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
    }
}

/** The module that owns the job's own domain. */
final class JobFixtureProvider implements ModuleContract
{
    public function solves(): string { return 'job.testing'; }

    /** @return list<string> */
    public function requires(): array { return []; }

    /** @return list<string> */
    public function exposes(): array { return []; }

    public function register(ModuleContainer $container): void
    {
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
    }
}

final class EssentialSpyJob implements JobContract
{
    public static int $handled = 0;

    public function handle(JobPayload $payload): JobResult
    {
        self::$handled++;

        return JobResult::success();
    }

    public function failed(JobPayload $payload, \Throwable $e): void
    {
    }
}

/** Minimal QueuePort that yields a fixed list of payloads once. */
final class EssentialQueue implements QueuePort
{
    /** @var list<string> */
    public array $calls = [];

    /** @param list<JobPayload> $pending */
    public function __construct(private array $pending = []) {}

    public function push(string $jobClass, array $payload, string $queue = 'default', int $delay = 0): string { return 'id'; }
    public function later(int $seconds, string $jobClass, array $payload, string $queue = 'default'): string { return 'id'; }
    public function size(string $queue = 'default'): int { return count($this->pending); }

    public function pop(string $queue = 'default'): ?JobPayload
    {
        return array_shift($this->pending);
    }

    public function ack(JobPayload $payload): void { $this->calls[] = 'ack'; }
    public function release(JobPayload $payload, int $delay = 0): void { $this->calls[] = 'release'; }
    public function fail(JobPayload $payload, ?\Throwable $reason = null): void { $this->calls[] = 'fail'; }
}
