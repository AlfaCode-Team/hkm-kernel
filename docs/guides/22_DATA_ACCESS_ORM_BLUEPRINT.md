# Data Access & "ORM" Blueprint — GDA-Compliant Persistence

> This is the blueprint for HOW data access is built in the AlfacodeTeam
> PhpServicePlatform framework. It is **not** a third-party ORM adoption guide —
> Eloquent, Doctrine, and Propel are explicitly forbidden (see [13_ANTIPATTERNS.md](13_ANTIPATTERNS.md)). This
> document defines the *layered, hand-rolled object-relational mapping* that the
> framework uses instead, and the boundaries every persistence type must respect.

---

## TL;DR — what to use, where

| Concern | Use | Never use |
|---|---|---|
| Schema / DDL | **LetMigrate** `Blueprint` migrations | Eloquent/Doctrine migrations |
| Query execution | **`DatabasePort`** (`query`/`queryOne`/`execute`) | a raw `PDO`/vendor handle in a repository |
| Row → object | a **Hydrator** (static, pure) | Active Record / lazy proxies |
| Object → row | the **Repository** (explicit column mapping) | `$model->save()` magic |
| Dynamic SELECTs | a small **QueryBuilder VO** (optional, see below) | string concatenation of user input |
| Identity / tenancy scoping | `Identity.tenantId` injected into the repository | global scopes / framework middleware magic |
| Transactions + events | `TransactionManager` + `DomainEventCollector` | implicit per-model transactions |

The "ORM" here is the **Repository + Hydrator + Domain Entity** triad. There is
no unit-of-work, no identity map, no lazy loading, and no Active Record. Mapping
is explicit and one-directional at each boundary.

---

## The four layers of the mapping

```
        ┌──────────────────────────────────────────────┐
        │  Domain Entity (Domain/Entities/*)            │  ← behaviour + invariants
        │  private ctor · named ctors · releaseEvents() │
        └──────────────▲───────────────────┬────────────┘
                       │ reconstitute(...)  │ toRow-ish (read by repo)
        ┌──────────────┴───────────────────▼────────────┐
        │  Hydrator (Infrastructure/Persistence/*Hydrator)│ ← row[] ⇄ entity, PURE
        └──────────────▲───────────────────┬────────────┘
                       │ array<string,mixed> rows        │
        ┌──────────────┴───────────────────▼────────────┐
        │  Repository (Infrastructure/Persistence/*)     │ ← SQL + DatabasePort ONLY
        └──────────────▲───────────────────┬────────────┘
                       │ query/execute      │
        ┌──────────────┴───────────────────▼────────────┐
        │  DatabasePort (Kernel\Ports\DatabasePort)      │ ← the only DB seam
        │  adapter: plugins/Database MultiDriverAdapter   │
        └────────────────────────────────────────────────┘
```

Rules (these are the GDA Five Access Rules applied to persistence):

- A **Repository** depends on `DatabasePort` ONLY — never an HTTP client, vendor
  SDK, or another module's repository. It translates every `\PDOException`
  (already wrapped as `ConnectionException` by the Database plugin) into a
  `RepositoryException` so no vendor type escapes the layer.
- A **Hydrator** is a `final` class of `static` pure functions. It imports only
  Domain types. No DB handle, no container, no I/O.
- A **Domain Entity** has a `private` constructor, a `reconstitute()` named
  constructor used ONLY by the hydrator (records NO events), and `create()`-style
  named constructors that DO record domain events.
- Persistence NEVER lives in the Domain layer. Entities don't know they're stored.

---

## 1. Migration (LetMigrate) — the schema is the source of truth

```php
return new class implements MigrationInterface {
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('invoices', static function ($t) {
            $t->char('invoice_id', 31);
            $t->char('tenant_id', 31);
            $t->integer('amount_cents');         // money is integer cents — never float/decimal-as-float
            $t->char('currency', 3);
            $t->string('status', 20);
            $t->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $t->timestamp('deleted_at')->nullable();

            $t->unique(['invoice_id'], 'uniq_invoice_id');
            $t->index(['tenant_id', 'status'], 'idx_tenant_status');   // every tenant-scoped query keys on tenant_id
        });
    }
    public function down(SchemaBuilderInterface $schema): void { $schema->dropIfExists('invoices'); }
};
```

Write the migration ONCE with the fluent `Blueprint`; LetMigrate compiles
correct DDL per driver (MySQL/PostgreSQL/SQLite/SQL Server). Always pair `up()`
with a real `down()`. Index every column a repository filters on — especially
`tenant_id`.

---

## 2. Hydrator — row ⇄ entity, pure and explicit

```php
final class InvoiceHydrator
{
    /** @param array<string,mixed> $row */
    public static function hydrate(array $row): Invoice
    {
        return Invoice::reconstitute(
            id:      InvoiceId::from((string) $row['invoice_id']),
            tenant:  (string) $row['tenant_id'],
            total:   Money::ofCents((int) $row['amount_cents'], (string) $row['currency']),
            status:  InvoiceStatus::from((string) $row['status']),
        );
    }

    /** Object → the column map the repository binds. Keeps SQL params in one place. */
    public static function toColumns(Invoice $i): array
    {
        return [
            'invoice_id'  => $i->id()->value(),
            'tenant_id'   => $i->tenantId(),
            'amount_cents'=> $i->total()->amount(),   // integer cents
            'currency'    => $i->total()->currency(),
            'status'      => $i->status()->value,
        ];
    }
}
```

The hydrator is the single place that knows column names ⇄ value objects. It is
trivially unit-testable with a literal array.

---

## 3. Repository — DatabasePort only, tenant-scoped, exception-translating

```php
final class InvoiceRepository
{
    public function __construct(
        private readonly DatabasePort $db,        // ONLY external dependency
        private readonly Identity     $identity,  // tenant scope comes from the verified Identity
    ) {}

    public function find(string $id): Invoice
    {
        try {
            $row = $this->db->queryOne(
                'SELECT * FROM invoices
                  WHERE invoice_id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
                ['id' => $id, 'tenant' => $this->identity->tenantId],   // ALWAYS scope by tenant
            );
        } catch (\Throwable $e) {
            throw new RepositoryException("Failed to load invoice [{$id}].",
                layer: 'repository.invoice', context: ['id' => $id], previous: $e);
        }

        return $row !== null
            ? InvoiceHydrator::hydrate($row)
            : throw new RepositoryException("Invoice [{$id}] not found.", layer: 'repository.invoice');
    }

    public function save(Invoice $invoice): void
    {
        $cols = InvoiceHydrator::toColumns($invoice)
            + ['created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')];
        try {
            // Portable upsert — DatabasePort compiles ON DUPLICATE KEY (MySQL) or
            // ON CONFLICT … DO UPDATE (PostgreSQL/SQLite). NEVER hand-write either.
            $this->db->upsert(
                'invoices',
                $cols,
                conflictColumns: ['invoice_id'],
                updateColumns:   ['amount_cents', 'status'],   // created_at left untouched on update
            );
        } catch (\Throwable $e) {
            throw new RepositoryException('Failed to save invoice.', layer: 'repository.invoice', previous: $e);
        }
    }
}
```

Always: bound parameters (never interpolation), `tenant_id` in every clause,
soft-delete with `deleted_at IS NULL`, `\Throwable` → `RepositoryException`, and
the **portable `DatabasePort` API** for anything dialect-specific (see next).

> **Multi-tenant note:** under the Tenancy plugin the `DatabasePort` injected here
> is *already* the per-request tenant connection (rebound by `TenantContextStage`),
> so tenant data isolation is enforced at TWO layers — the physical connection AND
> the `tenant_id` predicate. Control-plane repositories (users, memberships) pin to
> the `ConnectionManager` **default** (central) connection instead.

---

## 3b. Portable DatabasePort API — never hand-write dialect SQL

PDO's *API* and placeholder scheme (`:named`) are identical across MySQL,
PostgreSQL, and SQLite — but the *SQL text* is not (upserts, identifier quoting,
`lastInsertId`, string concat). The `DatabasePort` absorbs the constructs that
actually differ so repository code stays driver-agnostic:

| Need | Use | Don't write |
|---|---|---|
| Insert-or-update | `$db->upsert($table, $values, $conflictColumns, $updateColumns)` | `ON DUPLICATE KEY UPDATE` / `ON CONFLICT …` by hand |
| Last insert id | `$db->lastInsertId($sequence = null)` — pass the sequence name on Postgres | `lastInsertId()` assuming MySQL semantics |
| Plain reads/writes | `query` / `queryOne` / `execute` with `:named` params | positional `?` (works, but keep one scheme) |

`upsert()` semantics:
- `$conflictColumns` — the unique/PK columns that define a collision (must have a
  matching unique constraint in the migration).
- `$updateColumns = null` → overwrite every non-conflict column. `[]` → do
  nothing on conflict (insert-if-absent). A subset → overwrite only those (e.g.
  refresh `role`/`updated_at` but preserve the original `joined_at`).
- Compiles to `INSERT … ON DUPLICATE KEY UPDATE col = VALUES(col)` on MySQL and
  `INSERT … ON CONFLICT (cols) DO UPDATE SET col = EXCLUDED.col` on
  PostgreSQL/SQLite. One call, atomic, no UPDATE-then-INSERT race.

Constructs the port does NOT abstract (keep to the portable subset, or branch on
`$db->driver()` in the rare case you must): string concatenation (`CONCAT` vs
`||`), `bytea`/BLOB stream handling, and vendor-specific functions.

## 4. Optional QueryBuilder — only for genuinely dynamic SELECTs

Most repositories are fine with literal SQL strings. Reach for a builder ONLY
when filters/sorts are composed at runtime (search, list endpoints). Keep it a
small, immutable value object that emits `[sql, params]` — never a fluent
Active-Record-style chain that executes itself.

```php
$qb = QueryBuilder::select('invoices')
    ->whereEquals('tenant_id', $this->identity->tenantId)   // forced scope
    ->whereNull('deleted_at')
    ->when($status !== null, fn($q) => $q->whereEquals('status', $status))
    ->orderBy('created_at', 'desc')
    ->limit($perPage)->offset($offset);

[$sql, $params] = $qb->compile();
$rows = $this->db->query($sql, $params);
return array_map(InvoiceHydrator::hydrate(...), $rows);
```

Builder rules:
- It produces SQL + bound params; it does **not** hold a `DatabasePort` and does
  **not** execute. The repository executes.
- Column / table / direction identifiers come from a **whitelist**, never from
  raw request input — only *values* are bound.
- It is immutable (each method returns a new instance) for Swoole safety.
- It is NOT a public contract. It lives in `Infrastructure/Persistence/` and is
  an implementation detail of repositories.

---

## What this blueprint deliberately rejects

```
✗ Active Record ($invoice->save()) — entities never touch the DB
✗ Lazy loading / proxies / N+1 — repositories fetch explicitly
✗ Identity map / unit of work — TransactionManager owns the boundary instead
✗ Global query scopes resolved from a container/middleware — scope is an explicit param
✗ Annotations/attributes mapping classes to tables — the Hydrator is the map
✗ A fluent builder that executes itself — builder emits SQL, repo runs it
✗ Vendor exceptions escaping the repository — translate to RepositoryException
✗ float for money — integer cents in column + Money VO
✗ Skipping tenant_id in a tenant-scoped query — isolation is mandatory
```

---

## Migration path if a real ORM is ever needed

If the project later needs richer mapping, the seam to extend is the **Hydrator
+ Repository pair**, behind the existing published `*ServiceContract`. Because
services depend on contracts (never on a concrete repository or a `PDO` handle),
a future mapper can be swapped in without touching the Domain or Application
layers. Do not introduce a vendor ORM that bypasses `DatabasePort` — it would
break tenant connection rebinding, the exception-translation contract, and
Swoole request isolation.

---

## Related context

- `docs/guides/05_REPOSITORY.md`   — repository layer rules in detail
- `docs/guides/18_MIGRATIONS.md`    — LetMigrate engine + patterns
- `docs/guides/19_DATABASE.md`      — multi-driver Database module + DatabasePort adapter
- `docs/guides/03_DOMAIN.md`        — entity / value object / reconstitute() patterns
