# Directory Structure

Understanding where files live helps you navigate the kernel, scaffold projects, and organize plugins. This guide covers the kernel repo layout, a generated project structure, and the plugin pattern.

## Kernel repository layout

The kernel source lives in `src/Kernel/`. Core packages are in `modules/`.

```
src/Kernel/
├── Kernel.php                         ← fluent builder entry point
│
├── Boot/
│   ├── BootPipeline.php               ← 15 stages: compile, load, validate
│   ├── BootStamp.php                  ← cache manifest compilation when unchanged
│   ├── ManifestReader.php             ← reads module.json + config (cached per build)
│   └── Stages/
│       ├── ValidateConfigStage        ← verify all env vars are present + typed
│       ├── DetectConflictsStage       ← no two modules share solves() domain
│       ├── DetectCyclesStage          ← no circular requires[] chains
│       ├── CompileServiceManifestStage ← domain→service mapping
│       ├── CompileRouteManifestStage  ← routes + index (module + project)
│       ├── CompileViewManifestStage   ← view-manifest.php (project-first cascade)
│       ├── CompileLangManifestStage   ← lang-manifest.php (project-first + merge)
│       ├── CompileJobManifestStage    ← job-manifest.php
│       ├── CompileCommandManifestStage ← command-manifest.php (registered by CliPipeline)
│       ├── CompileScheduleManifestStage ← parsed cron schedule
│       ├── CompileConfigManifestStage ← merged config (project over plugin)
│       ├── CompileModuleFilesStage    ← files-manifest.php (helper requires)
│       ├── LoadModuleFilesStage       ← require_once helpers (always runs)
│       ├── RegisterPortsStage         ← verify port bindings
│       └── BindSecurityStage          ← verify security layer bindings
│
├── Security/
│   ├── SecurityGateway.php            ← runs BEFORE any module loads
│   ├── SecurityVerdict.php            ← allow/deny decision
│   ├── Identity.php                   ← immutable: userId, tenantId, roles
│   ├── Contracts/SecurityLayerContract.php
│   └── Layers/
│       └── CsrfTokenLayer.php         ← stateless HMAC-signed token CSRF
│
├── Container/
│   ├── CoreContainer.php              ← app-lifetime, ports + kernel services
│   └── ModuleContainer.php            ← request-scoped, enforces scope isolation
│
├── Loading/
│   ├── DependencyGraphCalculator.php  ← builds ordered module list
│   ├── DependencyGraph.php
│   └── OnDemandLoader.php             ← registers modules per request
│
├── Pipelines/
│   ├── Http/
│   │   ├── HttpPipeline.php           ← assembles and runs HTTP request lifecycle
│   │   ├── Contracts/HttpStageContract.php
│   │   └── Stages/
│   │       ├── CorrelationIdStage
│   │       ├── ErrorStage
│   │       ├── ObservabilityStage
│   │       ├── SecurityStage
│   │       ├── ResolveStage
│   │       ├── LoadStage
│   │       ├── RouteFilterStage
│   │       └── ExecuteStage
│   │
│   ├── Cli/
│   │   ├── CliPipeline.php
│   │   ├── AbstractCommand.php
│   │   ├── Contracts/CommandContract.php
│   │   └── Stages/
│   │
│   └── Worker/
│       ├── WorkerPipeline.php
│       ├── WorkerLoop.php             ← job dequeue → validate → execute
│       ├── JobPayload.php
│       ├── JobResult.php
│       ├── Contracts/JobContract.php
│       ├── Retry/
│       │   ├── RetryStrategyContract.php
│       │   ├── ExponentialRetryStrategy.php
│       │   ├── LinearRetryStrategy.php
│       │   └── FixedRetryStrategy.php
│       └── Stages/
│
├── Events/
│   ├── EventBus.php                   ← dispatches to subscribers
│   ├── DomainEventCollector.php       ← buffers events in-tx
│   └── Contracts/
│       ├── DomainEventContract.php
│       ├── IntegrationEventContract.php
│       └── EventListenerContract.php
│
├── Config/
│   └── Repository.php                 ← immutable dotted-key reader
│
├── Ports/
│   ├── DatabasePort.php               ← interface; adapters in plugins
│   ├── CachePort.php
│   ├── QueuePort.php
│   ├── MailPort.php
│   ├── SmsPort.php
│   ├── StoragePort.php
│   ├── HttpClientPort.php
│   ├── LoggerPort.php + LogLevel.php
│   ├── ClockPort.php + SystemClock.php
│   ├── EncryptionPort.php
│   ├── HashingPort.php
│   ├── SessionPort.php
│   ├── MetricsPort.php + TracerPort.php
│   ├── Lock.php + AbstractLock.php    ← mutual exclusion from cache
│   └── RangeReadableStorage.php
│
├── Routing/
│   ├── RouteParameter.php             ← {id:num} / {f:path} grammar
│   ├── RouteIndex.php                 ← matcher index
│   ├── UrlGenerator.php               ← route(name, params)
│   └── ... (matching logic)
│
├── Database/
│   └── TransactionManager.php         ← request-scoped tx wrapper
│
├── Error/
│   ├── ErrorPipeline.php              ← classifies exceptions → notifies
│   ├── ErrorContext.php
│   ├── ErrorClassifier.php
│   └── Notifiers/
│       ├── SlackNotifier.php
│       ├── MailNotifier.php
│       ├── DatabaseErrorLogger.php
│       └── FileNotifier.php           ← always runs (guaranteed fallback)
│
├── Exceptions/
│   ├── FrameworkException.php         ← abstract base
│   ├── SecurityException.php          ← 401/403
│   ├── DomainException.php            ← 422 (info)
│   ├── ServiceException.php           ← 422
│   ├── RepositoryException.php        ← 500
│   ├── GatewayException.php           ← 502
│   ├── KernelException.php            ← 500
│   ├── BootException.php
│   ├── ScopeViolationException.php
│   ├── CircularDependencyException.php
│   ├── ValidationException.php        ← 422 with field errors
│   └── OptimisticLockException.php
│
├── Contracts/
│   └── ModuleContract.php             ← solves/requires/exposes/register/boot
│
├── Observability/
│   ├── RequestTelemetry.php
│   └── ... (metrics + tracing)
│
├── Scheduling/
│   ├── Scheduler.php                  ← decides which tasks are due → queue them
│   ├── ScheduledTask.php
│   └── CronExpression.php
│
├── Support/
│   ├── helpers.php                    ← env(), config(), route(), etc.
│   ├── Paths.php
│   └── ... (utilities)
│
└── System/
    └── GlobalKernelProjectScaffolder.php  ← generates project tree for hkm new
```

## Generated project layout

When you run `hkm new ~/apps/shop`, this tree is created:

```
shop/
├── app/
│   ├── bootstrap/
│   │   ├── kernel-autoload.php        ← resolves PSR-4 namespaces
│   │   ├── base.php                   ← shared: ports, security, middleware
│   │   └── app.php                    ← project-specific: modules, routes, domains
│   │
│   ├── public/
│   │   └── index.php                  ← web entry point
│   │
│   ├── cli/
│   │   └── run.php                    ← console entry point
│   │
│   └── Infrastructure/
│       ├── Http/
│       │   ├── HomeController.php
│       │   └── ... (your routes)
│       ├── InMemoryCache.php          ← default port adapters (project-level)
│       ├── FileQueue.php
│       └── ... (other adapters)
│
├── src/                               ← your application code
│   ├── Support/
│   │   └── Clock.php                  ← sample utility
│   └── ... (organize as needed)
│
├── config/
│   ├── environments/
│   │   ├── local.php                  ← dev: logging, caching, etc.
│   │   ├── staging.php
│   │   ├── production.php
│   │   └── testing.php
│   ├── let-migrate.php                ← database migration config
│   └── ... (other config files)
│
├── database/
│   ├── migrations/                    ← LetMigrate migration files
│   ├── seeders/                       ← database seeders
│   └── factories/                     ← model factories (testing)
│
├── resources/                         ← views, languages, assets
│   └── ... (organized by feature)
│
├── var/                               ← runtime state (gitignored)
│   ├── cache/
│   │   └── manifests/                 ← compiled routes, config, services
│   ├── logs/                          ← error logs
│   ├── tmp/                           ← temp files
│   ├── locks/                         ← mutual exclusion files
│   ├── sessions/                      ← session storage (if configured)
│   └── queue/                         ← job queue (if file-backed)
│
├── userdata/                          ← persistent app state (gitignored)
│   └── storage/                       ← uploaded files, cache, etc.
│
├── proj.json                          ← PROJECT DECLARATION (single source of truth)
│                                        name, domains, routes, route policy
│
├── composer.json                      ← Composer config (namespace + local paths)
├── composer.lock
│
├── .env                               ← generated by hkm install
├── .env.example                       ← copy of .env, gitignored vars commented
├── .env.local                         ← local overrides (gitignored)
├── .gitignore
└── README.md
```

### Key directories

| Directory | Purpose | Git |
|---|---|---|
| `app/bootstrap/` | Entry point wiring | tracked |
| `app/public/`, `app/cli/` | Entry points | tracked |
| `src/` | Your application code | tracked |
| `config/` | Environment-specific configuration | tracked |
| `database/migrations/` | LetMigrate migrations | tracked |
| `var/` | Runtime state (logs, cache, queue) | gitignored |
| `userdata/` | Persistent uploads/files | gitignored |
| `.env` | Process environment (secrets) | gitignored |

## Plugin layout

Plugins live under a `plugins/` directory at the kernel root (installed with the launcher). Each plugin owns one business domain.

```
plugins/Invoice/
├── module.json                        ← SINGLE SOURCE OF TRUTH
│                                        solves, requires, exposes, routes, config
│
├── API/
│   ├── Contracts/
│   │   └── InvoiceServiceContract.php ← published interface (only thing others import)
│   └── IntegrationEvents/
│       └── InvoiceCreatedIntegrationEvent.php
│
├── Domain/                            ← pure business logic
│   ├── Entities/
│   │   └── Invoice.php                ← immutable, private constructor
│   ├── ValueObjects/
│   │   └── Money.php                  ← readonly, immutable
│   ├── Events/
│   │   └── InvoiceCreatedDomainEvent.php  ← collected in-tx
│   └── Rules/
│       └── InvoiceValidationRule.php  ← static validation
│
├── Application/
│   └── Services/
│       └── InvoiceService.php         ← transaction + event orchestration
│
├── Infrastructure/
│   ├── Persistence/
│   │   └── InvoiceRepository.php      ← DatabasePort only
│   ├── Gateways/
│   │   └── StripePaymentGateway.php   ← vendor SDK only
│   └── Http/
│       └── Controllers/
│           └── InvoiceController.php  ← ≤3 lines: DTO → service → Response
│
├── Provider.php                       ← implements ModuleContract
│
├── config/                            ← published to project on install
│   └── invoice.php
│
├── database/
│   ├── migrations/                    ← published to project
│   └── seeders/
│
└── resources/                         ← views, lang strings
    └── views/
        └── invoice/
```

### Why this structure?

**Each layer is isolated.** Tests can mock just the Repository without the entire kernel. A Service tests your business logic without knowing about HTTP. Controllers stay thin (≤3 lines) because they delegate.

**Vertical slices are self-contained.** Everything the Invoice domain needs lives under `plugins/Invoice/`. Adding a feature means adding to one slice, not touching dozens of files.

**Contracts are published.** Only `API/Contracts/` is imported by other modules. Internal classes in `Domain/`, `Application/`, and `Infrastructure/` stay private, enforced by the kernel at runtime.

## Compiled manifests (var/cache/manifests)

The kernel compiles several manifests at boot and writes them to `var/cache/manifests/`:

| File | Purpose | When changed |
|---|---|---|
| `service-manifest.php` | Service class → domain mapping | module.json `solves` changes |
| `route-manifest.php` | Flat route table | proj.json or module.json `routes[]` change |
| `route-index.php` | Matcher-ready index (per-method buckets) | route manifest changes |
| `route-names.php` | Route name → path mapping | route manifest changes |
| `view-manifest.php` | View path cascade | proj.json or module.json `views` changes |
| `job-manifest.php` | Job class → solves domain mapping | job declarations change |
| `config-manifest.php` | Merged config (project over plugin) | config/*.php changes |
| `command-manifest.php` | Declared CLI commands, registered when the CLI starts | command list changes |

These files are gitignored and rebuilt on boot. With `BOOT_CACHE=1` set, the kernel skips recompiling unchanged manifests (~2ms → ~0.02ms per request under FPM).

## Entry points

The scaffolded project creates two entry points for HTTP and CLI:

| File | Surface | Command | Calls |
|---|---|---|---|
| `app/public/index.php` | HTTP web server | Any HTTP request | `$kernel->http()->handle($request)` |
| `app/cli/run.php` | Console | `hkm cli <name> <command>` | `$kernel->cli()->run($argv)` |

Each entry point requires the shared `app/bootstrap/app.php` to get a configured Kernel, then decides which surface to materialize. A third entry point for queue workers can be created manually when background jobs are needed.

::: details The three surfaces

The kernel supports three runtime surfaces:

- **HTTP** — web servers (PHP-FPM or OpenSwoole)
- **CLI** — console commands
- **Worker** — background job processors

Keeping wiring separate from execution means:

- All surfaces share one identical configuration
- An HTTP-only request never pays to load job/worker manifests
- A CLI process never loads HTTP stages
- Each surface can pass its own inputs (Request vs argv vs job queue)

:::

## Source

- [src/Kernel/](https://github.com/AlfaCode-Team/hkm-kernel/tree/main/src/Kernel) — Kernel source tree
- [templates/](https://github.com/AlfaCode-Team/hkm-kernel/tree/main/templates) — Scaffold templates
- [src/System/GlobalKernelProjectScaffolder.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/System/GlobalKernelProjectScaffolder.php) — What `hkm new` generates
