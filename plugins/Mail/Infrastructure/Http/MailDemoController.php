<?php

declare(strict_types=1);

namespace Plugins\Mail\Infrastructure\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\MailPort;
use Plugins\Mail\API\Contracts\MailerContract;
use Plugins\Mail\Domain\MailException;
use Plugins\Mail\Domain\Message;
use Plugins\Mail\Domain\Priority;
use Project\Http\Controllers\ApiController;

/**
 * DEMO / testing surface for the Mail plugin — a live, runnable tour of how to use
 * it. NOT meant for production: remove these routes (or gate them behind auth) when
 * you deploy. The five endpoints map 1:1 to the plugin's two published contracts:
 *
 *   GET /mail/demo          → this overview (transport + endpoint map, never sends)
 *   GET /mail/demo/preview  → MailerContract::preview()  — see the built MIME, no send
 *   GET /mail/demo/send     → MailerContract::dispatch()  — rich Message, sent now
 *   GET /mail/demo/queue    → MailerContract::enqueue()   — background delivery (job id)
 *   GET /mail/demo/view     → MailPort::send()            — kernel view-based shortcut
 *
 * Safety: the routes that actually transmit (send/queue/view) refuse to run unless a
 * non-sending transport (MAIL_TRANSPORT=array|log) is active OR APP_DEBUG=true — so
 * an accidentally-enabled demo can't become an open relay. `preview` never sends and
 * is always available.
 *
 * NOTE: this extends the RequestAware ApiController, so its actions take route params
 * only — the request is read via `$this->request`, never a `Request` parameter.
 */
final class MailDemoController extends ApiController
{
    public function __construct(
        private readonly MailerContract $mailer,   // rich API: message/dispatch/enqueue/preview
        private readonly MailPort $mail,           // kernel view-based shortcut: send/queue
    ) {
    }

    /** GET /mail/demo — overview + endpoint map (never sends). */
    public function index(): Response
    {
        return $this->ok([
            'plugin'          => 'mail',
            'transport'       => $this->transport(),
            'from'            => env('MAIL_FROM_ADDRESS', '(unset — falls back to demo@example.com)'),
            'sending_enabled' => $this->guard() === null,
            'endpoints'       => [
                'GET /mail/demo'         => 'This overview.',
                'GET /mail/demo/preview' => 'Build a rich sample message and return its raw MIME. Never sends.',
                'GET /mail/demo/send'    => 'MailerContract::dispatch() the sample message. ?to=you@example.com',
                'GET /mail/demo/queue'   => 'MailerContract::enqueue() for background delivery. ?to=you@example.com',
                'GET /mail/demo/view'    => 'Kernel MailPort::send() view-based shortcut. ?to=you@example.com',
            ],
            'tips'            => [
                'Test without a real SMTP server: set MAIL_TRANSPORT=array or MAIL_TRANSPORT=log.',
                'send/queue/view return 403 unless a non-sending transport is active or APP_DEBUG=true.',
                'GET /mail/demo/preview is the fastest way to SEE what the MIME builder produces.',
            ],
        ]);
    }

    /** GET /mail/demo/preview — compile the sample message to MIME and show it (no send). */
    public function preview(): Response
    {
        $to = (string) ($this->request?->input('to', 'recipient@example.com') ?? 'recipient@example.com');

        try {
            $mime = $this->mailer->preview($this->sample($to));
        } catch (MailException $e) {
            return $this->unprocessable(['to' => $e->getMessage()]);
        }

        return Response::text($mime)->withHeader('Content-Type', 'text/plain; charset=UTF-8');
    }

    /** GET /mail/demo/send — rich API: build + dispatch a Message via the configured transport. */
    public function send(): Response
    {
        if ($blocked = $this->guard()) {
            return $blocked;
        }

        $to = $this->recipient();
        if ($to === null) {
            return $this->unprocessable(['to' => 'A recipient email is required, e.g. ?to=you@example.com.']);
        }

        try {
            $this->mailer->dispatch($this->sample($to));
        } catch (MailException $e) {
            return $this->unprocessable(['to' => $e->getMessage()]);
        }

        return $this->ok(['sent' => true, 'via' => 'MailerContract::dispatch', 'to' => $to, 'transport' => $this->transport()]);
    }

    /** GET /mail/demo/queue — rich API: enqueue for background delivery via the QueuePort. */
    public function queue(): Response
    {
        if ($blocked = $this->guard()) {
            return $blocked;
        }

        $to = $this->recipient();
        if ($to === null) {
            return $this->unprocessable(['to' => 'A recipient email is required, e.g. ?to=you@example.com.']);
        }

        try {
            $jobId = $this->mailer->enqueue($this->sample($to));
        } catch (MailException $e) {
            return $this->unprocessable(['to' => $e->getMessage()]);
        }

        return $this->accepted([
            'queued' => $jobId !== '',
            'via'    => 'MailerContract::enqueue',
            'to'     => $to,
            'job_id' => $jobId,
            'note'   => $jobId === ''
                ? 'No QueuePort bound in this request — delivered synchronously instead.'
                : 'Enqueued as job "mail.send"; run a worker to deliver it.',
        ]);
    }

    /** GET /mail/demo/view — kernel MailPort shortcut: send($to, $subject, $view, $data). */
    public function view(): Response
    {
        if ($blocked = $this->guard()) {
            return $blocked;
        }

        $to = $this->recipient();
        if ($to === null) {
            return $this->unprocessable(['to' => 'A recipient email is required, e.g. ?to=you@example.com.']);
        }

        // With the View plugin loaded, arg 3 is a template NAME resolved by
        // ViewRendererContract. Here we pass raw HTML, which the Mailer emails as-is
        // when no renderer is bound — so MailPort::send() works even without View.
        $html = '<h1>Hello from HKM Mail</h1>'
            . '<p>This was delivered through the kernel <code>MailPort::send()</code> shortcut.</p>'
            . '<p>Sent at ' . htmlspecialchars(date('r'), ENT_QUOTES) . '.</p>';

        try {
            $this->mail->send($to, 'MailPort demo', $html, ['now' => date('r')]);
        } catch (MailException $e) {
            return $this->unprocessable(['to' => $e->getMessage()]);
        }

        return $this->ok(['sent' => true, 'via' => 'MailPort::send', 'to' => $to, 'transport' => $this->transport()]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * The canonical "everything" sample: default From, To/Cc/Bcc, Reply-To, a
     * non-ASCII subject (→ MIME encoded-word), HTML + explicit plain-text, an inline
     * CID image and a generated CSV attachment, high priority, custom header + tag.
     */
    private function sample(string $to): Message
    {
        // Self-contained 1×1 transparent PNG so the inline-image demo needs no file.
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
            true,
        ) ?: '';

        $message = $this->mailer->message();

        // message() pre-fills From from MAIL_FROM_ADDRESS; supply one for the demo
        // when none is configured so every endpoint works out of the box.
        if ($message->getFrom() === null) {
            $message->from('demo@example.com', 'HKM Mail Demo');
        }

        return $message
            ->to($to, 'Demo Recipient')
            ->cc('audit@example.com')
            ->bcc('hidden@example.com')                     // delivered, never shown in headers
            ->replyTo('support@example.com', 'Support')
            ->subject('Your receipt ☕ (HKM Mail demo)')     // non-ASCII → RFC 2047 encoded-word
            ->html('<h1>Thanks!</h1><p>Here is your receipt.</p><img src="cid:logo" alt="logo">')
            ->text('Thanks! Here is your receipt.')          // explicit plain-text alternative
            ->embedData($png, 'logo', 'logo.png', 'image/png')          // inline image via cid:logo
            ->attachData("id,amount\n1,42.00\n", 'receipt.csv', 'text/csv')
            ->priority(Priority::High)
            ->header('X-Demo', 'mail-plugin')
            ->tag('source', 'mail-demo');
    }

    /** Read + require a recipient from the query/body (null when missing). */
    private function recipient(): ?string
    {
        $to = trim((string) ($this->request?->input('to', '') ?? ''));

        return $to === '' ? null : $to;
    }

    /** Active transport name, lower-cased (defaults to smtp). */
    private function transport(): string
    {
        return strtolower((string) env('MAIL_TRANSPORT', 'smtp'));
    }

    /**
     * Block real transmission unless it is demonstrably safe: a non-sending transport
     * (array/log) or an explicit APP_DEBUG=true. Returns a 403 Response when blocked,
     * or null when the caller may proceed.
     */
    private function guard(): ?Response
    {
        $safe = in_array($this->transport(), ['array', 'log'], true)
            || filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOL);

        return $safe ? null : $this->forbidden(
            'Live mail sending is disabled for the demo routes. Set MAIL_TRANSPORT=array|log to test '
            . 'safely, or APP_DEBUG=true to allow real delivery.',
        );
    }
}
