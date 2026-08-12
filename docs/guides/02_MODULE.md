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

  // ── Module-wide route defaults (all optional) ────────────────────────────
  "routePrefix":    "/api/v1",   // prepended to every path below
  "routeFilters":   ["auth"],    // merged in FRONT of every route's filters[]
  "routeRequires":  ["view.rendering"], // added to every route's requires[]
  "routeName":      "invoice.",  // prefixed onto every route's name
  "routeDomain":    "admin.example.com", // or "routeSubdomain": "admin"

  "routes": [                    // HTTP routes — compiled into route-manifest.php
    // method + path + handler are REQUIRED. Everything else is optional.
    { "method": "GET",    "path": "/invoices",      "handler": "InvoiceController@index",
      "name": "index" },
    { "method": "POST",   "path": "/invoices",      "handler": "InvoiceController@create",
      "filters": ["throttle:60,1"] },
    { "method": "GET",    "path": "/invoices/{id:num}", "handler": "InvoiceController@show" },
    { "method": "DELETE", "path": "/invoices/{id:num}", "handler": "InvoiceController@destroy" }
  ],

  // ── Groups: say it ONCE instead of on every route ────────────────────────
  // Expanded at BOOT into ordinary flat routes, so grouping costs nothing at
  // request time. Groups may nest (max depth 16).
  "groups": [
    { "prefix":  "/admin",
      "filters": ["shield"],     // merged, de-duplicated BY ALIAS — a route's
                                 // throttle:5,1 REPLACES a group's throttle:60,1
      "name":    "admin.",       // concatenated; an UNNAMED route stays unnamed
      "requires": ["audit.trail"], // union
      "domain":  "admin.example.com", // inner overrides outer
      "routes":  [ { "method": "GET", "path": "/stats", "handler": "AdminController@stats" } ],
      "groups":  [ /* … nest further … */ ]
    }
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

## Route Keys — Complete Reference

`method`, `path` and `handler` are required. Everything else is optional and
absent by default, so an existing `module.json` compiles byte-identically.

| Key | Type | Meaning |
|---|---|---|
| `method` | string | Upper-cased for you. `HEAD` is served by the `GET` route automatically. |
| `path` | string | **Must start with `/`** — a relative path could never match, so it fails the boot. |
| `handler` | string | `Full\Class@method`, **exactly one `@`**. |
| `name` | string | Addressable by `route('name')`. Application-wide unique. |
| `filters` | string\|list | Aliases wrapping this route: `auth`, `throttle:60,1`, `shield`, `hmac`, `signed`, `tenant`. |
| `requires` | string\|list | Module domains seeded into **this route's** dependency graph only. |
| `domain` | string | The host this route answers on. Part of the route KEY. |
| `subdomain` | string | A bare label that answers on **every** domain. |
| `faces` | string\|list | Restrict to `admin` / `api` / `project` / `public`. |

### Path parameters — `{name}`, `{name:type}`, `{name?}`

| Type | Matches | Rejects |
|---|---|---|
| *(untyped)* | one segment, `[^/]+` | `a/b` |
| `num` | digits | `abc` |
| `alpha` | letters | `draft2` |
| `alphanum` | letters + digits | `a-1` |
| `slug` | letters, digits, `-_` | `my.post` |
| `uuid` | a UUID | anything else |
| `segment` | same as untyped | `a/b` |
| `any` | anything, crosses `/` | — (**no traversal guard**) |
| `path` | crosses `/`, refuses `..` and control chars | `../etc` |
| `enum(a\|b)` | a closed set; members are `preg_quote`d | non-members |

`{page?}` is optional and takes its leading `/` with it, so `/posts/{page?}`
matches `/posts` as well as `/posts/2`. An omitted value arrives as `''`.

⚠ Use `path`, not `any`, for anything reaching a filesystem or a `StoragePort`.
`any` is a bare catch-all kept unchanged for compatibility.

**Captured values are percent-DECODED and then re-validated against their type**,
so `/files/..%2F..%2Fetc%2Fpasswd` does not satisfy `{name}` — the decoded value
does not. The type constrains what the CONTROLLER receives, not merely the wire
bytes. `/users/Jos%C3%A9` reaches the controller as `José`.

### Group inheritance

| Key | Inheritance |
|---|---|
| `prefix` | concatenated outward-in |
| `name` | concatenated outward-in; an **unnamed route stays unnamed** |
| `filters` | merged, de-duplicated **by alias** — inner replaces, never doubles |
| `requires` | union |
| `domain` / `subdomain` / `faces` | inner overrides outer |

Nothing subtracts: a group cannot strip a filter an outer group added. Removal is
the project's prerogative and lives in `proj.json` `routePolicy.disable`.

### Domain grouping — three rules

```
UNGROUPED route        GLOBAL. Every domain reaches it.
"subdomain": "api"     That LABEL on EVERY domain — api.example.com,
                       api.example2.com, and any future host with it.
"domain": "host"       That host only.
"domain": "*.parent"   Any subdomain of that parent.
```

The domain is part of the route KEY (`GET@africavoting.local /`), not a
post-match filter — which is why two domains may each declare `GET /` with a
different handler. The compiler groups by the string **verbatim**; the only check
is that a declared HOST appears in the deploying project's `proj.json`
`"domains"`. A bare `subdomain` is never checked (it spans every domain by
design), and a project declaring no `domains` is not checked at all.

Matching expands the request host into candidates, most specific first:

```
organizer.africavoting.local
  → organizer.africavoting.local   exact host
  → *.africavoting.local           wildcard on each parent suffix
  → *.local
  → organizer                      bare subdomain label
  → ''                             the shared group
```

Resolution order is that list, with **static still beating dynamic**: all static
work happens before any dynamic work, so a shared literal `/users/me` is never
swallowed by a group's `/users/{id}`.

### Boot-time failures

Each of these used to compile into a route that silently never matched:

| Message contains | Cause |
|---|---|
| *unknown parameter type* | `{id:number}` — no such type |
| *does not start with `/`* | `"path": "users"` |
| *repeats the capture name* | `/a/{id}/b/{id}` |
| *not a usable capture name* | `{2fa}` — starts with a digit |
| *'Controller@method' format* | no `@`, or two |
| *Duplicate route* | two plugins claim the same key |
| *Duplicate route name* | names are application-wide |
| *requires unknown module domain* | typo, or plugin missing from `withModules()` |
| *this project does not serve* | domain group absent from `proj.json` `domains` |
| *declares filter … which no Provider registered* | plugin missing, or alias misspelled |
| *groups … nest more than 16* | self-referencing `groups[]` |

> **Worked examples:** see [30_ROUTING_COOKBOOK.md](30_ROUTING_COOKBOOK.md) — 13 recipes,
> each compiled and showing what it actually produces.

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
