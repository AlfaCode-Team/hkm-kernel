<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Tests;

use AlfacodeTeam\Ground\PluginGroundTestCase;
use AlfacodeTeam\Ground\Tests\Fixtures\Sample\FailingJob;
use AlfacodeTeam\Ground\Tests\Fixtures\Sample\Provider;

/**
 * The worker path, including the one it got WRONG.
 *
 * `FakeQueue::release()` used to re-queue the same immutable JobPayload, so
 * `attempts` stayed at 0, `hasExceededMaxAttempts()` was never true, and a
 * failing job was released forever instead of giving up. Every assertion about
 * retry exhaustion was unfalsifiable. These tests fail if that regresses.
 */
final class WorkerTest extends PluginGroundTestCase
{
    protected function plugin(): string
    {
        return Provider::class;
    }

    protected function setUp(): void
    {
        parent::setUp();
        FailingJob::reset();
    }

    public function testTheJobManifestRecordsWhatTheManifestDeclared(): void
    {
        $this->assertJobDeclared('ground.sample.failing');

        $entry = $this->ground()->jobs()['ground.sample.failing'];

        self::assertSame('sample-retry', $entry['queue']);
        self::assertSame('ground.sample', $entry['solves']);
    }

    public function testAFailingJobIsReleasedForAnotherAttempt(): void
    {
        $this->ground()->queue()->push(FailingJob::class, ['n' => 1], 'sample-retry');
        $this->ground()->work('sample-retry', 1);

        $this->assertJobReleased();
        self::assertSame(1, FailingJob::$handled);
        self::assertSame(0, FailingJob::$deadLettered, 'It must not give up on the first failure.');
    }

    public function testTheAttemptCounterActuallyAdvances(): void
    {
        $this->ground()->queue()->push(FailingJob::class, [], 'sample-retry');
        $this->ground()->work('sample-retry', 1);

        $requeued = $this->ground()->queue()->pop('sample-retry');

        // The whole bug in one assertion: a re-queued payload MUST come back
        // with a higher attempt count, or nothing ever exhausts.
        self::assertNotNull($requeued);
        self::assertSame(1, $requeued->attempts());
    }

    public function testAJobThatKeepsFailingEventuallyGivesUp(): void
    {
        $this->ground()->queue()->push(FailingJob::class, [], 'sample-retry');

        // Generously more iterations than attempts — if exhaustion did not work
        // this would release every single time and never drain.
        $this->ground()->work('sample-retry', 8);

        $this->assertJobExhausted('sample-retry');
        self::assertSame(1, FailingJob::$deadLettered, 'The dead-letter hook must run exactly once.');
        self::assertSame(4, FailingJob::$handled, 'maxAttempts 3 → the initial run plus three retries.');
    }

    public function testExhaustionSurfacesAsAnAckNotAQueueFailure(): void
    {
        $this->ground()->queue()->push(FailingJob::class, [], 'sample-retry');
        $this->ground()->work('sample-retry', 8);

        // WorkerLoop::process() calls the job's failed() hook and returns a
        // SKIPPED result rather than rethrowing, so processWithPort acks it.
        // QueuePort::fail() is reached only if that hook itself throws — which
        // is why assertJobFailedHard() is not the assertion to reach for.
        self::assertNotSame([], $this->ground()->queue()->acked);
        self::assertSame([], $this->ground()->queue()->failed);
    }

    public function testDrainingAnEmptyQueueIsHarmless(): void
    {
        $this->ground()->work('sample-retry', 1);

        self::assertSame([], $this->ground()->queue()->acked);
        self::assertSame([], $this->ground()->queue()->released);
    }

    public function testPushAndWorkIsTheSameAsPushingThenDraining(): void
    {
        $this->ground()->pushAndWork(FailingJob::class, ['n' => 2], 'sample-retry');

        self::assertSame(1, FailingJob::$handled);
        $this->assertJobReleased();
    }
}
