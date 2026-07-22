# HKM Kernel — Module Layer

> A module is a **self-describing, self-contained bounded context**. It declares everything
> the kernel needs to know in `module.json`. The kernel reads only the manifest — never
> the module's PHP code directly.

---

## Module Identity Rules

| Rule | Detail |
|---|---|
| One module = one domain | `solves` field declares a single domain string. No exceptions. |
| Name is unique | Two modules with the same `solves` value causes boot failure. |
| All dependencies declared | Every contract the module needs must be in `requires[]`. |
| All exports declared | Every contract the module provides must be in `exposes[]`. |
| All config declared | Every env var the module reads must be in `config[]`. |
| All routes declared | Routes live in `module.json`, never in PHP route files. |

---

## module.json — Complete Annotated Schema

```json
{
  "name":    "invoice",          // kebab-case, unique across all installed modules
  "version": "1.0.0",            // semver — used for conflict detection
  "solves":  "invoice.generation", // dot-notation domain string — UNIQUE in the system

  "type": "module",              // "module" | "job" | "command"

  "requires": [                  // Contracts this module needs from other modules
    "database.query",            // → another module's solves() value
    "pdf.generation"
  ],

  "exposes": [                   // Contracts this module makes available to others
    "InvoiceServiceContract"     // → fully qualified or short class name
  ],

  "routes": [                    // HTTP routes — compiled into route-manifest.php
    // Optional "filters": [...] declares route filters by alias (run by
    // RouteFilterStage). String or list; "alias:arg1,arg2" passes args.
    { "method": "GET",    "path": "/api/invoices",      "handler": "InvoiceController@index"   },
    { "method": "POST",   "path": "/api/invoices",      "handler": "InvoiceController@create", "filters": ["auth", "throttle:60,1"] },
    { "method": "GET",    "path": "/api/invoices/{id}", "handler": "InvoiceController@show"    },
    { "method": "PUT",    "path": "/api/invoices/{id}", "handler": "InvoiceController@update"  },
    { "method": "DELETE", "path": "/api/invoices/{id}", "handler": "InvoiceController@destroy" }
  ],

  "emits": [                     // Integration events this module dispatches
    "invoice.created",
    "invoice.paid"
  ],

  "listens": [                   // Integration events this module subscribes to
    "payment.succeeded"
  ],

  "config": [                    // Environment variables this module reads
    "INVOICE_CURRENCY",                                            // required string
    { "key": "INVOICE_TAX_RATE", "type": "float", "required": false } // optional float
  ]
}
```

---

## ModuleContract — Every Module Implements This

```php
interface ModuleContract
{
    // The single domain this module owns. Must match module.json "solves" field.
    public function solves(): string;

    // Contracts this module requires from other modules. Must match module.json "requires".
    public function requires(): array;

    // Contracts this module exposes to other modules. Must match module.json "exposes".
    public function exposes(): array;

    // Register DI bindings in the module's scoped container.
    // Called once when the module is loaded for a request.
    public function register(ModuleContainer $container): void;

    // Register pipeline hooks and event subscriptions.
    // Called after all required modules are registered.
    public function boot(
        HttpPipeline   $http,
        CliPipeline    $cli,
        WorkerPipeline $worker,
        EventBus       $events,
    ): void;
}
```

---

## Provider.php — Canonical Implementation

```php
<?php
declare(strict_types=1);

namespace InvoiceModule;

use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipeline\{HttpPipeline, CliPipeline, WorkerPipeline};
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;

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
        return [DatabasePort::class];
    }

    public function exposes(): array
    {
        return [InvoiceServiceContract::class];
    }

    public function register(ModuleContainer $container): void
    {
        // INTERNAL binding — resolving from outside this module throws ScopeViolationException
        $container->bindInternal(InvoiceRepository::class, fn($c) =>
            new InvoiceRepository($c->make(DatabasePort::class))
        );

        // PUBLIC binding — resolvable by any module that declares this in requires[]
        $container->bind(InvoiceServiceContract::class, fn($c) =>
            new InvoiceService(
                repository:  $c->make(InvoiceRepository::class),
                transaction: $c->make(TransactionManager::class),
                collector:   $c->make(DomainEventCollector::class),
                eventBus:    $c->make(IntegrationEventBus::class),
                identity:    $c->make(Identity::class),
            )
        );
    }

    public function boot(
        HttpPipeline $http, CliPipeline $cli,
        WorkerPipeline $worker, EventBus $events,
    ): void {
        // Register pipeline hooks (optional)
        // $http->hook('after.security', SomeStage::class, priority: 50);

        // Subscribe to integration events (optional)
        // $events->subscribe('payment.succeeded', PaymentSucceededListener::class);
    }
}
```

---

## Pipeline Hook Slots and Priorities

```
HTTP Pipeline Hooks:
  after.security   ← module stages run after SecurityGateway clears the request
  after.load       ← module stages run after OnDemandLoader instantiates modules
  after.execute    ← module stages run after ExecuteStage returns a response

Priority conventions:
  1–9    = System-level (maintenance mode, CORS preflight)
  10–19  = Security-adjacent (rate limiter, IP validation)
  20–39  = Auth-adjacent (session refresh, token rotation)
  40–59  = Feature middleware (locale, feature flags)
  60–79  = Business-specific (tenant context)
  80–99  = Observability (metrics, tracing)
  100+   = Cleanup (response formatting, header injection)
```

---

## Cross-Module Communication

### Option 1 — Synchronous (API Contract)

```php
// Module B declares: "requires": ["invoice.generation"]
// Module B injects the contract — never the implementation

use InvoiceModule\API\Contracts\InvoiceServiceContract;

class PaymentService
{
    public function __construct(
        private readonly InvoiceServiceContract $invoices, // ← interface, not class
    ) {}

    public function process(ProcessPaymentDTO $dto): PaymentResponseDTO
    {
        $invoice = $this->invoices->find($dto->invoiceId); // valid cross-module call
    }
}
```

### Option 2 — Asynchronous (Integration Event)

```php
// Module B declares: "listens": ["invoice.created"]
// Module B's Provider registers the listener in boot()
$events->subscribe('invoice.created', InvoiceCreatedListener::class);
```

---

## Module Type Variants

### Standard Module (`"type": "module"`)
Has routes, services, domain. Standard module as described above.

### Job Module (`"type": "job"`)
```json
{
  "type":    "job",
  "queue":   "emails",
  "retry":   { "max": 3, "strategy": "exponential", "jitter": true },
  "timeout": 30,
  "requires": ["mail.port", "invoice.generation"]
}
```

### Command Module (`"type": "command"`)
```json
{
  "type":      "command",
  "signature": "invoice:generate {clientId} {--currency=USD}",
  "requires":  ["database.query", "invoice.generation"]
}
```

---

## Rules for Module Code

When writing or reviewing module code:

- **DO** ensure `module.json` lists every env var in `config[]` — boot will fail otherwise
- **DO** mark internal bindings with `bindInternal()` — not `bind()`
- **DO** match `solves()` return value exactly to `module.json` `"solves"` field
- **DO** match `requires()` and `exposes()` arrays to `module.json` fields
- **DON'T** register routes in PHP code — they belong in `module.json` only
- **DON'T** import another module's concrete class — use its published contract
- **DON'T** put business logic in `Provider.php` — it is wiring only
- **DON'T** create a module that solves two domains — split into two modules
