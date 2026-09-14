<?php declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Routing;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\BootException;

/**
 * RouteNormalizer — the value-level primitives the route compiler is built from.
 *
 * Every method here takes one declared value out of a module.json / proj.json and
 * returns it in the one shape the rest of the compiler is allowed to assume:
 * paths are absolute, prefixes have no trailing slash, filters are a list of
 * trimmed specs, requires and faces are lists of non-empty strings.
 *
 * They are STATIC because they are pure — no configuration, no state, nothing to
 * inject. That is also what makes them the natural first extraction from
 * {@see \AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Stages\CompileRouteManifestStage}:
 * they can be tested one value at a time, without compiling a manifest.
 *
 * THE RULE THEY ALL FOLLOW: a malformed declaration FAILS THE BOOT rather than
 * being coerced. Prepending a missing '/' would silently publish an endpoint the
 * author believed was already live; casting an array to a string produced the
 * literal filter alias "Array", which then failed at request time on a page
 * nobody had visited yet. Both were real, and both are boot failures now.
 */
final class RouteNormalizer
{
    /**
     * A route path must be absolute. `Request::path()` always starts with '/', so
     * a path declared as "users" compiles to the key "GET users" and can never be
     * matched — an invisible dead endpoint.
     */
    public static function path(string $path, string $context): string
    {
        if ($path === '' || $path[0] !== '/') {
            throw new BootException(
                "{$context} declares path [{$path}] which does not start with '/'. "
                . 'Request paths are always absolute, so this route could never match.'
            );
        }

        return $path;
    }

    /** A module-wide route prefix: '' or an absolute path with no trailing slash. */
    public static function prefix(mixed $prefix, string $context): string
    {
        if ($prefix === null || $prefix === '' || $prefix === false) {
            return '';
        }

        if (!is_string($prefix)) {
            throw new BootException("{$context} declares a non-string routePrefix.");
        }

        $prefix = rtrim(trim($prefix), '/');

        if ($prefix === '') {
            return '';
        }

        if ($prefix[0] !== '/') {
            throw new BootException(
                "{$context} declares routePrefix [{$prefix}] which does not start with '/'."
            );
        }

        return $prefix;
    }

    /**
     * Normalize a route's declared filters to a clean list of string specs.
     * Accepts a single string ("auth") or a list (["auth", "throttle:60"]).
     *
     * @return list<string>
     */
    public static function filters(mixed $filters, string $context = 'A route'): array
    {
        if (is_string($filters)) {
            $filters = [$filters];
        }
        if ($filters === null || $filters === []) {
            return [];
        }
        if (!is_array($filters)) {
            throw new BootException(
                "{$context} declares filters that are neither a string nor a list."
            );
        }

        $normalized = [];
        foreach ($filters as $filter) {
            if (!is_string($filter) && !is_int($filter) && !is_float($filter)) {
                // Previously this hit "(string) $array" and produced the literal
                // filter alias "Array", which then failed at request time.
                throw new BootException(
                    "{$context} declares a filter that is not a string — filters are "
                    . 'aliases like "auth" or "throttle:60,1".'
                );
            }
            $filter = trim((string) $filter);
            if ($filter !== '') {
                $normalized[] = $filter;
            }
        }

        return $normalized;
    }

    /**
     * Module defaults first, then the route's own, de-duplicated by ALIAS so a
     * route can re-declare "throttle:5,1" to override the module's "throttle:60,1"
     * rather than running the stage twice.
     *
     * @param list<string> $defaults
     * @param list<string> $own
     * @return list<string>
     */
    public static function mergeFilters(array $defaults, array $own): array
    {
        if ($defaults === []) {
            return $own;
        }

        $ownAliases = [];
        foreach ($own as $spec) {
            $ownAliases[self::parseFilterSpec($spec)['alias']] = true;
        }

        $merged = [];
        foreach ($defaults as $spec) {
            if (!isset($ownAliases[self::parseFilterSpec($spec)['alias']])) {
                $merged[] = $spec;
            }
        }

        return [...$merged, ...$own];
    }

    /**
     * "throttle:60,1" => ['alias' => 'throttle', 'args' => ['60', '1']]
     *
     * Shared with RouteFilterStage, which used to run this on every request for
     * every filter on the matched route.
     *
     * @return array{alias: string, args: list<string>}
     */
    public static function parseFilterSpec(string $spec): array
    {
        $spec = trim($spec);

        if (!str_contains($spec, ':')) {
            return ['alias' => $spec, 'args' => []];
        }

        [$alias, $rawArgs] = explode(':', $spec, 2);

        return [
            'alias' => trim($alias),
            'args'  => array_values(array_filter(
                array_map('trim', explode(',', $rawArgs)),
                static fn(string $a): bool => $a !== '',
            )),
        ];
    }

    /**
     * Optional face restriction — the project's DomainType values ('admin', 'api',
     * …). Empty means "every face", which is what every existing route gets, so
     * this is inert until a route opts in. The kernel stays domain-agnostic: it
     * compares against the plain `route_face` request attribute and never imports
     * the project's DomainContext.
     *
     * @return list<string>
     */
    public static function faces(mixed $faces): array
    {
        if (is_string($faces)) {
            $faces = [$faces];
        }
        if (!is_array($faces)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn($f): string => strtolower(trim((string) $f)), $faces),
            static fn(string $f): bool => $f !== '',
        ));
    }

    /**
     * Normalize a route's declared module requires to a clean list of domain
     * strings. Accepts a single string ("view.rendering") or a list.
     *
     * @return list<string>
     */
    public static function requires(mixed $requires): array
    {
        if (is_string($requires)) {
            $requires = [$requires];
        }
        if (!is_array($requires)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn($r): string => trim((string) $r), $requires),
            static fn(string $r): bool => $r !== '',
        ));
    }

    /**
     * @param list<string> $inherited
     * @param list<string> $own
     * @return list<string>
     */
    public static function mergeRequires(array $inherited, array $own): array
    {
        if ($inherited === []) {
            return $own;
        }

        return array_values(array_unique([...$inherited, ...$own]));
    }

    public static function stringOrEmpty(mixed $value, string $owner, string $what): string
    {
        if ($value === null || $value === false || $value === '') {
            return '';
        }

        if (!is_string($value)) {
            throw new BootException("{$owner} declares a non-string {$what}.");
        }

        return $value;
    }
}
