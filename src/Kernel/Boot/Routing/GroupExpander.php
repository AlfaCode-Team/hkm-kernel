<?php declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Routing;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\BootException;

/**
 * GroupExpander — turns a nested `groups[]` declaration into flat routes.
 *
 * A group exists to say a thing ONCE that would otherwise be repeated on every
 * route inside it. The whole expansion happens at BOOT — the manifest, the
 * matcher and every request-time stage only ever see flat routes, so grouping
 * costs exactly nothing at runtime.
 *
 * This is the largest single responsibility the route compiler had, and the one
 * with real recursion in it, which is why it is now its own unit: the
 * inheritance rules below can be tested against a declaration without compiling,
 * writing or validating a manifest.
 */
final readonly class GroupExpander
{
    /** Guard against a self-referencing groups[] structure. */
    private const MAX_GROUP_DEPTH = 16;

    public function __construct(private DomainComposer $domains) {}

    /**
     * Flatten a route declaration source — a module.json, a proj.json, or the
     * array passed to Kernel::withRoutes() — into a plain list of fully-resolved
     * routes.
     *
     * A source may declare routes directly, and/or nest them in `groups[]`, which
     * may nest further:
     *
     *   "routePrefix":  "/api",              // source-wide defaults
     *   "routeFilters": ["auth"],
     *   "routeRequires":["view.rendering"],
     *   "routeDomain":  "africavoting.local",
     *   "groups": [
     *     { "prefix": "/admin", "filters": ["throttle:30,1"], "name": "admin.",
     *       "subdomain": "admin", "routes": [ … ], "groups": [ … ] }
     *   ]
     *
     * INHERITANCE
     *   prefix    concatenated outward-in
     *   name      concatenated outward-in, prefixed onto each route's own name
     *   filters   merged, de-duplicated BY ALIAS — inner wins, so a group's
     *             "throttle:60,1" is replaced (not doubled) by a route's "throttle:5,1"
     *   requires  union
     *   domain    inner overrides outer (written literally — a host,
     *             "*.wildcard", or a bare subdomain; grouped VERBATIM)
     *   faces     inner overrides outer when non-empty
     *
     * Nothing SUBTRACTS. A group cannot strip a filter an outer group added:
     * removal is the project's prerogative and lives in routePolicy.disable, which
     * is the single place authorised to veto.
     *
     * Each route carries `decl`: where in the source it was DECLARED. A domain
     * list fans one declaration out into one route per host, and every copy
     * shares that `decl`, which is how the name guard tells a fan-out (one name,
     * one path, several hosts) from two declarations colliding on a name.
     *
     * @param array<string, mixed> $source
     * @return list<array{method: string, path: string, handler: string, name: ?string, filters: list<string>, requires: list<string>, domain: string, faces: list<string>, decl: string}>
     */
    public function flatten(array $source, string $owner): array
    {
        $scope = [
            'prefix'   => RouteNormalizer::prefix($source['routePrefix'] ?? '', $owner),
            'name'     => RouteNormalizer::stringOrEmpty($source['routeName'] ?? '', $owner, 'routeName'),
            'filters'  => RouteNormalizer::filters($source['routeFilters'] ?? [], $owner),
            'requires' => RouteNormalizer::requires($source['routeRequires'] ?? []),
            'domain'   => '',
            'faces'    => RouteNormalizer::faces($source['routeFaces'] ?? []),
        ];

        // The module-wide domain takes a list too, so the three levels that can
        // name a host — module-wide, group, route — all behave the same way.
        // One of them quietly refusing a list is the kind of inconsistency that
        // is only ever discovered by it not working.
        $flat = [];
        foreach ($this->domains->domainsFor($source, $scope, $owner, 'routeDomain', 'routeSubdomain') as $domain) {
            $scope['domain'] = $domain;
            $flat = [...$flat, ...$this->expand($source, $scope, $owner, 0, $owner)];
        }

        return $flat;
    }

    /**
     * @param array<string, mixed> $source
     * @param array{prefix: string, name: string, filters: list<string>, requires: list<string>, domain: string, faces: list<string>} $inherited
     * @return list<array<string, mixed>>
     */
    private function expand(array $source, array $inherited, string $owner, int $depth, string $at): array
    {
        if ($depth > self::MAX_GROUP_DEPTH) {
            throw new BootException(
                "Route groups in {$owner} nest more than " . self::MAX_GROUP_DEPTH . ' levels deep. '
                . 'That is almost always a self-referencing structure rather than an intended hierarchy.'
            );
        }

        $flat = [];

        foreach ($source['routes'] ?? [] as $index => $route) {
            if (!is_array($route) || !isset($route['method'], $route['path'], $route['handler'])) {
                throw new BootException(
                    "Invalid route in {$owner} - each route needs method, path and handler."
                );
            }

            $path = RouteNormalizer::path(
                $inherited['prefix'] . (string) $route['path'],
                "Route in {$owner}",
            );

            $name = RouteNormalizer::stringOrEmpty($route['name'] ?? '', $owner, 'name');

            $entry = [
                'method'   => strtoupper(trim((string) $route['method'])),
                'path'     => $path,
                'handler'  => (string) $route['handler'],
                // An unnamed route stays unnamed: a group's name prefix labels
                // routes that opted into a name, it does not invent names.
                'name'     => $name === '' ? null : $inherited['name'] . $name,
                'filters'  => RouteNormalizer::mergeFilters(
                    $inherited['filters'],
                    RouteNormalizer::filters($route['filters'] ?? [], "Route in {$owner}"),
                ),
                'requires' => RouteNormalizer::mergeRequires(
                    $inherited['requires'],
                    RouteNormalizer::requires($route['requires'] ?? []),
                ),
                'domain'   => $inherited['domain'],
                'faces'    => RouteNormalizer::faces($route['faces'] ?? []) ?: $inherited['faces'],
                'decl'     => "{$at} routes[{$index}]",
            ];

            // One flat route per domain. A single string yields one, exactly as
            // before; a list yields one copy per host, each with its own route
            // key, which is what makes them independently overridable.
            //
            // A NAMED route keeps its name on every copy. That is safe because
            // the copies differ ONLY in host — same method, same path — so the
            // name still means exactly one URL path, and a relative route() is
            // right on all of them. See RouteCompiler::claimName().
            foreach ($this->domains->domainsFor($route, $inherited, "Route in {$owner}") as $domain) {
                $entry['domain'] = $domain;
                $flat[] = $entry;
            }
        }

        foreach ($source['groups'] ?? [] as $index => $group) {
            if (!is_array($group)) {
                throw new BootException("Invalid route group in {$owner} - a group must be an object.");
            }

            $context = "Route group in {$owner}";

            $scope = [
                'prefix'   => $inherited['prefix']
                    . RouteNormalizer::prefix($group['prefix'] ?? '', $context),
                'name'     => $inherited['name']
                    . RouteNormalizer::stringOrEmpty($group['name'] ?? '', $owner, 'group name'),
                'filters'  => RouteNormalizer::mergeFilters(
                    $inherited['filters'],
                    RouteNormalizer::filters($group['filters'] ?? [], $context),
                ),
                'requires' => RouteNormalizer::mergeRequires(
                    $inherited['requires'],
                    RouteNormalizer::requires($group['requires'] ?? []),
                ),
                'domain'   => $inherited['domain'],
                'faces'    => RouteNormalizer::faces($group['faces'] ?? []) ?: $inherited['faces'],
            ];

            // Expand the WHOLE subtree once per domain. Nested groups and routes
            // inherit the one host they are being expanded for, so a list at any
            // level composes with a list at any other. `$at` does not change per
            // host: each copy of the subtree is the SAME declaration.
            foreach ($this->domains->domainsFor($group, $inherited, $context) as $domain) {
                $scope['domain'] = $domain;
                $flat = [...$flat, ...$this->expand($group, $scope, $owner, $depth + 1, "{$at} groups[{$index}]")];
            }
        }

        return $flat;
    }
}
