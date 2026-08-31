<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Database;

use AlfaCode\LetMigrate\MigrationResult;
use AlfaCode\LetMigrate\MigrationServiceFactory;

/**
 * Run a plugin's migrations against a REAL database, then roll them back.
 *
 * Not a fake, and not `--pretend`. Pretend mode compiles SQL and never executes
 * it, so it cannot catch the things that actually break a deploy: a column type
 * one engine rejects, an index name over its length limit, a foreign key
 * pointing at a table the ordering has not created yet. Those all compile.
 *
 * ─── THE SCRATCH DATABASE ───────────────────────────────────────────────────
 *
 * Every run CREATES its own database with a unique name and DROPS it at the
 * end. Ground never migrates a database someone configured, which removes the
 * whole class of accident where a test suite points at a real connection and
 * runs `reset()`. The config in ground.databases.json names a SERVER; the
 * database is ground's own and lives for the length of one run.
 *
 * That also means a failed run leaves the scratch database behind on purpose
 * when GROUND_KEEP_DATABASE is set: after a migration fails halfway, the
 * half-applied schema is the evidence.
 *
 * ─── WHAT IT PROVES ─────────────────────────────────────────────────────────
 *
 *   up()    every migration applies, in order, on this engine
 *   schema  the tables it claims to create exist afterwards
 *   down()  the whole stack rolls back and leaves nothing behind
 *
 * The rollback half is the one plugin authors skip, and it is the half a
 * `hkm plugins disable` depends on: unpublishing a plugin rolls its migrations
 * back before deleting the files.
 */
final class MigrationHarness
{
    /** @var list<string> every plugin whose migrations take part, dependencies first */
    private readonly array $pluginDirectories;

    /**
     * @param string|list<string> $pluginDirectory the plugin under test, or the
     *        whole chain with the plugin under test LAST
     * @param list<string> $paths migration directories, relative to each plugin
     */
    public function __construct(
        string|array $pluginDirectory,
        private readonly array $paths = ['database/migrations'],
        /** @var list<string> seeder directories, relative to each plugin */
        private readonly array $seederPaths = ['database/seeders'],
        /** Which schema this harness represents — 'central' or 'tenant'. */
        public readonly string $layer = 'central',
    ) {
        $this->pluginDirectories = \is_array($pluginDirectory)
            ? array_values($pluginDirectory)
            : [$pluginDirectory];
    }

    /**
     * Absolute migration paths that exist and hold at least one file.
     *
     * ─── WHY A CHAIN AND NOT ONE PLUGIN ─────────────────────────────────────
     *
     * A plugin's schema does not stop at its own tables. Tenancy's
     * `user_tenants` carries a foreign key onto `users`, which the User plugin
     * owns — so running tenancy's migrations ALONE against a real database
     * either fails outright or, on an engine that defers the check, quietly
     * produces a schema nothing else could ever join to. Neither answer is the
     * one the developer wanted.
     *
     * A project runs every enabled plugin's migrations together, so the honest
     * rehearsal of that is to run the dependency chain too. LetMigrate collects
     * files from every path and `ksort`s them by FILENAME, so the timestamp
     * prefixes interleave the plugins exactly as they would in a project — the
     * order is a property of the migrations, not of the order paths are handed
     * over.
     */
    public function migrationPaths(): array
    {
        $found = [];

        foreach ($this->pluginDirectories as $plugin) {
            foreach ($this->paths as $rel) {
                $dir = rtrim($plugin, '/') . '/' . trim($rel, '/');

                if (is_dir($dir) && glob($dir . '/*.php') !== []) {
                    $found[] = $dir;
                }
            }
        }

        return $found;
    }

    /**
     * Absolute seeder directories that exist and hold at least one file.
     *
     * Same chain as the migrations: a plugin's seed data routinely depends on
     * rows a dependency seeded (a role before a user that holds it), so seeding
     * only the plugin under test reproduces a state no deployment ever has.
     *
     * @return list<string>
     */
    public function seederPaths(): array
    {
        $found = [];

        foreach ($this->pluginDirectories as $plugin) {
            foreach ($this->seederPaths as $rel) {
                $dir = rtrim($plugin, '/') . '/' . trim($rel, '/');

                if (is_dir($dir) && glob($dir . '/*.php') !== []) {
                    $found[] = $dir;
                }
            }
        }

        return $found;
    }

    public function run(DatabaseTarget $target): MigrationRun
    {
        if (!$target->reachable) {
            return MigrationRun::skipped($target, $this->layer);
        }

        $paths = $this->migrationPaths();

        if ($paths === []) {
            return MigrationRun::failed($target, 'no migrations found under ' . implode(', ', $this->paths), $this->layer);
        }

        $scratch = $this->scratchName();

        try {
            $this->createDatabase($target, $scratch);
        } catch (\Throwable $e) {
            return MigrationRun::failed($target, 'could not create the scratch database: ' . $e->getMessage(), $this->layer);
        }

        try {
            $service = MigrationServiceFactory::fromConfig([
                'driver'   => $target->driver,
                'paths'    => $paths,
                'database' => $this->databaseValue($target, $scratch),
                ...$target->server,
                // A migration that fails must leave the run inspectable rather
                // than unwinding everything it did — the half-applied state is
                // what tells you WHERE it broke.
                'all_or_nothing' => false,
            ]);

            $service->install();
            $result = $service->run();

            $applied = $this->appliedCount($result);
            $tables  = $this->tablesIn($target, $scratch);

            // Seed BEFORE the rollback, against the schema that was just built.
            //
            // A seeder is the first thing to notice a column that a migration
            // renamed and nothing else uses yet, and it exercises the schema the
            // way the application will — with real INSERTs, real constraints and
            // real defaults — which is the half `migrate` alone never touches.
            $seeded = $this->seed($target, $scratch);

            // A foreign key whose PARENT TABLE is not in this database.
            //
            // MySQL, PostgreSQL and SQL Server refuse such a key outright, so
            // this only ever fires on SQLite — which accepts it silently and
            // would let a tenant schema referencing the CENTRAL `users` table
            // look perfectly healthy right up until it is deployed onto an
            // engine that checks.
            $dangling = $this->danglingForeignKeys($target, $scratch, $tables);

            if ($dangling !== []) {
                return MigrationRun::failed(
                    $target,
                    'foreign keys point at tables this database does not have: ' . implode(', ', $dangling)
                    . ($this->layer === 'tenant'
                        ? '. A tenant database cannot reference the central one — no engine supports a'
                          . ' cross-database foreign key. Drop the constraint and enforce it in the service.'
                        : '.'),
                    $this->layer,
                );
            }

            // Now the direction nobody tests. reset() unwinds every migration
            // through its own down().
            $service->reset();

            $after = $this->tablesIn($target, $scratch);
            $clean = $this->isClean($after);

            return MigrationRun::passed($target, $applied, $tables, $clean, $seeded, $this->layer);
        } catch (\Throwable $e) {
            return MigrationRun::failed($target, $e->getMessage(), $this->layer);
        } finally {
            $this->dropDatabase($target, $scratch);
        }
    }

    /**
     * Run every seeder in the chain against the scratch database.
     *
     * One service PER seeder directory, because `seedersPath` on
     * MigrationConfig is a single path, not a list. They share one database and
     * one `let_seeders` tracking table, so a seeder cannot run twice and the
     * order across plugins stays the order the chain put them in.
     *
     * A failure here fails the RUN. That is deliberate: a seeder that cannot
     * insert is telling you the schema does not accept the data the application
     * is built to store, and reporting "migrations passed" beside it would be
     * true and useless.
     *
     * @return int seeders that ran
     */
    private function seed(DatabaseTarget $target, string $scratch): int
    {
        $ran = 0;

        foreach ($this->seederPaths() as $directory) {
            $service = MigrationServiceFactory::fromConfig([
                'driver'        => $target->driver,
                'paths'         => $this->migrationPaths(),
                'seeders_path'  => $directory,
                'database'      => $this->databaseValue($target, $scratch),
                ...$target->server,
                'all_or_nothing' => false,
            ]);

            $ran += $service->seed();
        }

        return $ran;
    }

    /**
     * Foreign keys whose parent table is missing from this database.
     *
     * SQLite only, and deliberately: every other engine rejects the constraint
     * when the migration runs, so by the time control reaches here they have
     * already failed loudly. SQLite records the reference and says nothing,
     * which is what let a tenant-template migration declare
     * `foreign('user_id')->on('users')` — a table that lives in the CENTRAL
     * database — and still report a clean run.
     *
     * `PRAGMA foreign_key_check` cannot answer this: with no rows in the table
     * there is nothing to violate, so it returns empty. The declared keys have
     * to be read and their targets compared against what exists.
     *
     * @param  list<string> $tables tables this database ended up with
     * @return list<string> "child.column → missing_parent"
     */
    private function danglingForeignKeys(DatabaseTarget $target, string $scratch, array $tables): array
    {
        if ($target->driver !== 'sqlite') {
            return [];
        }

        try {
            $pdo = new \PDO(
                'sqlite:' . $this->databaseValue($target, $scratch),
                null,
                null,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
            );
        } catch (\Throwable) {
            return [];
        }

        $present = array_map('strtolower', $tables);
        $broken  = [];

        foreach ($tables as $table) {
            // The table name cannot be bound: PRAGMA takes an identifier, not a
            // parameter. It comes from sqlite_master, not from user input, and
            // the quotes are doubled regardless.
            $quoted = '"' . str_replace('"', '""', $table) . '"';

            try {
                $keys = $pdo->query("PRAGMA foreign_key_list({$quoted})")->fetchAll(\PDO::FETCH_ASSOC);
            } catch (\Throwable) {
                continue;
            }

            foreach ($keys as $key) {
                $parent = (string) ($key['table'] ?? '');

                if ($parent !== '' && !\in_array(strtolower($parent), $present, true)) {
                    $broken[] = sprintf('%s.%s → %s', $table, (string) ($key['from'] ?? '?'), $parent);
                }
            }
        }

        return $broken;
    }

    // ── The scratch database ──────────────────────────────────────────────────

    private function scratchName(): string
    {
        // Random, so two runs — or two developers on one shared server — never
        // collide, and a leftover from a crashed run is never reused.
        return 'ground_' . bin2hex(random_bytes(6));
    }

    /** For SQLite the "database" is a file path; for the rest it is a name. */
    private function databaseValue(DatabaseTarget $target, string $scratch): string
    {
        return $target->driver === 'sqlite'
            ? sys_get_temp_dir() . '/' . $scratch . '.sqlite'
            : $scratch;
    }

    private function createDatabase(DatabaseTarget $target, string $scratch): void
    {
        if ($target->driver === 'sqlite') {
            return; // PDO creates the file on first connect.
        }

        $pdo = $this->serverConnection($target);
        $q   = $this->quoteName($target->driver, $scratch);

        $pdo->exec(match ($target->driver) {
            'mysql'  => "CREATE DATABASE {$q} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
            'pgsql'  => "CREATE DATABASE {$q}",
            'sqlsrv' => "CREATE DATABASE {$q}",
            default  => throw new \InvalidArgumentException($target->driver),
        });
    }

    private function dropDatabase(DatabaseTarget $target, string $scratch): void
    {
        $keep = getenv('GROUND_KEEP_DATABASE');

        if ($keep === '1' || $keep === 'true') {
            return;
        }

        try {
            if ($target->driver === 'sqlite') {
                @unlink($this->databaseValue($target, $scratch));

                return;
            }

            $pdo = $this->serverConnection($target);
            $q   = $this->quoteName($target->driver, $scratch);

            // Postgres refuses to drop a database with live connections, and
            // the one this run just used may still be pooled.
            if ($target->driver === 'pgsql') {
                $pdo->exec(
                    'SELECT pg_terminate_backend(pid) FROM pg_stat_activity '
                    . "WHERE datname = " . $pdo->quote($scratch) . ' AND pid <> pg_backend_pid()',
                );
            }
            if ($target->driver === 'sqlsrv') {
                $pdo->exec("ALTER DATABASE {$q} SET SINGLE_USER WITH ROLLBACK IMMEDIATE");
            }

            $pdo->exec("DROP DATABASE IF EXISTS {$q}");
        } catch (\Throwable) {
            // Teardown must never turn a passing run red. A leaked scratch
            // database is named `ground_<hex>` and is trivially identifiable.
        }
    }

    private function serverConnection(DatabaseTarget $target): \PDO
    {
        return new \PDO(
            DatabaseMatrix::serverDsn($target->driver, $target->server),
            (string) ($target->server['username'] ?? ''),
            (string) ($target->server['password'] ?? ''),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
    }

    /**
     * The scratch name is generated here from hex, never from user input, so
     * quoting is belt-and-braces — but a database name reaches SQL as an
     * identifier, where a placeholder cannot go, so it is quoted anyway.
     */
    private function quoteName(string $driver, string $name): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_]/', '', $name) ?? '';

        return match ($driver) {
            'mysql'  => '`' . $safe . '`',
            'sqlsrv' => '[' . $safe . ']',
            default  => '"' . $safe . '"',
        };
    }

    // ── Inspection ────────────────────────────────────────────────────────────

    /** @return list<string> */
    private function tablesIn(DatabaseTarget $target, string $scratch): array
    {
        try {
            $pdo = new \PDO(
                $this->databaseDsn($target, $scratch),
                (string) ($target->server['username'] ?? ''),
                (string) ($target->server['password'] ?? ''),
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
            );

            $sql = match ($target->driver) {
                'sqlite' => "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'",
                'mysql'  => 'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()',
                'pgsql'  => "SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = 'public'",
                'sqlsrv' => 'SELECT name FROM sys.tables',
                default  => throw new \InvalidArgumentException($target->driver),
            };

            $rows = $pdo->query($sql)?->fetchAll(\PDO::FETCH_COLUMN) ?: [];
            $out  = array_map('strval', $rows);
            sort($out);

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    private function databaseDsn(DatabaseTarget $target, string $scratch): string
    {
        $host = (string) ($target->server['host'] ?? '127.0.0.1');
        $port = (int) ($target->server['port'] ?? DatabaseMatrix::defaultPort($target->driver));
        $db   = $this->databaseValue($target, $scratch);

        return match ($target->driver) {
            'sqlite' => 'sqlite:' . $db,
            'mysql'  => "mysql:host={$host};port={$port};dbname={$db}",
            'pgsql'  => "pgsql:host={$host};port={$port};dbname={$db}",
            'sqlsrv' => "sqlsrv:Server={$host},{$port};Database={$db}",
            default  => throw new \InvalidArgumentException($target->driver),
        };
    }

    /**
     * After a full reset, only LetMigrate's own bookkeeping may remain.
     *
     * The tracking tables are created by install() and are not something a
     * migration's down() is expected to remove, so counting them as leftovers
     * would fail every plugin that rolls back perfectly.
     *
     * @param list<string> $tables
     */
    private function isClean(array $tables): bool
    {
        $bookkeeping = ['let_migrations', 'let_seeders', 'let_breakpoints'];

        foreach ($tables as $table) {
            if (!\in_array($table, $bookkeeping, true)) {
                return false;
            }
        }

        return true;
    }

    private function appliedCount(MigrationResult $result): int
    {
        return $result->appliedCount();
    }
}
