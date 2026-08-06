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
    /** @var array<string, string> route name => path template */
    private array $byName = [];

    /** @var array<string, string> route name => HTTP method */
    private array $methodByName = [];

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
        foreach ($manifest as $key => $entry) {
            $name = $entry['name'] ?? null;
            if (!is_string($name) || $name === '') {
                continue;
            }
            [$method, $path]           = explode(' ', $key, 2);
            $this->byName[$name]       = $path;
            $this->methodByName[$name] = $method;
        }
    }

    /** Build from the compiled manifest on disk. */
    public static function fromManifest(string $base = '', string $secret = ''): self
    {
        return new self(
            ManifestReader::readCompiled('route-manifest.php'),
            $base,
            $secret !== '' ? $secret : (string) (\function_exists('env') ? (env('APP_KEY') ?: '') : ''),
        );
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
     * @param array<string, string|int|float|bool> $parameters
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

        $path = $this->substitute($name, $template, $parameters, $remaining);

        if ($remaining !== []) {
            $path .= '?' . http_build_query($remaining);
        }

        return $absolute ? $this->absolute($path) : $path;
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
     * @param array<string, string|int|float|bool> $parameters
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

        return $absolute
            ? $this->absolute($this->appendSignature($url))
            : $this->appendSignature($url);
    }

    /**
     * Verify a signed URL: signature intact AND (if present) not expired.
     *
     * Accepts a path with query string, e.g. `/verify/7?expires=…&signature=…`.
     * Pass the path only — a host is not covered by the signature, so including
     * one would make verification fail behind a proxy that rewrites it.
     */
    public function hasValidSignature(string $url): bool
    {
        if ($this->secret === '') {
            return false; // fail closed — never validate against an empty key
        }

        [$path, $query] = array_pad(explode('?', $url, 2), 2, '');

        parse_str($query, $params);

        $signature = $params['signature'] ?? null;
        unset($params['signature']);

        if (!is_string($signature) || $signature === '') {
            return false;
        }

        if (isset($params['expires']) && (int) $params['expires'] < time()) {
            return false;
        }

        $expected = $this->sign($path . ($params === [] ? '' : '?' . http_build_query($params)));

        // hash_equals — a timing-safe comparison. Never ===.
        return hash_equals($expected, $signature);
    }

    // ── internals ────────────────────────────────────────────────────────────

    /**
     * Replace `{name}` / `{name:type}` with values, validating each against its
     * declared type. Unconsumed parameters are returned via $remaining.
     *
     * @param array<string, string|int|float|bool> $parameters
     * @param array<string, string|int|float|bool>|null $remaining
     */
    private function substitute(string $name, string $template, array $parameters, ?array &$remaining): string
    {
        $remaining = $parameters;

        $path = preg_replace_callback(
            RouteParameter::PLACEHOLDER,
            function (array $m) use ($name, &$remaining): string {
                $param = $m[1];
                $type  = $m[2] ?? '';

                if (!array_key_exists($param, $remaining)) {
                    throw new \InvalidArgumentException(
                        "Route [{$name}] needs a value for {{$param}}."
                    );
                }

                $value = (string) $remaining[$param];
                unset($remaining[$param]);

                // Generating a URL the matcher cannot match is always a bug.
                $pattern = RouteParameter::pattern($type);
                if (preg_match('#^' . $pattern . '$#', $value) !== 1) {
                    throw new \InvalidArgumentException(
                        "Value [{$value}] for {{$param}} on route [{$name}] does not satisfy type"
                        . ($type === '' ? ' (a single path segment)' : " [{$type}]") . '.'
                    );
                }

                return rawurlencode($value);
            },
            $template,
        );

        return (string) $path;
    }

    private function absolute(string $path): string
    {
        return rtrim($this->base, '/') . $path;
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
