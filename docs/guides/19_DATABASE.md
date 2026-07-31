# 19 — DATABASE MODULE (Multi-Driver Persistence)

> Enterprise multi-driver implementation of the kernel `DatabasePort`.
> Lives in `plugins/Database/` under the `Plugins\Database\` namespace.
> Solves the `database.management` domain.

---

## WHAT THIS MODULE IS

The Database module is the **single concrete implementation** of the kernel
`DatabasePort` interface. The kernel defines the port; this module provides a
production-grade adapter that speaks to four database engines through PDO:

| Engine | Driver key | DSN prefix |
|---|---|---|
| MySQL / MariaDB | `mysql` | `mysql:` |
| PostgreSQL | `pgsql` | `pgsql:` |
| SQLite (file or `:memory:`) | `sqlite` | `sqlite:` |
| SQL Server | `sqlsrv` | `sqlsrv:` |

Repositories depend on `DatabasePort` only. They never import a driver class or
the adapter — driver selection is an infrastructure concern resolved at boot from
`DB_*` environment variables.

```
Repository ──> DatabasePort (kernel interface)
                    ▲
                    │  bound by Plugins\Database\Provider
                    │
        MultiDriverDatabaseAdapter ──> PDO ──> {MySQL|PostgreSQL|SQLite|SQL Server}
```

---

## FOLDER STRUCTURE

```
plugins/Database/
├── module.json                              ← solves database.management, declares DB_* config
├── Provider.php                             ← wiring only: factory → adapter → DatabasePort
├── API/
│   └── Contracts/
│       ├── DatabaseConfigurationContract.php       ← driver(), dsn(), pdoOptions(), initStatements()
│       └── DatabaseConnectionManagerContract.php   ← named multi-connection registry
├── Infrastructure/
│   ├── Drivers/
│   │   ├── DatabaseConfigurationFactory.php  ← alias resolution + per-driver defaults
│   │   ├── MySQLConfiguration.php
│   │   ├── PostgreSQLConfiguration.php
│   │   ├── SQLiteConfiguration.php
│   │   └── SqlServerConfiguration.php
│   ├── Persistence/
│   │   ├── MultiDriverDatabaseAdapter.php    ← DatabasePort implementation (direct)
│   │   ├── PooledDatabaseAdapter.php         ← DatabasePort implementation (pool-backed, request-scoped)
│   │   ├── ConnectionManager.php             ← DatabaseConnectionManagerContract implementation
│   │   └── SavepointGrammar.php              ← driver-correct nested-transaction SQL
│   └── Pool/
│       ├── ConnectionPool.php                ← per-worker pool: warmup, validate, evict, stats
│       ├── PoolConfiguration.php             ← min/max/timeouts/validate (DB_POOL_*)
│       └── PooledConnection.php              ← slot wrapper (lifetime + idle bookkeeping)
└── Exceptions/
    └── ConnectionException.php               ← the only exception that escapes the module
```

---

## THE FIVE ENTERPRISE BEHAVIOURS

### 1. Lazy connection
The adapter does **not** open a socket in its constructor. PDO is created on the
first query (or explicit `pdo()` / `ping()` call). Booting a module that never
touches the database costs nothing — consistent with GDA "load only what is needed".

```php
$db = new MultiDriverDatabaseAdapter($config);
$db->isConnected();   // false — no socket yet
$db->query('SELECT 1');
$db->isConnected();   // true
```

### 2. Nested transactions via savepoints
`beginTransaction()` / `commit()` / `rollback()` **nest**. Only the outermost
level drives the real transaction; inner levels use `SAVEPOINT` so a partial
rollback does not abandon the whole unit of work. `SavepointGrammar` emits the
correct dialect (`SAVEPOINT` / `RELEASE` / `ROLLBACK TO` for standard SQL;
`SAVE TRANSACTION` / `ROLLBACK TRANSACTION` for SQL Server).

```php
$db->transaction(function (MultiDriverDatabaseAdapter $db) {
    $db->execute('INSERT ...');           // outer
    $db->transaction(fn ($db) =>          // inner — savepoint
        $db->execute('INSERT ...'));
});                                        // single real COMMIT
```

`transaction(callable)` commits on success and rolls back on **any** throwable,
re-throwing the original exception. This is the preferred entry point for service
code that already wraps work in `TransactionManager`.

### 3. Auto-reconnect
Long-running Swoole workers keep connections for hours. When a statement fails
with a "server has gone away" class error **and no transaction is active**, the
adapter transparently reconnects and retries the statement once. Inside a
transaction it does not retry (the transaction is already invalid) — it surfaces
the error so the caller rolls back.

### 4. Post-connect init statements
Each driver returns `initStatements()` run immediately after connecting:

| Driver | Statements | Why |
|---|---|---|
| SQLite | `PRAGMA foreign_keys = ON`, `busy_timeout = 5000`, `journal_mode = WAL`* | FK enforcement is **off by default** in SQLite |
| MySQL | `SET SESSION sql_mode = 'STRICT_ALL_TABLES,…'` | fail on truncation/coercion instead of silent corruption |
| SQL Server | `SET XACT_ABORT ON` | whole-transaction rollback on any runtime error |
| PostgreSQL | — | strict + FK-enforcing by default |

\* WAL is skipped for `:memory:`.

### 5. Query observability
Inject an optional PSR-3 `LoggerInterface`. Every statement is timed:
- `logQueries = true` → each query logged at **debug**.
- Any query slower than `slowQueryThresholdMs` (default 200ms) → logged at
  **warning**, regardless of the debug flag.

Set `DB_ENABLE_QUERY_LOG=true` to turn on debug logging through the Provider.

---

## CONFIGURATION (ENV-DRIVEN)

`DatabaseConfigurationFactory::fromEnvironment()` reads:

| Variable | Applies to | Default |
|---|---|---|
| `DB_DRIVER` | all (aliases: `mariadb`, `postgres`, `mssql`, `sqlserver`, …) | `sqlite` |
| `DB_HOST` | mysql, pgsql, sqlsrv | driver default |
| `DB_PORT` | mysql, pgsql, sqlsrv | 3306 / 5432 / 1433 |
| `DB_DATABASE` | all (SQLite: file path or `:memory:`) | `:memory:` |
| `DB_USERNAME` / `DB_PASSWORD` | mysql, pgsql, sqlsrv | driver default |
| `DB_CHARSET` | mysql | `utf8mb4` |
| `DB_SSL_MODE` | pgsql (`disable`…`verify-full`) | `prefer` |
| `DB_SSL_VERIFY` / `DB_SSL_CA` | mysql | off |
| `DB_UNIX_SOCKET` | mysql, pgsql | — |
| `DB_ENCRYPT` / `DB_TRUST_SERVER_CERT` | sqlsrv | off |
| `DB_ENABLE_QUERY_LOG` | observability | off |

Every variable is declared in `module.json` `config[]` — an undeclared variable
read by the module fails boot (GDA rule).

---

## WIRING

`Provider::register()` performs wiring only — no business logic:

```php
$container->singleton(DatabaseConfigurationContract::class, fn () =>
    (new DatabaseConfigurationFactory())->fromEnvironment());

$container->bind(DatabasePort::class, fn ($c) =>
    new MultiDriverDatabaseAdapter(
        config:     $c->make(DatabaseConfigurationContract::class),
        logger:     /* optional PSR-3 */,
        logQueries: env('DB_ENABLE_QUERY_LOG') === 'true',   // env() — never getenv() for .env values
    ));

$container->singleton(DatabaseConnectionManagerContract::class, /* registry */);
```

The module is registered in `app/bootstrap/base.php`:

```php
->withModules([
    Plugins\Database\Provider::class,
    Plugins\Commands\Provider::class,
]);
```

---

## CONNECTION POOLING (OPT-IN, PER WORKER)

Under OpenSwoole the kernel boots **once per worker** and handles many requests
on that long-lived process. Reconnecting to the database on every request wastes
the TCP/TLS handshake. The pool keeps a bounded set of warm connections and lends
one per request.

### Topology

```
Worker process (app-lifetime)
└── ConnectionPool                      ← ONE per worker, bound via withPorts (CoreContainer)
        ├── idle:    [conn, conn, …]    ← warm, ready to lend
        └── borrowed:{conn, …}          ← currently checked out

Request (request-scoped)
└── PooledDatabaseAdapter (DatabasePort)
        └── pins ONE borrowed connection for the whole request,
            returns it to the pool on teardown
```

`PooledDatabaseAdapter` pins a single connection per request so `lastInsertId()`
and multi-statement transactions stay correct, then `release()`s it on teardown
(`__destruct` is the safety net). Because each request gets its own adapter and
(by default) requests run sequentially per worker, no per-coroutine keying is
needed; when `SWOOLE_COROUTINE=true`, `acquire()` yields the scheduler while
waiting for a free slot.

### Enabling it

Set `DB_POOL_ENABLED=true`. The bootstrap (`app/bootstrap/base.php`) builds one
`ConnectionPool` per worker and registers it app-lifetime via `withPorts`; the
module's `Provider` then binds `DatabasePort` to a request-scoped
`PooledDatabaseAdapter`. If no app-lifetime pool is present the Provider falls
back to a container-singleton pool, so the pooled path also works in tests/CLI.

### Tuning (`DB_POOL_*`)

| Variable | Default | Meaning |
|---|---|---|
| `DB_POOL_ENABLED` | `false` | Master switch for the pooled DatabasePort |
| `DB_POOL_MIN` | `0` | Connections opened at warm-up and kept hot |
| `DB_POOL_MAX` (alias `DB_POOL_SIZE`) | `10` | Hard ceiling on connections per worker |
| `DB_POOL_ACQUIRE_TIMEOUT_MS` | `3000` | Wait before `poolExhausted` when saturated |
| `DB_POOL_IDLE_TIMEOUT` | `60` | Evict a connection idle longer than this (s) |
| `DB_POOL_MAX_LIFETIME` | `3600` | Recycle a connection older than this (s) |
| `DB_POOL_VALIDATE` | `true` | `ping()` a reused connection before lending |

A connection that is stale (past idle/lifetime) or fails validation is closed
deterministically (`MultiDriverDatabaseAdapter::close()`) and replaced. A
connection returned mid-transaction is rolled back before re-entering the pool.

### Observability

`ConnectionPool::stats()` returns `idle`, `active`, `total`, `max`, `min`,
`waiters`, `closed` — wire it into a health endpoint to watch saturation.

Sizing rule of thumb: `DB_POOL_MAX × worker_count` must stay under the database
server's `max_connections`.

---

## MULTI-DATABASE (READ REPLICAS / WAREHOUSE)

`ConnectionManager` implements `DatabaseConnectionManagerContract` for setups
needing more than one connection. Connections are built lazily and cached:

```php
$manager->register('primary', $primaryConfig);
$manager->register('replica', $replicaConfig);

$manager->connection('replica')->query('SELECT ...');   // reads
$manager->default()->execute('INSERT ...');             // writes
$manager->close('replica');                             // drop one
```

---

## ERROR HANDLING

Every `\PDOException` is translated to `Plugins\Database\Exceptions\ConnectionException`
— no vendor exception escapes the module (GDA gateway/repository rule). It carries
structured context for the kernel `ErrorPipeline`:

```php
try {
    $db->query($sql);
} catch (ConnectionException $e) {
    $e->driver;     // 'mysql' | 'pgsql' | 'sqlite' | 'sqlsrv'
    $e->operation;  // 'connect' | 'query' | 'execute' | 'transaction.commit' | …
    $e->getPrevious(); // original \PDOException
}
```

Repositories should catch `ConnectionException` and re-throw a `RepositoryException`
(per [05_REPOSITORY.md](05_REPOSITORY.md)).

---

## TESTING

The module ships a full unit suite under `tests/Unit/Database/` (85 tests). It uses
**SQLite `:memory:`** as a real connection — no mocking of PDO, so transaction and
savepoint behaviour is genuinely exercised:

```bash
vendor/bin/phpunit tests/Unit/Database
```

Test coverage:
- `Drivers/*ConfigurationTest` — DSN, PDO options, init statements, password redaction
- `Drivers/DatabaseConfigurationFactoryTest` — alias resolution, env parsing, unknown driver
- `Persistence/MultiDriverDatabaseAdapterTest` — CRUD, nested tx/savepoints, `transaction()`, error translation, lazy connect
- `Persistence/ConnectionManagerTest` — named connection registry lifecycle
- `Persistence/QueryLoggingTest` — debug + slow-query logging
- `Exceptions/ConnectionExceptionTest` — structured context

For repository/service tests, prefer the in-memory adapter or a `DatabasePort`
fake (see [10_TESTING.md](10_TESTING.md)).

---

## CROSS-DRIVER PORTABILITY (UNIFORM API)

PDO's API and the `:named` placeholder scheme are identical across MySQL,
PostgreSQL and SQLite — but the SQL *text* is not. `DatabasePort` absorbs the
constructs that genuinely differ so repositories never branch on the driver:

| Need | Use | Never hand-write |
|---|---|---|
| Insert-or-update | `$db->upsert($table, $values, $conflictColumns, $updateColumns)` | `ON DUPLICATE KEY UPDATE` / `ON CONFLICT …` |
| Last insert id | `$db->lastInsertId($sequence = null)` — pass the sequence name on PostgreSQL | `lastInsertId()` assuming MySQL semantics |

`upsert()` compiles to `INSERT … ON DUPLICATE KEY UPDATE col = VALUES(col)` on
MySQL and `INSERT … ON CONFLICT (cols) DO UPDATE SET col = EXCLUDED.col` on
PostgreSQL/SQLite, quoting identifiers per driver. `$conflictColumns` must have a
matching unique/PK constraint. `$updateColumns`: `null` = all non-conflict
columns, `[]` = do nothing on conflict (insert-if-absent), a subset = only those
(e.g. refresh `role`/`updated_at` but preserve the original `joined_at`). It is
atomic — no UPDATE-then-INSERT race.

Constructs the port does NOT abstract (keep to the portable subset, or branch on
`$db->driver()` in the rare case you must): string concatenation (`CONCAT` vs
`||`), `SUBSTRING`/`substr`, `bytea`/BLOB streams, and vendor functions. Prefer
computing such values in PHP and binding the result. Full guidance:
[22_DATA_ACCESS_ORM_BLUEPRINT.md](22_DATA_ACCESS_ORM_BLUEPRINT.md).

## RULES — WHAT NOT TO DO

```
✗ Hand-writing ON DUPLICATE KEY / ON CONFLICT — use $db->upsert() (driver-portable)
✗ Importing a driver/adapter class in a repository — depend on DatabasePort only
✗ Reading DB_* env vars anywhere but DatabaseConfigurationFactory
✗ Letting a \PDOException escape the module — always ConnectionException
✗ Putting business logic in Provider — wiring only
✗ float for money columns — integer cents (see Domain/ValueObjects rules)
✗ Opening the connection eagerly in a constructor — it is lazy by design
✗ Catching ConnectionException and swallowing it — translate to RepositoryException
✗ Adding a 5th driver without an initStatements() review and a config test
✗ Making PooledDatabaseAdapter app-lifetime — it MUST be request-scoped (per-request pin)
✗ Making ConnectionPool request-scoped — it MUST be app-lifetime (one per worker)
✗ Holding a borrowed connection across requests without release() — starves the pool
✗ Setting DB_POOL_MAX × workers above the server's max_connections
```

---

## RELATED CONTEXT

- [05_REPOSITORY.md](05_REPOSITORY.md) — repository layer rules (DatabasePort only)
- [18_MIGRATIONS.md](18_MIGRATIONS.md) — LetMigrate uses the same `DB_*` variables
- [16_PLUGINS.md](16_PLUGINS.md) — plugins folder convention
- [10_TESTING.md](10_TESTING.md) — port fakes and service tests
```
