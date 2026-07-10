<?php

declare(strict_types=1);

namespace Plugins\Mail\Domain;

/**
 * A fluent, mutable e-mail message builder (PHPMailer-equivalent surface).
 *
 * Covers: From/Sender, To/Cc/Bcc, Reply-To, Subject, HTML + plain-text alt body,
 * file/raw/inline attachments, custom headers, priority, charset, read receipts,
 * envelope Return-Path, and free-form tags/metadata (for logging/webhooks).
 *
 * All addresses flow through {@see Address}, so CR/LF header injection is
 * impossible regardless of where the values came from.
 */
final class Message
{
    private ?Address $from = null;
    private ?Address $sender = null;              // envelope / Sender header
    private ?string $returnPath = null;

    /** @var list<Address> */
    private array $to = [];
    /** @var list<Address> */
    private array $cc = [];
    /** @var list<Address> */
    private array $bcc = [];
    /** @var list<Address> */
    private array $replyTo = [];

    private string $subject = '';
    private string $html = '';
    private string $text = '';
    private string $charset = 'UTF-8';
    private Priority $priority = Priority::Normal;
    private ?Address $confirmReadingTo = null;    // Disposition-Notification-To

    /** @var list<Attachment> */
    private array $attachments = [];
    /** @var array<string,string> */
    private array $headers = [];
    /** @var array<string,scalar> */
    private array $metadata = [];

    public static function make(): self
    {
        return new self();
    }

    // ── envelope / from ──────────────────────────────────────────────────────

    public function from(string $email, string $name = ''): self
    {
        $this->from = new Address($email, $name);
        return $this;
    }

    /** Distinct envelope sender (Sender header + default Return-Path). */
    public function sender(string $email, string $name = ''): self
    {
        $this->sender = new Address($email, $name);
        return $this;
    }

    public function returnPath(string $email): self
    {
        $this->returnPath = (new Address($email))->email;
        return $this;
    }

    // ── recipients ───────────────────────────────────────────────────────────

    public function to(string $email, string $name = ''): self
    {
        $this->to[] = new Address($email, $name);
        return $this;
    }

    public function cc(string $email, string $name = ''): self
    {
        $this->cc[] = new Address($email, $name);
        return $this;
    }

    public function bcc(string $email, string $name = ''): self
    {
        $this->bcc[] = new Address($email, $name);
        return $this;
    }

    public function replyTo(string $email, string $name = ''): self
    {
        $this->replyTo[] = new Address($email, $name);
        return $this;
    }

    // ── content ──────────────────────────────────────────────────────────────

    public function subject(string $subject): self
    {
        // Strip control chars — the subject becomes a header.
        $this->subject = (string) preg_replace('/[\r\n\x00]/', '', $subject);
        return $this;
    }

    public function html(string $html): self
    {
        $this->html = $html;
        return $this;
    }

    public function text(string $text): self
    {
        $this->text = $text;
        return $this;
    }

    public function charset(string $charset): self
    {
        $this->charset = $charset;
        return $this;
    }

    public function priority(Priority $priority): self
    {
        $this->priority = $priority;
        return $this;
    }

    /** Request a read receipt to this address (Disposition-Notification-To). */
    public function confirmReadingTo(string $email, string $name = ''): self
    {
        $this->confirmReadingTo = new Address($email, $name);
        return $this;
    }

    // ── attachments ──────────────────────────────────────────────────────────

    public function attach(string $path, string $name = '', string $mimeType = ''): self
    {
        $this->attachments[] = Attachment::fromPath($path, $name, $mimeType);
        return $this;
    }

    public function attachData(string $data, string $name, string $mimeType = 'application/octet-stream'): self
    {
        $this->attachments[] = Attachment::fromData($data, $name, $mimeType);
        return $this;
    }

    /** Embed an image and reference it in HTML as `<img src="cid:$cid">`. */
    public function embed(string $path, string $cid, string $name = '', string $mimeType = ''): self
    {
        $this->attachments[] = Attachment::inline($path, $cid, $name, $mimeType, isPath: true);
        return $this;
    }

    public function embedData(string $data, string $cid, string $name = '', string $mimeType = 'application/octet-stream'): self
    {
        $this->attachments[] = Attachment::inline($data, $cid, $name, $mimeType, isPath: false);
        return $this;
    }

    // ── headers / metadata ───────────────────────────────────────────────────

    public function header(string $name, string $value): self
    {
        if (preg_match('/[\r\n\x00]/', $name . $value) === 1) {
            throw new MailException('Custom headers may not contain control characters.');
        }
        $this->headers[$name] = $value;
        return $this;
    }

    /** Arbitrary tag for logging / webhooks (not sent unless you also add a header). */
    public function tag(string $key, string|int|float|bool $value): self
    {
        $this->metadata[$key] = $value;
        return $this;
    }

    // ── accessors (used by the MIME builder / transports) ────────────────────

    public function getFrom(): ?Address { return $this->from; }
    public function getSender(): ?Address { return $this->sender; }
    public function getReturnPath(): ?string { return $this->returnPath; }
    /** @return list<Address> */ public function getTo(): array { return $this->to; }
    /** @return list<Address> */ public function getCc(): array { return $this->cc; }
    /** @return list<Address> */ public function getBcc(): array { return $this->bcc; }
    /** @return list<Address> */ public function getReplyTo(): array { return $this->replyTo; }
    public function getSubject(): string { return $this->subject; }
    public function getHtml(): string { return $this->html; }
    public function getText(): string { return $this->text; }
    public function getCharset(): string { return $this->charset; }
    public function getPriority(): Priority { return $this->priority; }
    public function getConfirmReadingTo(): ?Address { return $this->confirmReadingTo; }
    /** @return list<Attachment> */ public function getAttachments(): array { return $this->attachments; }
    /** @return array<string,string> */ public function getHeaders(): array { return $this->headers; }
    /** @return array<string,scalar> */ public function getMetadata(): array { return $this->metadata; }

    /** All RCPT recipients (to + cc + bcc) as bare addresses. @return list<string> */
    public function recipientEmails(): array
    {
        $all = [];
        foreach ([...$this->to, ...$this->cc, ...$this->bcc] as $a) {
            $all[$a->email] = true;   // dedupe
        }
        return array_keys($all);
    }
}
