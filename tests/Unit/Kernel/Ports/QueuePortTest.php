<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Ports;

use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\JobPayload;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\QueuePort;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Project\Infrastructure\FileQueue;

/**
 * The four-verb lifecycle: pop → ack | release | fail.
 *
 * Exercised against FileQueue, the default adapter (Redis needs a live server).
 * The behaviours asserted here are the ones that make a queue survive a worker
 * crashing mid-job — reserve-then-resolve, attempt counting, and never silently
 * dropping a permanently-failing job.
 */
#[CoversClass(FileQueue::class)]
final class QueuePortTest extends TestCase
{
    private string $dir;
    private QueuePort $queue;

    protected function setUp(): void
    {
        $this->dir   = sys_get_temp_dir() . '/hkm-queue-' . bin2hex(random_bytes(6));
        $this->queue = new FileQueue($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function test_an_empty_queue_pops_null(): void
    {
        self::assertNull($this->queue->pop());
    }

    public function test_a_pushed_job_pops_back_as_a_typed_payload(): void
    {
        $id = $this->queue->push('App\\Jobs\\SendMail', ['to' => 'a@b.test']);

        $payload = $this->queue->pop();

        self::assertInstanceOf(JobPayload::class, $payload);
        self::assertSame($id, $payload->jobId());
        self::assertSame('App\\Jobs\\SendMail', $payload->jobClass());
        self::assertSame(['to' => 'a@b.test'], $payload->data());
        self::assertSame(0, $payload->attempts());
    }

    public function test_popping_reserves_the_job_so_a_second_worker_cannot_take_it(): void
    {
        $this->queue->push('App\\Jobs\\X', []);

        self::assertNotNull($this->queue->pop());
        self::assertNull($this->queue->pop(), 'a reserved job must not be handed out twice');
    }

    public function test_jobs_pop_in_fifo_order(): void
    {
        $this->queue->push('App\\Jobs\\First', []);
        $this->queue->push('App\\Jobs\\Second', []);

        self::assertSame('App\\Jobs\\First', $this->queue->pop()?->jobClass());
        self::assertSame('App\\Jobs\\Second', $this->queue->pop()?->jobClass());
    }

    public function test_a_delayed_job_is_not_yet_due(): void
    {
        $this->queue->later(3600, 'App\\Jobs\\Later', []);

        self::assertNull($this->queue->pop(), 'not available until its delay elapses');
        self::assertSame(1, $this->queue->size(), 'but it is still queued');
    }

    public function test_ack_leaves_the_queue_empty(): void
    {
        $this->queue->push('App\\Jobs\\X', []);
        $payload = $this->queue->pop();

        $this->queue->ack($payload);

        self::assertSame(0, $this->queue->size());
        self::assertNull($this->queue->pop());
    }

    public function test_release_requeues_with_an_incremented_attempt_count(): void
    {
        $this->queue->push('App\\Jobs\\Flaky', ['n' => 1]);

        $first = $this->queue->pop();
        self::assertSame(0, $first->attempts());

        $this->queue->release($first);

        $second = $this->queue->pop();
        self::assertNotNull($second, 'a released job comes back');
        self::assertSame(1, $second->attempts(), 'attempt count must advance or retries loop forever');
        self::assertSame(['n' => 1], $second->data(), 'payload survives the round trip');
    }

    public function test_release_with_a_delay_holds_the_job_back(): void
    {
        $this->queue->push('App\\Jobs\\Flaky', []);
        $payload = $this->queue->pop();

        $this->queue->release($payload, 3600);

        self::assertNull($this->queue->pop(), 'backoff must actually delay the retry');
        self::assertSame(1, $this->queue->size());
    }

    public function test_fail_removes_the_job_from_the_queue(): void
    {
        $this->queue->push('App\\Jobs\\Broken', []);
        $payload = $this->queue->pop();

        $this->queue->fail($payload, new \RuntimeException('nope'));

        self::assertSame(0, $this->queue->size(), 'a dead-lettered job must not come back');
        self::assertNull($this->queue->pop());
    }

    public function test_fail_records_the_job_rather_than_dropping_it(): void
    {
        $this->queue->push('App\\Jobs\\Broken', ['k' => 'v']);
        $payload = $this->queue->pop();

        $this->queue->fail($payload, new \RuntimeException('kaboom'));

        // A permanently-failing job that simply vanishes is the hardest kind of
        // bug to notice — it must be inspectable somewhere.
        self::assertSame(1, $this->queue->size('default.failed'));

        $dead = $this->queue->pop('default.failed');
        self::assertSame('App\\Jobs\\Broken', $dead?->jobClass());
        self::assertSame(['k' => 'v'], $dead?->data());
    }

    public function test_queues_are_isolated_from_each_other(): void
    {
        $this->queue->push('App\\Jobs\\Mail', [], 'emails');

        self::assertNull($this->queue->pop('default'));
        self::assertSame('App\\Jobs\\Mail', $this->queue->pop('emails')?->jobClass());
    }
}
