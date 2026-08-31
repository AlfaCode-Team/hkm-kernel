<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Commands;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use AlfacodeTeam\Ground\Ui\DevWorkspace;

/**
 * plugin:dev — make `yarn dev` work inside a plugin.
 *
 * `plugin:serve` renders a plugin's PAGES; this makes the React in them live.
 * It generates the Vite dev workspace (config, one entry per surface declared
 * in ui.json, the `dev` script) and then starts the dev server.
 *
 * ─── THE TWO HALVES ─────────────────────────────────────────────────────────
 *
 *   hkm ground serve .     PHP: routes, controllers, the page object
 *   yarn dev               Vite: the modules, with HMR
 *
 * They do not proxy to each other. PHP renders the layout and — while the dev
 * server's hot file exists — points the browser straight at Vite for the
 * modules. So both run, you browse the PHP port, and a saved .tsx applies
 * without a reload.
 *
 * Everything generated is gitignored: the aliases are absolute paths to the
 * sibling checkouts on this machine, which is exactly what must not be
 * committed with a plugin.
 */
final class PluginDevCommand extends AbstractCommand
{
    use ResolvesPlugin;

    protected function configure(): void
    {
        $this->name        = 'plugin:dev';
        $this->description = 'Generate the Vite dev workspace and start `yarn dev` for a plugin UI';

        $this->addArgument('name', 'Plugin name (default: the plugin you are standing in)');
        $this->addOption('path', 'p', 'Search from this directory instead of the working directory', acceptsValue: true);
        $this->addOption('setup', 's', 'Write the workspace and stop — do not start the dev server');
        $this->addOption('surface', 'u', 'Which surface to serve (default: the first in ui.json)', acceptsValue: true);
    }

    protected function handle(): int
    {
        $target = $this->resolvePlugin();

        if ($target === null) {
            return self::FAILURE;
        }

        $dev  = DevWorkspace::for($target->directory(), $this->locator());
        $name = $target->name();

        $this->section("Dev UI for {$name}");

        if (($why = $dev->unsupportedReason()) !== null) {
            // Not an error: most plugins ship no frontend, and saying so plainly
            // is more useful than a stack trace from a dev server with nothing
            // to serve.
            $this->warning("{$name} has no Pageflow UI to serve — {$why}.");
            $this->muted('A plugin needs ui/ui.json with "surfaces" and a Pages/ directory under each.');

            return self::SUCCESS;
        }

        foreach ($dev->generate() as $written) {
            $this->success("wrote  {$written}");
        }

        $surfaces = $dev->surfaces;
        $surface  = (string) ($this->option('surface') ?? $surfaces[0]);

        if (!\in_array($surface, $surfaces, true)) {
            $this->error("'{$surface}' is not a surface in ui.json. Declared: " . implode(', ', $surfaces) . '.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Surfaces  : ' . implode(', ', $surfaces));
        $this->info('Serving   : ' . $surface);
        $this->info('Hot file  : ' . $dev->publicPath() . "/{$surface}-hot");

        if (!is_dir($target->directory() . '/ui/node_modules')) {
            $this->newLine();
            $this->warning('ui/node_modules is missing — run `npm install` in ui/ first.');
            $this->muted('`ground init` installs it, or `ground new ui-test --config` writes package.json.');

            return self::FAILURE;
        }

        if ($this->hasOption('setup')) {
            $this->newLine();
            $this->muted('Workspace written. Start it with:  cd ui && yarn dev');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->muted('Run `hkm ground serve .` in another terminal and browse the PHP port,');
        $this->muted('not this one: PHP renders the page and loads its modules from here.');
        $this->muted('Ctrl-C to stop.');
        $this->newLine();

        return $this->runViteIn($target->directory() . '/ui', $surface);
    }

    /**
     * Hand off to the package manager the plugin actually uses.
     *
     * `yarn dev` is what was asked for, but a plugin whose lockfile is npm's
     * would install a second, divergent tree the moment yarn ran — so the
     * lockfile decides, and the script it runs is the same either way.
     */
    private function runViteIn(string $uiDirectory, string $surface): int
    {
        $runner = match (true) {
            is_file($uiDirectory . '/yarn.lock')         => 'yarn',
            is_file($uiDirectory . '/pnpm-lock.yaml')    => 'pnpm',
            default                                      => 'npm',
        };

        // `npm run <script> -- <args>` needs the separator to pass anything
        // through; yarn and pnpm forward the tail as-is.
        $command = sprintf(
            'cd %s && %s run dev %s--mode %s',
            escapeshellarg($uiDirectory),
            $runner,
            $runner === 'npm' ? '-- ' : '',
            escapeshellarg($surface),
        );

        passthru($command, $exitCode);

        return $exitCode === 0 ? self::SUCCESS : self::FAILURE;
    }
}
