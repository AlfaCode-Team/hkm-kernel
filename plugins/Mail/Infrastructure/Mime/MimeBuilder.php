<?php

declare(strict_types=1);

namespace Plugins\Mail\Infrastructure\Mime;

use Plugins\Mail\Domain\Attachment;
use Plugins\Mail\Domain\MailException;
use Plugins\Mail\Domain\Message;

/**
 * Builds a full RFC 5322 / MIME message (headers + body) from a {@see Message}.
 *
 * Structure is chosen by content:
 *   text only            → text/plain
 *   html only            → text/html (+ auto plain-text alternative)
 *   html + text          → multipart/alternative
 *   + inline images      → multipart/related wrapping the above
 *   + file attachments   → multipart/mixed wrapping the above
 *
 * Text parts are quoted-printable; attachments are base64 (76-col chunked).
 * All line endings are CRLF, as SMTP requires.
 */
final class MimeBuilder
{
    private const EOL = "\r\n";

    /** @return array{headers: list<string>, body: string} */
    public function build(Message $message): array
    {
        if ($message->getFrom() === null) {
            throw new MailException('A message must have a From address.');
        }
        if ($message->recipientEmails() === []) {
            throw new MailException('A message must have at least one recipient.');
        }

        $root    = $this->contentRoot($message);
        $headers = $this->topHeaders($message);
        foreach ($root['headers'] as $h) {
            $headers[] = $h;                       // Content-Type / -Transfer-Encoding of the root part
        }

        // Fold every header so no line exceeds the RFC 5322 limits — a long
        // To/Cc list or a long Subject would otherwise be rejected by strict MTAs.
        $headers = array_map($this->foldHeader(...), $headers);

        return ['headers' => $headers, 'body' => $root['body']];
    }

    // ── content tree ─────────────────────────────────────────────────────────

    /** @return array{headers: list<string>, body: string} */
    private function contentRoot(Message $m): array
    {
        $charset = $m->getCharset();
        $html    = $m->getHtml();
        $text    = $m->getText();

        if ($html !== '' && $text === '') {
            $text = $this->htmlToText($html);      // always ship a plain-text alternative
        }

        if ($html !== '' && $text !== '') {
            $content = $this->multipart('alternative', [
                $this->textPart($text, 'text/plain', $charset),
                $this->textPart($html, 'text/html', $charset),
            ]);
        } elseif ($html !== '') {
            $content = $this->textPart($html, 'text/html', $charset);
        } else {
            $content = $this->textPart($text, 'text/plain', $charset);
        }

        $inline  = array_values(array_filter($m->getAttachments(), static fn(Attachment $a): bool => $a->inline));
        $regular = array_values(array_filter($m->getAttachments(), static fn(Attachment $a): bool => !$a->inline));

        if ($inline !== []) {
            $rootType = $html !== '' ? 'text/html' : 'text/plain';
            $content  = $this->multipart(
                'related',
                [$content, ...array_map($this->attachmentPart(...), $inline)],
                '; type="' . $rootType . '"',
            );
        }

        if ($regular !== []) {
            $content = $this->multipart(
                'mixed',
                [$content, ...array_map($this->attachmentPart(...), $regular)],
            );
        }

        return $content;
    }

    // ── leaf parts ───────────────────────────────────────────────────────────

    /** @return array{headers: list<string>, body: string} */
    private function textPart(string $body, string $type, string $charset): array
    {
        return [
            'headers' => [
                'Content-Type: ' . $type . '; charset=' . $charset,
                'Content-Transfer-Encoding: quoted-printable',
            ],
            'body' => $this->quotedPrintable($body),
        ];
    }

    /** @return array{headers: list<string>, body: string} */
    private function attachmentPart(Attachment $a): array
    {
        $headers = [
            'Content-Type: ' . $a->mimeType . '; name="' . $this->headerParam($a->name) . '"',
            'Content-Transfer-Encoding: base64',
        ];
        if ($a->inline) {
            $headers[] = 'Content-Disposition: inline; filename="' . $this->headerParam($a->name) . '"';
            $headers[] = 'Content-ID: <' . $a->cid . '>';
        } else {
            $headers[] = 'Content-Disposition: attachment; filename="' . $this->headerParam($a->name) . '"';
        }

        return [
            'headers' => $headers,
            'body'    => rtrim(chunk_split(base64_encode($a->contents()), 76, self::EOL), self::EOL),
        ];
    }

    // ── multipart composition ────────────────────────────────────────────────

    /**
     * @param list<array{headers: list<string>, body: string}> $children
     * @return array{headers: list<string>, body: string}
     */
    private function multipart(string $subtype, array $children, string $typeParams = ''): array
    {
        $boundary = 'b1_' . bin2hex(random_bytes(16));

        $body = '';
        foreach ($children as $child) {
            $body .= '--' . $boundary . self::EOL
                . implode(self::EOL, $child['headers']) . self::EOL . self::EOL
                . $child['body'] . self::EOL;
        }
        $body .= '--' . $boundary . '--' . self::EOL;

        return [
            'headers' => ['Content-Type: multipart/' . $subtype . '; boundary="' . $boundary . '"' . $typeParams],
            'body'    => $body,
        ];
    }

    // ── top-level headers ────────────────────────────────────────────────────

    /** @return list<string> */
    private function topHeaders(Message $m): array
    {
        /** @var \Plugins\Mail\Domain\Address $from */
        $from    = $m->getFrom();
        $charset = $m->getCharset();
        $headers = [];

        $headers[] = 'Date: ' . date('r');
        $headers[] = 'From: ' . $from->toHeader($charset);
        if ($m->getSender() !== null) {
            $headers[] = 'Sender: ' . $m->getSender()->toHeader($charset);
        }
        if ($m->getTo() !== []) {
            $headers[] = 'To: ' . $this->addressList($m->getTo(), $charset);
        }
        if ($m->getCc() !== []) {
            $headers[] = 'Cc: ' . $this->addressList($m->getCc(), $charset);
        }
        // Bcc is deliberately NOT emitted as a header — recipients stay hidden.
        if ($m->getReplyTo() !== []) {
            $headers[] = 'Reply-To: ' . $this->addressList($m->getReplyTo(), $charset);
        }
        if ($m->getConfirmReadingTo() !== null) {
            $headers[] = 'Disposition-Notification-To: ' . $m->getConfirmReadingTo()->toHeader($charset);
        }

        $headers[] = 'Subject: ' . $this->encodeHeaderText($m->getSubject(), $charset);
        $headers[] = 'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $this->hostOf($from->email) . '>';
        $headers[] = 'X-Priority: ' . $m->getPriority()->value . ' (' . $m->getPriority()->label() . ')';
        $headers[] = 'X-Mailer: HKM-Mail';
        $headers[] = 'MIME-Version: 1.0';

        foreach ($m->getHeaders() as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        return $headers;
    }

    /** @param list<\Plugins\Mail\Domain\Address> $addresses */
    private function addressList(array $addresses, string $charset): string
    {
        return implode(', ', array_map(static fn($a): string => $a->toHeader($charset), $addresses));
    }

    // ── encoders ─────────────────────────────────────────────────────────────

    private function quotedPrintable(string $text): string
    {
        // Normalise to CRLF, then QP-encode (PHP inserts =\r\n soft breaks).
        $text = str_replace(["\r\n", "\r", "\n"], "\n", $text);
        $text = str_replace("\n", self::EOL, $text);

        return quoted_printable_encode($text);
    }

    /**
     * RFC 2047 encoded-word for non-ASCII header text (Subject, etc.). Uses
     * mb_encode_mimeheader so long values are split into MULTIPLE ≤75-char
     * encoded-words (a single oversized encoded-word is non-conformant and can
     * be mangled by receivers).
     */
    private function encodeHeaderText(string $text, string $charset): string
    {
        if (preg_match('/[^\x20-\x7E]/', $text) !== 1) {
            return $text;
        }
        return mb_encode_mimeheader($text, $charset, 'B', self::EOL);
    }

    /**
     * Fold a completed header line at whitespace so no line exceeds 78 chars
     * (soft; the RFC 5322 hard limit is 998). Only existing whitespace is used as
     * a fold point (RFC 5322 §2.2.3), so encoded-words and e-mail addresses —
     * which contain no spaces — are never split.
     */
    private function foldHeader(string $line, int $limit = 78): string
    {
        // Already-folded (mb_encode_mimeheader) or short lines pass through.
        if (strpos($line, self::EOL) !== false || strlen($line) <= $limit) {
            return $line;
        }

        $out = '';
        $current = '';
        foreach (preg_split('/( )/', $line, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$line] as $token) {
            if ($current !== '' && trim($current) !== '' && strlen($current . $token) > $limit) {
                $out .= rtrim($current, ' ') . self::EOL . ' ';
                $current = ltrim($token, ' ');
            } else {
                $current .= $token;
            }
        }

        return $out . $current;
    }

    /** Strip CR/LF/quotes from a header parameter (filename). */
    private function headerParam(string $value): string
    {
        return (string) preg_replace('/[\r\n"\x00]/', '', $value);
    }

    private function hostOf(string $email): string
    {
        $at = strrpos($email, '@');
        return $at === false ? 'localhost' : substr($email, $at + 1);
    }

    private function htmlToText(string $html): string
    {
        $text = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', '', $html) ?? $html;
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text) ?? $text;
        $text = preg_replace('/<\/(p|div|h[1-6]|li|tr)>/i', "\n", $text) ?? $text;

        return trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
