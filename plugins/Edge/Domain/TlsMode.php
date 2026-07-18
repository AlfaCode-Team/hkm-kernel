<?php

declare(strict_types=1);

namespace Plugins\Edge\Domain;

/**
 * How each rendered vhost terminates (or skips) TLS.
 *
 *  - Ssl  : listen on the TLS port only (:443) with an `ssl` listener. Default.
 *  - None : plain HTTP only — listen on :80, no certificate.
 *  - Both : plain :80 that 301-redirects to https, PLUS the :443 TLS listener.
 */
enum TlsMode: string
{
    case Ssl  = 'ssl';
    case None  = 'none';
    case Both  = 'both';

    /** Does this mode serve a TLS (:443) listener? */
    public function usesTls(): bool
    {
        return $this !== self::None;
    }

    /** Does this mode open a plain HTTP (:80) listener (serving or redirecting)? */
    public function usesHttp(): bool
    {
        return $this !== self::Ssl;
    }

    public function label(): string
    {
        return match ($this) {
            self::Ssl  => 'HTTPS only (:443)',
            self::None => 'HTTP only (:80, no TLS)',
            self::Both => 'HTTP (:80) → redirect to HTTPS (:443)',
        };
    }
}
