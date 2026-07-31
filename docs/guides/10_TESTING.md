# HKM Kernel — Testing Layer

> HKM Kernel's architecture is **designed for testability**. The domain has zero external
> dependencies. Port interfaces allow fake implementations. Scoped containers let you
> test modules in complete isolation.

---

## Test Layer Organization

```
modules/{name}/tests/
├── Unit/
│   ├── Domain/                  ← Pure PHP — no fakes needed — runs in < 1ms
│   │   ├── {Entity}Test.php
│   │   └── {ValueObject}Test.php
│   └── Application/
│       └── {Name}ServiceTest.php  ← Uses port fakes — no real DB/network
├── Integration/
│   ├── Persistence/
│   │   └── {Name}RepositoryTest.php  ← Real MySQL in CI
│   └── Http/
│       └── {Name}ControllerTest.php  ← TestKernel + real module
└── Fixtures/
    ├── InMemory{Name}Repository.php
    ├── Fake{Name}Service.php
    ├── FakeIntegrationEventBus.php
    └── FakeTransactionManager.php
```

---

## Test Double Taxonomy

| Type | Has logic | Asserts calls | When to use |
|---|---|---|---|
| **Stub** | Minimal (returns canned value) | No | Predictable return scenarios |
| **Fake** | Yes (working simplified impl) | No | Service layer tests — replace all ports |
| **Spy** | Passes through (records calls) | Yes | Verify events/calls were made |
| **Mock** | No (pre-programmed) | Yes | Strict call verification (rare) |
| **Dummy** | None | No | Satisfying a constructor that won't be called |

---

## Domain Unit Tests — No Fakes Needed

```php
<?php
declare(strict_types=1);

class InvoiceTest extends \PHPUnit\Framework\TestCase
{
    private function makeInvoice(): Invoice
    {
        return Invoice::create(
            InvoiceNumber::of('INV-2025-000001'),
            ClientId::of('client-123'),
            new \DateTimeImmutable('+30 days'),
        );
    }

    public function test_new_invoice_is_draft(): void
    {
        $this->assertEquals(InvoiceStatus::DRAFT, $this->makeInvoice()->status());
    }

    public function test_add_line_item_updates_subtotal(): void
    {
        $invoice = $this->makeInvoice();
        $invoice->addLineItem(LineItem::make('Widget', 2, Money::of(50, 'USD')));
        $invoice->addLineItem(LineItem::make('Service', 1, Money::of(200, 'USD')));
        $this->assertEquals(300.00, $invoice->subtotal()->value());
    }

    public function test_cannot_issue_without_line_items(): void
    {
        $this->expectException(\DomainException::class);
        $this->makeInvoice()->issue();
    }

    public function test_issue_records_domain_event(): void
    {
        $invoice = $this->makeInvoice();
        $invoice->addLineItem(LineItem::make('Widget', 1, Money::of(100, 'USD')));
        $invoice->releaseEvents(); // clear creation event
        $invoice->issue();

        $events = $invoice->releaseEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(InvoiceIssuedDomainEvent::class, $events[0]);
    }

    public function test_release_events_clears_buffer(): void
    {
        $invoice = $this->makeInvoice();
        $invoice->releaseEvents();
        $this->assertEmpty($invoice->releaseEvents());
    }
}
```

---

## Service Integration Tests — With Port Fakes

```php
<?php
declare(strict_types=1);

class InvoiceServiceTest extends \PHPUnit\Framework\TestCase
{
    private InvoiceService            $sut;
    private InMemoryInvoiceRepository $repo;
    private FakeTransactionManager    $txn;
    private FakeIntegrationEventBus   $bus;
    private DomainEventCollector      $collector;

    protected function setUp(): void
    {
        $this->repo      = new InMemoryInvoiceRepository();
        $this->txn       = new FakeTransactionManager();
        $this->bus       = new FakeIntegrationEventBus();
        $this->collector = new DomainEventCollector();

        $this->sut = new InvoiceService(
            repository:  $this->repo,
            transaction: $this->txn,
            collector:   $this->collector,
            eventBus:    $this->bus,
            identity:    Identity::asUser('user-1', 'tenant-abc'),
        );
    }

    public function test_creates_and_stores_invoice(): void
    {
        $result = $this->sut->create($this->validDto());

        $saved = $this->repo->find($result->invoiceId);
        $this->assertEquals(InvoiceStatus::ISSUED, $saved->status());
    }

    public function test_commits_transaction(): void
    {
        $this->sut->create($this->validDto());
        $this->assertTrue($this->txn->wasCommitted());
        $this->assertFalse($this->txn->wasRolledBack());
    }

    public function test_dispatches_integration_event_after_commit(): void
    {
        $this->sut->create($this->validDto());

        $events = $this->bus->dispatched(InvoiceCreatedIntegrationEvent::class);
        $this->assertCount(1, $events);
        $this->assertEquals('user-1', $events[0]->clientId);
    }

    public function test_rolls_back_and_discards_events_on_failure(): void
    {
        $this->repo->failOnNextSave();

        try {
            $this->sut->create($this->validDto());
        } catch (ServiceException) {}

        $this->assertTrue($this->txn->wasRolledBack());
        $this->assertEmpty($this->bus->all()); // no phantom events
    }

    public function test_unauthorized_user_cannot_create_for_another(): void
    {
        $dto = new CreateInvoiceDTO(clientId: 'different-user', /* ... */);
        $this->expectException(ServiceException::class);
        $this->sut->create($dto);
    }

    private function validDto(): CreateInvoiceDTO
    {
        return new CreateInvoiceDTO(
            clientId:  'user-1',
            dueDate:   '+30 days',
            lineItems: [['description' => 'Test', 'quantity' => 1, 'unitPrice' => 100.00]],
        );
    }
}
```

---

## Port Fakes

### InMemoryInvoiceRepository

```php
final class InMemoryInvoiceRepository implements InvoiceRepositoryContract
{
    private array $store       = [];
    private bool  $failOnSave  = false;

    public function find(string $id): Invoice
    {
        if (!isset($this->store[$id])) {
            throw new RepositoryException("Invoice [{$id}] not found");
        }
        return $this->store[$id];
    }

    public function save(Invoice $invoice): void
    {
        if ($this->failOnSave) {
            $this->failOnSave = false;
            throw new RepositoryException('Simulated save failure');
        }
        $this->store[$invoice->id()->value()] = $invoice;
    }

    public function failOnNextSave(): void { $this->failOnSave = true; }
    public function count(): int          { return count($this->store); }
    public function all(): array          { return $this->store; }
}
```

### FakeIntegrationEventBus

```php
final class FakeIntegrationEventBus implements IntegrationEventBusContract
{
    private array $dispatched = [];

    public function dispatch(IntegrationEventContract $event): void
    {
        $this->dispatched[] = $event;
    }

    public function dispatched(string $class): array
    {
        return array_values(array_filter(
            $this->dispatched,
            fn($e) => $e instanceof $class
        ));
    }

    public function all(): array { return $this->dispatched; }

    public function assertDispatched(string $class, int $times = 1): void
    {
        $count = count($this->dispatched($class));
        if ($count !== $times) {
            throw new \PHPUnit\Framework\AssertionFailedError(
                "Expected {$times} dispatch(es) of {$class}, got {$count}"
            );
        }
    }

    public function assertNotDispatched(string $class): void
    {
        $this->assertDispatched($class, 0);
    }
}
```

### FakeTransactionManager

```php
final class FakeTransactionManager implements TransactionManagerContract
{
    private bool $committed   = false;
    private bool $rolledBack  = false;

    public function begin(): void    {}
    public function commit(): void   { $this->committed  = true; }
    public function rollback(): void { $this->rolledBack  = true; }

    public function wasCommitted(): bool  { return $this->committed; }
    public function wasRolledBack(): bool { return $this->rolledBack; }

    public function wrap(callable $callback): mixed
    {
        $this->begin();
        try {
            $result = $callback();
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }
}
```

---

## Repository Integration Tests

```php
// Real MySQL — use transactional rollback to isolate each test
abstract class IntegrationTestCase extends \PHPUnit\Framework\TestCase
{
    protected static \PDO $pdo;

    protected function setUp(): void
    {
        // Wrap each test in a transaction — rolled back in tearDown()
        self::$pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        self::$pdo->rollBack(); // database clean for next test
    }

    protected function db(): DatabasePort
    {
        return new MySQLAdapter(self::$pdo);
    }
}

class InvoiceRepositoryTest extends IntegrationTestCase
{
    public function test_save_and_find_roundtrip(): void
    {
        $repo    = new InvoiceRepository($this->db(), Identity::admin());
        $invoice = Invoice::create(/* ... */);

        $repo->save($invoice);
        $found = $repo->find($invoice->id()->value());

        $this->assertTrue($invoice->id()->equals($found->id()));
    }
}
```

---

## Rules for Test Code

When writing or reviewing test code:

- **DO** use `InMemory*Repository` fakes — never real DB in service tests
- **DO** use `FakeIntegrationEventBus` — assert events after the service call
- **DO** assert both that the transaction committed AND that events were dispatched
- **DO** test the rollback path: `failOnNextSave()` → assert `wasRolledBack()` → assert no events
- **DO** wrap repository integration tests in transactions — roll back in `tearDown()`
- **DON'T** mock domain entities — instantiate them with real constructors
- **DON'T** use `@runInSeparateProcess` — it means your test has a global state problem
- **DON'T** test private methods — test behavior through public methods only
- **DON'T** share database state between tests — each test must be independent
- **DON'T** assert on exact SQL strings — assert on repository behavior (what was persisted)
