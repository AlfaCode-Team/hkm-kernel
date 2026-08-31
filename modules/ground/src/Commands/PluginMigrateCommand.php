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
        $this->addOption('with', 'w', 'Extra plugin NAMES whose migrations must run first, comma-separated', acceptsValue: true);
        $this->addOption('alone', 'a', 'Run ONLY this plugin\'s migrations — no dependency schema');
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

        $chain  = $this->migrationChain($plugin);
        $layers = $this->layers($chain);

        if ($layers === []) {
            $this->info("{$plugin->name()} ships no migrations — nothing to run.");

            return self::SUCCESS;
        }

        $matrix = DatabaseMatrix::discover($plugin->directory());
        $only   = $this->only();

        $this->section("Migrating {$plugin->name()}");

        if (\count($chain) > 1) {
            $others = array_slice(array_map(static fn($m): string => $m->name(), $chain), 0, -1);
            $this->info('With schema from: ' . implode(' → ', $others) . " → {$plugin->name()}");
        }

        $runs = [];

        foreach ($layers as $harness) {
            // Each layer is its own database, announced separately: "central"
            // and "tenant" are different deployments, and a run that merges
            // them proves nothing about either.
            $this->newLine();
            $this->info(($harness->layer === 'tenant' ? 'Tenant' : 'Central') . ' database');

            foreach ($harness->migrationPaths() as $path) {
                $this->muted('  ' . $path);
            }

            foreach ($harness->seederPaths() as $path) {
                $this->muted('  seed: ' . $path);
            }

            $this->newLine();

            foreach ($matrix->targets as $target) {
                if ($only !== [] && !\in_array($target->driver, $only, true)) {
                    continue;
                }

                $runs[] = $target->reachable
                    ? $harness->run($target)
                    : MigrationRun::skipped($target, $harness->layer);
            }
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
            // The layer is part of the identity of a result: "mysql passed" is
            // ambiguous once there are two databases per driver.
            $driver = str_pad($run->layer === 'tenant' ? $run->target->driver . '/tenant' : $run->target->driver, 15);

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

            $seeded = $run->seeded > 0 ? ", seeded {$run->seeded}" : '';
            $this->success("  ✓ {$driver} applied {$run->applied}{$seeded}, rolled back clean");

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
     * The two schemas a tenancy-aware platform actually deploys.
     *
     * ─── WHY THEY MUST NOT SHARE A DATABASE ─────────────────────────────────
     *
     * `database/migrations` builds the CENTRAL database — the one that holds
     * `users`, `tenants`, the registry. `database/tenant-template` builds ONE
     * TENANT's database, and every tenant gets a fresh copy of it.
     *
     * Running both into a single scratch database made ground the only place in
     * the world where those tables coexist. A tenant-template migration that
     * declares `foreign('user_id')->on('users')` — and one does — then compiled
     * and applied happily here, while in production `users` lives in a
     * different database entirely, where no engine can point a foreign key.
     * Ground reported a green tick for a schema that cannot be deployed.
     *
     * So each layer gets its OWN scratch database, created and dropped
     * independently. The tenant layer is skipped for a plugin that ships no
     * tenant-template, which is most of them.
     */
    private const CENTRAL_MIGRATIONS = ['database/migrations'];
    private const TENANT_MIGRATIONS  = ['database/tenant-template'];
    private const CENTRAL_SEEDERS    = ['database/seeders'];
    private const TENANT_SEEDERS     = ['database/tenant-seeders'];

    /**
     * @param  list<\AlfacodeTeam\Ground\PluginManifest> $chain
     * @return list<MigrationHarness>
     */
    private function layers(array $chain): array
    {
        $directories = array_map(static fn($m): string => $m->directory(), $chain);

        $harnesses = [
            new MigrationHarness($directories, self::CENTRAL_MIGRATIONS, self::CENTRAL_SEEDERS, 'central'),
            new MigrationHarness($directories, self::TENANT_MIGRATIONS, self::TENANT_SEEDERS, 'tenant'),
        ];

        // A layer nothing contributes to is not a layer.
        return array_values(array_filter(
            $harnesses,
            static fn(MigrationHarness $h): bool => $h->migrationPaths() !== [],
        ));
    }

    /**
     * Every plugin whose migrations must run, DEPENDENCIES FIRST.
     *
     * A plugin's schema does not stop at its own tables: tenancy's
     * `user_tenants` carries a foreign key onto `users`, which the User plugin
     * owns. Running tenancy's migrations alone therefore rehearses something no
     * project ever does, and fails for a reason that is not tenancy's fault.
     *
     * The chain is the same transitive `requires[]` walk that decides which
     * plugins a REQUEST loads, so it needs no second registry and cannot
     * disagree with what `ground serve` boots. Two escape hatches:
     *
     *   --with   name a plugin the manifest does NOT declare. Tenancy is the
     *            live example: it references `users` without requiring
     *            `user.management`, so nothing could infer that dependency.
     *   --alone  the old behaviour, for a plugin whose migrations really are
     *            self-contained and where the extra tables are only noise.
     *
     * Plugins carrying no migrations are dropped here rather than in the
     * harness, so the printed chain lists only what actually contributes.
     *
     * @return list<\AlfacodeTeam\Ground\PluginManifest> the plugin under test LAST
     */
    private function migrationChain(\AlfacodeTeam\Ground\PluginManifest $plugin): array
    {
        if ($this->hasOption('alone')) {
            return [$plugin];
        }

        $locator = $this->locator();

        // dependenciesFor() answers in PROVIDER classes; the manifests are what
        // carry a directory, so index them by provider once.
        $byProvider = [];
        foreach ($locator->all() as $manifest) {
            $byProvider[$manifest->providerClass] = $manifest;
        }

        $chain = [];

        $byDomain = $locator->byDomain();

        $collect = static function ($manifest) use (&$collect, &$chain, $locator, $byProvider, $byDomain): void {
            if (isset($chain[$manifest->name()])) {
                return;
            }

            $chain[$manifest->name()] = $manifest;

            foreach ($locator->dependenciesFor($manifest)['providers'] as $provider) {
                if (isset($byProvider[$provider])) {
                    $dependency = $byProvider[$provider];
                    $chain[$dependency->name()] ??= $dependency;
                }
            }

            // Schema-only dependencies, declared as module.json
            // "migrationRequires". Walked RECURSIVELY, and through the same
            // guard above, so a chain of them terminates on a cycle.
            foreach ($manifest->migrationRequires() as $domain) {
                if (isset($byDomain[$domain])) {
                    $collect($byDomain[$domain]);
                }
            }
        };

        foreach ($this->extraPlugins() as $name) {
            $extra = $locator->find($name);

            if ($extra === null) {
                $this->warning("--with names '{$name}', which is not installed — skipping it.");

                continue;
            }

            $collect($extra);
        }

        $collect($plugin);

        $relative = $this->migrationPaths($plugin);
        $carries  = static function ($manifest) use ($relative): bool {
            foreach ($relative as $rel) {
                $dir = rtrim($manifest->directory(), '/') . '/' . trim($rel, '/');

                if (is_dir($dir) && glob($dir . '/*.php') !== []) {
                    return true;
                }
            }

            return false;
        };

        // The plugin under test goes LAST, so its own tables meet a schema that
        // is already whole.
        unset($chain[$plugin->name()]);

        return [...array_values(array_filter($chain, $carries)), $plugin];
    }

    /** @return list<string> */
    private function extraPlugins(): array
    {
        $with = (string) ($this->option('with') ?? '');

        if ($with === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $with))));
    }

    /**
     * Where this plugin keeps migrations.
     *
     * Both LAYERS, for the chain walk and for `--alone`. The two are run into
     * SEPARATE databases (see layers()); this is only the union used to decide
     * whether a plugin contributes any schema at all.
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

        return [...self::CENTRAL_MIGRATIONS, ...self::TENANT_MIGRATIONS];
    }
}
