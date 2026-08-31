<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Commands;

use AlfacodeTeam\Ground\Database\DatabaseMatrix;
use AlfacodeTeam\Ground\Database\MigrationHarness;
use AlfacodeTeam\Ground\Database\MigrationRun;
use AlfacodeTeam\PhpIoCli\AbstractCommand;

/**
 * plugin:migrate — run a plugin's migrations against every real database it
 * claims to support, then roll them back.
 *
 * ─── WHY REAL DATABASES ─────────────────────────────────────────────────────
 *
 * A migration is the one thing in a plugin that cannot be tested against a
 * fake. `FakeDatabase` records the SQL it was handed; it does not parse it, so
 * a statement MySQL accepts and PostgreSQL rejects passes every fake-backed
 * test that exists. `--pretend` is no better: it compiles the SQL and never
 * executes it, so an index name over the length limit, a column type one engine
 * lacks, and a foreign key pointing at a table the ordering has not yet created
 * all compile perfectly and fail on deploy.
 *
 * So this connects to actual servers. Which ones exist is a property of the
 * machine, so it is configured in a file that is NEVER committed — see
 * `--init`.
 *
 * ─── SKIPS ARE LOUD ─────────────────────────────────────────────────────────
 *
 * A driver that is not configured or not answering is reported as SKIPPED with
 * the reason, and the summary says how many of the four were actually
 * exercised. That line matters more than the ticks: a green run that only ever
 * saw SQLite tells you nothing about the MySQL you deploy to, and the failure
 * mode of a quiet skip is discovering that in production.
 *
 * Exit code is non-zero only when a REACHABLE database failed. A machine with
 * no MySQL is not a broken plugin — but `--strict` makes any skip a failure,
 * which is what CI should use.
 */
final class PluginMigrateCommand extends AbstractCommand
{
    use MigrationScaffold;
    use ResolvesPlugin;

    protected function configure(): void
    {
        $this->name        = 'plugin:migrate';
        $this->description = 'Run a plugin\'s migrations against every real database, then roll them back';

        $this->addArgument('name', 'Plugin name (default: the plugin you are standing in)');
        $this->addOption('init', 'i', 'Write the gitignored database config + docker compose file');
        $this->addOption('strict', 's', 'Fail when any supported database was skipped (for CI)');
        $this->addOption('only', 'o', 'Only these drivers, comma-separated (sqlite,mysql,pgsql,sqlsrv)', acceptsValue: true);
        $this->addOption('tables', 't', 'List the tables each database ended up with');
        $this->addOption('path', 'p', 'Search from this directory instead of the working directory', acceptsValue: true);
        $this->addOption('here', 'H', 'Only this directory and its plugins/ — skip sibling repos');
    }

    protected function handle(): int
    {
        $plugin = $this->resolvePlugin();

        if ($plugin === null) {
            return self::FAILURE;
        }

        if ($this->hasOption('init')) {
            return $this->writeDatabaseScaffold($plugin->directory());
        }

        $harness = new MigrationHarness($plugin->directory(), $this->migrationPaths($plugin));

        if ($harness->migrationPaths() === []) {
            $this->info("{$plugin->name()} ships no migrations — nothing to run.");

            return self::SUCCESS;
        }

        $matrix = DatabaseMatrix::discover($plugin->directory());
        $only   = $this->only();

        $this->section("Migrating {$plugin->name()}");
        foreach ($harness->migrationPaths() as $path) {
            $this->muted('  ' . $path);
        }
        $this->newLine();

        $runs = [];
        foreach ($matrix->targets as $target) {
            if ($only !== [] && !\in_array($target->driver, $only, true)) {
                continue;
            }

            $runs[] = $target->reachable
                ? $harness->run($target)
                : MigrationRun::skipped($target);
        }

        return $this->report($runs);
    }

    // ── Reporting ─────────────────────────────────────────────────────────────

    /** @param list<MigrationRun> $runs */
    private function report(array $runs): int
    {
        $failed = 0;
        $ran    = 0;

        foreach ($runs as $run) {
            $driver = str_pad($run->target->driver, 8);

            if (!$run->ran) {
                $this->muted("  · {$driver} SKIPPED — {$run->error}");

                continue;
            }

            $ran++;

            if ($run->error !== '') {
                $failed++;
                $this->error("  ✗ {$driver} {$run->error}");

                continue;
            }

            if (!$run->rolledBackClean) {
                $failed++;
                $this->error("  ✗ {$driver} applied {$run->applied}, but rollback left tables behind");
                $this->muted('      Every migration needs a down() that undoes its up() —');
                $this->muted('      `hkm plugins disable` rolls migrations back before deleting them.');

                continue;
            }

            $this->success("  ✓ {$driver} applied {$run->applied}, rolled back clean");

            if ($this->hasOption('tables') && $run->tables !== []) {
                $this->muted('      ' . implode(', ', $run->tables));
            }
        }

        $total   = \count($runs);
        $skipped = $total - $ran;

        $this->newLine();
        $this->info(sprintf('%d of %d database(s) exercised, %d failed.', $ran, $total, $failed));

        if ($skipped > 0) {
            // The most important line in the output. A green run that only saw
            // SQLite has not tested the engine anyone deploys to.
            $this->warning("{$skipped} database(s) were NOT tested. Run `plugin:migrate --init` to configure them.");
        }

        if ($failed > 0) {
            return self::FAILURE;
        }

        return $skipped > 0 && $this->hasOption('strict') ? self::FAILURE : self::SUCCESS;
    }

    // ── Inputs ────────────────────────────────────────────────────────────────

    /** @return list<string> */
    private function only(): array
    {
        $raw = (string) ($this->option('only') ?? '');

        if ($raw === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * Where this plugin keeps migrations.
     *
     * `database/migrations` is the published set. `database/tenant-template` is
     * the per-tenant schema a tenancy-aware plugin ships, and it is migrations
     * too — skipping it would leave the half most likely to drift untested.
     *
     * @return list<string>
     */
    private function migrationPaths(\AlfacodeTeam\Ground\PluginManifest $plugin): array
    {
        $declared = $plugin->data['migrations'] ?? null;

        if (\is_array($declared) && $declared !== []) {
            return array_values(array_map('strval', $declared));
        }
        if (\is_string($declared) && $declared !== '') {
            return [$declared];
        }

        return ['database/migrations', 'database/tenant-template'];
    }
}
