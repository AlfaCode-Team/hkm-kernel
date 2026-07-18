<?php

declare(strict_types=1);

namespace Plugins\Edge\Domain;

/**
 * The runtime a project is served with behind the edge:
 *  - Fpm    : nginx/Apache serve `<project>/app/public` via PHP-FPM (fastcgi),
 *             passing the run env as fastcgi_param / SetEnv.
 *  - Swoole : the project runs its own OpenSwoole HTTP server; nginx acts as a
 *             reverse proxy to that upstream (env lives in the Swoole process)
 *             while still serving static assets straight off disk.
 *
 * Accepted spellings (case/spacing insensitive): `php-fpm`, `php_fpm`, `fpm`
 * for PHP-FPM and `openswoole`, `open-swoole`, `swoole` for OpenSwoole. The
 * stored values stay `fpm`/`swoole` so existing proj.json / EDGE_SERVE_MODEL
 * settings keep working unchanged.
 */
enum ServeModel: string
{
    case Fpm    = 'fpm';
    case Swoole = 'swoole';

    /** Alias → canonical value. Lets `runtime=php-fpm|openswoole` be used too. */
    private const ALIASES = [
        'php-fpm'    => 'fpm',
        'php_fpm'    => 'fpm',
        'phpfpm'     => 'fpm',
        'fpm'        => 'fpm',
        'openswoole' => 'swoole',
        'open-swoole' => 'swoole',
        'open_swoole' => 'swoole',
        'swoole'     => 'swoole',
    ];

    /** Parse any accepted spelling; unknown input falls back to $default. */
    public static function from_(string $value, self $default = self::Fpm): self
    {
        $key = strtolower(trim($value));

        return self::tryFrom(self::ALIASES[$key] ?? $key) ?? $default;
    }

    /** Human label used in the generated config banner. */
    public function label(): string
    {
        return match ($this) {
            self::Fpm    => 'PHP-FPM',
            self::Swoole => 'OpenSwoole',
        };
    }

    /** The `# HKM Edge runtime: …` banner written at the top of each vhost. */
    public function banner(): string
    {
        return match ($this) {
            self::Fpm    => "# HKM Edge runtime: PHP-FPM\n",
            self::Swoole => "# HKM Edge runtime: OpenSwoole\n# Nginx is acting as reverse proxy\n",
        };
    }
}
