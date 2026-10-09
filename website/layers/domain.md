# Domain Layer

The domain layer contains your core business logic expressed as immutable entities, value objects, and rules that encode your application's rules and constraints. Domain code is isolated from infrastructure, services, and the outside world — it has zero external dependencies and exists to ensure business invariants are always maintained.

## Entities

Entities are domain objects with identity. They represent core business concepts like `Invoice`, `Payment`, or `User` that exist within a bounded context and have a persistent identity over time.

### Entity Rules

Entities follow these strict patterns:

- **`final` class** — no inheritance, only composition
- **Private constructor** — all instantiation goes through named constructors
- **Readonly properties** — immutable state for Swoole safety
- **`private function __construct()`** — forces use of named constructors to control how entities are created
- **`static create()` for new entities** — records a domain event
- **`static reconstitute()` for hydration** — loads from persistence with NO events
- **State transitions** — public methods that assert preconditions and record events
- **`releaseEvents()` buffer** — clears and returns pending domain events after they are flushed to the service layer

### Complete Example

```php
<?php declare(strict_types=1);
namespace Shop\Invoice\Domain\Entities;

use Shop\Invoice\Domain\ValueObjects\{InvoiceId, InvoiceStatus, Money};
use Shop\Invoice\Domain\Events\InvoiceCreatedDomainEvent;
use Shop\Invoice\Domain\Events\InvoiceIssuedDomainEvent;

final class Invoice
{
    /** @var list<object> */
    private array $domainEvents = [];

    private function __construct(
        private readonly InvoiceId    $id,
        private readonly string       $clientId,
        private Money                 $amount,
        private InvoiceStatus         $status,
        private readonly \DateTimeImmutable $issuedAt,
    ) {}

    /**
     * Create a new invoice. Records an InvoiceCreatedDomainEvent.
     */
    public static function create(
        string $clientId,
        Money $amount,
    ): self {
        $invoice = new self(
            id:       InvoiceId::generate(),
            clientId: $clientId,
            amount:   $amount,
            status:   InvoiceStatus::DRAFT,
            issuedAt: new \DateTimeImmutable(),
        );

        $invoice->domainEvents[] = new InvoiceCreatedDomainEvent(
            $invoice->id,
            $invoice->clientId,
            $invoice->amount->value(),
        );

        return $invoice;
    }

    /**
     * Reconstitute from persistence. No events recorded.
     */
    public static function reconstitute(
        InvoiceId $id,
        string $clientId,
        Money $amount,
        InvoiceStatus $status,
        \DateTimeImmutable $issuedAt,
    ): self {
        return new self($id, $clientId, $amount, $status, $issuedAt);
    }

    public function id(): InvoiceId { return $this->id; }
    public function clientId(): string { return $this->clientId; }
    public function amount(): Money { return $this->amount; }
    public function status(): InvoiceStatus { return $this->status; }

    /**
     * Transition to issued status. Precondition: must be in draft.
     */
    public function issue(): void
    {
        if ($this->status !== InvoiceStatus::DRAFT) {
            throw new \DomainException(
                'Can only issue an invoice in draft status.',
            );
        }

        $this->status = InvoiceStatus::ISSUED;

        $this->domainEvents[] = new InvoiceIssuedDomainEvent(
            $this->id,
            $this->clientId,
        );
    }

    /**
     * Adjust the amount. Precondition: must not be issued.
     */
    public function adjustAmount(Money $newAmount): void
    {
        if ($this->status !== InvoiceStatus::DRAFT) {
            throw new \DomainException(
                'Cannot adjust the amount of a non-draft invoice.',
            );
        }

        $this->amount = $newAmount;
    }

    /**
     * Release pending events and clear the buffer.
     *
     * @return object[]
     */
    public function releaseEvents(): array
    {
        $events = $this->domainEvents;
        $this->domainEvents = [];
        return $events;
    }
}
```

## Value Objects

Value objects represent domain concepts with no persistent identity — they have meaning only by their value. Examples: `Money`, `Email`, `PhoneNumber`, `Location`. Two value objects are equal if their properties are equal.

### Value Object Rules

- **`final readonly class`** — immutable, cannot be extended
- **Validate in constructor** — every invariant is checked on instantiation, before the object can exist
- **Private constructor** — usually, exposing named static factories for creation
- **Operations return new instances** — no mutation
- **No value object mutation** — state changes create NEW value objects
- **Compare by value** — implement `__equals()` or rely on `readonly class` comparison if properties are comparable

### Complete Example

```php
<?php declare(strict_types=1);
namespace Shop\Invoice\Domain\ValueObjects;

final readonly class Money
{
    /**
     * The amount in integer cents (never float).
     * Avoids floating-point precision loss.
     */
    private int $amount;

    /**
     * @param int|float $amount Coins or decimal amount
     * @param string    $currency ISO 4217 code
     */
    private function __construct(
        int $amount,
        private string $currency,
    ) {
        if ($amount < 0) {
            throw new \DomainException('Money cannot be negative.');
        }
        $this->amount = $amount;
    }

    /**
     * Create from decimal amount. 49.99 USD → 4999 cents.
     */
    public static function of(int|float $amount, string $currency): self
    {
        $cents = (int) round($amount * 100);
        return new self($cents, strtoupper($currency));
    }

    /**
     * Create from raw cents.
     */
    public static function cents(int $amount, string $currency): self
    {
        return new self($amount, strtoupper($currency));
    }

    public function amount(): int { return $this->amount; }
    public function value(): float { return $this->amount / 100; }
    public function currency(): string { return $this->currency; }

    /**
     * Add two amounts. Precondition: same currency.
     */
    public function add(self $other): self
    {
        if ($this->currency !== $other->currency) {
            throw new \DomainException(
                "Cannot add {$other->currency} to {$this->currency}.",
            );
        }
        return new self($this->amount + $other->amount, $this->currency);
    }

    /**
     * Subtract another amount.
     */
    public function subtract(self $other): self
    {
        if ($this->currency !== $other->currency) {
            throw new \DomainException(
                "Cannot subtract {$other->currency} from {$this->currency}.",
            );
        }
        $result = $this->amount - $other->amount;
        if ($result < 0) {
            throw new \DomainException('Subtraction would result in negative money.');
        }
        return new self($result, $this->currency);
    }

    /**
     * Multiply by a scalar. Useful for tax, tips, adjustments.
     */
    public function multiply(float|int $factor): self
    {
        if ($factor < 0) {
            throw new \DomainException('Multiplication factor cannot be negative.');
        }
        $newAmount = (int) round($this->amount * $factor);
        return new self($newAmount, $this->currency);
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount
            && $this->currency === $other->currency;
    }
}
```

## Domain Events

Domain events are immutable records of something that happened in the domain. They express business meaning and are used to:

1. Record what happened (immutable history)
2. Trigger side effects within the same service (via `DomainEventCollector` during transaction)
3. Notify other modules via integration events (dispatched by `EventBus` after commit)

### Domain Event Rules

- **`final readonly class`** — immutable, cannot be subclassed
- **Implement `DomainEventContract`** — marker interface in `AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\`
- **Occurred-at timestamp** — record WHEN it happened (or let it default to now)
- **Reference value objects, IDs, or primitives only** — no full entity references (entities change; events do not)
- **Past tense naming** — `InvoiceCreatedDomainEvent`, not `CreateInvoice`
- **Recorded during transaction** — collected by the service via `collector->collect()`
- **Cleared on rollback** — `collector->discard()` in the catch block

### Domain Event Example

```php
<?php declare(strict_types=1);
namespace Shop\Invoice\Domain\Events;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\DomainEventContract;

final readonly class InvoiceCreatedDomainEvent implements DomainEventContract
{
    public function __construct(
        public string $invoiceId,
        public string $clientId,
        public float  $amount,
        public string $occurredAt = '',
    ) {
        if ($this->occurredAt === '') {
            $this->occurredAt = (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339);
        }
    }
}
```

## Domain Rules (Business Rules)

Domain rules encode invariants as static checks that any layer can call. They live in the `Domain/Rules` folder and assert that a value, state, or combination of values is valid according to business logic.

### Rule Pattern

```php
<?php declare(strict_types=1);
namespace Shop\Invoice\Domain\Rules;

final class InvoiceMustBeIssuedBeforePaying
{
    /**
     * Check that an invoice can be paid. Throw if not.
     * Static so it can be called from any layer when needed.
     */
    public static function check(InvoiceStatus $status): void
    {
        if ($status === InvoiceStatus::DRAFT) {
            throw new \DomainException(
                'Cannot pay a draft invoice. Issue it first.',
            );
        }
    }
}
```

Rules can be called from services as an additional guard, or composed into entity state transitions themselves.

## Imports Rule: Zero External Dependencies

Every file in the `Domain/` folder (entities, value objects, events, rules) follows this rule:

```php
<?php declare(strict_types=1);
namespace Shop\Invoice\Domain\{SubFolder};

// ONLY imports from within Domain/ are allowed here.
use Shop\Invoice\Domain\{ValueObjects, Events, Rules};

// NOT ALLOWED:
// - use Application\Services\*;
// - use Infrastructure\Persistence\*;
// - use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\*;
// - use Any\Vendor\Library;
```

This isolation ensures the domain layer is:

- **Testable without infrastructure** — domain tests use only `new`, no mocks or stubs
- **Reusable** — the domain layer is business logic that could be used in a CLI, a web request, or a background job
- **Portable** — the kernel, database, and every other infrastructure detail is irrelevant to domain correctness

## Common Mistakes

### ✗ Mutable Entities

```php
// WRONG — properties can be mutated directly
final class Invoice
{
    public Money $amount;
}

$invoice->amount = $newAmount; // silent state change, no validation
```

### ✓ Immutable, Transition via Methods

```php
// RIGHT — state changes go through methods that enforce invariants
final class Invoice
{
    private Money $amount;
    
    public function adjustAmount(Money $newAmount): void
    {
        if ($this->status !== InvoiceStatus::DRAFT) {
            throw new \DomainException('Cannot adjust non-draft invoices.');
        }
        $this->amount = $newAmount;
    }
}
```

### ✗ Float for Money

```php
// WRONG — floating-point math: 0.1 + 0.2 !== 0.3 in PHP
private float $amount = 99.99;
```

### ✓ Integer Cents (Money VO)

```php
// RIGHT — integer arithmetic is exact
private int $cents = 9999; // 99.99 USD
$total = $invoice->amount()->add($tax->amount()); // safe
```

### ✗ Domain Events Without Release

```php
// WRONG — events never leave the entity
class Invoice
{
    private array $events = [];
    // no releaseEvents() method
}
```

### ✓ Release Events from Service

```php
// RIGHT — service collects and flushes
foreach ($invoice->releaseEvents() as $event) {
    $this->collector->collect($event);
}
```

## Source

- [src/Kernel/Events/Contracts/DomainEventContract.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Events/Contracts/DomainEventContract.php)
- [docs/guides/03_DOMAIN.md](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/docs/guides/03_DOMAIN.md) (internal reference)
