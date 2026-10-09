# Database — querying and transactions

A repository in this framework touches the database exclusively through `DatabasePort`, which repositories resolve from their scoped `ModuleContainer`. The kernel provides `TransactionManager` for nesting-aware transaction control, and expects adapters to support key features like portable upserts and multi-database migration.

## DatabasePort methods

See [Ports](/infrastructure/ports#databaseport) for the complete interface reference.

### Portable upserts — not dialect-specific SQL

Never write `ON DUPLICATE KEY UPDATE` or `ON CONFLICT … DO UPDATE` by hand. Instead, call `upsert()`:

```php
$affected = $db->upsert(
    table: 'invoices',
    values: [
        'id' => $invoiceId,
        'total' => $total,
        'status' => 'draft',
    ],
    conflictColumns: ['id'],
    updateColumns: ['total', 'status'],  // null = all non-conflict cols
);
```

The adapter compiles the correct grammar for its underlying database:

- **MySQL**: `INSERT INTO invoices (…) VALUES (…) ON DUPLICATE KEY UPDATE …`
- **PostgreSQL**: `INSERT INTO invoices (…) VALUES (…) ON CONFLICT (id) DO UPDATE SET …`
- **SQLite**: `INSERT INTO invoices (…) VALUES (…) ON CONFLICT(id) DO UPDATE SET …`
- **SQL Server**: `MERGE INTO invoices USING source WHEN MATCHED THEN UPDATE …`

This is the ONLY correct way to write upsert logic in a repository — hand-writing it breaks cross-database portability.

### Returning the auto-increment id

After an INSERT:

```php
$db->execute('INSERT INTO users (email) VALUES (?)', [$email]);
$userId = $db->lastInsertId();  // MySQL/SQLite: ignore the arg
$userId = $db->lastInsertId('users_id_seq');  // PostgreSQL: pass the sequence name
```

PostgreSQL requires the sequence name because a table may have multiple sequences; MySQL and SQLite ignore it.

### Parameter binding

Always use parameterized queries. The adapter handles escaping and prevents SQL injection:

```php
$rows = $db->query(
    'SELECT * FROM invoices WHERE tenant_id = ? AND status = ?',
    [$tenantId, 'issued']
);

$affected = $db->execute(
    'UPDATE invoices SET status = ? WHERE id = ?',
    ['paid', $invoiceId]
);
```

## TransactionManager

Nesting-aware transaction control. Manages a depth counter so nested service calls don't double-commit or prematurely close a transaction.

```php
final class TransactionManager
{
    public function begin(): void;
    public function commit(): void;
    public function rollback(): void;
    public function inTransaction(): bool;
}
```

### Typical usage in a service

```php
final class InvoiceService
{
    public function __construct(
        private readonly InvoiceRepository $repository,
        private readonly TransactionManager $transaction,
        private readonly DomainEventCollector $collector,
        private readonly EventBus $eventBus,
    ) {}

    public function issue(string $invoiceId): void
    {
        $this->collector->beginCollection();
        $this->transaction->begin();

        try {
            $invoice = $this->repository->find($invoiceId);
            $invoice->issue();

            foreach ($invoice->releaseEvents() as $event) {
                $this->collector->collect($event);
            }

            $this->repository->save($invoice);
            $this->transaction->commit();

        } catch (\Throwable $e) {
            $this->transaction->rollback();
            $this->collector->discard();  // never persist phantom events
            throw new ServiceException('invoice.issue.failed', previous: $e);
        }

        // AFTER commit, dispatch integration events
        $this->eventBus->dispatch(new InvoiceIssuedIntegrationEvent(...));
    }
}
```

### Nesting semantics

A nested `begin()` increments a depth counter; only the outermost `commit()` actually commits. This allows composing services:

```php
public function createWithLineItems(array $items): Invoice
{
    $this->transaction->begin();
    try {
        $invoice = $this->repository->create($data);
        
        // This inner service ALSO calls begin/commit.
        // The inner commit() increments depth but doesn't commit.
        $this->lineItemService->addToInvoice($invoice->id(), $items);
        
        $this->transaction->commit();  // outer commit — this one actually commits
    } catch (\Throwable $e) {
        $this->transaction->rollback();  // rolls back everything
        throw new ServiceException('create.failed', previous: $e);
    }
}
```

If any nested call throws and rolls back, the entire transaction is rolled back.

## Tenant-scoped database binding

For multi-tenant applications where each tenant connects to a different database, rebind `DatabasePort` per request in a module's `register()` method, NOT in `withPorts()`:

```php
// WRONG — this is app-lifetime:
Kernel::configure()->withPorts([
    DatabasePort::class => $db,  // shared across all tenants
])

// CORRECT — request-scoped rebinding:
final class TenancyProvider implements ModuleContract
{
    public function register(ModuleContainer $container): void
    {
        $container->singleton(DatabasePort::class, function (ModuleContainer $c) {
            $tenant = $c->make(TenantContext::class);
            return new MySQLAdapter(config('databases.' . $tenant->slug));
        });
    }
}
```

Why this matters: Under OpenSwoole, a static/app-lifetime binding leaks across coroutines. If one request reads from Tenant A's database and the next overwrites the connection with Tenant B's, both coroutines see Tenant B's data — a silent multi-tenancy break. Request-scoped rebinding isolates each request's container and its database connection.

## DriverAware — checking the underlying SQL dialect

A `DatabasePort` may implement the optional `DriverAware` interface to declare its SQL dialect:

```php
if ($db instanceof DriverAware) {
    $driver = $db->driver();  // 'mysql' | 'pgsql' | 'sqlite' | 'sqlsrv'
}
```

Use this when you must branch SQL for compatibility (e.g., a query builder that generates different SQL per database). Most code should avoid branching and write portable queries instead — hand-writing `ON DUPLICATE KEY` is the canonical example to avoid.

## Transactions and the error pipeline

A service-layer exception triggers rollback BEFORE it reaches the error pipeline. The error pipeline logs the exception (and optionally notifies via Slack/mail), but by then the database change has been rolled back:

```
Service throws
    ↓
catch in Service
    → transaction->rollback()
    → collector->discard()
    ↓
throw ServiceException
    ↓
ErrorPipeline
    → log
    → notify Slack/mail
    ↓
HTTP 500 response
```

Integration events are dispatched AFTER commit succeeds, so they are never sent for failed operations.

## Performance: upsert atomicity

`upsert()` is atomic at the database level — either the INSERT happens or the UPDATE happens, never both, never neither. This makes it safe for:

- Single-flight deduplication (process one incoming API call atomically)
- Idempotent job retries (the job can be processed twice without duplicating side effects)
- Race-condition-safe counters

A query + update is not atomic and opens a race window.

## Testing with a database fake

For unit tests, create an in-memory fake:

```php
<?php declare(strict_types=1);
namespace Tests\Fixtures;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;

final class InMemoryDatabase implements DatabasePort
{
    private array $tables = [];

    public function query(string $sql, array $params = []): array
    {
        // Parse and execute mock SQL...
    }

    public function queryOne(string $sql, array $params = []): ?array
    {
        $rows = $this->query($sql, $params);
        return $rows[0] ?? null;
    }

    public function execute(string $sql, array $params = []): int
    {
        // Track affected rows...
    }

    public function upsert(string $table, array $values, array $conflictColumns, ?array $updateColumns = null): int
    {
        // Upsert logic...
    }

    // ... etc
}
```

Use the fake for service tests so tests run fast and need no database setup.

## Migration support

Use [LetMigrate](/packages/let-migrate) for database schema management. It is framework-agnostic, supports multiple databases, and compiles portable SQL:

```php
// In a migration
$blueprint->createTable('invoices', function (Blueprint $table) {
    $table->string('id', 26)->primary();
    $table->string('tenant_id', 26);
    $table->decimal('total', 10, 2);  // integer cents
    $table->enum('status', ['draft', 'issued', 'paid']);
    $table->timestamps();  // created_at, updated_at
    $table->softDeletes();

    $table->index(['tenant_id', 'status']);
});
```

The `Blueprint` API compiles to correct CREATE TABLE syntax per database.

## Data access patterns

See [Data Access](/layers/data-access) for repository and hydrator patterns.

## Common mistakes

::: warning

**Never mutate a connection pool or port after boot.** The core container is frozen after materialization, and modifying a connection string would affect every pending request. If a tenant needs a different database, rebind in the module's `register()` method (request-scoped), not in bootstrap.

**Never use `getenv()` or putenv()` for configuration.** These are shared globals unsafe under OpenSwoole. Use the `env()` helper (reads `$_ENV`/`$_SERVER`) or the `config()` helper (compiled at boot).

**Never store a `DatabasePort` reference in a static.** It leaks across requests under OpenSwoole. Inject it into each service instance.

:::

## Source

- [DatabasePort](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Ports/DatabasePort.php)
- [TransactionManager](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Database/TransactionManager.php)
- [LetMigrate](https://github.com/AlfaCode-Team/let-migrate)
