# HKM Kernel — Anti-Patterns and What NOT to Do

> This guide is as important as any other. Many patterns that work in Laravel/Symfony
> **violate HKM's Gated Demand Architecture** — the ones below are actively rejected by the
> framework at boot or runtime.

---

## ANTI-PATTERN 1 — Cross-Module Repository Access

**Wrong — importing another module's Repository:**
```php
// In PaymentModule's service — NEVER DO THIS
use InvoiceModule\Infrastructure\Persistence\InvoiceRepository; // FORBIDDEN

class PaymentService
{
    public function __construct(
        private InvoiceRepository $invoiceRepo, // ScopeViolationException at runtime
    ) {}
}
```

**Correct — inject the published contract:**
```php
use InvoiceModule\API\Contracts\InvoiceServiceContract; // Correct

class PaymentService
{
    public function __construct(
        private InvoiceServiceContract $invoices, // published interface — OK
    ) {}
}
```

**Why:** `InvoiceRepository` is internal to InvoiceModule. The scoped container throws
`ScopeViolationException` if any code outside InvoiceModule resolves it.

---

## ANTI-PATTERN 2 — Business Logic in Controller

**Wrong:**
```php
class InvoiceController
{
    public function create(Request $request): Response
    {
        $data = $request->body();

        // Business logic in controller — WRONG
        if ($data['amount'] <= 0) {
            return Response::unprocessable(['amount' => 'Must be positive']);
        }
        if (count($data['lineItems']) === 0) {
            return Response::unprocessable(['lineItems' => 'Required']);
        }

        $row = $this->db->execute('INSERT INTO invoices ...');
        return Response::json($row, 201);
    }
}
```

**Correct:**
```php
class InvoiceController
{
    public function create(Request $request): Response
    {
        $dto    = CreateInvoiceDTO::fromRequest($request); // validation here
        $result = $this->service->create($dto);            // business logic here
        return Response::json($result->toArray(), 201);    // just translation
    }
}
```

---

## ANTI-PATTERN 3 — Event Dispatch Before Commit

**Wrong — phantom events if commit fails:**
```php
public function create(CreateInvoiceDTO $dto): InvoiceResponseDTO
{
    $this->transaction->begin();
    $invoice = Invoice::create(...);
    $this->repository->save($invoice);

    // WRONG: dispatched before commit — if commit fails, phantom event was sent
    $this->eventBus->dispatch(new InvoiceCreatedIntegrationEvent(...));

    $this->transaction->commit();
}
```

**Correct — dispatch ONLY after commit:**
```php
public function create(CreateInvoiceDTO $dto): InvoiceResponseDTO
{
    $this->transaction->begin();
    try {
        $invoice = Invoice::create(...);
        $this->repository->save($invoice);
        $this->transaction->commit();
    } catch (\Throwable $e) {
        $this->transaction->rollback();
        $this->collector->discard(); // no phantom events
        throw $e;
    }

    // Only reached on successful commit
    $this->eventBus->dispatch(new InvoiceCreatedIntegrationEvent(...));
}
```

---

## ANTI-PATTERN 4 — Domain with External Dependencies

**Wrong — Eloquent / ORM in domain entity:**
```php
// In Domain/Entities/Invoice.php — NEVER
class Invoice extends \Illuminate\Database\Eloquent\Model // FORBIDDEN
{
    // Eloquent is infrastructure — domain cannot depend on it
}
```

**Wrong — importing any framework or vendor class in Domain:**
```php
namespace InvoiceModule\Domain\Entities;

use Illuminate\Support\Carbon; // FORBIDDEN in Domain
use Symfony\Component\Uid\Ulid; // FORBIDDEN in Domain
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort; // FORBIDDEN in Domain
```

**Correct — pure PHP in Domain:**
```php
namespace InvoiceModule\Domain\Entities;

// Only PHP built-ins and own Domain types
use InvoiceModule\Domain\ValueObjects\InvoiceId;
use InvoiceModule\Domain\ValueObjects\Money;
use InvoiceModule\Domain\Events\InvoiceCreatedDomainEvent;

final class Invoice
{
    // Pure PHP — no external dependencies
}
```

---

## ANTI-PATTERN 5 — Shared Database Tables Between Modules

**Wrong:**
```php
// PaymentModule queries InvoiceModule's table directly
class PaymentRepository
{
    public function findWithInvoice(string $paymentId): array
    {
        return $this->db->query(
            // FORBIDDEN — payments module must not know invoice table schema
            'SELECT p.*, i.number, i.total_cents
             FROM payments p
             JOIN invoices i ON p.invoice_id = i.id  ← cross-module table join
             WHERE p.id = :id',
            ['id' => $paymentId]
        );
    }
}
```

**Correct — each module owns its tables, reads cross-module data via contract:**
```php
class PaymentService
{
    public function findWithInvoice(string $paymentId): PaymentWithInvoiceDTO
    {
        $payment = $this->paymentRepository->find($paymentId);
        $invoice = $this->invoiceService->find($payment->invoiceId()); // via contract
        return new PaymentWithInvoiceDTO($payment, $invoice);
    }
}
```

---

## ANTI-PATTERN 6 — Routes in PHP Files

**Wrong:**
```php
// In Provider.php or anywhere — DO NOT define routes in PHP
$router->get('/api/invoices', [InvoiceController::class, 'index']);
$router->post('/api/invoices', [InvoiceController::class, 'create']);
```

**Correct — routes in module.json:**
```json
{
  "routes": [
    { "method": "GET",  "path": "/api/invoices", "handler": "InvoiceController@index"  },
    { "method": "POST", "path": "/api/invoices", "handler": "InvoiceController@create" }
  ]
}
```

---

## ANTI-PATTERN 7 — Skipping a Job by Throwing

**Wrong — throwing causes retry:**
```php
public function handle(JobPayload $payload): JobResult
{
    $dto     = GenerateReportPayload::from($payload->data());
    $invoice = $this->invoices->find($dto->invoiceId);

    if ($invoice->isPaid()) {
        throw new \RuntimeException('Invoice already paid — skip'); // causes retry!
    }
}
```

**Correct — return skipped:**
```php
public function handle(JobPayload $payload): JobResult
{
    $dto     = GenerateReportPayload::from($payload->data());
    $invoice = $this->invoices->find($dto->invoiceId);

    if ($invoice->isPaid()) {
        return JobResult::skipped('Invoice already paid — no report needed');
    }

    // ... proceed
}
```

---

## ANTI-PATTERN 8 — Using Static Properties for State

**Wrong — static state leaks between requests in FPM:**
```php
class InvoiceService
{
    private static array $cache = []; // FORBIDDEN — leaks between requests!

    public function find(string $id): InvoiceResponseDTO
    {
        if (isset(self::$cache[$id])) {
            return self::$cache[$id]; // returns stale data from previous request
        }
        // ...
    }
}
```

**Correct — use CachePort:**
```php
class InvoiceQueryService
{
    public function find(string $id): InvoiceResponseDTO
    {
        return $this->cache->remember(
            key:      "invoice:v1:{$id}",
            ttl:      300,
            callback: fn() => InvoiceResponseDTO::from($this->repository->find($id)),
        );
    }
}
```

---

## ANTI-PATTERN 9 — Authorization in the SecurityGateway

**Wrong — business authorization in a SecurityLayer:**
```php
class InvoiceOwnershipLayer implements SecurityLayerContract
{
    public function check(Request $request): SecurityVerdict
    {
        $invoiceId = $request->routeParam('id');
        $invoice   = $this->invoiceRepo->find($invoiceId); // WRONG — repo in gateway

        if ($invoice->clientId() !== $request->identity()->userId) {
            return SecurityVerdict::deny(403, 'Not your invoice');
        }
        return SecurityVerdict::allow($request);
    }
}
```

**Correct — ownership check in the Service layer:**
```php
class InvoiceService
{
    public function find(string $id): InvoiceResponseDTO
    {
        $invoice = $this->repository->find($id);

        // Ownership check belongs here — Service has Identity
        if ($invoice->clientId()->value() !== $this->identity->userId
            && !$this->identity->hasPermission('invoice:view-all')) {
            throw new ServiceException('invoice.access.denied');
        }

        return InvoiceResponseDTO::from($invoice);
    }
}
```

---

## ANTI-PATTERN 10 — Missing `declare(strict_types=1)`

**Wrong — PHP will silently coerce wrong types:**
```php
<?php
// No declare — PHP coerces types silently

function calculateTotal(float $amount, int $quantity): float
{
    return $amount * $quantity;
}

calculateTotal('100', '3'); // PHP coerces silently — potential bugs
```

**Correct — strict types at the top of every file:**
```php
<?php
declare(strict_types=1); // First line, always

function calculateTotal(float $amount, int $quantity): float
{
    return $amount * $quantity;
}

calculateTotal('100', '3'); // TypeError thrown immediately — caught early
```

---

## ANTI-PATTERN 11 — Float for Money

**Wrong:**
```php
$price = 9.99;
$tax   = 0.1;
$total = $price + ($price * $tax); // 10.989000000000001 — floating point error!
```

**Correct — use Money value object (cents as integer):**
```php
$price = Money::of(9.99, 'USD'); // stored as 999 cents
$tax   = $price->multiply(0.1);  // 99 cents (rounded correctly)
$total = $price->add($tax);      // 1098 cents = $10.98
```

---

## ANTI-PATTERN 12 — Declaring Config in Code, Not module.json

**Wrong:**
```php
class Provider implements ModuleContract
{
    public function register(ModuleContainer $container): void
    {
        $container->bind(InvoiceService::class, fn($c) =>
            new InvoiceService(
                currency: env('INVOICE_CURRENCY'), // config used but NOT declared in module.json!
            )
        );
    }
}
```

**Correct — declare ALL config in module.json:**
```json
{
  "config": ["INVOICE_CURRENCY"]
}
```
```php
// Now the kernel validates INVOICE_CURRENCY exists at boot time
// If missing: boot fails with a descriptive error listing the variable and which module needs it
```

---

## Common Mistakes to Avoid

| If you are about to... | Stop. Do this instead. |
|---|---|
| Extend `Model` in Domain/ | Use a plain PHP class with static factory methods |
| Import another module's Repository | Import its contract from `API/Contracts/` |
| Put SQL in a Service | Move it to the Repository |
| Put authorization in a Controller | Move it to the Service |
| Dispatch an event inside a `try` block | Move dispatch after the `try/catch` |
| Use `static` properties for caching | Use `CachePort` |
| Define routes in PHP | Define them in `module.json` |
| Use `float` for money | Use `Money::of()` with integer cents |
| Throw in a job to skip processing | Return `JobResult::skipped($reason)` |
| Use `===` for token comparison | Use `hash_equals()` |
| Extend `AbstractCommand` with `CommandContract` | Extend `AbstractCommand` from php-io-cli |
| Register a command as `$cli->command('name', Cmd::class)` | Use `$cli->command(Cmd::class)` (class-string only) |
| Call `CoreContainer::getInstance()` | Inject via DI — `getInstance()` throws `LogicException` |
| Bind services after the kernel materializes | All bindings happen in `register()` before the core is frozen |
| Forget to call `$container->reset()` in Swoole workers | Call `reset()` at end of every request |

---

## ANTI-PATTERN 13 — Using Global Container Singletons

**Wrong — calling `getInstance()` anywhere:**
```php
// FORBIDDEN — both containers have disabled this method
$core   = CoreContainer::getInstance();    // ← LogicException
$module = ModuleContainer::getInstance();  // ← LogicException
```

**Why it is disabled:** In Swoole workers, multiple coroutines share the same process memory.
A globally shared container instance would cause race conditions and data leaks between requests.

**Correct — always receive the container via dependency injection or constructor:**
```php
// CoreContainer is injected into each pipeline when the kernel materializes
// ModuleContainer is created by OnDemandLoader per request — receive it as a method parameter

public function handle(Request $request, callable $next): Response
{
    $container = $request->getAttribute('container'); // injected by LoadStage
    $service   = $container->make(InvoiceServiceContract::class);
    // ...
}
```

---

## ANTI-PATTERN 14 — Binding Services After Kernel::build()

**Wrong — writing to CoreContainer after it is frozen:**
```php
// The kernel calls $core->freeze() when it materializes — on the first
// http()/cli()/workerLoop()/container() call, after all modules boot.
// Any write after this throws LogicException.

$kernel = $app->build();   // compile-only — NOT yet frozen
$kernel->http();           // materializes + freezes the container

// WRONG — attempting to add a binding after the kernel has materialized:
$kernel->container()->singleton(SomeService::class, fn() => new SomeService());
// ↑ LogicException: Cannot bind to a frozen CoreContainer
```

**Correct — all bindings registered inside `Provider::register()`:**
```php
class Provider implements ModuleContract
{
    public function register(ModuleContainer $container): void
    {
        // All bindings go HERE — before the kernel materializes and freezes the CoreContainer
        $container->bind(InvoiceServiceContract::class, fn($c) => new InvoiceService(...));
    }
}
```

---

## ANTI-PATTERN 15 — Resolving Internal Bindings from the Wrong Scope

**Wrong — resolving another module's internal binding directly:**
```php
// In ExecuteStage or any code outside InvoiceModule:
$repo = $container->make(InvoiceRepository::class);
// ↑ ScopeViolationException — InvoiceRepository is internal to InvoiceModule
```

**Correct — use `makeInScope()` with the owning module's scope, or use published contract:**
```php
// Option 1: use makeInScope when ExecuteStage resolves a controller
$controller = $container->makeInScope(InvoiceController::class, 'invoice.generation');

// Option 2: resolve via published contract from any scope
$service = $container->make(InvoiceServiceContract::class); // public — always OK
```
