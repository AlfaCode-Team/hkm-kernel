# Events

Events express what happened in your domain. There are two types: **domain events** (internal, during transaction) and **integration events** (cross-module, after commit). Both are immutable records of a business fact, but they serve different purposes and follow different timing rules.

## Event Types Comparison

| Aspect | Domain Event | Integration Event |
|--------|--------------|-------------------|
| **Location** | `Domain/Events/` | `API/IntegrationEvents/` |
| **Scope** | Internal to one module | Public, cross-module |
| **Types allowed** | Domain value objects, IDs, primitives | Primitives only (string, int, float, bool) |
| **Dispatch** | `collector->collect()` DURING transaction | `eventBus->dispatch()` AFTER commit |
| **Rollback** | `collector->discard()` clears them | Never dispatched if tx fails |
| **Listeners** | Direct callback; always runs | EventBus subscribes listeners; failures isolated |
| **Purpose** | Record internal state changes; trigger side effects within the service | Notify other modules; trigger distributed workflows |

## Domain Events

A domain event records what happened inside a bounded context. It is internal—other modules should not know about it. Domain events are collected during a transaction and discarded if the transaction fails.

### Domain Event Contract

Domain events implement the marker interface `DomainEventContract`:

```php
<?php declare(strict_types=1);
namespace AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts;

interface DomainEventContract {}
```

No methods required—it is purely a type marker.

### Domain Event Pattern

```php
<?php declare(strict_types=1);
namespace Shop\Invoice\Domain\Events;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\DomainEventContract;
use Shop\Invoice\Domain\ValueObjects\InvoiceId;

final readonly class InvoiceCreatedDomainEvent implements DomainEventContract
{
    public function __construct(
        public InvoiceId $invoiceId,
        public string    $clientId,
        public float     $amount,
        public string    $occurredAt,
    ) {}
}

final readonly class InvoiceIssuedDomainEvent implements DomainEventContract
{
    public function __construct(
        public InvoiceId $invoiceId,
        public string    $clientId,
    ) {}
}
```

### Emitting Domain Events

Entities emit domain events through named constructors and state transitions:

```php
final class Invoice
{
    private array $domainEvents = [];

    public static function create(string $clientId, Money $amount): self
    {
        $invoice = new self(...);
        
        $invoice->domainEvents[] = new InvoiceCreatedDomainEvent(
            invoiceId: $invoice->id,
            clientId:  $clientId,
            amount:    $amount->value(),
            occurredAt: (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339),
        );
        
        return $invoice;
    }

    public function issue(): void
    {
        if ($this->status !== InvoiceStatus::DRAFT) {
            throw new \DomainException('Can only issue draft invoices.');
        }
        
        $this->status = InvoiceStatus::ISSUED;
        
        $this->domainEvents[] = new InvoiceIssuedDomainEvent(
            invoiceId: $this->id,
            clientId:  $this->clientId,
        );
    }

    /**
     * Release and clear pending domain events.
     */
    public function releaseEvents(): array
    {
        $events = $this->domainEvents;
        $this->domainEvents = [];
        return $events;
    }
}
```

### Collecting Domain Events

Services collect domain events during a transaction using `DomainEventCollector`:

```php
public function create(CreateInvoiceDTO $dto): InvoiceResponseDTO
{
    $this->collector->beginCollection();
    $this->transaction->begin();

    try {
        $invoice = Invoice::create($dto->clientId, Money::of($dto->amount, $dto->currency));

        // Release events from the entity and collect them
        foreach ($invoice->releaseEvents() as $event) {
            $this->collector->collect($event);
        }

        $this->repository->save($invoice);
        $this->transaction->commit();

    } catch (\Throwable $e) {
        $this->transaction->rollback();
        $this->collector->discard();  // Clear domain events on failure
        throw new ServiceException('Failed to create invoice.', previous: $e);
    }

    // ... dispatch integration events AFTER commit (see below)
}
```

**DomainEventCollector API:**

```php
public function beginCollection(): void   // Activate the buffer
public function collect(DomainEventContract $event): void  // Add an event
public function release(): array           // Return and clear pending events
public function discard(): void            // Clear without returning
public function count(): int               // How many events are pending
```

## Integration Events

Integration events are public, versioned announcements that other modules subscribe to. They use primitives only (no domain objects) so subscribers who do not have the originating module's classes can still deserialize the event.

### Integration Event Contract

```php
<?php declare(strict_types=1);
namespace AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts;

interface IntegrationEventContract
{
    /** Event name, e.g. 'invoice.created' */
    public function name(): string;

    /** Semantic version of the event payload, e.g. '1.0' */
    public function version(): string;

    /** Immutable payload: primitives only */
    public function payload(): array;
}
```

### Integration Event Pattern

```php
<?php declare(strict_types=1);
namespace Shop\Invoice\API\IntegrationEvents;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;

final readonly class InvoiceCreatedIntegrationEvent implements IntegrationEventContract
{
    /** Event version for changelog tracking */
    public string $version = '1.0';

    public function __construct(
        public string $invoiceId,    // primitives only
        public string $clientId,
        public float  $amount,
        public string $currency,
        public string $occurredAt,  // RFC3339 timestamp
    ) {}

    public function name(): string
    {
        return 'invoice.created';
    }

    public function version(): string
    {
        return $this->version;
    }

    public function payload(): array
    {
        return get_object_vars($this);
    }
}
```

### Dispatching Integration Events

Dispatch integration events **AFTER a transaction commits**, never inside the try/catch:

```php
public function create(CreateInvoiceDTO $dto): InvoiceResponseDTO
{
    $this->collector->beginCollection();
    $this->transaction->begin();

    try {
        $invoice = Invoice::create(...);
        foreach ($invoice->releaseEvents() as $event) {
            $this->collector->collect($event);
        }
        $this->repository->save($invoice);
        $this->transaction->commit();
    } catch (\Throwable $e) {
        $this->transaction->rollback();
        $this->collector->discard();
        throw new ServiceException('Failed to create invoice.', previous: $e);
    }

    // ONLY AFTER commit succeeds — dispatch integration events
    $failures = $this->eventBus->dispatch(new InvoiceCreatedIntegrationEvent(
        invoiceId: $invoice->id()->value(),
        clientId:  $invoice->clientId(),
        amount:    $invoice->amount()->value(),
        currency:  $invoice->amount()->currency(),
        occurredAt: (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339),
    ));

    if (!empty($failures)) {
        // Some listeners failed; log or re-queue for later retry
        foreach ($failures as $listenerClass => $exception) {
            // Log: $listenerClass did not handle the event
        }
    }

    return InvoiceResponseDTO::from($invoice);
}
```

## EventBus — Listener Subscription and Dispatch

The `EventBus` manages subscriptions and dispatches integration events to all listeners. Subscriptions are registered once during module boot; dispatch happens per-request.

### EventBus API

```php
public function subscribe(string $eventName, string $listenerClass): void
public function dispatch(IntegrationEventContract $event): array  // [listenerClass => \Throwable]
public function forContainer(ContainerInterface $container): self
```

### Subscribing to Events

Modules subscribe listeners in their `Provider::boot()` method:

```php
<?php declare(strict_types=1);
namespace Shop\Invoice;

use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use Shop\Invoice\API\IntegrationEvents\InvoiceCreatedIntegrationEvent;
use Shop\Invoice\Application\Listeners\NotifyClientListener;
use Shop\Notification\Application\Listeners\QueueNotificationListener;

class Provider implements ModuleContract
{
    // ...

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // Subscribe 'invoice.created' events
        $events->subscribe('invoice.created', NotifyClientListener::class);
        $events->subscribe('invoice.created', QueueNotificationListener::class);
    }
}
```

### Event Listener Contract

Listeners implement `EventListenerContract`:

```php
<?php declare(strict_types=1);
namespace AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts;

interface EventListenerContract
{
    public function handle(IntegrationEventContract $event): void;
}
```

### Listener Implementation

```php
<?php declare(strict_types=1);
namespace Shop\Invoice\Application\Listeners;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\{EventListenerContract, IntegrationEventContract};
use Shop\Invoice\API\IntegrationEvents\InvoiceCreatedIntegrationEvent;
use Shop\Notification\API\Contracts\NotificationServiceContract;

final class NotifyClientListener implements EventListenerContract
{
    public function __construct(
        private readonly NotificationServiceContract $notifications,
    ) {}

    public function handle(IntegrationEventContract $event): void
    {
        // Check the event type (it could be any integration event)
        if (!$event instanceof InvoiceCreatedIntegrationEvent) {
            return;
        }

        // Handle the event
        $this->notifications->send(
            recipient: $event->clientId,
            subject:   'Invoice created',
            body:      "Your invoice #{$event->invoiceId} for {$event->amount} {$event->currency} is ready.",
        );
    }
}
```

### EventBus Guarantees

- **Listener isolation:** If one listener throws, others still run
- **Failures returned:** `dispatch()` returns `[listenerClass => exception]` for any that failed
- **Logger optional:** Failures are logged if a `LoggerPort` is bound; otherwise silent
- **Dependency injection:** Listeners are resolved from `ModuleContainer`, so their dependencies are injected
- **Per-request container:** Use `$bus->forContainer($requestContainer)` in request pipelines so listeners get request-scoped dependencies

## Module Integration Events Declaration

Declare which events your module emits in `module.json`:

```jsonc
{
  "name": "invoice",
  "solves": "invoice.generation",
  "emits": ["invoice.created", "invoice.issued", "invoice.paid"],
  // ...
}
```

This documents the events your module produces. Other modules can then subscribe to them.

## Event Versioning

Integration events are **versioned** so you can evolve them without breaking subscribers:

```php
final readonly class InvoiceCreatedIntegrationEvent implements IntegrationEventContract
{
    public string $version = '1.1';

    public function __construct(
        public string $invoiceId,
        public string $clientId,
        public float  $amount,
        public string $currency,
        public string $occurredAt,
        public string $notes = '',  // New in 1.1
    ) {}

    // ...
}
```

Listeners check `$event->version()` to handle multiple versions:

```php
public function handle(IntegrationEventContract $event): void
{
    if (!$event instanceof InvoiceCreatedIntegrationEvent) {
        return;
    }

    if ($event->version === '1.0') {
        // Handle legacy format
    } elseif ($event->version === '1.1') {
        // Handle new format with $notes
    }
}
```

## Common Mistakes

### ✗ Domain Events with Complex Objects

```php
// WRONG — cannot be serialized across module boundaries
final readonly class InvoiceCreatedEvent implements IntegrationEventContract
{
    public function __construct(public Invoice $invoice) {}
}
```

### ✓ Integration Events with Primitives Only

```php
// RIGHT — can be serialized and deserialized anywhere
final readonly class InvoiceCreatedIntegrationEvent implements IntegrationEventContract
{
    public function __construct(
        public string $invoiceId,  // ID, not the object
        public float  $amount,
    ) {}
}
```

### ✗ Dispatching Inside Transaction

```php
// WRONG — if rollback happens, the event was already sent
$this->transaction->begin();
try {
    $invoice = Invoice::create(...);
    $this->eventBus->dispatch(new InvoiceCreatedIntegrationEvent(...));  // TOO EARLY
    $this->transaction->commit();
}
```

### ✓ Dispatch After Commit

```php
// RIGHT — only after commit succeeds
$this->transaction->begin();
try {
    $invoice = Invoice::create(...);
    $this->transaction->commit();
} catch (\Throwable $e) {
    $this->transaction->rollback();
    throw;
}
$this->eventBus->dispatch(new InvoiceCreatedIntegrationEvent(...));  // After commit
```

### ✗ Ignoring Listener Failures

```php
// WRONG — listener failures silently dropped
$this->eventBus->dispatch($event);
// Did the listeners actually run?
```

### ✓ Check Dispatch Failures

```php
// RIGHT — check if listeners failed
$failures = $this->eventBus->dispatch($event);
if (!empty($failures)) {
    // Log or re-queue; callers can detect and handle
    foreach ($failures as $listenerClass => $exception) {
        // Listener $listenerClass failed with $exception
    }
}
```

## Source

- [src/Kernel/Events/Contracts/DomainEventContract.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Events/Contracts/DomainEventContract.php)
- [src/Kernel/Events/Contracts/IntegrationEventContract.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Events/Contracts/IntegrationEventContract.php)
- [src/Kernel/Events/Contracts/EventListenerContract.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Events/Contracts/EventListenerContract.php)
- [src/Kernel/Events/DomainEventCollector.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Events/DomainEventCollector.php)
- [src/Kernel/Events/EventBus.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Events/EventBus.php)
- [docs/guides/08_EVENTS.md](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/docs/guides/08_EVENTS.md) (internal reference)
