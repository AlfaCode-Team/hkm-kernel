# Request Lifecycle

Every request flows through a series of stages in the HTTP, CLI, or Worker pipeline. Understanding this flow helps you reason about where your code runs and how errors are handled.

## HTTP request lifecycle

When an HTTP request arrives, it passes through these stages in order:

```
Request
  ├─ CorrelationIdStage
  │  └─ Generate/propagate X-Correlation-ID (outermost so all responses carry it)
  │
  ├─ ErrorStage (wraps everything below)
  │  └─ Catch Throwables → classify → notify (Slack/Mail/DB/File) → HTTP error response
  │
  ├─ ObservabilityStage
  │  └─ RED metrics + root span (no-op unless MetricsPort/TracerPort is bound)
  │
  ├─ SecurityStage
  │  └─ Run SecurityGateway → attach Identity on allow
  │     DENIED REQUEST STOPS HERE (zero module cost)
  │
  ├─ after.security hooks
  │  └─ Module-registered cross-cutting stages (run in priority order)
  │
  ├─ ResolveStage
  │  └─ Match request path → route entry → service name
  │     Attach route_entry, route_name, route_host, route_face attributes
  │
  ├─ LoadStage
  │  └─ Calculate dependency graph from route's requires[] → OnDemandLoader
  │     Wire ONLY the modules this route needs (each registers once)
  │     Attach resolved modules to request → ModuleContainer
  │
  ├─ after.load hooks
  │  └─ Module-registered stages (observability, tenant context, etc.)
  │
  ├─ RouteFilterStage
  │  └─ Run route's declared filters[] in order (auth, throttle, hmac, shield)
  │     If a filter denies, return 401/403 (module is already loaded)
  │
  ├─ after.execute hooks (see below)
  │  └─ Module-registered stages: call $next, then decorate the controller's response
  │
  └─ ExecuteStage (terminal)
     └─ Resolve handler controller → instantiate (via ModuleContainer) → call method
        Pass route parameters positionally (path order); Request first unless RequestAware
        Return Response, which bubbles back out through every stage above
```

### Flow diagram

```
    Incoming Request
           │
           ▼
    ┌──────────────────────────────────────┐
    │  CorrelationIdStage                  │  Generates trace ID
    └──────────────────────────────────────┘
           │
           ▼
    ┌──────────────────────────────────────┐
    │  ErrorStage (wraps everything)  ◄────┼─── All Throwables caught here
    │  ├─ ObservabilityStage               │  
    │  │  ├─ SecurityStage                 │
    │  │  │  ├─ after.security hooks       │
    │  │  │  │  ├─ ResolveStage            │    If route found:
    │  │  │  │  │  ├─ LoadStage            │  Load modules
    │  │  │  │  │  │  ├─ after.load hooks  │  Run filters
    │  │  │  │  │  │  │  ├─ RouteFilterStage
    │  │  │  │  │  │  │  │  ├─ after.execute hooks
    │  │  │  │  │  │  │  │  │  └─ ExecuteStage ──► Response
    │  │  │  │  │  │  │  │  │      ▲
    │  │  │  │  │  │  │  │  └──────┘
    │  │  │  │  │  │  │  └─ (denied) ──────► 401/403
    │  │  │  │  │  │  └─ (no route) ────────► 404
    │  │  │  │  │  └─ (exception) ─────────► caught above
    │  │  │  │  └─ (denied) ────────────────► 403
    │  │  │  └─ (denied) ──────────────────► 401
    │  │  └─ (exception) ─────────────────► caught
    │  └─ (exception) ────────────────────► log + notify + 500
    └──────────────────────────────────────┘
           │
           ▼
         Response (bubbles back up through all stages)
           │
           ▼
    Back to client
```

## Stage details

### CorrelationIdStage

Generates a unique `X-Correlation-ID` header (or propagates one from an incoming request). This ID appears in:

- Response headers (`X-Correlation-ID`)
- Error notifications (Slack, mail, logs)
- Request logs
- Spans (if tracing is enabled)

Placed **outermost** so error responses carry the ID too.

### ErrorStage

Wraps every stage below it. When ANY stage throws a `Throwable`:

1. Classify the exception into a severity (critical, warning, info)
2. Create an `ErrorContext` with the exception + request + severity
3. Run the `ErrorPipeline`:
   - Notify (Slack, mail, database, file) based on severity
   - Return an HTTP error response with a machine-readable error envelope

The response always includes:

```json
{
  "error": {
    "code": "exception.code",
    "message": "Human readable message",
    "requestId": "correlation-id-here",
    "fields": {}  // 422 validation errors only
  }
}
```

### ObservabilityStage

Records RED metrics (Rate, Errors, Duration) and creates a root span if `MetricsPort` or `TracerPort` is bound to the `CoreContainer`. If no port is bound, this is a no-op.

### SecurityStage

Runs the `SecurityGateway`, which checks each security layer in order:

1. `CsrfTokenLayer` (the kernel's built-in layer)
2. Any plugin layer (e.g., `SessionAuthStage` from the Auth plugin)

A layer returns a `SecurityVerdict`:

- `allow($request)` — proceed, optionally attach an `Identity`
- `deny($code, $reason)` — stop and return HTTP error

**If a request is denied here, NO MODULES ARE LOADED.** This is the "zero-cost denial" rule—a blocked request pays only for the security layers it traverses.

After all layers pass, the `Identity` (if attached) is added to the request, and module loading can begin.

### ResolveStage

Looks up the request path + method in the `route-manifest.php` (compiled at boot from all module.json and proj.json routes).

If a match is found, the `route_entry` is attached to the request, containing:

- Route name
- Service class to instantiate
- Module domain the service solves
- Declared filters (auth, throttle, …)
- Required module domains (beyond what the module's own `requires[]` states)

If no match is found, execution stops here and a 404 is returned.

### LoadStage

1. Extracts the route's module domain + any `requires[]` domains
2. Calls `DependencyGraphCalculator` to build an ordered list of modules to load
3. Creates a request-scoped `ModuleContainer` (discarded at end of request)
4. Calls `OnDemandLoader` to register each module once into the container
5. Attaches the container to the request

At this point, every module the route needs is wired and its DI bindings are available.

### RouteFilterStage

(Inserted into the `after.load` hook position)

Looks at the route's `filters[]` and runs each one in order:

```json
{ "filters": ["auth", "throttle:60,1", "hmac"] }
```

Each filter is a stage that can allow or deny the request. They run as a nested onion, so:

1. `auth` runs first → if allowed, calls next
2. `throttle` runs → if allowed, calls next
3. `hmac` runs → if allowed, calls next → ExecuteStage runs

If any filter denies, the response is returned immediately. Module is already loaded (paid for), but the route handler doesn't run.

### ExecuteStage

1. Instantiates the handler controller via the `ModuleContainer` (so dependencies are injected)
2. Calls the handler method with route parameters
3. Returns the response

Controllers are typed `: Response` and return exactly one response type — the kernel's immutable `Response` class with named constructors (`json()`, `html()`, `redirect()`, `notFound()`, etc.).

### after.execute hooks

Module-registered stages that run directly around `ExecuteStage`, after the route filters: each calls `$next()`, receives the controller's response, and can change it before it leaves the pipeline. Use them for security headers, cache directives and response timing. See [HTTP pipeline](/http/pipeline#hook-slots-and-priorities).

## Error handling

Errors in any stage are caught by `ErrorStage` and handled by the `ErrorPipeline`:

```php
// Classify by severity
match($severity) {
    'critical'  => notify via all channels (slack, mail, db, file),
    'warning'   => notify via database + file,
    'info'      => file only,
}
```

The response is a JSON or HTML error envelope (depending on `Accept` header).

**The FileNotifier always runs**, even if other notifiers fail — it is the guaranteed fallback.

## CLI pipeline

The CLI entry point (`app/cli/run.php`) runs a different pipeline:

```
Console input (argv)
       │
       ▼
  CliPipeline
       │
       ├─ CliCorrelationIdStage (trace logging)
       ├─ AuthenticateCommandStage (optional auth for restricted commands)
       ├─ ResolveCommandStage (match argv → command class)
       ├─ LoadCommandStage (DI load required modules)
       ├─ ValidateArgsStage (check command arguments)
       ├─ ExecuteCommandStage (run command)
       └─ (error handling, output buffering)
       │
       ▼
 Console output (stdout/stderr) + exit code
```

Commands extend `AbstractCommand` and receive the input/output streams. Unlike HTTP, there is no response object — commands write directly to stdout.

## Worker pipeline

The queue worker (`app/worker/run.php`) loops continuously, pulling jobs and executing them:

```
Worker loop
       │
       ├─ Dequeue next job
       │  └─ Failed? → dead-letter
       │
       ├─ Validate job signature (if JOB_SIGNING_SECRET set)
       │  └─ Invalid? → dead-letter
       │
       ├─ Validate job class + payload
       │  └─ Invalid? → dead-letter
       │
       ├─ Load module container + essentials
       │
       ├─ Execute job handler
       │  └─ Throws? → classify + notify via ErrorPipeline → decide: retry or fail
       │
       ├─ Retry on max attempts exceeded
       │  └─ Exponential / linear / fixed backoff
       │
       └─ Loop → next job
```

Each job is isolated: the `ModuleContainer` is created fresh, the job loads its required modules, and the container is discarded after the job (or its retries).

## Request isolation (OpenSwoole)

Under OpenSwoole, multiple coroutines can run concurrently in one worker process. Isolation is maintained because:

- **Kernel** is app-lifetime (one per worker), immutable after build
- **CoreContainer** is app-lifetime, frozen after build
- **ModuleContainer** is created fresh per request/job, discarded after
- **Request** is immutable (every mutator returns a new instance)
- **Response** is immutable

Static properties and global state are **not used**. If a module or service uses a static property, it leaks between requests. This is why the framework is strict about scope isolation.

## Common patterns

### Accessing the container in a service

Services receive dependencies via constructor injection. Never resolve from the container yourself:

```php
// ✓ Good
public function __construct(
    private readonly DatabasePort $db,
    private readonly TransactionManager $tx,
) {}

// ✗ Bad — no container available and would bypass DI
$db = container()->make(DatabasePort::class);
```

### Accessing the request in a controller

If a controller needs the request, take it as a parameter:

```php
// ✓ Good
public function show(Request $request, string $id): Response { ... }

// ✗ Bad — no global request available
$request = Request::current();
```

### Adding a pipeline hook

Module `boot()` methods run once at startup and register hooks:

```php
public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
{
    // This runs ONCE when the kernel boots, not per request
    $http->hook('after.security', MySecurityStage::class, priority: 10);
}
```

## Source

- [src/Kernel/Pipelines/Http/HttpPipeline.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Pipelines/Http/HttpPipeline.php) — HTTP stage assembly
- [src/Kernel/Pipelines/Http/Stages/](https://github.com/AlfaCode-Team/hkm-kernel/tree/main/src/Kernel/Pipelines/Http/Stages) — Stage implementations
- [src/Kernel/Error/ErrorPipeline.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Error/ErrorPipeline.php) — Error handling
- [README.md — The request lifecycle](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/README.md#the-request-lifecycle)
