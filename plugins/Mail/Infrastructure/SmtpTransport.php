<?php

declare(strict_types=1);

namespace Plugins\Mail\Infrastructure;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\GatewayException;

/**
 * Minimal, dependency-free SMTP client (EHLO, STARTTLS, AUTH LOGIN, DATA).
 *
 * Speaks just enough SMTP to deliver a single message reliably over a TLS or
 * STARTTLS connection. No external SDK — uses the native socket stream.
 */
final class SmtpTransport
{
    /** @var resource|null */
    private $socket = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port = 587,
        private readonly ?string $username = null,
        private readonly ?string $password = null,
        private readonly string $encryption = 'tls', // 'tls' (STARTTLS), 'ssl', or ''
        private readonly int $timeout = 30,
    ) {
    }

    /**
     * @param array{0:string,1:string} $from [email, name]
     * @param list<string> $recipients
     */
    public function send(array $from, array $recipients, string $rawMessage): void
    {
        $this->connect();
        try {
            $this->ehlo();

            if ($this->encryption === 'tls') {
                $this->command('STARTTLS', 220);
                if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new GatewayException('STARTTLS negotiation failed.', layer: 'gateway.smtp');
                }
                $this->ehlo(); // re-EHLO after upgrading
            }

            if ($this->username !== null) {
                $this->command('AUTH LOGIN', 334);
                $this->command(base64_encode($this->username), 334);
                $this->command(base64_encode((string) $this->password), 235);
            }

            $this->command('MAIL FROM:<' . $from[0] . '>', 250);
            foreach ($recipients as $rcpt) {
                $this->command('RCPT TO:<' . $rcpt . '>', 250);
            }
            $this->command('DATA', 354);
            // Dot-stuffing + terminating "."
            $body = preg_replace('/^\./m', '..', $rawMessage) ?? $rawMessage;
            $this->command($body . "\r\n.", 250);
            $this->command('QUIT', 221);
        } finally {
            $this->close();
        }
    }

    private function connect(): void
    {
        $prefix = $this->encryption === 'ssl' ? 'ssl://' : '';
        $socket = @stream_socket_client(
            $prefix . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
        );
        if ($socket === false) {
            throw new GatewayException(
                "Could not connect to SMTP host {$this->host}:{$this->port} ({$errstr}).",
                layer: 'gateway.smtp',
                context: ['errno' => $errno],
            );
        }
        $this->socket = $socket;
        stream_set_timeout($this->socket, $this->timeout);
        $this->expect(220);
    }

    private function ehlo(): void
    {
        $host = gethostname() ?: 'localhost';
        $this->command('EHLO ' . $host, 250);
    }

    private function command(string $line, int $expected): void
    {
        fwrite($this->socket, $line . "\r\n");
        $this->expect($expected);
    }

    private function expect(int $code): void
    {
        $response = '';
        while (($line = fgets($this->socket, 515)) !== false) {
            $response .= $line;
            // Multi-line replies use "250-"; the final line uses "250 ".
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }

        $actual = (int) substr($response, 0, 3);
        if ($actual !== $code) {
            throw new GatewayException(
                "Unexpected SMTP reply: expected {$code}, got " . trim($response),
                layer: 'gateway.smtp',
            );
        }
    }

    private function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }
}
