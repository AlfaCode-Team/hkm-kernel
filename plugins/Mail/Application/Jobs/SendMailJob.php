<?php

declare(strict_types=1);

namespace Plugins\Mail\Application\Jobs;

use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\Contracts\JobContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\JobPayload;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\JobResult;
use Plugins\Mail\Domain\MailException;
use Plugins\Mail\Infrastructure\Transport\Transport;

/**
 * Delivers a message that was enqueued by Mailer::enqueue(). The MIME is already
 * built and DKIM-signed at enqueue time, so the job just moves the bytes — a
 * transport failure throws, triggering the worker's retry strategy.
 */
final class SendMailJob implements JobContract
{
    public function __construct(
        private readonly Transport $transport,
    ) {}

    public function handle(JobPayload $payload): JobResult
    {
        $data = $payload->data();
        $mime = (string) ($data['mime'] ?? '');
        $from = (string) ($data['from'] ?? '');
        /** @var list<string> $recipients */
        $recipients = array_values((array) ($data['recipients'] ?? []));

        if ($mime === '' || $from === '' || $recipients === []) {
            return JobResult::skipped('Malformed mail payload.');
        }

        $this->transport->send($from, $recipients, $mime);

        return JobResult::success(['recipients' => count($recipients)]);
    }

    public function failed(JobPayload $payload, \Throwable $e): void
    {
        error_log('[mail] permanent delivery failure: ' . $e->getMessage());
    }
}
