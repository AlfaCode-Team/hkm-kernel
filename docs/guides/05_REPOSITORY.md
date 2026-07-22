# HKM Kernel — Repository Layer

> The Repository is the **only layer that touches DatabasePort**. It translates between
> domain entities and raw database rows. It contains SQL but zero business logic.

---

## Repository Rules (ABSOLUTE)

| Rule | Detail |
|---|---|
| ONLY layer using `DatabasePort` | No controller, service, or gateway touches the DB directly |
| Zero business logic | No domain rules, no authorization, no event dispatch |
| One repository per aggregate root | `InvoiceRepository` for Invoice, not for LineItem |
| Returns domain objects, not arrays | `find()` returns `Invoice`, not `['id' => ...]` |
| All SQL in the repository | No SQL strings in services, domain, or controllers |
| Soft-delete by default | Filter `deleted_at IS NULL` on all queries unless specified |
| Always include `tenant_id` in WHERE | Tenant isolation is mandatory — never omit it |
| Translate `\PDOException` to `RepositoryException` | DB errors never leak to higher layers |

---

## Canonical Repository Implementation

```php
<?php
declare(strict_types=1);

namespace InvoiceModule\Infrastructure\Persistence;

use InvoiceModule\Domain\Entities\Invoice;
use InvoiceModule\Domain\ValueObjects\InvoiceId;
use AlfacodeTeam\PhpServicePlatform\Kernel\Auth\Identity;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;

final class InvoiceRepository
{
    public function __construct(
        private readonly DatabasePort $db,
        private readonly Identity     $identity, // for tenant scoping
    ) {}

    // ── Find by ID ──────────────────────────────────────────────────────────
    public function find(string $id): Invoice
    {
        try {
            $row = $this->db->queryOne(
                'SELECT * FROM invoices
                 WHERE id        = :id
                   AND tenant_id = :tenant   -- ALWAYS scope by tenant
                   AND deleted_at IS NULL',
                ['id' => $id, 'tenant' => $this->identity->tenantId]
            );
        } catch (\PDOException $e) {
            throw new RepositoryException(
                "Failed to find invoice [{$id}]",
                layer:    'repository.invoice',
                context:  ['invoiceId' => $id],
                previous: $e,
            );
        }

        if ($row === null) {
            throw new RepositoryException(
                "Invoice [{$id}] not found",
                layer:   'repository.invoice',
                context: ['invoiceId' => $id],
            );
        }

        $lineRows = $this->db->query(
            'SELECT * FROM invoice_line_items WHERE invoice_id = :id ORDER BY sort_order ASC',
            ['id' => $id]
        );

        return InvoiceHydrator::hydrate($row, $lineRows);
    }

    // ── Persist (insert or update) ──────────────────────────────────────────
    public function save(Invoice $invoice): void
    {
        try {
            $data = InvoiceHydrator::dehydrate($invoice);
            $affected = $this->db->execute(
                'INSERT INTO invoices
                 (id, number, tenant_id, client_id, status,
                  subtotal_cents, tax_cents, currency, due_date,
                  issued_at, paid_at, cancelled_at, version)
                 VALUES
                 (:id, :number, :tenant_id, :client_id, :status,
                  :subtotal_cents, :tax_cents, :currency, :due_date,
                  :issued_at, :paid_at, :cancelled_at, 1)
                 ON DUPLICATE KEY UPDATE
                   status         = VALUES(status),
                   subtotal_cents = VALUES(subtotal_cents),
                   tax_cents      = VALUES(tax_cents),
                   issued_at      = VALUES(issued_at),
                   paid_at        = VALUES(paid_at),
                   cancelled_at   = VALUES(cancelled_at),
                   version        = version + 1',
                $data
            );
        } catch (\PDOException $e) {
            throw new RepositoryException(
                'Failed to persist invoice',
                layer:    'repository.invoice',
                context:  ['invoiceId' => $invoice->id()->value()],
                previous: $e,
            );
        }

        // Save line items
        $this->saveLineItems($invoice);
    }

    // ── Soft delete ─────────────────────────────────────────────────────────
    public function softDelete(string $id): void
    {
        $this->db->execute(
            'UPDATE invoices
             SET deleted_at = NOW()
             WHERE id        = :id
               AND tenant_id = :tenant
               AND deleted_at IS NULL',
            ['id' => $id, 'tenant' => $this->identity->tenantId]
        );
    }

    // ── Criteria-based list ─────────────────────────────────────────────────
    public function findByCriteria(InvoiceCriteria $c): PaginatedResult
    {
        [$where, $params] = $this->buildWhere($c);

        $total = (int) $this->db->queryOne(
            "SELECT COUNT(*) AS n FROM invoices WHERE {$where}", $params
        )['n'];

        $rows = $this->db->query(
            "SELECT * FROM invoices
             WHERE  {$where}
             ORDER  BY {$this->sanitizeOrder($c->sortBy, $c->sortDir)}
             LIMIT  :limit
             OFFSET :offset",
            array_merge($params, [
                'limit'  => $c->perPage,
                'offset' => ($c->page - 1) * $c->perPage,
            ])
        );

        return new PaginatedResult(
            data:     array_map(fn($r) => InvoiceHydrator::hydrateRow($r), $rows),
            total:    $total,
            page:     $c->page,
            perPage:  $c->perPage,
            lastPage: (int) ceil($total / $c->perPage),
        );
    }

    // ── Private helpers ─────────────────────────────────────────────────────
    private function buildWhere(InvoiceCriteria $c): array
    {
        $clauses = [
            'tenant_id  = :tenant_id',
            'deleted_at IS NULL',
        ];
        $params = ['tenant_id' => $this->identity->tenantId];

        if ($c->clientId) {
            $clauses[] = 'client_id = :client_id';
            $params['client_id'] = $c->clientId;
        }
        if ($c->status) {
            $clauses[] = 'status = :status';
            $params['status'] = $c->status->value;
        }

        return [implode(' AND ', $clauses), $params];
    }

    private function sanitizeOrder(string $sortBy, string $sortDir): string
    {
        $allowed = ['created_at', 'due_date', 'total_cents', 'number', 'status'];
        $col     = in_array($sortBy, $allowed, true) ? $sortBy : 'created_at';
        $dir     = strtoupper($sortDir) === 'ASC' ? 'ASC' : 'DESC';
        return "{$col} {$dir}";
    }
}
```

---

## Hydrator Pattern

```php
// Hydrator: translates DB rows ↔ Domain objects. Never in the entity itself.
final class InvoiceHydrator
{
    public static function hydrate(array $row, array $lineRows): Invoice
    {
        return Invoice::reconstitute(
            id:          InvoiceId::from($row['id']),
            number:      InvoiceNumber::of($row['number']),
            clientId:    ClientId::from($row['client_id']),
            status:      InvoiceStatus::from($row['status']),
            subtotal:    Money::fromCents((int) $row['subtotal_cents'], $row['currency']),
            tax:         Money::fromCents((int) $row['tax_cents'], $row['currency']),
            dueDate:     new \DateTimeImmutable($row['due_date']),
            lineItems:   array_map(fn($r) => LineItemHydrator::hydrate($r), $lineRows),
            issuedAt:    $row['issued_at'] ? new \DateTimeImmutable($row['issued_at']) : null,
            paidAt:      $row['paid_at']   ? new \DateTimeImmutable($row['paid_at'])   : null,
            createdAt:   new \DateTimeImmutable($row['created_at']),
            version:     (int) ($row['version'] ?? 1),
        );
    }

    public static function dehydrate(Invoice $invoice): array
    {
        return [
            'id'             => $invoice->id()->value(),
            'number'         => $invoice->number()->value(),
            'tenant_id'      => $invoice->tenantId(),
            'client_id'      => $invoice->clientId()->value(),
            'status'         => $invoice->status()->value,
            'subtotal_cents' => $invoice->subtotal()->amount(),
            'tax_cents'      => $invoice->tax()->amount(),
            'currency'       => $invoice->subtotal()->currency(),
            'due_date'       => $invoice->dueDate()->format('Y-m-d'),
            'issued_at'      => $invoice->issuedAt()?->format('Y-m-d H:i:s'),
            'paid_at'        => $invoice->paidAt()?->format('Y-m-d H:i:s'),
            'cancelled_at'   => $invoice->cancelledAt()?->format('Y-m-d H:i:s'),
        ];
    }
}
```

---

## Migration Pattern

```php
// Migrations implement MigrationContract — run via `php cli.php migrate`
final class CreateInvoicesTable implements MigrationContract
{
    public function up(DatabasePort $db): void
    {
        $db->execute("
            CREATE TABLE invoices (
                id              CHAR(26)       NOT NULL PRIMARY KEY,  -- ULID
                number          VARCHAR(32)    NOT NULL UNIQUE,
                tenant_id       CHAR(26)       NOT NULL,
                client_id       CHAR(26)       NOT NULL,
                status          VARCHAR(16)    NOT NULL DEFAULT 'draft',
                subtotal_cents  BIGINT         NOT NULL DEFAULT 0,
                tax_cents       BIGINT         NOT NULL DEFAULT 0,
                currency        CHAR(3)        NOT NULL DEFAULT 'USD',
                due_date        DATE           NOT NULL,
                issued_at       DATETIME       NULL,
                paid_at         DATETIME       NULL,
                cancelled_at    DATETIME       NULL,
                version         INT            NOT NULL DEFAULT 1,
                created_at      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at      DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                deleted_at      DATETIME       NULL,
                INDEX idx_tenant_status  (tenant_id, status),
                INDEX idx_tenant_client  (tenant_id, client_id),
                INDEX idx_tenant_due     (tenant_id, due_date),
                INDEX idx_deleted_at     (deleted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(DatabasePort $db): void
    {
        $db->execute('DROP TABLE IF EXISTS invoices');
    }
}
```

---

## Rules for Repository Code

When writing or reviewing repository code:

- **DO** include `tenant_id = :tenant` in EVERY query — never omit tenant scoping
- **DO** include `deleted_at IS NULL` in EVERY query unless explicitly fetching deleted records
- **DO** translate `\PDOException` to `RepositoryException` in every try/catch
- **DO** use parameterized queries — never string concatenation in SQL
- **DO** sanitize ORDER BY columns against an allowlist — never pass user input directly to SQL
- **DO** store money as integer cents (BIGINT) — never DECIMAL or FLOAT for money
- **DON'T** put business logic in repositories — no authorization, no domain rules
- **DON'T** return raw arrays from `find()` — return domain objects
- **DON'T** let `\PDOException` propagate to the service layer
- **DON'T** create a repository for child entities — access them through the aggregate root
- **DON'T** use any ORM, Eloquent, or Active Record in the repository layer
