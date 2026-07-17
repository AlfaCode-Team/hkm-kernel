<?php

declare(strict_types=1);

namespace Plugins\Edge\Domain;

/**
 * How a project is served behind the edge:
 *  - Fpm    : nginx/Apache serve `<project>/app/public` via PHP-FPM (fastcgi),
 *             passing the run env as fastcgi_param / SetEnv.
 *  - Swoole : the project runs its own OpenSwoole HTTP server; the edge just
 *             reverse-proxies to that upstream (env lives in the Swoole process).
 */
enum ServeModel: string
{
    case Fpm    = 'fpm';
    case Swoole = 'swoole';

    public static function from_(string $value, self $default = self::Fpm): self
    {
        return self::tryFrom(strtolower(trim($value))) ?? $default;
    }
}
