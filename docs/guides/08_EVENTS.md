# HKM Kernel — Event System

> HKM Kernel uses **two distinct event types** with different semantics.
> Conflating them causes phantom events for transactions that later roll back.

---

## Two Event Types — Critical Distinction

| Aspect | Domain Event | Integration Event |
|---|---|---|
| **Scope** | Internal to the module | Cross-module or cross-service |
| **Timing** | Collected DURING transaction | Dispatched AFTER commit only |
| **On rollback** | DISCARDED — no phantom events | N/A — never dispatched |
| **Schema** | Module-internal DTO | Versioned public contract |
| **How to create** | `entity->record(new SomeEvent())` | `new SomeIntegrationEvent(...)` |
| **How to dispatch** | `collector->collect($event)` | `eventBus->dispatch($event)` |
| **Subscribers** | Projections inside same module | Any module that declares `listens[]` |
| **Transport** | In-memory only | Sync or async queue |

---

## Domain Event Pattern

```php
// Location: Domain/Events/InvoiceCreatedDomainEvent.php
// Rules:
//   1. Named in PAST TENSE
//   2. final readonly class — immutable
//   3. No external dependencies
//   4. Carries MINIMUM data for in-module listeners

final readonly class InvoiceCreatedDomainEvent implements DomainEventContract
{
    public function __construct(
        public readonly InvoiceId         $invoiceId,
        public readonly ClientId          $clientId,
        public readonly Money             $total,
        public readonly DateTimeImmutable $occurredAt,
    ) {}
}
```

## Domain Event Flow

```
Entity.issue()
    │
    ├── $this->record(new InvoiceIssuedDomainEvent($this))
    │         ↓
    │   Stored in $this->domainEvents array
    │
Service.create()
    │
    ├── foreach ($invoice->releaseEvents() as $event)
    │       $this->collector->collect($event)    ← buffered
    │
    ├── $this->repository->save($invoice)
    │
    ├── $this->transaction->commit()              ← if success
    │       ↓
    │   foreach ($collector->release() as $event)
    │       $this->projection->on($event)         ← applied in-transaction
    │
    └── on failure: $this->collector->discard()   ← NO phantom events
```

---

## Integration Event Pattern

```php
// Location: API/IntegrationEvents/InvoiceCreatedIntegrationEvent.php
// Rules:
//   1. Versioned — version field is mandatory
//   2. Stable public schema — other modules depend on this
//   3. Contains all data subscribers need (no further DB queries required)
//   4. Dispatched ONLY after successful transaction commit

final readonly class InvoiceCreatedIntegrationEvent implements IntegrationEventContract
{
    public string $version = '1.0';

    public function __construct(
        // Use primitive types (string, int, float) — not domain objects
        // Other modules may not have your domain value objects
        public readonly string $invoiceId,
        public readonly string $clientId,
        public readonly string $tenantId,
        public readonly float  $amount,
        public readonly string $currency,
        public readonly string $dueDate,     // 'Y-m-d'
        public readonly string $occurredAt,  // RFC3339
    ) {}

    public function name(): string    { return 'invoice.created'; }
    public function version(): string { return $this->version; }
    public function payload(): array  { return get_object_vars($this); }
}
```

## Integration Event Dispatch (Service Layer)

```php
// CORRECT: dispatched AFTER the transaction commits — outside try/catch
$this->transaction->begin();
try {
    $invoice = Invoice::create(/* ... */);
    $this->repository->save($invoice);
    $this->transaction->commit();
} catch (\Throwable $e) {
    $this->transaction->rollback();
    $this->collector->discard();
    throw $e;
}

// ← Only reach here on successful commit
$this->eventBus->dispatch(new InvoiceCreatedIntegrationEvent(
    invoiceId:  $invoice->id()->value(),
    clientId:   $dto->clientId,
    tenantId:   $this->identity->tenantId,
    amount:     $invoice->total()->value(),
    currency:   $invoice->total()->currency(),
    dueDate:    $invoice->dueDate()->format('Y-m-d'),
    occurredAt: (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339),
));
```

---

## Subscribing to Integration Events

```json
// module.json — declare which events you listen to
{
  "listens": ["invoice.created", "invoice.paid"]
}
```

```php
// Provider.php boot() — register the listener
public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
{
    $events->subscribe('invoice.created', InvoiceCreatedListener::class);
    $events->subscribe('invoice.paid',    InvoicePaidListener::class);
}
```

```php
// The listener class
final class InvoiceCreatedListener implements EventListenerContract
{
    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    public function handle(IntegrationEventContract $event): void
    {
        // Always check version — schema may evolve
        if ($event->version() !== '1.0') {
            // handle or skip unknown versions gracefully
            return;
        }

        $payload = InvoiceCreatedPayload::from($event->payload());

        $this->notifications->sendInvoiceConfirmation(
            userId:    $payload->clientId,
            invoiceId: $payload->invoiceId,
            amount:    $payload->amount,
        );
    }
}
```

---

## Event Versioning — Adding Fields Without Breaking

```php
// v1.0 — original
final readonly class InvoiceCreatedIntegrationEvent
{
    public string $version = '1.0';
    public function __construct(
        public readonly string $invoiceId,
        public readonly string $clientId,
        public readonly float  $amount,
    ) {}
}

// v2.0 — adds lineItems without removing existing fields
final readonly class InvoiceCreatedIntegrationEvent
{
    public string $version = '2.0';
    public function __construct(
        public readonly string $invoiceId,
        public readonly string $clientId,
        public readonly float  $amount,
        public readonly array  $lineItems,  // NEW in 2.0
    ) {}
}

// Subscriber handles both versions
public function handle(IntegrationEventContract $event): void
{
    match ($event->version()) {
        '1.0' => $this->handleV1($event->payload()),
        '2.0' => $this->handleV2($event->payload()),
        default => null, // ignore unknown versions — never throw
    };
}
```

---

## Projection Pattern (In-Module Domain Event Listener)

```php
// Projections update read models from domain events — inside the same transaction
final class InvoiceSummaryProjection
{
    public function __construct(
        private readonly DatabasePort $db,
    ) {}

    public function on(DomainEventContract $event): void
    {
        match (true) {
            $event instanceof InvoiceCreated => $this->onCreated($event),
            $event instanceof InvoiceIssued  => $this->onIssued($event),
            $event instanceof InvoicePaid    => $this->onPaid($event),
            default                          => null,
        };
    }

    private function onCreated(InvoiceCreated $e): void
    {
        $this->db->execute(
            'INSERT INTO invoice_summaries (invoice_id, client_id, total_cents, status)
             VALUES (:id, :cid, :total, :status)',
            ['id' => $e->invoiceId->value(), 'cid' => $e->clientId->value(),
             'total' => $e->total->amount(), 'status' => 'draft']
        );
    }
}
```

---

## Rules for Event Code

When writing or reviewing event code:

- **DO** dispatch integration events AFTER the transaction commits — never inside
- **DO** use `collector->discard()` in every rollback path
- **DO** include a `version` field on every integration event
- **DO** use primitive types in integration event constructors (string, int, float)
- **DO** check version in listeners before reading payload fields
- **DON'T** dispatch a domain event as an integration event — they are different classes
- **DON'T** dispatch integration events inside a try/catch block
- **DON'T** dispatch events from repositories or gateways
- **DON'T** use domain objects (entities, value objects) in integration event constructors
- **DON'T** catch exceptions thrown by event listeners — isolation is handled by the EventBus
