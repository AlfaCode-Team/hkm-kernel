<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Pipelines\Worker;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\CoreContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Error\ErrorPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\Contracts\JobContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\JobPayload;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\JobResult;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerLoop;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\QueuePort;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * What the worker does with a payload it will not run.
 *
 * The check itself was unreachable — WorkerLoop's signing secret had no way in
 * from the Kernel, so it was always '' and every payload ran. Worse, when it did
 * fire it returned skipped(), which processWithPort then ACKED: a payload that
 * failed authentication was deleted without a trace, making a misconfigured
 * producer and an active attacker both look like nothing happening at all.
 */
#[CoversClass(WorkerLoop::class)]
final class WorkerLoopSignatureTest extends TestCase
{
    private const SECRET = 'worker-signing-key';

    private static function payload(string $signature, string $jobClass = SpyJob::class): JobPayload
    {
        return new JobPayload(
            jobId:       'job-1',
            jobClass:    $jobClass,
            data:        ['x' => 1],
            queue:       'default',
            attempts:    0,
            maxAttempts: 3,
            enqueuedAt:  new \DateTimeImmutable('@1700000000'),
            signature:   $signature,
        );
    }

    private static function signedPayload(string $jobClass = SpyJob::class): JobPayload
    {
        $unsigned = self::payload('', $jobClass);

        return self::payload($unsigned->signatureFor(self::SECRET), $jobClass);
    }

    protected function setUp(): void
    {
        SpyJob::$handled = 0;
    }

    // ── An unverifiable payload is dead-lettered, never acked ───────────────

    public function test_an_unsigned_payload_is_failed_not_acked(): void
    {
        $queue = $this->runOnce(new RecordingQueue([self::payload('')]), self::SECRET);

        self::assertSame(['fail'], $queue->calls, 'a rejected payload must go to the dead-letter queue');
        self::assertSame(0, SpyJob::$handled, 'it must never reach the job');
    }

    public function test_a_forged_payload_is_failed_not_acked(): void
    {
        $queue = $this->runOnce(new RecordingQueue([self::payload('deadbeef')]), self::SECRET);

        self::assertSame(['fail'], $queue->calls);
        self::assertSame(0, SpyJob::$handled);
    }

    public function test_a_rejected_payload_is_not_retried(): void
    {
        // release() would put it back for another attempt; a signature that does
        // not verify will not verify on the second attempt either.
        $queue = $this->runOnce(new RecordingQueue([self::payload('')]), self::SECRET);

        self::assertNotContains('release', $queue->calls);
    }

    // ── A correctly signed payload runs normally ────────────────────────────

    public function test_a_correctly_signed_payload_runs_and_is_acked(): void
    {
        $queue = $this->runOnce(new RecordingQueue([self::signedPayload()]), self::SECRET);

        self::assertSame(['ack'], $queue->calls);
        self::assertSame(1, SpyJob::$handled);
    }

    // ── With no secret configured, verification is off ──────────────────────

    public function test_no_secret_leaves_verification_off(): void
    {
        // The historical behaviour, and still the default: an application whose
        // queue nothing else can write to should not have to sign anything.
        $queue = $this->runOnce(new RecordingQueue([self::payload('')]), '');

        self::assertSame(['ack'], $queue->calls);
        self::assertSame(1, SpyJob::$handled);
    }

    /** Run exactly one job through a loop wired to $queue. */
    private function runOnce(RecordingQueue $queue, string $secret): RecordingQueue
    {
        $core = new CoreContainer();
        $core->instance(QueuePort::class, $queue);

        $loop = new WorkerLoop($core, ErrorPipeline::notifiers([]), new WorkerPipeline(), $secret);
        $loop->run(maxIterations: 1);

        return $queue;
    }
}

/** Records which lifecycle verb the loop resolved a job with. */
final class RecordingQueue implements QueuePort
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

final class SpyJob implements JobContract
{
    public static int $handled = 0;

    public function handle(JobPayload $payload): JobResult
    {
        self::$handled++;

        return JobResult::success();
    }

    public function failed(JobPayload $payload, \Throwable $e): void {}
}
