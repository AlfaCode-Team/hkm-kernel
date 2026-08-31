<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Fakes;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\MailPort;

/**
 * A MailPort that delivers nothing and remembers everything.
 *
 * The view is recorded as the NAME the caller passed, not rendered output: a
 * test asserting "the verification mail went out with this token" should not
 * fail because someone edited the template's wording.
 */
final class FakeMail implements MailPort
{
    /** @var list<array{to: list<string>, subject: string, view: string, data: array<string, mixed>, queued: bool}> */
    public array $sent = [];

    private int $counter = 0;

    public function send(string|array $to, string $subject, string $view, array $data = []): void
    {
        $this->record($to, $subject, $view, $data, queued: false);
    }

    public function queue(string|array $to, string $subject, string $view, array $data = []): string
    {
        $this->record($to, $subject, $view, $data, queued: true);

        return 'mail-' . (++$this->counter);
    }

    // ── Asserting ─────────────────────────────────────────────────────────────

    public function count(): int
    {
        return \count($this->sent);
    }

    /** True when anything was addressed to this recipient. */
    public function sentTo(string $address): bool
    {
        foreach ($this->sent as $mail) {
            if (\in_array($address, $mail['to'], true)) {
                return true;
            }
        }

        return false;
    }

    /** True when a message using this view was sent or queued. */
    public function sentView(string $view): bool
    {
        foreach ($this->sent as $mail) {
            if ($mail['view'] === $view) {
                return true;
            }
        }

        return false;
    }

    /** The most recent message, or null. Reads better than `$mail->sent[count(...)-1]`. */
    public function last(): ?array
    {
        return $this->sent === [] ? null : $this->sent[\count($this->sent) - 1];
    }

    public function reset(): void
    {
        $this->sent = [];
    }

    private function record(string|array $to, string $subject, string $view, array $data, bool $queued): void
    {
        $this->sent[] = [
            'to'      => array_values(array_map('strval', (array) $to)),
            'subject' => $subject,
            'view'    => $view,
            'data'    => $data,
            'queued'  => $queued,
        ];
    }
}
