<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Commands;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use AlfacodeTeam\Ground\Database\DatabaseMatrix;
use AlfacodeTeam\Ground\Database\DatabaseTarget;

/**
 * plugin:drop — remove every scratch database ground left behind.
 *
 * `plugin:migrate` creates `ground_<random>` per layer per driver and drops it
 * in a `finally`, so in the normal case there is nothing here to do. Three
 * cases break that: `GROUND_KEEP_DATABASE=1` (kept on purpose, to inspect a
 * failure), a run killed hard enough to skip the finally, and a crash inside
 * the drop itself.
 *
 * Each leftover is a whole database sitting on a developer's server, named
 * unrecognisably. So this exists to make cleaning up a command rather than a
 * research project — and it only ever touches names matching ground's own
 * prefix, because dropping a database on a hunch is not a thing a tool should
 * be able to do.
 */
final class PluginDropCommand extends AbstractCommand
{
    use ResolvesPlugin;

    /** The prefix MigrationHarness::scratchName() gives every database it makes. */
    private const PREFIX = 'ground_';

    protected function configure(): void
    {
        $this->name        = 'plugin:drop';
        $this->description = 'Drop the scratch databases a previous `ground migrate` left behind';

        $this->addArgument('name', 'Plugin name (default: the plugin you are standing in)');
        $this->addOption('path', 'p', 'Search from this directory instead of the working directory', acceptsValue: true);
        $this->addOption('only', 'o', 'Only these drivers, comma-separated', acceptsValue: true);
        $this->addOption('list', 'l', 'Show what would be dropped and stop');
    }

    protected function handle(): int
    {
        $plugin = $this->resolvePlugin();

        if ($plugin === null) {
            return self::FAILURE;
        }

        $matrix = DatabaseMatrix::discover($plugin->directory());
        $only   = $this->only();
        $total  = 0;

        $this->section('Scratch databases');

        foreach ($matrix->targets as $target) {
            if ($only !== [] && !\in_array($target->driver, $only, true)) {
                continue;
            }

            if (!$target->reachable) {
                $this->muted("  · {$target->driver} SKIPPED — {$target->skipReason}");

                continue;
            }

            $found = $this->leftovers($target);

            if ($found === []) {
                $this->muted("  · {$target->driver} nothing left behind");

                continue;
            }

            foreach ($found as $name) {
                if ($this->hasOption('list')) {
                    $this->info("  would drop  {$target->driver}  {$name}");

                    continue;
                }

                try {
                    $this->drop($target, $name);
                    $this->success("  dropped  {$target->driver}  {$name}");
                    $total++;
                } catch (\Throwable $e) {
                    $this->error("  could not drop {$name}: " . $e->getMessage());
                }
            }
        }

        $this->newLine();

        if ($this->hasOption('list')) {
            $this->muted('Nothing was dropped — remove --list to do it.');

            return self::SUCCESS;
        }

        $this->info($total === 0 ? 'Nothing to drop.' : "Dropped {$total} database(s).");

        return self::SUCCESS;
    }

    /**
     * Leftovers on one target, by ground's prefix and nothing else.
     *
     * @return list<string>
     */
    private function leftovers(DatabaseTarget $target): array
    {
        if ($target->driver === 'sqlite') {
            // SQLite scratch databases are files in the temp directory, named
            // by the same prefix.
            return array_map(
                static fn(string $path): string => basename($path),
                glob(sys_get_temp_dir() . '/' . self::PREFIX . '*.sqlite') ?: [],
            );
        }

        $pdo = new \PDO(
            DatabaseMatrix::serverDsn($target->driver, $target->server),
            (string) ($target->server['username'] ?? ''),
            (string) ($target->server['password'] ?? ''),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );

        $sql = match ($target->driver) {
            'mysql'  => "SELECT schema_name FROM information_schema.schemata WHERE schema_name LIKE '" . self::PREFIX . "%'",
            'pgsql'  => "SELECT datname FROM pg_database WHERE datname LIKE '" . self::PREFIX . "%'",
            'sqlsrv' => "SELECT name FROM sys.databases WHERE name LIKE '" . self::PREFIX . "%'",
            default  => throw new \InvalidArgumentException($target->driver),
        };

        return array_map('strval', $pdo->query($sql)->fetchAll(\PDO::FETCH_COLUMN) ?: []);
    }

    private function drop(DatabaseTarget $target, string $name): void
    {
        // Belt and braces: never act on a name that is not ground's own, even
        // though the queries above already filter by prefix.
        if (!str_starts_with($name, self::PREFIX)) {
            throw new \RuntimeException("refusing to drop '{$name}' — not a ground scratch database");
        }

        if ($target->driver === 'sqlite') {
            @unlink(sys_get_temp_dir() . '/' . $name);

            return;
        }

        $pdo = new \PDO(
            DatabaseMatrix::serverDsn($target->driver, $target->server),
            (string) ($target->server['username'] ?? ''),
            (string) ($target->server['password'] ?? ''),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );

        $quoted = match ($target->driver) {
            'mysql'  => '`' . str_replace('`', '``', $name) . '`',
            'pgsql'  => '"' . str_replace('"', '""', $name) . '"',
            'sqlsrv' => '[' . str_replace(']', ']]', $name) . ']',
            default  => throw new \InvalidArgumentException($target->driver),
        };

        if ($target->driver === 'pgsql') {
            // Postgres refuses to drop a database anything is connected to.
            $pdo->exec(
                'SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '
                . $pdo->quote($name) . ' AND pid <> pg_backend_pid()',
            );
        }

        if ($target->driver === 'sqlsrv') {
            $pdo->exec("ALTER DATABASE {$quoted} SET SINGLE_USER WITH ROLLBACK IMMEDIATE");
        }

        $pdo->exec("DROP DATABASE IF EXISTS {$quoted}");
    }

    /** @return list<string> */
    private function only(): array
    {
        $raw = (string) ($this->option('only') ?? '');

        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}
