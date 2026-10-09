---
outline: [2, 3]
---

# Anti-Patterns

This page documents common mistakes when building HKM Kernel applications, why they're wrong, and how to fix them. These patterns are actively rejected by the framework or will cause bugs in production.

## Architecture & Modules

### Never Access Another Module's Internals

**Wrong** — importing another module's internal classes:

```php
use InvoiceModule\Infrastructure\Persistence\InvoiceRepository;

class PaymentService
{
    public function __construct(
        private InvoiceRepository $invoiceRepo, // ScopeViolationException at runtime
    ) {}
}
```

**Correct** — use the published contract:

```php
use InvoiceModule\API\Contracts\InvoiceServiceContract;

class PaymentService
{
    public function __construct(
        private InvoiceServiceContract $invoices, // public interface
    ) {}
}
```

**Why:** Repositories are bound as `internal` to their module. The scoped container throws `ScopeViolationException` if any code outside the module tries to resolve them. Every module owns its data access layer; cross-module data sharing happens through published contracts only.

### Never Repeat Routes with Copy-Paste Prefix/Filter

**Wrong** — the prefix and filter copied onto every route:

```json
{
  "routes": [
    { "method": "GET",    "path": "/admin/users",      "handler": "…@index",   "filters": ["auth"] },
    { "method": "POST",   "path": "/admin/users",      "handler": "…@store",   "filters": ["auth"] },
    { "method": "DELETE", "path": "/admin/users/{id}", "handler": "…@destroy", "filters": ["auth"] }
  ]
}
```

**Correct** — use a group to state it once:

```json
{
  "groups": [
    { "prefix": "/admin/users", "filters": ["auth"],
      "routes": [
        { "method": "GET",    "path": "",      "handler": "…@index"   },
        { "method": "POST",   "path": "",      "handler": "…@store"   },
        { "method": "DELETE", "path": "/{id}", "handler": "…@destroy" }
      ] }
  ]
}
```

**Why:** Grouping costs nothing at request time — it's expanded at boot into the flat routes the pipeline uses. Hiding duplication in data makes maintenance easier and the contract clearer.

### Never Bind Services After the Kernel Materializes

**Wrong** — attempting to bind after the first entry point call:

```php
$kernel = Kernel::configure()->withModules([...])->build();
$kernel->http(); // materialize — freezes the CoreContainer

$kernel->container()->bind(MyService::class, fn() => new MyService());
// LogicException: Cannot bind to a frozen CoreContainer
```

**Correct** — all bindings must happen in `Provider::register()`:

```php
class Provider implements ModuleContract
{
    public function register(ModuleContainer $container): void
    {
        // Every binding happens here, before the kernel materializes
        $container->bind(MyServiceContract::class, fn($c) => new MyService(...));
    }
}
```

**Why:** The CoreContainer is frozen after the first entry point call to prevent race conditions under OpenSwoole. Bindings must all be declared at module registration time.

## Routing

### Never Use `{param:any}` for a File Path

**Wrong** — `any` allows traversal:

```json
{ "method": "GET", "path": "/download/{file:any}", "handler": "…@download" }
```

A request like `/download/../../etc/passwd` matches, and the controller receives `../../etc/passwd`.

**Correct** — use `path` type (blocks `..` and control characters):

```json
{ "method": "GET", "path": "/download/{file:path}", "handler": "…@download" }
```

**Why:** The `any` type is a bare catch-all for backward compatibility. The `path` type is identical but rejects directory traversal attempts (`..`) and control characters, making it safe for filesystem operations.

### Never Hard-Code a Path in a Link

**Wrong** — a literal path silently 404s if the project moves the page:

```php
return Response::redirect('/register');
```

**Correct** — use a named route:

```php
return Response::redirect(route('auth.register'));
```

**Why:** Projects override routes by name. A literal path breaks when a project disables or moves the endpoint. A name survives overrides because the project's version inherits the plugin route's name.

### Never Declare a Route for a Host Not in `proj.json` `domains`

**Wrong:**

```json
{
  "groups": [
    { "domain": "unknownhost.com", "routes": [...] }
  ]
}
```

**Correct** — register hosts in `proj.json`:

```json
{
  "domains": ["example.com", "www.example.com"],
  "groups": [
    { "domain": "example.com", "routes": [...] }
  ]
}
```

**Why:** The boot pipeline validates every declared domain against `proj.json` domains. A route for an unregistered host is unreachable — the request would go to a different project long before routing happens.

## Business Logic & Layers

### Never Put Business Logic in a Controller

**Wrong** — validation and database calls in the controller:

```php
public function create(Request $request): Response
{
    $data = $request->input();

    if (!$data['amount'] || $data['amount'] <= 0) {
        return Response::unprocessable(['amount' => 'Must be positive']);
    }

    $row = $this->db->execute('INSERT INTO invoices ...');
    return Response::json($row, 201);
}
```

**Correct** — delegate to a service:

```php
public function create(Request $request): Response
{
    $dto    = CreateInvoiceDTO::fromRequest($request); // validation
    $result = $this->service->create($dto);             // logic
    return Response::json($result->toArray(), 201);    // translation
}
```

**Why:** Controllers are translation layers. Logic belongs in services where it can be tested, reused, and composed. Keeping controllers to 3 lines makes them obviously correct.

### Never Put Authorization in the SecurityGateway

**Wrong** — business authorization in a security layer:

```php
class InvoiceOwnershipLayer implements SecurityLayerContract
{
    public function check(Request $request): SecurityVerdict
    {
        $invoice = $this->invoiceRepo->find($request->attribute('route_params')['id'] ?? '');

        if ($invoice->clientId !== $request->identity()->userId) {
            return SecurityVerdict::deny(403, 'Not your invoice');
        }
        return SecurityVerdict::allow($request);
    }
}
```

**Correct** — ownership checks belong in the service:

```php
public function find(string $id): InvoiceResponseDTO
{
    $invoice = $this->repository->find($id);

    if ($invoice->clientId()->value() !== $this->identity->userId
        && !$this->identity->hasPermission('invoice:view-all')) {
        throw new ServiceException('invoice.access.denied');
    }

    return InvoiceResponseDTO::from($invoice);
}
```

**Why:** The SecurityGateway runs before any module loads and costs nothing on denial. Business authorization requires domain knowledge (invoice ownership) that only the service has. The gateway is for authentication (who are you?); the service handles authorization (are you allowed?).

### Never Let Domain Classes Import External Dependencies

**Wrong** — using framework classes in a domain entity:

```php
namespace InvoiceModule\Domain\Entities;

use Illuminate\Support\Carbon;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;

class Invoice
{
    // FORBIDDEN — domain depends on Eloquent and the framework
}
```

**Correct** — domain uses only PHP built-ins and its own types:

```php
namespace InvoiceModule\Domain\Entities;

use InvoiceModule\Domain\ValueObjects\{InvoiceId, Money};

final class Invoice
{
    private function __construct(
        private readonly InvoiceId $id,
        private readonly Money $total,
        private readonly \DateTimeImmutable $createdAt,
    ) {}
}
```

**Why:** Domain logic is business logic and should be independent of infrastructure. This makes it testable, portable, and easy to reason about. If a domain class needs a port, the design is wrong — move the concern to the service layer.

### Never Query Another Module's Tables Directly

**Wrong** — `PaymentModule` queries `InvoiceModule`'s schema:

```php
class PaymentRepository
{
    public function findWithInvoice(string $id): array
    {
        return $this->db->query(
            'SELECT p.*, i.number
             FROM payments p
             JOIN invoices i ON p.invoice_id = i.id
             WHERE p.id = ?',
            [$id]
        );
    }
}
```

**Correct** — cross-module data comes through published contracts:

```php
class PaymentService
{
    public function findWithInvoice(string $id): PaymentWithInvoiceDTO
    {
        $payment = $this->paymentRepository->find($id);
        $invoice = $this->invoiceService->find($payment->invoiceId());
        return new PaymentWithInvoiceDTO($payment, $invoice);
    }
}
```

**Why:** Each module owns its schema. Cross-module joins couple modules at the database level, making it impossible to split them apart later or swap implementations. Published contracts are the seam.

## Data & Money

### Never Use `float` for Money

**Wrong** — floating-point arithmetic causes rounding errors:

```php
$price = 9.99;
$tax   = $price * 0.1;
$total = $price + $tax; // 10.989000000000001 (wrong!)
```

**Correct** — use a Money value object with integer cents:

```php
$price = Money::of(9.99, 'USD');   // stored as 999 cents internally
$tax   = $price->multiply(0.1);    // 99 cents (rounded correctly)
$total = $price->add($tax);        // 1098 cents = $10.98
```

**Why:** Floating-point has precision limits that make it unsuitable for financial calculations. Integers (cents) have unlimited precision and guarantee correctness.

### Never Store Money in `DECIMAL` Without Specifying Precision

**Wrong:**

```php
$table->decimal('amount'); // No precision specified — defaults vary by database
```

**Correct:**

```php
$table->decimal('amount_cents', precision: 14, scale: 0); // integer cents
// or
$table->decimal('amount', precision: 10, scale: 2); // two decimal places
```

**Why:** A `DECIMAL` without precision is a database-specific type that may silently truncate values. Always specify precision and scale explicitly.

## Events & Transactions

### Never Dispatch Integration Events Inside a Try Block

**Wrong** — phantom events if commit fails:

```php
public function create(CreateDTO $dto): ResultDTO
{
    $this->transaction->begin();
    try {
        $entity = Entity::create(...);
        $this->repository->save($entity);

        // WRONG: dispatched inside try, but before commit
        $this->eventBus->dispatch(new EntityCreatedIntegrationEvent(...));

        $this->transaction->commit();
    } catch (\Throwable $e) {
        $this->transaction->rollback();
        throw $e;
    }
}
```

**Correct** — dispatch only after successful commit:

```php
public function create(CreateDTO $dto): ResultDTO
{
    $this->transaction->begin();
    try {
        $entity = Entity::create(...);
        $this->repository->save($entity);
        $this->transaction->commit();
    } catch (\Throwable $e) {
        $this->transaction->rollback();
        $this->collector->discard(); // no phantom events
        throw $e;
    }

    // Only reached on successful commit
    $this->eventBus->dispatch(new EntityCreatedIntegrationEvent(...));
}
```

**Why:** If an event is dispatched before commit and the commit fails, the event was sent but the change never happened. A consumer acting on the event will find the data missing. Events must be dispatched ONLY after a successful commit — they are notifications of completed changes, not predictions.

### Never Forget to Call `$collector->discard()` on Rollback

**Wrong** — phantom domain events in the buffer:

```php
try {
    $entity = Entity::create(...);
    $events = $entity->releaseEvents();
    $this->collector->collect(...$events); // buffered
    $this->repository->save($entity);
    $this->transaction->commit();
} catch (\Throwable $e) {
    $this->transaction->rollback();
    // Domain events are still in the collector — never discarded!
    throw $e;
}
```

**Correct** — clear the buffer on rollback:

```php
try {
    $entity = Entity::create(...);
    $events = $entity->releaseEvents();
    $this->collector->collect(...$events);
    $this->repository->save($entity);
    $this->transaction->commit();
} catch (\Throwable $e) {
    $this->transaction->rollback();
    $this->collector->discard(); // clear the buffer
    throw $e;
}
```

**Why:** Domain events collected but never committed must be discarded. Otherwise they appear in the next successful request and trigger integrations for changes that never happened.

## Background Jobs

### Never Throw an Exception to Skip a Job

**Wrong** — throwing causes a retry:

```php
public function handle(JobPayload $payload): JobResult
{
    $entity = $this->repository->find($payload->data['id']);

    if ($entity->isProcessed()) {
        throw new \RuntimeException('Already processed — skip');
        // Worker treats this as a failure and retries
    }
}
```

**Correct** — return `JobResult::skipped()`:

```php
public function handle(JobPayload $payload): JobResult
{
    $entity = $this->repository->find($payload->data['id']);

    if ($entity->isProcessed()) {
        return JobResult::skipped('Already processed — no action needed');
    }

    // ... proceed
}
```

**Why:** Throwing signals a transient failure (retry). Skipping signals a legitimate reason not to process. Use the correct signal so the retry strategy works as intended.

## OpenSwoole Safety

### Never Use Static Properties for State

**Wrong** — static state leaks between requests in Swoole:

```php
class InvoiceService
{
    private static array $cache = [];

    public function find(string $id): Invoice
    {
        if (isset(self::$cache[$id])) {
            return self::$cache[$id]; // stale data from request A while handling request B!
        }
    }
}
```

**Correct** — use request-scoped bindings or CachePort:

```php
class InvoiceService
{
    public function find(string $id): Invoice
    {
        return $this->cache->remember(
            key: "invoice:{$id}",
            ttl: 300,
            callback: fn() => $this->repository->find($id),
        );
    }
}
```

**Why:** In Swoole, multiple coroutines run in the same process memory. A static property is shared across all of them, causing request A's cached data to be visible in request B. Use `CachePort` for request-independent caching or instance properties for request-scoped state.

### Never Keep Request Data Somewhere That Outlives the Request

**Wrong** — request state parked on something long-lived:

```php
final class CurrentTenant
{
    public static ?string $id = null;      // static: shared by every request in the worker
}

// In withPorts(): one closure, one instance for the whole worker
DatabasePort::class => fn () => new TenantAwareDatabase(CurrentTenant::$id),
```

**Correct** — keep it in the request's own `ModuleContainer`, which the kernel builds fresh for every request and job:

```php
public function register(ModuleContainer $container): void
{
    $container->bind(TenantDatabase::class, fn ($c) =>
        new TenantDatabase($c->make(Identity::class)->tenantId));
}
```

**Why:** The kernel never reuses a `ModuleContainer`, so module bindings cannot leak; there is no container to `reset()` (and `Kernel::requestTeardown()` is currently a no-op). Leaks come from your own `static` properties, globals, and request data placed in the `CoreContainer` or a `withPorts()` closure. Under OpenSwoole those live for the whole worker, so request B sees request A's tenant.

## Configuration & Secrets

### Never Use Env Vars Without Declaring Them in `module.json`

**Wrong** — the module uses an env var that is never declared:

```php
class Provider implements ModuleContract
{
    public function register(ModuleContainer $container): void
    {
        $container->bind(MyService::class, fn($c) =>
            new MyService(
                currency: env('INVOICE_CURRENCY'), // not declared in module.json!
            )
        );
    }
}
```

**Correct** — declare every env var in module.json:

```json
{
  "config": ["INVOICE_CURRENCY"]
}
```

**Why:** The kernel validates every env var in `config[]` at boot. A missing var fails loudly with a clear error message. If you don't declare it, an operator won't know it's required, and you'll get a silent runtime error when the var is used.

### Never Store Secrets in Config Files

**Wrong:**

```php
// config/database.php
return [
    'password' => 'my-secret-password',
];
```

**Correct** — load from env or a secrets manager:

```php
return [
    'password' => env('DATABASE_PASSWORD'),
];
```

**Why:** Config files get committed to version control. Secrets should never be in version control. Use environment variables or a secrets manager (AWS Secrets Manager, Vault, etc.).

## Deployment

### Never Deploy to PHP-FPM Without `BOOT_CACHE=1`

**Wrong:**

```bash
# Without caching, every request recompiles every manifest
# ~2ms per request × 1000 req/sec = 2 seconds of CPU per second
```

**Correct:**

```bash
# In .env.production
BOOT_CACHE=1
```

**Why:** BOOT_CACHE skips recompiling manifests when they're current. With it, boot time drops from 2ms to 0.02ms (86× faster). This is essential under PHP-FPM, which re-executes the bootstrap on every request.

### Never Deploy Code Without Running Tests

**Wrong:**

```bash
git push production
# Oops, application is broken
```

**Correct:**

```bash
phpunit              # Run all tests
phpstan              # Type checking
composer install     # Resolve dependencies
git push production  # Only after tests pass
```

**Why:** Tests catch logic errors, type mismatches, and wiring issues before they reach production. Every deploy should have a pipeline that runs tests and type checking first.

## Quick Reference

| Mistake | Fix |
|---------|-----|
| Import another module's internal class | Use published contract from `API/Contracts/` |
| Copy prefix/filter onto every route | Use `groups[]` to state it once |
| Bind services after `Kernel::build()` | All bindings in `Provider::register()` |
| Use `{file:any}` for file paths | Use `{file:path}` (blocks `..`) |
| Hard-code `/register` in a link | Use `route('auth.register')` |
| Put authorization in SecurityGateway | Move to the Service layer |
| Import framework classes in Domain/ | Use only PHP built-ins + own types |
| Use `float` for money | Use `Money::of()` with integer cents |
| Dispatch events inside try block | Dispatch after `commit()` succeeds |
| Throw to skip a job | Return `JobResult::skipped()` |
| Static state in Swoole | Use `CachePort` or instance properties |
| Request data in statics, globals or `withPorts()` | Bind it in a module's `register()` (fresh per request) |
| Use env vars without declaring them | Add to `module.json` `config[]` |
| Store secrets in config files | Load from env or secrets manager |
| Deploy to FPM without BOOT_CACHE | Set `BOOT_CACHE=1` in `.env` |

## Source

- [docs/guides/13_ANTIPATTERNS.md](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/docs/guides/13_ANTIPATTERNS.md)
- [Architecture Guide](/architecture/gda)
- [Modules Guide](/modules/module-contract)
- [Security Gateway](/security/gateway)
