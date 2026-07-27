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

        // Single-pass CRLF normalisation — a str_replace(["\r\n","\r","\n"] → "\r\n")
        // doubles existing CRLFs (\r\n → \r\r\n\r\n) and corrupts the message.
        fwrite($pipes[0], (string) preg_replace('/\r\n|\r|\n/', "\r\n", $mime));
        fclose($pipes[0]);

        // Drain stdout/stderr to EOF BEFORE proc_close: a chatty binary that fills the
        // pipe buffer (~64 KB) while we hold the pipes open would otherwise deadlock.
        stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (proc_close($process) !== 0) {
            $detail = trim($stderr) !== '' ? ': ' . trim($stderr) : ' (non-zero exit status).';
            throw new MailException('Sendmail: delivery failed' . $detail);
        }
    }

    private function assertSafe(string $address): void
    {
        if (preg_match('/[\r\n\x00]/', $address) === 1) {
            throw new MailException('Sendmail: address contains control characters.');
        }
    }
}
