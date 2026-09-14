<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Scheduling;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\ManifestWriter;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\JobPayload;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\{CachePort, Lock, QueuePort};
use AlfacodeTeam\PhpServicePlatform\Kernel\Scheduling\{CronExpression, ScheduledTask, Scheduler};
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** Records pushes; everything else is unreachable from the scheduler. */
final class RecordingQueue implements QueuePort
{
    /** @var list<array{job: string, payload: array, queue: string}> */
    public array $pushed = [];
    public ?\Throwable $failWith = null;

    /** Throw only for this job class; null means "throw for any" (with $failWith). */
    public ?string $failFor = null;

    public function push(string $jobClass, array $payload, string $queue = 'default', int $delay = 0): string
    {
        if ($this->failFor !== null && $jobClass === $this->failFor) {
            throw new \RuntimeException('nope');
        }

        if ($this->failFor === null && $this->failWith !== null) {
            throw $this->failWith;
        }
        $this->pushed[] = ['job' => $jobClass, 'payload' => $payload, 'queue' => $queue];

        return 'id-' . count($this->pushed);
    }

    public function later(int $seconds, string $jobClass, array $payload, string $queue = 'default'): string
    {
        return $this->push($jobClass, $payload, $queue, $seconds);
    }

    public function size(string $queue = 'default'): int { return count($this->pushed); }
    public function pop(string $queue = 'default'): ?JobPayload { return null; }
    public function ack(JobPayload $payload): void {}
    public function release(JobPayload $payload, int $delay = 0): void {}
    public function fail(JobPayload $payload, ?\Throwable $reason = null): void {}
}

final class FakeLock implements Lock
{
    public bool $released = false;

    public function __construct(private readonly bool $available) {}

    public function acquire(): bool { return $this->available; }
    public function release(): bool { return $this->released = true; }
    public function get(?callable $callback = null): mixed { return $this->acquire(); }
    public function block(int $seconds, ?callable $callback = null): mixed { return $this->acquire(); }
    public function owner(): string { return 'test'; }
    public function forceRelease(): void { $this->released = true; }
}

final class FakeCache implements CachePort
{
    public array $locks = [];

    public function __construct(private readonly bool $lockAvailable = true) {}

    public function lock(string $name, int $seconds = 0, ?string $owner = null): Lock
    {
        return $this->locks[$name] = new FakeLock($this->lockAvailable);
    }

    public function restoreLock(string $name, string $owner): Lock { return new FakeLock(true); }

    public function get(string $key): mixed { return null; }
    public function set(string $key, mixed $value, ?int $ttl = null): bool { return true; }
    public function delete(string $key): bool { return true; }
    public function has(string $key): bool { return false; }
    public function remember(string $key, int $ttl, callable $callback): mixed { return $callback(); }
    public function increment(string $key, int $by = 1): int { return $by; }
    public function deletePattern(string $pattern): int { return 0; }
    public function flush(): bool { return true; }
}

#[CoversClass(Scheduler::class)]
final class SchedulerTest extends TestCase
{
    private string $root;
    private ?string $previousBase = null;
    private ?string $previousProject = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hkm-sched-run-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/var/cache/manifests', 0775, true);

        $this->previousBase    = Paths::base();
        $this->previousProject = Paths::project();
        Paths::setBase($this->root);
        Paths::setProject($this->root);
    }

    protected function tearDown(): void
    {
        Paths::setBase((string) $this->previousBase);
        Paths::setProject($this->previousProject);

        foreach (glob($this->root . '/var/cache/manifests/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->root . '/var/cache/manifests');
        @rmdir($this->root . '/var/cache');
        @rmdir($this->root . '/var');
        @rmdir($this->root);
    }

    /** @param list<ScheduledTask> $tasks */
    private function manifest(array $tasks): void
    {
        $out = [];
        foreach ($tasks as $t) {
            $out[$t->name] = $t->toArray();
        }
        ManifestWriter::write('schedule-manifest.php', $out);
    }

    private function task(string $name, string $at, array $overrides = []): ScheduledTask
    {
        return new ScheduledTask(
            name:               $name,
            cron:               CronExpression::parse($at),
            kind:               $overrides['kind'] ?? ScheduledTask::KIND_JOB,
            target:             $overrides['target'] ?? 'App\\Jobs\\X',
            queue:              $overrides['queue'] ?? 'default',
            payload:            $overrides['payload'] ?? [],
            withoutOverlapping: $overrides['withoutOverlapping'] ?? false,
            expiresAfter:       $overrides['expiresAfter'] ?? 3600,
        );
    }

    private const AT_2AM = '2026-01-05 02:00:00';

    public function testOnlyDueTasksAreDispatched(): void
    {
        $this->manifest([
            $this->task('nightly', '0 2 * * *', ['target' => 'Nightly']),
            $this->task('hourly', '0 * * * *', ['target' => 'Hourly']),
            $this->task('noon', '0 12 * * *', ['target' => 'Noon']),
        ]);

        $queue = new RecordingQueue();
        $results = (new Scheduler(queue: $queue))->run(new \DateTimeImmutable(self::AT_2AM));

        self::assertSame(['Nightly', 'Hourly'], array_column($queue->pushed, 'job'));
        self::assertSame(['queued', 'queued'], array_column($results, 'outcome'));
    }

    public function testAJobIsQueuedWithItsDeclaredQueueAndPayload(): void
    {
        $this->manifest([
            $this->task('nightly', '0 2 * * *', [
                'target' => 'App\\Jobs\\Report', 'queue' => 'reports', 'payload' => ['format' => 'pdf'],
            ]),
        ]);

        $queue = new RecordingQueue();
        (new Scheduler(queue: $queue))->run(new \DateTimeImmutable(self::AT_2AM));

        self::assertSame(
            [['job' => 'App\\Jobs\\Report', 'payload' => ['format' => 'pdf'], 'queue' => 'reports']],
            $queue->pushed,
        );
    }

    /**
     * The multi-server case this exists for: the second box's tick must not
     * queue a second copy.
     */
    public function testAnOverlappingTaskIsSkippedWhenTheLockIsHeld(): void
    {
        $this->manifest([$this->task('nightly', '0 2 * * *', ['withoutOverlapping' => true])]);

        $queue   = new RecordingQueue();
        $results = (new Scheduler(queue: $queue, cache: new FakeCache(lockAvailable: false)))
            ->run(new \DateTimeImmutable(self::AT_2AM));

        self::assertSame([], $queue->pushed);
        self::assertSame('skipped', $results[0]['outcome']);
        self::assertStringContainsString('still holds the lock', $results[0]['detail']);
    }

    public function testAnOverlappingTaskRunsWhenTheLockIsFree(): void
    {
        $this->manifest([$this->task('nightly', '0 2 * * *', ['withoutOverlapping' => true])]);

        $queue = new RecordingQueue();
        (new Scheduler(queue: $queue, cache: new FakeCache()))->run(new \DateTimeImmutable(self::AT_2AM));

        self::assertCount(1, $queue->pushed);
    }

    /**
     * Dropping the protection someone explicitly asked for is worse than not
     * running the task — so it is skipped, not run unguarded.
     */
    public function testAnOverlapGuardedTaskIsSkippedWhenNothingCanLock(): void
    {
        $this->manifest([$this->task('nightly', '0 2 * * *', ['withoutOverlapping' => true])]);

        $queue   = new RecordingQueue();
        $results = (new Scheduler(queue: $queue, cache: null))->run(new \DateTimeImmutable(self::AT_2AM));

        self::assertSame([], $queue->pushed);
        self::assertStringContainsString('no CachePort', $results[0]['detail']);
    }

    /** A successful queue keeps the lock — the WORK has not run yet. */
    public function testTheLockSurvivesASuccessfulEnqueue(): void
    {
        $this->manifest([$this->task('nightly', '0 2 * * *', ['withoutOverlapping' => true])]);

        $cache = new FakeCache();
        (new Scheduler(queue: new RecordingQueue(), cache: $cache))->run(new \DateTimeImmutable(self::AT_2AM));

        self::assertFalse($cache->locks['schedule:lock:nightly']->released);
    }

    /** …but a FAILED dispatch releases it, or the task is blocked until expiry. */
    public function testTheLockIsReleasedWhenTheDispatchThrows(): void
    {
        $this->manifest([$this->task('nightly', '0 2 * * *', ['withoutOverlapping' => true])]);

        $queue = new RecordingQueue();
        $queue->failWith = new \RuntimeException('redis is down');
        $cache = new FakeCache();

        (new Scheduler(queue: $queue, cache: $cache))->run(new \DateTimeImmutable(self::AT_2AM));

        self::assertTrue($cache->locks['schedule:lock:nightly']->released);
    }

    /** One bad task must not cost the other twelve their night. */
    public function testAFailingTaskDoesNotStopTheRest(): void
    {
        $this->manifest([
            $this->task('first', '0 2 * * *', ['target' => 'First']),
            $this->task('second', '0 2 * * *', ['target' => 'Second']),
        ]);

        $queue = new RecordingQueue();
        $queue->failFor = 'First';

        $results = (new Scheduler(queue: $queue))->run(new \DateTimeImmutable(self::AT_2AM));

        self::assertSame(['failed', 'queued'], array_column($results, 'outcome'));
        self::assertSame(['Second'], array_column($queue->pushed, 'job'));
    }

    public function testACommandTaskRunsThroughTheInjectedRunner(): void
    {
        $this->manifest([
            $this->task('prune', '0 2 * * *', ['kind' => ScheduledTask::KIND_COMMAND, 'target' => 'cache:prune --stale']),
        ]);

        $seen = [];
        $results = (new Scheduler(commandRunner: function (string $line) use (&$seen): int {
            $seen[] = $line;

            return 0;
        }))->run(new \DateTimeImmutable(self::AT_2AM));

        self::assertSame(['cache:prune --stale'], $seen);
        self::assertSame('ran', $results[0]['outcome']);
    }

    public function testANonZeroCommandExitIsAFailure(): void
    {
        $this->manifest([
            $this->task('prune', '0 2 * * *', ['kind' => ScheduledTask::KIND_COMMAND, 'target' => 'boom']),
        ]);

        $results = (new Scheduler(commandRunner: static fn(string $l): int => 3))
            ->run(new \DateTimeImmutable(self::AT_2AM));

        self::assertSame('failed', $results[0]['outcome']);
        self::assertStringContainsString('exited 3', $results[0]['detail']);
    }

    /** `schedule:run --task=x` ignores the schedule entirely. */
    public function testRunTaskDispatchesRegardlessOfWhetherItIsDue(): void
    {
        $this->manifest([$this->task('noon', '0 12 * * *', ['target' => 'Noon'])]);

        $queue  = new RecordingQueue();
        $result = (new Scheduler(queue: $queue))->runTask('noon');

        self::assertSame('queued', $result['outcome']);
        self::assertSame(['Noon'], array_column($queue->pushed, 'job'));
    }

    public function testRunTaskReturnsNullForAnUnknownName(): void
    {
        $this->manifest([$this->task('noon', '0 12 * * *')]);

        self::assertNull((new Scheduler(queue: new RecordingQueue()))->runTask('nope'));
    }

    public function testAMissingManifestIsNotAnError(): void
    {
        $scheduler = new Scheduler(queue: new RecordingQueue());

        self::assertSame([], $scheduler->tasks());
        self::assertSame([], $scheduler->run(new \DateTimeImmutable(self::AT_2AM)));
    }

    public function testAJobTaskIsSkippedWhenNoQueueIsBound(): void
    {
        $this->manifest([$this->task('nightly', '0 2 * * *')]);

        $results = (new Scheduler(queue: null))->run(new \DateTimeImmutable(self::AT_2AM));

        self::assertSame('skipped', $results[0]['outcome']);
        self::assertStringContainsString('no QueuePort', $results[0]['detail']);
    }
}
