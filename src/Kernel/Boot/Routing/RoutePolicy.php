<?php declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Routing;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\BootException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Routing\RouteIndex;

/**
 * RoutePolicy — the project's authority over the routes a PLUGIN declared.
 *
 * A plugin owns and declares its routes, but the project deploying it is the
 * final authority on what is exposed. Two verbs, applied in this order:
 *
 *   allow()    keep ONLY what an allowlist names ("state my surface")
 *   disable()  drop what a blocklist names ("subtract what I do not want")
 *
 * They compose: allow a module's whole domain, then disable the handful of its
 * routes you do not want. Both run on plugin routes only, and BEFORE project
 * routes are compiled — so a project may veto a plugin route AND declare its own
 * on the freed key without tripping the duplicate-route guard.
 *
 * Neither ever touches a project's own routes. Removing those is done by not
 * declaring them.
 */
final readonly class RoutePolicy
{
    /**
     * @param list<string> $disabled proj.json routePolicy.disable / Kernel::withRoutePolicy()
     * @param list<string> $allowed  proj.json routePolicy.only / Kernel::withRouteAllowPolicy()
     */
    public function __construct(
        private array $disabled = [],
        private array $allowed = [],
    ) {}

    /**
     * Keep ONLY the plugin routes an allowlist names — "expose nothing except
     * these".
     *
     * The disable policy subtracts, which requires the project to already know a
     * route exists before it can refuse it. That is the wrong default when one
     * `hkm plugins install` publishes thirty routes nobody reviewed. This is the
     * inverse, for a project that would rather state its surface than chase it.
     *
     * An EMPTY allowlist means "no allowlist" and keeps every route. Treating it
     * as "allow nothing" would turn a project that never opted in into an empty
     * application on upgrade.
     *
     * Unmatched specs do NOT fail the boot here, unlike a disable spec. An
     * allowlist that names routes from a plugin this deployment has not enabled
     * is normal for shared/base configuration, and failing on it would make the
     * safer posture the harder one to adopt.
     *
     * @param array<string, array<string, mixed>> $routes
     * @return array<string, array<string, mixed>>
     */
    public function allow(array $routes): array
    {
        if ($this->allowed === []) {
            return $routes;
        }

        foreach ($routes as $key => $route) {
            foreach ($this->allowed as $spec) {
                if ($this->matches(trim($spec), $key, $route)) {
                    continue 2;
                }
            }

            unset($routes[$key]);
        }

        return $routes;
    }

    /**
     * Drop plugin routes the project explicitly disabled, then verify every
     * disable spec matched at least one route — an unmatched spec is a typo or a
     * stale reference and fails the boot with a descriptive message (mirrors the
     * unknown-requires-domain guard).
     *
     * @param array<string, array<string, mixed>> $routes
     * @return array<string, array<string, mixed>>
     */
    public function disable(array $routes): array
    {
        foreach ($this->disabled as $spec) {
            $spec = trim($spec);
            if ($spec === '') {
                continue;
            }

            $matched = 0;

            foreach ($routes as $key => $route) {
                if ($this->matches($spec, $key, $route)) {
                    unset($routes[$key]);
                    $matched++;
                }
            }

            if ($matched === 0) {
                throw new BootException(
                    "routePolicy.disable [{$spec}] matched no plugin route. "
                    . 'Use "METHOD /path" for a single route or a module domain to '
                    . 'disable all of its routes — check the spelling and that the '
                    . 'owning plugin is listed in withModules()/withEssentialModules().'
                );
            }
        }

        return $routes;
    }

    /**
     * Does one policy spec match one compiled route? Three forms:
     *
     *   "METHOD /path"    exact route key
     *   "METHOD /path/*"  every route under that path prefix, same method
     *   "domain"          every route whose owning module solves() that domain
     *
     * The PREFIX form is what makes a policy survive a dependency bump. Listing
     * five routes by exact key stays green when the plugin's next release adds a
     * sixth: the five still match, so the anti-typo guard is satisfied, and the
     * surface grew with nothing to announce it. A prefix keeps covering whatever
     * arrives later.
     *
     * The method is compared too, so "GET /admin/*" does not silently also cover
     * the POST that mutates. Use one spec per method when both are meant.
     *
     * @param array<string, mixed> $route
     */
    private function matches(string $spec, string $key, array $route): bool
    {
        // Domain form: no whitespace + no path part.
        if (!str_contains($spec, ' ') || !str_contains($spec, '/')) {
            return ($route['solves'] ?? null) === $spec;
        }

        // Normalize "get  /register" → "GET /register", and "get@organizer /x"
        // → "GET@organizer /x" (the domain stays lower-case; only the HTTP
        // method is upper-cased).
        [$verb, $path] = preg_split('/\s+/', $spec, 2) ?: [$spec, ''];

        if (!str_ends_with($path, '*')) {
            $parsed = RouteIndex::parseKey($verb . ' ' . $path);

            return $key === RouteIndex::key(
                $parsed['method'],
                strtolower($parsed['domain']),
                $parsed['path'],
            );
        }

        // Prefix form. Parse the wildcard away first so the method/domain are
        // normalised exactly as the exact form does, then compare paths.
        $stem   = rtrim(substr($path, 0, -1), '/');
        $parsed = RouteIndex::parseKey($verb . ' ' . ($stem === '' ? '/' : $stem));
        $actual = RouteIndex::parseKey($key);

        if ($actual['method'] !== $parsed['method']
            || strtolower($actual['domain']) !== strtolower($parsed['domain'])
        ) {
            return false;
        }

        // "/mail/demo/*" covers /mail/demo and everything beneath it, but never
        // a sibling that merely shares the prefix as a substring (/mail/demos).
        return $actual['path'] === $parsed['path']
            || str_starts_with($actual['path'], $parsed['path'] . '/');
    }
}
