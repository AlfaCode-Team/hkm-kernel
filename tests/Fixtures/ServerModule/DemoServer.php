<?php

declare(strict_types=1);

namespace Tests\Fixtures\ServerModule;

use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Server\Contracts\ServerContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Server\ServerContext;

/**
 * A server whose loop is observable and cannot run away.
 *
 * TICKS ARE BOUNDED ON PURPOSE. Every assertion here is about a loop ENDING,
 * and the failure mode of getting that wrong is a test suite that hangs rather
 * than one that fails — so the cap is the backstop that turns "shouldStop()
 * never became true" into a readable assertion instead of a CI timeout.
 */
final class DemoServer implements ServerContract
{
    /** A loop that polls correctly ends long before this. */
    public const MAX_TICKS = 5000;

    public static int $servedTimes = 0;
    public static int $ticks       = 0;
    public static int $stopCalls   = 0;
    public static int $exitCode    = 0;
    public static ?ServerContext $context = null;
    public static ?\Throwable $throw = null;

    /** Tick at which to raise SIGTERM at ourselves; 0 = never. */
    public static int $raiseTermAtTick = 0;

    /**
     * Return from serve() WITHOUT ever polling shouldStop().
     *
     * The shape of a forking server: under Swoole the loop belongs to Swoole,
     * the poll happens on a timer inside a worker, and the decision is recorded
     * in that fork — so the process that called run() sees a server that
     * returned having polled nothing.
     */
    public static bool $neverPolls = false;

    public static function reset(): void
    {
        self::$servedTimes     = 0;
        self::$ticks           = 0;
        self::$stopCalls       = 0;
        self::$exitCode        = 0;
        self::$context         = null;
        self::$throw           = null;
        self::$raiseTermAtTick = 0;
        self::$neverPolls      = false;
    }

    public function serve(ServerContext $context): int
    {
        self::$servedTimes++;
        self::$context = $context;

        if (self::$throw !== null) {
            throw self::$throw;
        }

        if (self::$neverPolls) {
            return self::$exitCode;
        }

        while (!$context->shouldStop()) {
            self::$ticks++;

            if (self::$raiseTermAtTick > 0 && self::$ticks === self::$raiseTermAtTick) {
                // The real thing a supervisor does, not a simulation of it.
                posix_kill(posix_getpid(), \SIGTERM);
            }

            if (self::$ticks >= self::MAX_TICKS) {
                break;
            }
        }

        return self::$exitCode;
    }

    public function stop(): void
    {
        self::$stopCalls++;
    }
}
