<?php declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Security\Waf\Rules;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Waf\Contracts\WafRuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Waf\WafMatch;

/**
 * Paths that only a vulnerability scanner asks for on a kernel application.
 *
 * A kernel app has no WordPress, no phpMyAdmin and no reason to serve a dotfile,
 * so a request for one is someone looking for a way in. It is the most reliable
 * signal a WAF gets, which is why it is the one that feeds WafLayer's ban.
 *
 * Matching is case-insensitive and anchored to path SEGMENTS, so `/.env` also
 * catches `/.env.bak` and `/api/.env`, while `/environment` and
 * `/.well-known/…` are untouched.
 *
 * `$allow` releases a built-in probe a project genuinely serves (say it really
 * has a `/cgi-bin`); `$extra` adds project-specific paths, matched as a prefix
 * on a segment boundary.
 */
final class ScannerProbeRule implements WafRuleContract
{
    /**
     * signature => pattern, applied to the decoded, lower-cased path.
     * Every pattern starts at a '/' so it matches whole segments only.
     */
    private const PROBES = [
        // `;` too: `/.env;x` is the same file to a server that strips path parameters.
        'dotfile'     => '#/\.(?:env|git|svn|hg|bzr|aws|ssh|docker|vscode|idea|npmrc|htaccess|htpasswd|ds_store|bash_history|composer)(?:[./;]|$)#',
        'wordpress'   => '#/(?:wp-(?:admin|content|includes|json)(?:/|$)|(?:wp-login|wp-config|wp-cron|xmlrpc)\.php)#',
        // Short names only at the root: `pma` is also an acronym a slug can carry.
        'db-admin'    => '#(?:^/(?:pma|myadmin|mysqladmin|sqladmin)(?:[./]|$)|/(?:phpmyadmin|adminer)(?:[./]|$))#',
        'vendor'      => '#/vendor/(?:phpunit|composer)(?:/|$)#',
        'php-probe'   => '#/(?:phpinfo|info|test|shell|cmd|eval-stdin|config|setup|install)\.php(?:/|$)#',
        'cgi'         => '#/cgi-bin(?:/|$)#',
        'server-info' => '#/(?:server-status|server-info|actuator|_profiler)(?:/|$)#',
        'device'      => '#/(?:boaform|hnap1|goform|setup\.cgi)(?:/|$)#',
        'manifest'    => '#^/(?:composer\.(?:json|lock)|package(?:-lock)?\.json|yarn\.lock|\.env\.example|docker-compose\.ya?ml|dockerfile)$#',
    ];

    /**
     * File extensions of dumps and editor leftovers, checked on the last
     * segment with str_ends_with — a `$`-anchored regex was retried from every
     * '/' of the path. Not .zip/.tar: an app serving downloads through
     * StoragePort has real ones.
     */
    private const BACKUP_EXTENSIONS = ['.sql', '.bak', '.old', '.orig', '.swp'];

    /** @var array<string, string> PROBES minus `$allow` */
    private readonly array $probes;

    private readonly bool $checkBackup;

    /** @var list<string> `$extra`, lower-cased and without a trailing slash */
    private readonly array $extra;

    /**
     * @param list<string> $allow signatures to switch off, e.g. ['cgi'] (PROBES keys, or 'backup')
     * @param list<string> $extra project paths to trap, e.g. ['/old-admin']
     */
    public function __construct(array $allow = [], array $extra = [])
    {
        $this->probes      = array_diff_key(self::PROBES, array_flip($allow));
        $this->checkBackup = !\in_array('backup', $allow, true);

        $normalised = [];
        foreach ($extra as $prefix) {
            $prefix = rtrim(strtolower($prefix), '/');
            if ($prefix !== '') {
                $normalised[] = $prefix;
            }
        }
        $this->extra = $normalised;
    }

    public function id(): string
    {
        return 'probe';
    }

    public function inspect(Request $request): ?WafMatch
    {
        $raw  = $request->path();
        $path = strtolower(rawurldecode($raw));

        foreach ($this->probes as $signature => $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return WafMatch::of($this->id(), $signature, 'path', $raw);
            }
        }

        if ($this->checkBackup) {
            foreach (self::BACKUP_EXTENSIONS as $extension) {
                if (str_ends_with($path, $extension)) {
                    return WafMatch::of($this->id(), 'backup', 'path', $raw);
                }
            }
        }

        foreach ($this->extra as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return WafMatch::of($this->id(), 'extra', 'path', $raw);
            }
        }

        return null;
    }
}
