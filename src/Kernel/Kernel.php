<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\BootPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\CoreContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Error\ErrorPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\KernelException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\{WorkerLoop, WorkerPipeline};
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\{SecurityGateway, Contracts\SecurityLayerContract};
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;

/**
 * Kernel — the single entry point for bootstrapping a PhpServicePlatform app.
 *
 *   return Kernel::configure()
 *       ->withBasePath(dirname(__DIR__))
 *       ->withPorts([DatabasePort::class => new MySQLAdapter(config('db'))])
 *       ->withSecurity([new FirewallLayer(...), new CsrfTokenLayer(...)])
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
     * Use sparingly — this opts those modules out of on-demand loading and adds
     * their register() cost to every request. Each essential module is also
     * added to withModules() so its boot() hooks are registered.
     *
     * @param list<class-string<ModuleContract>> $modules
     */
    public function withEssentialModules(array $modules): self
    {
        $this->essentialModules = array_values(array_unique(array_merge($this->essentialModules, $modules)));
        // Essentials must also be wired (boot) like any other module.
        $this->withModules($modules);
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
            $this->projectRoutes[strtoupper($route['method'] ?? '') . ' ' . ($route['path'] ?? '')] = $route;
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
        (new BootPipeline(
            $this->moduleClasses,
            $this->core,
            $this->securityLayers,
            array_values($this->projectRoutes),
            array_values($this->disabledRoutes),
        ))->run();

        $this->built = true;
        return $this;
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
        $this->eventBus   = new EventBus($this->core);
        $this->workerPipe = new WorkerPipeline();

        $this->http = new HttpPipeline(
            gateway:          new SecurityGateway($this->securityLayers),
            core:             $this->core,
            errorPipeline:    $errorPipeline,
            essentialModules: $this->essentialModules,
        );
        $this->cli        = new CliPipeline($this->core, $errorPipeline);
        $this->workerLoop = new WorkerLoop($this->core, $errorPipeline, $this->workerPipe);

        // Expose kernel services to modules via the core container.
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
