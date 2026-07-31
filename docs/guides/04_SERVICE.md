# HKM Kernel — Application Service Layer

> The Service layer is the **only layer** that may call both Repository (persistence) and
> Gateway (third-party APIs). It orchestrates workflows, owns transactions, collects domain
> events, and dispatches integration events.

---

## Service Layer Rules (ABSOLUTE)

| Rule | Detail |
|---|---|
| ONLY layer calling Repository AND Gateway | No other layer touches both |
| Owns transaction boundaries | `begin → save → commit / rollback` |
| Collects domain events during transaction | Via `DomainEventCollector` |
| Dispatches integration events AFTER commit | Never inside the transaction |
| Discards domain events on rollback | `collector->discard()` in catch block |
| Identity injected via constructor | From the scoped container — set by SecurityGateway |
| NEVER instantiates HTTP Request/Response objects | Those belong in controllers |
| NEVER calls another Service directly | Use Integration Events for cross-module async |
| NEVER dispatches to QueuePort directly | Use Integration Events or dedicated dispatch service |

---

## Canonical Service Implementation

```php
<?php
declare(strict_types=1);

namespace InvoiceModule\Application\Services;

use InvoiceModule\API\Contracts\InvoiceServiceContract;
use InvoiceModule\Application\DTO\{CreateInvoiceDTO, InvoiceResponseDTO};
use InvoiceModule\Domain\Entities\Invoice;
use InvoiceModule\Domain\ValueObjects\{InvoiceNumber, ClientId};
use InvoiceModule\Infrastructure\Persistence\InvoiceRepository;
use InvoiceModule\API\IntegrationEvents\InvoiceCreatedIntegrationEvent;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\{DomainEventCollector, IntegrationEventBus};
use AlfacodeTeam\PhpServicePlatform\Kernel\Auth\Identity;
use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;

final class InvoiceService implements InvoiceServiceContract
{
    public function __construct(
        private readonly InvoiceRepository    $repository,   // INTERNAL — Repository
        private readonly TransactionManager   $transaction,
        private readonly DomainEventCollector $collector,
        private readonly IntegrationEventBus  $eventBus,
        private readonly Identity             $identity,     // from SecurityGateway
    ) {}

    public function create(CreateInvoiceDTO $dto): InvoiceResponseDTO
    {
        // ── Authorization check ──────────────────────────────────────────
        if ($dto->clientId !== $this->identity->userId
            && !$this->identity->hasPermission('invoice:create-for-others')) {
            throw new ServiceException(
                'invoice.creation.unauthorized',
                layer: 'service.invoice',
                context: ['clientId' => $dto->clientId, 'userId' => $this->identity->userId],
            );
        }

        // ── Transaction + domain event collection ────────────────────────
        $this->collector->beginCollection();
        $this->transaction->begin();
        try {
            $invoice = Invoice::create(
                InvoiceNumber::generate(),
                ClientId::from($dto->clientId),
                new \DateTimeImmutable($dto->dueDate),
            );

            foreach ($dto->lineItems as $item) {
                $invoice->addLineItem(LineItem::from($item));
            }

            $invoice->issue();

            // Collect domain events from entity into the transaction buffer
            foreach ($invoice->releaseEvents() as $event) {
                $this->collector->collect($event);
            }

            $this->repository->save($invoice);
            $this->transaction->commit();

        } catch (\Throwable $e) {
            $this->transaction->rollback();
            $this->collector->discard(); // No phantom events on failure
            throw new ServiceException(
                'invoice.create.failed',
                layer:   'service.invoice',
                context: ['clientId' => $dto->clientId],
                previous: $e,
            );
        }

        // ── Integration event dispatch — AFTER successful commit ─────────
        $this->eventBus->dispatch(new InvoiceCreatedIntegrationEvent(
            invoiceId:  $invoice->id()->value(),
            clientId:   $dto->clientId,
            amount:     $invoice->total()->value(),
            currency:   $invoice->total()->currency(),
            occurredAt: new \DateTimeImmutable(),
            version:    '1.0',
        ));

        return InvoiceResponseDTO::from($invoice);
    }
}
```

---

## Transaction Pattern — Always This Shape

```php
$this->collector->beginCollection();
$this->transaction->begin();
try {
    // 1. Domain operations
    // 2. Collect domain events
    // 3. Persist
    $this->transaction->commit();

} catch (\Throwable $e) {
    $this->transaction->rollback();
    $this->collector->discard();    // ← ALWAYS discard events on rollback
    throw new ServiceException('...', previous: $e);
}

// 4. Dispatch integration events ONLY here — after successful commit
$this->eventBus->dispatch(new SomethingHappenedIntegrationEvent(...));
```

**Never dispatch an integration event inside a try block.** If the commit fails after the event
was dispatched, you have a phantom event for data that was never persisted.

---

## DTO Pattern — Input and Output

```php
// Input DTO — validated before reaching the service
final readonly class CreateInvoiceDTO
{
    public function __construct(
        public readonly string $clientId,
        public readonly string $dueDate,   // 'Y-m-d' or relative like '+30 days'
        public readonly array  $lineItems,
        public readonly string $currency = 'USD',
    ) {}

    // DTOs may have a factory from Request — validation happens here
    public static function fromRequest(Request $request): self
    {
        $data   = $request->body();
        $errors = [];

        if (empty($data['clientId'])) {
            $errors['clientId'] = 'Client ID is required';
        }
        // ... more validation ...

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        return new self(
            clientId:  $data['clientId'],
            dueDate:   $data['dueDate'],
            lineItems: $data['lineItems'] ?? [],
        );
    }
}

// Output DTO — public shape of the domain entity
final readonly class InvoiceResponseDTO
{
    public function __construct(
        public readonly string  $invoiceId,
        public readonly string  $number,
        public readonly string  $status,
        public readonly string  $clientId,
        public readonly float   $subtotal,
        public readonly float   $tax,
        public readonly float   $total,
        public readonly string  $currency,
        public readonly string  $dueDate,
        public readonly ?string $issuedAt,
        public readonly ?string $paidAt,
        public readonly array   $lineItems,
    ) {}

    public static function from(Invoice $invoice): self
    {
        return new self(
            invoiceId: $invoice->id()->value(),
            number:    $invoice->number()->value(),
            status:    $invoice->status()->value,
            clientId:  $invoice->clientId()->value(),
            subtotal:  $invoice->subtotal()->value(),
            tax:       $invoice->tax()->value(),
            total:     $invoice->total()->value(),
            currency:  $invoice->total()->currency(),
            dueDate:   $invoice->dueDate()->format('Y-m-d'),
            issuedAt:  $invoice->issuedAt()?->format(\DateTimeInterface::RFC3339),
            paidAt:    $invoice->paidAt()?->format(\DateTimeInterface::RFC3339),
            lineItems: array_map(fn($i) => LineItemDTO::from($i)->toArray(), $invoice->lineItems()),
        );
    }

    public function toArray(): array { return get_object_vars($this); }
}
```

---

## Service Contract (API/Contracts/)

```php
// This is the PUBLIC API of the module — other modules import only this interface.
interface InvoiceServiceContract
{
    /**
     * @throws ServiceException code='invoice.creation.unauthorized'
     * @throws ServiceException code='invoice.create.failed'
     * @throws ValidationException if DTO is invalid
     */
    public function create(CreateInvoiceDTO $dto): InvoiceResponseDTO;

    /**
     * @throws ServiceException code='invoice.not_found'
     */
    public function find(string $invoiceId): InvoiceResponseDTO;

    public function list(ListInvoicesDTO $dto): PaginatedResult;

    /**
     * @throws ServiceException code='invoice.not_found'
     * @throws DomainException if invoice is not in ISSUED or OVERDUE status
     */
    public function markPaid(MarkInvoicePaidDTO $dto): void;
}
```

---

## Service Security Pattern

```php
// Pattern: explicit authorization at the start of every mutating service method
public function delete(string $invoiceId): void
{
    $invoice = $this->repository->find($invoiceId);

    // Ownership check (ABAC) + role check (RBAC) combined
    if ($invoice->clientId()->value() !== $this->identity->userId
        && !$this->identity->hasPermission('invoice:delete-any')) {
        throw new ServiceException(
            'invoice.delete.unauthorized',
            layer: 'service.invoice',
            context: ['invoiceId' => $invoiceId, 'userId' => $this->identity->userId],
        );
    }

    // ... proceed with deletion
}
```

---

## Rules for Service Code

When writing or reviewing service code:

- **DO** inject `Identity` via constructor — never resolve it inside a method
- **DO** call `collector->beginCollection()` before `transaction->begin()`
- **DO** call `collector->discard()` in every catch block — no exceptions
- **DO** dispatch integration events ONLY after the transaction commits (outside try/catch)
- **DO** throw `ServiceException` with a dot-notation code: `'invoice.create.failed'`
- **DO** check authorization at the start of every mutating method
- **DON'T** catch `ServiceException` inside the service — let it propagate
- **DON'T** use `QueuePort` directly from service — use `eventBus->dispatch()` with async events
- **DON'T** pass `Request` or `Response` objects into a service method
- **DON'T** call another module's Service class — call its published contract interface
- **DON'T** put business logic in DTOs — DTOs validate shape, not business rules
