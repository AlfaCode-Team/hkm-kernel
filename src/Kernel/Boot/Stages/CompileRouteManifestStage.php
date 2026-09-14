<?php declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\{BootException, ManifestReader, ManifestWriter};
use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Routing\{
    DomainComposer, GroupExpander, RouteCompiler, RouteNormalizer, RoutePolicy
};
use AlfacodeTeam\PhpServicePlatform\Kernel\Routing\RouteIndex;

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
 *
 * WHAT THIS CLASS DOES, AND WHAT IT DELEGATES
 * -------------------------------------------
 * This file used to be 1,200 lines doing six jobs, which made it both the most
 * defect-prone file in the kernel and the hardest to test in isolation — every
 * routing test had to compile a whole manifest to reach any of it. The six jobs
 * now live in `Boot\Routing\`, each independently testable:
 *
 *   RouteNormalizer   one declared VALUE → its canonical shape (path, prefix,
 *                     filters, faces, requires), failing the boot on nonsense
 *   DomainComposer    which HOSTS a route/group/module answers on
 *   GroupExpander     nested groups[] → flat routes, with the inheritance rules
 *   RouteCompiler     one route → its precompiled entry, plus the anti-typo guards
 *   RoutePolicy       the project's allow/disable authority over plugin routes
 *
 * What remains here is the ORDER, which is the part that is genuinely this
 * stage's own: read manifests, compile plugin routes, apply the project's policy,
 * then compile project routes over the top. Each step's placement is load-bearing
 * and commented where it is not obvious.
 */
final class CompileRouteManifestStage implements BootStageContract
{
    /** Synthetic scope for project-layer routes (no owning module). */
    public const PROJECT_SCOPE = '__project__';

    private readonly GroupExpander $groups;
    private readonly RouteCompiler $compiler;
    private readonly RoutePolicy   $policy;

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
        array $disabledRoutes = [],
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
        array $projectDomains = [],
        /**
         * ALLOWLIST of plugin routes — Kernel::withRouteAllowPolicy(). When
         * non-empty, a plugin route must match a spec or it is dropped. Empty
         * means "no allowlist", not "allow nothing".
         *
         * @var list<string>
         */
        array $allowedRoutes = [],
    ) {
        $this->groups   = new GroupExpander(new DomainComposer($projectDomains));
        $this->compiler = new RouteCompiler();
        $this->policy   = new RoutePolicy($disabledRoutes, $allowedRoutes);
    }

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
            $manifest                = $this->reader->read($moduleClass);
            $manifests[$moduleClass] = $manifest;
            $knownDomains[$manifest['solves']] = true;
        }

        // PASS 2a — plugin routes (from module.json). A plugin route normally
        // gets its deps via its module's solves graph, but it MAY also declare
        // route-level requires[] (validated + honoured by LoadStage), kept
        // consistent with project routes.
        foreach ($this->moduleClasses as $moduleClass) {
            $manifest = $manifests[$moduleClass];

            foreach ($this->groups->flatten($manifest, "[{$moduleClass}]") as $route) {
                $path = $route['path'];
                $key  = RouteIndex::key($route['method'], $route['domain'], $path);

                if (isset($routes[$key])) {
                    throw new BootException(
                        "Duplicate route [{$key}] declared by [{$moduleClass}] and [{$routes[$key]['module']}]."
                    );
                }

                $filters  = $route['filters'];
                $requires = $this->compiler->validateRequires(
                    $route['requires'],
                    $knownDomains,
                    "Route [{$key}] in [{$moduleClass}]",
                );

                $routes[$key] = [
                    'handler'  => $route['handler'],
                    'module'   => $moduleClass,
                    'solves'   => $manifest['solves'],
                    'name'     => $this->compiler->claimName($route, $key, $names, "[{$moduleClass}]"),
                    'filters'  => $filters,
                    'requires' => $requires,
                    'faces'    => $route['faces'],
                    'domain'   => $route['domain'],
                ] + $this->compiler->precompile(
                    $route['handler'],
                    $path,
                    $manifest['solves'],
                    $filters,
                    "Route [{$key}] in [{$moduleClass}]",
                );

                // requires[] is validated above but precompile() ran before it was
                // stored; recompute the graph key now that the final list is known.
                $routes[$key]['graph_key'] = $manifest['solves'] . '|' . implode(',', $requires);
            }
        }

        // PASS 2a.5 — apply the project's route policy. Runs on plugin routes only
        // (project routes are compiled below and are the project's own to
        // add/remove). Dropping BEFORE project routes frees the "METHOD path" key
        // so a project may disable a plugin route AND declare its own on it
        // without a duplicate-route boot failure.
        // Allowlist FIRST, then subtract. The two compose: allow a module's whole
        // domain, then disable the handful of its routes you do not want.
        $routes = $this->policy->allow($routes);
        $routes = $this->policy->disable($routes);

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

        foreach ($this->groups->flatten($projectSource, 'the project') as $route) {
            // DETERMINISTIC PRIORITY: project routes are compiled AFTER every
            // plugin route and OVERRIDE a plugin route declaring the same
            // "METHOD path". This is the default project-over-plugin precedence —
            // never the reverse. Plugins cannot reclaim a route the project owns.
            $path = $route['path'];
            $key  = RouteIndex::key($route['method'], $route['domain'], $path);

            // A project override INHERITS the overridden plugin route's name
            // unless it declares its own. Overriding changes where a name points,
            // not whether it exists — otherwise every route('user.show') in a
            // plugin's own views would break the moment a project customised that
            // page, which is the single most common thing a project does.
            $inherited = $routes[$key]['name'] ?? null;
            $declared  = $this->compiler->claimName($route, $key, $names, 'the project');

            if ($declared === null && $inherited !== null) {
                // Already claimed by the plugin route being replaced — the name
                // survives, still pointing at exactly one route.
                $declared = $inherited;
            }

            $filters  = $route['filters'];
            $requires = $this->compiler->validateRequires($route['requires'], $knownDomains, "Project route [{$key}]");

            $routes[$key] = [
                'handler'   => $route['handler'],
                'module'    => null,
                'solves'    => self::PROJECT_SCOPE,
                'name'      => $declared,
                'overrides' => $routes[$key]['module'] ?? null,
                'filters'   => $filters,
                // Per-route module dependencies seeded into this request's graph
                // by LoadStage. Each must name a real module domain — fail at boot.
                'requires'  => $requires,
                'faces'     => $route['faces'],
                'domain'    => $route['domain'],
            ] + $this->compiler->precompile(
                $route['handler'],
                $path,
                self::PROJECT_SCOPE,
                $filters,
                "Project route [{$key}]",
            );

            $routes[$key]['graph_key'] = self::PROJECT_SCOPE . '|' . implode(',', $requires);
        }

        ManifestWriter::write('route-manifest.php', $routes);
        ManifestWriter::write('route-index.php', RouteIndex::build($routes));
        ManifestWriter::write('route-names.php', RouteIndex::names($routes));
    }

    /**
     * "throttle:60,1" => ['alias' => 'throttle', 'args' => ['60', '1']]
     *
     * Kept here as the public entry point because RouteFilterStage already calls
     * it by this name. The implementation moved to {@see RouteNormalizer}, where
     * the rest of the value-normalisation lives.
     *
     * @return array{alias: string, args: list<string>}
     */
    public static function parseFilterSpec(string $spec): array
    {
        return RouteNormalizer::parseFilterSpec($spec);
    }
}
