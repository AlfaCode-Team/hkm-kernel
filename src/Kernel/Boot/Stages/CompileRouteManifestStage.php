<?php declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\{BootException, ManifestReader, ManifestWriter};
use AlfacodeTeam\PhpServicePlatform\Kernel\Routing\{RouteIndex, RouteParameter};

/**
 * Reads routes[] from every module.json -> route-manifest.php (OPcache-cached).
 *
 * THREE ARTEFACTS, ONE COMPILATION
 * --------------------------------
 *   route-manifest.php  the canonical flat map, `"METHOD /path" => entry`. Its
 *                       shape is PUBLIC (RouteCatalog, tooling and tests read
 *                       it), so it only ever gains keys, never changes shape.
 *   route-index.php     the matcher's ready-to-use index: static table, per-method
 *                       first-segment buckets, and a precompiled anchored regex +
 *                       parameter list per dynamic route. Everything RouteMatcher
 *                       used to derive on every worker's first request.
 *   route-names.php     name => {path, method}. Lets UrlGenerator build URLs
 *                       without loading the whole route table — the difference
 *                       between a worker that mints one email link and one that
 *                       holds the entire routing surface in memory.
 *
 * Both derived files are OPTIONAL at runtime: every consumer falls back to
 * deriving from route-manifest.php, so a stale deploy that predates them still
 * boots and serves.
 */
final class CompileRouteManifestStage implements BootStageContract
{
    /** Synthetic scope for project-layer routes (no owning module). */
    public const PROJECT_SCOPE = '__project__';

    /** Guard against a self-referencing groups[] structure. */
    private const MAX_GROUP_DEPTH = 16;

    /**
     * @param list<class-string> $moduleClasses
     * @param list<array{method: string, path: string, handler: string}> $projectRoutes
     * @param list<string> $disabledRoutes
     *   Project route policy (proj.json "routePolicy.disable" / Kernel::withRoutePolicy).
     *   Each entry is EITHER a "METHOD /path" spec (drops that one plugin route) OR a
     *   bare module domain (drops EVERY plugin route that module solves()). Applied
     *   AFTER plugin routes and BEFORE project routes, so a project can veto a plugin
     *   route and then optionally re-declare its own on the freed key. A spec that
     *   matches nothing fails the boot — no silent typos.
     */
    public function __construct(
        private readonly array $moduleClasses,
        private readonly array $projectRoutes = [],
        private readonly array $disabledRoutes = [],
        private readonly ManifestReader $reader = new ManifestReader(),
        private readonly array $projectGroups = [],
        /**
         * Hosts this project serves — proj.json "domains", via
         * Kernel::withProjectDomains(). A route grouped under a host that is not
         * in here could never be reached, so it fails the boot. Empty (a project
         * that registers no domains) disables the check entirely.
         *
         * @var list<string>
         */
        private readonly array $projectDomains = [],
    ) {}

    public function run(): void
    {
        $routes = [];

        /**
         * Declared route names => the route key that claimed them. Names must be
         * unique across the whole application (they are a flat namespace, like
         * filter aliases), so a collision is a BOOT failure rather than a
         * last-one-wins surprise at URL-generation time.
         *
         * @var array<string, string>
         */
        $names = [];

        // PASS 1 — read every module manifest once and collect the full set of
        // domains some module solves(). Building this BEFORE compiling any route
        // means a route's requires[] can name a domain declared by a module that
        // appears later in the list (order-independent validation).
        $manifests    = [];
        $knownDomains = [];
        foreach ($this->moduleClasses as $moduleClass) {
            $manifest               = $this->reader->read($moduleClass);
            $manifests[$moduleClass] = $manifest;
            $knownDomains[$manifest['solves']] = true;
        }

        // PASS 2a — plugin routes (from module.json). A plugin route normally
        // gets its deps via its module's solves graph, but it MAY also declare
        // route-level requires[] (validated + honoured by LoadStage), kept
        // consistent with project routes.
        foreach ($this->moduleClasses as $moduleClass) {
            $manifest = $manifests[$moduleClass];

            // Module-wide route defaults. "routePrefix" is prepended to every
            // path the module declares and "routeFilters" is merged in FRONT of
            // each route's own filters[], so an admin plugin declares `auth`
            // once instead of on all forty routes. Both are optional and absent
            // by default, so existing module.json files compile identically.
            foreach ($this->flatten($manifest, "[{$moduleClass}]") as $route) {
                $path = $route['path'];
                $key  = RouteIndex::key($route['method'], $route['domain'], $path);

                if (isset($routes[$key])) {
                    throw new BootException(
                        "Duplicate route [{$key}] declared by [{$moduleClass}] and [{$routes[$key]['module']}]."
                    );
                }

                $filters  = $route['filters'];
                $requires = $this->validateRequires(
                    $route['requires'],
                    $knownDomains,
                    "Route [{$key}] in [{$moduleClass}]",
                );

                $routes[$key] = [
                    'handler' => $route['handler'],
                    'module' => $moduleClass,
                    'solves' => $manifest['solves'],
                    'name' => $this->routeName($route, $key, $names, "[{$moduleClass}]"),
                    'filters' => $filters,
                    'requires' => $requires,
                    'faces' => $route['faces'],
                    'domain' => $route['domain'],
                ] + $this->precompile($route['handler'], $path, $manifest['solves'], $filters, "Route [{$key}] in [{$moduleClass}]");

                // requires[] is validated above but precompile() ran before it was
                // stored; recompute the graph key now that the final list is known.
                $routes[$key]['graph_key'] = $manifest['solves'] . '|' . implode(',', $requires);
            }
        }

        // PASS 2a.5 — apply the project's route DISABLE policy. Runs on plugin
        // routes only (project routes are compiled below and are the project's own
        // to add/remove). Dropping BEFORE project routes frees the "METHOD path"
        // key so a project may disable a plugin route AND declare its own on it
        // without a duplicate-route boot failure.
        $routes = $this->applyDisablePolicy($routes);

        // A disabled plugin route releases its name. Otherwise a project that
        // vetoes GET /register and declares its own named 'auth.register' would
        // collide with the very route it just removed.
        foreach ($names as $name => $owningKey) {
            if (!isset($routes[$owningKey])) {
                unset($names[$name]);
            }
        }

        // PASS 2b — project-layer routes (Kernel::withRoutes / proj.json), not in
        // any module.json. They carry no module and resolve under the synthetic
        // PROJECT_SCOPE, whose dependency graph is empty — so route-level
        // requires[] is the ONLY way a project page pulls in a plugin.
        // withRoutes() routes and any routes[] declared alongside the groups are
        // BOTH the project's, so they concatenate. A `+` union here would have
        // let one silently drop the other — array union keeps the LEFT key, so a
        // routes[] passed to withRouteGroups() would have vanished without a word.
        $projectSource = $this->projectGroups;
        $projectSource['routes'] = [
            ...$this->projectRoutes,
            ...(is_array($projectSource['routes'] ?? null) ? $projectSource['routes'] : []),
        ];

        foreach ($this->flatten($projectSource, 'the project') as $route) {
            // DETERMINISTIC PRIORITY: project routes are compiled AFTER every
            // plugin route and OVERRIDE a plugin route declaring the same
            // "METHOD path". This is the default project-over-plugin precedence —
            // never the reverse. Plugins cannot reclaim a route the project owns.
            $path = $route['path'];

            $key = RouteIndex::key($route['method'], $route['domain'], $path);

            // A project override INHERITS the overridden plugin route's name
            // unless it declares its own. Overriding changes where a name points,
            // not whether it exists — otherwise every route('user.show') in a
            // plugin's own views would break the moment a project customised that
            // page, which is the single most common thing a project does.
            $inherited = $routes[$key]['name'] ?? null;
            $declared  = $this->routeName($route, $key, $names, 'the project');

            if ($declared === null && $inherited !== null) {
                // Already claimed by the plugin route being replaced — the name
                // survives, still pointing at exactly one route.
                $declared = $inherited;
            }

            $filters  = $route['filters'];
            $requires = $this->validateRequires($route['requires'], $knownDomains, "Project route [{$key}]");

            $routes[$key] = [
                'handler' => $route['handler'],
                'module' => null,
                'solves' => self::PROJECT_SCOPE,
                'name' => $declared,
                'overrides' => $routes[$key]['module'] ?? null,
                'filters' => $filters,
                // Per-route module dependencies seeded into this request's graph
                // by LoadStage. Each must name a real module domain — fail at boot.
                'requires' => $requires,
                'faces' => $route['faces'],
                'domain' => $route['domain'],
            ] + $this->precompile($route['handler'], $path, self::PROJECT_SCOPE, $filters, "Project route [{$key}]");

            $routes[$key]['graph_key'] = self::PROJECT_SCOPE . '|' . implode(',', $requires);
        }

        ManifestWriter::write('route-manifest.php', $routes);
        ManifestWriter::write('route-index.php', RouteIndex::build($routes));
        ManifestWriter::write('route-names.php', RouteIndex::names($routes));
    }

    // ── Groups ───────────────────────────────────────────────────────────────

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
     * A group exists to say a thing ONCE that would otherwise be repeated on every
     * route inside it. The whole expansion happens here, at boot — the manifest,
     * the matcher and every request-time stage only ever see flat routes, so
     * grouping costs exactly nothing at runtime.
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
     * @param array<string, mixed> $source
     * @return list<array{method: string, path: string, handler: string, name: ?string, filters: list<string>, requires: list<string>, domain: string, faces: list<string>}>
     */
    private function flatten(array $source, string $owner): array
    {
        return $this->flattenInto($source, [
            'prefix'   => $this->normalizePrefix($source['routePrefix'] ?? '', $owner),
            'name'     => $this->stringOrEmpty($source['routeName'] ?? '', $owner, 'routeName'),
            'filters'  => $this->normalizeFilters($source['routeFilters'] ?? [], $owner),
            'requires' => $this->normalizeRequires($source['routeRequires'] ?? []),
            'domain'   => $this->checkedDomain(
                $this->normalizeDomain($source['routeDomain'] ?? $source['routeSubdomain'] ?? ''),
                $owner,
            ),
            'faces'    => $this->normalizeFaces($source['routeFaces'] ?? []),
        ], $owner, 0);
    }

    /**
     * @param array<string, mixed> $source
     * @param array{prefix: string, name: string, filters: list<string>, requires: list<string>, domain: string, faces: list<string>} $inherited
     * @return list<array<string, mixed>>
     */
    private function flattenInto(array $source, array $inherited, string $owner, int $depth): array
    {
        if ($depth > self::MAX_GROUP_DEPTH) {
            throw new BootException(
                "Route groups in {$owner} nest more than " . self::MAX_GROUP_DEPTH . ' levels deep. '
                . 'That is almost always a self-referencing structure rather than an intended hierarchy.'
            );
        }

        $flat = [];

        foreach ($source['routes'] ?? [] as $route) {
            if (!is_array($route) || !isset($route['method'], $route['path'], $route['handler'])) {
                throw new BootException(
                    "Invalid route in {$owner} - each route needs method, path and handler."
                );
            }

            $path = $this->normalizePath(
                $inherited['prefix'] . (string) $route['path'],
                "Route in {$owner}",
            );

            $name = $this->stringOrEmpty($route['name'] ?? '', $owner, 'name');

            $flat[] = [
                'method'   => strtoupper(trim((string) $route['method'])),
                'path'     => $path,
                'handler'  => (string) $route['handler'],
                // An unnamed route stays unnamed: a group's name prefix labels
                // routes that opted into a name, it does not invent names.
                'name'     => $name === '' ? null : $inherited['name'] . $name,
                'filters'  => $this->mergeFilters(
                    $inherited['filters'],
                    $this->normalizeFilters($route['filters'] ?? [], "Route in {$owner}"),
                ),
                'requires' => $this->mergeRequires(
                    $inherited['requires'],
                    $this->normalizeRequires($route['requires'] ?? []),
                ),
                'domain'   => isset($route['domain']) || isset($route['subdomain'])
                    ? $this->checkedDomain(
                        $this->normalizeDomain($route['domain'] ?? $route['subdomain']),
                        "Route in {$owner}",
                    )
                    : $inherited['domain'],
                'faces'    => $this->normalizeFaces($route['faces'] ?? []) ?: $inherited['faces'],
            ];
        }

        foreach ($source['groups'] ?? [] as $group) {
            if (!is_array($group)) {
                throw new BootException("Invalid route group in {$owner} - a group must be an object.");
            }

            $flat = [...$flat, ...$this->flattenInto($group, [
                'prefix'   => $inherited['prefix']
                    . $this->normalizePrefix($group['prefix'] ?? '', "Route group in {$owner}"),
                'name'     => $inherited['name']
                    . $this->stringOrEmpty($group['name'] ?? '', $owner, 'group name'),
                'filters'  => $this->mergeFilters(
                    $inherited['filters'],
                    $this->normalizeFilters($group['filters'] ?? [], "Route group in {$owner}"),
                ),
                'requires' => $this->mergeRequires(
                    $inherited['requires'],
                    $this->normalizeRequires($group['requires'] ?? []),
                ),
                'domain'   => isset($group['domain']) || isset($group['subdomain'])
                    ? $this->checkedDomain(
                        $this->normalizeDomain($group['domain'] ?? $group['subdomain']),
                        "Route group in {$owner}",
                    )
                    : $inherited['domain'],
                'faces'    => $this->normalizeFaces($group['faces'] ?? []) ?: $inherited['faces'],
            ], $owner, $depth + 1)];
        }

        return $flat;
    }

    /**
     * The DOMAIN a group of routes answers on, taken verbatim.
     *
     *   "domain":    "africavoting.local"     a host
     *   "domain":    "*.africavoting.local"   a wildcard
     *   "subdomain": "organizer"              a bare label
     *
     * This GROUPS — it does not verify. The compiler does not resolve the string,
     * look it up in any registry, or check that this deployment serves it: a
     * domain nothing requests simply never matches, exactly like a path nothing
     * requests. Only case and surrounding whitespace are normalised, plus the two
     * characters that would break the route key round-trip ({@see
     * RouteIndex::parseKey}) — a space and an '@', neither of which occurs in a
     * hostname.
     */
    private function normalizeDomain(mixed $domain): string
    {
        if (!is_string($domain)) {
            return '';
        }

        $domain = strtolower(trim($domain));

        return str_contains($domain, ' ') || str_contains($domain, RouteIndex::DOMAIN_SEPARATOR)
            ? ''
            : $domain;
    }

    /** Validate a normalised domain and return it, so it composes in an expression. */
    private function checkedDomain(string $domain, string $context): string
    {
        $this->validateDomain($domain, $context);

        return $domain;
    }

    /**
     * Check that a declared HOST is one this project actually serves.
     *
     * The registry is the project's own `proj.json` "domains" — the same list
     * DomainResolver matches an incoming Host against to build a DomainContext.
     * Grouping routes under a host the project never registered produces routes
     * that can never be reached: the request would have been routed to a
     * different project, or refused, long before the router saw it. That is the
     * same silent-404 class as an unknown `{name:type}` or a dead disable spec,
     * so it fails the boot with the list of hosts that WOULD have worked.
     *
     * TWO THINGS ARE DELIBERATELY NOT CHECKED:
     *
     *   - A BARE SUBDOMAIN (`"subdomain": "api"`, anything with no dot). It is
     *     domain-agnostic ON PURPOSE — it answers on api.example.com AND
     *     api.example2.com AND any future host with that first label — so there
     *     is no single registered host to check it against.
     *   - Anything at all when proj.json declares no "domains". A project that
     *     does not register its hosts has no registry to validate against, and
     *     inventing one is exactly the indirection this design avoids.
     *
     * A WILDCARD (`*.africavoting.local`) passes when the parent is registered or
     * when any registered host falls under it — which is what makes it the right
     * tool for tenant hosts that are added to the database, not to proj.json.
     */
    private function validateDomain(string $domain, string $context): void
    {
        // No dot ⇒ a bare subdomain label, which spans every domain by design.
        if ($domain === '' || $this->projectDomains === [] || !str_contains($domain, '.')) {
            return;
        }

        $wildcard = str_starts_with($domain, '*.');
        $suffix   = $wildcard ? substr($domain, 2) : '';

        foreach ($this->projectDomains as $host) {
            $host = strtolower(trim((string) $host));

            if ($wildcard
                ? ($host === $suffix || str_ends_with($host, '.' . $suffix))
                : $host === $domain) {
                return;
            }
        }

        throw new BootException(sprintf(
            '%s groups routes under domain [%s], which this project does not serve. '
            . 'Registered domains (proj.json "domains"): %s. Add it there, use a wildcard '
            . 'like [*.%s], or declare a bare "subdomain" if the routes should answer on '
            . 'every domain.',
            $context,
            $domain,
            implode(', ', $this->projectDomains),
            ltrim(strstr($domain, '.') ?: $domain, '.'),
        ));
    }

    private function stringOrEmpty(mixed $value, string $owner, string $what): string
    {
        if ($value === null || $value === false || $value === '') {
            return '';
        }

        if (!is_string($value)) {
            throw new BootException("{$owner} declares a non-string {$what}.");
        }

        return $value;
    }

    /**
     * @param list<string> $inherited
     * @param list<string> $own
     * @return list<string>
     */
    private function mergeRequires(array $inherited, array $own): array
    {
        if ($inherited === []) {
            return $own;
        }

        return array_values(array_unique([...$inherited, ...$own]));
    }

    // ── Precompilation ───────────────────────────────────────────────────────

    /**
     * Everything about a route that is constant, computed once at boot so no
     * request has to derive it: the handler split, the parsed filter specs, the
     * dependency-graph cache key, and (for a dynamic path) the anchored regex.
     *
     * @param list<string> $filters
     * @return array<string, mixed>
     */
    private function precompile(string $handler, string $path, string $solves, array $filters, string $context): array
    {
        if (substr_count($handler, '@') !== 1) {
            throw new BootException(
                "{$context} has handler [{$handler}] — it must be in 'Controller@method' format "
                . '(exactly one "@").'
            );
        }

        [$class, $action] = explode('@', $handler, 2);

        if ($class === '' || $action === '') {
            throw new BootException(
                "{$context} has handler [{$handler}] — both the controller class and the method are required."
            );
        }

        $compiled = ['class' => $class, 'action' => $action];

        $this->verifyHandler($class, $action, $handler, $context);

        $specs = [];
        foreach ($filters as $spec) {
            $specs[] = self::parseFilterSpec($spec);
        }
        $compiled['filter_specs'] = $specs;
        $compiled['graph_key']    = $solves . '|';

        if (str_contains($path, '{')) {
            $this->validateParameterTypes($path, $context);

            try {
                $route = RouteParameter::compile($path);
            } catch (\InvalidArgumentException $e) {
                // A pattern that PCRE refuses compiles to a route which makes
                // preg_match() return false on EVERY request — a permanent silent
                // 404 that reads as a missing controller. Same anti-typo policy as
                // unknown types, unknown requires[] domains and dead disable specs.
                throw new BootException("{$context}: " . $e->getMessage(), previous: $e);
            }

            $compiled['regex']  = $route['regex'];
            $compiled['params'] = $route['params'];
        }

        return $compiled;
    }

    /**
     * OPT-IN: check that the handler class and method actually exist.
     *
     * Off by default because it forces the autoloader to load every controller in
     * the application at boot — real cost on a cold FPM process, and pointless in
     * production where the routes demonstrably worked when the build was cut.
     * Turn it on in development and CI (`ROUTE_VERIFY_HANDLERS=1`) and a renamed
     * action fails the build with the route that references it, instead of 500ing
     * the first time someone visits that page.
     */
    private function verifyHandler(string $class, string $action, string $handler, string $context): void
    {
        static $enabled = null;

        $enabled ??= \function_exists('env')
            && filter_var(env('ROUTE_VERIFY_HANDLERS', false), FILTER_VALIDATE_BOOL);

        if ($enabled !== true) {
            return;
        }

        if (!class_exists($class)) {
            throw new BootException("{$context} references controller [{$class}], which does not exist.");
        }

        if (!method_exists($class, $action)) {
            throw new BootException(
                "{$context} references [{$handler}], but [{$class}] has no method [{$action}]."
            );
        }

        if (!(new \ReflectionMethod($class, $action))->isPublic()) {
            throw new BootException(
                "{$context} references [{$handler}], but [{$action}] is not public — "
                . 'the pipeline can only invoke public controller actions.'
            );
        }
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

    // ── Validation ───────────────────────────────────────────────────────────

    /**
     * Ensure every declared dependency names a domain some module solves(),
     * failing fast at boot with a descriptive message instead of a request-time
     * 500. Returns the list unchanged on success.
     *
     * @param list<string>         $requires
     * @param array<string, true>  $knownDomains
     * @return list<string>
     */
    private function validateRequires(array $requires, array $knownDomains, string $context): array
    {
        foreach ($requires as $dep) {
            if (!isset($knownDomains[$dep])) {
                throw new BootException(
                    "{$context} requires unknown module domain [{$dep}]. "
                    . 'No registered module solves it — check the spelling and that the '
                    . 'plugin is listed in withModules()/withEssentialModules().'
                );
            }
        }

        return $requires;
    }

    /**
     * Drop plugin routes the project explicitly disabled, then verify every
     * disable spec matched at least one route — an unmatched spec is a typo or a
     * stale reference and fails the boot with a descriptive message (mirrors the
     * unknown-requires-domain guard). Two spec forms, distinguished by shape:
     *   - "METHOD /path"  → contains whitespace AND a "/path" part → exact route key
     *   - "domain"        → anything else → every plugin route whose solves() matches
     *
     * @param array<string, array{module: ?class-string, solves: string, ...}> $routes
     * @return array<string, array<string, mixed>>
     */
    private function applyDisablePolicy(array $routes): array
    {
        foreach ($this->disabledRoutes as $spec) {
            $spec = trim($spec);
            if ($spec === '') {
                continue;
            }

            $isRouteKey = str_contains($spec, ' ') && str_contains($spec, '/');
            $matched    = 0;

            if ($isRouteKey) {
                // Normalize "get  /register" → "GET /register", and
                // "get@organizer /x" → "GET@organizer /x" (the domain stays
                // lower-case — only the HTTP method is upper-cased).
                [$verb, $path] = preg_split('/\s+/', $spec, 2) ?: [$spec, ''];
                $parsed = RouteIndex::parseKey($verb . ' ' . $path);
                $key    = RouteIndex::key($parsed['method'], strtolower($parsed['domain']), $parsed['path']);

                if (isset($routes[$key])) {
                    unset($routes[$key]);
                    $matched = 1;
                }
            } else {
                // Domain form — drop every plugin route that module solves().
                foreach ($routes as $key => $route) {
                    if (($route['solves'] ?? null) === $spec) {
                        unset($routes[$key]);
                        $matched++;
                    }
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
     * Resolve and claim a route's optional `"name"`.
     *
     * Names are OPTIONAL — an unnamed route is unchanged in every way and simply
     * cannot be addressed by UrlGenerator::route(). They live in one flat,
     * application-wide namespace, so a duplicate fails the boot: silently letting
     * the last declaration win would make route('user.show') resolve to whichever
     * plugin happened to load last.
     *
     * @param array<string, mixed>  $route
     * @param array<string, string> $names  claimed names => owning route key
     */
    private function routeName(array $route, string $key, array &$names, string $owner): ?string
    {
        $name = $route['name'] ?? null;

        if ($name === null || $name === '') {
            return null;
        }

        if (!is_string($name)) {
            throw new BootException("Route [{$key}] in {$owner} has a non-string name.");
        }

        if (isset($names[$name])) {
            throw new BootException(
                "Duplicate route name [{$name}] in {$owner} - already claimed by [{$names[$name]}]. "
                . 'Route names are application-wide and must be unique.'
            );
        }

        $names[$name] = $key;

        return $name;
    }

    /**
     * Fail the BOOT on an unknown `{name:type}` placeholder type.
     *
     * A typo like `{id:number}` would otherwise compile to a route that simply
     * never matches — a silent 404 that looks like a missing controller. Same
     * anti-typo guard already applied to unknown requires[] domains and to
     * disable specs that match nothing.
     */
    private function validateParameterTypes(string $path, string $context): void
    {
        foreach (RouteParameter::parse($path) as $placeholder) {
            if ($placeholder['type'] === '' || RouteParameter::isValidType($placeholder['type'])) {
                continue;
            }

            throw new BootException(sprintf(
                '%s declares path [%s] with unknown parameter type [%s] on {%s}. Valid types: %s.',
                $context,
                $path,
                $placeholder['type'],
                $placeholder['name'],
                implode(', ', RouteParameter::names()),
            ));
        }
    }

    /**
     * A route path must be absolute. `Request::path()` always starts with '/', so
     * a path declared as "users" compiles to the key "GET users" and can never be
     * matched — an invisible dead endpoint. Fail loudly instead of prepending the
     * slash, which would silently PUBLISH an endpoint the author believed was
     * already live.
     */
    private function normalizePath(string $path, string $context): string
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
    private function normalizePrefix(mixed $prefix, string $context): string
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
    private function normalizeFilters(mixed $filters, string $context = 'A route'): array
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
    private function mergeFilters(array $defaults, array $own): array
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
     * Optional face restriction — the project's DomainType values ('admin', 'api',
     * …). Empty means "every face", which is what every existing route gets, so
     * this is inert until a route opts in. The kernel stays domain-agnostic: it
     * compares against the plain `route_face` request attribute and never imports
     * the project's DomainContext.
     *
     * @return list<string>
     */
    private function normalizeFaces(mixed $faces): array
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
     * @param mixed $requires
     * @return list<string>
     */
    private function normalizeRequires(mixed $requires): array
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
}
