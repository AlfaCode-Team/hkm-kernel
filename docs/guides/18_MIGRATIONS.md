# Database Migrations — LetMigrate Engine

> **Module:** `alfacode-team/let-migrate` (`modules/let-migrate/`)  
> **Namespace:** `AlfaCode\LetMigrate\`  
> **Supports:** MySQL, PostgreSQL, SQLite, SQL Server  
> **Requires:** PHP 8.2+, PSR-3 logger, PDO extension  
> **Framework:** Standalone — **zero framework dependencies**

---

## WHAT IS LETMIGRATE?

LetMigrate is an **enterprise-grade**, multi-database migration engine that:

- ✅ Writes migrations once, compiles to correct DDL per database
- ✅ Manages per-driver migration folders (mysql/, postgresql/, sqlite/, sqlserver/)
- ✅ Provides fluent `Blueprint` API for schema changes
- ✅ Supports batched runs, rollbacks, and full transaction safety
- ✅ Includes seeder engine with dependency resolution
- ✅ Ships with complete CLI commands (migrate:run, migrate:rollback, make:migration, db:seed, etc.)
- ✅ Extensible — register custom drivers and grammars via `DriverRegistry`
- ✅ Framework-agnostic — works in any PHP 8.2+ project

**Do NOT use Laravel migrations, Doctrine migrations, or Symfony migrations.**  
Every project in this framework uses **LetMigrate exclusively**.

---

## BOOTSTRAP & CONFIGURATION

### Minimal Setup

```php
use AlfaCode\LetMigrate\LetMigrate;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('migrations');
$logger->pushHandler(new StreamHandler('php://stdout'));

$engine = LetMigrate::configure([
    'driver'   => 'mysql',              // or pgsql, sqlite, sqlserver
    'host'     => 'localhost',
    'port'     => 3306,
    'database' => 'my_app',
    'username' => 'root',
    'password' => env('DB_PASSWORD'),
    'paths'    => [__DIR__ . '/migrations/mysql'],
], $logger);

// Run pending migrations
$result = $engine->run();
echo $result->summary();  // "3 migration(s) applied in batch 1."
```

### Multi-Database Setup

For projects targeting multiple databases, use separate folders per driver:

```php
$engine = LetMigrate::configure([
    'driver'   => env('DB_DRIVER', 'mysql'),  // env-driven
    'host'     => env('DB_HOST'),
    'database' => env('DB_NAME'),
    'username' => env('DB_USER'),
    'password' => env('DB_PASS'),
    'paths'    => [
        __DIR__ . '/migrations/' . env('DB_DRIVER'),  // driver-specific
        __DIR__ . '/migrations/shared',                 // driver-agnostic seeds
    ],
], $logger);
```

### Config Options

```php
LetMigrate::configure([
    // Connection (required)
    'driver'   => 'mysql|pgsql|sqlite|sqlserver',
    'host'     => '127.0.0.1',          // required for non-SQLite
    'port'     => 3306,
    'database' => 'my_app',             // file path for SQLite
    'username' => 'root',               // optional for SQLite
    'password' => 'secret',             // optional for SQLite
    
    // Paths (required)
    'paths'    => [__DIR__ . '/migrations'],
    
    // Options (all optional)
    'pretend'  => false,                // log SQL without executing
    'table'    => 'let_migrations',     // tracking table name
    'batch'    => 1,                    // initial batch number
], $logger);
```

---

## WRITING MIGRATIONS

### File Naming Convention

```
YYYY_MM_DD_NNNNNN_description.php
2024_01_15_000001_create_users_table.php
2024_01_15_000002_create_posts_table.php
2024_03_22_000001_add_avatar_to_users.php
```

Files are sorted lexicographically — the timestamp prefix guarantees correct order.

### Basic Migration Template

Every migration file must return a class implementing `MigrationInterface`:

```php
<?php
declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\MigrationInterface;
use AlfaCode\LetMigrate\Contract\SchemaBuilderInterface;
use AlfaCode\LetMigrate\Schema\Blueprint;

return new class implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->create('users', static function (Blueprint $t): void {
            $t->id();
            $t->string('email', 191)->unique()->notNull();
            $t->string('password');
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropIfExists('users');
    }
};
```

### Use `make:migration` to Scaffold

Instead of writing from scratch, use the `make:migration` command. It writes a
blank, timestamped migration stub into the configured migrations directory
(the first entry of `paths[]`); pass `--path` to override the destination:

```bash
# New migration (snake_case name)
php app/cli/run.php make:migration create_invoices_table

# Override the output directory
php app/cli/run.php make:migration add_status_to_posts --path=/abs/dir
```

The scaffolder:
- Auto-generates filename with correct timestamp + sequence number
- Converts snake_case names to YYYY_MM_DD_NNNNNN format
- Guards against overwriting existing files
- Prepares stub with placeholder methods

---

## BLUEPRINT API — COLUMN DEFINITIONS

### Numeric Columns

```php
$t->id();                              // BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT
$t->tinyInteger('x');                  // TINYINT
$t->smallInteger('x');                 // SMALLINT
$t->mediumInteger('x');                // MEDIUMINT (→ INT on non-MySQL)
$t->integer('x');                      // INT
$t->bigInteger('x');                   // BIGINT
$t->decimal('price', 8, 2);            // DECIMAL(8,2)
$t->float('rate');                     // FLOAT
$t->double('score');                   // DOUBLE

// Unsigned shorthands (equivalent to ->unsigned() on the base type)
$t->unsignedTinyInteger('x');          // TINYINT UNSIGNED
$t->unsignedSmallInteger('x');         // SMALLINT UNSIGNED
$t->unsignedMediumInteger('x');        // MEDIUMINT UNSIGNED
$t->unsignedInteger('x');              // INT UNSIGNED
$t->unsignedBigInteger('x');           // BIGINT UNSIGNED
```

### String Columns

```php
$t->char('code', 36);                  // CHAR(36)
$t->string('name', 255);               // VARCHAR(255) — default length 255
$t->text('body');                      // TEXT
$t->longText('content');               // LONGTEXT
```

### Date / Time Columns

```php
$t->date('born_on');                   // DATE
$t->dateTime('happened_at');           // DATETIME (MySQL) / TIMESTAMP (PG)
$t->timestamp('created_at');           // TIMESTAMP
$t->timestamps();                      // created_at + updated_at (auto-managed)
$t->softDeletes();                     // deleted_at DATETIME NULL
```

### Special Columns

```php
$t->boolean('is_active');              // TINYINT(1) (MySQL) / BOOLEAN (others)
$t->json('metadata');                  // JSON
$t->enum('status', ['a', 'b']);        // ENUM('a','b')
$t->uuid('id');                        // CHAR(36)
$t->binary('data');                    // BLOB
```

### Column Modifiers (chainable)

```php
$t->string('email')
    ->nullable()                       // allow NULL
    ->notNull()                        // NOT NULL (default for most types)
    ->unsigned()                       // UNSIGNED (numeric columns only)
    ->default('hello')                 // DEFAULT value
    ->default('CURRENT_TIMESTAMP')     // raw expression (special cases only)
    ->comment('User email address')    // COMMENT
    ->after('id')                      // column position (MySQL only)
    ->first()                          // place first (MySQL only)
    ->unique()                         // inline UNIQUE constraint
    ->primary()                        // mark as PRIMARY KEY (see note)
    ->autoIncrement();                 // AUTO_INCREMENT (numeric columns)
    ->onUpdateCurrentTimestamp();      // on-update trigger (see below)
```

> **`->primary()` vs `$t->primary([...])`.** The column modifier `->primary()`
> marks a single column as the table's primary key. The grammar emits it as a
> standalone `PRIMARY KEY (col)` clause (NOT inline next to the column) so it
> never collides with the auto-increment path — an auto-increment `id()` stays
> inline (`INTEGER PRIMARY KEY AUTOINCREMENT` on SQLite). For a **composite**
> primary key use the Blueprint method `$t->primary(['a', 'b'])`. Do not use
> both on the same table.
>
> **There is no fluent `->index()` or `->useCurrent()` column modifier.**
> Declare indexes with the Blueprint method `$t->index(['col'])` (see below),
> and a current-timestamp default with `->default('CURRENT_TIMESTAMP')`.

### Timestamps Behavior

```php
$t->timestamps();
// Expands to:
// - created_at: DATETIME DEFAULT CURRENT_TIMESTAMP
// - updated_at: DATETIME DEFAULT CURRENT_TIMESTAMP
//   + MySQL: ON UPDATE CURRENT_TIMESTAMP (inline)
//   + PostgreSQL: BEFORE UPDATE trigger (auto-created)
//   + SQLite: created by SQLite timestamp triggers
//   + SQL Server: DATETIME2 with computed columns
```

For custom on-update behavior, use `onUpdateCurrentTimestamp()` modifier:

```php
$t->dateTime('synced_at')
    ->nullable()
    ->default('CURRENT_TIMESTAMP')
    ->onUpdateCurrentTimestamp();      // triggers DB-level auto-update
```

---

## BLUEPRINT API — INDEXES & CONSTRAINTS

### Indexes

```php
$t->index(['email']);                  // single-column index
$t->index(['tenant_id', 'status']);    // composite index
$t->unique(['email']);                 // UNIQUE constraint
$t->unique(['code'], 'uq_code');       // named UNIQUE
$t->primary(['user_id', 'role_id']);   // composite PRIMARY KEY
```

### Foreign Keys

```php
$t->foreign('user_id')
    ->references('id')
    ->on('users')
    ->onDelete('CASCADE')               // or cascadeOnDelete()
    ->onUpdate('RESTRICT')              // or restrictOnDelete()
    ->name('fk_user_id');              // optional custom name
```

When referencing a composite primary key:

```php
$t->foreign(['user_id', 'role_id'])
    ->references(['user_id', 'role_id'])
    ->on('user_roles');
```

---

## BLUEPRINT API — TABLE OPERATIONS

### Create Table

```php
$schema->create('posts', static function (Blueprint $t): void {
    $t->id();
    $t->string('title');
    $t->text('body');
    $t->bigInteger('author_id');
    $t->foreign('author_id')
        ->references('id')
        ->on('users')
        ->cascadeOnDelete();
    $t->timestamps();
});
```

### Table Options (MySQL)

Chainable table-level options on the Blueprint. These are emitted **only** by the
MySQL grammar — PostgreSQL, SQLite, and SQL Server ignore them (their grammars
override `compileTableOptions()` to return an empty string), so the same migration
stays portable.

```php
$schema->create('users', static function (Blueprint $t): void {
    $t->id();
    $t->string('email');

    $t->engine('InnoDB');                 // ENGINE=InnoDB              (default)
    $t->charset('utf8mb4');               // DEFAULT CHARSET=utf8mb4    (default)
    $t->collation('utf8mb4_0900_ai_ci');  // COLLATE=…                  (default utf8mb4_unicode_ci)
    $t->rowFormat('DYNAMIC');             // ROW_FORMAT=DYNAMIC
    $t->comment('Core project registry'); // COMMENT='…' (table-level)
});
```

`rowFormat()` accepts `DYNAMIC` | `COMPACT` | `COMPRESSED` | `REDUNDANT` | `FIXED`
(case-insensitive — normalised to upper-case). Omit it to let MySQL choose the
engine default. `comment()` sets the MySQL table-level `COMMENT='…'`.

### CHECK Constraints (portable)

`check()` adds a table-level CHECK constraint, emitted inline in the CREATE TABLE
body. Unlike table options, this is **portable across every driver** — MySQL
8.0.16+/MariaDB 10.2+, PostgreSQL, SQLite, and SQL Server all support inline
CHECK. Pass a raw boolean SQL expression using **unquoted** column names so it
stays dialect-neutral, plus an optional constraint name.

```php
$schema->create('projects', static function (Blueprint $t): void {
    $t->id('project_id');
    $t->tinyInteger('status')->unsigned()->default(1);
    $t->tinyInteger('visibility')->unsigned()->default(4);

    $t->check('status between 1 and 3', 'chk_status');       // CONSTRAINT chk_status CHECK (...)
    $t->check('visibility between 4 and 19', 'chk_visibility');
    $t->check('type between 0 and 5');                        // anonymous CHECK (name auto-assigned by DB)
});
```

### Alter Existing Table

```php
$schema->table('users', static function (Blueprint $t): void {
    $t->string('avatar_url', 500)->nullable();
});
```

### Modify Column (alter existing)

```php
$schema->table('users', static function (Blueprint $t): void {
    $t->modifyColumn('email', fn($c) =>
        $c->string(320)->notNull()->unique()
    );
});
```

### Rename Column

```php
$schema->table('users', static function (Blueprint $t): void {
    $t->renameColumn('full_name', 'name');
});
```

### Drop Table

```php
$schema->drop('users');                // fails if table doesn't exist
$schema->dropIfExists('users');        // safe
```

### Check Table / Column Existence

```php
if ($schema->hasTable('users')) {
    echo "Users table exists";
}

if ($schema->hasColumn('users', 'email')) {
    echo "Email column exists";
}
```

---

## SCHEMA INTROSPECTION

LetMigrate can inspect existing database schemas to make intelligent decisions:

```php
$inspector = $schema->getInspector();
if ($inspector === null) {
    throw new \LogicException('Inspector not available for this driver');
}

// Get all tables
$tables = $inspector->getTables();  // string[]

// Get columns of a table
$columns = $inspector->getColumns('users');  // ColumnMeta[]
foreach ($columns as $col) {
    echo $col->name;           // string
    echo $col->type;           // 'string', 'integer', 'boolean', etc.
    echo $col->nullable;       // bool
    echo $col->default;        // ?string
    echo $col->primaryKey;     // bool
    echo $col->autoIncrement;  // bool
}

// Get indexes
$indexes = $inspector->getIndexes('users');  // IndexMeta[]

// Get foreign keys
$fks = $inspector->getForeignKeys('posts');  // ForeignKeyMeta[]
```

---

## RUNNER OPERATIONS

### Run Pending Migrations

```php
$result = $engine->run();
echo $result->summary();              // "3 migration(s) applied in batch 1."
echo $result->count();                // 3
echo $result->batch();                // 1
```

### Rollback

```php
// Roll back the last batch
$result = $engine->rollback();

// Roll back the last N batches
$result = $engine->rollback(steps: 3);

// Roll back ALL migrations (dev only)
$result = $engine->reset();

// Reset + re-run everything
$result = $engine->refresh();
```

### Status & Inspection

```php
// Status of all migrations
$status = $engine->status();
// [
//     '2024_01_01_000001_create_users' => [
//         'status' => 'applied|pending',
//         'batch'  => 1,              // null if pending
//     ],
//     ...
// ]

// List only pending migrations
$pending = $engine->pending();  // string[]
```

### Result Object

```php
$result = $engine->run();

$result->count();              // int — migrations applied/rolled back
$result->batch();              // int — batch number
$result->direction();          // 'up' | 'down'
$result->summary();            // string — formatted message
$result->wasSuccessful();      // bool
```

---

## EVENTS & LIFECYCLE HOOKS

```php
use AlfaCode\LetMigrate\Event\MigrationStarted;
use AlfaCode\LetMigrate\Event\MigrationFinished;
use AlfaCode\LetMigrate\Event\MigrationFailed;
use AlfaCode\LetMigrate\Event\MigrationsCompleted;

// Migration about to run
$engine->events()->on(MigrationStarted::class, function (MigrationStarted $e): void {
    echo "⏳ Starting: {$e->migration} ({$e->direction})\n";
    echo "Batch: {$e->batch}\n";
});

// Migration finished successfully
$engine->events()->on(MigrationFinished::class, function (MigrationFinished $e): void {
    echo "✔ Done: {$e->migration}\n";
    echo "Duration: {$e->durationMs}ms\n";
});

// Migration failed
$engine->events()->on(MigrationFailed::class, function (MigrationFailed $e): void {
    echo "✗ Failed: {$e->migration}\n";
    // Report to error tracking
    Sentry::captureException($e->exception);
});

// All migrations completed
$engine->events()->on(MigrationsCompleted::class, function (MigrationsCompleted $e): void {
    echo $e->result->summary();
});

// Run them
$result = $engine->run();
```

---

## SEEDER ENGINE

### Writing Seeders

Seeders populate the database with test/reference data. The `SeederRunner`
resolver accepts **two file styles** — pick either:

**1. Named class (the `make:seeder` scaffold).** The class name MUST match the
file name (`UsersSeeder.php` → `class UsersSeeder`):

```php
<?php
declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\DatabaseDriverInterface;
use AlfaCode\LetMigrate\Seeder\SeederInterface;

final class UsersSeeder implements SeederInterface
{
    public function run(DatabaseDriverInterface $db): void
    {
        $db->insert('users', ['name' => 'Alice', 'email' => 'a@example.test']);
    }

    public function getDependencies(): array
    {
        return [];  // or ['SomeOtherSeeder'] for dependency ordering
    }
}
```

**2. Returned instance (anonymous class), like a migration file:**

```php
<?php
declare(strict_types=1);

use AlfaCode\LetMigrate\Contract\DatabaseDriverInterface;
use AlfaCode\LetMigrate\Seeder\SeederInterface;

return new class implements SeederInterface
{
    public function run(DatabaseDriverInterface $db): void
    {
        $db->execute('INSERT INTO statuses (name) VALUES (?)', ['active']);
        $db->execute('INSERT INTO statuses (name) VALUES (?)', ['inactive']);
    }

    public function getDependencies(): array
    {
        return [];
    }
};
```

Scaffold one with: `php app/cli/run.php make:seeder UsersSeeder`.

### Seeder Commands

Seeding runs through the `db:seed` command (there is no `seed:*` group):

```bash
# Run all pending seeders (tracked in let_seeders — idempotent)
php app/cli/run.php db:seed

# Run only one seeder by name (file basename / class name)
php app/cli/run.php db:seed --class UsersSeeder
```

### SeederRunner (Programmatic)

```php
use AlfaCode\LetMigrate\Seeder\SeederRunner;
use AlfaCode\LetMigrate\Seeder\SeederRepository;

$repository = new SeederRepository($driver, $grammar);   // tracking table: let_seeders
$runner = new SeederRunner(
    driver:     $driver,
    repository: $repository,
    paths:      [__DIR__ . '/seeders'],
    // logger:  $logger,                  // optional PSR-3 logger
);

// Run pending seeders; pass a name to run just one.
$applied = $runner->run();                       // string[] of seeder names
$applied = $runner->run(only: 'UsersSeeder');    // single seeder
$applied = $runner->run(force: true);            // re-run even if recorded

// Re-run all (ignores the tracking table)
$applied = $runner->fresh();

// Get status
$status = $runner->status();
// [
//     'SomeSeeder' => ['status' => 'applied|pending'],
//     ...
// ]
```

### Seeder Dependency Ordering

Seeders with dependencies are run **after** their dependencies (topological sort):

```php
// DatabaseSeeder.php — runs first (no dependencies)
return new class implements SeederInterface {
    public function getDependencies(): array { return []; }
    public function run(DatabaseDriverInterface $db): void {
        // Create base reference data
    }
};

// PermissionSeeder.php — depends on DatabaseSeeder
return new class implements SeederInterface {
    public function getDependencies(): array {
        return ['DatabaseSeeder'];  // class name string
    }
    public function run(DatabaseDriverInterface $db): void {
        // Uses data from DatabaseSeeder
    }
};

// Circular dependencies throw LetMigrateException at runtime
```

---

## PRETEND MODE (CI PREVIEWS)

Test what SQL would be executed without touching the database:

```php
$engine = LetMigrate::configure([
    'driver'   => 'mysql',
    'host'     => '127.0.0.1',
    'database' => 'staging_db',
    'paths'    => [__DIR__ . '/migrations/mysql'],
    'pretend'  => true,  // ← log SQL, execute nothing
], $logger);

$engine->run();  // logs all SQL statements without executing
```

Used in CI/CD pipelines to verify migrations compile correctly before applying to production.

---

## CUSTOM DRIVERS & GRAMMARS

Extend LetMigrate to support additional databases:

```php
use AlfaCode\LetMigrate\Registry\DriverRegistry;
use AlfaCode\LetMigrate\Contract\DatabaseDriverInterface;
use AlfaCode\LetMigrate\Schema\GrammarInterface;

// 1. Implement custom driver (extends AbstractPdoDriver)
class CockroachDBDriver extends AbstractPdoDriver {
    // ... PDO connection + execution logic
}

// 2. Implement custom grammar (extends AbstractGrammar)
class CockroachDBGrammar extends AbstractGrammar {
    // ... DDL compilation rules
}

// 3. Register via DriverRegistry
DriverRegistry::extendDriver('cockroach', fn($cfg) => new CockroachDBDriver($cfg));
DriverRegistry::extendGrammar('cockroach', fn($cfg) => new CockroachDBGrammar());

// 4. Now use it normally
$engine = LetMigrate::configure([
    'driver'   => 'cockroach',
    'host'     => '127.0.0.1',
    'database' => 'mydb',
    'paths'    => [__DIR__ . '/migrations/cockroach'],
]);
```

---

## DATABASE-SPECIFIC NOTES

### MySQL / MariaDB

- InnoDB engine, utf8mb4 charset by default
- Backtick identifier quoting: `` `table` ``
- `AUTO_INCREMENT`, `FOREIGN KEY … ON DELETE CASCADE`
- `ON UPDATE CURRENT_TIMESTAMP` supported inline
- Column positioning: `->after('col')`, `->first()`
- Table options: `->engine()`, `->charset()`, `->collation()`, `->rowFormat('DYNAMIC')`, `->comment('…')` (MySQL-only)
- CHECK constraints via `->check('expr', 'name')` are portable (inline in CREATE TABLE) on all four drivers

### PostgreSQL

- Double-quote identifier quoting: `"table"`
- `BIGSERIAL` / `SERIAL` for auto-increment
- `TIMESTAMP` instead of `DATETIME`; `JSONB` instead of `JSON`
- **On-update triggers:** `timestamps()` and `onUpdateCurrentTimestamp()` create `BEFORE UPDATE` triggers automatically
- FK checks via `SET session_replication_role`
- No column positioning (Postgres doesn't support it)

### SQLite

- All types mapped to SQLite affinity groups (INTEGER, TEXT, REAL, BLOB)
- `INTEGER PRIMARY KEY AUTOINCREMENT` for auto-increment
- FK checks via `PRAGMA foreign_keys`
- DDL is transactional — migrations are fully rolled back on failure
- Limited ALTER TABLE support (only rename + add column)

### SQL Server

- Square-bracket `[identifier]` quoting: `[table]`
- `IDENTITY(1,1)` for auto-increment
- `NVARCHAR(MAX)` for Unicode text
- `DATETIME2` for timestamps (higher precision than DATETIME)
- No native on-update triggers — handled via computed columns
- FK checks via `sp_MSforeachtable` (no standard constraint syntax)

---

## ERROR HANDLING

LetMigrate throws domain-specific exceptions:

```php
use AlfaCode\LetMigrate\Exception\LetMigrateException;
use AlfaCode\LetMigrate\Exception\ConnectionException;
use AlfaCode\LetMigrate\Exception\MigrationException;
use AlfaCode\LetMigrate\Exception\QueryException;

try {
    $engine->run();
} catch (ConnectionException $e) {
    echo "Database connection failed: {$e->getMessage()}";
} catch (MigrationException $e) {
    echo "Migration logic error: {$e->getMessage()}";
} catch (QueryException $e) {
    echo "SQL execution failed: {$e->getMessage()}";
} catch (LetMigrateException $e) {
    echo "Migration engine error: {$e->getMessage()}";
}
```

---

## COMPLETE WORKFLOW EXAMPLE

```php
<?php
use AlfaCode\LetMigrate\LetMigrate;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// 1. Setup logger
$logger = new Logger('migrations');
$logger->pushHandler(new StreamHandler('php://stdout'));

// 2. Configure engine
$engine = LetMigrate::configure([
    'driver'   => 'pgsql',
    'host'     => 'localhost',
    'database' => 'myapp',
    'username' => 'postgres',
    'password' => env('DB_PASSWORD'),
    'paths'    => [
        __DIR__ . '/migrations/postgresql',
        __DIR__ . '/migrations/shared',
    ],
], $logger);

// 3. (Optional) Hook events
$engine->events()->on(
    \AlfaCode\LetMigrate\Event\MigrationFailed::class,
    function ($e) {
        Sentry::captureException($e->exception);
    }
);

// 4. Run
$result = $engine->run();

// 5. Check result
if (!$result->wasSuccessful()) {
    exit(1);
}

echo $result->summary();
```

---

## What you must never do

```
✗ Use Laravel or Doctrine migrations in this project — only LetMigrate
✗ Import Eloquent, Doctrine, or Symfony migration classes
✗ Define routes in migrations or write any business logic
✗ Use float for money — use decimal() with precision + scale
✗ Write migrations without matching down() rollback
✗ Forget to run data migrations inside transactions (or explicit transaction handling)
✗ Use --seed in refresh without wiring SeederRunner to MigrateRefreshCommand
✗ Use a fluent `->index()` or `->useCurrent()` column modifier — they do NOT exist; use `$t->index(['col'])` and `->default('CURRENT_TIMESTAMP')`
✗ Combine a column `->primary()` with a Blueprint `$t->primary([...])` on the same table — pick one (double PRIMARY KEY error)
✗ Reference `seed:run`/`seed:fresh`/`seed:status` or `migrate:make` — the real commands are `db:seed` and `make:migration`
✗ Hardcode database table names — use string literals, never interpolation
✗ Call onUpdateCurrentTimestamp() on non-timestamp columns
✗ Use ON UPDATE CURRENT_TIMESTAMP on PostgreSQL (use trigger instead — LetMigrate handles this)
✗ Forget to add new env vars to config — migrations must declare all DB config they use
✗ Use pretend mode in production tests — only for CI previews
✗ Mutate migration files after they've been applied (create a new migration instead)
```

---

## CLI COMMANDS REFERENCE

All commands are wired into `php-io-cli` and available via:

```bash
php app/cli/run.php COMMAND [options]
```

### Migrate Commands

| Command | Description |
|---|---|
| `migrate:run` | Apply all pending migrations |
| `migrate:rollback [--steps=3]` | Roll back last batch (or N batches) |
| `migrate:reset` | Roll back ALL migrations |
| `migrate:refresh [--seed]` | Reset + re-run all (optionally seed) |
| `migrate:status` | Show all migrations with run/pending status |
| `make:migration NAME` | Scaffold a new migration |
| `migrate:fresh` | Drop ALL tables and re-run every migration |
| `migrate:check` | CI drift guard — non-zero exit if live schema differs from target |
| `migrate:diff [--stdout] [--force]` | Emit a delta migration reconciling live DB → target |

### Seed Commands

| Command | Description |
|---|---|
| `db:seed` | Run all pending seeders (tracked in `let_seeders`) |
| `db:seed --class NAME` | Run only the named seeder |
| `make:seeder NAME` | Scaffold a new seeder class |

---

## TESTING MIGRATIONS

### Unit Tests

```bash
composer test:unit  # no DB needed
```

### Integration Tests

```bash
composer test:int   # requires pdo_sqlite
```

SQLite in-memory database used for integration tests — no MySQL/PostgreSQL installation required.

### Full Test Suite

```bash
composer check      # cs-check + phpstan + all tests
```

---

## See Also

- [CLI — php-io-cli Components](17_PHP_IO_CLI.md)
- [Commands & CLI Integration](14_CLI.md)
- [Error Handling](15_ERROR_HANDLING.md)
