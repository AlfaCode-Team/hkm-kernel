<?php

declare(strict_types=1);

namespace Plugins\Mail\API\Contracts;

use Plugins\Mail\Domain\Message;

/**
 * Rich mail API published by the Mail plugin (beyond the kernel MailPort's
 * view-based helpers). Other modules type-hint THIS to build full messages with
 * attachments, cc/bcc, inline images, priority, etc.
 *
 * Method names differ from MailPort's `send`/`queue` because the same class also
 * implements MailPort (PHP has no method overloading): use `dispatch`/`enqueue`
 * for a built Message, `send`/`queue` (MailPort) for the view-based shortcut.
 */
interface MailerContract
{
    /** A fresh message pre-filled with the configured default From. */
    public function message(): Message;

    /** Build + (optionally DKIM-sign) + deliver a Message now. */
    public function dispatch(Message $message): void;

    /** Enqueue a Message for background delivery via the QueuePort; returns the job id. */
    public function enqueue(Message $message): string;
}
