<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Routing;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\ManifestReader;

/**
 * UrlGenerator — builds URLs from the compiled route manifest.
 *
 * The inverse of {@see \AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\RouteMatcher}:
 * the matcher turns a URL into a route, this turns a route into a URL. Both read
 * the same manifest, so they cannot disagree about what a route's path is.
 *
 * WHY NAMED ROUTES MATTER HERE SPECIFICALLY
 * -----------------------------------------
 * The whole platform is built on projects OVERRIDING and DISABLING plugin routes.
 * With literal path strings, a plugin's own view that links to "/register" breaks
 * silently the moment a project moves that page — the link 404s and nothing fails
 * at build time. A name survives the override (the compiler carries it onto the
 * project's replacement route), so the link keeps working.
 *
 *     $url->route('auth.register');            // /register  — or wherever it moved
 *     $url->route('user.show', ['id' => 7]);   // /users/7
 *
 * PARAMETERS ARE VALIDATED AGAINST THEIR TYPE
 * -------------------------------------------
 * A route declared `/users/{id:num}` will not generate `/users/abc`. Producing a
 * URL the matcher provably cannot match is always a bug, and catching it here
 * turns a mystery 404 into an exception at the call site.
 *
 * SIGNED URLS
 * -----------
 * signedRoute() appends an HMAC over the URL so a recipient cannot tamper with
 * it — the standard mechanism behind email verification and one-time action
 * links. Signing uses HMAC (integrity), not encryption (confidentiality): the
 * parameters stay readable, they just cannot be changed.
 *
 * NOT REQUEST-SCOPED
 * ------------------
 * This class holds no request state. Absolute URLs need a base, which the caller
 * supplies (typically from `$request->site()`), so the generator stays usable
 * from CLI and worker contexts where there is no Request at all — which is
 * exactly where email links get built.
 */
final class UrlGenerator
{
    /**
     * Matches one placeholder together with the separator in front of it, so an
     * omitted optional parameter takes its '/' with it.
     */
    private const PLACEHOLDER_WITH_SEPARATOR = '#(/?)\{([^}:?]+)(?::([^}?]+))?(\?)?\}#';

    /** @var array<string, string> route name => path template */
    private array $byName = [];

    /** @var array<string, string> route name => HTTP method */
    private array $methodByName = [];

    /**
     * route name => the domain group it belongs to ('' when ungrouped).
     *
     * Used only for ABSOLUTE urls: a project serving two brands has two routes
     * called `vote.home` and `africa.home`, and generating both against a single
     * APP_URL would send half its links to the wrong site.
     *
     * @var array<string, string>
     */
    private array $domainByName = [];

    /**
     * @param array<string, array<string, mixed>> $manifest compiled route manifest
     * @param string $base   base URL for absolute generation, e.g. https://app.example.com
     * @param string $secret HMAC key for signed URLs; defaults to APP_KEY
     */
    public function __construct(
        array $manifest,
        private readonly string $base = '',
        private readonly string $secret = '',
    ) {
        foreach (RouteIndex::names($manifest) as $name => $route) {
            $this->byName[$name]       = $route['path'];
            $this->methodByName[$name] = $route['method'];
            $this->domainByName[$name] = $route['domain'] ?? '';
        }
    }

    /**
     * Build from the compiled manifests on disk.
     *
     * Prefers `route-names.php` — a name => {path, method} index the boot compiler
     * writes. Reading it instead of the full route table matters most where this
     * class is actually used: a CLI command or queue worker that mints one
     * password-reset link should not hold the application's entire routing
     * surface in memory to do it. Falls back to the flat manifest when the index
     * is absent (a deploy whose cache predates it).
     */
    public static function fromManifest(string $base = '', string $secret = ''): self
    {
        $secret = $secret !== ''
            ? $secret
            : (string) (\function_exists('env') ? (env('APP_KEY') ?: '') : '');

        /** @var array<string, array{path: string, method: string}> $names */
        $names = ManifestReader::readCompiled('route-names.php');

        if ($names !== []) {
            $generator = new self([], $base, $secret);

            foreach ($names as $name => $route) {
                $generator->byName[$name]       = $route['path'] ?? '';
                $generator->methodByName[$name] = $route['method'] ?? 'GET';
                $generator->domainByName[$name] = $route['domain'] ?? '';
            }

            return $generator;
        }

        return new self(ManifestReader::readCompiled('route-manifest.php'), $base, $secret);
    }

    public function has(string $name): bool
    {
        return isset($this->byName[$name]);
    }

    /** The HTTP method a named route answers — useful for building forms. */
    public function methodFor(string $name): ?string
    {
        return $this->methodByName[$name] ?? null;
    }

    /**
     * The URL for a named route.
     *
     * Parameters not consumed by a path placeholder become the query string, so
     * `route('search', ['q' => 'x'])` on `/search` yields `/search?q=x`.
     *
     * @param array<string, string|int|float|bool|null> $parameters  a null or ''
     *        value counts as OMITTED, which is what an optional `{page?}` wants
     *
     * @throws \InvalidArgumentException on an unknown name, a missing required
     *         parameter, or a value that violates the placeholder's declared type
     */
    public function route(string $name, array $parameters = [], bool $absolute = false): string
    {
        $template = $this->byName[$name] ?? null;

        if ($template === null) {
            throw new \InvalidArgumentException(
                "Unknown route name [{$name}]. Declare \"name\" on the route in module.json or proj.json."
            );
        }

        $remaining = [];
        $path      = $this->substitute($name, $template, $parameters, $remaining);

        if ($remaining !== []) {
            $path .= '?' . http_build_query($remaining);
        }

        return $absolute ? $this->absolute($path, $this->domainByName[$name] ?? '') : $path;
    }

    /** The domain group a named route belongs to, or '' when it is ungrouped. */
    public function domainFor(string $name): string
    {
        return $this->domainByName[$name] ?? '';
    }

    /**
     * A URL for a literal path — the escape hatch for endpoints that have no name
     * (a plugin's route you did not author, an external redirect target).
     *
     * @param array<string, string|int|float|bool> $query
     */
    public function to(string $path, array $query = [], bool $absolute = false): string
    {
        $path = '/' . ltrim($path, '/');

        if ($query !== []) {
            $path .= '?' . http_build_query($query);
        }

        return $absolute ? $this->absolute($path) : $path;
    }

    /**
     * A tamper-proof URL for a named route.
     *
     * Appends `signature`, an HMAC over the path and its query. Any change to the
     * path or to a parameter invalidates it. Optionally appends `expires` (a UNIX
     * timestamp) which is covered by the same signature, so the deadline cannot be
     * extended by editing the URL.
     *
     * @param array<string, string|int|float|bool|null> $parameters  a null or ''
     *        value counts as OMITTED, which is what an optional `{page?}` wants
     * @param int|null $expiresIn seconds from now; null = no expiry
     *
     * @throws \RuntimeException when no signing secret is configured — failing
     *         closed, because a URL signed with an empty key is forgeable by
     *         anyone and would look identical to a real one
     */
    public function signedRoute(
        string $name,
        array $parameters = [],
        ?int $expiresIn = null,
        bool $absolute = false,
    ): string {
        $this->requireSecret();

        if ($expiresIn !== null) {
            $parameters['expires'] = time() + $expiresIn;
        }

        $url = $this->route($name, $parameters);

        // The signature covers the path and query only, never the host, so
        // choosing a per-domain base cannot invalidate it.
        return $absolute
            ? $this->absolute($this->appendSignature($url), $this->domainByName[$name] ?? '')
            : $this->appendSignature($url);
    }

    /**
     * Verify a signed URL: signature intact AND (if present) not expired.
     *
     * Accepts a path with query string, e.g. `/verify/7?expires=…&signature=…`.
     * Pass the path only — a host is not covered by the signature, so including
     * one would make verification fail behind a proxy that rewrites it.
     *
     * The query is compared BYTE FOR BYTE with the `signature` pair removed, not
     * parsed and re-serialised. `parse_str()` rewrites '.', ' ' and '[' inside
     * parameter NAMES, so a legitimately signed URL carrying such a parameter
     * could never validate — the check failed closed, but it failed.
     */
    public function hasValidSignature(string $url): bool
    {
        if ($this->secret === '') {
            return false; // fail closed — never validate against an empty key
        }

        [$path, $query] = array_pad(explode('?', $url, 2), 2, '');

        $signature = null;
        $expires   = null;
        $signed    = [];

        foreach ($query === '' ? [] : explode('&', $query) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');

            // Only the FIRST signature pair is lifted out; a second one injected
            // by an attacker stays in the signed material and breaks the match.
            if ($key === 'signature' && $signature === null) {
                $signature = urldecode($value);
                continue;
            }

            if ($key === 'expires') {
                $expires = urldecode($value);
            }

            $signed[] = $pair;
        }

        if ($signature === null || $signature === '') {
            return false;
        }

        if ($expires !== null && (int) $expires < time()) {
            return false;
        }

        $expected = $this->sign($path . ($signed === [] ? '' : '?' . implode('&', $signed)));

        // hash_equals — a timing-safe comparison. Never ===.
        return hash_equals($expected, $signature);
    }

    // ── internals ────────────────────────────────────────────────────────────

    /**
     * Replace `{name}` / `{name:type}` / `{name?}` with values, validating each
     * against its declared type. Unconsumed parameters are returned via $remaining.
     *
     * A repeated placeholder (`/a/{id}/b/{id}`) is supported: consumption is
     * tracked in a set rather than by removing the value, which previously made
     * the second occurrence report a missing parameter.
     *
     * @param array<string, string|int|float|bool|null> $parameters  a null or ''
     *        value counts as OMITTED, which is what an optional `{page?}` wants
     * @param array<string, string|int|float|bool|null> $remaining
     */
    private function substitute(string $name, string $template, array $parameters, array &$remaining): string
    {
        $consumed = [];

        $path = preg_replace_callback(
            self::PLACEHOLDER_WITH_SEPARATOR,
            function (array $m) use ($name, $parameters, &$consumed): string {
                $separator = $m[1];
                $param     = $m[2];
                $type      = ($m[3] ?? '') !== '' ? $m[3] : '';
                $optional  = ($m[4] ?? '') === '?';

                $present = array_key_exists($param, $parameters)
                    && $parameters[$param] !== null
                    && $parameters[$param] !== '';

                if (!$present) {
                    if ($optional) {
                        // Takes its separator with it: /posts/{page?} → /posts
                        $consumed[$param] = true;

                        return '';
                    }

                    throw new \InvalidArgumentException(
                        "Route [{$name}] needs a value for {{$param}}."
                    );
                }

                $value              = (string) $parameters[$param];
                $consumed[$param]   = true;

                // Generating a URL the matcher cannot match is always a bug.
                $pattern = RouteParameter::pattern($type);
                if (preg_match('#^' . $pattern . '$#D', $value) !== 1) {
                    throw new \InvalidArgumentException(
                        "Value [{$value}] for {{$param}} on route [{$name}] does not satisfy type"
                        . ($type === '' ? ' (a single path segment)' : " [{$type}]") . '.'
                    );
                }

                return $separator . rawurlencode($value);
            },
            $template,
        );

        $remaining = array_diff_key($parameters, $consumed);

        return (string) $path;
    }

    /**
     * Prefix a path with the right origin.
     *
     * A route grouped under a concrete HOST is absolute against THAT host, so a
     * two-brand project links each brand to itself instead of sending every link
     * to whatever single APP_URL happens to be configured. The scheme is taken
     * from the configured base (https when there is none).
     *
     * A wildcard (`*.example.com`) or a bare subdomain (`api`) names no single
     * host — there is nothing to build an origin from — so those fall back to the
     * configured base, exactly as an ungrouped route does.
     */
    private function absolute(string $path, string $domain = ''): string
    {
        return rtrim($this->originFor($domain), '/') . $path;
    }

    private function originFor(string $domain): string
    {
        if ($domain === '' || str_starts_with($domain, '*.') || !str_contains($domain, '.')) {
            return $this->base;
        }

        $scheme = $this->base !== '' ? parse_url($this->base, PHP_URL_SCHEME) : null;

        return (is_string($scheme) && $scheme !== '' ? $scheme : 'https') . '://' . $domain;
    }

    private function appendSignature(string $url): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . 'signature=' . $this->sign($url);
    }

    private function sign(string $url): string
    {
        return hash_hmac('sha256', $url, $this->secret);
    }

    private function requireSecret(): void
    {
        if ($this->secret === '') {
            throw new \RuntimeException(
                'Cannot sign a URL: no signing secret configured (APP_KEY is empty). '
                . 'A URL signed with an empty key is forgeable by anyone.'
            );
        }
    }
}
