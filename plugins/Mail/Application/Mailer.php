<?php

declare(strict_types=1);

namespace Plugins\Mail\Application;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\MailPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\QueuePort;
use Plugins\Mail\API\Contracts\MailerContract;
use Plugins\Mail\Domain\MailException;
use Plugins\Mail\Domain\Message;
use Plugins\Mail\Infrastructure\Mime\MimeBuilder;
use Plugins\Mail\Infrastructure\Security\DkimSigner;
use Plugins\Mail\Infrastructure\Transport\Transport;
use Plugins\View\API\Contracts\ViewRendererContract;

/**
 * The mail façade — implements BOTH the kernel {@see MailPort} (view-based
 * send/queue used generically across the platform) and the richer
 * {@see MailerContract} (full Message builder).
 *
 * Pipeline per message: apply the default From → build MIME (MimeBuilder) →
 * optionally DKIM-sign (prepend the signature header) → hand the raw bytes and
 * envelope to the configured Transport (or enqueue via the QueuePort).
 */
final class Mailer implements MailPort, MailerContract
{
    public const QUEUE_JOB = 'mail.send';

    public function __construct(
        private readonly Transport $transport,
        private readonly MimeBuilder $mime,
        private readonly ?DkimSigner $dkim = null,
        private readonly ?ViewRendererContract $views = null,
        private readonly ?QueuePort $queue = null,
        private readonly string $fromEmail = '',
        private readonly string $fromName = '',
        private readonly string $charset = 'UTF-8',
        private readonly string $queueName = 'mail',
    ) {}

    // ── MailerContract (rich API) ────────────────────────────────────────────

    public function message(): Message
    {
        $m = Message::make()->charset($this->charset);
        if ($this->fromEmail !== '') {
            $m->from($this->fromEmail, $this->fromName);
        }
        return $m;
    }

    public function dispatch(Message $message): void
    {
        $compiled = $this->compile($message);
        $this->transport->send($compiled['from'], $compiled['recipients'], $compiled['mime']);
    }

    public function enqueue(Message $message): string
    {
        $compiled = $this->compile($message);

        if ($this->queue === null) {
            $this->transport->send($compiled['from'], $compiled['recipients'], $compiled['mime']);
            return '';
        }

        return $this->queue->push(self::QUEUE_JOB, $compiled, $this->queueName);
    }

    // ── MailPort (kernel, view-based) ────────────────────────────────────────

    /** @param string|array<int|string,string> $to */
    public function send(string|array $to, string $subject, string $view, array $data = []): void
    {
        $this->dispatch($this->fromView($to, $subject, $view, $data));
    }

    /** @param string|array<int|string,string> $to */
    public function queue(string|array $to, string $subject, string $view, array $data = []): string
    {
        return $this->enqueue($this->fromView($to, $subject, $view, $data));
    }

    // ── internals ────────────────────────────────────────────────────────────

    /** @return array{from: string, recipients: list<string>, mime: string} */
    private function compile(Message $message): array
    {
        if ($message->getFrom() === null) {
            if ($this->fromEmail === '') {
                throw new MailException('No From address on the message and no default configured.');
            }
            $message->from($this->fromEmail, $this->fromName);
        }

        $built   = $this->mime->build($message);
        $headers = $built['headers'];
        $body    = $built['body'];

        if ($this->dkim !== null) {
            array_unshift($headers, $this->dkim->sign($headers, $body));
        }

        /** @var \Plugins\Mail\Domain\Address $from */
        $from     = $message->getFrom();
        $envelope = $message->getReturnPath() ?? $message->getSender()?->email ?? $from->email;

        return [
            'from'       => $envelope,
            'recipients' => $message->recipientEmails(),
            'mime'       => implode("\r\n", $headers) . "\r\n\r\n" . $body,
        ];
    }

    /** @param string|array<int|string,string> $to */
    private function fromView(string|array $to, string $subject, string $view, array $data): Message
    {
        $message = $this->message()->subject($subject)->html($this->render($view, $data));

        foreach ($this->normaliseRecipients($to) as $email => $name) {
            $message->to($email, $name);
        }

        return $message;
    }

    private function render(string $view, array $data): string
    {
        // With the View plugin, treat $view as a template name; without it, the
        // caller passed raw HTML (so MailPort works even with no renderer bound).
        return $this->views !== null ? $this->views->render($view, $data) : $view;
    }

    /**
     * @param string|array<int|string,string> $to
     * @return array<string,string> email => name
     */
    private function normaliseRecipients(string|array $to): array
    {
        if (is_string($to)) {
            return [$to => ''];
        }

        $out = [];
        foreach ($to as $key => $value) {
            if (is_int($key)) {
                $out[$value] = '';       // list of emails
            } else {
                $out[$key] = $value;     // email => name
            }
        }
        return $out;
    }
}
