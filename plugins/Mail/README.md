# Mail Plugin

> Solves: **`mail.delivery`** · Namespace: **`Plugins\Mail\`** · Type: on-demand GDA module

A **native, dependency-free** mail stack (no PHPMailer/Symfony Mailer required)
that implements the kernel `MailPort` and adds a rich `MailerContract`. It covers
the feature surface you'd expect from PHPMailer — attachments, inline images,
cc/bcc, DKIM, SMTP with TLS + auth — while staying self-contained.

## Quick start

```php
// Rich API (inject MailerContract)
$mailer->dispatch(
    $mailer->message()
        ->to('customer@example.com', 'Cust')
        ->cc('audit@shop.test')
        ->bcc('hidden@shop.test')          // delivered, never shown in headers
        ->replyTo('support@shop.test')
        ->subject('Your receipt ☕')        // non-ASCII → MIME encoded-word
        ->html('<h1>Thanks!</h1><img src="cid:logo">')
        ->embed('/path/logo.png', 'logo')  // inline image referenced by cid:
        ->attach('/path/receipt.pdf')
        ->priority(\Plugins\Mail\Domain\Priority::High),
);

// Kernel MailPort (view-based) — works for any module
$mail->send('customer@example.com', 'Welcome', 'user::emails/verify', ['url' => $url]);
$mail->queue($to, $subject, $view, $data);   // background delivery via QueuePort
```

## Message API (PHPMailer parity)

`from` · `sender` · `returnPath` · `to` · `cc` · `bcc` · `replyTo` · `subject` ·
`html` · `text` (auto plain-text alternative when only HTML is set) · `charset` ·
`priority` · `confirmReadingTo` (read receipt) · `attach` / `attachData` ·
`embed` / `embedData` (inline CID) · `header` (custom) · `tag` (metadata).

## Transports (`MAIL_TRANSPORT`)

| Value | Notes |
|---|---|
| `smtp` (default) | Native SMTP. `tls` (STARTTLS) or `ssl` (implicit); AUTH `plain`/`login`/`cram-md5`/`xoauth2` (auto-negotiated); **multi-host failover** (comma-separated `MAIL_SMTP_HOSTS`); optional keep-alive. |
| `sendmail` | Pipes to the sendmail binary with `-f` envelope. |
| `mail` | PHP `mail()`. |
| `array` | Captures messages in memory — **tests**. |
| `log` | Writes the full MIME to a log — **dev**. |

## Security (security-first defaults)

- **Header-injection proof.** Every address, name, custom header and attachment
  filename is rejected if it contains CR/LF/NUL (`Address`, `Message::header`,
  `MimeBuilder`, transports) — an attacker cannot smuggle a `Bcc:` through a
  user-supplied field.
- **BCC never leaks** — recipients get the mail via the envelope, but `Bcc:`
  is never emitted as a header.
- **TLS with peer verification ON by default** (`MAIL_VERIFY_PEER`).
- **Fail-closed STARTTLS** — if the server does not advertise STARTTLS the
  connection is refused, never downgraded to plaintext (downgrade protection).
- **No cleartext credential leak** — SMTP AUTH is refused over an unencrypted
  channel unless you explicitly set `MAIL_ALLOW_INSECURE_AUTH=true`.
- **DKIM** RSA-SHA256, relaxed/relaxed (`MAIL_DKIM_*`) — publish the public key
  at `<selector>._domainkey.<domain>`.
- **SMTP command injection** blocked (envelope/RCPT re-validated before the wire).

## Robustness

- **RFC 5322 header folding** — no header line exceeds 998 chars (folded at
  whitespace), so long To/Cc lists and Subjects aren't rejected by strict MTAs.
- **RFC 2047 encoded-words** — non-ASCII Subjects/names are split into multiple
  ≤75-char encoded-words (via `mb_encode_mimeheader`), never one oversized blob.
- **Auto plain-text alternative** generated from HTML so every mail is multipart.

## Performance (built for transactional volume)

- **Non-blocking by default** — `queue()` hands the built message to the
  `QueuePort` (`mail.send` job), so the HTTP request returns immediately; the
  worker does the SMTP round-trip. (The User plugin's signup email uses this.)
- **Connection reuse** — set `MAIL_KEEP_ALIVE=true` so a queue worker sends many
  messages over ONE SMTP connection (RSET between them) instead of reconnecting.
- **Fast path preserved** — short ASCII headers skip MIME-encoding and folding
  entirely; encoding only kicks in when a value actually needs it.

## Configuration

`config/mail.php` (all `MAIL_*` env, overridable per project via
`config_path('mail.php')`). Key vars: `MAIL_TRANSPORT`, `MAIL_FROM_ADDRESS/NAME`,
`MAIL_SMTP_HOSTS`/`MAIL_HOST`, `MAIL_PORT`, `MAIL_ENCRYPTION`,
`MAIL_USERNAME`/`MAIL_PASSWORD`, `MAIL_AUTH_MODE`, `MAIL_OAUTH_TOKEN`,
`MAIL_VERIFY_PEER`, `MAIL_KEEP_ALIVE`, `MAIL_DKIM_DOMAIN/SELECTOR/KEY`.

## Layout

```
API/Contracts/MailerContract        message() · dispatch(Message) · enqueue(Message)
Application/Mailer                   MailPort + MailerContract; compile → DKIM → transport/queue
Application/Jobs/SendMailJob         background delivery (job name "mail.send")
Domain/                             Message (builder), Address (CRLF guard), Attachment, Priority, MailException
Infrastructure/Mime/MimeBuilder     multipart mixed/related/alternative + QP/base64 encoders
Infrastructure/Security/DkimSigner  RSA-SHA256 relaxed/relaxed
Infrastructure/Transport/           Transport + Smtp/Sendmail/Mail/Array/Log
```

## Enabling

Add `Plugins\Mail\Provider::class` to the project's `withModules([...])`. It binds
`MailPort` + `MailerContract`, so e.g. the User plugin's signup verification email
is delivered automatically. `requires: []` (uses `ViewRendererContract` +
`QueuePort` when present, degrades gracefully otherwise).
