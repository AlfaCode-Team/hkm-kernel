<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Commands;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use AlfacodeTeam\Ground\PluginGround;
use AlfacodeTeam\Ground\PluginManifest;
use AlfacodeTeam\Ground\Inspection\PluginLocator;

/**
 * plugin:probe <name> — boot the plugin in an isolated ground and report what
 * the kernel actually produced.
 *
 * `plugin:check` reads the plugin. This RUNS it: the real BootPipeline against
 * fake ports, in a temp workspace. The two answer different questions, and the
 * one this answers is the more common: "why does this plugin not work when I
 * enable it". A boot failure here is the exact failure a project would get,
 * printed without having to wire a project up first.
 */
final class PluginProbeCommand extends AbstractCommand
{
    use ResolvesPlugin;

    protected function configure(): void
    {
        $this->name        = 'plugin:probe';
        $this->description = 'Boot a plugin in an isolated ground and report what compiled';

        $this->addArgument('name', 'Plugin name (default: the plugin you are standing in)');
        $this->addOption('routes', 'r', 'List every compiled route');
        $this->addOption('keep', 'k', 'Keep the temp workspace and print its path');
        $this->addOption('path', 'p', 'Search from this directory instead of the working directory', acceptsValue: true);
        $this->addOption('here', 'H', 'Only this directory and its plugins/ — skip sibling repos');
    }

    protected function handle(): int
    {
        $target = $this->resolvePlugin();

        if ($target === null) {
            return self::FAILURE;
        }

        $locator = $this->locator();
        $name    = $target->name();

        // A plugin whose requires[] are unregistered cannot boot — that is the
        // kernel's rule, not a harness limitation. Resolving the closure here
        // means `plugin:probe` works on a real plugin instead of only on
        // dependency-free ones.
        $dependencies = $this->resolveDependencies($target, $locator);
        if ($dependencies === null) {
            return self::FAILURE;
        }

        if ($this->hasOption('keep')) {
            $_ENV['GROUND_KEEP_WORKSPACE'] = 'true';
        }

        $this->section("Probing {$name}");
        $this->info('Provider : ' . $target->providerClass);
        $this->info('Solves   : ' . ($target->solves() ?: '—'));
        $this->info('Requires : ' . ($target->requires() === [] ? '—' : implode(', ', $target->requires())));

        if ($dependencies !== []) {
            $this->muted('  resolved to: ' . implode(', ', $dependencies));
        }

        try {
            $ground = PluginGround::for($target->providerClass, ...$dependencies)->boot();
        } catch (\Throwable $e) {
            $this->newLine();
            $this->alertError('Boot failed', [
                $e::class,
                $e->getMessage(),
            ]);
            $this->muted('This is the same failure a project would get when enabling the plugin.');

            return self::FAILURE;
        }

        try {
            $this->report($ground, $target);
        } finally {
            if ($this->hasOption('keep')) {
                $this->newLine();
                $this->muted('Workspace kept: ' . $ground->workspace()->root);
            }
            $ground->destroy();
        }

        return self::SUCCESS;
    }

    private function report(\AlfacodeTeam\Ground\BootedGround $ground, PluginManifest $target): void
    {
        $placeholders = $ground->placeholders();
        if ($placeholders !== []) {
            $this->newLine();
            $this->alertWarning('Required config with no default', [
                implode(', ', $placeholders),
                'The probe filled these with placeholders so the boot could proceed.',
                'A project enabling this plugin gets them as empty entries in .env and the boot stops there.',
            ]);
        }

        // Only this plugin's routes: the manifest also holds the routes of every
        // dependency pulled in, and listing those would misreport what the
        // plugin under test contributes.
        $scope  = $target->solves();
        $routes = array_filter(
            $ground->routes(),
            static fn(array $entry): bool => ($entry['solves'] ?? null) === $scope,
        );

        $this->newLine();
        $this->info('Routes compiled : ' . \count($routes));
        $this->info('Events declared : ' . ($target->emits() === [] ? '—' : implode(', ', $target->emits())));

        if ($routes !== [] && $this->hasOption('routes')) {
            $this->newLine();
            $rows = [];
            foreach ($routes as $key => $entry) {
                $rows[] = [
                    $key,
                    (string) ($entry['handler'] ?? '—'),
                    implode(',', (array) ($entry['filters'] ?? [])) ?: '—',
                ];
            }

            $this->table()->headers(['Route', 'Handler', 'Filters'])->rows($rows)->render();
        }

        // Resolving every exposed contract is the check worth running: a
        // contract that compiles but does not resolve fails in the CONSUMING
        // module at request time, a long way from the plugin that broke it.
        $exposes = $target->exposes();
        if ($exposes === []) {
            return;
        }

        $this->newLine();
        $this->info('Exposed contracts:');

        foreach ($exposes as $contract) {
            try {
                $instance = $ground->service($contract);
                $this->success('  ✓ ' . $contract . ' → ' . (\is_object($instance) ? $instance::class : \gettype($instance)));
            } catch (\Throwable $e) {
                $this->error('  ✗ ' . $contract);
                $this->muted('      ' . $e->getMessage());
            }
        }
    }

    /**
     * Providers for every domain in requires[], transitively.
     *
     * The walk itself lives on {@see PluginLocator::dependenciesFor()} because
     * `plugin:serve` needs exactly the same answer, and two copies of a
     * transitive-dependency walk is two chances to disagree about what a plugin
     * needs to boot.
     *
     * @return list<class-string>|null null when a required domain has no
     *                                 installed provider — reported, not guessed.
     */
    private function resolveDependencies(PluginManifest $target, PluginLocator $locator): ?array
    {
        ['providers' => $providers, 'missing' => $missing] = $locator->dependenciesFor($target);

        if ($missing !== []) {
            $this->error('No installed plugin provides: ' . implode(', ', $missing));
            $this->muted('Install them, or fix the requires[] entry — it must name a module\'s solves domain.');

            return null;
        }

        return $providers;
    }
}
