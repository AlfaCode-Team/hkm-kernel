<?php

declare(strict_types=1);

namespace Plugins\Mail\Infrastructure\Transport;

/**
 * A mail transport delivers an already-built MIME message.
 *
 * The Mailer builds + (optionally) DKIM-signs the MIME; the transport only moves
 * the bytes. The envelope sender / recipients are passed separately so BCC stays
 * out of the visible headers and bounces route to the right Return-Path.
 */
interface Transport
{
    /**
     * @param string       $envelopeFrom bare address for MAIL FROM / -f
     * @param list<string> $recipients   bare addresses for RCPT TO (to+cc+bcc)
     * @param string       $mime         full message (headers + CRLFCRLF + body)
     */
    public function send(string $envelopeFrom, array $recipients, string $mime): void;
}
