<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Fakes;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SmsPort;

/** An SmsPort that sends nothing and records every message. */
final class FakeSms implements SmsPort
{
    /** @var list<array{to: string, message: string}> */
    public array $sent = [];

    public function send(string $to, string $message): void
    {
        $this->sent[] = ['to' => $to, 'message' => $message];
    }

    public function sentTo(string $number): bool
    {
        foreach ($this->sent as $sms) {
            if ($sms['to'] === $number) {
                return true;
            }
        }

        return false;
    }

    /** The message body most recently sent to a number — for OTP assertions. */
    public function lastTo(string $number): ?string
    {
        for ($i = \count($this->sent) - 1; $i >= 0; $i--) {
            if ($this->sent[$i]['to'] === $number) {
                return $this->sent[$i]['message'];
            }
        }

        return null;
    }

    public function reset(): void
    {
        $this->sent = [];
    }
}
