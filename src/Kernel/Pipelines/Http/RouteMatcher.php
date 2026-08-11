<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Routing\{RouteIndex, RouteParameter};

/**
 * RouteMatcher — matches a method+path against the compiled route index.
 *
 * Exact matches are an O(1) hash lookup. Parameterized routes ({id}, {slug})
 * fall back to a regex scan with NAMED capture, so captured values are returned
 * and forwarded to the controller. Dynamic routes are bucketed by HTTP method
 * AND by their first literal path segment, so a request only tests the handful
 * of patterns that could possibly match its prefix.
 *
 * Placeholders may be TYPED — `{id:num}`, `{slug:slug}`, `{path:any}` — which
 * narrows the segment pattern so a non-matching value 404s at the routing layer
 * instead of reaching a controller. See {@see RouteParameter} for the type table
 * and the compatibility rules. An untyped `{id}` still means `[^/]+`.
 *
 * Static routes are checked BEFORE dynamic ones, so a literal `/users/me` always
 * wins over `/users/{id}` regardless of declaration order. Among dynamic routes
 * the first match wins, in manifest order (preserved across the bucket split by
 * each candidate's ordinal).
 *
 * Built once and reused across requests — it holds no per-request state.
 *
 * ── PERCENT-DECODING IS PART OF MATCHING ─────────────────────────────────────
 * `Request::path()` is the RAW path: `%2F` is three ordinary characters, so it
 * slips through `[^/]+` untouched. Handing that to a controller means the "one
 * path segment" guarantee evaporates the moment anything decodes it — and it
 * must decode it, or `/users/José` arrives as `Jos%C3%A9`.
 *
 * So a captured value is decoded and then RE-VALIDATED against its declared
 * type. `/files/..%2F..%2Fetc%2Fpasswd` no longer satisfies `{name}`, because
 * the decoded `../../etc/passwd` does not. Decoding runs only when the value
 * actually contains '%', so the common case costs one strpos.
 */
final class RouteMatcher
{
    /** Trailing-slash policies. `strict` is the historical behaviour. */
    public const TRAILING_STRICT   = 'strict';
    public const TRAILING_IGNORE   = 'ignore';
    public const TRAILING_REDIRECT = 'redirect';

    /** @var array<string, array<string, array<string, mixed>>> domain => "METHOD /path" => entry */
    private array $static = [];

    /**
     * Dynamic (parameterized) routes bucketed by domain, then HTTP method, then by
     * first literal path segment, plus a `wild` list for routes whose first
     * segment is itself a placeholder.
     *
     * @var array<string, array<string, array{buckets?: array<string, list<array<string, mixed>>>, wild?: list<array<string, mixed>>}>>
     */
    private array $dynamic = [];

    /** @var list<string> every method some route answers — used to compute Allow. */
    private array $methods = [];

    /** Whether ANY route is grouped under a domain — lets the common case skip the loops. */
    private bool $grouped = false;

    /**
     * Legacy constructor: accepts the FLAT route manifest and derives the index
     * in-process. Kept because it is public API (tests and any consumer that
     * builds a matcher from a hand-made array). Prefer {@see fromCompiled()},
     * which reads an index the boot compiler already built.
     *
     * @param array<string, array<string, mixed>> $manifest
     */
    public function __construct(
        array $manifest,
        private readonly bool $headFallback = true,
        private readonly string $trailingSlash = self::TRAILING_STRICT,
    ) {
        $this->apply(RouteIndex::build($manifest));
    }

    /**
     * Build from `route-index.php` — the index the boot compiler precomputed.
     *
     * @param array<string, mixed> $index
     */
    public static function fromCompiled(
        array $index,
        bool $headFallback = true,
        string $trailingSlash = self::TRAILING_STRICT,
    ): self {
        $matcher = new self([], $headFallback, $trailingSlash);
        $matcher->apply($index);

        return $matcher;
    }

    /** @param array<string, mixed> $index */
    private function apply(array $index): void
    {
        $this->static  = is_array($index['static'] ?? null) ? $index['static'] : [];
        $this->dynamic = is_array($index['dynamic'] ?? null) ? $index['dynamic'] : [];
        $this->methods = is_array($index['methods'] ?? null) ? array_values($index['methods']) : [];
        $this->grouped = is_array($index['domains'] ?? null) && $index['domains'] !== [];
    }

    /**
     * Whether ANY route is grouped under a domain.
     *
     * Lets a caller skip expanding the request host into candidate keys — which
     * costs more than the match itself — when no route could possibly use them.
     */
    public function hasDomainGroups(): bool
    {
        return $this->grouped;
    }

    /**
     * @param list<string> $domains the domain-group keys this request may match,
     *        MOST SPECIFIC FIRST — normally {@see RouteIndex::hostCandidates()}
     *        applied to the request host. Empty means only the shared group,
     *        which is every route in an application that groups nothing.
     *
     * @return array{entry: array<string, mixed>, params: array<string, string>}|null
     */
    public function match(string $method, string $path, array $domains = []): ?array
    {
        $found = $this->lookup($method, $path, $domains);

        if ($found === null && $this->trailingSlash === self::TRAILING_IGNORE) {
            $found = $this->lookup($method, self::alternateSlash($path), $domains);
        }

        // HEAD is defined as GET without a body. Without this, every page on the
        // site 404s for link checkers, uptime monitors and caches that probe with
        // HEAD. ExecuteStage drops the body again on the way out.
        if ($found === null && $this->headFallback && $method === 'HEAD') {
            $found = $this->lookup('GET', $path, $domains);

            if ($found === null && $this->trailingSlash === self::TRAILING_IGNORE) {
                $found = $this->lookup('GET', self::alternateSlash($path), $domains);
            }
        }

        return $found;
    }

    /**
     * The methods that DO answer this path — for a `405 Method Not Allowed` and
     * its mandatory `Allow` header. Only ever called after a miss, so the extra
     * cross-method scan never touches the hot path.
     *
     * @return list<string>
     */
    public function allowedMethods(string $path, array $domains = []): array
    {
        $allowed = [];

        foreach ($this->methods as $method) {
            if ($this->lookup($method, $path, $domains) !== null) {
                $allowed[] = $method;
            }
        }

        if ($allowed !== [] && $this->headFallback && in_array('GET', $allowed, true)
            && !in_array('HEAD', $allowed, true)) {
            $allowed[] = 'HEAD';
        }

        return $allowed;
    }

    /**
     * Under the `redirect` trailing-slash policy: the canonical path this one
     * should be sent to, or null when the alternate form does not match either.
     */
    public function canonicalPath(string $method, string $path, array $domains = []): ?string
    {
        if ($this->trailingSlash !== self::TRAILING_REDIRECT) {
            return null;
        }

        $alternate = self::alternateSlash($path);

        if ($alternate === $path || $this->lookup($method, $alternate, $domains) === null) {
            return null;
        }

        return $alternate;
    }

    // ── internals ────────────────────────────────────────────────────────────

    /**
     * One exact lookup.
     *
     * Order is SPECIFICITY within each table, and static still beats dynamic:
     *
     *   1. each domain group's static routes, most specific first
     *   2. the shared group's static routes
     *   3. each domain group's dynamic routes, most specific first
     *   4. the shared group's dynamic routes
     *
     * Doing all the static work before any dynamic work preserves the invariant
     * that a literal `/users/me` always beats `/users/{id}` — if a domain group
     * were searched end-to-end first, its `/users/{id}` would swallow the shared
     * literal `/users/me`, which is the kind of surprise this router exists to
     * avoid. Within that, a domain group wins over the shared group, so declaring
     * `GET /` under `organizer` overrides the shared `GET /` on that host only.
     *
     * @param list<string> $domains most specific first
     * @return array{entry: array<string, mixed>, params: array<string, string>}|null
     */
    private function lookup(string $method, string $path, array $domains = []): ?array
    {
        $key = $method . ' ' . $path;

        // An application that groups nothing pays a single bool check for all of
        // this and then behaves exactly as it did before domain groups existed.
        if ($this->grouped) {
            foreach ($domains as $domain) {
                if (isset($this->static[$domain][$key])) {
                    return ['entry' => $this->static[$domain][$key], 'params' => []];
                }
            }
        }

        if (isset($this->static[''][$key])) {
            return ['entry' => $this->static[''][$key], 'params' => []];
        }

        if ($this->grouped) {
            foreach ($domains as $domain) {
                $match = $this->scan($this->dynamic[$domain][$method] ?? null, $path);
                if ($match !== null) {
                    return $match;
                }
            }
        }

        return $this->scan($this->dynamic[''][$method] ?? null, $path);
    }

    /**
     * Scan one domain+method's dynamic candidates in declaration order.
     *
     * @param array{buckets?: array<string, list<array<string, mixed>>>, wild?: list<array<string, mixed>>}|null $bucketed
     * @return array{entry: array<string, mixed>, params: array<string, string>}|null
     */
    private function scan(?array $bucketed, string $path): ?array
    {
        if ($bucketed === null) {
            return null;
        }

        $candidates = $bucketed['buckets'][RouteIndex::requestSegment($path)] ?? [];
        $wild       = $bucketed['wild'] ?? [];

        if ($wild === []) {
            foreach ($candidates as $route) {
                $match = $this->test($route, $path);
                if ($match !== null) {
                    return $match;
                }
            }

            return null;
        }

        // Merge the two ordered lists on the fly (no allocation) so a wildcard
        // route declared before a bucketed one still wins, exactly as it would
        // have in a single flat scan.
        $i = 0;
        $j = 0;
        $n = count($candidates);
        $m = count($wild);

        while ($i < $n || $j < $m) {
            if ($j >= $m || ($i < $n && $candidates[$i]['ord'] <= $wild[$j]['ord'])) {
                $route = $candidates[$i++];
            } else {
                $route = $wild[$j++];
            }

            $match = $this->test($route, $path);
            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    /**
     * Test one candidate, decoding and re-validating every captured value.
     *
     * A capture that decodes into something its type forbids is treated as NO
     * MATCH rather than as an error, so a later route still gets its chance —
     * identical to how a value that never matched the pattern behaves.
     *
     * @param array<string, mixed> $route
     * @return array{entry: array<string, mixed>, params: array<string, string>}|null
     */
    private function test(array $route, string $path): ?array
    {
        if (preg_match($route['regex'], $path, $matches) !== 1) {
            return null;
        }

        $params = [];

        foreach ($route['params'] as $param) {
            $name  = is_array($param) ? $param['name'] : $param;
            $type  = is_array($param) ? ($param['type'] ?? '') : '';
            $value = $matches[$name] ?? '';

            if ($value !== '' && str_contains($value, '%')) {
                $decoded = rawurldecode($value);

                if ($decoded !== $value) {
                    // A NUL byte truncates strings in every C-backed API it later
                    // reaches (filesystem, some DB drivers) — never let one through.
                    if (str_contains($decoded, "\0")) {
                        return null;
                    }

                    if (preg_match('#^' . RouteParameter::pattern($type) . '$#D', $decoded) !== 1) {
                        return null;
                    }

                    $value = $decoded;
                }
            }

            $params[$name] = $value;
        }

        return ['entry' => $route['entry'], 'params' => $params];
    }

    /** '/users/' <-> '/users'. The root path has no alternate form. */
    private static function alternateSlash(string $path): string
    {
        if ($path === '/' || $path === '') {
            return $path;
        }

        return str_ends_with($path, '/') ? rtrim($path, '/') : $path . '/';
    }
}
