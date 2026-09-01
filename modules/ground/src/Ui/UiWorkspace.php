<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Ui;

use AlfacodeTeam\Ground\Inspection\PluginLocator;

/**
 * Everything a plugin's UI needs in order to be rendered OUTSIDE a project:
 * the import aliases its pages use, and the npm packages behind them.
 *
 * ─── THE PROBLEM ────────────────────────────────────────────────────────────
 *
 * A plugin's pages do not import relative paths. They import
 * `@pageflow/react`, `@pageflow/admin`, `@ui/button` — aliases that only exist
 * after `hkm ui sync` has mirrored every plugin into a project's `frontend/`
 * and written `tsconfig.plugins.json`. So "can I check this component?"
 * historically answered "only once you have a project", which is the wrong
 * answer while the plugin is the thing you are writing.
 *
 * Every one of those aliases points at something already on disk:
 *
 *   @pageflow/react   → hkm-plugin-pageflow/ui/react/index.tsx   (its ui.json "exports")
 *   @pageflow/admin   → hkm-plugin-pageflow/ui/admin/index.ts
 *   @ui/button        → <kernel>/templates/frontend/src/shared/ui/button.tsx
 *   @user             → this plugin's own ui/
 *
 * So the mapping is derivable, and this class derives it — from the same
 * `ui.json` declarations `hkm ui sync` reads, not from a hardcoded list that
 * would drift the first time a plugin adds an export.
 */
final class UiWorkspace
{
    private function __construct(
        /** @var array<string, string> alias => absolute path */
        public readonly array $aliases,
        /** @var array<string, string> package => version constraint */
        public readonly array $dependencies,
    ) {}

    /**
     * Resolve the aliases and npm dependencies for one plugin's UI.
     *
     * @param string $pluginDirectory the directory holding module.json
     */
    public static function for(string $pluginDirectory, PluginLocator $locator): self
    {
        $own = UiManifest::for($pluginDirectory);

        $aliases      = [];
        $dependencies = [];

        // The plugin's own alias first, so a page importing `@user/…` from
        // inside the user plugin resolves to the working tree and not to a
        // mirrored copy of it.
        if ($own->alias() !== '') {
            $aliases[$own->alias()] = $own->directory;
        }
        foreach ($own->dependencies() as $package => $version) {
            $dependencies[$package] = $version;
        }

        foreach ($locator->all() as $manifest) {
            $ui = UiManifest::for($manifest->directory());

            if (!$ui->exists || $ui->directory === $own->directory) {
                continue;
            }

            // Named exports win over the bare alias, and are registered first:
            // Vite matches aliases in order, so a bare `@pageflow` placed ahead
            // of `@pageflow/react` would swallow it and resolve the subpath to
            // a directory that has no index at that name.
            foreach ($ui->exports() as $alias => $file) {
                $aliases[$alias] = $ui->directory . '/' . ltrim($file, '/');
            }

            if ($ui->alias() !== '' && !isset($aliases[$ui->alias()])) {
                $aliases[$ui->alias()] = $ui->directory;
            }

            // A page rendered in a test executes the imported plugin's code, so
            // ITS runtime dependencies have to be installed too — rendering an
            // admin page without `lucide-react` fails on the first icon.
            foreach ($ui->dependencies() as $package => $version) {
                $dependencies[$package] ??= $version;
            }
        }

        // Ground's own dev bench. It belongs HERE rather than in DevWorkspace
        // because both generated configs need it: the dev server's, and the
        // VITEST one — a test that boots the generated entry (which imports the
        // bench) otherwise fails to resolve it, and the message names the entry
        // rather than the missing alias.
        $bench = self::benchPath();
        if ($bench !== null) {
            $aliases['@ground/dev'] = $bench;
        }

        $shared = self::sharedKitPath();
        if ($shared !== null) {
            // The template's own alias list, from vite/aliases.ts
            // `sharedAliases()`. The kit's components use every one of these on
            // each other — sonner.tsx reaches for @providers/theme, button.tsx
            // for @lib/utils — so mapping only @ui gets one file further and
            // then fails.
            $src = \dirname($shared, 2);

            $aliases['@ui']        = $shared;
            $aliases['@lib']       = $src . '/shared/lib';
            $aliases['@hooks']     = $src . '/shared/hooks';
            $aliases['@providers'] = $src . '/shared/providers';
            $aliases['@shared']    = $src . '/shared';

            self::ensureKitTsconfig($shared);

            // The kit's components pull in Radix, framer-motion and friends.
            // The template's own package.json is the authoritative list — a
            // hand-maintained copy here would go stale the first time a shadcn
            // component is added.
            foreach (self::kitDependencies($shared) as $package => $version) {
                $dependencies[$package] ??= $version;
            }
        }

        ksort($dependencies);

        /**
         * Bare npm specifiers, pinned to THIS ui/'s node_modules.
         *
         * Node resolves a bare import by walking up from the importing FILE, and
         * the imported files live in a sibling checkout with no node_modules of
         * its own — so `import { debounce } from "es-toolkit"` inside
         * hkm-plugin-pageflow/ui/ fails, even though es-toolkit is installed
         * right here. Mapping each package explicitly is the fix that does not
         * involve writing a node_modules symlink into somebody else's
         * repository.
         *
         * React is in the list because the linked pages import it too, including
         * the implicit `react/jsx-runtime` the automatic JSX transform emits.
         */
        $modules = $own->directory . '/node_modules';

        foreach ([...array_keys($dependencies), 'react-dom', 'react'] as $package) {
            $aliases[$package] ??= $modules . '/' . $package;
        }

        return new self($aliases, $dependencies);
    }

    /**
     * Ground's own `ui/dev` — the bench the generated entry frames pages with.
     *
     * Found by reflection for the same reason {@see sharedKitPath} does it:
     * this package runs from a kernel checkout, an installed bundle and a
     * plugin's vendor/, and the hop count to its root differs in each.
     *
     * It cannot come from the locator. The locator globs `*<slash>module.json`
     * one level down, and ground keeps its manifest at `src/module.json` — so
     * ground never appears in its own scan and would never be aliased.
     */
    public static function benchPath(): ?string
    {
        $file = (new \ReflectionClass(self::class))->getFileName();

        if ($file === false) {
            return null;
        }

        // …/modules/ground/src/Ui/UiWorkspace.php → …/modules/ground
        $dev = \dirname($file, 3) . '/ui/dev';

        return is_file($dev . '/index.ts') ? $dev . '/index.ts' : null;
    }

    /**
     * The shadcn kit every surface shares, in the kernel's frontend template.
     *
     * Located by reflecting a kernel class rather than by a path guess: the
     * kernel is a composer dependency, so it is either a real vendor install or
     * a path-repo symlink to a checkout, and reflection finds both. A relative
     * `../../..` walk finds neither reliably.
     */
    private static function sharedKitPath(): ?string
    {
        $file = (new \ReflectionClass(\AlfacodeTeam\PhpServicePlatform\Kernel\Kernel::class))->getFileName();

        if ($file === false) {
            return null;
        }

        // …/src/Kernel/Kernel.php → …/
        $root = \dirname($file, 3);
        $kit  = $root . '/templates/frontend/src/shared/ui';

        return is_dir($kit) ? $kit : null;
    }

    /**
     * The npm packages the shared kit imports, from the frontend template's
     * package.json.
     *
     * @return array<string, string>
     */
    private static function kitDependencies(string $kit): array
    {
        $file = \dirname($kit, 3) . '/package.json';

        if (!is_file($file)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($file), true);
        $deps    = \is_array($decoded) ? ($decoded['dependencies'] ?? []) : [];

        // react/react-dom are pinned by this package.json's own devDependencies;
        // taking the template's copy too would install a second React and every
        // hook would fail with "invalid hook call".
        unset($deps['react'], $deps['react-dom']);

        return \is_array($deps) ? array_map('strval', $deps) : [];
    }

    /**
     * Make the shared kit's own tsconfig resolvable.
     *
     * Vite hands every .ts/.tsx file to esbuild, and esbuild first discovers the
     * NEAREST tsconfig.json by walking up from that file. For a kit component
     * that is the kernel's `templates/frontend/tsconfig.json`, which does
     * `"extends": "./tsconfig.plugins.json"` — a file `hkm ui sync` GENERATES
     * into a project and which therefore never exists in the template. The
     * result is a hard parse error before a single test runs, and it cannot be
     * suppressed: Vite calls loadTsconfigJsonForFile unconditionally and
     * rethrows, so neither `esbuild.tsconfigRaw` nor `esbuild: false` avoids it.
     *
     * Writing the stub is safe and is not a hack in someone else's repository:
     * the template's own .gitignore lists tsconfig.plugins.json precisely
     * because it is generated, so this stays untracked, and `hkm ui sync`
     * overwrites it inside a real project. The tooling already maintains the
     * same invariant — plugin_ui.zig resets the file rather than deleting it,
     * "so tsconfig.json's extends still resolves".
     */
    private static function ensureKitTsconfig(string $kit): void
    {
        // …/src/shared/ui → …/  (the frontend root, where tsconfig.json lives)
        $root = \dirname($kit, 3);
        $stub = $root . '/tsconfig.plugins.json';

        if (!is_file($root . '/tsconfig.json') || is_file($stub)) {
            return;
        }

        @file_put_contents($stub, json_encode([
            '//'              => 'Stub written by hkm-plugin-ground so the template typechecks '
                . 'outside a project. `hkm ui sync` overwrites it with the real aliases.',
            'compilerOptions' => ['baseUrl' => '.', 'paths' => new \stdClass()],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    /**
     * Aliases as a Vite `resolve.alias` object literal, longest-first and
     * RELATIVE to the ui/ directory the config sits in.
     *
     * Relative because the file is worth committing. Absolute paths would bake
     * one developer's home directory into the repository, so the config would
     * work on exactly one machine and silently resolve to nothing on every
     * other — the failure being a missing import, not a missing file.
     *
     * The relative form assumes the workspace layout this whole toolchain
     * already assumes (plugins as sibling checkouts). When that does not hold,
     * `make:ui-test --config` regenerates.
     *
     * @param string $base the directory the generated config lives in
     */
    public function toViteAliases(string $base): string
    {
        $aliases = $this->aliases;

        // Longest alias first. Vite tries them in order, so `@pageflow` ahead of
        // `@pageflow/react` would capture the subpath import and resolve it to
        // a file that does not exist.
        uksort($aliases, static fn(string $a, string $b): int => \strlen($b) <=> \strlen($a));

        $lines = [];
        foreach ($aliases as $alias => $path) {
            $lines[] = sprintf(
                '      %s: here(%s),',
                json_encode($alias, JSON_UNESCAPED_SLASHES),
                json_encode(self::relative($base, $path), JSON_UNESCAPED_SLASHES),
            );
        }

        return implode("\n", $lines);
    }

    /**
     * $to expressed relative to $from, with `../` hops.
     *
     * Public because {@see DevWorkspace} generates a stylesheet whose `@source`
     * directives have to reach the same sibling checkouts these aliases point
     * at, and re-deriving the hop count there would be a second implementation
     * of this that drifts the first time the workspace layout changes.
     */
    public static function relative(string $from, string $to): string
    {
        $fromParts = explode('/', trim($from, '/'));
        $toParts   = explode('/', trim($to, '/'));

        $common = 0;
        while (
            $common < \count($fromParts)
            && $common < \count($toParts)
            && $fromParts[$common] === $toParts[$common]
        ) {
            $common++;
        }

        $up   = array_fill(0, \count($fromParts) - $common, '..');
        $down = \array_slice($toParts, $common);
        $path = implode('/', [...$up, ...$down]);

        return $path === '' ? '.' : $path;
    }
}
