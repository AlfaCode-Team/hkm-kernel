<?php declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\{BootException, ManifestReader, ManifestWriter};

/** Reads routes[] from every module.json -> route-manifest.php (OPcache-cached). */
final class CompileRouteManifestStage implements BootStageContract
{
    /** Synthetic scope for project-layer routes (no owning module). */
    public const PROJECT_SCOPE = '__project__';

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
    ) {}

    public function run(): void
    {
        $routes = [];

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
            foreach ($manifest['routes'] ?? [] as $route) {
                if (!isset($route['method'], $route['path'], $route['handler'])) {
                    throw new BootException(
                        "Invalid route in [{$moduleClass}] - each route needs method, path and handler."
                    );
                }
                if (!str_contains($route['handler'], '@')) {
                    throw new BootException(
                        "Route handler [{$route['handler']}] in [{$moduleClass}] must be in 'Controller@method' format."
                    );
                }
                $key = strtoupper($route['method']) . ' ' . $route['path'];
                if (isset($routes[$key])) {
                    throw new BootException(
                        "Duplicate route [{$key}] declared by [{$moduleClass}] and [{$routes[$key]['module']}]."
                    );
                }
                $routes[$key] = [
                    'handler' => $route['handler'],
                    'module' => $moduleClass,
                    'solves' => $manifest['solves'],
                    'filters' => $this->normalizeFilters($route['filters'] ?? []),
                    'requires' => $this->validateRequires(
                        $this->normalizeRequires($route['requires'] ?? []),
                        $knownDomains,
                        "Route [{$key}] in [{$moduleClass}]",
                    ),
                ];
            }
        }

        // PASS 2a.5 — apply the project's route DISABLE policy. Runs on plugin
        // routes only (project routes are compiled below and are the project's own
        // to add/remove). Dropping BEFORE project routes frees the "METHOD path"
        // key so a project may disable a plugin route AND declare its own on it
        // without a duplicate-route boot failure.
        $routes = $this->applyDisablePolicy($routes);

        // PASS 2b — project-layer routes (Kernel::withRoutes / proj.json), not in
        // any module.json. They carry no module and resolve under the synthetic
        // PROJECT_SCOPE, whose dependency graph is empty — so route-level
        // requires[] is the ONLY way a project page pulls in a plugin.
        foreach ($this->projectRoutes as $route) {
            if (!isset($route['method'], $route['path'], $route['handler'])) {
                throw new BootException(
                    'Invalid project route - each route needs method, path and handler.'
                );
            }
            if (!str_contains($route['handler'], '@')) {
                throw new BootException(
                    "Project route handler [{$route['handler']}] must be in 'Controller@method' format."
                );
            }
            // DETERMINISTIC PRIORITY: project routes are compiled AFTER every
            // plugin route and OVERRIDE a plugin route declaring the same
            // "METHOD path". This is the default project-over-plugin precedence —
            // never the reverse. Plugins cannot reclaim a route the project owns.
            $key = strtoupper($route['method']) . ' ' . $route['path'];

            $routes[$key] = [
                'handler' => $route['handler'],
                'module' => null,
                'solves' => self::PROJECT_SCOPE,
                'overrides' => $routes[$key]['module'] ?? null,
                'filters' => $this->normalizeFilters($route['filters'] ?? []),
                // Per-route module dependencies seeded into this request's graph
                // by LoadStage. Each must name a real module domain — fail at boot.
                'requires' => $this->validateRequires(
                    $this->normalizeRequires($route['requires'] ?? []),
                    $knownDomains,
                    "Project route [{$key}]",
                ),
            ];
        }

        ManifestWriter::write('route-manifest.php', $routes);
    }

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
                // Normalize "get  /register" → "GET /register".
                [$method, $path] = preg_split('/\s+/', $spec, 2) ?: [$spec, ''];
                $key = strtoupper($method) . ' ' . $path;
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
     * Normalize a route's declared filters to a clean list of string specs.
     * Accepts a single string ("auth") or a list (["auth", "throttle:60"]).
     *
     * @param mixed $filters
     * @return list<string>
     */
    private function normalizeFilters(mixed $filters): array
    {
        if (is_string($filters)) {
            $filters = [$filters];
        }
        if (!is_array($filters)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn($f): string => trim((string) $f), $filters),
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
