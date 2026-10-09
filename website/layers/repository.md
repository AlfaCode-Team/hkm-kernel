# Repository Layer

Repositories are the exclusive bridge between the domain and data access layers. A repository is responsible for loading and persisting domain entities, translating between domain objects and database rows. Repositories **only talk to the database** through the `DatabasePort` — never to vendor SDKs, APIs, or other infrastructure.

## Repository Rules

- **Implement ONE per entity** — `InvoiceRepository`, `PaymentRepository`
- **Depend on `DatabasePort` only** — no HTTP clients, no vendor SDKs, no ORM
- **Inject `Identity` for tenant scoping** — ALWAYS filter by `$identity->tenantId`
- **Translate `\PDOException` to `RepositoryException`** — never let database errors escape
- **Use hydrators** — map rows to domain objects; reverse-map objects back
- **Call `upsert()` not hand-written `ON DUPLICATE KEY`** — portable across databases
- **Use `lastInsertId(sequence)`** — pass the sequence name on PostgreSQL

## Complete Example

```php
<?php declare(strict_types=1);
namespace Shop\Invoice\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Shop\Invoice\Domain\Entities\Invoice;
use Shop\Invoice\Domain\ValueObjects\{InvoiceId, Money};
use Shop\Invoice\Infrastructure\Hydrators\InvoiceHydrator;

final class InvoiceRepository
{
    public function __construct(
        private readonly DatabasePort $db,
        private readonly Identity      $identity,  // tenant scoping
    ) {}

    /**
     * Fetch an invoice by ID. Scoped to tenant.
     */
    public function find(string $id): ?Invoice
    {
        try {
            $row = $this->db->queryOne(
                "SELECT * FROM invoices 
                 WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL",
                [
                    'id'     => $id,
                    'tenant' => $this->identity->tenantId,
                ],
            );
        } catch (\PDOException $e) {
            throw new RepositoryException(
                "Failed to fetch invoice [{$id}].",
                layer:    'repository.invoice.find',
                context:  ['id' => $id],
                previous: $e,
            );
        }

        return $row ? InvoiceHydrator::hydrate($row) : null;
    }

    /**
     * Find all invoices for this tenant, paginated.
     *
     * @return array{invoices: Invoice[], total: int}
     */
    public function paginate(int $page = 1, int $perPage = 20): array
    {
        try {
            $offset = ($page - 1) * $perPage;

            $invoices = $this->db->query(
                "SELECT * FROM invoices 
                 WHERE tenant_id = :tenant AND deleted_at IS NULL
                 ORDER BY created_at DESC
                 LIMIT :limit OFFSET :offset",
                [
                    'tenant' => $this->identity->tenantId,
                    'limit'  => $perPage,
                    'offset' => $offset,
                ],
            );

            $total = $this->db->queryOne(
                "SELECT COUNT(*) as count FROM invoices 
                 WHERE tenant_id = :tenant AND deleted_at IS NULL",
                ['tenant' => $this->identity->tenantId],
            );

            return [
                'invoices' => array_map([InvoiceHydrator::class, 'hydrate'], $invoices),
                'total'    => (int) ($total['count'] ?? 0),
            ];
        } catch (\PDOException $e) {
            throw new RepositoryException(
                'Failed to fetch invoices.',
                layer:    'repository.invoice.paginate',
                context:  ['page' => $page, 'per_page' => $perPage],
                previous: $e,
            );
        }
    }

    /**
     * Persist a new invoice.
     */
    public function save(Invoice $invoice): void
    {
        try {
            $this->db->execute(
                "INSERT INTO invoices 
                    (id, tenant_id, client_id, amount, currency, status, created_at, updated_at)
                 VALUES
                    (:id, :tenant_id, :client_id, :amount, :currency, :status, :created_at, :updated_at)",
                [
                    'id'         => $invoice->id()->value(),
                    'tenant_id'  => $this->identity->tenantId,
                    'client_id'  => $invoice->clientId(),
                    'amount'     => $invoice->amount()->amount(),  // cents
                    'currency'   => $invoice->amount()->currency(),
                    'status'     => $invoice->status()->value(),
                    'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                    'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ],
            );
        } catch (\PDOException $e) {
            throw new RepositoryException(
                'Failed to save invoice.',
                layer:    'repository.invoice.save',
                context:  ['id' => $invoice->id()->value()],
                previous: $e,
            );
        }
    }

    /**
     * Update an existing invoice (for mutations like issue()).
     */
    public function update(Invoice $invoice): void
    {
        try {
            $this->db->execute(
                "UPDATE invoices 
                 SET status = :status, updated_at = :updated_at
                 WHERE id = :id AND tenant_id = :tenant",
                [
                    'id'         => $invoice->id()->value(),
                    'tenant'     => $this->identity->tenantId,
                    'status'     => $invoice->status()->value(),
                    'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ],
            );
        } catch (\PDOException $e) {
            throw new RepositoryException(
                'Failed to update invoice.',
                layer:    'repository.invoice.update',
                context:  ['id' => $invoice->id()->value()],
                previous: $e,
            );
        }
    }

    /**
     * Soft-delete an invoice.
     */
    public function delete(string $id): void
    {
        try {
            $this->db->execute(
                "UPDATE invoices 
                 SET deleted_at = :now 
                 WHERE id = :id AND tenant_id = :tenant",
                [
                    'id'     => $id,
                    'tenant' => $this->identity->tenantId,
                    'now'    => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ],
            );
        } catch (\PDOException $e) {
            throw new RepositoryException(
                "Failed to delete invoice [{$id}].",
                layer:    'repository.invoice.delete',
                context:  ['id' => $id],
                previous: $e,
            );
        }
    }
}
```

## Hydrators — Map Rows to Domain Objects

A hydrator translates between database rows (associative arrays) and domain entities. This separation keeps repositories clean and easy to test.

```php
<?php declare(strict_types=1);
namespace Shop\Invoice\Infrastructure\Hydrators;

use Shop\Invoice\Domain\Entities\Invoice;
use Shop\Invoice\Domain\ValueObjects\{InvoiceId, InvoiceStatus, Money};

final class InvoiceHydrator
{
    /**
     * Hydrate a database row into a domain entity.
     * Uses reconstitute() so NO domain events are recorded.
     *
     * @param array<string, mixed> $row
     */
    public static function hydrate(array $row): Invoice
    {
        return Invoice::reconstitute(
            id:      InvoiceId::from($row['id']),
            clientId: $row['client_id'],
            amount:  Money::cents((int) $row['amount'], $row['currency']),
            status:  InvoiceStatus::from($row['status']),
            issuedAt: new \DateTimeImmutable($row['created_at']),
        );
    }

    /**
     * Optionally, map an entity back to an insert/update row.
     *
     * @return array<string, mixed>
     */
    public static function extract(Invoice $invoice): array
    {
        return [
            'id'       => $invoice->id()->value(),
            'client_id' => $invoice->clientId(),
            'amount'   => $invoice->amount()->amount(),
            'currency' => $invoice->amount()->currency(),
            'status'   => $invoice->status()->value(),
        ];
    }
}
```

## Tenant Scoping

Every query must be scoped to the current tenant via `Identity::tenantId`. Forgetting this is a critical security bug: a customer's data leaks to another tenant.

```php
// ALWAYS include tenant_id in WHERE clauses
$this->db->queryOne(
    "SELECT * FROM invoices WHERE id = :id AND tenant_id = :tenant",
    ['id' => $id, 'tenant' => $this->identity->tenantId],
);

// NOT:
$this->db->queryOne("SELECT * FROM invoices WHERE id = :id", ['id' => $id]);
```

## Portable Upsert

Instead of hand-writing `ON DUPLICATE KEY UPDATE` (MySQL) or `ON CONFLICT … DO UPDATE` (PostgreSQL), use `DatabasePort::upsert()`. It compiles the correct SQL for the underlying driver.

```php
$affected = $this->db->upsert(
    table:            'invoices',
    values:           [
        'id'       => $invoiceId,
        'tenant_id' => $tenantId,
        'amount'   => $amount,
        'status'   => 'draft',
    ],
    conflictColumns:  ['id', 'tenant_id'],
    updateColumns:    ['amount', 'status', 'updated_at'],  // null = all except conflict columns
);
```

**Arguments:**

- `$table` — table name (string)
- `$values` — column => value to insert (also used for the update)
- `$conflictColumns` — columns that define uniqueness (usually primary key or unique index)
- `$updateColumns` — `null` = every non-conflict column, `[]` = insert-if-absent, list = those only

## LastInsertId on PostgreSQL

After an INSERT, retrieve the generated ID. PostgreSQL requires the sequence name:

```php
$this->db->execute(
    "INSERT INTO invoices (id, client_id) VALUES (:id, :client_id)",
    ['id' => $uuid, 'client_id' => $clientId],
);

// MySQL/SQLite ignore the sequence argument
$lastId = $this->db->lastInsertId();           // Auto-increment ID

// PostgreSQL requires the sequence name
$lastId = $this->db->lastInsertId('invoices_id_seq');
```

To be portable across databases, pass the sequence name (PostgreSQL uses it; MySQL/SQLite ignore it).

## Translating Errors

**Always catch `\PDOException` and re-throw as `RepositoryException`.** Never let database-layer exceptions escape to the service or controller.

```php
try {
    $result = $this->db->execute($sql, $params);
} catch (\PDOException $e) {
    throw new RepositoryException(
        'Failed to update invoice.',
        layer:    'repository.invoice.update',
        context:  ['id' => $id],
        previous: $e,
    );
}
```

## Optimistic Locking

For handling concurrent updates without locks, use version numbers. Throw `OptimisticLockException` if the version has changed since the entity was loaded.

```php
try {
    $this->db->execute(
        "UPDATE invoices 
         SET status = :status, version = version + 1
         WHERE id = :id AND version = :version",
        [
            'id'      => $invoiceId,
            'version' => $currentVersion,
            'status'  => 'issued',
        ],
    );

    // If no rows were updated, the version did not match
    if ($result === 0) {
        throw new OptimisticLockException(
            'Invoice was modified by another process.',
            layer: 'repository.invoice.update',
        );
    }
} catch (\PDOException $e) {
    throw new RepositoryException(
        'Failed to update invoice.',
        layer:    'repository.invoice.update',
        previous: $e,
    );
}
```

## Common Mistakes

### ✗ Forgetting Tenant Scope

```php
// WRONG — data from all tenants returned
$invoices = $this->db->query(
    "SELECT * FROM invoices WHERE status = :status",
    ['status' => 'issued'],
);
```

### ✓ Always Include Tenant ID

```php
// RIGHT — scoped to the current tenant
$invoices = $this->db->query(
    "SELECT * FROM invoices WHERE tenant_id = :tenant AND status = :status",
    ['tenant' => $this->identity->tenantId, 'status' => 'issued'],
);
```

### ✗ Hand-Written Upsert Clauses

```php
// WRONG — MySQL-specific; fails on PostgreSQL
$this->db->execute(
    "INSERT INTO invoices (id, amount) VALUES (:id, :amount)
     ON DUPLICATE KEY UPDATE amount = :amount",
    [...],
);
```

### ✓ Use DatabasePort::upsert()

```php
// RIGHT — compiles to the correct dialect
$this->db->upsert('invoices', ['id' => $id, 'amount' => $amount], ['id'], ['amount']);
```

### ✗ Float for Money

```php
// WRONG — precision loss
$this->db->execute(
    "INSERT INTO invoices (amount) VALUES (:amount)",
    ['amount' => 99.99],  // float → 99.98999...
);
```

### ✓ Store as Integer Cents

```php
// RIGHT — exact integer arithmetic
$this->db->execute(
    "INSERT INTO invoices (amount) VALUES (:amount)",
    ['amount' => 9999],  // 99.99 USD
);
```

## Source

- [src/Kernel/Ports/DatabasePort.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Ports/DatabasePort.php)
- [src/Kernel/Exceptions/RepositoryException.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Exceptions/RepositoryException.php)
- [src/Kernel/Exceptions/OptimisticLockException.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Exceptions/OptimisticLockException.php)
- [docs/guides/05_REPOSITORY.md](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/docs/guides/05_REPOSITORY.md) (internal reference)
