<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Fakes;

use AlfaCode\LetMigrate\Driver\SQLite\SQLiteDriver;
use AlfaCode\LetMigrate\Driver\SQLite\SQLiteGrammar;
use AlfaCode\LetMigrate\DriverRegistry;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DriverAware;

/**
 * A real SQLite `DatabasePort`, built by running a plugin's own migrations.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * WHY THIS IS NOT {@see FakeDatabase}
 * ────────────────────────────────────────────────────────────────────────────
 *
 * `FakeDatabase` runs no SQL: it records the statements it was given and
 * returns rows the test queued. That is the right tool for asserting that a
 * service committed, dispatched and rolled back in the right order, and the
 * wrong one for asserting that a repository's SQL matches the schema its
 * migrations produce — because it will happily accept a query naming a column
 * that does not exist.
 *
 * `Ground\Database\MigrationHarness` does run real migrations, but it is a CI
 * verifier behind `hkm plugins migrate`: it reports whether a migration set
 * applies and rolls back on each driver. It is not a port a repository can be
 * pointed at.
 *
 * This class is the third thing: the migrations, applied, with a `DatabasePort`
 * on top. It catches the class of defect neither of the others can — a column
 * that does not exist, a `NOT NULL` with no default, a unique index that is
 * missing so a duplicate silently inserts, an index that does not cover the
 * query, a conditional `UPDATE` whose row count the repository never checks.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * IT EXISTS BECAUSE TEN PLUGINS HAD WRITTEN IT SEPARATELY
 * ────────────────────────────────────────────────────────────────────────────
 *
 * Ten repositories in the Elimu lineage — frontdesk, inventory, boarding,
 * library, transport, curriculum, expenses, accounting-ohada, fees and payroll
 * — each carry a `tests/Support/SqliteDatabase.php` that is this class with a
 * different temp-file prefix and a different set of plugin-specific remarks in
 * its docblock. Every one of them says, in its own words, that it is one copy
 * too many. Counting them is what established that the shared package they
 * were each asking for already existed: this one.
 *
 * A plugin using this class deletes its own copy and gains nothing to
 * maintain. The two behaviours worth keeping from those copies are both here
 * and both load-bearing:
 *
 *   FOREIGN KEYS ARE TURNED ON. SQLite parses every `FOREIGN KEY` and then
 *   ignores it unless `PRAGMA foreign_keys = ON`, per connection. A test
 *   asserting that a row cannot reference a missing parent passes while the
 *   database accepts it.
 *
 *   THE DATABASE IS A FILE, NOT `:memory:`. LetMigrate's `SQLiteDriver` takes
 *   a PATH and opens its own connection, so an in-memory database would be
 *   migrated on one connection and queried on another: the tests would run
 *   against an empty schema and every assertion would fail for a reason that
 *   has nothing to do with the code under test.
 *
 * It advertises {@see DriverAware} so that a plugin's own row-locking helper
 * resolves the SQLite form of `FOR UPDATE` — the empty string, because SQLite
 * serialises writers — and the portability branch is exercised rather than
 * bypassed.
 *
 * ────────────────────────────────────────────────────────────────────────────
 * TEST-ONLY. NOT FOR PRODUCTION.
 * ────────────────────────────────────────────────────────────────────────────
 *
 * `upsert()` compiles the SQLite `ON CONFLICT` form only, and {@see pdo()} is a
 * deliberate reach-through for arranging and asserting rows. Both are correct
 * in a test and wrong in an adapter.
 */
final class MigratedSqlite implements DatabasePort, DriverAware
{
    private ?string $path = null;

    public function __construct(private readonly \PDO $pdo)
    {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Per connection, and silently ignored if omitted. See the docblock.
        $this->pdo->exec('PRAGMA foreign_keys = ON');
    }

    /**
     * Apply every migration in a directory, then connect to the result.
     *
     * Files are applied in sorted order, which is what the timestamp prefix in
     * a migration's name is for. Each file is expected to `return` a
     * `MigrationInterface`, as LetMigrate's own scaffolder generates.
     *
     * @param string $migrationsDir e.g. `__DIR__ . '/../../database/migrations'`
     * @param string $prefix        temp-file prefix, for recognising leftovers
     */
    public static function migrated(string $migrationsDir, string $prefix = 'ground'): self
    {
        $path = tempnam(sys_get_temp_dir(), $prefix . '-') . '.sqlite';

        $registry = DriverRegistry::fromDriverAndGrammar(
            'sqlite',
            new SQLiteDriver($path),
            new SQLiteGrammar(),
        );

        $schema = $registry->schemaBuilder();
        $files  = glob(rtrim($migrationsDir, '/') . '/*.php') ?: [];
        sort($files);

        if ($files === []) {
            throw new \RuntimeException(
                "No migrations found in {$migrationsDir}. A suite that silently "
                . 'runs against an empty schema fails for the wrong reason.'
            );
        }

        foreach ($files as $file) {
            (require $file)->up($schema);
        }

        $pdo = new \PDO('sqlite:' . $path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $self       = new self($pdo);
        $self->path = $path;

        return $self;
    }

    /** Remove the temp database file. Call from tearDown. */
    public function discard(): void
    {
        if ($this->path !== null && is_file($this->path)) {
            @unlink($this->path);
        }

        $this->path = null;
    }

    public function driver(): string
    {
        return 'sqlite';
    }

    public function query(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function queryOne(string $sql, array $params = []): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function execute(string $sql, array $params = []): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->rowCount();
    }

    public function upsert(
        string $table,
        array $values,
        array $conflictColumns,
        ?array $updateColumns = null,
    ): int {
        $updateColumns ??= array_values(array_diff(array_keys($values), $conflictColumns));

        $quote  = static fn (string $c): string => '"' . $c . '"';
        $cols   = implode(', ', array_map($quote, array_keys($values)));
        $binds  = implode(', ', array_map(static fn (string $c): string => ':' . $c, array_keys($values)));
        $target = implode(', ', array_map($quote, $conflictColumns));

        $sql = "INSERT INTO \"{$table}\" ({$cols}) VALUES ({$binds})";

        if ($updateColumns === []) {
            // Insert-if-absent, which is what an empty update list means on
            // DatabasePort. Not a no-op: the row is still inserted when it is
            // not already there.
            $sql .= " ON CONFLICT ({$target}) DO NOTHING";
        } else {
            $set = implode(', ', array_map(
                static fn (string $c): string => "\"{$c}\" = EXCLUDED.\"{$c}\"",
                $updateColumns,
            ));
            $sql .= " ON CONFLICT ({$target}) DO UPDATE SET {$set}";
        }

        return $this->execute($sql, $values);
    }

    public function lastInsertId(?string $sequence = null): string
    {
        return (string) $this->pdo->lastInsertId();
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollback(): void
    {
        $this->pdo->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }

    /** Test-only reach-through, for arranging and asserting rows directly. */
    public function pdo(): \PDO
    {
        return $this->pdo;
    }
}
