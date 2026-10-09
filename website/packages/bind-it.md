# bind-it — The DI Container Engine

The `phpshots/bind-it` package provides the reflection-based dependency injection container that powers both `CoreContainer` and `ModuleContainer`. It is a lightweight, framework-independent DI engine with contextual bindings, extenders, and PSR-11 compliance.

## Why bind-it?

- **Reflection-based autowiring**: the container inspects constructor signatures and resolves dependencies automatically, even for unbound classes
- **Flexible bindings**: abstract interfaces to concrete implementations, via closures, callables, or class strings
- **Contextual bindings**: different implementations of the same interface depending on which class requests it
- **Singletons and factories**: manage lifecycle — app-lifetime instances or fresh instances per request
- **Extenders**: modify resolved instances after construction (decoration pattern)
- **Callbacks**: react to resolution events with `resolving()` and `rebinding()` hooks
- **PSR-11 compliance**: implements `Psr\Container\ContainerInterface` for standard interop

The kernel layers its scope-isolation rules on top of bind-it's core, so modules cannot escape to internals of other modules and the framework cannot be bypassed.

## Container API

The core Container class from bind-it provides reflection-based dependency injection. The kernel's CoreContainer extends it with additional methods like `instance()` for pre-built objects.

### Binding — multiple shapes

```php
$container = new Container();

// 1. Simple binding — class-string concrete
$container->bind(UserRepository::class, UserMySQLRepository::class);

// 2. Binding with a factory closure
$container->bind(DatabasePort::class, function ($container) {
    return new MySQLAdapter(config('database'));
});

// 3. Singleton — instantiate once, reuse forever
$container->singleton(TransactionManager::class, TransactionManager::class);

// 4. Conditional binding — only if not already bound
$container->bindIf(MailPort::class, SendgridMailAdapter::class);

// 5. Pre-built instance (CoreContainer only)
$core->instance(CachePort::class, $redisAdapter);
```

### Resolution

```php
// Fetch an instance — autowires constructor dependencies
$user = $container->make(UserService::class);

// PSR-11: has() and get()
if ($container->has(UserService::class)) {
    $user = $container->get(UserService::class);
}

// Check if a key is bound
$bound = $container->bound(UserService::class);
```

### Autowiring and optional dependencies

The container inspects constructor parameters using reflection. For each parameter:

1. If a type hint exists, the container attempts to resolve it
2. If the parameter has a default value and resolution fails, the default is used
3. If the parameter is required and cannot be resolved, an exception is thrown

```php
class InvoiceService
{
    // Both DatabasePort and TransactionManager are autowired.
    // TransactionManager is optional — if it cannot be resolved, its default (null) is used.
    public function __construct(
        private readonly DatabasePort $db,
        private readonly ?TransactionManager $txn = null,
    ) {}
}

$service = $container->make(InvoiceService::class);
// $txn is null if TransactionManager is unbound or cannot be resolved
```

**New in 1.17.1**: optional constructor dependencies (parameters with `= null` or other defaults) now receive their declared default when the container cannot resolve them, instead of throwing an exception. This allows graceful degradation when optional ports or services are not bound.

### Contextual bindings

A contextual binding lets you specify: "when class `A` requests interface `I`, give it implementation `X`; when class `B` requests `I`, give it `Y`."

```php
// Default: all requesters get MySQLRepository
$container->bind(UserRepository::class, MySQLRepository::class);

// But when InvoiceService requests UserRepository, use CacheWrapper instead
$container->when(InvoiceService::class)
    ->needs(UserRepository::class)
    ->give(CacheWrapper::class);

// Factory closure also works
$container->when(PaymentService::class)
    ->needs(PaymentGateway::class)
    ->give(function ($container) {
        return new StripeGateway(env('STRIPE_KEY'));
    });
```

The most specific binding wins: a contextual binding overrides the default for that requestor only.

### Extenders — decorate after construction

Extend a service to modify every instance after it is built:

```php
// Every DatabasePort gets wrapped with timing instrumentation
$container->extend(DatabasePort::class, function ($db, $container) {
    return new TimedDatabasePort($db);
});
```

Extenders run in the order registered, and chain together — the output of one becomes the input to the next.

### Lifecycle hooks — resolving() and rebinding()

React when an instance is resolved or rebound:

```php
// Fires every time a TransactionManager is resolved
$container->resolving(TransactionManager::class, function ($instance, $container) {
    // Log it, cache it, attach observers, etc.
});

// Fires when a binding is rebound after resolution
$container->rebinding(TransactionManager::class, function ($new, $container) {
    // Instances that hold the old binding can update references here
});
```

### Frozen containers

In the kernel, the `CoreContainer` is frozen after the Kernel materializes (first entry-point call):

```php
$core->freeze();        // Lock the container from further bindings
$core->isFrozen(): bool // Check if locked

// After freeze, bind() and singleton() throw LogicException
// This prevents accidental runtime modifications and forces configuration
// to happen during bootstrap, not mid-request
```

### Shared instances (singletons)

An instance is "shared" (singleton) if:
- It was bound with `singleton()`
- It was stored with `instance()`
- The container marks it as shared via internal `share()` call

```php
// Both calls return the SAME instance
$txn1 = $container->make(TransactionManager::class);
$txn2 = $container->make(TransactionManager::class);
// $txn1 === $txn2 is true
```

Non-singleton bindings return a fresh instance on each `make()` call.

### Method bindings

Call a method on a resolved instance with container-injected arguments:

```php
$container->bindMethod('send-mail', function ($mailer) {
    return $mailer->send(...);
});

$result = $container->callMethodBinding('send-mail', $mailerInstance);
```

This is a low-level tool; most code uses dependency injection directly instead.

## How the kernel uses bind-it

### CoreContainer — app-lifetime bindings

The `CoreContainer` extends `Container` and holds:
- Port implementations (DatabasePort, CachePort, etc.)
- App-lifetime singleton services (TransactionManager, EventBus, etc.)
- Frozen after materialize (first entry-point call) to prevent mid-request changes

```php
$core = new CoreContainer();
$core->instance(DatabasePort::class, new MySQLAdapter(...));
$core->singleton(TransactionManager::class, ...);
$core->freeze(); // Lock it from further changes
```

### ModuleContainer — request-scoped bindings

The `ModuleContainer` extends `Container` and adds scope enforcement:
- Holds module-scoped services, repositories, and gateways
- Falls back to the `CoreContainer` for unbound keys (ports + kernel services)
- Built fresh for every request and job by the `OnDemandLoader`, and dropped afterwards, so bindings never leak between requests

```php
// Inside a Provider::register(ModuleContainer $container)
$container->bindInternal(InvoiceRepository::class, ...); // only resolvable inside this module
$container->bind(InvoiceServiceContract::class, ...);    // public contract
```

`ModuleContainer::reset()` clears every binding and callback. The kernel never needs it because it never reuses a container; it is there for code that pools containers itself.

## Common patterns

### Registering a module's services

```php
class Provider implements ModuleContract
{
    public function register(ModuleContainer $container): void
    {
        // Internal — hidden from other modules
        $container->bindInternal(InvoiceRepository::class, fn($c) =>
            new InvoiceRepository($c->make(DatabasePort::class))
        );

        // Public — exported contract
        $container->bind(InvoiceServiceContract::class, fn($c) =>
            new InvoiceService(
                repository: $c->make(InvoiceRepository::class),
                transaction: $c->make(TransactionManager::class),
            )
        );
    }
}
```

### Conditional port binding

```php
// Use a real adapter in production, a fake in tests
if (env('APP_ENV') === 'testing') {
    $core->instance(DatabasePort::class, new FakeDatabasePort());
} else {
    $core->instance(DatabasePort::class, new MySQLAdapter(...));
}
```

### Lazy loading with closures

```php
// The closure is called only when the key is requested
$core->singleton(HeavyService::class, function ($container) {
    return new HeavyService(...); // Built only on first resolution
});
```

## Gotchas and pitfalls

::: danger Never use getInstance() or setInstance()
The kernel disables `Container::getInstance()` and `Container::setInstance()` by throwing `LogicException`. These methods are forbidden to prevent hidden global state that causes:
- Coroutine safety issues in Swoole (request isolation breaks)
- Debugging difficulty (state comes from nowhere)
- Testing complexity (global state must be reset)

Always inject the container as a constructor dependency instead.
:::

::: warning Frozen containers cannot be rebound
After `CoreContainer::freeze()`, attempting to call `bind()`, `singleton()`, or `instance()` throws `LogicException`. Freeze happens during `Kernel::materialize()` — the first entry-point call (HTTP, CLI, or worker). All bootstrap-time configuration must complete before materialize.

This is by design: it forces wiring to happen during startup, not mid-request.
:::

::: tip Contextual bindings require the requesting class
The container resolves contextual bindings by inspecting what CLASS requested the dependency. This only works if:
1. The requesting class is being built by the container (via `make()`)
2. The container can inspect its constructor signature

You cannot provide a contextual binding for an already-instantiated object requesting something; it must be part of a constructor dependency chain.
:::

## Source

- [modules/bind-it/](https://github.com/AlfaCode-Team/hkm-kernel/tree/main/modules/bind-it)
- [modules/bind-it/src/Container.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/modules/bind-it/src/Container.php)
- [modules/common-type-alias/](https://github.com/AlfaCode-Team/hkm-kernel/tree/main/modules/common-type-alias)
- [src/Kernel/Container/](https://github.com/AlfaCode-Team/hkm-kernel/tree/main/src/Kernel/Container)
