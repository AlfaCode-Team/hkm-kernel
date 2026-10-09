# Data Access

The data access layer bridges repositories and the database through the `DatabasePort` interface. This layer defines portable, vendor-independent patterns for querying, inserting, updating, and deleting data while respecting the framework's constraints: no ORMs, no vendor-specific SQL, and always tenant-scoped.

## DatabasePort Interface

The kernel defines a minimal, portable interface that every database adapter must implement:

```php
interface DatabasePort
{
    public function query(string $sql, array $params = []): array;
    public function queryOne(string $sql, array $params = []): ?array;
    public function execute(string $sql, array $params = []): int;
    public function upsert(string $table, array $values, array $conflictColumns, ?array $updateColumns = null): int;
    public function lastInsertId(?string $sequence = null): string;
    public function beginTransaction(): void;
    public function commit(): void;
    public function rollback(): void;
    public function inTransaction(): bool;
}
```

All SQL is **parameterized** — bound values protect against SQL injection, and the adapter handles dialect differences.

## Query Patterns

### Reading Data

Fetch multiple rows or a single row. Rows are returned as associative arrays `['column' => value]`.

```php
// Multiple rows
$invoices = $this->db->query(
    "SELECT id, client_id, amount FROM invoices 
     WHERE tenant_id = :tenant AND status = :status",
    ['tenant' => $tenantId, 'status' => 'issued'],
);

foreach ($invoices as $row) {
    $invoice = InvoiceHydrator::hydrate($row);
}

// Single row
$row = $this->db->queryOne(
    "SELECT * FROM invoices WHERE id = :id AND tenant_id = :tenant",
    ['id' => $id, 'tenant' => $tenantId],
);

if ($row !== null) {
    $invoice = InvoiceHydrator::hydrate($row);
}

// Count
$result = $this->db->queryOne("SELECT COUNT(*) as count FROM invoices WHERE tenant_id = :tenant", ['tenant' => $tenantId]);
$total = (int) ($result['count'] ?? 0);
```

### Writing Data

`execute()` returns the number of affected rows.

```php
// Insert
$affected = $this->db->execute(
    "INSERT INTO invoices (id, tenant_id, client_id, amount) 
     VALUES (:id, :tenant_id, :client_id, :amount)",
    [
        'id'         => $id,
        'tenant_id'  => $tenantId,
        'client_id'  => $clientId,
        'amount'     => 9999,  // cents
    ],
);

// Update
$affected = $this->db->execute(
    "UPDATE invoices SET status = :status WHERE id = :id AND tenant_id = :tenant",
    ['id' => $id, 'tenant' => $tenantId, 'status' => 'issued'],
);

// Delete (soft)
$affected = $this->db->execute(
    "UPDATE invoices SET deleted_at = :now WHERE id = :id AND tenant_id = :tenant",
    ['id' => $id, 'tenant' => $tenantId, 'now' => date('Y-m-d H:i:s')],
);

// Hard delete
$affected = $this->db->execute(
    "DELETE FROM invoices WHERE id = :id AND tenant_id = :tenant",
    ['id' => $id, 'tenant' => $tenantId],
);
```

## Portable Upsert

Instead of hand-writing `ON DUPLICATE KEY UPDATE` (MySQL) or `ON CONFLICT … DO UPDATE` (PostgreSQL), use `upsert()`. It compiles the correct SQL for the underlying driver.

### Upsert Semantics

```php
$affected = $this->db->upsert(
    table:            'invoices',
    values:           [
        'id'         => $id,
        'tenant_id'  => $tenantId,
        'client_id'  => $clientId,
        'amount'     => 9999,
        'status'     => 'draft',
    ],
    conflictColumns:  ['id', 'tenant_id'],
    updateColumns:    ['amount', 'status', 'updated_at'],  // null = all except conflict
);
```

**Arguments:**

- **`table`** — table name
- **`values`** — all columns to insert; subset used for update
- **`conflictColumns`** — columns that trigger the conflict (usually primary key or unique index)
- **`updateColumns`** — how to update on conflict:
  - `null` (default) — update every column EXCEPT the conflict columns
  - `[]` — insert-if-absent; do nothing on conflict
  - `['col1', 'col2']` — update ONLY these columns

### Examples

**Insert or update everything:**

```php
// If (id, tenant_id) exists, update amount, status, and updated_at
$this->db->upsert('invoices', [
    'id'         => $id,
    'tenant_id'  => $tenantId,
    'amount'     => 9999,
    'status'     => 'draft',
    'updated_at' => date('Y-m-d H:i:s'),
], ['id', 'tenant_id']);  // updateColumns = null → all except id, tenant_id
```

**Insert-if-absent (do nothing on conflict):**

```php
// Create the invoice only if it does not already exist
$this->db->upsert('invoices', [
    'id'        => $id,
    'tenant_id' => $tenantId,
    'amount'    => 9999,
], ['id', 'tenant_id'], []);  // empty updateColumns → no update on conflict
```

**Selective update:**

```php
// Update amount and status only; leave others unchanged
$this->db->upsert('invoices', [
    'id'        => $id,
    'tenant_id' => $tenantId,
    'amount'    => 9999,
    'status'    => 'draft',
], ['id', 'tenant_id'], ['amount', 'status']);
```

## LastInsertId (PostgreSQL Sequences)

After an INSERT, fetch the generated ID. PostgreSQL requires the sequence name; MySQL/SQLite ignore it.

```php
// Auto-increment ID (MySQL/SQLite)
$this->db->execute("INSERT INTO invoices (client_id) VALUES (:client_id)", [...]
$id = $this->db->lastInsertId();

// Sequence name (PostgreSQL)
$this->db->execute("INSERT INTO invoices (client_id) VALUES (:client_id)", [...]);
$id = $this->db->lastInsertId('invoices_id_seq');
```

To be portable, **always pass the sequence name**; adapters for MySQL/SQLite simply ignore it:

```php
$id = $this->db->lastInsertId('invoices_id_seq');  // Works on PostgreSQL and MySQL
```

## Driver Detection

Some SQL must be different per database (date functions, JSON operators, etc.). Use `DriverAware` to detect the database at runtime:

```php
<?php declare(strict_types=1);
namespace Shop\Invoice\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\{DatabasePort, DriverAware};

final class InvoiceRepository
{
    public function __construct(
        private readonly DatabasePort $db,
    ) {}

    public function findByMetadata(array $metadata): array
    {
        // Check if the port exposes its driver
        $driver = ($this->db instanceof DriverAware) ? $this->db->driver() : null;

        if ($driver === 'mysql') {
            $sql = "SELECT * FROM invoices 
                   WHERE JSON_EXTRACT(metadata, '$.status') = :value";
        } elseif ($driver === 'pgsql') {
            $sql = "SELECT * FROM invoices 
                   WHERE metadata->>'status' = :value";
        } else {
            // Fallback: application-level filtering
            $sql = "SELECT * FROM invoices";
            $invoices = $this->db->query($sql);
            return array_filter($invoices, fn($row) => 
                ($row['metadata']['status'] ?? null) === 'draft'
            );
        }

        return $this->db->query($sql, ['value' => 'draft']);
    }
}
```

**Drivers:** `'mysql'`, `'pgsql'`, `'sqlite'`, `'sqlsrv'` (lower-case).

::: warning DriverAware is Optional
Not every `DatabasePort` implementation exposes `DriverAware`. Always check `instanceof` and fall back to a safe default (application-level filtering, or an env var) when the port does not expose it.
:::

## SQL to Avoid

### ✗ Driver-Specific Functions Without Branching

```php
// WRONG — MySQL-specific; fails on PostgreSQL
$this->db->query("SELECT * FROM invoices WHERE DATE(created_at) = CURDATE()");

// WRONG — PostgreSQL-specific; fails on MySQL
$this->db->query("SELECT * FROM invoices WHERE created_at::date = CURRENT_DATE");
```

### ✓ Portable Approach

```php
// RIGHT — bind the date boundary
$today = date('Y-m-d');
$this->db->query(
    "SELECT * FROM invoices 
     WHERE created_at >= :start AND created_at < :end",
    ['start' => "{$today} 00:00:00", 'end' => date('Y-m-d H:i:s', strtotime('+1 day'))],
);
```

### ✗ Hard-Coded Table Names

```php
// WRONG — string interpolation; SQL injection risk and no reusability
$sql = "SELECT * FROM $table WHERE id = ?";  // unsafe
```

### ✓ Parameterized Tables (Or Validate + Quote)

```php
// RIGHT — pass the table as a constant or validate against a whitelist
const ALLOWED_TABLES = ['invoices', 'payments', 'items'];
$table = $params['table'] ?? 'invoices';
if (!in_array($table, ALLOWED_TABLES, true)) {
    throw new \DomainException('Invalid table.');
}
$sql = "SELECT * FROM {$table} WHERE id = :id";
$this->db->query($sql, ['id' => $id]);
```

### ✗ CONCAT / String Functions Without Branching

```php
// WRONG — MySQL-specific
$this->db->query("SELECT CONCAT(first_name, ' ', last_name) as full_name FROM users");

// WRONG — PostgreSQL-specific
$this->db->query("SELECT first_name || ' ' || last_name as full_name FROM users");
```

### ✓ Concatenate in PHP

```php
// RIGHT — compute in application code
$users = $this->db->query("SELECT first_name, last_name FROM users");
$results = array_map(fn($u) => [
    'full_name' => "{$u['first_name']} {$u['last_name']}",
], $users);
```

### ✗ Automatic Timestamp Update

```php
// WRONG — works on MySQL but not on PostgreSQL
CREATE TABLE invoices (
    id VARCHAR(36) PRIMARY KEY,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

### ✓ Application-Level Timestamps

```php
// RIGHT — explicit in the SQL
$this->db->execute(
    "UPDATE invoices SET updated_at = :now WHERE id = :id",
    ['id' => $id, 'now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')],
);
```

### ✗ Hand-Written Upsert

```php
// WRONG — MySQL-only; fails on PostgreSQL
$this->db->execute(
    "INSERT INTO invoices (id, amount) VALUES (:id, :amount)
     ON DUPLICATE KEY UPDATE amount = :amount",
    ['id' => $id, 'amount' => 9999],
);
```

### ✓ Use DatabasePort::upsert()

```php
// RIGHT — compiles to the correct dialect
$this->db->upsert('invoices', ['id' => $id, 'amount' => 9999], ['id']);
```

## Schema Best Practices

### Tenant Scoping

**Always include `tenant_id` in your unique indexes and primary keys:**

```sql
CREATE TABLE invoices (
    id CHAR(36) PRIMARY KEY,
    tenant_id CHAR(36) NOT NULL,
    client_id CHAR(36) NOT NULL,
    amount INT NOT NULL,  -- cents
    status VARCHAR(50) NOT NULL,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL,
    deleted_at TIMESTAMP NULL,
    
    UNIQUE KEY idx_tenant_id (tenant_id, id),
    INDEX idx_tenant_status (tenant_id, status),
    INDEX idx_tenant_created (tenant_id, created_at)
);
```

### Money Storage

**Store money as integer cents, never as FLOAT:**

```sql
-- WRONG
amount DECIMAL(10, 2)  -- tempting but imprecise

-- RIGHT
amount INT  -- represents cents; 9999 = $99.99
```

### Timestamps

**Use `TIMESTAMP` or `DATETIME`; always store in UTC; include timezone in the application:**

```sql
CREATE TABLE invoices (
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL  -- soft delete
);
```

## Source

- [src/Kernel/Ports/DatabasePort.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Ports/DatabasePort.php)
- [src/Kernel/Ports/DriverAware.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Ports/DriverAware.php)
- [docs/guides/22_DATA_ACCESS_ORM_BLUEPRINT.md](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/docs/guides/22_DATA_ACCESS_ORM_BLUEPRINT.md) (internal reference)
