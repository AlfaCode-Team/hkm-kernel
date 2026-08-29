<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\SecurityException;

/**
 * A dequeued payload the worker refuses to execute at all.
 *
 * Distinct from a job that RAN and FAILED, and the distinction decides what
 * happens to the message: a failure is retried, a rejection is not. Retrying
 * cannot help — a signature that does not verify will not verify on the second
 * attempt either — so it goes straight to the dead-letter queue, where it stays
 * visible.
 *
 * It extends SecurityException because that is what it is: something wrote to
 * the queue that could not prove it was the application. ErrorClassifier already
 * routes SecurityException at `warning`, so a rejected payload reaches the same
 * notifiers as a denied request rather than needing its own wiring.
 */
final class RejectedJobException extends SecurityException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, layer: 'worker.signature', code: 403, previous: $previous);
    }
}
