<?php

declare(strict_types=1);

namespace Plugins\Edge\Domain;

/**
 * The routing strategy chosen for the detected server stack.
 *
 *  - NginxStream : nginx AND Apache are active and nginx supports the stream
 *                  module → run nginx as an SNI (L4) splitter: platform domains
 *                  to nginx, everything else to Apache.
 *  - NginxOnly   : only nginx is active (or Apache is present but not active, or
 *                  nginx lacks stream) → a plain nginx reverse-proxy vhost, no
 *                  stream layer.
 *  - ApacheOnly  : only Apache is active → an Apache SSL VirtualHost.
 *  - None        : no active web server detected → nothing to do.
 */
enum Strategy: string
{
    case NginxStream = 'nginx-stream';
    case NginxOnly   = 'nginx-only';
    case ApacheOnly  = 'apache-only';
    case None        = 'none';

    public function label(): string
    {
        return match ($this) {
            self::NginxStream => 'nginx SNI stream splitter (nginx + Apache fallback)',
            self::NginxOnly   => 'nginx-only reverse proxy (no stream)',
            self::ApacheOnly  => 'Apache-only SSL VirtualHost',
            self::None        => 'no active web server',
        };
    }
}
