<?php

declare(strict_types=1);

namespace Plugins\Mail\Infrastructure\Security;

use Plugins\Mail\Domain\MailException;

/**
 * DKIM signer (RFC 6376) — `rsa-sha256`, relaxed/relaxed canonicalization.
 *
 * Produces the `DKIM-Signature:` header the Mailer prepends to the message so
 * receivers can cryptographically verify the domain. Requires an RSA private key
 * (PEM) plus the signing domain and DNS selector; the matching public key must
 * be published at `<selector>._domainkey.<domain>` TXT.
 */
final readonly class DkimSigner
{
    /** @param list<string> $signedHeaders lower-case header names to sign when present */
    public function __construct(
        private string $domain,
        private string $selector,
        private string $privateKeyPem,
        private array $signedHeaders = ['from', 'to', 'cc', 'subject', 'date', 'message-id', 'mime-version', 'content-type'],
    ) {}

    /**
     * @param list<string> $headers "Name: value" lines
     * @return string the DKIM-Signature header line (no trailing CRLF)
     */
    public function sign(array $headers, string $body): string
    {
        $key = openssl_pkey_get_private($this->privateKeyPem);
        if ($key === false) {
            throw new MailException('DKIM: invalid private key.');
        }

        $bodyHash = base64_encode(hash('sha256', $this->canonicalizeBody($body), true));

        // Collect the signed headers (last occurrence, in configured order).
        $index = $this->indexHeaders($headers);
        $names = [];
        $canonHeaders = [];
        foreach ($this->signedHeaders as $name) {
            if (isset($index[$name])) {
                $names[] = $name;
                $canonHeaders[] = $this->canonicalizeHeader($name, $index[$name]);
            }
        }

        $dkim = 'v=1; a=rsa-sha256; c=relaxed/relaxed; d=' . $this->domain
            . '; s=' . $this->selector
            . '; t=' . time()
            . '; h=' . implode(':', $names)
            . '; bh=' . $bodyHash
            . '; b=';

        // The DKIM-Signature header itself is signed with an empty b= and NO CRLF.
        $canonHeaders[] = $this->canonicalizeHeader('dkim-signature', $dkim);
        $toSign = implode("\r\n", $canonHeaders);

        $signature = '';
        if (openssl_sign($toSign, $signature, $key, OPENSSL_ALGO_SHA256) === false) {
            throw new MailException('DKIM: signing failed.');
        }

        return 'DKIM-Signature: ' . $dkim . base64_encode($signature);
    }

    /** @param list<string> $headers @return array<string,string> lower-name => value (last wins) */
    private function indexHeaders(array $headers): array
    {
        $index = [];
        foreach ($headers as $line) {
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $index[strtolower(trim(substr($line, 0, $pos)))] = ltrim(substr($line, $pos + 1));
        }
        return $index;
    }

    /** Relaxed header canonicalization: lower name, unfold, collapse WSP, trim. */
    private function canonicalizeHeader(string $name, string $value): string
    {
        $value = preg_replace('/\s+/', ' ', str_replace(["\r\n", "\r", "\n"], ' ', $value)) ?? $value;

        return $name . ':' . trim($value);
    }

    /** Relaxed body canonicalization: strip trailing WSP, collapse WSP, drop trailing blank lines. */
    private function canonicalizeBody(string $body): string
    {
        $body = str_replace(["\r\n", "\r", "\n"], "\n", $body);
        $lines = explode("\n", $body);
        $lines = array_map(
            static fn(string $l): string => rtrim((string) preg_replace('/[ \t]+/', ' ', $l)),
            $lines,
        );
        $canonical = implode("\r\n", $lines);
        $canonical = rtrim($canonical, "\r\n");

        return $canonical === '' ? '' : $canonical . "\r\n";
    }
}
