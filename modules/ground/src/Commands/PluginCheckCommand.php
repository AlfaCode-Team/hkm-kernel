<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Commands;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use AlfacodeTeam\Ground\Inspection\Finding;
use AlfacodeTeam\Ground\Inspection\Inspector;
use AlfacodeTeam\Ground\Inspection\PluginLocator;

/**
 * plugin:check [name] — static conformance checks, without booting anything.
 *
 * With no name, checks every installed plugin. Exits 1 when any ERROR is found,
 * so it gates CI; warnings and notes report and exit 0.
 */
final class PluginCheckCommand extends AbstractCommand
{
    use ResolvesPlugin;

    protected function configure(): void
    {
        $this->name        = 'plugin:check';
        $this->description = 'Check a plugin against the GDA rules the boot cannot catch';

        $this->addArgument('name', 'Plugin name (default: the plugin you are standing in)');
        $this->addOption('all', 'a', 'Check every installed plugin');
        $this->addOption('strict', 's', 'Treat warnings as failures too');
        $this->addOption('json', 'j', 'Machine-readable output');
        $this->addOption('path', 'p', 'Search from this directory instead of the working directory', acceptsValue: true);
        $this->addOption('here', 'H', 'Only this directory and its plugins/ — skip sibling repos');
    }

    protected function handle(): int
    {
        $locator = $this->locator();
        $targets = $this->targets($locator);

        if ($targets === null) {
            return self::FAILURE;
        }

        // Re-fetched, NOT the $locator from above: resolving the target rebases
        // the scan onto the plugin's own directory, and the neighbours have to
        // come from there. Reusing the earlier instance is what made `ground
        // check` from `ui/` disagree with the same command from the repo root.
        $neighbours = $this->locator()->byDomain();

        $results = [];
        foreach ($targets as $pluginName => $manifest) {
            $results[$pluginName] = (new Inspector($manifest, $neighbours))->run();
        }

        return $this->hasOption('json')
            ? $this->renderJson($results)
            : $this->renderHuman($results);
    }

    /**
     * What to check: this plugin, a named one, or everything.
     *
     * Bare `plugin:check` used to mean EVERY installed plugin, which from
     * inside a plugin you are working on prints 31 reports and buries the one
     * you asked about. It now means "this one", and `--all` is the sweep. From
     * a project, where the working directory is not a plugin, bare still means
     * all — there is nothing else it could sensibly mean there.
     *
     * @return array<string, PluginManifest>|null null after reporting why
     */
    private function targets(PluginLocator $locator): ?array
    {
        if ($this->hasOption('all')) {
            $all = $locator->all();

            if ($all === []) {
                $this->error('No plugins found.');
                $this->muted('Searched:');
                foreach ($locator->searchedPaths() as $glob) {
                    $this->muted('  ' . $glob);
                }

                return null;
            }

            return $all;
        }

        $name = trim((string) ($this->argument('name') ?? ''));

        // `.` is explicit for "here" and never means "everything".
        if ($name === '' && $locator->current() === null) {
            return $this->targetsFromWholeWorkspace($locator);
        }

        $target = $this->resolvePlugin();

        return $target === null ? null : [$target->name() => $target];
    }

    /** @return array<string, PluginManifest>|null */
    private function targetsFromWholeWorkspace(PluginLocator $locator): ?array
    {
        $all = $locator->all();

        if ($all === []) {
            $this->error('No plugins found, and this directory is not a plugin.');
            $this->muted('Searched:');
            foreach ($locator->searchedPaths() as $glob) {
                $this->muted('  ' . $glob);
            }

            return null;
        }

        return $all;
    }

    /** @param array<string, list<Finding>> $results */
    private function renderHuman(array $results): int
    {
        $errors = $warnings = $notes = 0;

        foreach ($results as $plugin => $findings) {
            $this->newLine();
            $this->section($plugin);

            if ($findings === []) {
                $this->success('  Clean.');

                continue;
            }

            foreach ($findings as $finding) {
                if ($finding->severity === Finding::ERROR) {
                    $this->error('  ✗ ' . $finding->message);
                    $errors++;
                } elseif ($finding->severity === Finding::WARNING) {
                    $this->warning('  ! ' . $finding->message);
                    $warnings++;
                } else {
                    $this->muted('  · ' . $finding->message);
                    $notes++;
                }

                if ($finding->fix !== '') {
                    $this->muted('      ' . $finding->fix);
                }
                $this->muted('      [' . $finding->code . ']');
            }
        }

        $this->newLine();
        $this->info(sprintf('%d error(s), %d warning(s), %d note(s).', $errors, $warnings, $notes));

        if ($errors > 0) {
            return self::FAILURE;
        }

        // --strict exists because manifest drift is not an error today and IS a
        // bug tomorrow: a Provider that disagrees with its manifest is wrong the
        // moment anyone reads the method. Teams that want that gated can gate it.
        return $warnings > 0 && $this->hasOption('strict') ? self::FAILURE : self::SUCCESS;
    }

    /** @param array<string, list<Finding>> $results */
    private function renderJson(array $results): int
    {
        $payload = [];
        $errors  = 0;

        foreach ($results as $plugin => $findings) {
            $payload[$plugin] = array_map(
                static fn(Finding $f): array => [
                    'severity' => $f->severity,
                    'code'     => $f->code,
                    'message'  => $f->message,
                    'fix'      => $f->fix,
                    'file'     => $f->file,
                ],
                $findings,
            );

            $errors += \count(array_filter($findings, static fn(Finding $f): bool => $f->blocking()));
        }

        $this->info((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
