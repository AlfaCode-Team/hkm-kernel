<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Inspection;

use AlfacodeTeam\Ground\PluginManifest;

/**
 * Find installed plugins and their Provider classes.
 *
 * The Provider class is read from the `namespace` declaration in Provider.php
 * rather than derived from the directory name. Directory names vary by install
 * route — `plugins/Task/` in a project, `hkm-plugin-task/` in a development
 * checkout, `vendor/alfacode-team/hkm-plugin-task/` via composer — while the
 * namespace inside the file is the same in all three. A convention-based guess
 * would work in exactly one of them.
 *
 * ─── WHERE IT LOOKS ─────────────────────────────────────────────────────────
 *
 * Four layouts, searched NEAREST-FIRST so the copy you are standing in wins
 * over a stale sibling or a vendored release of the same plugin:
 *
 *   1. the root itself           — you are INSIDE a plugin repo (module.json at
 *                                  the top). This is the layout a plugin author
 *                                  works in all day, and searching only 2-4
 *                                  meant `plugin:check` answered "No plugins
 *                                  found" from inside a plugin.
 *   2. root/plugins, root/modules — a project or the kernel checkout
 *   3. the PARENT's children     — a plugin-development workspace, where every
 *                                  plugin is a sibling repo:
 *
 *                                      PLUGINS/PHP/
 *                                        hkm-plugin-ground/   ← cwd
 *                                        hkm-plugin-view/
 *                                        hkm-plugin-user/
 *
 *                                  This is what makes cross-plugin work
 *                                  possible before anything is published:
 *                                  `plugin:probe user` can resolve
 *                                  `auth.identity` to the checkout next door
 *                                  instead of demanding a composer install.
 *   4. root/vendor/{a}/{b}       — plugins installed the ordinary way
 *
 * ─── AND WHY IT AUTOLOADS THEM ──────────────────────────────────────────────
 *
 * A discovered plugin is useless if its Provider cannot be CONSTRUCTED: the
 * inspector's Provider/manifest drift check needs the class, and `plugin:probe`
 * has to boot it. A sibling repo is not in this process's composer autoloader
 * and never will be — it is a different package with its own vendor/.
 *
 * So each discovered plugin gets a PSR-4 mapping registered from the namespace
 * in its Provider.php to its own directory. That mapping is not a guess: it is
 * the same `"Plugins\\X\\": ""` every one of these plugins declares in its own
 * composer.json. The autoloader is APPENDED, so a plugin that IS properly
 * installed still resolves through composer first and nothing is shadowed.
 */
final class PluginLocator
{
    /** Directories, relative to a search root, that hold modules. */
    private const MODULE_DIRS = ['plugins', 'modules'];

    /** @var array<string, string> namespace prefix => directory, already registered */
    private static array $autoloadPrefixes = [];

    private static bool $autoloaderRegistered = false;

    /** @var array<string, PluginManifest>|null memoized scan */
    private ?array $found = null;

    /** @var list<string> the globs the last scan actually used, for a useful failure message */
    private array $searched = [];

    public function __construct(
        private readonly string $root,
        /** Scan the parent directory's children — the sibling-repo workspace layout. */
        private readonly bool $includeSiblings = true,
    ) {}

    public static function fromCwd(): self
    {
        return new self(getcwd() ?: '.');
    }

    /** Scan somewhere specific — what `--path` on the commands passes. */
    public static function at(string $path): self
    {
        return new self(rtrim($path, '/') ?: '/');
    }

    /**
     * Every plugin found, keyed by its module.json "name".
     *
     * @return array<string, PluginManifest>
     */
    public function all(): array
    {
        if ($this->found !== null) {
            return $this->found;
        }

        $found          = [];
        $this->searched = [];

        foreach ($this->globs() as $glob) {
            $this->searched[] = $glob;

            foreach (glob($glob) ?: [] as $path) {
                $directory = \dirname($path);
                $provider  = $this->providerClassIn($directory);
                if ($provider === null) {
                    continue;
                }

                try {
                    $manifest = PluginManifest::fromPath($provider, $path);
                } catch (\InvalidArgumentException) {
                    // Unreadable or malformed JSON: `plugin:check` reports that
                    // for a NAMED plugin, but it must not stop a listing.
                    continue;
                }

                // ??= is the nearest-first rule: an earlier glob is a closer
                // copy of the same plugin, and it keeps the name.
                $found[$manifest->name()] ??= $manifest;
            }
        }

        ksort($found);

        return $this->found = $found;
    }

    /**
     * The plugin you are STANDING IN, if any.
     *
     * Walks up from the root looking for a module.json beside a Provider.php.
     * Every command takes a plugin name, and while developing one the name is
     * almost always "this one" — having to type it, correctly, from inside the
     * directory that already says what it is, is friction for nothing.
     *
     * Walking up rather than checking only the root matters: you are as often
     * in `ui/` or `tests/` as at the top.
     */
    public function current(): ?PluginManifest
    {
        $dir = rtrim($this->root, '/');

        // A bounded walk. Without the stop it would climb to / and could pick up
        // an unrelated module.json from a parent of the whole workspace.
        for ($depth = 0; $depth < 6 && $dir !== '' && $dir !== '/'; $depth++) {
            if (is_file($dir . '/module.json')) {
                $provider = $this->providerClassIn($dir);

                if ($provider !== null) {
                    try {
                        return PluginManifest::fromPath($provider, $dir . '/module.json');
                    } catch (\InvalidArgumentException) {
                        return null;
                    }
                }
            }

            $dir = \dirname($dir);
        }

        return null;
    }

    /** One plugin by its module.json name, or null. */
    public function find(string $name): ?PluginManifest
    {
        return $this->all()[$name] ?? null;
    }

    /**
     * Installed plugins indexed by the domain they solve — the index
     * `plugin:probe` resolves a requires[] entry through.
     *
     * @return array<string, PluginManifest>
     */
    public function byDomain(): array
    {
        $index = [];

        foreach ($this->all() as $manifest) {
            if ($manifest->solves() !== '') {
                $index[$manifest->solves()] ??= $manifest;
            }
        }

        return $index;
    }

    /**
     * Providers for every domain this plugin requires, transitively.
     *
     * Route-level requires[] count as well as module-level. They are just as
     * hard a boot dependency — the route compiler validates every one and fails
     * on an unknown domain — but they never appear in the module's own
     * requires[], so walking only that missed Auth's `http.pageflow` and
     * reported a boot failure the caller had caused itself.
     *
     * Missing domains are RETURNED, not thrown on and not guessed at: the
     * caller decides whether an unresolvable dependency is fatal (probe, serve)
     * or merely worth reporting.
     *
     * @return array{providers: list<class-string>, missing: list<string>}
     */
    public function dependenciesFor(PluginManifest $target): array
    {
        $byDomain = $this->byDomain();
        $resolved = [];
        $missing  = [];
        $queue    = [...$target->requires(), ...$target->routeRequires()];

        while ($queue !== []) {
            $domain = array_shift($queue);

            if ($domain === $target->solves() || isset($resolved[$domain]) || \in_array($domain, $missing, true)) {
                continue;
            }

            $manifest = $byDomain[$domain] ?? null;
            if ($manifest === null) {
                $missing[] = $domain;

                continue;
            }

            $resolved[$domain] = $manifest->providerClass;
            $queue = [...$queue, ...$manifest->requires(), ...$manifest->routeRequires()];
        }

        return [
            'providers' => array_values($resolved),
            'missing'   => $missing,
        ];
    }

    /**
     * The globs the scan used. Printed when nothing was found, because "no
     * plugins here" is only useful next to "and here is where I looked".
     *
     * @return list<string>
     */
    public function searchedPaths(): array
    {
        $this->all();

        return $this->searched;
    }

    /** @return list<string> */
    private function globs(): array
    {
        $root   = rtrim($this->root, '/');
        $parent = \dirname($root);

        // 1. the root itself — a plugin repo checkout you are standing in.
        $globs = [$root . '/module.json'];

        // 2. a project or kernel checkout.
        foreach (self::MODULE_DIRS as $dir) {
            $globs[] = $root . '/' . $dir . '/*/module.json';
        }

        // 3. sibling repos. Guarded against a root at the filesystem top, where
        //    dirname() returns the root again and this would rescan it.
        if ($this->includeSiblings && $parent !== $root && $parent !== '' && is_dir($parent)) {
            $globs[] = $parent . '/*/module.json';

            foreach (self::MODULE_DIRS as $dir) {
                $globs[] = $parent . '/' . $dir . '/*/module.json';
            }
        }

        // 4. composer-installed plugins, last: a released copy loses to a
        //    checkout of the same plugin you are actively editing.
        $globs[] = $root . '/vendor/*/*/module.json';

        return $globs;
    }

    /**
     * The fully-qualified Provider class declared in a plugin directory, with
     * its namespace registered for autoloading.
     *
     * Only the namespace is parsed — the class is Provider by contract, and
     * every plugin in this framework names it so.
     */
    private function providerClassIn(string $dir): ?string
    {
        $file = $dir . '/Provider.php';
        if (!is_file($file)) {
            return null;
        }

        // Read the head only: the namespace is always in the first few lines,
        // and some providers are long.
        $head = (string) file_get_contents($file, length: 4096);

        if (preg_match('/^namespace\s+([^;]+);/m', $head, $matches) !== 1) {
            return null;
        }

        $namespace = trim($matches[1]);
        self::registerAutoload($namespace . '\\', $dir);

        return $namespace . '\\Provider';
    }

    /**
     * Map a discovered plugin's namespace onto its directory.
     *
     * Registered once, lazily, and APPENDED — composer's autoloader runs first,
     * so a plugin that is properly installed keeps resolving through it and
     * this never shadows a real install.
     */
    private static function registerAutoload(string $prefix, string $directory): void
    {
        if (isset(self::$autoloadPrefixes[$prefix])) {
            return;
        }

        self::$autoloadPrefixes[$prefix] = $directory;

        if (self::$autoloaderRegistered) {
            return;
        }

        self::$autoloaderRegistered = true;

        spl_autoload_register(static function (string $class): void {
            foreach (self::$autoloadPrefixes as $prefix => $directory) {
                if (!str_starts_with($class, $prefix)) {
                    continue;
                }

                $relative = substr($class, \strlen($prefix));
                $path     = $directory . '/' . str_replace('\\', '/', $relative) . '.php';

                if (is_file($path)) {
                    require_once $path;

                    return;
                }
            }
        });
    }
}
