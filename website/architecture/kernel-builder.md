# Kernel Builder

The `Kernel` class is the single entry point for bootstrapping an HKM application. It is a fluent builder that accepts configuration, validates everything at boot, and then materializes the pipelines on first use. This page documents every builder method, merge semantics, the build/materialize lifecycle, and the bootstrap pattern for multi-project builds.

## Overview: Build vs. Materialize

```php
$kernel = Kernel::configure()
    ->withBasePath('/app')
    ->withPorts([DatabasePort::class => new MySQLAdapter(...)])
    ->withModules([AuthModule::class, InvoiceModule::class])
    ->build();  // ← compile-only: validates config, reads manifests, no pipelines

// Later, on first use:
$response = $kernel->http()->handle($request);  // ← materialize: construct pipelines, wire modules, freeze core
```

- **`build()`** — compile-only. Runs the BootPipeline (validates config, compiles manifests). No pipelines are constructed; no modules are wired. Can be called safely multiple times in tests (it is idempotent once all input is the same).
- **`materialize()`** — called implicitly on the first entry-point call (`http()`, `cli()`, `workerLoop()`, or `container()`). Constructs the three pipelines, wires every module's `register()` and `boot()`, binds kernel services, and freezes the core container. Runs exactly once per kernel instance.

## Builder Methods

### Configuration

#### `withBasePath(string $path): self`

Set the application base path. Used to resolve `var/`, `userdata/`, and relative paths. Defaults to the working directory if omitted.

```php
$kernel->withBasePath('/home/app');
```

Merge: **replaces** the previous value.

#### `withProjectPath(string $path): self`

Set the active project path (e.g. `projects/admin`). Per-project runtime state (`var/`, `userdata/`) is isolated under this path. When omitted, these fall back to the base path.

Used in multi-project builds where each project has its own configuration and cache.

```php
$kernel->withProjectPath('projects/admin');
```

Merge: **replaces** the previous value.

### Ports (Infrastructure)

#### `withPorts(array<class-string, object|\Closure> $bindings): self`

Bind port implementations. A port interface (e.g. `DatabasePort`) maps to an adapter (e.g. `MySQLAdapter` instance or a lazy factory closure).

```php
->withPorts([
    DatabasePort::class => new MySQLAdapter(config('database')),
    CachePort::class    => new RedisAdapter(config('cache')),
    QueuePort::class    => fn(CoreContainer $c) => new RedisQueueAdapter(config('jobs')),
])
```

**Lazy factories:**
- A `Closure(CoreContainer): object` is resolved (and any connection opened) only on first use.
- A pre-built object is bound eagerly as-is.
- Use closures for expensive resources (database connections, Redis clients).

Merge: **merges** with previous bindings (later calls override earlier ones for the same key). Allows a base bootstrap to bind standard ports and child projects to override them:

```php
$base = Kernel::configure()->withPorts([DatabasePort::class => $standardDb]);
$admin = clone $base; // In real use, store the builder
$admin->withPorts([DatabasePort::class => $adminTenantDb])->build();
```

### Security

#### `withSecurity(array $layers): self`

Register security gateway layers. Order matters: cheapest first (quick rejections first, expensive ones last). At least one layer is **required**: with none, `BindSecurityStage` fails the boot ("No security layers configured").

```php
->withSecurity([
    new CsrfTokenLayer(...),
    new CustomApiKeyLayer(...),  // optional; a plugin may add this
])
```

The kernel ships the `CsrfTokenLayer`. JWT and API-key validation are provided by an Auth module.

Merge: **appends** to the previous list. Inherited projects keep base layers and add their own.

#### `withWorkerSecret(string $secret): self`

Require every dequeued job payload to be HMAC-signed with this key.

```php
->withWorkerSecret(env('JOB_SIGNING_SECRET'))
```

Defaults to the `JOB_SIGNING_SECRET` env var if you call this method; stays OFF when the env var is unset. Deliberately does NOT fall back to `APP_KEY` — turning on job verification is a two-sided change (both producer and consumer must be updated), and silently rejecting existing jobs in the queue is the wrong default.

Merge: **replaces** the previous value.

#### `withErrorPipeline(ErrorPipeline|callable|\Closure $pipeline): self`

Configure error handling (where exceptions are logged, who is notified, etc.).

```php
->withErrorPipeline(
    ErrorPipeline::notifiers([
        new SlackNotifier(env('SLACK_ERRORS')),
        new MailNotifier(config('support.email')),
        new DatabaseErrorLogger(),
    ])
    ->fallback(new FileNotifier(logs_path('errors.log')))
    ->rules(['critical' => ['slack', 'mail', 'db', 'file'], 'warning' => ['db', 'file']])
)
```

Or pass a callable that receives the ports array and returns an ErrorPipeline (useful when the pipeline depends on a port):

```php
->withErrorPipeline(fn($ports) => new ErrorPipeline(...))
```

Merge: **replaces** the previous ErrorPipeline.

### Modules

#### `withModules(array<class-string> $modules): self`

Register modules to load. Provider class-strings, passed as a list.

```php
->withModules([
    AuthModule::class,
    InvoiceModule::class,
    PaymentModule::class,
])
```

Each module's `module.json` declares what it `solves`, what it `requires`, and what it `exposes`. The kernel reads these and validates the dependency graph at boot.

Merge: **appends and de-duplicates** while preserving first-seen order. Allows a base bootstrap to register shared modules and child projects to add their own:

```php
$base = Kernel::configure()->withModules([AuthModule::class]);
$admin = clone $base;
$admin->withModules([InvoiceModule::class])->build();  // [Auth, Invoice]
```

#### `withEssentialModules(array $modules): self`

Mark modules as essential: they are registered into every request-scoped container regardless of the route's dependency graph. This is what makes services always available without depending on the route graph.

```php
->withEssentialModules(['tenancy.routing', 'user.management'])
```

Accepts **both provider class-strings AND module domains** (the `solves` value). Domain entries must name modules already registered in `withModules()` and are resolved to their providers at build time — an unknown domain fails the boot with a descriptive error.

**Use sparingly.** This opts those modules out of on-demand loading and adds their registration cost (plus their `requires[]` graph) to every request.

**When to use:**
- Session/cookie management (sessions must be available on every page).
- Tenant scoping (every request needs to know which tenant it belongs to).
- User management for role/permission checks.

**When NOT to use:**
- Outbound HTTP (only some routes send emails).
- Payment processing (only invoice creation pays for it).
- Batch jobs (they have their own module loading).

Merge: **appends and de-duplicates**. Class entries are auto-added to `withModules()` if they are class-strings (contain a backslash).

### Routes (Project-level)

#### `withRoutes(array $routes): self`

Declare project-level routes. These are routes that belong to the project wiring layer (thin orchestration) rather than to a module.

```php
->withRoutes([
    ['method' => 'GET',  'path' => '/',          'handler' => 'Shop\\Http\\HomeController@index'],
    ['method' => 'POST', 'path' => '/contact',   'handler' => 'Shop\\Http\\ContactController@store'],
])
```

Routes are compiled into the route-manifest under the synthetic `__project__` scope (which has no module dependency graph). The controller is resolved and its ports autowired, but no module's `register()` runs for it.

- Use this for thin project pages that orchestrate published module contracts.
- Keep real domain logic inside modules.

Merge: **appends**, keyed by method + domain (or subdomain) + path: a later route with the same key **replaces** the earlier one, so a child project can redefine a route its base builder declared, while routes with different keys accumulate.

#### `withRouteGroups(array $source): self`

Declare project route groups and source-wide route defaults. A group states once what would otherwise repeat on every route inside it.

```php
->withRouteGroups([
    'prefix' => '/api',
    'filters' => ['auth'],
    'groups' => [
        [
            'domain' => 'organizer.example.com',
            'prefix' => '/dashboard',
            'name' => 'organizer.',
            'routes' => [
                ['method' => 'GET', 'path' => '', 'handler' => 'Organizer\\DashboardController@index']
            ]
        ]
    ]
])
```

**Inheritance:**
- `prefix` is concatenated outward-in.
- `name` is concatenated as a prefix onto each route's name.
- `filters` are merged and de-duplicated by alias (a nested group's filter overrides an outer one).
- `domain` / `subdomain` are most-specific-first (inner overrides outer).

**Domain groups:** Because `domain` is part of the compiled route KEY, two domains may each declare `GET /` with different handlers. This is how one project serves several brands without forking.

Merge: **shallow merge** — `groups` arrays accumulate so a base builder's groups stay.

#### `withProjectDomains(array $domains): self`

Declare the hosts this project serves (normally from `proj.json` `"domains"`). Used to validate route domain groups at boot: grouping routes under a host the project never registered produces routes nothing can reach, so the compiler fails with a descriptive error.

```php
->withProjectDomains(['example.com', 'shop.example.com', '*.tenants.example.com'])
```

A bare `"subdomain"` group (no domain attached) is never checked — it answers on that label across every domain.

Merge: **appends and de-duplicates**.

### Route Policy (Vetoing Plugin Routes)

#### `withRoutePolicy(array $disable): self`

Declare routes a plugin exposes that the project chooses NOT to use. A plugin owns its routes, but the project deploying it stays the final authority.

```php
->withRoutePolicy([
    'GET /register',       // disable one plugin route (method + path)
    'oauth.server',        // disable every route the oauth.server module solves()
    'GET /mail/demo/*',    // disable a whole prefix
])
```

**Spec forms:**
- `"METHOD /path"` — one exact plugin route.
- `"oauth.server"` — all routes the `oauth.server` module owns (its entire `solves()` domain).
- `"METHOD /prefix/*"` — every route matching the prefix, present and future.

**Validation:** A spec matching no plugin route fails the build (prevents typos).

**Timing:** Applied to plugin routes BEFORE project routes compile, so a project can disable a plugin route and declare its own on the freed key.

Merge: **appends and de-duplicates**.

#### `withRouteAllowPolicy(array $only): self`

Invert the disable policy: "expose nothing except these" instead of "expose everything except these".

```php
->withRouteAllowPolicy([
    'POST /oauth/token',
    'GET /oauth/jwks',
    'auth.identity',        // all routes the auth.identity module solves()
    'GET /account/*',
])
```

**When the list is non-empty:** EVERY plugin route must match a spec or it is dropped. Lets you adopt new plugins confidently — nothing they publish reaches your users without review.

**When the list is EMPTY:** No allowlist (every plugin route stays). This is the default — an empty list means "no filter" rather than "allow nothing" (the latter would silently delete your application).

**Composition:** Applied BEFORE the disable policy, so the two work together: allow a module's domain, then subtract the handful of its routes you do not want.

**No validation:** Unlike a disable spec, an allow spec that matches nothing does NOT fail the boot. Naming routes from a plugin you have not enabled yet is normal and should not break your build.

Merge: **appends and de-duplicates**.

## Lifecycle: Build

When you call `build()`:

1. **Set paths** — calls `Paths::setBase()` and `Paths::setProject()` from your `withBasePath()` and `withProjectPath()` calls.

2. **Resolve error pipeline** — if you passed a callable to `withErrorPipeline()`, invoke it with the ports array to get the final `ErrorPipeline`.

3. **Construct CoreContainer** — a fresh container where ports and kernel services are bound.

4. **Bind ports:**
   - Closures → `singleton()` (lazy, resolved on first use).
   - Pre-built objects → `instance()` (eager, stored as-is).

5. **Run BootPipeline:**
   - Validation stages: check config, detect conflicts, detect cycles.
   - Compilation stages: read every `module.json` and `config/*.php`, write manifests to `var/cache/manifests/`.
   - Process stages: load module helper files (require_once).
   - Validation stages: verify ports, verify security layers.

6. **Check BootStamp** — if `BOOT_CACHE=1` and the manifest is current, skip compilation (runValidationOnly instead).

7. **Resolve essential modules** — domain entries in `withEssentialModules()` are resolved to their provider classes (only after the manifest is built, so `module.json` is available).

8. **Write BootStamp** — if caching is on, record the manifest file signatures and builder inputs.

9. **Set `$built = true`** — the kernel is ready for entry points.

## Lifecycle: Materialize

When you call an entry point (`http()`, `cli()`, `workerLoop()`, or `container()`) for the first time:

1. **Check if already materialized** — if so, return the existing pipeline/container (materialize runs at most once).

2. **Set RuntimeMode** — `Http`, `Cli`, or `Worker` depending on which entry point was called.

3. **Construct EventBus** — the long-lived event dispatcher (subscriptions registered by modules during boot).

4. **Construct WorkerPipeline** — the job-execution pipeline.

5. **Construct HttpPipeline** — the HTTP request-handling pipeline (with SecurityGateway).

6. **Construct CliPipeline** — the CLI command-execution pipeline.

7. **Construct WorkerLoop** — the background-job worker loop.

8. **Bind configuration** — the compiled `ConfigRepository` (from config-manifest.php) into the core container.

9. **Bind kernel services** — `EventBus`, pipelines, `UrlGenerator`, `Scheduler` (all singletons).

10. **Register scheduler commands** — `schedule:list` and `schedule:run`.

11. **Wire modules:**
    - For each module in `withModules()`, call `new ProviderClass()` and then `provider->boot($http, $cli, $worker, $eventBus)`.
    - Modules register their hooks and event subscriptions on the live pipeline instances.

12. **Freeze CoreContainer** — call `freeze()` so no new bindings can be registered. Any write now throws `LogicException`.

13. **Set `$materialized = true`** — indicate we are ready to handle requests.

## Entry Points

All entry points call `ensureBuilt()` (throw if build was not called), then call `materialize()` with the appropriate `RuntimeMode`.

#### `http(): HttpPipeline`

Materialize for `RuntimeMode::Http`. Returns the HTTP pipeline, ready to call `handle(Request)`.

```php
$pipeline = $kernel->http();
$response = $pipeline->handle($request);
```

#### `cli(): CliPipeline`

Materialize for `RuntimeMode::Cli`. Returns the CLI pipeline, ready to call `run(argv)`.

```php
$cli = $kernel->cli();
$exitCode = $cli->run(['hkm', 'list']);   // argv[0] is the script name
```

#### `workerLoop(): WorkerLoop`

Materialize for `RuntimeMode::Worker`. Returns the worker loop, ready to call `run($puller, $maxIterations)`.

```php
$loop = $kernel->workerLoop();
$loop->run(fn() => $queue->pop(), maxIterations: 0);  // 0 = infinite
```

#### `container(): CoreContainer`

Materialize for `RuntimeMode::Cli` (the safest neutral choice). Returns the core container, ready for dependency resolution.

Used in tests and tooling that need to reach kernel services without driving a full entry point.

```php
$container = $kernel->container();
$eventBus = $container->make(EventBus::class);
```

#### `config(): ConfigRepository`

Return the compiled configuration (from `config-manifest.php`), available immediately after `build()`.

Does NOT materialize the kernel. Used by bootstrap code and tooling that need to read configuration without standing up the HTTP/CLI/worker surfaces.

```php
$kernel = $kernel->build();
$dbConfig = $kernel->config()->get('database');  // safe before any entry point
$serviceUrl = $kernel->config()->get('api.external.url');
```

The same `ConfigRepository` instance is bound into the core container during `materialize()`, so modules can type-hint it and resolve it via the core container.

## RuntimeMode

An enum that tracks which surface the kernel materialized for:

```php
enum RuntimeMode: string {
    case Http   = 'http';
    case Cli    = 'cli';
    case Worker = 'worker';
}
```

Available via `$kernel->mode()` after the first entry point call (null until then).

```php
if ($kernel->mode() === RuntimeMode::Http) {
    // We are serving HTTP requests
}
```

## Freezing the CoreContainer

The core container is frozen at the end of `materialize()`, after every module's `boot()` has completed and all hooks/subscriptions are registered. After that:

- `freeze()` is called.
- `isFrozen()` returns true.
- Any call to `bind()`, `singleton()`, `instance()`, or `extend()` throws `LogicException`.

**Why:** Prevents request handlers from accidentally (or maliciously) registering new bindings into the shared app-lifetime container. Under OpenSwoole, a binding registered in one coroutine would leak into all others. Under FPM, it would persist across requests in unexpected ways.

**When does this matter?**
- A middleware or stage that tries to rebind a port throws an error.
- A background job that wants to override a binding cannot (it receives a fresh `ModuleContainer` instead).
- Testing code that tries to rebind something after `build()` fails clearly.

## Bootstrap Pattern: Single Project

```php
<?php
// app/bootstrap/app.php
use Kernel;

return Kernel::configure()
    ->withBasePath(dirname(__DIR__))
    ->withPorts([
        DatabasePort::class => new MySQLAdapter(config('database')),
        CachePort::class    => new RedisAdapter(config('cache')),
    ])
    ->withSecurity([new CsrfTokenLayer(...)])
    ->withErrorPipeline(...)
    ->withModules([AuthModule::class, InvoiceModule::class])
    ->build();
```

## Bootstrap Pattern: Multi-Project

Each project inherits a base builder and adds its own config:

```php
<?php
// app/bootstrap/base.php — shared base, no build()
return Kernel::configure()
    ->withBasePath(dirname(__DIR__))
    ->withPorts([DatabasePort::class => new MySQLAdapter(config('database'))])
    ->withModules([AuthModule::class]);
    // NOT calling build() here

// projects/admin/bootstrap/app.php
$builder = require __DIR__ . '/../../../app/bootstrap/base.php';

return $builder
    ->withProjectPath(dirname(__DIR__))
    ->withModules([AdminModule::class])
    ->build();

// projects/api/bootstrap/app.php
$builder = require __DIR__ . '/../../../app/bootstrap/base.php';

return $builder
    ->withProjectPath(dirname(__DIR__))
    ->withModules([ApiModule::class])
    ->build();
```

Each call to `build()` compiles manifests with the project-specific routes, domains, and modules.

## Common Mistakes

### Calling withPorts() After build()

```php
$kernel = $kernel->build();
$kernel->withPorts([LoggerPort::class => $logger]);  // ✗ too late, core is frozen
```

All port bindings must be declared before `build()`. If you need to swap a port per-request, rebind it into a fresh `ModuleContainer` inside an `after.load` stage instead.

### Building Without Calling an Entry Point

```php
$kernel = $kernel->build();
// ... but never call $kernel->http() or $kernel->cli()
// Pipelines are not constructed; modules are not wired
```

The kernel defers pipeline construction and module wiring to the first entry point. Call an entry point before you need the pipelines.

### Storing the Materialized Kernel Across Requests (Swoole)

```php
$kernel = null;

$server->on('start', function() use (&$kernel) {
    $kernel = Kernel::configure()->...->build();  // ✓
});

$server->on('request', function($req, $res) use ($kernel) {
    $response = $kernel->http()->handle(...);      // ✓ (materialize once)
    $kernel->requestTeardown();                    // ✓ (though currently a no-op)
    $res->end($response->body());
});
```

This is correct. The kernel is materialized once per worker, and each request gets a fresh `ModuleContainer` inside `LoadStage`.

## Source

- [Kernel.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Kernel.php) — complete builder implementation
- [RuntimeMode.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/RuntimeMode.php)
