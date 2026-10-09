# Module Loading

After the kernel is built and the first entry point is called (http(), cli(), workerLoop()), the `OnDemandLoader` creates a request-scoped container, calculates which modules are needed, and registers each one's bindings in dependency order. This page covers dependency graph calculation, the three module activation modes, and how modules are loaded per request.

## Dependency Graph Calculation

The `DependencyGraphCalculator` reads the compiled service-manifest and builds a dependency graph for a given service (usually the route's handler service).

### DependencyGraphCalculator

#### `resolve(string $service, array $additional = []): DependencyGraph`

Calculate the dependency graph for a service and optional extra domains.

```php
$calculator = new DependencyGraphCalculator($manifest);

// HTTP pipeline resolves a route to a service name (e.g. 'POST /api/invoices' → 'invoice.generation')
$graph = $calculator->resolve('invoice.generation');

// A project route may also opt into specific plugins per-route
$graph = $calculator->resolve('__project__', ['view.rendering', 'tenancy.routing']);
```

**Stateless:** All traversal state lives in locals passed by reference, never on `$this`. One calculator instance is shared across every request (and every coroutine under OpenSwoole) — keeping zero mutable state makes concurrent calls provably non-interfering.

**Algorithm:** Depth-first search over the `requires[]` graph:
1. Start at the service, mark it as "resolving".
2. Visit each dependency (each entry in `requires[]`), recursively.
3. Mark as "resolved" (added to the final graph) only after all dependencies are resolved.
4. Detect cycles: if we encounter a "resolving" entry again, throw `CircularDependencyException`.

**Result:** A `DependencyGraph` listing modules in dependency order (dependencies before dependents). This is the order in which `OnDemandLoader` calls `register()`.

#### `domainsFor(array $manifest, array $moduleClasses): array`

Map a list of provider classes to their `solves` domains (read from the service manifest).

```php
$domains = DependencyGraphCalculator::domainsFor($manifest, [
    AuthModule::class,
    InvoiceModule::class,
]);
// → ['auth.identity', 'invoice.generation']
```

Used by `OnDemandLoader` to convert essential-module classes into domains so they can be seeded into the dependency graph (bringing their transitive `requires[]` with them).

### DependencyGraph

The result of `resolve()`. Immutable, holds ordered module information.

```php
$graph = $calculator->resolve('invoice.generation');

$graph->moduleNames();     // ['database.management', 'invoice.generation']
$graph->entry('invoice.generation');  // {
                                       //   'module' => InvoiceModule::class,
                                       //   'requires' => ['database.management'],
                                       // }
```

## OnDemandLoader

Creates a request-scoped `ModuleContainer` and registers each module's bindings in dependency order.

### Constructor

```php
public function __construct(
    private readonly CoreContainer $core,
    private readonly array $essentialModules = [],
)
```

- **`$core`** — the app-lifetime CoreContainer, used as fallback for ports.
- **`$essentialModules`** — list of provider class-strings loaded into EVERY request, regardless of the route graph.

### `load(DependencyGraph $graph, Request $request): ModuleContainer`

Build a container for an HTTP request.

```php
// Inside HTTP pipeline's LoadStage
$loader = new OnDemandLoader($core, $essentialModules);
$graph = $calculator->resolve($serviceToCall);  // from route resolution
$container = $loader->load($graph, $request);
```

**Process:**
1. Create a fresh `ModuleContainer` (fallback to core for ports).
2. Bind request-scoped kernel services into the empty scope:
   - `Identity` → the request's identity (or guest if unauthenticated).
   - `DomainEventCollector` → fresh buffer for domain events.
   - `EventBus` → rebound to resolve listeners against this request's container.
   - `TransactionManager` → wrapping the core's `DatabasePort`.
   - `client.ip` → the request's client IP (for audit trails). HTTP only: `load()` binds it after `loadWithIdentity()`, so job containers have no `client.ip`. It comes from `Request::ip()`, so behind a proxy set `TRUSTED_PROXIES` or it is the proxy's address (see [Request](/http/request#request-properties-as-values)).
3. Register each module in the graph, in dependency order.
4. Register essential modules that the graph did not pull in (de-duplicated).
5. Return the wired container.

### `loadWithIdentity(DependencyGraph $graph, ?Identity $identity): ModuleContainer`

Build a container with a pre-resolved Identity. Used by `WorkerLoop` (jobs have no HTTP Request) and by `load()` above.

```php
$container = $loader->loadWithIdentity($graph, $jobIdentity);
// Same as load(), but without the request IP binding
```

Passing `null` yields a guest Identity (`Identity::guest()`).

## Three Activation Modes

A module's `Provider::register()` and `boot()` methods run at different times depending on which activation mode the module is in.

### Mode 1: On-Demand Module

**Declaration:**
```php
Kernel::configure()
    ->withModules([InvoiceModule::class, PaymentModule::class])
    // (no withEssentialModules)
```

**When is register() called?**
- The route pulls this module into the dependency graph (via its `solves` domain in `requires[]` or the route's per-route `requires[]`).
- `OnDemandLoader::load()` calls it once per request, in dependency order.

**When is boot() called?**
- Once, during kernel materialize (before any request is served).
- All three surfaces (HTTP, CLI, worker) call `boot()` so modules can register hooks.

**Cost per request:**
- `register()` cost only when the module is loaded.
- If a route does not need PaymentModule, that `register()` never runs on that request.

**Use for:** capabilities only some requests need (outbound HTTP, file storage, payment processing).

### Mode 2: Essential Module

**Declaration:**
```php
Kernel::configure()
    ->withModules([SessionModule::class, TenancyModule::class])
    ->withEssentialModules(['user.management', SessionModule::class])
```

**When is register() called?**
- EVERY request-scoped container, even if the route graph did not pull it in.
- Called in dependency order (dependencies before dependents).
- De-duplicated: if the route graph already includes the module, it is not registered twice.

**When is boot() called?**
- Once, during kernel materialize.

**Cost per request:**
- Full `register()` cost on every request, plus the cost of every module it transitively `requires[]`.
- Essentials remove themselves from on-demand loading.

**When to use:**
- Session management (sessions must be available on every page).
- Tenant scoping (every request needs to know which tenant).
- User management for role/permission checks.
- Very rarely for anything else.

**In proj.json:**
```jsonc
{
  "essentials": ["tenancy.routing", "user.management"]
}
```

Project routes inherit essentials from `proj.json`, so they have the same infrastructure available as plugin routes. Essential domains are converted to provider classes by `Kernel::build()` and validated against the registered module list.

### Mode 3: App-Lifetime Port

**Declaration:**
```php
Kernel::configure()
    ->withPorts([
        DatabasePort::class => new MySQLAdapter(config('database')),
        CachePort::class    => new RedisAdapter(config('cache')),
    ])
```

**When is it available?**
- From `Kernel::build()` onwards.
- Available in every request-scoped container via core fallback.
- Available to modules during `register()`.

**When is it initialized?**
- Immediately (pre-built object): when `build()` is called.
- Lazily (Closure factory): on first use (first request that touches it).

**Cost:**
- One-time cost to construct (if eager) or zero if never used (if lazy).
- Available everywhere, always.

**Use for:** stateless infrastructure (DB connection, cache driver, logger).

## Rule of Thumb

| Activation mode | Cost | Always available? | Use case |
|---|---|---|---|
| **App-lifetime port** | One-time initialization | Yes, everywhere | Stateless, app-wide infrastructure |
| **On-demand module** | Per-request, only when loaded | Only when needed | Optional capabilities (mail, file storage) |
| **Essential module** | Per-request, always | Yes, every request | Request-scoped infrastructure that must be app-wide (sessions, tenancy) |

Most modules should be on-demand. Essential is for request-scoped infrastructure that cannot be a port (because it needs the module's full wiring).

## Request-Scoped Kernel Services

These are bound into every request-scoped container, in the empty scope (shared by all modules):

#### `Identity`

The current user (or guest) from `SecurityGateway`.

```php
$container->singleton(Identity::class, static fn() => $resolvedIdentity);
```

Resolvable by any module, never internal. Contains userId, tenantId, roles, permissions.

#### `DomainEventCollector`

Buffers domain events during a transaction. Discarded if the transaction rolls back.

```php
$container->singleton(DomainEventCollector::class, static fn() => new DomainEventCollector());
```

Used by services to collect events before commit.

#### `EventBus` (rebound)

The core's EventBus, but rebound to resolve listeners against THIS request's container.

```php
if ($this->core->has(EventBus::class)) {
    $coreBus = $this->core->make(EventBus::class);
    $container->singleton(
        EventBus::class,
        static fn($c): EventBus => $coreBus->forContainer($c),
    );
}
```

Without this rebind, a listener whose dependencies a plugin binds in `register()` can never be constructed.

#### `TransactionManager`

Wraps the core's `DatabasePort` to manage transaction lifecycle.

```php
$container->singleton(
    TransactionManager::class,
    fn() => new TransactionManager($this->core->make(DatabasePort::class)),
);
```

#### `client.ip` (conditionally)

The client's IP address (from the request), bound only on HTTP requests.

```php
$ip = $request->ip();
if ($ip !== null && $ip !== '') {
    $container->bind('client.ip', static fn(): string => $ip);
}
```

Used by services and repositories to attribute actions to their origin (audit trails, rate limiting context).

## Essentials Applied to Workers

Essential modules are ALSO seeded into every job's container via `WorkerLoop`:

```php
// Inside WorkerLoop::run()
$loader = new OnDemandLoader($core, $essentialModules);  // same essential list
$graph = $calculator->resolve($jobClass);
$container = $loader->loadWithIdentity($graph, $jobIdentity);  // essentials included
```

A job is a request with no HTTP in front of it. If a module declared as essential is skipped on workers, the job would run without access to tenancy routing or session management. This is a subtle breaking change when essentials are added or removed, which is why the same list applies everywhere.

## Synthetic `__project__` Scope

Project routes (declared via `Kernel::withRoutes()` or `proj.json` `routes[]`) are compiled into the route-manifest under the synthetic `__project__` scope.

**Properties:**
- No `module` provider (null).
- No `requires[]` of its own (empty).
- Per-route `requires[]` can pull in specific plugins (opt-in via proj.json).

**Why it exists:**
- Project routes are thin orchestration (no business logic). They should not own a module.
- The controller is resolved (and its ports autowired) from the request container like any handler.
- But the controller is NOT in a module scope, so it can only autowire public contracts and ports, not internal bindings.

**De-duplication:**
- If the route graph pulls in a module that is also essential, the module is registered once (de-duped by provider class).
- Essential modules run AFTER the route graph in `OnDemandLoader::loadWithIdentity()` for this reason.

## Common Patterns

### Conditional Module Loading

A project route opts into a plugin only when needed:

```jsonc
// proj.json
{
  "routes": [
    {
      "method": "GET",
      "path": "/dashboard",
      "handler": "Shop\\DashboardController@index",
      "requires": ["analytics.reporting"]  // only this route needs it
    }
  ]
}
```

Other routes skip the analytics module entirely.

### Cross-Module Service Consumption

Module A uses a service from Module B:

```php
// ModuleA/module.json
"requires": ["module.b"]

// ModuleA/Provider.php
$container->bind(MyServiceA::class, fn($c) =>
    new MyServiceA(
        $c->make(ServiceBContract::class)  // ✓ public, cross-module
    )
);
```

The dependency graph ensures ModuleB is registered before ModuleA.

### Essential Tenancy

Every request needs to know which tenant, even project routes:

```php
// bootstrap/app.php
->withEssentialModules(['tenancy.routing'])

// proj.json
"essentials": ["tenancy.routing"]  // redundant if already in bootstrap, but explicit
```

The tenancy module's `register()` runs on every request, so a project route's controller can autowire a tenant-scoped repository from a plugin.

## Pitfalls

**Declaring a module essential when it should be on-demand:**
```php
// ✗ every request pays for this
->withEssentialModules([OutboundMailModule::class])

// ✓ only mail-sending routes load it
->withModules([OutboundMailModule::class])
```

**Forgetting `requires[]` in a module that depends on another:**
```php
// module.json — missing database.management
"requires": []

// Provider.php
$container->bind(MyService::class, fn($c) =>
    new MyService($c->make(DatabaseContract::class))  // ✗ unbound, throws EntryNotFoundException
);
```

**Trying to access an internal binding from a different module:**
```php
// PaymentModule trying to use InvoiceRepository directly
$container->make(InvoiceRepository::class)  // ✗ throws ScopeViolationException
```

Use the public `InvoiceServiceContract` instead.

**Registering a binding outside of `register()`:**
```php
// ✗ too late, container is frozen
public function boot(...) {
    $container->bind(...);  // ERROR — but you don't have the container here anyway
}
```

All bindings must be in `register()`. Hooks and event subscriptions go in `boot()`.

## Source

- [OnDemandLoader.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Loading/OnDemandLoader.php)
- [DependencyGraphCalculator.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Loading/DependencyGraphCalculator.php)
- [DependencyGraph.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Loading/DependencyGraph.php)
