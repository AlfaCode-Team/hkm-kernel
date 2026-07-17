<?php

declare(strict_types=1);

namespace Plugins\Mail\Infrastructure;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\MailPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\GatewayException;

/**
 * SMTP implementation of the kernel MailPort.
 *
 * Renders the $view (a PHP template under the configured views directory) with
 * $data, builds a MIME HTML message, and delivers it via SmtpTransport.
 *
 * queue() delivers synchronously and returns a generated id; wire a QueuePort-
 * backed mailer if you need true async delivery.
 */
final class SmtpMailer implements MailPort
{
    public function __construct(
        private readonly SmtpTransport $transport,
        private readonly string $fromEmail,
        private readonly string $fromName = '',
        private readonly string $viewsPath = '',
    ) {
    }

    public function send(string|array $to, string $subject, string $view, array $data = []): void
    {
        $recipients = array_values((array) $to);
        $html = $this->render($view, $data);
        $message = $this->buildMime($recipients, $subject, $html);

        $this->transport->send([$this->fromEmail, $this->fromName], $recipients, $message);
    }

    public function queue(string|array $to, string $subject, string $view, array $data = []): string
    {
        $this->send($to, $subject, $view, $data);
        return 'sync-' . bin2hex(random_bytes(8));
    }

    /**
     * Render a PHP template to HTML. If $view contains a newline or '<' it is
     * treated as an inline HTML body instead of a template name.
     *
     * @param array<string,mixed> $data
     */
    private function render(string $view, array $data): string
    {
        if (str_contains($view, "\n") || str_contains($view, '<')) {
            return $view; // inline HTML
        }

        $file = rtrim($this->viewsPath, '/') . '/' . str_replace('.', '/', $view) . '.php';
        if ($this->viewsPath === '' || !is_file($file)) {
            throw new GatewayException(
                "Mail view [{$view}] not found.",
                layer: 'gateway.smtp',
                context: ['file' => $file],
            );
        }

        return (static function () use ($file, $data): string {
            extract($data, EXTR_SKIP);
            ob_start();
            include $file;
            return (string) ob_get_clean();
        })();
    }

    /** @param list<string> $recipients */
    private function buildMime(array $recipients, string $subject, string $html): string
    {
        $from = $this->fromName !== ''
            ? sprintf('%s <%s>', $this->mimeEncode($this->fromName), $this->fromEmail)
            : $this->fromEmail;

        $headers = [
            'From: ' . $from,
            'To: ' . implode(', ', $recipients),
            'Subject: ' . $this->mimeEncode($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (gethostname() ?: 'localhost') . '>',
        ];

        return implode("\r\n", $headers) . "\r\n\r\n" . $this->normalizeNewlines($html);
    }

    private function mimeEncode(string $value): string
    {
        return preg_match('/[^\x20-\x7E]/', $value) === 1
            ? '=?UTF-8?B?' . base64_encode($value) . '?='
            : $value;
    }

    private function normalizeNewlines(string $body): string
    {
        return preg_replace('/\r\n|\r|\n/', "\r\n", $body) ?? $body;
    }
}
