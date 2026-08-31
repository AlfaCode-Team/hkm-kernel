<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Tests\Fixtures\Sample;

use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\Contracts\JobContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\JobPayload;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\JobResult;

/**
 * A job that always throws — the fixture for the retry/exhaustion path.
 *
 * The counters are static because the worker builds its own container per job,
 * so a test cannot hold a reference to the instance that ran.
 */
final class FailingJob implements JobContract
{
    public static int $handled = 0;
    public static int $deadLettered = 0;

    public static function reset(): void
    {
        self::$handled = 0;
        self::$deadLettered = 0;
    }

    public function handle(JobPayload $payload): JobResult
    {
        self::$handled++;

        throw new \RuntimeException('FailingJob always fails.');
    }

    public function failed(JobPayload $payload, \Throwable $e): void
    {
        self::$deadLettered++;
    }
}
