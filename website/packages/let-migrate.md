# let-migrate — Enterprise Database Migrations

The `alfacode-team/let-migrate` package is an enterprise-grade, framework-independent database migration engine supporting MySQL, PostgreSQL, SQLite, and SQL Server. It handles schema versioning, transactions, rollbacks, and lifecycle events — all without depending on any framework beyond PSR-3 logging.

## Supported databases

| Database | Support level | Notes |
|---|---|---|
| MySQL / MariaDB | Full (8.0+) | Native DDL dialect |
| PostgreSQL | Full (12+) | BEFORE UPDATE triggers for `onUpdateCurrentTimestamp()` |
| SQLite | Full (3.37+) | In-memory or file-based |
| SQL Server | Full (2016+) | T-SQL grammar |

Every database is tested against a real server; SQL compilation is verified by execution, not just inspection.

## Why let-migrate?

- **Single code, multiple databases**: write migrations once using the fluent `Blueprint` API; the engine compiles to correct dialect DDL for each driver
- **Per-driver migration folders**: keep MySQL-specific and PostgreSQL-specific schema changes neatly separated
- **Batched runs and rollbacks**: each call to `run()` creates a batch; `rollback(steps: 2)` reverses the last 2 batches
- **Full transactions**: every migration runs inside its own transaction by default; failures auto-rollback with zero data loss
- **Lifecycle events**: hook into `MigrationStarted`, `MigrationFinished`, `MigrationFailed`, `MigrationsCompleted` for progress bars, logging, CI/CD integration
- **Portable to any framework**: zero framework dependencies (only `psr/log` and PHP 8.2+)
- **Pretend mode**: compile and log SQL without executing — safe for CI previews
- **Extensible**: register custom drivers and grammars to support additional databases
- **Dependent migrations**: declare inter-migration dependencies; the runner topologically sorts pending migrations
- **Transactionless migrations**: opt individual migrations out of transactions for operations like `CREATE INDEX CONCURRENTLY`
- **Schema introspection**: inspect the live database schema and generate migrations from differences
- **Migration breakpoints**: halt rollback at a specific migration (Phinx-style production safety)
- **Squashing and dumping**: compress historical migrations into a single baseline schema dump

::: danger Do NOT use Laravel/Symfony migrations

If you are familiar with Laravel Eloquent migrations or Symfony/Doctrine migrations, **do not use those here**. They do not compile to correct SQL for all four drivers, and their schema builders are tightly coupled to their respective frameworks. Use LetMigrate's `MigrationInterface` and `Blueprint` API instead.

:::

## Core workflow

```php
use AlfaCode\LetMigrate\LetMigrate;

// Bootstrap the engine
$engine = LetMigrate::configure([
    'driver'   => 'mysql',
    'host'     => 'localhost',
    'port'     => 3306,
    'database' => 'app_db',
    'username' => 'root',
    'password' => getenv('DB_PASSWORD'),
    'paths'    => [
        __DIR__ . '/database/migrations/mysql',
        __DIR__ . '/database/migrations/shared',
    ],
]);

// Run all pending migrations
$result = $engine->run();
echo $result->summary();  // "3 migration(s) applied in batch 1."

// Rollback the last batch
$result = $engine->rollback();
echo $result->summary();  // "3 migration(s) rolled back."

// Rollback multiple batches
$result = $engine->rollback(steps: 2);

// Get migration status
$status = $engine->status();
foreach ($status as $name => $info) {
    echo "{$name}: {$info['status']}\n";  // 'applied' or 'pending'
}
```

## Writing migrations

Every migration file returns an anonymous class (or a named class) implementing `MigrationInterface`:

```php
<?php
// database/migrations/mysql/2024_01_15_000001_create_users_table.php

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;
use AlfaCode\LetMigrate\Schema\Blueprint;

return new class implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('users', static function (Blueprint $t): void {
            $t->id();                                    // Unsigned BIGINT primary key
            $t->string('email', 191)->unique();          // VARCHAR + UNIQUE constraint
            $t->string('password');
            $t->boolean('is_active')->default(true);    // BOOLEAN or TINYINT
            $t->timestamps();                            // created_at + updated_at
            $t->softDeletes();                           // deleted_at
            
            $t->index(['email'], 'idx_email');           // Named index
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('users');
    }
};
```

### Filename convention

```
YYYY_MM_DD_NNNNNN_description.php
```

Examples:
- `2024_01_15_000001_create_users_table.php`
- `2024_02_01_000002_add_verified_at_to_users.php`
- `2024_02_15_000003_create_invoices_table.php`

**Batch number must be unique per timestamp.** LetMigrate tracks batches by when they run, not which file was first — so the batch number is only to make filenames unique within one day.

## Configuration

LetMigrate reads from a config array (passed to `LetMigrate::configure()` or a config file):

```php
[
    // ── Database connection ────────────────────────────────────
    'driver'   => 'mysql',           // mysql | pgsql | sqlite | sqlsrv
    'host'     => '127.0.0.1',
    'port'     => 3306,
    'database' => 'app_db',
    'username' => 'root',
    'password' => 'secret',
    
    // ── Migration paths ────────────────────────────────────────
    'paths'    => [
        __DIR__ . '/database/migrations/mysql',
        __DIR__ . '/database/migrations/shared',
    ],
    
    // ── Tracking table ────────────────────────────────────────
    'tracking_table' => 'let_migrations',  // Default
    
    // ── Transaction behavior ──────────────────────────────────
    'transactional'   => true,   // Each migration in BEGIN/COMMIT
    'all_or_nothing'  => false,  // Entire batch in one transaction (MySQL DDL will auto-commit)
    
    // ── Pretend mode ───────────────────────────────────────────
    'pretend' => false,          // Log SQL without executing
    
    // ── Scoped runs ────────────────────────────────────────────
    'ignore_missing' => false,   // Skip missing files on rollback (safe for scoped runs)
    
    // ── Seeders ────────────────────────────────────────────────
    'seeders_path'  => __DIR__ . '/database/seeders',  // Optional
    'seeders_table' => 'let_seeders',                   // Tracks applied seeders
]
```

### Key descriptions

| Key | Default | Type | Purpose |
|---|---|---|---|
| `driver` | `'mysql'` | string | Database driver: `'mysql'`, `'pgsql'`, `'sqlite'`, `'sqlsrv'` |
| `paths` | `[]` | array | Array of absolute paths to migration directories. At least one is required. |
| `tracking_table` | `'let_migrations'` | string | Name of the table tracking applied migrations |
| `transactional` | `true` | bool | When true, each migration runs in BEGIN/COMMIT. Set false for non-transactional DDL (SQLite without PRAGMA). |
| `all_or_nothing` | `false` | bool | When true, the entire pending batch runs in one transaction. **MySQL DDL will auto-commit.** |
| `pretend` | `false` | bool | When true, SQL is logged but never executed (dry-run mode). |
| `ignore_missing` | `false` | bool | When true, a scoped run (narrow paths) can rollback its migrations without erroring on missing files elsewhere. |
| `seeders_path` | `null` | string &#124; null | Absolute path to seeder files. When null, seeding is disabled. |
| `seeders_table` | `'let_seeders'` | string | Name of the table tracking applied seeders |

## Schema Builder API

The `SchemaBuilder` interface is passed to your `up()` and `down()` methods. It provides methods to create, modify, and drop tables.

### Table operations

```php
// Create a table
$schema->create('invoices', function (Blueprint $t) {
    $t->id();
    $t->string('number')->unique();
    $t->unsignedInteger('customer_id');
    $t->decimal('total', 10, 2);  // decimal(10, 2) — precision, scale
    $t->timestamps();
});

// Modify (alter) an existing table
$schema->table('invoices', function (Blueprint $t) {
    $t->string('reference')->nullable();
    $t->index(['number']);
});

// Rename a table
$schema->rename('users', 'app_users');

// Check table/column existence
$schema->hasTable('invoices');        // bool
$schema->hasColumn('users', 'email'); // bool

// Drop a table
$schema->drop('invoices');
$schema->dropIfExists('invoices');  // Safe if it doesn't exist

// Foreign key checks (for dropping related tables)
$schema->disableForeignKeyChecks();
$schema->drop('users');
$schema->enableForeignKeyChecks();

// Raw SQL (use sparingly)
$schema->raw('COMMENT ON TABLE users IS "User accounts"');
```

### Column types

All column methods are called on the Blueprint (`$t`), passing the column name as the first argument:

| Method | SQL | Notes |
|---|---|---|
| `$t->id()` | UNSIGNED BIGINT PRIMARY KEY | Auto-increment, default name `'id'` |
| `$t->bigIncrements()` | same as `id()` | Alias kept so migrations ported from Laravel compile unchanged |
| `$t->uuid()` | UUID or CHAR(36) | Database-native UUID or string storage |
| `$t->string(len)` | VARCHAR(len) | Default `len`: 255 |
| `$t->char(len)` | CHAR(len) | Fixed-width string |
| `$t->text()` | TEXT | Unbounded text |
| `$t->tinyText()` | TINYTEXT | Tiny text |
| `$t->mediumText()` | MEDIUMTEXT | Medium text |
| `$t->longText()` | LONGTEXT | Large text |
| `$t->integer()` | INT | Signed integer |
| `$t->unsignedInteger()` | UNSIGNED INT | Unsigned integer |
| `$t->tinyInteger()` | TINYINT | Tiny signed integer |
| `$t->unsignedTinyInteger()` | UNSIGNED TINYINT | Tiny unsigned integer |
| `$t->smallInteger()` | SMALLINT | Small signed integer |
| `$t->unsignedSmallInteger()` | UNSIGNED SMALLINT | Small unsigned integer |
| `$t->mediumInteger()` | MEDIUMINT | Medium signed integer |
| `$t->unsignedMediumInteger()` | UNSIGNED MEDIUMINT | Medium unsigned integer |
| `$t->bigInteger()` | BIGINT | Large signed integer |
| `$t->unsignedBigInteger()` | UNSIGNED BIGINT | Large unsigned integer |
| `$t->boolean()` | BOOLEAN or TINYINT | Driver-dependent |
| `$t->decimal(precision, scale)` | DECIMAL(p,s) | e.g. `(10, 2)` for currency |
| `$t->float()` | FLOAT | Floating point |
| `$t->double()` | DOUBLE | Double precision floating point |
| `$t->date()` | DATE | Date only |
| `$t->dateTime()` | DATETIME | Date and time |
| `$t->time()` | TIME | Time only |
| `$t->timestamp()` | TIMESTAMP | Timestamp with timezone metadata |
| `$t->year()` | YEAR | Year (MySQL) or SMALLINT (others) |
| `$t->json()` | JSON | JSON column (MySQL, PostgreSQL, SQLite, SQL Server) |
| `$t->binary()` | BLOB or BYTEA | Binary data |
| `$t->enum(values)` | ENUM(values) | MySQL/PostgreSQL enums |
| `$t->set(values)` | SET(values) | MySQL-only set |
| `$t->ipAddress()` | VARCHAR(45) | IPv4/IPv6 string |
| `$t->macAddress()` | VARCHAR(17) | MAC address string |
| `$t->ulid()` | CHAR(26) | ULID (sortable unique ID) |
| `$t->rememberToken()` | VARCHAR(100) NULL | API token column |
| `$t->softDeletes()` | TIMESTAMP NULL | `deleted_at` column for soft-delete pattern |
| `$t->timestamps()` | TIMESTAMP | `created_at` + `updated_at` columns |
| `$t->morphs(name)` | UNSIGNED BIGINT + VARCHAR | Polymorphic FK: `{name}_id` + `{name}_type` |
| `$t->nullableMorphs(name)` | UNSIGNED BIGINT NULL + VARCHAR NULL | Nullable polymorphic FK |
| `$t->uuidMorphs(name)` | UUID + VARCHAR | UUID polymorphic FK |
| `$t->foreignId(name)` | UNSIGNED BIGINT | Convenience for foreign keys |
| `$t->foreignIdFor(Model)` | UNSIGNED BIGINT | FK for an Eloquent model (Laravel compat) |

### Column modifiers

Chain modifiers after a column type to add constraints. All modifiers return `$this` for fluent interface:

| Modifier | Effect |
|---|---|
| `->nullable()` | Allow NULL values (default: NOT NULL) |
| `->notNull()` | Explicitly NOT NULL (default) |
| `->unsigned()` | UNSIGNED (numeric columns only) |
| `->autoIncrement()` | AUTO_INCREMENT (integers only) |
| `->primary()` | Mark as primary key (usually implicit with `id()`) |
| `->unique()` | Add UNIQUE constraint |
| `->default(value)` | Set DEFAULT value (e.g. `->default('guest')`) |
| `->useCurrent()` | DEFAULT CURRENT_TIMESTAMP |
| `->useCurrentOnUpdate()` | Auto-update to current timestamp on every UPDATE. Creates a PostgreSQL trigger if needed. |
| `->comment(text)` | Column comment |
| `->charset(name)` | Character set (MySQL) |
| `->collation(name)` | Collation (MySQL) |
| `->after(columnName)` | Add after another column (MySQL) |
| `->first()` | Add as first column (MySQL) |

### Indexes

```php
// Primary key (usually implicit with id())
$t->primary(['id']);

// Unique constraint
$t->unique(['email']);
$t->unique(['email', 'tenant_id']);  // Composite unique

// Regular index
$t->index(['created_at']);
$t->index(['name', 'status']);       // Composite index

// Named index
$t->index(['email'], 'idx_users_email');

// Full-text index (MySQL + PostgreSQL support via GIN)
$t->fullText(['body']);
$t->fullText(['title', 'description'], 'ft_posts_search');

// Drop index by column(s)
$t->dropIndex(['email']);

// Drop index by name
$t->dropIndex('idx_users_email');
```

### Foreign keys

```php
// Add foreign key
$t->unsignedBigInteger('customer_id');
$t->foreign('customer_id')
    ->references('id')
    ->on('customers')
    ->onDelete('cascade')   // CASCADE | SET NULL | RESTRICT | NO ACTION
    ->onUpdate('cascade');

// Drop foreign key by column
$t->dropForeign('customer_id');

// Drop by column array
$t->dropForeign(['customer_id']);

// Drop by name
$t->dropForeign('invoices_customer_id_foreign');
```

### Modifying existing columns

To change a column's type or constraints in an existing table, use `modifyColumn()`:

```php
$schema->table('users', function (Blueprint $t) {
    $t->modifyColumn('email', function($col) {
        return $col->string(191)->unique();
    });
});
```

The callback receives a fresh `ColumnDefinition` and must return it. Modifying a column is **MySQL-specific** in most ORMs; LetMigrate handles the differences for all four drivers.

### Renaming columns

```php
$schema->table('users', function (Blueprint $t) {
    $t->renameColumn('old_name', 'new_name');
});
```

Supported by all four drivers (SQLite ≥ 3.25.0, MySQL ≥ 8.0, PostgreSQL, SQL Server).

### Dropping columns

```php
$schema->table('users', function (Blueprint $t) {
    $t->dropColumn('old_field');
    $t->dropColumn(['field1', 'field2']);
});
```

### Check constraints

```php
$t->check("age >= 18", 'chk_adult_age');
```

### Table options (MySQL)

```php
$t->engine('InnoDB');          // Table engine
$t->charset('utf8mb4');        // Default charset
$t->collation('utf8mb4_unicode_ci');  // Collation
$t->rowFormat('DYNAMIC');      // Row format
$t->comment('User accounts');  // Table comment
$t->algorithm('INSTANT');      // ALTER algorithm (MySQL 8.0.12+)
$t->lock('DEFAULT');           // ALTER lock
```

## Running migrations

### Service API

Use `MigrationService` (or the `LetMigrate` facade) to run, rollback, and inspect migrations:

```php
use AlfaCode\LetMigrate\LetMigrate;

$engine = LetMigrate::configure($config);

// Run all pending migrations
$result = $engine->run();
// MigrationResult { applied, rolledBack, batch, summary() }

// Rollback the last batch
$result = $engine->rollback();

// Rollback N batches
$result = $engine->rollback(steps: 2);

// Roll back ALL migrations (destructive — dev/test only)
$result = $engine->reset();

// Reset then re-run (destructive — dev/test only)
$result = $engine->refresh();

// Re-run the last N batches
$result = $engine->redo(steps: 1);

// Run up to a specific migration (by filename, no extension)
$result = $engine->service()->migrateTo('2024_02_15_000003_create_invoices_table');

// Get status of every discovered migration
$status = $engine->status();
// Returns: ['filename' => ['status' => 'applied'|'pending', 'batch' => int|null], ...]

// Get pending migrations only
$pending = $engine->pending();
// Returns: ['filename' => MigrationInterface, ...]

// Capture SQL without executing (pretend mode)
$sql = $engine->service()->captureSql(['2024_01_15_000001_create_users_table']);
// Returns: ['filename' => 'SQL statement', ...]

// Install the migrations tracking table
$engine->service()->install();

// Load a schema dump and apply only post-dump migrations (see SchemaDump below)
$engine->service()->installFromDumpIfFresh($dumpPath);
```

### MigrationResult

Returned by `run()`, `rollback()`, `reset()`, `refresh()`, and `redo()`:

```php
$result = $engine->run();

$result->applied;           // string[] filenames that were applied
$result->rolledBack;        // string[] filenames that were rolled back
$result->batch;             // int batch number
$result->appliedCount();    // int
$result->rolledBackCount(); // int
$result->isEmpty();         // bool
$result->summary();         // string e.g. "3 migration(s) applied in batch 1."
```

## Events and lifecycle

LetMigrate fires events as migrations run. Attach listeners to the event dispatcher:

```php
use AlfaCode\LetMigrate\LetMigrate;
use AlfaCode\LetMigrate\Event\{
    MigrationStarted, MigrationFinished, MigrationFailed, MigrationsCompleted
};

$engine = LetMigrate::configure($config);

$dispatcher = $engine->events();

$dispatcher->on(MigrationStarted::class, function (MigrationStarted $e): void {
    echo "Starting: {$e->migration}\n";
    // $e->migration: string filename
    // $e->direction: 'up' or 'down'
});

$dispatcher->on(MigrationFinished::class, function (MigrationFinished $e): void {
    echo "✓ {$e->migration}\n";
    // Properties: $e->migration, $e->direction
});

$dispatcher->on(MigrationFailed::class, function (MigrationFailed $e): void {
    echo "✘ {$e->migration}: {$e->exception->getMessage()}\n";
    // Properties: $e->migration, $e->direction, $e->exception
});

$dispatcher->on(MigrationsCompleted::class, function (MigrationsCompleted $e): void {
    echo "Done. Applied: {$e->result->appliedCount()}\n";
    // Properties: $e->result (MigrationResult)
});

// One-shot listener
$dispatcher->once(MigrationStarted::class, function ($e) {
    echo "First migration starting\n";
});

// Remove all listeners for an event
$dispatcher->off(MigrationStarted::class);

// Remove a specific listener
$dispatcher->off(MigrationStarted::class, $callback);
```

## Advanced features

### Dependent migrations

Declare inter-migration dependencies to enforce run order:

```php
<?php
use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\DependentMigrationInterface;

return new class implements MigrationInterface, DependentMigrationInterface
{
    public function dependsOn(): array
    {
        return [
            '2024_01_01_000001_create_teams_table',
            // Run AFTER this migration, regardless of timestamp order
        ];
    }

    public function up($schema): void
    {
        $schema->create('users', function ($t) {
            $t->id();
            $t->unsignedBigInteger('team_id');
            $t->foreign('team_id')->references('id')->on('teams')->onDelete('cascade');
        });
    }

    public function down($schema): void
    {
        $schema->dropIfExists('users');
    }
};
```

The runner topologically sorts pending migrations so every dependency runs first. Timestamp order is preserved as the tie-breaker. A dependency cycle raises `LetMigrateException`.

### Transactionless migrations

Opt individual migrations out of transactions for operations that cannot run inside a transaction (e.g., `CREATE INDEX CONCURRENTLY` on PostgreSQL):

```php
<?php
use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\TransactionlessMigrationInterface;

return new class implements MigrationInterface, TransactionlessMigrationInterface
{
    public function up($schema): void
    {
        $schema->raw('CREATE INDEX CONCURRENTLY idx_users_email ON users (email)');
    }

    public function down($schema): void
    {
        $schema->raw('DROP INDEX CONCURRENTLY IF EXISTS idx_users_email');
    }
};
```

When `all_or_nothing` is active, the batch transaction is committed immediately before a transactionless migration, then a fresh transaction is opened after it — atomicity is necessarily split.

### Migration linting

Check for dangerous operations (DROP, TRUNCATE, DELETE without WHERE) before applying:

```php
use AlfaCode\LetMigrate\MigrationLinter;

$linter = new MigrationLinter();

// Lint a list of SQL statements
$statements = ['DROP TABLE users;', 'ALTER TABLE posts ADD COLUMN author_id BIGINT;'];
$findings = $linter->lint($statements);

// Returns: [
//   ['severity' => 'danger', 'message' => 'DROP TABLE — irreversible data loss', 'sql' => '…']
// ]

// Check if any statement has danger severity
if ($linter->hasDanger($statements)) {
    echo "This migration is destructive. Use --force to proceed.\n";
}

// Format findings for display
$lines = $linter->format($findings);
foreach ($lines as $line) {
    echo $line . "\n";
}
```

Severities: `'danger'` (irreversible, e.g. DROP TABLE) and `'warning'` (risky, e.g. NOT NULL without default).

### Schema inspection and diffing

Inspect the live database schema and generate migrations from differences:

```php
use AlfaCode\LetMigrate\LetMigrate;
use AlfaCode\LetMigrate\SchemaDiffer;

$engine = LetMigrate::configure($config);

// Inspect the live database
$inspector = $engine->inspect();
$tables = $inspector->getTables();           // string[]
$columns = $inspector->getColumns('users');  // ColumnMeta[]
$hasTable = $inspector->tableExists('users'); // bool

// Capture the database schema as an array
$liveSchema = \AlfaCode\LetMigrate\SchemaSnapshot::capture($engine->inspect());

// Capture code schema (freshly-migrated database)
$codeSchema = $engine->snapshot();

// Diff: FROM live TO code (what's missing)
$differ = new SchemaDiffer();
$delta = $differ->diff($liveSchema, $codeSchema, allowDestructive: false);

// Returns:
// {
//   created: ['new_table'],
//   dropped: [],  // empty unless allowDestructive: true
//   columnsAdded: {'users': ['new_column']},
//   columnsRemoved: {},
//   columnsChanged: {},
//   empty: false
// }
```

### Schema dumping and squashing

Compress historical migrations into a baseline schema dump for fast fresh installs:

```php
use AlfaCode\LetMigrate\SchemaDump;
use AlfaCode\LetMigrate\LetMigrate;

$engine = LetMigrate::configure($config);

// Capture all applied migrations' SQL
$applied = [];  // ... get from your repository
$sql = $engine->service()->captureSql($applied);

// Write a schema dump and manifest
$dump = new SchemaDump(__DIR__ . '/database/schema.sql');
$dump->write(array_values($sql), $applied);

// On a fresh database, install from dump then apply post-dump migrations
$engine->service()->installFromDumpIfFresh(__DIR__ . '/database/schema.sql');
$result = $engine->run();  // only runs migrations after the dump

// Get which migrations are covered by the dump
$covered = $dump->coveredMigrations();  // string[] filenames
```

The dump writes two files: `schema.sql` (DDL) and `schema.manifest.json` (metadata).

### Deployment lock

Prevent concurrent migrations on the same database using an advisory lock:

```php
use AlfaCode\LetMigrate\DeployLock;
use AlfaCode\LetMigrate\LetMigrate;

$engine = LetMigrate::configure($config);
$driver = $engine->registry()->driver();

$lock = new DeployLock($driver);

if (!$lock->acquire(timeout: 30)) {
    echo "Migration in progress (or stale lock). Timeout: 30s.\n";
    exit(1);
}

try {
    $result = $engine->run();
    echo $result->summary();
} finally {
    $lock->release();
}
```

Useful in CI/CD to prevent concurrent deployments. Each driver (MySQL, PostgreSQL, SQLite, SQL Server) implements locking differently; `DeployLock` abstracts them.

### Breakpoints

Prevent rollback past a specific migration (Phinx-style production safety):

From the CLI (the usual way):

```bash
hkm cli migrate:breakpoint                    # set on the latest applied migration
hkm cli migrate:breakpoint 2024_02_15_000003_create_invoices_table --set
hkm cli migrate:breakpoint 2024_02_15_000003_create_invoices_table --unset
hkm cli migrate:breakpoint --list
```

Programmatically, `BreakpointStore` wraps a database driver:

```php
use AlfaCode\LetMigrate\BreakpointStore;

$breakpoints = new BreakpointStore($driver);          // table defaults to 'let_breakpoints'
$breakpoints->ensureTable();

$breakpoints->set('2024_02_15_000003_create_invoices_table');
$breakpoints->isSet('2024_02_15_000003_create_invoices_table');   // true
$breakpoints->all();                                              // every breakpoint
$breakpoints->blocking($filenamesAboutToBeRolledBack);            // the ones that would block
$breakpoints->clear('2024_02_15_000003_create_invoices_table');
```

Breakpoints are stored in their own one-column table (`{prefix}let_breakpoints`), separate from the tracking table. `rollback` and `reset` abort before crossing a migration that has a breakpoint, unless forced. The feature is opt-in: with no `BreakpointStore` given to the runner, rollback behaves as before.

### Table prefix

Apply a global table prefix (e.g., `app_`) so multiple applications can share one database:

```php
$config = [
    // ...
    'table_prefix' => 'app_',  // All tables will be prefixed
];

$engine = LetMigrate::configure($config);
```

Table prefixing is idempotent: a table already named `app_users` will not be double-prefixed.

### Tenant-aware migrations

Run migrations scoped to a specific tenant using `TenantAwareRunner`:

```php
use AlfaCode\LetMigrate\Tenant\TenantAwareRunner;
use AlfaCode\LetMigrate\LetMigrate;

class MyTenantResolver implements TenantResolverInterface
{
    public function resolveTenants(): array
    {
        // Return tenant identifiers (e.g., database names per tenant)
        return ['tenant_1', 'tenant_2', 'tenant_3'];
    }
}

$engine = LetMigrate::configure($config);
$resolver = new MyTenantResolver();

$runner = new TenantAwareRunner(
    $engine->service(),
    $resolver,
);

$result = $runner->runForAllTenants();  // Applies migrations to every tenant
```

### Migration generation

Auto-generate migrations by introspecting a table:

```php
use AlfaCode\LetMigrate\LetMigrate;

$engine = LetMigrate::configure($config);
$generator = $engine->generator();

// Stub a migration from the entire schema
// Skip let_migrations and let_seeders tracking tables
$stub = $generator->generate();

echo $stub;  // Returns array of migration stubs
```

Useful for bootstrapping a new schema from an existing database.

### PSR-3 logging

Pass any PSR-3 logger to see structured migration output:

```php
use AlfaCode\LetMigrate\LetMigrate;
use Psr\Log\NullLogger;

$logger = new Monolog\Logger('migrations');  // or any PSR-3 logger
// $logger = new NullLogger();  // silent (default)

$engine = LetMigrate::configure($config, logger: $logger);
$result = $engine->run();
```

The logger receives `info` messages at key points (start, success, etc.) and `error` on failure.

## Seeders and factories

LetMigrate provides seeder stubs for test data:

```php
<?php
// database/seeders/DatabaseSeeder.php

use AlfaCode\LetMigrate\Seeder\SeederInterface;

return new class implements SeederInterface
{
    public function run(): void
    {
        // Direct database seeding (no ORM)
        // Or call other seeders:
        // $this->call(UsersSeeder::class);
    }
};
```

Seeders are tracked in `seeders_table` (default: `let_seeders`) and run only once. Use the CLI command `db:seed` to run them.

Factories are intentionally left as a stub — implement your own factory pattern or use an ORM's builder. LetMigrate stays out of the ORM space.

## Drivers and grammar

LetMigrate ships with four database drivers and corresponding SQL grammars:

| Driver | Grammar | Coverage |
|---|---|---|
| `MySQLDriver` | `MySQLGrammar` | MySQL 8.0+ and MariaDB |
| `PostgreSQLDriver` | `PostgreSQLGrammar` | PostgreSQL 12+ (triggers for `onUpdateCurrentTimestamp`) |
| `SQLiteDriver` | `SQLiteGrammar` | SQLite 3.37+ |
| `SQLServerDriver` | `SQLServerGrammar` | SQL Server 2016+ (T-SQL) |

### Extending with custom drivers

Register a custom driver:

```php
use AlfaCode\LetMigrate\DriverRegistry;
use AlfaCode\LetMigrate\Driver\AbstractPdoDriver;

class MyDatabaseDriver extends AbstractPdoDriver
{
    // Implement the DatabaseDriverInterface
}

class MyDatabaseGrammar extends AbstractGrammar
{
    // Implement the GrammarInterface
}

DriverRegistry::extendDriver('mydb', MyDatabaseDriver::class);
DriverRegistry::extendGrammar('mydb', MyDatabaseGrammar::class);

$engine = LetMigrate::configure(['driver' => 'mydb', /* ... */]);
```

Your driver must implement `DatabaseDriverInterface` and your grammar must extend `AbstractGrammar`.

### Driver-specific notes

#### MySQL / MariaDB

- `$t->boolean()` compiles to `TINYINT(1)`
- `$t->onUpdateCurrentTimestamp()` uses inline `ON UPDATE CURRENT_TIMESTAMP`
- Supports `->engine()`, `->charset()`, `->collation()`, `->comment()`
- `ALTER TABLE` adds no `ALGORITHM`/`LOCK` clause unless you ask: call `$t->algorithm(...)` / `$t->lock(...)`, or `$t->instant()` for `ALGORITHM=INSTANT` (MySQL 8.0.12+). Other grammars ignore these.

#### PostgreSQL

- `$t->boolean()` is BOOLEAN
- `$t->onUpdateCurrentTimestamp()` creates a `BEFORE UPDATE` trigger since PostgreSQL has no ON UPDATE modifier
- UUID columns use native `UUID` type
- `CREATE INDEX CONCURRENTLY` is supported (use `TransactionlessMigrationInterface`)
- Enums are database types; `$t->enum()` creates `CREATE TYPE … AS ENUM`

#### SQLite

- Limited ALTER TABLE support; some modifications require table recreation
- No native `BOOLEAN`; uses `INTEGER` with 0/1
- UUIDs are `CHAR(36)` strings
- No foreign key constraints by default; enable with `PRAGMA foreign_keys = ON`
- `all_or_nothing` transactions may be helpful for atomicity

#### SQL Server

- Uses T-SQL dialect; `DECIMAL` is the default numeric type
- No native `BOOLEAN`; uses `BIT` (0/1)
- IDENTITY is used for `->autoIncrement()`
- `$t->json()` uses `NVARCHAR(MAX)` (native JSON available in 2016+)

## Framework bridges

LetMigrate includes optional bridges for Laravel and Symfony:

### Laravel bridge

Located in `src/Bridge/Laravel/`. Adapts LetMigrate's `MigrationInterface` to run alongside Laravel's Illuminate migrations in a single CLI.

### Symfony bridge

Located in `src/Bridge/Symfony/`. Integrates LetMigrate as a Symfony console command so migrations can be run via `bin/console`.

Both bridges are in the same repository; consult their README files for wiring details.

## Built-in commands

The kernel ships migration commands via `CliCommandFactory`. See [/cli/built-in-commands](/cli/built-in-commands) for full argument and option details for:

- `migrate:run` — Apply pending migrations
- `migrate:rollback` — Reverse the last batch
- `migrate:status` — Show migration history
- `migrate:pending` — List unapplied migrations
- `migrate:fresh` — Drop all tables and re-run
- `migrate:reset` — Rollback all migrations
- `migrate:refresh` — Reset + run all
- `migrate:install` — Create the migrations table
- `migrate:redo` — Re-run migrations
- `migrate:to` — Run up to a target migration
- `migrate:diff` — Compare code schema vs. live database
- `migrate:breakpoint` — Set/clear rollback breakpoints
- `migrate:lint` — Check for dangerous operations
- `make:migration` — Scaffold a new migration
- `make:seeder` — Scaffold a new seeder
- `db:seed` — Run seeders

::: tip `--help` is side-effect free
`migrate:run --help`, `-h` and `help migrate:run` print help and touch nothing. (Older php-io-cli releases ran the command before printing its help, so `migrate:fresh --help` dropped every table; if you are on one, do not ask destructive commands for help.)
:::

## Configuration in the kernel

The kernel expects migrations to be configured via `config/migrations.php`:

```php
// config/migrations.php
return [
    'driver'  => env('DB_DRIVER', 'mysql'),
    'host'    => env('DB_HOST', '127.0.0.1'),
    'port'    => env('DB_PORT', 3306),
    'database' => env('DB_NAME', 'app'),
    'username' => env('DB_USER', 'root'),
    'password' => env('DB_PASSWORD', ''),
    'paths'   => [
        base_path('database/migrations'),
    ],
];
```

The `CliCommandFactory` loads this config and injects it into every migration command. You can also override at runtime with `--config=<path>`.

## Common mistakes

::: warning Never mutate migrations after they're applied

Once a migration has been applied to production, do NOT edit it. Changes will not be reflected unless you rollback and re-apply. Create a NEW migration instead.

:::

::: warning Pretend mode does NOT validate against your database

`pretend: true` compiles SQL to a string but does NOT execute it. A migration can have perfectly valid SQL and still fail on your specific database due to:
- Missing credentials / wrong host
- Schema naming conflicts
- Character set mismatches
- Reserved keywords in table/column names

Always test migrations against a copy of your actual database before running them in production.

:::

::: warning Avoid storing application data in tables you plan to drop

Migrations that delete or truncate tables should only do so during development, never in production. Use data retention logic instead (soft deletes, archive tables).

:::

::: warning `all_or_nothing` on MySQL will NOT be atomic

MySQL DDL (CREATE TABLE, ALTER TABLE, DROP, etc.) implicitly commits any open transaction. Setting `all_or_nothing: true` on MySQL provides no atomicity guarantee — use it only on PostgreSQL or SQLite if full batch atomicity is critical.

:::

## Source

- [modules/let-migrate/](https://github.com/AlfaCode-Team/php-service-platform/blob/main/modules/let-migrate)
- [modules/let-migrate/README.md](https://github.com/AlfaCode-Team/php-service-platform/blob/main/modules/let-migrate/README.md)
- [src/Commands/Migrate/](https://github.com/AlfaCode-Team/php-service-platform/blob/main/src/Commands/Migrate)
