<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Commands;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use AlfacodeTeam\Ground\Contract\ContractLock;
use AlfacodeTeam\Ground\Contract\ContractSurface;
use AlfacodeTeam\Ground\Inspection\Finding;
use AlfacodeTeam\Ground\PluginManifest;

/**
 * contract:lock — write, check and retire this plugin's published strings.
 *
 *     ground contract:lock                 # first run: write the lock. After: check it.
 *     ground contract:lock --update        # record what is newly published
 *     ground contract:lock --deprecate X   # retire X, keeping the record
 *
 * WHY THE DEFAULT IS NOT `--update`
 * ---------------------------------
 * Writing the lock is the easy half, and a command that silently brought the
 * file up to date on every run would record a removal as readily as an
 * addition — the lock would always agree with the code, which is a lock that
 * says nothing. So an EXISTING lock is only ever read by default. Changing it
 * takes `--update` (add) or `--deprecate` (retire), and there is no flag that
 * removes a record at all.
 *
 * {@see ContractLock} for what is locked and why each kind qualifies.
 */
final class ContractLockCommand extends AbstractCommand
{
    use ResolvesPlugin;

    protected function configure(): void
    {
        $this->name        = 'contract:lock';
        $this->description = 'Record the strings this plugin publishes, so none can be removed by accident';

        $this->addArgument('name', 'Plugin name (default: the plugin you are standing in)');
        $this->addOption('update', 'u', 'Record everything newly published');
        $this->addOption('deprecate', 'D', 'Retire a value, keeping its record', acceptsValue: true);
        $this->addOption('check', 'c', 'Report only — never write (the default when a lock exists)');
        $this->addOption('path', 'p', 'Search from this directory instead of the working directory', acceptsValue: true);
        $this->addOption('here', 'H', 'Only this directory and its plugins/ — skip sibling repos');
    }

    protected function handle(): int
    {
        $plugin = $this->resolvePlugin();

        if ($plugin === null) {
            return self::FAILURE;
        }

        $surface = ContractSurface::of($plugin);
        $lock    = ContractLock::beside($plugin);

        if ($lock === null) {
            return $this->hasOption('check')
                ? $this->reportNoLock($plugin)
                : $this->create($plugin, $surface);
        }

        $retire = trim((string) ($this->option('deprecate') ?? ''));

        if ($retire !== '') {
            return $this->retire($lock, $surface, $retire);
        }

        return $this->hasOption('update')
            ? $this->update($lock, $surface)
            : $this->check($lock, $surface);
    }

    // ── First lock ────────────────────────────────────────────────────────────

    private function create(PluginManifest $plugin, ContractSurface $surface): int
    {
        if ($surface->isEmpty()) {
            $this->warning('Nothing to lock: this plugin publishes no domain, route name, event, contract or config key.');
            $this->muted('A lock over an empty surface would record nothing and check nothing.');

            return self::SUCCESS;
        }

        $lock = ContractLock::from($surface, ContractLock::pathFor($plugin));
        $lock->save();

        $this->newLine();
        $this->success('Wrote ' . ContractLock::FILE . ' — ' . $surface->count() . ' value(s) recorded.');
        $this->newLine();
        $this->renderSurface($surface);
        $this->newLine();
        $this->muted('Commit it. From now on `plugin:check` fails if any of these disappears,');
        $this->muted('and `--update` is what records the next one.');
        $this->newLine();

        return self::SUCCESS;
    }

    private function reportNoLock(PluginManifest $plugin): int
    {
        $this->muted('No ' . ContractLock::FILE . ' — this plugin has not opted in.');
        $this->muted('Run `ground contract:lock` in ' . $plugin->directory() . ' to write the first one.');

        return self::SUCCESS;
    }

    // ── Check ─────────────────────────────────────────────────────────────────

    private function check(ContractLock $lock, ContractSurface $surface): int
    {
        $findings = $lock->compare($surface);

        $this->newLine();

        if ($findings === []) {
            $this->success('Lock matches: ' . $lock->count() . ' value(s), nothing removed, nothing unrecorded.');
            $this->newLine();

            return self::SUCCESS;
        }

        foreach ($findings as $finding) {
            $this->error('  ✗ ' . $finding->message);

            if ($finding->fix !== '') {
                $this->muted('      ' . $finding->fix);
            }
            $this->muted('      [' . $finding->code . ']');
        }

        $this->newLine();
        $this->info(\count($findings) . ' contract problem(s).');
        $this->newLine();

        return self::FAILURE;
    }

    // ── Update ────────────────────────────────────────────────────────────────

    private function update(ContractLock $lock, ContractSurface $surface): int
    {
        $added = $lock->record($surface);

        // A removal is NOT fixed by --update, and saying so is the whole value
        // of this branch: the one thing a developer reaches for when the check
        // goes red is this flag, and it cannot make a removal go away.
        $removals = array_values(array_filter(
            $lock->compare($surface),
            static fn (Finding $f): bool => $f->code === 'contract.removed',
        ));

        $this->newLine();

        if ($added === []) {
            $this->muted('Nothing new to record.');
        } else {
            $lock->save();

            foreach ($added as $kind => $values) {
                foreach ($values as $value) {
                    $this->success('  + ' . $kind . '  ' . $value);
                }
            }

            $this->newLine();
            $this->info('Recorded ' . array_sum(array_map('count', $added)) . ' new value(s).');
        }

        if ($removals !== []) {
            $this->newLine();
            $this->error('Still ' . \count($removals) . ' removal(s) — --update cannot record an absence:');

            foreach ($removals as $finding) {
                $this->error('  ✗ ' . $finding->message);
            }

            $this->newLine();
            $this->muted('Restore the value, or retire it with --deprecate.');
            $this->newLine();

            return self::FAILURE;
        }

        $this->newLine();

        return self::SUCCESS;
    }

    // ── Deprecate ─────────────────────────────────────────────────────────────

    private function retire(ContractLock $lock, ContractSurface $surface, string $value): int
    {
        $kinds = $lock->deprecate($value);

        $this->newLine();

        if ($kinds === []) {
            $this->error("[{$value}] is not in the lock, so there is nothing to retire.");
            $this->muted('Only a value that was PUBLISHED can be retired. Check the spelling against '
                . ContractLock::FILE . '.');
            $this->newLine();

            return self::FAILURE;
        }

        $lock->save();

        foreach ($kinds as $kind) {
            $this->success('  retired  ' . $kind . '  ' . $value);
        }

        $this->newLine();
        $this->muted('The record stays in surfaces, so the string can never be reused for something else.');

        if ($surface->has($kinds[0], $value)) {
            $this->muted('It is still published, which is fine — deprecating it only stops requiring it.');
        }

        $this->newLine();

        return self::SUCCESS;
    }

    // ── Rendering ─────────────────────────────────────────────────────────────

    private function renderSurface(ContractSurface $surface): void
    {
        foreach (ContractSurface::KINDS as $kind) {
            $values = $surface->kind($kind);

            if ($values === []) {
                continue;
            }

            $this->muted('  ' . str_pad($kind, 12) . implode(', ', $values));
        }
    }
}
