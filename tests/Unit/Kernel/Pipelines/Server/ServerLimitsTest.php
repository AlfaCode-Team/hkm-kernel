<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Pipelines\Server;

use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Server\ServerLimits;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * When a server process should end, and why.
 *
 * The "why" is load-bearing rather than cosmetic: a supervisor's log shows a
 * process that exited and nothing else, so a clean 512 MB recycle and a crash
 * are the same line. An operator chasing the second one needs to not be sent
 * after the first.
 */
#[CoversClass(ServerLimits::class)]
final class ServerLimitsTest extends TestCase
{
    public function test_with_no_ceilings_it_never_asks_to_stop(): void
    {
        self::assertFalse((new ServerLimits())->shouldStop());
    }

    public function test_being_asked_to_stop_is_sticky(): void
    {
        $limits = new ServerLimits();
        $limits->stop();

        self::assertTrue($limits->shouldStop());
        self::assertTrue($limits->shouldStop(), 'it must stay true — a loop polls it once per tick');
    }

    public function test_the_memory_ceiling_ends_the_process(): void
    {
        // PHP's own runtime is already past 1 MB of real allocation, so this is
        // exceeded on the first poll — no allocation games needed.
        $limits = new ServerLimits(memoryLimitMb: 1);

        self::assertTrue($limits->shouldStop());
        self::assertSame('memory', $limits->reason());
        self::assertStringContainsString('1 MB ceiling', $limits->describe());
    }

    public function test_the_lifetime_ceiling_ends_the_process(): void
    {
        // Backdating the start is what makes this assert the comparison rather
        // than sleeping for it.
        $limits = new ServerLimits(maxSeconds: 1, startedAt: microtime(true) - 5.0);

        self::assertTrue($limits->shouldStop());
        self::assertSame('runtime', $limits->reason());
        self::assertStringContainsString('1s lifetime', $limits->describe());
    }

    public function test_a_ceiling_not_yet_reached_does_not_stop(): void
    {
        $limits = new ServerLimits(maxSeconds: 3600, startedAt: microtime(true));

        self::assertFalse($limits->shouldStop());
        self::assertSame('', $limits->reason(), 'nothing to report while it is still running');
    }

    public function test_the_first_reason_wins(): void
    {
        // A SIGTERM arriving while the process is ALREADY winding down for
        // memory did not cause the shutdown. Reporting it as the cause sends
        // the operator after the wrong thing.
        $limits = new ServerLimits(memoryLimitMb: 1);
        $limits->shouldStop();
        $limits->stop('signal');

        self::assertSame('memory', $limits->reason());
    }

    public function test_the_manifest_block_is_read_and_clamped(): void
    {
        $limits = ServerLimits::fromManifest(['memoryMb' => 512, 'seconds' => -9]);

        self::assertSame(512, $limits->memoryLimitMb);
        self::assertSame(0, $limits->maxSeconds, 'a negative ceiling is no ceiling, not an instant exit');
    }

    public function test_an_absent_manifest_block_means_no_ceiling(): void
    {
        $limits = ServerLimits::fromManifest(null);

        self::assertSame(0, $limits->memoryLimitMb);
        self::assertSame(0, $limits->maxSeconds);
        self::assertFalse($limits->shouldStop());
    }

    public function test_describe_is_never_empty_even_before_a_stop(): void
    {
        self::assertNotSame('', (new ServerLimits())->describe());
    }

    public function test_with_no_reason_it_does_not_claim_the_loop_chose_to_return(): void
    {
        // Measured on Swoole 6.2.5: Swoole takes SIGTERM natively, below PHP's
        // handler table, so the kernel's trap never fires and nothing records
        // `signal`; and a ceiling reached inside a worker is recorded in that
        // fork, not here. Both arrive as no reason at all. "Returned on its
        // own" would be a false statement about a server that was in fact
        // terminated — so it is not made.
        $limits = new ServerLimits();

        self::assertFalse($limits->hasReason());
        self::assertStringContainsString('no reason recorded', $limits->describe());
        self::assertStringNotContainsString('on its own', $limits->describe());
    }

    public function test_a_recorded_reason_is_reportable(): void
    {
        $limits = new ServerLimits();
        $limits->stop('signal');

        self::assertTrue($limits->hasReason());
        self::assertStringContainsString('shutdown signal', $limits->describe());
    }
}
