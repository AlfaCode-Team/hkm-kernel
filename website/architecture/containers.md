# Containers: CoreContainer and ModuleContainer

HKM Kernel uses two DI containers with distinct lifetimes and purposes: `CoreContainer` for app-lifetime bindings and `ModuleContainer` for request-scoped bindings. Both extend the `bind-it` container for reflection-based autowiring, with the `ModuleContainer` adding scope isolation enforcement. This page covers their API, lifecycle, and OpenSwoole safety.

## CoreContainer: App-Lifetime

The core container is constructed during `Kernel::build()` and exists for the lifetime of the process. It holds:

- **Ports** — adapters bound by `Kernel::withPorts()` (DatabasePort → MySQLAdapter, etc.).
- **Kernel services** — long-lived objects like EventBus, pipelines, ErrorPipeline (bound during materialize).
- **Shared kernel utilities** — UrlGenerator, Scheduler (lazy singletons).

Every request-scoped ModuleContainer delegates to the core when a binding is not found locally (the core is its fallback).

### API

#### `instance(string $abstract, object $instance): void`

Bind a pre-built object eagerly. Used for ports and kernel services.

```php
$core->instance(DatabasePort::class, $mysqlAdapter);
$core->instance(CachePort::class, $redisAdapter);
```

Must be called before materialize. After that, throws `LogicException`.

#### `bind(string $abstract, Closure|string|null $concrete = null, bool $shared = false): void`

Bind a factory (lazy or not). A closure is resolved (and cached if `$shared = true`) on first use.

```php
$core->bind(MyService::class, fn($c) => new MyService($c->make(DatabasePort::class)));
```

Must be called before materialize.

#### `singleton(string $abstract, Closure|string|null $concrete = null): void`

Shorthand for `bind(..., shared: true)`. Resolved once, cached for the lifetime of the core.

```php
$core->singleton(UrlGenerator::class, fn() => UrlGenerator::fromManifest(...));
```

#### `extend(string $abstract, Closure $callback): void`

Wrap an existing binding with decorators.

```php
$core->extend(MailPort::class, fn($mail, $c) => new LoggingMailAdapter($mail));
```

#### `make(string $abstract, array $parameters = []): mixed`

Resolve and return an instance. Uses reflection-based autowiring for concrete classes.

```php
$db = $core->make(DatabasePort::class);
$service = $core->make(InvoiceService::class);  // autowired
```

#### `has(string $id): bool`

Check if a binding or concrete class is resolvable.

```php
if ($core->has(CachePort::class)) {
    $cache = $core->make(CachePort::class);
}
```

#### `freeze(): void`

Lock the container against further registration. Called by `Kernel::materialize()` after every module's `boot()` has completed.

After this, any call to `bind()`, `singleton()`, `instance()`, or `extend()` throws `LogicException`.

#### `isFrozen(): bool`

Check whether the container is frozen.

```php
if ($core->isFrozen()) {
    // No new bindings may be registered
}
```

### Freezing: Why and When

**Why it matters:**
- Under **OpenSwoole/Swoole**, a binding registered in one coroutine would leak into all others (shared app-lifetime state).
- Under **PHP-FPM**, a binding registered in one request would persist into the next (unexpected shared state across requests).

**When it happens:**
- Materialize calls `freeze()` after every module's `boot()` completes and all pipelines are wired.
- A request handler or test that tries to bind something now fails with a clear error.

**Error message:**
```
CoreContainer::bind() was called after the kernel froze the container.
Do not register bindings into the core (app-lifetime) container during a request.
```

### Disabled: getInstance() and setInstance()

Both are disabled and throw `LogicException`:

```php
CoreContainer::getInstance();  // ✗ throws
CoreContainer::setInstance($c);  // ✗ throws
```

Why: A global container singleton is unsafe under OpenSwoole (shared across coroutines) and incorrect under FPM (persists across requests). Inject the container via:
- `Kernel::container()` (for tests and CLI tooling).
- Constructor injection into a Provider's `register()` method.

## ModuleContainer: Request-Scoped

A fresh `ModuleContainer` is created for every request (or every job) and discarded when the request ends. It holds request-scoped bindings from every module that is loaded for that request, plus request-scoped kernel services (Identity, TransactionManager, DomainEventCollector).

### Lifetime

1. **OnDemandLoader** creates a fresh `ModuleContainer` per request/job.
2. Each loaded module's `Provider::register()` is called with the container as the active scope.
3. The controller is resolved and executed.
4. Request ends → the container is dropped and garbage-collected. The kernel never reuses a `ModuleContainer`, under FPM or OpenSwoole alike, so nothing needs to call `reset()`.
5. Zero state leaks to the next request.

### API (Public Contract)

Most methods are inherited from the bind-it `Container`, with a few additions for scope enforcement.

#### `bind(string $abstract, Closure|string|null $concrete = null, bool $shared = false): void`

Bind a public contract (resolvable by any module that requires it).

```php
// Inside PaymentModule::register()
$container->bind(PaymentServiceContract::class, fn($c) =>
    new PaymentService($c->make(PaymentGateway::class))
);
```

Automatically threads the current scope so the binding is marked as public (resolvable from anywhere).

#### `singleton(string $abstract, Closure|string|null $concrete = null): void`

Bind a public singleton (resolved once, shared within the request).

```php
$container->singleton(PaymentGateway::class, fn($c) => new PaymentGateway(...));
```

#### `instance(string $abstract, object $instance): void`

Bind a pre-built instance (public).

```php
$container->instance(PaymentGateway::class, $gateway);
```

Mirrors `CoreContainer::instance()` but threads the current scope so the binding is public.

#### `bindInternal(string $abstract, Closure $factory): void`

Bind an INTERNAL binding — only resolvable from within the owning module's scope. Throws `ScopeViolationException` if resolved from another scope.

```php
// Inside PaymentModule::register()
$container->bindInternal(PaymentRepository::class, fn($c) =>
    new PaymentRepository($c->make(DatabasePort::class), ...)
);
```

Always a singleton within the request (internal bindings are never transient).

**Used for:** hiding module implementation details (repositories, internal services).

#### `setScope(string $scope): void`

Set the current module domain for bindings. Called by `OnDemandLoader` before each `register()` call.

```php
// Inside OnDemandLoader::loadWithIdentity()
$container->setScope('invoice.generation');
$provider->register($container);  // bindings registered now are scoped to 'invoice.generation'
```

#### `make(string $abstract, array $parameters = []): mixed`

Resolve a binding, enforcing scope isolation.

Checks:
1. If the binding is `internal` and the caller scope does not match the binding's scope, throw `ScopeViolationException`.
2. If the binding is in this module, thread its scope so nested `make()` calls (dependencies) inherit it.
3. Delegate to the CoreContainer if not found locally.
4. Autowire a concrete class if it exists.
5. Throw `EntryNotFoundException` if nothing matches.

```php
// Inside an InvoiceModule service
$repo = $container->make(InvoiceRepository::class);  // ✓ internal, same scope
$payment = $container->make(PaymentServiceContract::class);  // ✓ public, cross-module

// From PaymentModule
$repo = $container->make(InvoiceRepository::class);  // ✗ throws ScopeViolationException
```

#### `makeInScope(string $abstract, string $scope): mixed`

Resolve an entry on behalf of an explicit caller scope. Used by `ExecuteStage` so a module entry point (controller) can reach its own internal bindings.

```php
// Inside ExecuteStage (HTTP pipeline)
$controller = $container->makeInScope(
    'InvoiceModule\\Http\\Controllers\\InvoiceController',
    'invoice.generation'  // scope of the route's owning module
);
// The controller's constructor can now autowire InvoiceRepository (internal to invoice.generation)
```

#### `has(string $id): bool`

Check if a binding is resolvable (from this module or the core).

```php
if ($container->has(PaymentServiceContract::class)) {
    $payment = $container->make(PaymentServiceContract::class);
}
```

### Scope Isolation at Runtime

The container tracks which scope registered each binding:

```php
private array $bindingScope = [];  // abstract => scope domain
private array $internal = [];      // abstract => is internal?
```

When a binding is resolved, the resolver's scope is compared against the binding's owning scope:

```php
// Inside ModuleContainer::make()
if (($this->internal[$resolved] ?? false) && $caller !== ($this->bindingScope[$resolved] ?? '')) {
    throw new ScopeViolationException(
        "Cannot resolve internal [{$abstract}] from scope [" . ($caller ?: 'kernel') . "].\n"
        . 'It is internal to scope [' . ($this->bindingScope[$resolved] ?? '') . "].\n"
        . 'Use the module\'s published contract from API/Contracts/ instead.'
    );
}
```

**The caller scope is threaded automatically:**
- When a binding starts resolving, its owning scope is pushed onto a resolution stack.
- Nested `make()` calls during that resolution inherit the scope from the stack.
- When resolution completes, the scope is popped.

This enforces the rule: *a module's internal bindings are accessible only from within that module*.

### Default Parameter Fallback

A constructor parameter with a default value receives it if the binding is unbound:

```php
final class InvoiceService {
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly ?MailPort $mail = null,  // ← optional
    ) {}
}
```

If `MailPort` is unbound, the service receives `null` instead of an exception. If `InvoiceRepository` is unbound, the exception is thrown (required).

**Why:** Modules often depend on optional cross-cutting concerns (logging, caching) that may not be loaded in a particular request.

### Disabled: getInstance() and setInstance()

Both throw `LogicException`:

```php
ModuleContainer::getInstance();  // ✗ throws
ModuleContainer::setInstance($c);  // ✗ throws
```

Why: Request-scoped state must never be stored as a global singleton. It would leak Identity, transaction state, and module bindings across requests under OpenSwoole.

### Lifecycle: reset()

Under standard PHP-FPM/CLI, the container is garbage-collected when the request ends. Under **OpenSwoole/Swoole**, you may want to pool or reuse container instances. Call `reset()` before reuse:

```php
public function reset(): void
```

Clears all internal state (bindings, resolved instances, callbacks) so the container can be reused safely. The kernel's default design creates a fresh container per request (via `OnDemandLoader`), so this is a safety escape-hatch rather than required.

## Relationship: Core ↔ Module

```
CoreContainer (app-lifetime, frozen)
    ↑ fallback / delegation
    │
ModuleContainer (request-scoped, fresh per request)
    ├─ public bindings (resolvable by any module)
    ├─ internal bindings (scoped to owning module)
    └─ request-scoped kernel services
         (Identity, TransactionManager, EventBus)
```

**How delegation works:**
1. Module container tries to resolve the binding locally.
2. If not found, delegates to the core container.
3. If the core has it, returns the value.
4. If not found anywhere, throws `EntryNotFoundException` (or autowires a concrete class).

**What lives where:**
- **Core:** Ports (DatabasePort, CachePort, …), kernel services (EventBus, pipelines, UrlGenerator, Scheduler, ErrorPipeline), configuration.
- **Module:** Services (public and internal), repositories, gateways, domain entities.
- **Kernel services (request-scoped):** Identity, TransactionManager, DomainEventCollector, EventBus rebind.

## Common Patterns

### Opt-In Cross-Module Dependency

Module A depends on a service from Module B only when explicitly loaded:

```php
// Module A's module.json
"requires": ["invoice.generation"]  // load Module B when Module A is needed

// Module A's Provider::register()
$container->bind(MyService::class, fn($c) =>
    new MyService(
        $c->make(InvoiceServiceContract::class)  // ✓ cross-module, public contract
    )
);
```

### Request-Scoped Infrastructure Override

Override a core port for a single request (e.g. tenant-specific database):

```php
// Inside an after.load hook
$request->container()->instance(DatabasePort::class, $tenantDb);
```

The module container now uses `$tenantDb` for the rest of the request, while the core's binding stays unchanged (used by other requests).

### Lazy Service Construction

Use a closure to defer expensive work until first use:

```php
$container->singleton(CachableService::class, fn($c) =>
    new CachableService($c->make(CachePort::class))
);
```

The service is built only on first resolution, not when `register()` runs.

## OpenSwoole Safety

The two-container design is essential for OpenSwoole worker safety:

1. **CoreContainer is frozen** — no mutations during requests.
2. **ModuleContainer is request-scoped** — fresh per coroutine, garbage-collected immediately.
3. **No static properties** — request-scoped classes never store state in statics (would leak across coroutines).
4. **No global singletons** — `getInstance()` / `setInstance()` are disabled.

A request-scoped binding stored as a static or global singleton would leak Identity, transaction state, and module bindings into subsequent requests. The framework prevents this with structure, not just convention.

## Pitfalls

**Storing a ModuleContainer:**
```php
class MyCache {
    private $container;
    public function __construct(ModuleContainer $container) {
        $this->container = $container;  // ✗ stale after request
    }
}
```

The container is request-scoped. Store what you need from it, not the container itself.

**Trying to rebind a core port:**
```php
// ✗ throws LogicException (core is frozen)
$core->instance(DatabasePort::class, $newAdapter);
```

Rebind it into the module container instead (via an after.load hook).

**Assuming autowiring works for abstract types:**
```php
// ✗ throws EntryNotFoundException
class MyService {
    public function __construct(SomeInterfaceContract $dep) {}  // not bound
}
```

Explicitly bind the interface to an implementation in `register()`.

## Extending Containers: The bind-it Foundation

Both containers extend `PHPShots\Common\Container`, which provides:

- **Reflection-based autowiring** — constructor parameter types are auto-resolved.
- **Contextual bindings** — bind different implementations for different contexts.
- **Extenders** — wrap existing bindings with decorators.
- **Resolving callbacks** — hooks that fire when a type is resolved.
- **PSR-11 compliance** — `get()`, `has()` interface.

See the [bind-it documentation](/packages/bind-it) for complete API reference.

## Source

- [CoreContainer.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Container/CoreContainer.php)
- [ModuleContainer.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Container/ModuleContainer.php)
- [EntryNotFoundException.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Container/EntryNotFoundException.php)
