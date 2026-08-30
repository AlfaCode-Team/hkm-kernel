<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\{BootException, BootPipeline, BootStamp, ManifestReader};
use AlfacodeTeam\PhpServicePlatform\Kernel\Config\Repository as ConfigRepository;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\CoreContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Error\ErrorPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\KernelException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\{WorkerLoop, WorkerPipeline};
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Routing\{RouteIndex, UrlGenerator};
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\{SecurityGateway, Contracts\SecurityLayerContract};
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;

/**
 * Kernel — the single entry point for bootstrapping a PhpServicePlatform app.
 *
 *   return Kernel::configure()
 *       ->withBasePath(dirname(__DIR__))
 *       ->withPorts([DatabasePort::class => new MySQLAdapter(config('db'))])
 *       ->withSecurity([new CsrfTokenLayer(...)])   // + your Auth module's layer
 *       ->withErrorPipeline(ErrorPipeline::notifiers([...])->fallback(new FileNotifier(...)))
 *       ->withModules([AuthModule::class, InvoiceModule::class])
 *       ->build();
 *
 * build() runs the BootPipeline (compiling manifests + validating config), then
 * constructs the long-lived pipelines and wires each module ONCE
 * (Provider::boot) — registering hooks and event subscriptions on the live
 * instances. Per-request work (module DI) happens later in the pipelines.
 */
final class Kernel
{
    /** @var array<class-string, object> */
    private array $portBindings = [];
    /** @var list<SecurityLayerContract> */
    private array $securityLayers = [];
    /** @var list<class-string<ModuleContract>> */
    private array $moduleClasses = [];
    /** @var list<class-string<ModuleContract>> */
    private array $essentialModules = [];
    /** @var list<array{method: string, path: string, handler: string}> */
    private array $projectRoutes = [];
    /** @var array<string, string> disable-spec => spec (de-duplicated, insertion order) */
    private array $disabledRoutes = [];
    /** @var array<string, mixed> project route groups + source-wide route defaults */
    private array $projectGroups = [];
    /** @var list<string> hosts this project serves (proj.json "domains") */
    private array $projectDomains = [];
    /** HMAC key every dequeued job payload must carry; null = read the env. */
    private ?string $workerSecret = null;
    private ?ErrorPipeline $errorPipeline = null;
    private ?\Closure $errorPipelineFun = null;
    private ?string $basePath = null;
    private ?string $projectPath = null;

    private CoreContainer $core;
    private HttpPipeline  $http;
    private CliPipeline   $cli;
    private WorkerLoop    $workerLoop;

    /** Long-lived kernel services, constructed during materialize(). */
    private EventBus       $eventBus;
    private WorkerPipeline $workerPipe;

    /** Compiled configuration — lazily read after build(), before materialize(). */
    private ?ConfigRepository $config = null;

    private bool $built = false;
    private bool $materialized = false;
    /** The surface this kernel was materialized for (null until first entry-point use). */
    private ?RuntimeMode $mode = null;

    private function __construct() {}

    // ── Fluent builder ────────────────────────────────────────────────────────

    public static function configure(): self
    {
        return new self();
    }

    /** Set the application base path (var/, userdata/ live under it). */
    public function withBasePath(string $path): self
    {
        $this->basePath = $path;
        return $this;
    }

    /**
     * Set the active project path (e.g. projects/admin). Per-project runtime
     * state (var/ — manifests, logs, caches — and userdata/) is isolated under
     * it. When omitted, these fall back to the workspace base path.
     */
    public function withProjectPath(string $path): self
    {
        $this->projectPath = $path;
        return $this;
    }

    /**
     * @param array<class-string, object|\Closure> $bindings
     *   Map port interface → implementation. A pre-built object is bound
     *   eagerly; a Closure(CoreContainer): object is a lazy singleton factory,
     *   resolved (and any connection opened) only on first use.
     */
    public function withPorts(array $bindings): self
    {
        // Merge so child projects can override or add bindings from a base builder.
        $this->portBindings = array_merge($this->portBindings, $bindings);
        return $this;
    }

    /** @param list<SecurityLayerContract> $layers Order matters: cheapest first. */
    public function withSecurity(array $layers): self
    {
        // Append so inherited projects keep base layers and add their own.
        $this->securityLayers = array_merge($this->securityLayers, $layers);
        return $this;
    }

    /**
     * Require every dequeued job payload to be HMAC-signed with this key.
     *
     * A queue is an input channel: whoever can write to it is calling into the
     * application. WorkerLoop has always had the check — but the kernel never
     * had a way to give it a key, so in every deployment it was dead code and
     * the worker ran whatever it was handed.
     *
     * Defaults to `JOB_SIGNING_SECRET`, and stays OFF when that is unset, which
     * is the historical behaviour. It deliberately does NOT fall back to
     * APP_KEY: that would switch verification on for every existing application
     * at once and reject every job already in flight, since no QueuePort adapter
     * signs by default.
     *
     * TURNING IT ON IS A TWO-SIDED CHANGE. The producing adapter must stamp
     * {@see \AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\JobPayload::signatureFor()}
     * onto the envelope at push() time. Until it does, every payload is rejected
     * as unsigned and dead-lettered — correct, but not a discovery to make in
     * production. Roll it out producer-first.
     */
    public function withWorkerSecret(string $secret): self
    {
        $this->workerSecret = $secret;
        return $this;
    }

    public function withErrorPipeline(ErrorPipeline|callable|\Closure $pipeline): self
    {
        if (is_callable($pipeline)) {
            $this->errorPipelineFun = $pipeline instanceof \Closure
                ? $pipeline
                : \Closure::fromCallable($pipeline);
        } else {
            $this->errorPipeline = $pipeline;
        }
        return $this;
    }

    /** @param list<class-string<ModuleContract>> $modules */
    public function withModules(array $modules): self
    {
        // Append + de-duplicate while preserving first-seen order.
        $this->moduleClasses = array_values(array_unique(array_merge($this->moduleClasses, $modules)));
        return $this;
    }

    /**
     * Mark modules as ESSENTIAL: registered into every request-scoped container
     * regardless of the route's dependency graph, so their request-scoped
     * services (sessions, cookies, …) are available app-wide.
     *
     * Accepts provider CLASS-STRINGS and/or module DOMAINS (a plugin's solves
     * value — the shape proj.json "essentials" uses, read by
     * EntryHelpers::projectEssentials()). A class entry is auto-added to
     * withModules(); a domain entry must name a module ALREADY registered via
     * withModules() and is resolved to its provider at build() — an unknown
     * domain fails the boot with a descriptive message, never silently.
     *
     * Use sparingly — this opts those modules out of on-demand loading and adds
     * their register() cost (plus their requires[] graph) to every request.
     *
     * @param list<string> $modules provider class-strings or solves domains
     */
    public function withEssentialModules(array $modules): self
    {
        $this->essentialModules = array_values(array_unique(array_merge($this->essentialModules, $modules)));
        // Class entries must also be wired (boot) like any other module. Domain
        // entries reference modules already in withModules(); build() resolves them.
        $classes = array_values(array_filter($modules, static fn($m): bool => str_contains((string) $m, '\\')));
        if ($classes !== []) {
            $this->withModules($classes);
        }
        return $this;
    }

    /**
     * Declare PROJECT-LEVEL routes — routes that belong to the project wiring
     * layer rather than to a module's module.json. Each route maps to a project
     * controller by its FULL class path:
     *
     *   ->withRoutes([
     *       ['method' => 'GET', 'path' => '/', 'handler' => 'Shop\\Http\\HomeController@index'],
     *   ])
     *
     * These are compiled into route-manifest.php under the synthetic
     * '__project__' scope, which carries no module dependency graph. The
     * controller is resolved (and its ports autowired) from the request-scoped
     * container like any module controller, but no module register() runs for
     * it. Use this for thin project pages/endpoints that orchestrate published
     * module contracts; keep real domain logic inside modules.
     *
     * Appends + de-duplicates on "METHOD path" so a base builder can contribute
     * routes that a child project extends.
     *
     * @param list<array{method: string, path: string, handler: string}> $routes
     */
    public function withRoutes(array $routes): self
    {
        foreach ($routes as $route) {
            // The de-duplication key includes the DOMAIN group: two domains may
            // legitimately declare the same "METHOD /path" with different
            // handlers, and keying on method+path alone would silently drop one.
            $domain = strtolower(trim((string) ($route['domain'] ?? $route['subdomain'] ?? '')));

            $this->projectRoutes[RouteIndex::key(
                (string) ($route['method'] ?? ''),
                $domain,
                (string) ($route['path'] ?? ''),
            )] = $route;
        }
        return $this;
    }

    /**
     * Declare the project's route GROUPS and source-wide route defaults.
     *
     * A group states ONCE what would otherwise be repeated on every route inside
     * it — a path prefix, filters, requires, a name prefix, and the DOMAIN the
     * routes answer on. Groups may nest.
     *
     *   ->withRouteGroups([
     *       'groups' => [
     *           ['domain' => 'organizer', 'prefix' => '/dashboard',
     *            'filters' => ['auth'], 'name' => 'organizer.',
     *            'routes' => [ ... ]],
     *       ],
     *   ])
     *
     * Because `domain` is part of the compiled route KEY, two domains may each
     * define `GET /` with a different handler — this is how one project serves
     * several brands, or grants a different access level per host, without
     * forking. The domain is written literally (`africavoting.local`,
     * `*.africavoting.local`, or a bare `organizer` subdomain) and is grouped
     * verbatim: nothing resolves or validates it.
     *
     * The whole structure is expanded at BOOT into ordinary flat routes, so
     * grouping costs nothing at request time. Merged shallowly with previous
     * calls; `groups` accumulate so a base builder can contribute groups a child
     * project extends.
     *
     * @param array<string, mixed> $source
     */
    public function withRouteGroups(array $source): self
    {
        $groups = [...($this->projectGroups['groups'] ?? []), ...($source['groups'] ?? [])];

        $this->projectGroups = [...$this->projectGroups, ...$source];

        if ($groups !== []) {
            $this->projectGroups['groups'] = $groups;
        }

        return $this;
    }

    /**
     * Declare the hosts this project serves — normally proj.json "domains", the
     * same list DomainResolver matches an incoming Host against.
     *
     * Used to CHECK route domain groups at boot: grouping routes under a host the
     * project never registered produces routes nothing can reach, because such a
     * request would have been routed to another project (or refused) long before
     * the router saw it. Declaring nothing here disables the check.
     *
     * A bare `"subdomain"` group is never checked — it answers on that label
     * across every domain, so there is no single host to check it against.
     *
     * @param list<string> $domains
     */
    public function withProjectDomains(array $domains): self
    {
        foreach ($domains as $domain) {
            $domain = strtolower(trim((string) $domain));
            if ($domain !== '' && !in_array($domain, $this->projectDomains, true)) {
                $this->projectDomains[] = $domain;
            }
        }
        return $this;
    }

    /**
     * Declare the project's ROUTE-DISABLE policy — plugin routes the project
     * chooses NOT to expose. A plugin OWNS and declares its routes, but the
     * project deploying it stays the final authority: it can veto specific
     * plugin routes here without forking the plugin.
     *
     *   ->withRoutePolicy([
     *       'GET /register',   // disable one plugin route (method + path)
     *       'oauth.server',    // disable EVERY route the oauth.server module solves()
     *   ])
     *
     * Each spec is EITHER a "METHOD /path" string (one exact plugin route) or a
     * bare module domain (all of that module's routes). Specs are applied at boot
     * to plugin routes only, BEFORE project routes compile — so a project can
     * disable a plugin route and then declare its own on the freed key. A spec
     * matching nothing FAILS the build (no silent typos). Project routes declared
     * via withRoutes() are the project's own and are not affected.
     *
     * Appends + de-duplicates; a base builder's disables carry into child projects.
     *
     * @param list<string> $disable
     */
    public function withRoutePolicy(array $disable): self
    {
        foreach ($disable as $spec) {
            $spec = trim((string) $spec);
            if ($spec !== '') {
                $this->disabledRoutes[$spec] = $spec;
            }
        }
        return $this;
    }

    /**
     * Build and validate the kernel. Fails fast on any misconfiguration.
     *
     * @throws \AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\BootFailureException
     */
    public function build(): self
    {
        if ($this->basePath !== null) {
            Paths::setBase($this->basePath);
        }
        Paths::setProject($this->projectPath);

        if ($this->errorPipelineFun instanceof \Closure) {
            $this->errorPipeline = ($this->errorPipelineFun)($this->portBindings);
        }

        $this->core = new CoreContainer();
        foreach ($this->portBindings as $abstract => $concrete) {
            // A Closure is a lazy factory: the port (and any connection it
            // opens) is built only on first resolution, so requests that never
            // touch it pay nothing. A pre-built object is bound eagerly as-is.
            $concrete instanceof \Closure
                ? $this->core->singleton($abstract, $concrete)
                : $this->core->instance($abstract, $concrete);
        }

        // Validate config + compile manifests (service/route/job/command).
        // No pipelines are constructed and no module is wired here: that work is
        // deferred to materialize(), driven by whichever entry point is actually
        // used. A process that only serves HTTP never pays to build the worker
        // surface, and vice versa.
        $reader   = new ManifestReader();
        $pipeline = new BootPipeline(
            $this->moduleClasses,
            $this->core,
            $this->securityLayers,
            array_values($this->projectRoutes),
            array_values($this->disabledRoutes),
            $this->projectGroups,
            $this->projectDomains,
            $reader,
        );

        // BOOT CACHE (opt-in). Under PHP-FPM every request re-runs this method,
        // recompiling manifests that are byte-identical to the last request's.
        // When BOOT_CACHE is on and nothing the compile read has changed, skip
        // the compilation and keep only the validation stages, which touch no
        // disk and must still catch a missing port or an unusable layer.
        // Computed ONCE, from the RAW builder inputs, and reused for the write
        // below. It must NOT be recomputed after resolveEssentialModules():
        // that turns proj.json's "essentials" domains into provider classes, so
        // a hash taken afterwards would never equal the one the next build looks
        // the stamp up with — the cache would miss on every request, and pay for
        // a stamp rewrite on top of the recompile it failed to skip.
        $cacheEnabled = BootStamp::enabled();
        $stampHash    = $cacheEnabled ? $this->buildHash() : '';

        $cached = $cacheEnabled ? BootStamp::read($stampHash) : null;

        if ($cached !== null) {
            $pipeline->runValidationOnly();
            // Recomputing these means re-reading every module.json — the very
            // cost the cache exists to avoid — so they are cached alongside it.
            $this->essentialModules = $cached['essentials'];
            $this->built = true;

            return $this;
        }

        $pipeline->run();

        // Resolve essential DOMAIN entries (proj.json "essentials") to their
        // provider classes now that the module list is final. Fails the boot on
        // an unknown domain — never a silent no-op essential. Reuses the
        // pipeline's reader, whose module.json cache is already warm.
        $this->essentialModules = $this->resolveEssentialModules($reader);

        if ($cacheEnabled) {
            // $stampHash, not a fresh buildHash() — see the note above.
            BootStamp::write($stampHash, $reader->files(), $this->essentialModules);
        }

        $this->built = true;
        return $this;
    }

    /**
     * Everything the BUILDER contributes to compilation, as one hash.
     *
     * proj.json reaches the kernel as PHP arrays (routes, groups, domains,
     * essentials, disable policy), so hashing these covers a proj.json edit
     * without stat'ing it — and covers an edit to bootstrap/app.php itself,
     * which no file-mtime check would catch.
     *
     * CALL THIS BEFORE resolveEssentialModules(), AND ONLY ONCE PER BUILD.
     * `essentials` here is a BUILDER INPUT — the raw list the project passed,
     * which for proj.json is domains ('tenancy.routing'). resolveEssentialModules()
     * replaces it with the DERIVED provider classes, so a hash taken afterwards
     * describes a different array and can never match the one the next build
     * reads with. The derived list belongs in the stamp's payload, not its key.
     */
    private function buildHash(): string
    {
        return BootStamp::hash([
            'modules'    => $this->moduleClasses,
            'essentials' => $this->essentialModules,
            'routes'     => $this->projectRoutes,
            'groups'     => $this->projectGroups,
            'disabled'   => $this->disabledRoutes,
            'domains'    => $this->projectDomains,
            'base'       => $this->basePath,
            'project'    => $this->projectPath,
        ]);
    }

    /**
     * Resolve the essential-module list to provider classes. Entries containing
     * a namespace separator are class-strings and pass through; anything else is
     * a module DOMAIN (a solves value, e.g. 'tenancy.routing' from proj.json
     * "essentials") resolved against the modules registered in withModules().
     *
     * @return list<class-string<ModuleContract>>
     * @throws BootException when a domain matches no registered module
     */
    private function resolveEssentialModules(ManifestReader $reader): array
    {
        if ($this->essentialModules === []) {
            return [];
        }

        $byDomain = [];
        foreach ($this->moduleClasses as $class) {
            $m = $reader->read($class);
            if (isset($m['solves']) && is_string($m['solves'])) {
                $byDomain[$m['solves']] = $class;
            }
        }

        $resolved = [];
        $unknown = [];
        foreach ($this->essentialModules as $entry) {
            if (str_contains($entry, '\\')) {
                $resolved[$entry] = true;
            } elseif (isset($byDomain[$entry])) {
                $resolved[$byDomain[$entry]] = true;
            } else {
                $unknown[] = $entry;
            }
        }

        if ($unknown !== []) {
            throw new BootException(
                "Unknown essential module domain(s): '" . implode("', '", $unknown) . "'."
                . " Each must be the solves domain of a module registered in withModules([...])."
                . " Registered domains: " . implode(', ', array_keys($byDomain))
            );
        }

        return array_keys($resolved);
    }

    /**
     * Construct the pipelines, wire every module ONCE, and freeze the core
     * container. Runs at most once, on the first entry-point call, for the
     * surface that call selects.
     *
     * Module boot() registers hooks for all three pipelines plus event
     * subscriptions in a single call, so all three pipeline instances must
     * exist when modules are wired. They are cheap shells — each defers its
     * manifest disk I/O until its own first run — so constructing the two
     * surfaces not in use costs effectively nothing.
     */
    private function materialize(RuntimeMode $mode): void
    {
        if ($this->materialized) {
            return;
        }
        $this->mode = $mode;

        $errorPipeline    = $this->errorPipeline ?? ErrorPipeline::default();

        // Wire the app logger into the EventBus when the project bound one via
        // withPorts(). Without it a listener exception is swallowed AND unlogged,
        // which is indistinguishable from the event never being dispatched.
        $this->eventBus   = new EventBus(
            $this->core,
            $this->core->has(LoggerPort::class) ? $this->core->get(LoggerPort::class) : null,
        );
        $this->workerPipe = new WorkerPipeline();

        $this->http = new HttpPipeline(
            gateway:          new SecurityGateway($this->securityLayers),
            core:             $this->core,
            errorPipeline:    $errorPipeline,
            essentialModules: $this->essentialModules,
        );
        $this->cli        = new CliPipeline($this->core, $errorPipeline);
        $this->workerLoop = new WorkerLoop(
            $this->core,
            $errorPipeline,
            $this->workerPipe,
            $this->workerSecret ?? (string) (env('JOB_SIGNING_SECRET') ?: ''),
            // Essentials reach the worker for the same reason they reach HTTP: a
            // job is a request with no HTTP in front of it, and a module the
            // project declared app-wide should not quietly stop existing at the
            // queue boundary.
            essentialModules: $this->essentialModules,
        );

        // Configuration compiled by CompileConfigManifestStage during build().
        // Bound BEFORE module boot() so a Provider can read config while wiring.
        $this->core->instance(ConfigRepository::class, $this->config());

        // Expose kernel services to modules via the core container.
        // UrlGenerator is a lazy singleton: a module that never builds a URL never
        // pays for the route-name index, and one that does gets it injected rather
        // than reaching for the global helper.
        $this->core->singleton(
            UrlGenerator::class,
            static fn(): UrlGenerator => UrlGenerator::fromManifest((string) (env('APP_URL') ?: '')),
        );
        $this->core->instance(EventBus::class, $this->eventBus);
        $this->core->instance(WorkerPipeline::class, $this->workerPipe);
        $this->core->instance(HttpPipeline::class, $this->http);
        $this->core->instance(CliPipeline::class, $this->cli);
        $this->core->instance(ErrorPipeline::class, $errorPipeline);

        // Wire each module ONCE — hooks + event subscriptions on live instances.
        foreach ($this->moduleClasses as $moduleClass) {
            /** @var ModuleContract $provider */
            $provider = new $moduleClass();
            $provider->boot($this->http, $this->cli, $this->workerPipe, $this->eventBus);
        }

        // Freeze the core container — no new bindings may be registered after this
        // point. Any write (bind/singleton/instance/extend) now throws LogicException.
        // This is enforced under both OpenSwoole (prevents cross-request mutation) and
        // standard FPM/CLI (catches accidental lazy registration in request handlers).
        $this->core->freeze();

        $this->materialized = true;
    }

    // ── Entry points ──────────────────────────────────────────────────────────

    public function http(): HttpPipeline
    {
        $this->ensureBuilt();
        $this->materialize(RuntimeMode::Http);
        return $this->http;
    }

    public function cli(): CliPipeline
    {
        $this->ensureBuilt();
        $this->materialize(RuntimeMode::Cli);
        return $this->cli;
    }

    public function workerLoop(): WorkerLoop
    {
        $this->ensureBuilt();
        $this->materialize(RuntimeMode::Worker);
        return $this->workerLoop;
    }

    public function container(): CoreContainer
    {
        $this->ensureBuilt();
        // Resolving kernel services (EventBus, pipelines) requires materialization,
        // and the container must be frozen for callers relying on read-only access.
        // Default to the CLI surface — the safest neutral choice for tooling/tests
        // that reach for the container directly without driving an entry point.
        $this->materialize(RuntimeMode::Cli);
        return $this->core;
    }

    /**
     * The compiled configuration, read from config-manifest.php.
     *
     * Available after build() — it does NOT materialize, so bootstrap code and
     * tooling can read configuration without standing up the pipelines. The same
     * instance is bound into the core container during materialize(), so modules
     * resolve it by type-hinting Config\Repository.
     */
    public function config(): ConfigRepository
    {
        $this->ensureBuilt();

        return $this->config ??= new ConfigRepository(
            ManifestReader::readCompiled('config-manifest.php'),
        );
    }

    /** The surface this kernel materialized for, or null if no entry point ran yet. */
    public function mode(): ?RuntimeMode
    {
        return $this->mode;
    }

    /**
     * Optional per-request teardown hook.
     *
     * Under standard PHP-FPM or CLI this is a no-op — every request runs in a
     * fresh process (FPM) or the script simply ends (CLI). Call it anyway so
     * application code is portable from the start.
     *
     * Under OpenSwoole / Swoole, call this at the END of every request handler
     * before the coroutine yields back to the event loop:
     *
     *   $server->on('request', function ($req, $res) use ($kernel) {
     *       $response = $kernel->http()->handle(Request::fromSwoole($req));
     *       $res->end($response->body());
     *       $kernel->requestTeardown(); // ← always call after each request
     *   });
     *
     * Why it is currently a no-op:
     *   ModuleContainer is already created fresh per request inside LoadStage
     *   (via OnDemandLoader) and is garbage-collected when the request chain
     *   finishes. CoreContainer is frozen (read-only after build()). There is
     *   no persistent request-scoped state attached to the kernel itself.
     *
     * If you add request-scoped kernel services in the future, reset them here.
     */
    public function requestTeardown(): void
    {
        // no-op — see docblock above
    }

    private function ensureBuilt(): void
    {
        if (!$this->built) {
            throw new KernelException('Kernel::build() must be called before using an entry point.', layer: 'kernel');
        }
    }
}
