<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Tests\Fixtures\Sample;

use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\Contracts\JobContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\JobPayload;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\JobResult;
use AlfacodeTeam\Ground\Fakes\FakeQueue;

/**
 * A job that verifies the signature on its OWN payload.
 *
 * The fixture exists for one regression: the ground used to stamp a hand-rolled
 * HMAC over the data alone, while the kernel signs
 * `jobId|jobClass|queue|maxAttempts|canonical(data)`. Every payload the harness
 * produced was therefore signature-invalid, and no assertion anywhere noticed —
 * the worker only verifies when a signing secret is configured, and the ground
 * configures none.
 *
 * A job that checks its own envelope is the cheapest thing that would have
 * caught it, and it fails in the same way the real WorkerLoop would.
 */
final class SignedJob implements JobContract
{
    public static bool $sawValidSignature = false;

    public static function reset(): void
    {
        self::$sawValidSignature = false;
    }

    public function handle(JobPayload $payload): JobResult
    {
        self::$sawValidSignature = $payload->isSignatureValid(FakeQueue::SECRET);

        return JobResult::success(['verified' => self::$sawValidSignature]);
    }

    public function failed(JobPayload $payload, \Throwable $e): void
    {
    }
}
