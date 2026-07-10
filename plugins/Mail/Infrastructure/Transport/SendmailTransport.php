<?php

declare(strict_types=1);

namespace Plugins\Mail\Infrastructure\Transport;

use Plugins\Mail\Domain\MailException;

/**
 * Pipes the message to a local sendmail-compatible binary. The envelope sender
 * is passed with -f (Return-Path); -t is NOT used, so BCC stays hidden and the
 * explicit RCPT list is authoritative.
 */
final class SendmailTransport implements Transport
{
    public function __construct(
        private readonly string $binary = '/usr/sbin/sendmail',
    ) {}

    public function send(string $envelopeFrom, array $recipients, string $mime): void
    {
        $this->assertSafe($envelopeFrom);
        foreach ($recipients as $r) {
            $this->assertSafe($r);
        }

        $cmd = escapeshellcmd($this->binary) . ' -oi'
            . ' -f ' . escapeshellarg($envelopeFrom) . ' '
            . implode(' ', array_map('escapeshellarg', $recipients));

        $process = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new MailException('Sendmail: failed to start ' . $this->binary);
        }

        fwrite($pipes[0], str_replace(["\r\n", "\r", "\n"], "\r\n", $mime));
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (proc_close($process) !== 0) {
            throw new MailException('Sendmail: delivery returned a non-zero status.');
        }
    }

    private function assertSafe(string $address): void
    {
        if (preg_match('/[\r\n\x00]/', $address) === 1) {
            throw new MailException('Sendmail: address contains control characters.');
        }
    }
}
