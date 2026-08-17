<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Routing;

/**
 * RouteIndex — turns the flat route manifest into the shape the matcher runs on.
 *
 * ONE implementation, TWO callers:
 *   - CompileRouteManifestStage calls it at BOOT and writes the result to
 *     route-index.php, so no request ever pays for it;
 *   - RouteMatcher calls it when it is handed a legacy manifest that predates
 *     that file (an old deploy, a hand-built array in a test).
 *
 * Keeping it in one class is the same discipline that put the `{name:type}`
 * table in {@see RouteParameter}: the compiled index and the on-the-fly index
 * must be identical, or a route would behave differently depending on whether
 * the cache happened to be warm.
 *
 * SHAPE
 * -----
 *   [
 *     'static'  => ["GET /health" => entry, …],
 *     'dynamic' => ['GET' => [
 *         'buckets' => ['users' => [candidate, …]],   // keyed by first LITERAL segment
 *         'wild'    => [candidate, …],                // first segment is a placeholder
 *     ]],
 *     'methods' => ['GET', 'POST', …],
 *   ]
 *
 * where a candidate is `['ord' => int, 'regex' => string, 'params' => […], 'entry' => […]]`.
 *
 * WHY BUCKETS
 * -----------
 * Matching used to scan every dynamic pattern registered for the request's
 * method. A path can only be matched by a dynamic route whose first segment is
 * either the same literal or a placeholder, so bucketing by that segment reduces
 * the scan to the few patterns that can possibly match. `ord` preserves manifest
 * order across the bucket/wild split, so first-match-wins is unchanged.
 *
 * DOMAINS
 * -------
 * A route may be grouped under a DOMAIN — written literally, as the host or the
 * subdomain it answers on:
 *
 *   { "domain": "africavoting.local",    "routes": [ … ] }
 *   { "domain": "*.africavoting.local",  "routes": [ … ] }
 *   { "subdomain": "organizer",          "routes": [ … ] }
 *
 * The compiler GROUPS by that string verbatim — it does not resolve it, look it
 * up anywhere, or check that it is a host this deployment serves. It is part of
 * the route KEY, which is the whole point: `GET /` can exist once per domain with
 * a different handler each time. '' is the shared group every ungrouped route
 * lives in.
 *
 * Matching a request is the reverse: {@see hostCandidates()} expands the incoming
 * host into the group keys that could hold it, most specific first, and the
 * matcher tries each and then the shared group.
 */
final class RouteIndex
{
    /** Separates the HTTP method from the domain in a route key: `GET@organizer /path`. */
    public const DOMAIN_SEPARATOR = '@';

    /**
     * Build a route key. Ungrouped routes keep the historical `"METHOD /path"`
     * exactly, so every existing manifest entry and every consumer that splits on
     * the first space is unaffected.
     */
    public static function key(string $method, string $domain, string $path): string
    {
        return strtoupper($method)
            . ($domain === '' ? '' : self::DOMAIN_SEPARATOR . $domain)
            . ' ' . $path;
    }

    /**
     * Split a route key back into its parts.
     *
     * Note the shape of a grouped key: the domain rides on the METHOD segment, so
     * `explode(' ', $key, 2)` still yields a clean, leading-slash path for any
     * consumer that has not been taught about domain groups. Such a consumer sees
     * the method as `GET@organizer`, which no HTTP method matches, so it SKIPS the
     * route rather than emitting a corrupted path. Failing safe was the deciding
     * factor in choosing this format.
     *
     * @return array{method: string, domain: string, path: string}
     */
    public static function parseKey(string $key): array
    {
        [$verb, $path] = array_pad(explode(' ', $key, 2), 2, '');

        $at = strpos($verb, self::DOMAIN_SEPARATOR);

        return $at === false
            ? ['method' => $verb, 'domain' => '', 'path' => $path]
            : ['method' => substr($verb, 0, $at), 'domain' => substr($verb, $at + 1), 'path' => $path];
    }

    /**
     * The domain-group keys an incoming host could match, MOST SPECIFIC FIRST.
     *
     *   organizer.africavoting.local
     *     → organizer.africavoting.local     the exact host
     *     → *.africavoting.local             a wildcard on each parent suffix
     *     → *.local
     *     → organizer                        the bare subdomain label
     *
     * So a group may be declared as a full host, a wildcard, or just a subdomain,
     * and the most specific declaration wins. Nothing is validated: a key nothing
     * expands to simply never matches, exactly like a path nothing requests.
     *
     * @return list<string>
     */
    public static function hostCandidates(string $host): array
    {
        // Same normalisation DomainResolver applies: lower-case, no port, no
        // trailing dot, IPv6 brackets unwrapped.
        $host = strtolower(trim($host));
        $host = trim(explode(':', ltrim($host, '['), 2)[0], "].\t\n\r ");

        if ($host === '') {
            return [];
        }

        $candidates = [$host];
        $labels     = explode('.', $host);
        $count      = count($labels);

        for ($i = 1; $i < $count; $i++) {
            $candidates[] = '*.' . implode('.', array_slice($labels, $i));
        }

        // A bare label only means "subdomain" when there is one to speak of:
        // for "hkmvote.local", "hkmvote" is the site itself, not a subdomain.
        if ($count > 2) {
            $candidates[] = $labels[0];
        }

        return $candidates;
    }
    /**
     * @param array<string, array<string, mixed>> $routes flat manifest, "METHOD /path" => entry
     * @return array{static: array<string, array<string, array<string, mixed>>>, dynamic: array<string, array<string, array{buckets?: array<string, list<array<string, mixed>>>, wild?: list<array<string, mixed>>}>>, methods: list<string>, domains: list<string>}
     */
    public static function build(array $routes): array
    {
        $static  = [];
        $dynamic = [];
        $methods = [];
        $domains = [];
        $ordinal = 0;

        foreach ($routes as $key => $entry) {
            ['method' => $method, 'domain' => $domain, 'path' => $path] = self::parseKey((string) $key);

            $methods[$method] = true;
            if ($domain !== '') {
                $domains[$domain] = true;
            }

            // The inner key drops the domain — it is already the outer dimension
            // — so a lookup is $static[$domain]["GET /path"].
            $innerKey = $method . ' ' . $path;

            if (!str_contains($path, '{')) {
                $static[$domain][$innerKey] = $entry;
                continue;
            }

            $regex  = $entry['regex']  ?? null;
            $params = $entry['params'] ?? null;

            if (!is_string($regex) || !is_array($params)) {
                try {
                    $compiled = RouteParameter::compile($path);
                } catch (\InvalidArgumentException) {
                    // A path PCRE cannot represent. The boot compiler rejects
                    // these outright; here — reading a manifest compiled by an
                    // older kernel — dropping just this route preserves the old
                    // behaviour (that one endpoint 404s) instead of taking the
                    // whole pipeline down.
                    continue;
                }
                $regex  = $compiled['regex'];
                $params = $compiled['params'];
            }

            $candidate = [
                'ord'    => $ordinal++,
                'key'    => $key,
                'regex'  => $regex,
                'params' => $params,
                'entry'  => $entry,
            ];

            $segment = self::firstLiteralSegment($path);

            if ($segment === null) {
                $dynamic[$domain][$method]['wild'][] = $candidate;
            } else {
                $dynamic[$domain][$method]['buckets'][$segment][] = $candidate;
            }
        }

        return [
            'static'  => $static,
            'dynamic' => $dynamic,
            'methods' => array_keys($methods),
            'domains' => array_keys($domains),
        ];
    }

    /**
     * The first path segment when it is literal, or null when it contains a
     * placeholder (and so cannot be used as a bucket key).
     */
    public static function firstLiteralSegment(string $path): ?string
    {
        $rest  = ltrim($path, '/');
        $slash = strpos($rest, '/');
        $first = $slash === false ? $rest : substr($rest, 0, $slash);

        return str_contains($first, '{') ? null : $first;
    }

    /** The first segment of a REQUEST path — the bucket key to look up. */
    public static function requestSegment(string $path): string
    {
        $rest  = ltrim($path, '/');
        $slash = strpos($rest, '/');

        return $slash === false ? $rest : substr($rest, 0, $slash);
    }

    /**
     * Every route entry in an index, keyed by "METHOD /path" — for callers that
     * need to walk all routes (boot-time validation) without also loading the
     * flat manifest and holding a second copy of the table.
     *
     * @param array<string, mixed> $index
     * @return array<string, array<string, mixed>>
     */
    public static function entries(array $index): array
    {
        $entries = [];

        foreach (is_array($index['static'] ?? null) ? $index['static'] : [] as $domain => $table) {
            foreach ($table as $innerKey => $entry) {
                ['method' => $method, 'path' => $path] = self::parseKey((string) $innerKey);
                $entries[self::key($method, (string) $domain, $path)] = $entry;
            }
        }

        foreach (is_array($index['dynamic'] ?? null) ? $index['dynamic'] : [] as $byMethod) {
            foreach (is_array($byMethod) ? $byMethod : [] as $bucketed) {
                $lists = [...array_values($bucketed['buckets'] ?? []), $bucketed['wild'] ?? []];

                foreach ($lists as $list) {
                    foreach ($list as $candidate) {
                        $entries[$candidate['key'] ?? ''] = $candidate['entry'] ?? [];
                    }
                }
            }
        }

        unset($entries['']);

        return $entries;
    }

    /**
     * name => {path, method, domain} — everything UrlGenerator needs, nothing else.
     *
     * Names stay a FLAT, application-wide namespace even with domain groups: two
     * domains that both want a route called `home` must name them `vote.home` and
     * `africa.home` (a group's `name` prefix makes that one declaration). The
     * alternative — per-domain names — would force UrlGenerator to know which
     * domain it is generating for, and it deliberately holds no request state so
     * that CLI commands and queue workers can build links at all.
     *
     * @param array<string, array<string, mixed>> $routes
     * @return array<string, array{path: string, method: string, domain: string}>
     */
    public static function names(array $routes): array
    {
        $names = [];

        foreach ($routes as $key => $entry) {
            $name = $entry['name'] ?? null;
            if (!is_string($name) || $name === '') {
                continue;
            }
            ['method' => $method, 'domain' => $domain, 'path' => $path] = self::parseKey((string) $key);
            $names[$name] = ['path' => $path, 'method' => $method, 'domain' => $domain];
        }

        return $names;
    }
}