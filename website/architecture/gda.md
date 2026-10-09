# Gated Demand Architecture

The Gated Demand Architecture (GDA) is the core design principle behind HKM Kernel. It defines how the framework loads code, isolates modules, and enforces security at every stage of a request. This document covers the principles, the five access rules, and how the kernel enforces them at runtime.

## Core Principles

### 1. Security Before Everything

The SecurityGateway runs before any module loads. A denied request costs zero module initialization, zero database queries, and zero event subscriptions — the cheapest possible rejection. This is why security layers run first and why denial never throws an exception (it returns a verdict so the pipeline can refuse the request cleanly).

### 2. Load Only What's Needed

Not every request needs every module. The kernel calculates a dependency graph from the matched route's required domains and loads ONLY those modules. An HTTP process that serves invoices does not load the payment-processing module on every page. A CLI command that backs up users never touches invoicing. Unused modules never pay to initialize.

### 3. One Module, One Domain

Each module owns exactly one bounded domain. A module's `solves` value (e.g. `invoice.generation`) is a semantic label, not a technical artifact. Two modules cannot claim the same domain. A module cannot solve more than one thing — if your application needs both invoice generation and invoice distribution, those are two separate modules with a published contract between them.

### 4. Isolation by Default

Modules cannot access each other's internals. The only seam between modules is a published contract interface in `API/Contracts/`. Internal implementation classes (repositories, value objects, domain entities) are off-limits. This is enforced at runtime: a module trying to resolve another module's internal binding throws `ScopeViolationException` immediately, so violations surface during testing, not in production.

### 5. Infrastructure Independence

The kernel defines port interfaces (`DatabasePort`, `QueuePort`, `CachePort`, etc.). Modules depend on these contracts, never on concrete adapters. The application wires adapters in the bootstrap. This means:
- Swapping MySQL for PostgreSQL is a bootstrap change, not a recompile.
- Testing modules with fakes is trivial: inject a fake adapter.
- Multi-tenancy can rebind `DatabasePort` per request without touching module code.

### 6. Explicit Over Implicit

Everything is declared. Module dependencies are listed in `module.json` `requires[]`. Routes are declared in `module.json` `routes[]` or `proj.json`. Env vars are listed in `config[]`. The kernel reads these declarations at boot, validates them, and fails fast if anything is missing or misconfigured. Nothing is auto-discovered, inferred from naming conventions, or lazily loaded from file globbing.

## The Five Access Rules

These rules enforce layering and define what each layer can depend on:

| From | To | Why |
|---|---|---|
| **Controller** | Service contract only | Orchestrates business logic without owning it |
| **Service** | Repository + Gateway + EventBus | Coordinates domain logic, data, external APIs, and events |
| **Repository** | DatabasePort only | Reads/writes application state — no side effects |
| **Gateway** | Vendor SDK only | Wraps external APIs — no database, no internal models |
| **Domain** | Nothing external | Pure domain logic, independent of infrastructure |

### How Each Is Enforced

Be precise about this: the kernel enforces **one** of these rules at runtime, and the rest are conventions you keep by code review.

| Rule | What actually stops a violation |
|---|---|
| Nothing outside a module may resolve its internals | **Runtime.** A binding registered with `bindInternal()` throws `ScopeViolationException` when resolved from any other scope (below). |
| Controller → service contract only | Convention. Injecting an implementation class works; it just couples you to it. |
| Service → repository and gateway | Convention, backed by the rule above: another module's repository is internal to it, so you cannot reach it. |
| Repository → `DatabasePort` only | Convention. A repository that type-hints `MailPort` resolves it from the core container without complaint. |
| Gateway → vendor SDK only | Convention. Nothing stops a gateway injecting `DatabasePort`. |
| Domain → nothing external | Convention. PHP has no package-private imports; review `use` lines in `Domain/`. |

Two more things the container does, which help but are not the access rules:

- **A module's public contracts exist only when the module is loaded.** They are bound in that module's `register()`, which runs only if the module is in the request's dependency graph (its `solves` domain is in the `requires[]` chain, or it is essential). Depending on a contract you never declared in `requires[]` therefore fails, because the binding is not there, even though no scope rule is involved.
- **Ports are visible everywhere.** Anything bound with `withPorts()` resolves from every module and layer.

### Runtime Enforcement: ScopeViolationException

The `ModuleContainer` tracks which module (`scope`) registered each binding. When a binding is resolved, the container compares the resolver's scope against the binding's owning scope:

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

**When does this throw?**
- A service from module A tries to directly instantiate a repository from module B.
- A controller (synthetic `__project__` scope) tries to access an internal binding from an on-demand module.
- A middleware tries to resolve a service that is bound as internal to a different module.

**When does it NOT throw?**
- A service autowires a published contract from another module (resolution succeeds via public `bind`, not `bindInternal`).
- A service autowires a port from the core container (ports are public).
- A service autowires another class within its own module (same scope).

## Architecture Diagram

```
    Request
       ↓
    ┌──────────────────────────────────────────────────────┐
    │ CoreContainer (app-lifetime, frozen after materialize)│
    │                                                       │
    │  - Ports: DatabasePort, CachePort, QueuePort, etc.  │
    │  - Kernel services: EventBus, Scheduler, HttpClient │
    │  (shared across all requests)                        │
    └──────────────────────────────────────────────────────┘
            ↑ fallback / fallback
            │
    ┌───────────────────────────────────────────────────┐
    │ ModuleContainer (request-scoped, new per request)  │
    │                                                   │
    │  per-module scopes                               │
    │  ┌─────────────────┐  ┌──────────────────┐      │
    │  │ invoice.gen     │  │ database.mgmt    │      │
    │  │  (bound         │  │  (bound          │      │
    │  │   internal)     │  │   internal)      │      │
    │  │                 │  │                  │      │
    │  │ InvoiceRepo     │  │ Database Schema  │      │
    │  │ (×)             │  │ Migrator (×)     │      │
    │  └─────────────────┘  └──────────────────┘      │
    │                                                   │
    │  public scope (resolvable by all modules)        │
    │  ┌─────────────────┐  ┌──────────────────┐      │
    │  │InvoiceContract  │  │DatabaseContract  │      │
    │  │ (published)     │  │(published)       │      │
    │  └─────────────────┘  └──────────────────┘      │
    │                                                   │
    │  kernel scope (request-scoped services)          │
    │  ┌─────────────────┐  ┌──────────────────┐      │
    │  │Identity         │  │EventBus rebind   │      │
    │  │TransactionMgr   │  │DomainEventColl   │      │
    │  └─────────────────┘  └──────────────────┘      │
    └───────────────────────────────────────────────────┘
            ↓ controller resolution
    ┌───────────────────────────────────────────────────┐
    │ Controller (makeInScope with its module's domain) │
    │                                                   │
    │  Can reach:                                       │
    │  - Public contracts (other modules)              │
    │  - Core ports                                    │
    │  - Own internal bindings (same scope)            │
    │  - Request-scoped kernel services                │
    │                                                   │
    │  Cannot reach:                                    │
    │  - Other modules' internal bindings              │
    └───────────────────────────────────────────────────┘
            ↓ calls
    ┌───────────────────────────────────────────────────┐
    │ Service (public contract)                         │
    │                                                   │
    │  Receives: repository, gateway, eventBus         │
    │  Coordinates domain logic, state, events          │
    └───────────────────────────────────────────────────┘
    ├─────────────────────────────┬─────────────────────┤
    ↓                             ↓                     ↓
┌─────────────┐          ┌──────────────┐      ┌──────────────┐
│ Repository  │          │ Gateway      │      │ Domain Logic │
│             │          │              │      │              │
│ DatabasePort│          │ Vendor SDK   │      │ Pure logic   │
└─────────────┘          └──────────────┘      └──────────────┘
    (internal)              (internal)            (nothing)
```

## Common Patterns

### Published Contract Pattern

```php
// API/Contracts/InvoiceServiceContract.php — public, resolvable by other modules
interface InvoiceServiceContract {
    public function find(string $id): InvoiceResponseDTO;
    public function create(CreateInvoiceDTO $dto): InvoiceResponseDTO;
}

// Application/Services/InvoiceService.php — implementation, internal binding
final class InvoiceService implements InvoiceServiceContract { ... }

// Provider.php — wiring
public function register(ModuleContainer $container): void {
    $container->bindInternal(InvoiceRepository::class, fn($c) => ...);
    
    // Public binding — resolvable by other modules
    $container->bind(InvoiceServiceContract::class, fn($c) =>
        new InvoiceService($c->make(InvoiceRepository::class), ...)
    );
}
```

A module that depends on invoicing declares `requires: ['invoice.generation']` and injects `InvoiceServiceContract`. It never knows `InvoiceService` or `InvoiceRepository` exist.

### Internal Binding Pattern

```php
// InvoiceRepository is bound INTERNAL to the invoice module
$container->bindInternal(InvoiceRepository::class, fn($c) =>
    new InvoiceRepository($c->make(DatabasePort::class), ...)
);
```

- Resolvable ONLY from within `invoice.generation` scope.
- An invoice module service can autowire it directly.
- A payment module service cannot, even via constructor injection (the container throws).
- Protects implementation detail changes from breaking consumers.

### Cross-Module Dependency

```php
// PaymentModule depends on invoices
module.json "requires": ["invoice.generation", "database.management"]

Provider.php register() {
    $container->bind(PaymentGatewayContract::class, fn($c) =>
        new PaymentGateway(
            $c->make(InvoiceServiceContract::class),  // ✓ published contract
            $c->make(DatabasePort::class),            // ✓ port from core
            // $c->make(InvoiceRepository::class)     // ✗ throws ScopeViolationException
        )
    );
}
```

## Pitfalls

**Hard to diagnose:**
- Circular module dependencies are detected at boot (guaranteed fail-fast).
- An internal binding resolved cross-module throws `ScopeViolationException` with a clear message.
- An **unknown** domain in `requires[]` fails at boot with a descriptive error. A **forgotten** entry does not: the module simply is not loaded, and resolving its contract fails when the request runs.

**Easy to miss:**
- A controller that autowires an implementation class instead of a contract stays wired until something swaps the implementation, then breaks at runtime.
- A service that reaches for a static helper or global instead of a dependency stays functional but becomes hard to test.
- A domain entity that accepts a port in its constructor breaks isolation and blocks testing.

**What to avoid:**
- `getInstance()` / `setInstance()` on CoreContainer or ModuleContainer — both throw `LogicException`.
- Static properties in request-scoped classes (services, repositories) — they leak across requests under OpenSwoole.
- Storing a ModuleContainer or Identity for later use — they are request-scoped and stale on the next request.
- Accessing an internal binding outside its module — test it as an integrated module service instead.

## Source

- [ModuleContainer.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Container/ModuleContainer.php) — scope isolation at runtime
- [CoreContainer.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Container/CoreContainer.php) — freezing after materialize
- [ScopeViolationException.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Exceptions/ScopeViolationException.php)
- [ModuleContract.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Contracts/ModuleContract.php) — the interface every module provider implements
