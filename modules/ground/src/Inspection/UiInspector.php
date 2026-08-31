<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Inspection;

use AlfacodeTeam\Ground\PluginManifest;
use AlfacodeTeam\Ground\Ui\PageResolver;
use AlfacodeTeam\Ground\Ui\UiManifest;

/**
 * Static checks for a plugin's `ui/` — the half of a plugin that no PHP test,
 * no boot and no `tsc --noEmit` looks at.
 *
 * The failure mode this exists for: everything on the server can be correct and
 * the page still be blank. `render('Auth/Login', 'admin')` compiles, boots,
 * returns 200 and a valid page object; if no `Auth/Login.tsx` is reachable, the
 * browser console says `Page "Auth/Login" not found under Pages/` and nothing
 * earlier in the pipeline had an opinion.
 *
 * A plugin with no `ui/` directory produces NO findings. Shipping UI is optional
 * and most plugins do not; reporting its absence would make the checker noisy
 * for the majority to serve the minority.
 */
final class UiInspector
{
    /** @var list<Finding> */
    private array $findings = [];

    public function __construct(
        private readonly PluginManifest $manifest,
        private readonly UiManifest $ui,
    ) {}

    public static function for(PluginManifest $manifest): self
    {
        return new self($manifest, UiManifest::for($manifest->directory()));
    }

    /** @return list<Finding> */
    public function run(): array
    {
        $this->findings = [];

        if (!$this->ui->exists) {
            return [];
        }

        $this->checkManifest();
        $this->checkExports();
        $this->checkPages();
        $this->checkRenderedComponents();
        $this->checkNav();

        return $this->findings;
    }

    // ── ui.json ───────────────────────────────────────────────────────────────

    private function checkManifest(): void
    {
        if ($this->ui->error !== null) {
            $this->findings[] = Finding::error('ui.manifest-invalid', $this->ui->error, '', $this->ui->path());

            return;
        }

        if ($this->ui->data === []) {
            $this->findings[] = Finding::warning(
                'ui.no-manifest',
                'ui/ exists but has no ui.json.',
                'Without it the alias and entry fall back to defaults, so other packages cannot '
                . 'import this plugin\'s UI by a stable name.',
                $this->ui->directory,
            );

            return;
        }

        $alias = $this->ui->alias();
        if ($alias === '') {
            $this->findings[] = Finding::error(
                'ui.no-alias',
                'ui.json declares no "alias".',
                'The alias is what `hkm ui sync` writes into tsconfig.plugins.json — the single '
                . 'source of path aliases for both vite and TypeScript. Without it nothing can import this UI.',
                $this->ui->path(),
            );
        } elseif (!str_starts_with($alias, '@')) {
            $this->findings[] = Finding::error(
                'ui.alias-shape',
                "ui.json alias [{$alias}] does not start with '@'.",
                'Every federated alias is an @-scoped specifier; a bare word collides with npm packages.',
                $this->ui->path(),
            );
        }

        $entry = $this->ui->directory . '/' . ltrim($this->ui->entry(), '/');
        if (!is_file($entry)) {
            $this->findings[] = Finding::error(
                'ui.entry-missing',
                'ui.json entry [' . $this->ui->entry() . '] does not exist.',
                'The alias resolves to this file; a missing one breaks every import of the alias.',
                $this->ui->path(),
            );
        }

        // The bundler globs admin/Pages and site/Pages BY CONVENTION and never
        // reads this map, so a surface pointing elsewhere federates nothing —
        // silently, since the declaration looks deliberate.
        foreach ($this->ui->surfaces() as $surface => $path) {
            $expected = $surface . '/Pages';

            if (trim($path, '/') !== $expected) {
                $this->findings[] = Finding::warning(
                    'ui.surface-path',
                    "ui.json maps surface '{$surface}' to [{$path}], but surfaces glob [{$expected}].",
                    'Pages outside the conventional path are never globbed by any surface.',
                    $this->ui->path(),
                );

                continue;
            }

            if (!is_dir($this->ui->directory . '/' . $expected)) {
                $this->findings[] = Finding::warning(
                    'ui.surface-missing',
                    "ui.json declares surface '{$surface}' but [{$expected}] does not exist.",
                    '',
                    $this->ui->path(),
                );
            }
        }
    }

    private function checkExports(): void
    {
        foreach ($this->ui->exports() as $name => $file) {
            if (!is_file($this->ui->directory . '/' . ltrim($file, '/'))) {
                $this->findings[] = Finding::error(
                    'ui.export-missing',
                    "ui.json export [{$name}] points at [{$file}], which does not exist.",
                    '',
                    $this->ui->path(),
                );
            }
        }
    }

    // ── Pages ─────────────────────────────────────────────────────────────────

    private function checkPages(): void
    {
        foreach ($this->ui->pageFiles() as $file) {
            $source = (string) file_get_contents($file);

            // The surface does `mod.default ?? mod` — a page with no default
            // export renders the module namespace object, which React refuses
            // at runtime with a message that names React, not this file.
            if (!preg_match('/export\s+default\b/', $source)) {
                $this->findings[] = Finding::warning(
                    'ui.page-no-default-export',
                    $this->relative($file) . ' has no default export.',
                    'The surface resolves a page as `mod.default ?? mod`; without a default the '
                    . 'module object is handed to React as a component.',
                    $file,
                );
            }
        }

        // A page directory whose files a surface cannot reach.
        foreach (['admin', 'site'] as $face) {
            $dir = $this->ui->directory . '/' . $face;
            if (!is_dir($dir) || is_dir($dir . '/Pages')) {
                continue;
            }

            // A face directory that is a declared EXPORT is a library, not a
            // pages tree — `@pageflow/admin` is the admin foundation (shell,
            // registries, data components) and ships no pages ON PURPOSE.
            // Warning about it would make the checker wrong about the one
            // package every admin UI depends on.
            if ($this->isExportedDirectory($face)) {
                continue;
            }

            if (UiManifest::tsxUnder($dir) !== []) {
                $this->findings[] = Finding::warning(
                    'ui.pages-outside-convention',
                    "ui/{$face}/ has .tsx files but no Pages/ directory.",
                    "Surfaces glob `{$face}/Pages/**` — components elsewhere under {$face}/ are "
                    . 'reachable only by direct import, never as a Pageflow page.',
                    $dir,
                );
            }
        }
    }

    /**
     * Every component this plugin's controllers RENDER must have a page file.
     *
     * The component name is read from literal `render(..., 'Component/Name', ...)`
     * arguments found by tokenising the plugin's PHP. A dynamically-built name
     * is skipped rather than guessed — the runtime ground assertion
     * (`assertPageResolves`) covers those, because there the name comes from the
     * response instead of the source.
     */
    private function checkRenderedComponents(): void
    {
        $components = $this->renderedComponents();
        if ($components === []) {
            return;
        }

        // Resolve against the plugin's own pages only. A project CAN supply the
        // page instead, which is why this is a warning: it is "this plugin does
        // not ship the page it renders", not "this page cannot exist".
        $resolver = PageResolver::forPlugin($this->manifest->directory(), 'admin');

        foreach ($components as $component => $file) {
            if ($resolver->resolves($component)) {
                continue;
            }

            $nearest = $resolver->nearest($component);

            $this->findings[] = Finding::warning(
                'ui.page-missing',
                "Renders component [{$component}], which no page file in this plugin serves.",
                'At runtime this is a blank screen and `Page "' . $component . '" not found under '
                . 'Pages/` in the console.'
                . ($nearest === [] ? '' : ' Nearest: ' . implode(', ', $nearest) . '.')
                . ' If the project is expected to supply this page, ignore this.',
                $file,
            );
        }
    }

    /**
     * Literal component names passed to a `render(` call, by tokenising.
     *
     * PageflowResponder::render() takes (Request, component, surface, props),
     * so the component is the first STRING argument. Matching text with a regex
     * would find the same names inside comments and docblocks — the false
     * positive that had to be fixed once already in {@see Inspector}.
     *
     * @return array<string, string> component => file it was found in
     */
    private function renderedComponents(): array
    {
        $found = [];

        foreach ($this->phpFiles() as $file) {
            $tokens = @token_get_all((string) file_get_contents($file));

            for ($i = 0, $count = \count($tokens); $i < $count; $i++) {
                $token = $tokens[$i];

                if (!\is_array($token) || $token[0] !== \T_STRING || $token[1] !== 'render') {
                    continue;
                }

                $open = $this->next($tokens, $i);
                if ($open === null || $tokens[$open] !== '(') {
                    continue;
                }

                // Scan the first few arguments for a literal that looks like a
                // component name — `Dir/Name`. The Request comes first and a
                // surface string ('admin') follows, so position alone is not a
                // reliable discriminator; the shape is.
                for ($j = $open + 1, $depth = 0; $j < $count && $j < $open + 40; $j++) {
                    if ($tokens[$j] === '(') {
                        $depth++;
                    } elseif ($tokens[$j] === ')') {
                        if ($depth === 0) {
                            break;
                        }
                        $depth--;
                    }

                    if (!\is_array($tokens[$j]) || $tokens[$j][0] !== \T_CONSTANT_ENCAPSED_STRING) {
                        continue;
                    }

                    $value = trim($tokens[$j][1], "'\"");
                    if (preg_match('#^[A-Z][A-Za-z0-9]*(/[A-Z][A-Za-z0-9]*)+$#', $value) === 1) {
                        $found[$value] ??= $file;

                        break;
                    }
                }
            }
        }

        return $found;
    }

    // ── Navigation ────────────────────────────────────────────────────────────

    /** True when ui.json exports a file from inside this face directory. */
    private function isExportedDirectory(string $face): bool
    {
        foreach ($this->ui->exports() as $file) {
            if (str_starts_with(ltrim($file, '/'), $face . '/')) {
                return true;
            }
        }

        return false;
    }

    private function checkNav(): void
    {
        if ($this->ui->pageFilesFor('admin') === [] || $this->ui->hasAdminNav()) {
            return;
        }

        $this->findings[] = Finding::note(
            'ui.no-admin-nav',
            'Ships admin pages but no ui/admin/nav.ts.',
            'The admin surface globs `plugins/*/admin/nav.ts` to build the sidebar. Without one '
            . 'these pages are reachable only by typing the URL.',
            $this->ui->directory . '/admin',
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return list<string> */
    private function phpFiles(): array
    {
        $dir = $this->manifest->directory();
        if (!is_dir($dir)) {
            return [];
        }

        $files    = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $path = $file->getPathname();
            if ($file->getExtension() === 'php'
                && preg_match('#/(vendor|tests|node_modules)/#', $path) !== 1) {
                $files[] = $path;
            }
        }

        return $files;
    }

    private function next(array $tokens, int $from): ?int
    {
        for ($i = $from + 1; isset($tokens[$i]); $i++) {
            if (\is_array($tokens[$i]) && \in_array($tokens[$i][0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }

    private function relative(string $file): string
    {
        $dir = $this->manifest->directory();

        return str_starts_with($file, $dir) ? ltrim(substr($file, \strlen($dir)), '/') : $file;
    }
}
