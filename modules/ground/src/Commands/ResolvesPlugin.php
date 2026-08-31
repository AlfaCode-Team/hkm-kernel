<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Commands;

use AlfacodeTeam\Ground\PluginManifest;
use AlfacodeTeam\Ground\Inspection\PluginLocator;

/**
 * "Which plugin do you mean?" — answered once, for every command.
 *
 * The answer is almost always "the one I am standing in", so that is the
 * default. Naming a plugin is for reaching a DIFFERENT one:
 *
 *     cd hkm-plugin-user && ground check          → user
 *     ground check view                           → view
 *
 * The four commands each resolved this themselves, with four copies of the
 * same not-found message and four chances to drift.
 */
trait ResolvesPlugin
{
    private ?PluginLocator $locator = null;

    /** The scan, honouring --path and --here. Memoized: the scan globs a workspace. */
    protected function locator(): PluginLocator
    {
        if ($this->locator !== null) {
            return $this->locator;
        }

        $path = (string) ($this->option('path') ?? '');

        return $this->locator = new PluginLocator(
            $path !== '' ? rtrim($path, '/') : (getcwd() ?: '.'),
            includeSiblings: !$this->hasOption('here'),
        );
    }

    /**
     * Re-root the scan at the plugin we settled on.
     *
     * Everything after this point asks the locator about the plugin's
     * NEIGHBOURS — which plugin solves a required domain, which one declares an
     * env var this one only reads. Those answers come from the search root, and
     * the search root was wherever you happened to be standing.
     *
     * So `ground check` from the repo root and the same command from `ui/`
     * disagreed about the same plugin: at the root the sibling checkouts are
     * one level up and `crypto` is found; from `ui/` they are two levels up and
     * it is not, so a variable owned by a dependency was reported as read by
     * nobody. Same plugin, same code, different verdict, decided by `cd`.
     *
     * Rebasing makes the answer a property of the plugin instead.
     */
    private function rebase(PluginManifest $plugin): PluginManifest
    {
        $this->locator = new PluginLocator(
            $plugin->directory(),
            includeSiblings: !$this->hasOption('here'),
        );

        return $plugin;
    }

    /**
     * The plugin this invocation is about, or null after reporting why not.
     *
     * @param string $argument the argument holding an explicit name
     */
    protected function resolvePlugin(string $argument = 'name'): ?PluginManifest
    {
        $locator = $this->locator();
        $name    = trim((string) ($this->argument($argument) ?? ''));

        // `.` — and any path that exists — means "the plugin here", written
        // out. `hkm ground check .` reads naturally and is what a shell user
        // reaches for; treating the dot as a plugin NAME would answer "no
        // plugin named '.'", which is technically true and useless.
        if ($name === '.' || $name === './' || (str_contains($name, '/') && is_dir($name))) {
            $at = (new PluginLocator(realpath($name) ?: $name))->current();

            if ($at !== null) {
                return $this->rebase($at);
            }

            $this->error("[{$name}] is not a plugin directory.");

            return null;
        }

        if ($name === '') {
            $current = $locator->current();

            if ($current !== null) {
                return $this->rebase($current);
            }

            $this->error('No plugin named, and this directory is not a plugin.');
            $this->muted('Either cd into a plugin, or name one: ' . implode(', ', array_keys($locator->all())));

            return null;
        }

        $found = $locator->find($name);

        if ($found !== null) {
            return $this->rebase($found);
        }

        $this->error("No plugin named '{$name}'.");

        // Where it looked matters more than the fact it found nothing: a plugin
        // repo one directory off looks identical to one that is not there.
        $known = array_keys($locator->all());

        if ($known !== []) {
            $this->muted('Installed: ' . implode(', ', $known));
        } else {
            $this->muted('Searched:');
            foreach ($locator->searchedPaths() as $glob) {
                $this->muted('  ' . $glob);
            }
        }

        return null;
    }
}
