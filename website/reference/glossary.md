# Glossary

A reference of key terms used throughout HKM Kernel. Each entry links to pages where the concept is explained in depth.

## A

**AbstractCommand**
A base class for console commands. Extends this instead of using the deprecated Symfony Console wrapper. See [CLI Pipeline](/cli/cli-pipeline).

**API contract**
A published interface in `API/Contracts/` that other modules can depend on. Only thing exported by a module; internals remain private. See [Module Contract](/modules/module-contract).

**Application layer**
Contains `Services/` classes that orchestrate domain logic and talk to infrastructure. Handles transactions, event collection, and authorization. See [Service Layer](/layers/service).

## B

**Boot**
The kernel's startup phase that compiles manifests, validates configuration, and detects conflicts. Happens once per process before any request. See [Boot Pipeline](/architecture/boot-pipeline).

**BootStamp**
Cache of manifest compilation inputs. When set via `BOOT_CACHE=1`, the kernel skips recompiling manifests that haven't changed, reducing per-request overhead under PHP-FPM from ~2ms to ~0.02ms. See [Configuration](/guide/configuration).

**Bounded context**
A term from Domain-Driven Design meaning a distinct business domain. Each HKM module owns exactly one. See [Modules](/modules/module-contract).

## C

**CSRF token**
Cross-Site Request Forgery protection. HKM ships with `CsrfTokenLayer`, a stateless HMAC-signed token (WordPress-nonce style) bound to an opaque cookie. See [Security](/security/csrf).

**Config Repository**
Immutable dotted-key configuration reader. Read via `config('mail.from.address')`. See [Configuration](/guide/configuration).

## D

**Database migration**
A versioned SQL schema change. HKM uses LetMigrate, a multi-driver engine. See [Data Access](/layers/data-access).

**Dependency graph**
Calculated at request time from a route's module domain + `requires[]`. Determines which modules load for this request only. See [Module Loading](/architecture/module-loading).

**Domain layer**
Contains `Entities/`, `ValueObjects/`, `Events/`, and `Rules/`. Pure business logic with zero external imports. Never touches HTTP, databases, or vendor SDKs. See [Domain Layer](/layers/domain).

**Domain event**
Immutable event raised by a domain entity (e.g., `InvoiceCreatedDomainEvent`). Collected during a transaction and dispatched on commit. See [Events](/layers/events).

**Double-submit CSRF**
A naive CSRF defense where the token is stored in a cookie and re-sent in a form field. HKM does NOT use this; it uses HMAC-signed tokens instead. See [Security](/security/csrf).

## E

**Entry point**
A script that receives a request and materializes the kernel. Three exist: HTTP (`app/public/index.php`), CLI (`app/cli/run.php`), Worker (`app/worker/run.php`). See [Directory Structure](/guide/directory-structure).

**Essential module**
A module loaded into every request (vs. on-demand). Use for request-scoped infrastructure (sessions, cookies, auth). See [Architecture](/architecture/module-loading).

**ExceptionHandler**
@see ErrorPipeline.

## F

**Face**
A route property controlling visibility. A route with `faces: ["admin"]` is invisible on other faces. See [Routing](/routing/basics).

**Filter** (route)
A stage that runs when a route declares it. Examples: `auth`, `throttle`, `hmac`. Registered by name in module `boot()`. See [HTTP Filters](/http/filters).

**FPM**
PHP-FPM (FastCGI Process Manager). The default PHP SAPI when deployed to shared hosting or traditional servers. See [Installation](/guide/installation).

## G

**Gated Demand Architecture (GDA)**
HKM's core pattern: security runs first (the gate), then only needed modules load (on-demand). Zero-cost denial because denied requests skip module wiring entirely. See [Introduction](/guide/introduction).

**Gateway layer**
Contains `Gateways/` classes that talk to vendor SDKs (Stripe, AWS, third-party APIs). Translates vendor exceptions into `GatewayException`. See [Gateway Layer](/layers/gateway).

**Global kernel mode**
Development mode where `hkm --dev` runs against a kernel checkout instead of an installed one. See [Installation](/guide/installation).

## H

**HKM**
The framework's name. Also the native launcher CLI (Zig-built binary). Also stands for "hiking" (the vibes). See [Introduction](/guide/introduction).

**Hook** (pipeline)
A module-registered stage that runs at a named slot (`after.security`, `after.load`, `after.execute`). Registered in `boot()` and runs on every request. See [HTTP Pipeline](/http/pipeline).

## I

**Identity**
Immutable object carrying user context: userId, tenantId, roles, permissions. Attached by SecurityGateway. See [Security](/security/gateway).

**Immutable**
No setters; every mutator returns a new instance. `Request`, `Response`, and `Config\Repository` are all immutable by design. Safe under OpenSwoole concurrency. See [Request Lifecycle](/guide/request-lifecycle).

**Infrastructure layer**
Contains `Persistence/`, `Gateways/`, and `Http/Controllers/`. Adapts domain logic to external systems. See [Directory Structure](/guide/directory-structure).

**Integration event**
Public event with versioned schema, published by a module for other modules to listen to. Contains primitives only (strings, ints) so modules can be decoupled. See [Events](/layers/events).

## J

**Job**
An asynchronous task queued for later execution. Declares a `handle()` method and retry strategy. See [Background Jobs](/background/workers-and-jobs).

## K

**Kernel**
The core framework. Knows about pipelines, DI, security, and ports. Knows nothing about business domains. Compiled and materialized lazily. See [Introduction](/guide/introduction).

## L

**LetMigrate**
Multi-driver database migration engine (MySQL, PostgreSQL, SQLite, SQL Server). Fluent schema API, seeders, full CLI. Standalone, no ORM dependency. See [Let Migrate](/packages/let-migrate).

**Load stage** (pipeline)
Stage that calculates the dependency graph and wires only the modules a route needs. Attached container is request-scoped and discarded after. See [Request Lifecycle](/guide/request-lifecycle).

**LoggerPort**
Port interface for application logging. Adapters in plugins bind it. See [Infrastructure](/infrastructure/ports).

## M

**Manifest**
Compiled artefact written at boot. Examples: route-manifest.php (all routes), service-manifest.php (module domains), config-manifest.php (merged config). See [Directory Structure](/guide/directory-structure).

**Materialize**
Lazy kernel initialization. Happens on first `http()`, `cli()`, or `workerLoop()` call. Constructs pipelines and wires modules. See [Boot Pipeline](/architecture/boot-pipeline).

**Module**
A self-contained unit solving one business domain. Declares dependencies, exposed contracts, routes, and config in `module.json`. See [Modules](/modules/module-contract).

**ModuleContainer**
Request-scoped DI container. Enforces scope isolation at runtime—internal bindings throw `ScopeViolationException` when accessed cross-module. Discarded after request. See [Containers](/architecture/containers).

**Module.json**
SINGLE SOURCE OF TRUTH for a module. Declares solves/requires/exposes/routes/config. Compiled and validated at boot. See [Module JSON](/modules/module-json).

## N

## O

**On-demand module**
A module that registers only when a route's dependency graph includes it (vs. essential). Use for optional features. See [Module Loading](/architecture/module-loading).

**OpenSwoole** / **Swoole**
Long-lived HTTP/worker server (alternative to PHP-FPM). Concurrent coroutines share a worker process. Requires immutable request/response and clean container lifecycle. See [Installation](/guide/installation).

## P

**Pipeline**
A chain of stages that process a request (HTTP, CLI, or Worker). Each stage can allow, deny, or modify the request. See [Request Lifecycle](/guide/request-lifecycle).

**Plugin**
A first-party module published as a separate package (hkm-plugin-*). Opt into via `withModules([...])`. See [Plugins](/modules/plugins).

**Port**
An interface defining a capability (DatabasePort, CachePort, MailPort, etc.). The kernel defines ports; the project binds implementations. Lets you swap MySQL for Postgres without touching business code. See [Infrastructure](/infrastructure/ports).

**Project layer**
Pure wiring. Declares routes, config, which modules are active. Contains no business logic. See [Introduction](/guide/introduction).

**Proj.json**
Project-level declaration of routes, domains, route policies, and essentials. NOT the same as module.json. See [Directory Structure](/guide/directory-structure).

## Q

**QueuePort**
Port interface for job queuing. Adapters in plugins bind it (Redis, file-backed, SQS, etc.). See [Infrastructure](/infrastructure/ports).

## R

**Repository layer**
Contains `Persistence/` classes that read/write to the database via `DatabasePort`. Translates PDO exceptions into `RepositoryException`. See [Repository Layer](/layers/repository).

**Resolve stage** (pipeline)
Stage that matches a request path to a route and extracts the handler class. Attaches route metadata as request attributes. See [Request Lifecycle](/guide/request-lifecycle).

**Route filter**
@see Filter.

**Route group**
A declaration in `module.json` or `proj.json` that states once what would be repeated on many routes (prefix, filters, requires, name prefix). Expanded at boot into flat routes. See [Routing](/routing/groups).

**Route host**
The domain a route answers on (e.g., `admin.example.com`). Declared in `module.json` or `proj.json` `groups[]`. Part of the route key alongside method+path. See [Routing](/routing/domains).

**RouteName**
Unique application-wide name for a route. Used by `route()` helper to generate URLs that survive refactors. See [Routing](/routing/named-routes).

## S

**Scope violation**
Runtime exception when a module tries to access another module's internal binding. Thrown by `ModuleContainer::bindInternal()`. Enforces isolation at the container level. See [Containers](/architecture/containers).

**Security layer**
A stage that runs before module loading and can deny a request (returning 401/403). The kernel ships `CsrfTokenLayer`; plugins can add auth layers. See [Security](/security/gateway).

**SecurityGateway**
Runs every security layer in sequence. Denied requests never load any module. The "gate" in Gated Demand Architecture. See [Security](/security/gateway).

**SecurityVerdict**
Allow or deny decision returned by a security layer. `allow($request)` attaches optional Identity; `deny($code, $reason)` stops execution. See [Security](/security/gateway).

**Service layer**
Contains orchestration logic. Handles transactions, collects domain events, coordinates with repositories and gateways. See [Service Layer](/layers/service).

**Solves domain**
The business domain a module owns. Declared in `module.json` `solves`. Example: `invoice.generation`. Module must own exactly one. See [Module JSON](/modules/module-json).

**Stage**
A middleware-like unit in a pipeline. Receives a request/input, processes it, calls `$next`, receives a response/output, and returns it. See [Request Lifecycle](/guide/request-lifecycle).

**Synthetic __project__ scope**
The DI scope for project-declared routes (in `proj.json`). Has zero module dependencies. Controllers wired here get full port access but no plugin modules. See [Containers](/architecture/containers).

## T

**Tenancy**
Multi-tenant architecture where one application instance serves many logical organizations. HKM's `tenancy.routing` module handles host-based tenant resolution. See [Directory Structure](/guide/directory-structure).

**Transaction manager**
Coordinates database transactions across repositories. Starts, commits, and rolls back. Works with `DomainEventCollector` to buffer events and discard them on rollback. See [Service Layer](/layers/service).

**TransactionAware**
An interface for objects that need to participate in a database transaction. Services that talk to repositories use this. See [Service Layer](/layers/service).

## U

**URL generator**
Builds URLs from named routes. Accessible via `url()` helper or injected `UrlGenerator` class. Generates relative or absolute URLs with signed variants for one-time actions. See [Routing](/routing/named-routes).

## V

**Value object**
Immutable, self-validating domain object (e.g., Money, Email, PhoneNumber). Private constructor, static factory, operations return new instances. See [Domain Layer](/layers/domain).

**Verdict**
@see SecurityVerdict.

## W

**Worker loop**
Long-running process that dequeues jobs from a queue, executes them, and retries on failure. Runs one job at a time with configurable retry strategy. See [Background Jobs](/background/workers-and-jobs).

**WithModules**
Fluent builder method that declares which modules are active. Called on the Kernel in `app/bootstrap/app.php`. See [Kernel Builder](/architecture/kernel-builder).

**WithPorts**
Fluent builder method that binds infrastructure implementations (MySQL, Redis, SMTP, etc.). Called on the Kernel in the project bootstrap (`app/bootstrap/app.php` in a generated project). See [Kernel Builder](/architecture/kernel-builder).

## X

## Y

## Z

**Zig**
The language the native `hkm` launcher is built in. Compiles to a single portable binary (no runtime dependency). See [Installation](/guide/installation).

---

## See also

- [Architecture](/architecture/gda) — Deep dives into design patterns
- [Modules](/modules/module-contract) — Full module contract and lifecycle
- [Layers](/layers/domain) — Each layer's rules and responsibilities
- [Routing](/routing/basics) — Route declaration and matching
