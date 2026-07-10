<?php

declare(strict_types=1);

namespace Plugins\Mail\Infrastructure\Transport;

use Plugins\Mail\Domain\MailException;

/**
 * Delivers via PHP's built-in mail(). Simple and dependency-free, but the least
 * capable transport (no auth, relies on the server MTA). The envelope sender is
 * passed via the `-f` additional parameter so bounces route correctly.
 *
 * The MIME we build already contains all headers, so we split the top header
 * block from the body and hand mail() the To/Subject it insists on separately.
 */
final class MailTransport implements Transport
{
    public function send(string $envelopeFrom, array $recipients, string $mime): void
    {
        if (preg_match('/[\r\n\x00]/', $envelopeFrom) === 1) {
            throw new MailException('mail(): envelope sender contains control characters.');
        }

        [$headerBlock, $body] = array_pad(explode("\r\n\r\n", $mime, 2), 2, '');

        $headers = $this->parseHeaders($headerBlock);
        $to      = $headers['to'] ?? implode(', ', $recipients);
        $subject = $headers['subject'] ?? '';

        // mail() takes To + Subject separately; remove them from the header blob.
        $remaining = preg_replace('/^(To|Subject):.*(\r\n|$)/mi', '', $headerBlock) ?? $headerBlock;

        $ok = mail($to, $subject, $body, trim($remaining), '-f' . $envelopeFrom);
        if ($ok === false) {
            throw new MailException('mail(): delivery failed.');
        }
    }

    /** @return array<string,string> lower-name => value (first line only) */
    private function parseHeaders(string $block): array
    {
        $out = [];
        foreach (explode("\r\n", $block) as $line) {
            $pos = strpos($line, ':');
            if ($pos !== false && $line[0] !== ' ' && $line[0] !== "\t") {
                $out[strtolower(substr($line, 0, $pos))] = ltrim(substr($line, $pos + 1));
            }
        }
        return $out;
    }
}
