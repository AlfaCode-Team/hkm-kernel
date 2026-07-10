<?php

declare(strict_types=1);

namespace Plugins\Mail\Domain;

/** X-Priority levels (PHPMailer parity). */
enum Priority: int
{
    case High   = 1;
    case Normal = 3;
    case Low    = 5;

    public function label(): string
    {
        return match ($this) {
            self::High   => 'High',
            self::Normal => 'Normal',
            self::Low    => 'Low',
        };
    }
}
