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
    public function __construct(
        private readonly string $pluginDirectory,
        /** @var list<string> migration directories, relative to the plugin */
        private readonly array $paths = ['database/migrations'],
    ) {}

    /** Absolute migration paths that exist and hold at least one file. */
    public function migrationPaths(): array
    {
        $found = [];

        foreach ($this->paths as $rel) {
            $dir = rtrim($this->pluginDirectory, '/') . '/' . trim($rel, '/');

            if (is_dir($dir) && glob($dir . '/*.php') !== []) {
                $found[] = $dir;
            }
        }

        return $found;
    }

    public function run(DatabaseTarget $target): MigrationRun
    {
        if (!$target->reachable) {
            return MigrationRun::skipped($target);
        }

        $paths = $this->migrationPaths();

        if ($paths === []) {
            return MigrationRun::failed($target, 'no migrations found under ' . implode(', ', $this->paths));
        }

        $scratch = $this->scratchName();

        try {
            $this->createDatabase($target, $scratch);
        } catch (\Throwable $e) {
            return MigrationRun::failed($target, 'could not create the scratch database: ' . $e->getMessage());
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

            // Now the direction nobody tests. reset() unwinds every migration
            // through its own down().
            $service->reset();

            $after = $this->tablesIn($target, $scratch);
            $clean = $this->isClean($after);

            return MigrationRun::passed($target, $applied, $tables, $clean);
        } catch (\Throwable $e) {
            return MigrationRun::failed($target, $e->getMessage());
        } finally {
            $this->dropDatabase($target, $scratch);
        }
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
