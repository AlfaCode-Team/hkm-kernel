<?php

declare(strict_types=1);

namespace Plugins\Mail\Infrastructure\Transport;

use Plugins\Mail\Domain\MailException;

/**
 * Native SMTP transport (no vendor dependency).
 *
 * Security & features:
 *   - Implicit TLS (`ssl`) OR opportunistic STARTTLS (`tls`); peer + peer-name
 *     verification ON by default (a MITM'd STARTTLS is refused).
 *   - AUTH: auto-negotiated from the server's EHLO, or forced — PLAIN, LOGIN,
 *     CRAM-MD5, XOAUTH2 (OAuth 2.0 bearer).
 *   - Multi-host failover (try each host until one connects) + optional
 *     keep-alive (RSET between messages instead of reconnecting).
 *   - Envelope MAIL FROM / RCPT TO are re-validated for CR/LF before they touch
 *     the socket — no SMTP command injection.
 */
final class SmtpTransport implements Transport
{
    private const EOL = "\r\n";

    /** @var resource|null */
    private $socket = null;

    /** True once the channel is encrypted (implicit TLS or successful STARTTLS). */
    private bool $secured = false;

    /**
     * @param list<string> $hosts       ordered failover list
     * @param 'tls'|'ssl'|'none' $encryption
     * @param 'auto'|'plain'|'login'|'cram-md5'|'xoauth2'|'none' $authMode
     */
    public function __construct(
        private readonly array $hosts,
        private readonly int $port = 587,
        private readonly string $encryption = 'tls',
        private readonly string $username = '',
        private readonly string $password = '',
        private readonly string $authMode = 'auto',
        private readonly string $oauthToken = '',
        private readonly string $heloDomain = '',
        private readonly int $timeout = 30,
        private readonly bool $verifyPeer = true,
        private readonly bool $keepAlive = false,
        /** Allow AUTH over a plaintext channel — DANGEROUS, off by default. */
        private readonly bool $allowInsecureAuth = false,
    ) {}

    public function send(string $envelopeFrom, array $recipients, string $mime): void
    {
        $this->assertNoInjection($envelopeFrom);
        foreach ($recipients as $rcpt) {
            $this->assertNoInjection($rcpt);
        }

        if ($this->socket === null) {
            $this->connect();
        }

        try {
            $this->command('MAIL FROM:<' . $envelopeFrom . '>', 250);
            foreach ($recipients as $rcpt) {
                $this->command('RCPT TO:<' . $rcpt . '>', 250);
            }
            $this->command('DATA', 354);
            $this->write($this->dotStuff($mime) . self::EOL . '.');
            $this->expect(250);
        } catch (\Throwable $e) {
            $this->close();
            throw $e;
        }

        if ($this->keepAlive) {
            $this->command('RSET', 250);
        } else {
            $this->close();
        }
    }

    // ── connection / handshake ───────────────────────────────────────────────

    private function connect(): void
    {
        $lastError = 'no hosts configured';

        foreach ($this->hosts as $host) {
            try {
                $this->open($host);
                $this->handshake();
                return;
            } catch (\Throwable $e) {
                $lastError = $host . ': ' . $e->getMessage();
                $this->close();
            }
        }

        throw new MailException('SMTP: could not connect (' . $lastError . ').');
    }

    private function open(string $host): void
    {
        $this->secured = false;
        $scheme = $this->encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $context = stream_context_create(['ssl' => [
            'verify_peer'       => $this->verifyPeer,
            'verify_peer_name'  => $this->verifyPeer,
            'allow_self_signed' => !$this->verifyPeer,
            'SNI_enabled'       => true,
            'peer_name'         => $host,
        ]]);

        $socket = @stream_socket_client(
            $scheme . $host . ':' . $this->port,
            $errno,
            $errstr,
            (float) $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context,
        );
        if ($socket === false) {
            throw new MailException("SMTP connect failed: {$errstr} ({$errno})");
        }

        stream_set_timeout($socket, $this->timeout);
        $this->socket = $socket;
        $this->secured = $this->encryption === 'ssl';   // implicit TLS
        $this->expect(220);          // server greeting
    }

    private function handshake(): void
    {
        $helo = $this->heloDomain !== '' ? $this->heloDomain : (gethostname() ?: 'localhost');

        $ehlo = $this->ehlo($helo);
        if ($this->encryption === 'tls') {
            // Fail CLOSED: if the server does not advertise STARTTLS we refuse
            // rather than silently continue in plaintext (downgrade protection).
            if (stripos($ehlo, 'STARTTLS') === false) {
                throw new MailException('SMTP: server did not offer STARTTLS; refusing to continue unencrypted.');
            }
            $this->command('STARTTLS', 220);
            $crypto = @stream_socket_enable_crypto(
                $this->socket,
                true,
                STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
            );
            if ($crypto !== true) {
                throw new MailException('SMTP: STARTTLS negotiation failed.');
            }
            $this->secured = true;
            $ehlo = $this->ehlo($helo);   // re-EHLO over the encrypted channel
        }

        if ($this->authMode !== 'none' && ($this->username !== '' || $this->oauthToken !== '')) {
            // NEVER put credentials on the wire in cleartext unless explicitly forced.
            if (!$this->secured && !$this->allowInsecureAuth) {
                throw new MailException('SMTP: refusing to send credentials over an unencrypted connection (enable TLS or allow_insecure_auth).');
            }
            $this->authenticate($ehlo);
        }
    }

    /** @return string the raw EHLO response (capability lines) */
    private function ehlo(string $helo): string
    {
        return $this->command('EHLO ' . $helo, 250);
    }

    // ── auth ─────────────────────────────────────────────────────────────────

    private function authenticate(string $ehlo): void
    {
        $mode = $this->authMode === 'auto' ? $this->negotiateAuth($ehlo) : $this->authMode;

        match ($mode) {
            'xoauth2'  => $this->authXoauth2(),
            'login'    => $this->authLogin(),
            'cram-md5' => $this->authCramMd5(),
            default    => $this->authPlain(),
        };
    }

    private function negotiateAuth(string $ehlo): string
    {
        $caps = strtoupper($ehlo);
        return match (true) {
            $this->oauthToken !== '' && str_contains($caps, 'XOAUTH2') => 'xoauth2',
            str_contains($caps, 'CRAM-MD5')                            => 'cram-md5',
            str_contains($caps, 'LOGIN')                               => 'login',
            default                                                    => 'plain',
        };
    }

    private function authPlain(): void
    {
        $token = base64_encode("\0" . $this->username . "\0" . $this->password);
        $this->command('AUTH PLAIN ' . $token, 235);
    }

    private function authLogin(): void
    {
        $this->command('AUTH LOGIN', 334);
        $this->command(base64_encode($this->username), 334);
        $this->command(base64_encode($this->password), 235);
    }

    private function authCramMd5(): void
    {
        $challenge = $this->command('AUTH CRAM-MD5', 334);
        $decoded   = base64_decode(trim(substr($challenge, 4)), true) ?: '';
        $digest    = hash_hmac('md5', $decoded, $this->password);
        $this->command(base64_encode($this->username . ' ' . $digest), 235);
    }

    private function authXoauth2(): void
    {
        $token = base64_encode(
            'user=' . $this->username . "\x01auth=Bearer " . $this->oauthToken . "\x01\x01",
        );
        $this->command('AUTH XOAUTH2 ' . $token, 235);
    }

    // ── protocol I/O ─────────────────────────────────────────────────────────

    private function command(string $command, int $expected): string
    {
        $this->write($command);
        return $this->expect($expected);
    }

    private function write(string $line): void
    {
        if ($this->socket === null || fwrite($this->socket, $line . self::EOL) === false) {
            throw new MailException('SMTP: write failed.');
        }
    }

    private function expect(int $code): string
    {
        $response = '';
        while (($line = fgets($this->socket ?: null, 515)) !== false) {
            $response .= $line;
            // Multi-line replies use "250-", the final line uses "250 ".
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }

        $status = (int) substr($response, 0, 3);
        if ($status !== $code) {
            throw new MailException('SMTP: expected ' . $code . ', got: ' . trim($response));
        }

        return $response;
    }

    /** SMTP dot-stuffing: a line starting with '.' gets an extra '.'. */
    private function dotStuff(string $mime): string
    {
        $mime = str_replace(["\r\n", "\r", "\n"], self::EOL, $mime);
        return (string) preg_replace('/^\./m', '..', $mime);
    }

    private function assertNoInjection(string $address): void
    {
        if (preg_match('/[\r\n\x00]/', $address) === 1) {
            throw new MailException('SMTP: address contains illegal control characters.');
        }
    }

    private function close(): void
    {
        if (is_resource($this->socket)) {
            @fwrite($this->socket, 'QUIT' . self::EOL);
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    public function __destruct()
    {
        $this->close();
    }
}
