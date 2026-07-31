# HKM Kernel — Domain Layer

> The Domain layer is the **core of every module**. It contains pure business logic and has
> **zero external dependencies** — no framework classes, no port interfaces, no vendor SDKs.
> If a file in `Domain/` has an `import` from outside its own namespace, it is wrong.

---

## Domain Layer Rules (ABSOLUTE)

| Rule | Why |
|---|---|
| No imports from outside `Domain/` | Domain must be testable with zero infrastructure |
| No `new DateTimeImmutable()` from outside — pass it in | Makes tests deterministic |
| State changes only through entity methods | Prevents invalid state |
| All validation in constructors | Invalid objects cannot exist |
| All invariants checked before state mutation | Business rules enforced at the source |
| Domain events recorded inside entities | Entity is responsible for its own events |
| `releaseEvents()` returns AND clears the buffer | Events are consumed once |

---

## Entity Pattern

```php
<?php
declare(strict_types=1);

namespace InvoiceModule\Domain\Entities;

use InvoiceModule\Domain\ValueObjects\{InvoiceId, InvoiceNumber, ClientId, Money};
use InvoiceModule\Domain\Events\{InvoiceCreatedDomainEvent, InvoiceIssuedDomainEvent};
use InvoiceModule\Domain\Rules\InvoiceMustHaveLineItems;

final class Invoice
{
    private InvoiceStatus $status;
    private array         $lineItems    = [];
    private array         $domainEvents = [];

    // PRIVATE constructor — use named static constructors
    private function __construct(
        private readonly InvoiceId           $id,
        private readonly InvoiceNumber       $number,
        private readonly ClientId            $clientId,
        private          Money               $subtotal,
        private          Money               $tax,
        private readonly DateTimeImmutable   $dueDate,
        private readonly DateTimeImmutable   $createdAt,
        private          int                 $version = 1,
    ) {
        $this->status = InvoiceStatus::DRAFT;
    }

    // ── Named constructor (factory method) ─────────────────────────────────
    public static function create(
        InvoiceNumber     $number,
        ClientId          $clientId,
        DateTimeImmutable $dueDate,
    ): self {
        $invoice = new self(
            id:        InvoiceId::generate(),
            number:    $number,
            clientId:  $clientId,
            subtotal:  Money::zero('USD'),
            tax:       Money::zero('USD'),
            dueDate:   $dueDate,
            createdAt: new DateTimeImmutable(),
        );
        $invoice->record(new InvoiceCreatedDomainEvent($invoice));
        return $invoice;
    }

    // ── Reconstitution (for hydration from DB) ──────────────────────────────
    public static function reconstitute(/* all fields */): self
    {
        $invoice = new self(/* ... */);
        // NOTE: do NOT record domain events on reconstitution
        return $invoice;
    }

    // ── State transitions ───────────────────────────────────────────────────
    public function addLineItem(LineItem $item): void
    {
        $this->ensureStatus(InvoiceStatus::DRAFT, 'add line items');
        $this->lineItems[] = $item;
        $this->subtotal    = $this->subtotal->add($item->total());
        $this->tax         = $this->tax->add($item->taxAmount());
    }

    public function issue(): void
    {
        $this->ensureStatus(InvoiceStatus::DRAFT, 'issue');
        InvoiceMustHaveLineItems::check($this->lineItems);
        $this->status = InvoiceStatus::ISSUED;
        $this->record(new InvoiceIssuedDomainEvent($this));
    }

    // ── Domain event management ─────────────────────────────────────────────
    public function releaseEvents(): array
    {
        $events            = $this->domainEvents;
        $this->domainEvents = [];
        return $events;
    }

    private function record(DomainEventContract $event): void
    {
        $this->domainEvents[] = $event;
    }

    // ── Guard helpers ───────────────────────────────────────────────────────
    private function ensureStatus(InvoiceStatus $required, string $action): void
    {
        if ($this->status !== $required) {
            throw new \DomainException(
                "Cannot {$action}: invoice status is [{$this->status->value}]"
            );
        }
    }

    // ── Getters (read-only access) ──────────────────────────────────────────
    public function id(): InvoiceId              { return $this->id; }
    public function number(): InvoiceNumber      { return $this->number; }
    public function clientId(): ClientId         { return $this->clientId; }
    public function status(): InvoiceStatus      { return $this->status; }
    public function subtotal(): Money            { return $this->subtotal; }
    public function tax(): Money                 { return $this->tax; }
    public function dueDate(): DateTimeImmutable { return $this->dueDate; }
    public function lineItems(): array           { return $this->lineItems; }
    public function version(): int               { return $this->version; }
}
```

---

## Value Object Pattern

```php
<?php
declare(strict_types=1);

namespace InvoiceModule\Domain\ValueObjects;

final readonly class Money
{
    // Store in CENTS — never use float for money
    private function __construct(
        private int    $amount,   // in cents
        private string $currency, // ISO 4217 (USD, EUR, GBP)
    ) {
        if ($this->amount < 0) {
            throw new \DomainException('Money amount cannot be negative');
        }
        if (strlen($this->currency) !== 3) {
            throw new \DomainException("Invalid currency code: [{$this->currency}]");
        }
    }

    // ── Named constructors ──────────────────────────────────────────────────
    public static function of(int|float $amount, string $currency): self
    {
        return new self((int) round($amount * 100), strtoupper($currency));
    }

    public static function fromCents(int $cents, string $currency): self
    {
        return new self($cents, strtoupper($currency));
    }

    public static function zero(string $currency): self
    {
        return new self(0, strtoupper($currency));
    }

    // ── Operations return NEW instances — immutable ─────────────────────────
    public function add(self $other): self
    {
        $this->assertSameCurrency($other);
        return new self($this->amount + $other->amount, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);
        return new self($this->amount - $other->amount, $this->currency);
    }

    public function multiply(int|float $factor): self
    {
        return new self((int) round($this->amount * $factor), $this->currency);
    }

    // ── Comparison ──────────────────────────────────────────────────────────
    public function equals(self $other): bool
    {
        return $this->amount === $other->amount && $this->currency === $other->currency;
    }

    public function isGreaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);
        return $this->amount > $other->amount;
    }

    // ── Accessors ───────────────────────────────────────────────────────────
    public function value(): float    { return $this->amount / 100; }
    public function amount(): int     { return $this->amount; }
    public function currency(): string { return $this->currency; }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new \DomainException(
                "Cannot operate on {$this->currency} and {$other->currency}"
            );
        }
    }
}
```

---

## Domain Event Pattern

```php
<?php
declare(strict_types=1);

// Domain events: named in PAST TENSE, carry minimum data, no external dependencies
final readonly class InvoiceCreatedDomainEvent implements DomainEventContract
{
    public function __construct(
        public readonly InvoiceId          $invoiceId,
        public readonly ClientId           $clientId,
        public readonly Money              $total,
        public readonly DateTimeImmutable  $occurredAt,
    ) {}
}
```

**Domain Event Rules:**
- Named in past tense: `InvoiceCreated`, not `CreateInvoice`
- Carry the **minimum data** listeners inside this module need
- NEVER exposed outside the module — use Integration Events for that
- Collected during a transaction — discarded if the transaction rolls back
- Released from the entity via `releaseEvents()` — buffer cleared after release

---

## Domain Rule (Specification) Pattern

```php
<?php
declare(strict_types=1);

// Rules enforce business invariants. Throw DomainException on violation.
final class InvoiceMustHaveLineItems
{
    public static function check(array $lineItems): void
    {
        if (empty($lineItems)) {
            throw new \DomainException(
                'An invoice must have at least one line item before it can be issued'
            );
        }
    }
}
```

---

## Status Enum Pattern

```php
<?php
declare(strict_types=1);

// Backed enum — value stored in DB. Pure enum for in-memory-only states.
enum InvoiceStatus: string
{
    case DRAFT     = 'draft';
    case ISSUED    = 'issued';
    case PAID      = 'paid';
    case OVERDUE   = 'overdue';
    case CANCELLED = 'cancelled';

    public function isTerminal(): bool
    {
        return in_array($this, [self::PAID, self::CANCELLED], true);
    }

    // Valid transitions from this status
    public function canTransitionTo(self $target): bool
    {
        return in_array($target, match($this) {
            self::DRAFT     => [self::ISSUED, self::CANCELLED],
            self::ISSUED    => [self::PAID, self::OVERDUE, self::CANCELLED],
            self::OVERDUE   => [self::PAID, self::CANCELLED],
            self::PAID      => [],
            self::CANCELLED => [],
        }, true);
    }
}
```

---

## Domain Layer File Checklist

Every file in `Domain/` must satisfy:

- [ ] `declare(strict_types=1)` at the top
- [ ] No imports from outside `Domain/` namespace
- [ ] No `use` of any framework class, port interface, or vendor SDK
- [ ] All constructors validate their inputs and throw `\DomainException` on violation
- [ ] Entities use `private` constructors and `public static` factory methods
- [ ] Value objects are `final readonly` classes
- [ ] Domain events are `final readonly` classes named in past tense
- [ ] `releaseEvents()` clears the event buffer after returning it

---

## Rules for Domain Code

When writing or reviewing domain code:

- **DO** make all Value Objects `final readonly` — immutability is mandatory
- **DO** throw `\DomainException` (not `\RuntimeException`) for business rule violations
- **DO** store money as integer cents — never `float`
- **DO** use `declare(strict_types=1)` on every domain file
- **DO** use private constructors + static factory methods for Entities
- **DO** use named constructors that describe intent: `Invoice::create()`, not `new Invoice()`
- **DON'T** inject any service, port, or infrastructure class into domain objects
- **DON'T** put persistence logic in entities (no Eloquent, no Active Record)
- **DON'T** call `new DateTimeImmutable()` inside domain methods — pass time as a parameter
- **DON'T** dispatch events from domain objects — record them, release from the Service layer
