# The ModuleContract Interface

Every module's `Provider.php` implements `ModuleContract` to tell the kernel what it offers and what it needs. This interface is where you declare methods that describe your module's boundary — its domain, dependencies, and published contracts — along with hooks to wire its services and subscribe to events.

## The Five Methods

### `solves(): string`

Declares the single business domain this module owns. The kernel uses this as a unique identifier in the dependency graph.

```php
public function solves(): string
{
    return 'invoice.generation';
}
```

- Must match your `module.json` `"solves"` field exactly.
- Used by other modules to declare a dependency via [`requires()`](#requires-array).
- Must be unique across all installed modules; duplicates fail the boot.
- Conventionally dot-separated lowercase (`vendor.domain` or `domain.subdomain`).

### `requires(): array`

Lists the module domains this module depends on. The kernel wires these modules into your container before your `register()` method is called.

```php
public function requires(): array
{
    return ['database.query', 'view.rendering'];
}
```

- **Must mirror your `module.json` `"requires"` field exactly** — the manifest is the source of truth the kernel reads; this method is documentation.
- Each entry is a `solves()` domain from another registered module.
- An unknown domain (typo, or the plugin is not in `withModules([...])`) fails the boot with a descriptive message listing registered domains.
- Port interfaces (like `DatabasePort`) resolve via `CoreContainer` and are **not** listed here.
- Empty array `[]` means this module is standalone.

::: tip
Declaring `requires[]` is how you enforce hard dependencies. The boot fails loudly if a required module is missing, rather than failing deep inside a request when something tries to resolve an unbound contract.
:::

### `exposes(): array`

Lists the public contracts this module makes available to other modules. Only listed classes can be resolved by modules that declare this module in their `requires[]`.

```php
public function exposes(): array
{
    return [InvoiceServiceContract::class];
}
```

- Each entry must be a fully qualified class name or a short class name (if imported).
- These are **service contracts** you've defined in your `API/Contracts/` folder.
- Other modules access these only via the published interface; they cannot import your internal classes.
- Empty array `[]` means you expose nothing (your module is purely internal).

::: info
**Public vs. internal bindings:** In `register()`, you bind internal classes with `$container->bindInternal()` and published contracts with `$container->bind()`. A module consuming your contract only gets access to the published one; internal bindings throw `ScopeViolationException` if reached from outside.
:::

### `register(ModuleContainer $container): void`

Register DI bindings into your module's request-scoped container. This is called once per request when your module is loaded by the on-demand loader.

```php
public function register(ModuleContainer $container): void
{
    // Internal bindings — accessible only within this module
    $container->bindInternal(InvoiceRepository::class, fn($c) =>
        new InvoiceRepository(
            $c->make(DatabasePort::class),
            $c->make(Identity::class),
        )
    );

    // Public bindings — resolvable by modules that require this one
    $container->bind(InvoiceServiceContract::class, fn($c) =>
        new InvoiceService(
            repository:  $c->make(InvoiceRepository::class),
            transaction: $c->make(TransactionManager::class),
            collector:   $c->make(DomainEventCollector::class),
            eventBus:    $c->make(EventBus::class),
            identity:    $c->make(Identity::class),
        )
    );
}
```

**What goes here:**

- **Bind repositories** — they need `DatabasePort` and `Identity` to scope queries.
- **Bind services** — they orchestrate repositories and coordinate transactions.
- **Bind gateways** — they call vendor APIs and return value objects.
- Use `bindInternal()` for anything that should never leave your module (repositories, internal helpers).
- Use `bind()` only for the contracts listed in `exposes()`.

**What doesn't go here:**

- Controllers and request handlers (autowired on demand by the executor).
- Pipeline hooks and event listeners (registered in `boot()` instead).
- Global functions or constants (declared in `files[]` in `module.json`).

### `boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void`

Register pipeline hooks (stages that run on every request) and event subscriptions. Called after all required modules have completed their `register()` calls.

```php
public function boot(
    HttpPipeline   $http,
    CliPipeline    $cli,
    WorkerPipeline $worker,
    EventBus       $events,
): void {
    // Register a global pipeline hook
    $http->hook('after.security', RateLimiterStage::class, priority: 10);
    $http->hook('after.load', LocaleResolverStage::class, priority: 40);

    // Register a declarative route filter (modules declare which routes use it)
    $http->filter('my-filter', MyFilterStage::class);

    // Subscribe to an integration event
    $events->subscribe('payment.succeeded', PaymentSucceededListener::class);

    // Register a CLI command
    $cli->command(InvoiceGenerateCommand::class);
}
```

**What goes here:**

- `$http->hook(slot, StageClass, priority)` — run a stage on every HTTP request at a fixed position.
- `$http->filter(alias, StageClass)` — publish a route filter that routes opt into via `"filters": ["alias"]`.
- `$events->subscribe(eventName, ListenerClass)` — react to an integration event from another module.
- `$cli->command(CommandClass)` — register a CLI command.
- `$worker->hook()`, `$worker->filter()` — worker pipeline equivalents.

**Hook slots and priorities:**

| Slot | Position | When | Priority range |
|---|---|---|---|
| `after.security` | After identity is attached | First module opportunity | 10–99 |
| `after.load` | After required modules are loaded | Route filters run here | 40–99 |
| `after.execute` | Around `ExecuteStage` | The controller's response, on its way out | 80–99 |

Lower priority numbers run first (default `50`). The bands are a convention: by agreement the kernel keeps 1–9 and modules use 10–99, but `hook()` does not enforce them. To post-process responses, hook at `after.execute` and act on what `$next` returns. See [HTTP pipeline](/http/pipeline#hook-slots-and-priorities).

## Complete Provider Example

```php
<?php
declare(strict_types=1);

namespace InvoiceModule;

use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\{DomainEventCollector, EventBus};
use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use InvoiceModule\API\Contracts\InvoiceServiceContract;
use InvoiceModule\Application\Services\InvoiceService;
use InvoiceModule\Infrastructure\Persistence\InvoiceRepository;

class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'invoice.generation';
    }

    public function requires(): array
    {
        return []; // No hard dependencies
    }

    public function exposes(): array
    {
        return [InvoiceServiceContract::class];
    }

    public function register(ModuleContainer $container): void
    {
        $container->bindInternal(InvoiceRepository::class, fn($c) =>
            new InvoiceRepository(
                $c->make(DatabasePort::class),
                $c->make(Identity::class),
            )
        );

        $container->bind(InvoiceServiceContract::class, fn($c) =>
            new InvoiceService(
                repository:  $c->make(InvoiceRepository::class),
                transaction: $c->make(TransactionManager::class),
                collector:   $c->make(DomainEventCollector::class),
                eventBus:    $c->make(EventBus::class),
                identity:    $c->make(Identity::class),
            )
        );
    }

    public function boot(
        HttpPipeline   $http,
        CliPipeline    $cli,
        WorkerPipeline $worker,
        EventBus       $events,
    ): void {
        // No hooks or subscriptions for this simple module
    }
}
```

## Module Directory Structure

A module is a directory with a `Provider.php` file and a `module.json` manifest. The layout below is a convention, not a requirement — only `Provider.php` and `module.json` matter to the kernel.

```
InvoiceModule/
├── module.json                    ← Source of truth for kernel
├── Provider.php                   ← Implements ModuleContract
├── API/
│   ├── Contracts/
│   │   └── InvoiceServiceContract.php    ← Published interface
│   └── IntegrationEvents/
│       └── InvoiceCreatedIntegrationEvent.php
├── Domain/
│   ├── Entities/
│   │   └── Invoice.php
│   ├── ValueObjects/
│   │   └── InvoiceNumber.php
│   ├── Rules/
│   │   └── InvoiceValidation.php
│   └── Events/
│       └── InvoiceCreatedDomainEvent.php
├── Application/
│   └── Services/
│       └── InvoiceService.php       ← Implements the contract
├── Infrastructure/
│   ├── Persistence/
│   │   └── InvoiceRepository.php
│   ├── Gateways/
│   │   └── PaymentGateway.php
│   └── Http/
│       └── Controllers/
│           └── InvoiceController.php
├── config/
│   └── invoice.php
├── resources/
│   ├── views/
│   │   └── invoice-list.php
│   └── lang/
│       ├── en/
│       │   └── messages.php
│       └── fr/
│           └── messages.php
├── database/
│   └── migrations/
│       └── 2024_01_15_create_invoices_table.php
└── tests/
    └── Services/
        └── InvoiceServiceTest.php
```

**What the kernel reads:**

- `module.json` — all module metadata
- `Provider.php` — the ModuleContract implementation
- `config/*.php` — configuration files (auto-discovered, not declared)
- `files` field in `module.json` — PHP files to require at boot

**What the kernel does not read:**

- Controllers, services, repositories — autowired on demand from `Provider.php` bindings
- Views, languages, migrations — discovered by their respective systems

## Cross-Module Access Rules

Only access other modules through their **published contracts**, never their internals.

### Correct

```php
// In a service, inject the published contract
class OrderService
{
    public function __construct(
        private readonly InvoiceServiceContract $invoices,
    ) {}

    public function process(array $items): void
    {
        // Call only the public interface
        $invoice = $this->invoices->create(...);
    }
}
```

### Wrong

```php
// Do NOT import another module's internal classes
use InvoiceModule\Infrastructure\Persistence\InvoiceRepository;  // ✗

class OrderService
{
    public function __construct(
        private readonly InvoiceRepository $invoices,  // ✗
    ) {}
}
```

**Scope enforcement:** If you try to resolve an internal binding (one bound with `bindInternal()`) from outside its module, the container throws `ScopeViolationException`. This is a runtime check that prevents accidental coupling.

## Common Mistakes

### Mistake: Declaring dependencies in the wrong order

**Wrong:**

```php
public function requires(): array
{
    return ['payment.processing'];  // Typo — module's actual solves is 'payment.gateway'
}
```

The boot fails immediately with: `Unknown module.json requires[] entries — no registered module solves them`. Fix the spelling or add the missing plugin to `withModules([...])`.

### Mistake: Exposing internal classes

**Wrong:**

```php
public function exposes(): array
{
    return [InvoiceRepository::class];  // ✗ Repositories are internal
}
```

If another module tries to resolve your repository, they'll get it — but you've now coupled their code to your implementation. When you refactor the repository, their code breaks. Always expose a service contract instead.

### Mistake: Binding to the CoreContainer

**Wrong:**

```php
public function register(ModuleContainer $container): void
{
    $container->make(CoreContainer::class)
        ->bind(SomeService::class, fn() => new SomeService());  // ✗
}
```

The core container is app-lifetime (lives for the whole process). Binding request-scoped state (like the current user) there causes it to leak across requests in OpenSwoole, where workers handle many requests concurrently. Always bind into the `ModuleContainer` argument passed to `register()`.

### Mistake: Calling `requires()` with port classes

**Wrong:**

```php
public function requires(): array
{
    return [DatabasePort::class];  // ✗ Ports are not modules
}
```

Ports resolve via `CoreContainer` and are already available in your container. Never list them as `requires[]`.

### Mistake: Forgetting to mirror the manifest

**Wrong:**

```php
// module.json declares:
// "requires": ["database.query", "cache.management"]

public function requires(): array
{
    return ['database.query'];  // ✗ Forgot cache.management
}
```

The manifest is what the kernel actually reads and validates. The method is documentation — it must stay in sync. A discrepancy is not an error, but it's a red flag that the code and config have drifted.

## When to Use `register()` vs `boot()`

| Scenario | Use |
|---|---|
| Bind services, repositories, gateways | `register()` |
| Inject dependencies into a service | `register()` |
| Register a pipeline hook (runs on every request) | `boot()` |
| Register a route filter (routes opt in) | `boot()` |
| Subscribe to integration events | `boot()` |
| Register CLI commands | `boot()` |

**Key difference:** `register()` runs per-request and wires up DI. `boot()` runs once at kernel materialization and sets up the pipelines. A hook registered in `boot()` runs on every request; a binding in `register()` is resolved only when something asks for it.

## Source

- [`src/Kernel/Contracts/ModuleContract.php`](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Contracts/ModuleContract.php)
- [`templates/plugin/Provider.php`](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/templates/plugin/Provider.php)
- [`src/Kernel/Boot/Stages/CompileServiceManifestStage.php`](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Boot/Stages/CompileServiceManifestStage.php)
