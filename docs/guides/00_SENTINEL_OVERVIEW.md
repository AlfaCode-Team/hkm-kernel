# HKM Kernel — Architecture Overview

> **Start here.** This is the root guide that every layer guide builds on. Every rule stated
> here is absolute and enforced by the framework at runtime.

---

## What HKM Kernel Is

HKM Kernel is a PHP 8.4+ framework built on the **Gated Demand Architecture (GDA)** pattern.

| Principle | Meaning |
|---|---|
| **Security before bootstrap** | The `SecurityGateway` runs before any module loads. Denied requests cost microseconds. |
| **Load only what is needed** | Only the modules required for the current request are loaded into memory. |
| **One module, one domain** | Every module declares exactly one business domain it solves. |
| **Isolation by default** | Modules cannot access each other's internals. Scoped containers enforce this at runtime. |
| **Infrastructure independence** | The kernel defines port interfaces. The project provides implementations. |
| **Explicit over implicit** | Everything is declared in `module.json`. Nothing is auto-discovered at runtime. |

---

## The Three Worlds

```
┌─────────────────────────────────────────────────┐
│  PROJECT LAYER  (wiring only — no business logic) │
│  ┌─────────────────────────────────────────────┐ │
│  │  MODULE LAYER  (business domains)           │ │
│  │  ┌───────────────────────────────────────┐  │ │
│  │  │  KERNEL  (boot, pipeline, security,   │  │ │
│  │  │          loading, events, DI)         │  │ │
│  │  └───────────────────────────────────────┘  │ │
│  └─────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────┘
```

- **Kernel** — knows nothing about modules or business logic. Never changes.
- **Module** — knows nothing about the project. Wires to the kernel through contracts.
- **Project** — knows about everything but contains no business logic. Wires only.

---

## The Five Access Rules (ABSOLUTE — NEVER VIOLATE)

These are enforced by `ModuleContainer` scope checking at runtime.

```
Controller  →  Service (via published contract only)
Service     →  Repository  AND  Gateway
Repository  →  DatabasePort only
Gateway     →  Vendor SDK only
Domain      →  Nothing external
```

**In plain English:**
1. A Controller may ONLY call a Service. Never a Repository, Gateway, or Domain class directly.
2. A Service is the ONLY layer that may call both Repository and Gateway.
3. A Repository may ONLY use `DatabasePort`. No HTTP calls, no third-party SDKs.
4. A Gateway may ONLY wrap a vendor SDK. No database, no other services.
5. Domain classes (Entities, Value Objects) have ZERO imports from outside `Domain/`.

---

## The HTTP Request Lifecycle

```
Request arrives
    │
    ▼
CorrelationIdStage      ← generates/propagates X-Correlation-ID
    │
    ▼
SecurityGateway         ← PRE-BOOTSTRAP: runs before any module loads
  CsrfTokenLayer        ← kernel: stateless HMAC-signed CSRF verification
  [Auth plugin] layers  ← JwtAuthLayer / PersonalAccessTokenLayer → sets Identity
    │ DENIED → 401/403 (zero module cost)
    │ (rate limiting + IP shield are SecurityFilters route filters, later in-pipeline)
    │ CLEARED ↓
after.security hooks    ← module-registered stages run here
    │
    ▼
ResolveStage            ← route-manifest.php lookup → service name
                          (static: O(1) "METHOD /path" hash; parameterized:
                           regex scan over ONLY the requested method's bucket)
    │
    ▼
LoadStage               ← dep graph calc → OnDemandLoader
                          (only modules needed for THIS route)
after.load hooks        ← module-registered stages run here
RouteFilterStage        ← runs the matched route's declared filters[] (auth, throttle, …)
    │
    ▼
ExecuteStage            ← resolve service contract → run → Response
after.execute hooks
    │
    ▼
Response returned
    │
ErrorStage wraps everything ← catches all Throwables, routes to ErrorPipeline
```

---

## Module Directory Structure

```
projects/{name}/
├── module.json                         ← SINGLE SOURCE OF TRUTH
├── API/
│   ├── Contracts/{Name}ServiceContract.php
│   └── IntegrationEvents/{Name}CreatedEvent.php
├── Domain/
│   ├── Entities/{Name}.php
│   ├── ValueObjects/{Field}.php
│   ├── Rules/{Rule}.php
│   └── Events/{Name}CreatedDomainEvent.php
├── Application/
│   └── Services/{Name}Service.php
├── Infrastructure/
│   ├── Persistence/{Name}Repository.php
│   ├── Gateways/{Vendor}Gateway.php
│   └── Http/Controllers/{Name}Controller.php
└── Provider.php
```

Note: `modules/` in this repository holds internal framework packages
(`bind-it`, `php-io-cli`, etc.), while business domain modules are currently
loaded from `projects/{name}/`.

---

## module.json Schema (All Fields)

```json
{
  "name":     "invoice",
  "version":  "1.0.0",
  "solves":   "invoice.generation",
  "type":     "module",
  "requires": ["database.query", "pdf.generation"],
  "exposes":  ["InvoiceServiceContract"],
  "routes": [
    { "method": "GET",  "path": "/api/invoices",      "handler": "InvoiceController@index" },
    { "method": "POST", "path": "/api/invoices",      "handler": "InvoiceController@create" }
  ],
  "emits":    ["invoice.created", "invoice.paid"],
  "listens":  ["payment.succeeded"],
  "config":   ["INVOICE_CURRENCY", { "key": "TAX_RATE", "type": "float", "required": false }]
}
```

---

## Exception Hierarchy

```
FrameworkException (base)
├── SecurityException   → severity: warning  → HTTP 401/403
├── DomainException     → severity: info     → HTTP 422
├── ServiceException    → severity: warning  → HTTP 422/500
├── RepositoryException → severity: critical → HTTP 500
├── GatewayException    → severity: critical → HTTP 502
└── KernelException     → severity: critical → HTTP 500
```

Always throw the exception type matching the layer. Never let a `\PDOException` or `\Stripe\Exception` escape its layer.

---

## Key Contracts Reference

| Interface | Location | Used By |
|---|---|---|
| `DatabasePort` | Kernel | Repository layer |
| `CachePort` | Kernel | Rate limiter, cache module |
| `QueuePort` | Kernel | Service layer (job dispatch) |
| `MailPort` | Kernel | Service layer (email) |
| `SmsPort` | Kernel | Service layer (SMS) |
| `StoragePort` | Kernel | Service layer (files) |
| `ModuleContract` | Kernel | Every module's Provider |
| `SecurityLayerContract` | Kernel | SecurityGateway layers |
| `JobContract` | Kernel | Worker pipeline job handlers |
| `CommandContract` | Kernel | CLI pipeline command handlers |

---

## Guide index

| File | Covers |
|---|---|
| `00_SENTINEL_OVERVIEW.md` | This file — architecture rules and structure |
| `01_KERNEL.md` | Kernel components, pipelines, boot sequence |
| `02_MODULE.md` | module.json, ModuleContract, Provider wiring |
| `03_DOMAIN.md` | Entities, Value Objects, Domain Events, Rules |
| `04_SERVICE.md` | Application Services, transactions, event dispatch |
| `05_REPOSITORY.md` | Repository layer, DatabasePort, hydration |
| `06_GATEWAY.md` | Gateway layer, SDK wrapping, exception translation |
| `07_CONTROLLER.md` | HTTP Controllers, DTOs, response format |
| `08_EVENTS.md` | Domain vs Integration events, EventBus, outbox |
| `09_SECURITY.md` | SecurityGateway, layers, Identity, tokens |
| `10_TESTING.md` | Test patterns, fakes, port doubles, strategies |
| `12_WORKER.md` | Worker pipeline, jobs, retry, dead-letter queue |
