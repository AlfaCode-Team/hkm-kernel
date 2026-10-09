# Service Layer

The service layer orchestrates business logic by composing domain objects and repositories. Services are **stateless, request-scoped handlers** that use a strict pattern to ensure atomicity and event consistency: begin a transaction, do the work, commit if successful, and dispatch integration events ONLY after the commit.

## Service Responsibility and Pattern

Each service method:

1. **Asserts authorization** — check the identity's permissions FIRST
2. **Begins transaction collection** — `$collector->beginCollection()` activates the event buffer
3. **Starts the database transaction** — `$transaction->begin()` opens a unit of work
4. **Performs the work** — repository calls, domain logic, entity state transitions
5. **Collects domain events** — `$collector->collect()` as entities emit them
6. **Commits on success** — `$transaction->commit()` makes changes durable
7. **Dispatches integration events** — `$eventBus->dispatch()` ONLY after commit succeeds
8. **Rolls back and clears on failure** — `$transaction->rollback()` and `$collector->discard()` in catch

## The Complete Pattern

```php
<?php declare(strict_types=1);
namespace Shop\Invoice\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\{DomainEventCollector, EventBus};
use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Shop\Invoice\API\Contracts\InvoiceServiceContract;
use Shop\Invoice\Domain\Entities\Invoice;
use Shop\Invoice\Domain\ValueObjects\Money;
use Shop\Invoice\API\IntegrationEvents\InvoiceCreatedIntegrationEvent;
use Shop\Invoice\Infrastructure\Persistence\InvoiceRepository;

final class InvoiceService implements InvoiceServiceContract
{
    public function __construct(
        private readonly InvoiceRepository   $repository,
        private readonly TransactionManager  $transaction,
        private readonly DomainEventCollector $collector,
        private readonly EventBus            $eventBus,
        private readonly Identity            $identity,
    ) {}

    public function create(CreateInvoiceDTO $dto): InvoiceResponseDTO
    {
        // 1. Authorization — FIRST, before any side effect
        if ($dto->clientId !== $this->identity->userId
            && !$this->identity->hasPermission('invoice:create-for-others')) {
            throw new ServiceException(
                'Not authorized to create invoices for other clients.',
                layer:   'service.invoice.create',
                context: ['clientId' => $dto->clientId],
            );
        }

        // 2. Begin collection and transaction
        $this->collector->beginCollection();
        $this->transaction->begin();

        try {
            // 3. Domain logic — create the entity
            $invoice = Invoice::create(
                clientId: $dto->clientId,
                amount:   Money::of($dto->amount, $dto->currency),
            );

            // 4. Collect any domain events the entity emitted
            foreach ($invoice->releaseEvents() as $event) {
                $this->collector->collect($event);
            }

            // 5. Persist the entity
            $this->repository->save($invoice);

            // 6. Commit — makes changes durable
            $this->transaction->commit();

        } catch (\Throwable $e) {
            // 7. Rollback on ANY failure (domain logic, DB, etc.)
            $this->transaction->rollback();

            // 8. Discard collected events — they never happened
            $this->collector->discard();

            // Re-throw as a ServiceException for the pipeline
            throw new ServiceException(
                'Failed to create invoice.',
                layer:   'service.invoice.create',
                context: ['clientId' => $dto->clientId],
                previous: $e,
            );
        }

        // 9. Integration events — ONLY after commit succeeds
        // Release any collected domain events and turn them into integration events
        $this->eventBus->dispatch(new InvoiceCreatedIntegrationEvent(
            invoiceId: $invoice->id()->value(),
            clientId:  $invoice->clientId(),
            amount:    $invoice->amount()->value(),
            currency:  $invoice->amount()->currency(),
            occurredAt: (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339),
        ));

        return InvoiceResponseDTO::from($invoice);
    }
}
```

## Key Components

### TransactionManager — Nesting-Aware Transactions

The `TransactionManager` wraps database transactions and tracks nesting depth so composed services never double-commit. Call `begin()` / `commit()` / `rollback()` to bracket your work.

```php
public function begin(): void
public function commit(): void
public function rollback(): void
public function inTransaction(): bool
```

**Nesting behavior:**

- `begin()` increments a depth counter; only the first call actually opens a transaction
- `commit()` decrements the counter; only when depth reaches 0 does the database commit
- `rollback()` sets depth to 0 immediately and rolls back, unwinding the entire stack

**Example:**

```php
$this->transaction->begin();    // depth = 1, db->beginTransaction()
try {
    $this->otherService->create($data);  // begin() → depth = 2, no db call
                                         // commit() → depth = 1, no db call
    $this->transaction->commit();        // depth = 0, db->commit()
} catch (\Throwable $e) {
    $this->transaction->rollback();      // depth = 0, db->rollback()
    throw;
}
```

### DomainEventCollector — Transaction-Scoped Event Buffer

The collector accumulates domain events during a transaction. On commit, the service reads them and dispatches integration events. On rollback, they are discarded so phantom events never escape failed work.

```php
public function beginCollection(): void
public function collect(DomainEventContract $event): void
public function release(): array          // returns and clears
public function discard(): void           // clears without returning
public function count(): int
```

**Workflow:**

1. Call `beginCollection()` before the transaction begins to activate the buffer
2. Services call `collect()` as domain objects emit events
3. On success, read events (from `release()`) and dispatch them after commit
4. On failure, call `discard()` in the catch block to clear the buffer

::: warning No Collect Outside Collection
Calling `collect()` when no collection is active throws `\LogicException`. Always call `beginCollection()` first in a transaction-bearing method.
:::

### EventBus — Isolated Listener Dispatch

The `EventBus` dispatches integration events to subscribed listeners. Each listener is isolated: if one fails, others still receive the event.

**Core methods:**

```php
public function subscribe(string $eventName, string $listenerClass): void
public function dispatch(IntegrationEventContract $event): array  // returns [class => Throwable]
public function forContainer(ContainerInterface $container): self
```

**Key guarantees:**

- Listeners are resolved from a `ModuleContainer`, so dependencies are injected
- Listener failures are logged but do NOT stop other listeners
- The method returns failures as `[listenerClass => exception]`, so callers can detect issues
- If a logger is bound, failures are logged; otherwise they're silent

**Listener isolation example:**

```php
// If ListenerA throws, ListenerB still runs
$failures = $this->eventBus->dispatch(new InvoiceCreatedIntegrationEvent(...));

// Check if any listener failed
if (!empty($failures)) {
    // Log or re-queue the event for retry
}
```

## Common Service Patterns

### Mutation with Domain Events

When an entity method triggers a domain event, collect it:

```php
$invoice->issue();  // emits InvoiceIssuedDomainEvent

foreach ($invoice->releaseEvents() as $event) {
    $this->collector->collect($event);
}

$this->repository->save($invoice);
```

### Composing Services

Nested services automatically nest transactions:

```php
public function createInvoiceAndSendEmail(CreateInvoiceDTO $dto): void
{
    $invoice = $this->invoiceService->create($dto);
    $this->emailService->sendInvoiceEmail($invoice->id());
    // Both services share the same transaction. If either fails, both roll back.
}
```

### DTOs for Input Validation

Services accept DTOs that have already been validated:

```php
final class CreateInvoiceDTO
{
    public readonly string $clientId;
    public readonly float  $amount;
    public readonly string $currency;

    public static function fromRequest(Request $request): self
    {
        return new self(
            clientId: $request->input('client_id'),
            amount:   (float) $request->input('amount'),
            currency: $request->input('currency', 'USD'),
        );
    }
}
```

Controllers validate the DTO structure; services assume it is valid and focus on business logic.

## Error Handling

Services throw `ServiceException` for business failures. The exception carries:

- A message describing what failed
- A `layer` identifier (e.g., `'service.invoice.create'`)
- A `context` array of relevant data for logging
- The previous exception (if translating from another layer)

```php
throw new ServiceException(
    'Invoice creation failed.',
    layer:   'service.invoice.create',
    context: ['clientId' => $dto->clientId, 'amount' => $dto->amount],
    previous: $databaseError,
);
```

The `ServiceException` is not a `RepositoryException` or `GatewayException` — it sits between the domain layer (which throws `\DomainException`) and infrastructure (which throws `RepositoryException` or `GatewayException`). The service translates infrastructure errors into business-layer exceptions.

## Common Mistakes

### ✗ Dispatching Integration Events Inside Transaction

```php
// WRONG — if rollback happens, the event was already dispatched
$this->transaction->begin();
try {
    $invoice = Invoice::create(...);
    $this->repository->save($invoice);
    
    $this->eventBus->dispatch(new InvoiceCreatedIntegrationEvent(...)); // TOO EARLY
    
    $this->transaction->commit();
} catch (\Throwable $e) {
    $this->transaction->rollback();
    // Event is already out; listener may have acted on failed data
}
```

### ✓ Dispatch ONLY After Commit

```php
// RIGHT — dispatch OUTSIDE the try/catch, only on successful commit
$this->transaction->begin();
try {
    // ... work ...
    $this->transaction->commit();
} catch (\Throwable $e) {
    $this->transaction->rollback();
    $this->collector->discard();
    throw;
}

// ONLY AFTER commit succeeds
$this->eventBus->dispatch(...);
```

### ✗ Forgetting to Discard Events on Rollback

```php
// WRONG — events accumulate across failed attempts
catch (\Throwable $e) {
    $this->transaction->rollback();
    // Forgot collector->discard() — events stay in buffer
    throw;
}
```

### ✓ Always Discard on Rollback

```php
catch (\Throwable $e) {
    $this->transaction->rollback();
    $this->collector->discard();  // Clear the buffer
    throw;
}
```

### ✗ No Authorization Check

```php
// WRONG — authorization at the end, after work was done
public function create(CreateInvoiceDTO $dto): void
{
    $invoice = Invoice::create(...);
    
    if (!$this->identity->hasPermission('invoice:create')) {
        throw new ServiceException('Not authorized');
    }
}
```

### ✓ Authorization First

```php
// RIGHT — authorization BEFORE any database or business logic
if (!$this->identity->hasPermission('invoice:create')) {
    throw new SecurityException('Not authorized.', code: 403);
}

$invoice = Invoice::create(...);
```

## Source

- [src/Kernel/Database/TransactionManager.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Database/TransactionManager.php)
- [src/Kernel/Events/DomainEventCollector.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Events/DomainEventCollector.php)
- [src/Kernel/Events/EventBus.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Events/EventBus.php)
- [docs/guides/04_SERVICE.md](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/docs/guides/04_SERVICE.md) (internal reference)
