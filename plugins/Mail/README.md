# Mail — Delivery (`solves: mail.delivery`)

> Namespace **`Plugins\Mail\`** · on-demand GDA module

A **native, dependency-free** mail stack — no PHPMailer, no Symfony Mailer — that
implements the kernel `MailPort` and adds a rich `MailerContract`. It covers the
feature surface you would expect from PHPMailer (attachments, inline images,
cc/bcc, DKIM, SMTP with TLS + auth) while staying entirely self-contained, so the
native distribution ships without `vendor/`.

---

## Part I — Requirements

### Module manifest

| Field | Value |
|---|---|
| `solves` | `mail.delivery` |
| `requires` | **none** — `[]` |
| `exposes` | `MailPort` (kernel port), `MailerContract` |
| `jobs` | `mail.send` → `SendMailJob`, queue `mail` |
| Routes | 5 demo `GET` routes (see Part IV — remove for production) |
| Activation | **on-demand** |

`requires: []` is deliberate: the plugin uses `ViewRendererContract` and
`QueuePort` **when present** and degrades gracefully when they are not. That
graceful degradation has one sharp edge — see the warning below.

### Kernel ports and collaborators

| Dependency | Used for | Required? |
|---|---|---|
| `QueuePort` | `queue()` / `enqueue()` background delivery | optional — without it, `enqueue()` **sends inline** and returns `''` |
| `ViewRendererContract` (`Plugins\View`) | rendering a view name into the HTML body | optional — see the warning |
| `StoragePort` / `DatabasePort` | *not used* — Mail owns no tables and no files | — |

> ### ⚠️ The view-rendering trap
>
> `MailPort::send($to, $subject, $view, $data)` treats `$view` as a **template
> name** only when a `ViewRendererContract` is bound. When the View plugin is
> **not** loaded for that request, the string is treated as **raw HTML** — so the
> literal text `auth::password-otp` is silently mailed as the message body.
>
> Any route that sends a view-based mail must therefore declare **both**:
>
> ```jsonc
> { "method": "POST", "path": "/…", "handler": "…",
>   "requires": ["mail.delivery", "view.rendering"] }
> ```
>
> Do not rely on another module pulling them in transitively — that breaks the
> moment the other module's `requires[]` changes.

### Configuration

Everything lives in `config/mail.php`, every value falling back to `env()`.
Override per project by copying it to `config_path('mail.php')`.

| Env key | Default | Meaning |
|---|---|---|
| `MAIL_TRANSPORT` | `smtp` | `smtp` · `sendmail` · `mail` · `array` · `log` |
| `MAIL_FROM_ADDRESS` | `''` | default `From:` — a message with no `from()` and no default **throws** |
| `MAIL_FROM_NAME` | `''` | display name for the default sender |
| `MAIL_CHARSET` | `UTF-8` | body + header charset |
| `MAIL_QUEUE` | `mail` | queue name used by `enqueue()`/`queue()` |
| `MAIL_SMTP_HOSTS` | `MAIL_HOST` | **comma-separated** host list — failover, tried in order |
| `MAIL_HOST` | `localhost` | single host (fallback when `MAIL_SMTP_HOSTS` is unset) |
| `MAIL_PORT` | `587` | SMTP port |
| `MAIL_ENCRYPTION` | `tls` | `tls` (STARTTLS) · `ssl` (implicit) · `none` |
| `MAIL_USERNAME` / `MAIL_PASSWORD` | `''` | SMTP AUTH credentials |
| `MAIL_AUTH_MODE` | `auto` | `auto`·`plain`·`login`·`cram-md5`·`xoauth2`·`none` |
| `MAIL_OAUTH_TOKEN` | `''` | bearer token for `xoauth2` |
| `MAIL_HELO_DOMAIN` | `''` | EHLO name (defaults to the local hostname) |
| `MAIL_TIMEOUT` | `30` | socket timeout, seconds |
| `MAIL_VERIFY_PEER` | `true` | **TLS peer verification — leave on** |
| `MAIL_KEEP_ALIVE` | `false` | reuse one SMTP connection across sends |
| `MAIL_ALLOW_INSECURE_AUTH` | `false` | permit AUTH over plaintext — **do not enable** |
| `MAIL_SENDMAIL_BINARY` | `/usr/sbin/sendmail` | sendmail transport binary |
| `MAIL_DKIM_DOMAIN` | `''` | signing domain — empty disables DKIM |
| `MAIL_DKIM_SELECTOR` | `''` | DNS selector |
| `MAIL_DKIM_KEY` | `''` | PEM string **or** path to the private key file |

Read them with `env()` — **never `getenv()`**.

### Wiring checklist

1. Add `Plugins\Mail\Provider::class` to the project's `withModules([...])`.
2. Set at minimum `MAIL_FROM_ADDRESS` (a message with no sender throws).
3. For SMTP: `MAIL_HOST`/`MAIL_SMTP_HOSTS`, `MAIL_PORT`, `MAIL_ENCRYPTION`,
   credentials. Leave `MAIL_VERIFY_PEER=true`.
4. Want background delivery? Bind a `QueuePort` and run a worker — the
   `mail.send` job is registered by this plugin's `module.json`.
5. Sending a **view**? Ensure `view.rendering` is loaded on that route.
6. Production: delete the five `/mail/demo/*` entries from `routes[]`, or veto
   them from the project (`proj.json` → `routePolicy.disable`).

---

## Part II — The two APIs

| API | Shape | Use when |
|---|---|---|
| **`MailPort`** (kernel) | `send($to, $subject, $view, $data)` · `queue(...)` | any module — the portable, view-based shortcut |
| **`MailerContract`** (this plugin) | `message()` → fluent `Message` → `dispatch()` / `enqueue()` / `preview()` | you need cc/bcc, attachments, inline images, headers, priority |

Both are the same underlying `Mailer` instance, so they share transport, DKIM
signer and defaults.

```php
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\MailPort;
use Plugins\Mail\API\Contracts\MailerContract;
```

Cross-plugin callers should type against `MailPort` (kernel port, no coupling) and
only reach for `MailerContract` when they genuinely need the rich surface.

### The pipeline

```
Message ──compile()──► MimeBuilder ──► DkimSigner (optional) ──► Transport
                          │                                          │
                    headers + body                          smtp│sendmail│mail
                    multipart/mixed                          array│log
                      └ related (inline CID)
                        └ alternative (text + html)
```

`compile()` also fills the default `From` and computes the envelope sender
(`Return-Path` → `Sender` → `From`).

---

## Part III — Usage

### 1. Simple, view-based (`MailPort`)

```php
$mail->send('customer@example.com', 'Welcome', 'user::emails/verify', ['url' => $url]);
$jobId = $mail->queue($to, 'Welcome', 'user::emails/verify', ['url' => $url]);
```

`$to` accepts a string, a list of addresses, or an `email => name` map. `$data`
becomes the template's variables. Remember the `view.rendering` requirement.

### 2. Rich message (`MailerContract`)

```php
$mailer->dispatch(
    $mailer->message()
        ->to('customer@example.com', 'Cust')
        ->cc('audit@shop.test')
        ->bcc('hidden@shop.test')            // delivered, never shown in headers
        ->replyTo('support@shop.test')
        ->subject('Your receipt ☕')          // non-ASCII → RFC 2047 encoded-word
        ->html('<h1>Thanks!</h1><img src="cid:logo">')
        ->embed('/path/logo.png', 'logo')    // inline image referenced by cid:
        ->attach('/path/receipt.pdf')
        ->priority(\Plugins\Mail\Domain\Priority::High),
);
```

### 3. The full `Message` builder

| Group | Methods |
|---|---|
| Sender | `from(email, name)` · `sender(email, name)` · `returnPath(email)` |
| Recipients | `to()` · `cc()` · `bcc()` · `replyTo()` — call repeatedly to add more |
| Content | `subject()` · `html()` · `text()` · `charset()` |
| Delivery hints | `priority(Priority::High\|Normal\|Low)` · `confirmReadingTo(email, name)` |
| Attachments | `attach(path, name, mime)` · `attachData(raw, name, mime)` |
| Inline | `embed(path, cid, name, mime)` · `embedData(raw, cid, name, mime)` |
| Extras | `header(name, value)` (custom) · `tag(key, value)` (metadata, not emitted) |
| Readers | `getFrom()` · `getTo()` · `getCc()` · `getBcc()` · `getSubject()` · `getHtml()` · `getAttachments()` · `getHeaders()` · `recipientEmails()` · … |

Every setter returns `$this`. Set only `html()` and a **plain-text alternative is
generated automatically**, so the mail is always `multipart/alternative`.

```php
$m = $mailer->message()
    ->to('a@x.test')->to('b@x.test')                 // repeat to add
    ->subject('Report')
    ->html('<p>See attached</p>')
    ->text('See attached')                            // explicit alternative
    ->attachData($csv, 'report.csv', 'text/csv')      // no temp file needed
    ->header('X-Campaign', 'july')
    ->tag('campaign', 'july');                        // metadata for your own code
```

### 4. Background delivery

```php
$jobId = $mailer->enqueue($message);      // → job id, or '' when no QueuePort is bound
```

The message is compiled to MIME **first**, then the `{from, recipients, mime}`
payload is pushed as the `mail.send` job on the `MAIL_QUEUE` queue. The worker
(`SendMailJob`) only re-opens the transport — it never re-renders, so a template
change between enqueue and delivery cannot alter a queued mail. A malformed
payload returns `JobResult::skipped()` rather than failing the worker.

> With **no** `QueuePort` bound, `enqueue()` silently sends inline and returns
> `''`. Check for `''` if the job id matters to you.

### 5. Previewing — no send

```php
echo $mailer->preview($message);   // the exact MIME, DKIM-signed, that would go on the wire
```

Ideal for a golden-file test or for eyeballing header folding and encoding.

### 6. Testing

```php
$transport = new ArrayTransport();
$mailer    = new Mailer(
    transport: $transport,
    mime:      new MimeBuilder(),
    views:     $viewRenderer,          // omit to treat $view as raw HTML
    fromEmail: 'no-reply@example.com',
);

$mailer->send('user@example.com', 'Hi', 'auth::password-otp', ['otp' => '123456']);

$transport->count();      // 1
$transport->last();       // ['from' => …, 'recipients' => [...], 'mime' => …]
$transport->messages();   // every captured message
$transport->flush();
```

Or set `MAIL_TRANSPORT=array` (in-memory) / `MAIL_TRANSPORT=log` (full MIME to the
error log) and nothing leaves the machine.

### 7. DKIM

```dotenv
MAIL_DKIM_DOMAIN=example.com
MAIL_DKIM_SELECTOR=mail
MAIL_DKIM_KEY=/etc/ssl/private/dkim.pem     # PEM string also accepted
```

RSA-SHA256, relaxed/relaxed canonicalisation. Publish the public key at
`<selector>._domainkey.<domain>`. Leave `MAIL_DKIM_DOMAIN` empty to disable.

### 8. SMTP failover and connection reuse

```dotenv
MAIL_SMTP_HOSTS=smtp1.example.com,smtp2.example.com
MAIL_KEEP_ALIVE=true
```

Hosts are tried in order until one connects. With keep-alive, several sends in one
request or job reuse a single connection (`RSET` between messages). The queue
worker builds a fresh module scope per job, so reuse is *within* a job — batch a
run of messages into one job to benefit.

---

## Part IV — Reference

### Transports (`MAIL_TRANSPORT`)

| Value | Notes |
|---|---|
| `smtp` (default) | Native SMTP. `tls` (STARTTLS) or `ssl` (implicit); AUTH `plain`/`login`/`cram-md5`/`xoauth2` (auto-negotiated); multi-host failover; optional keep-alive |
| `sendmail` | Pipes to the sendmail binary with a `-f` envelope sender |
| `mail` | PHP's `mail()` |
| `array` | Captures in memory — **tests** (`messages()`/`last()`/`count()`/`flush()`) |
| `log` | Writes the full MIME to the log — **dev** |

All implement `Infrastructure\Transport\Transport`:
`send(string $envelopeFrom, array $recipients, string $mime): void`. Add your own
(an API-based provider, say) by implementing it and binding it in the container.

### Demo routes

Self-contained [`MailDemoController`](Infrastructure/Http/MailDemoController.php)
wired to five `GET` routes so you can exercise every path from a browser.
**For learning/testing — remove the `routes[]` entries or gate them behind `auth`
before production.**

| Route | Shows | Sends? |
|---|---|---|
| `GET /mail/demo` | overview — active transport + endpoint map | no |
| `GET /mail/demo/preview?to=…` | `preview()` — the raw MIME | no |
| `GET /mail/demo/send?to=…` | `dispatch()` — rich message (cc/bcc, inline image, attachment) | yes |
| `GET /mail/demo/queue?to=…` | `enqueue()` — returns the job id | yes |
| `GET /mail/demo/view?to=…` | `MailPort::send()` — the view-based shortcut | yes |

**Safety guard:** `send`/`queue`/`view` return **403** unless a non-sending
transport (`array`/`log`) is active **or** `APP_DEBUG=true` — an accidentally
enabled demo can never become an open relay. `preview` never sends.

```bash
export MAIL_TRANSPORT=log                                   # nothing leaves the box
curl "http://localhost:8000/mail/demo/preview?to=you@example.com"
curl "http://localhost:8000/mail/demo/send?to=you@example.com"
```

### Security (defaults are security-first)

- **Header-injection proof** — every address, name, custom header and attachment
  filename is rejected if it contains CR/LF/NUL (`Address`, `Message::header`,
  `MimeBuilder`, transports). An attacker cannot smuggle a `Bcc:` through a
  user-supplied field.
- **BCC never leaks** — recipients get the mail via the envelope; `Bcc:` is never
  emitted as a header.
- **TLS peer verification ON by default** (`MAIL_VERIFY_PEER`).
- **Fail-closed STARTTLS** — if the server does not advertise STARTTLS the
  connection is refused, never downgraded to plaintext.
- **No cleartext credential leak** — SMTP AUTH is refused over an unencrypted
  channel unless `MAIL_ALLOW_INSECURE_AUTH=true` is explicitly set.
- **SMTP command injection** blocked (envelope/RCPT re-validated before the wire).
- **DKIM** RSA-SHA256, relaxed/relaxed.

### Robustness

- **RFC 5322 header folding** — no header line exceeds 998 chars (folded at
  whitespace), so long To/Cc lists and Subjects survive strict MTAs.
- **RFC 2047 encoded-words** — non-ASCII Subjects/names split into multiple
  ≤75-char encoded-words, never one oversized blob.
- **Auto plain-text alternative** generated from HTML.
- **Fast path preserved** — short ASCII headers skip MIME-encoding and folding
  entirely; encoding kicks in only when a value needs it.

### Errors

Everything the plugin throws is `Plugins\Mail\Domain\MailException`
(`\RuntimeException`): no `From` address, an address failing the CR/LF guard, an
unreadable attachment, an SMTP handshake/AUTH failure, a DKIM key that will not
load. Callers that treat mail as non-critical (the Auth password flows, for
instance) catch `\Throwable` and carry on.

### Layout

```
API/Contracts/MailerContract            message() · dispatch() · enqueue() · preview()
Application/Mailer                      MailPort + MailerContract; compile → DKIM → transport/queue
Application/Jobs/SendMailJob            background delivery (job name "mail.send")
Domain/                                 Message (builder) · Address (CRLF guard) · Attachment · Priority · MailException
Infrastructure/Mime/MimeBuilder         multipart mixed/related/alternative + QP/base64 encoders
Infrastructure/Security/DkimSigner      RSA-SHA256 relaxed/relaxed
Infrastructure/Transport/               Transport + Smtp/Sendmail/Mail/Array/Log
Infrastructure/Http/MailDemoController  demo routes (GET /mail/demo/*) — remove for prod
config/mail.php                         all MAIL_* configuration
```

### Rules

**Do** — type cross-plugin callers against `MailPort` · declare
`"requires": ["mail.delivery", "view.rendering"]` on any route that mails a view ·
keep `MAIL_VERIFY_PEER=true` · use `array`/`log` transports in tests and dev ·
`queue()` anything on a request path · treat mail failure as non-fatal in flows
that already committed their real work.

**Don't** — enable `MAIL_ALLOW_INSECURE_AUTH` · ship the `/mail/demo/*` routes ·
put user input into a header without going through `Message::header()` ·
`getenv()` a `MAIL_*` value · assume `enqueue()` returned a job id without a
`QueuePort` bound · pass view data as the renderer's second argument — that
parameter is render *options*; template variables go through `setData()`.
