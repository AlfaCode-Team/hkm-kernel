<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Ui;

use AlfacodeTeam\Ground\Inspection\PluginLocator;

/**
 * `yarn dev` inside a plugin — the React UI, with HMR, against the real kernel.
 *
 * ─── WHY THIS IS NOT JUST A VITE CONFIG ─────────────────────────────────────
 *
 * A plugin's pages import `@pageflow/react`, `@ui/button`, `@providers/theme`.
 * Those aliases exist only after `hkm ui sync` has mirrored every plugin into a
 * PROJECT's frontend/. So "let me see this page in a browser" answered "first
 * build a project", which is the wrong answer while the plugin is the thing
 * being written. UiWorkspace already derives that alias map for vitest; this
 * class reuses it for a dev server.
 *
 * The second half is the handshake with PHP. ViteManifest decides between
 * dev-server URLs and hashed production assets by looking for a HOT FILE at
 * `{VITE_PUBLIC_PATH}/{surface}-hot`, whose contents are the dev server's base
 * URL. When it is hot, `vite($entry, $surface)` emits
 * `<script src="{base}/{entry}">` plus `@vite/client` — absolute URLs at the
 * Vite origin.
 *
 * That is what makes this simple: THERE IS NO PROXY. You browse the PHP server
 * (`ground serve`), PHP renders the layout, and the browser fetches modules
 * straight from Vite. Nothing has to forward anything.
 *
 * ─── WHY THE WORKSPACE LIVES AT ui/.ground/ ─────────────────────────────────
 *
 * The layout asks for entry `src/surfaces/{surface}/index.tsx` unless a
 * controller overrides it. Rooting Vite at `ui/.ground/` and generating the
 * entries at exactly that path means the DEFAULT string resolves — no shared
 * prop to inject, no controller change, nothing for a plugin author to
 * remember. A plugin that overrides the entry keeps working, because the
 * override still wins.
 *
 * Everything generated here is machine-specific (the aliases are absolute
 * sibling checkouts) and so is gitignored, never committed with the plugin.
 */
final class DevWorkspace
{
    /** Where the generated dev scaffolding lives, relative to the plugin root. */
    public const DIRECTORY = 'ui/.ground';

    /** Default port for the Vite dev server. */
    public const PORT = 5173;

    private function __construct(
        public readonly string $pluginDirectory,
        public readonly UiManifest $ui,
        public readonly UiWorkspace $workspace,
        /** @var list<string> surface names from ui.json */
        public readonly array $surfaces,
    ) {}

    public static function for(string $pluginDirectory, PluginLocator $locator): self
    {
        $ui = UiManifest::for($pluginDirectory);

        return new self(
            rtrim($pluginDirectory, '/'),
            $ui,
            UiWorkspace::for($pluginDirectory, $locator),
            array_keys($ui->surfaces()),
        );
    }

    /**
     * Can this plugin's UI actually be booted by Pageflow?
     *
     * Three things have to hold, and each failure is reported separately
     * because "no dev server for you" without a reason is the least useful
     * thing this could say.
     */
    public function unsupportedReason(): ?string
    {
        if (!$this->ui->exists) {
            return 'no ui/ui.json — this plugin ships no frontend';
        }

        if ($this->surfaces === []) {
            return 'ui.json declares no "surfaces", so there is nothing to boot';
        }

        if (!$this->ui->hasPages()) {
            return 'no Pages/ directory under any surface — nothing to render';
        }

        // The pages import @pageflow/react; without the checkout the dev server
        // would start and then fail to resolve on the first page load.
        if (!isset($this->workspace->aliases['@pageflow/react'])) {
            return 'the pageflow plugin was not found beside this one, so @pageflow/react cannot resolve';
        }

        return null;
    }

    public function supported(): bool
    {
        return $this->unsupportedReason() === null;
    }

    public function directory(): string
    {
        return $this->pluginDirectory . '/' . self::DIRECTORY;
    }

    /** Where the `<surface>-hot` files land — what PHP must read as VITE_PUBLIC_PATH. */
    public function publicPath(): string
    {
        return $this->directory() . '/public';
    }

    /**
     * Write the dev scaffolding. Returns the relative paths written.
     *
     * Regenerated every time rather than kept: every path in it is derived
     * from where the sibling checkouts are RIGHT NOW, and a stale alias
     * resolving to a moved plugin is a worse failure than a rewritten file
     * (the whole directory is gitignored, so there is no author's edit to
     * preserve).
     *
     * @return list<string>
     */
    public function generate(): array
    {
        $written = [];
        $dir     = $this->directory();

        @mkdir($dir . '/src/surfaces', 0o775, true);
        @mkdir($this->publicPath(), 0o775, true);

        file_put_contents($dir . '/vite.config.ts', $this->viteConfig());
        $written[] = self::DIRECTORY . '/vite.config.ts';

        foreach ($this->surfaces as $surface) {
            $entryDir = $dir . '/src/surfaces/' . $surface;
            @mkdir($entryDir, 0o775, true);

            file_put_contents($entryDir . '/index.tsx', $this->surfaceEntry($surface));
            $written[] = self::DIRECTORY . "/src/surfaces/{$surface}/index.tsx";
        }

        if ($this->addDevScript()) {
            $written[] = 'ui/package.json (dev script)';
        }

        return $written;
    }

    /**
     * Teach `ui/package.json` the `dev` script, leaving everything else alone.
     *
     * `yarn dev` is the command a frontend developer already types, so it is
     * the one that should work — rather than a `ground` verb they have to
     * learn for this one job.
     */
    private function addDevScript(): bool
    {
        $path = $this->pluginDirectory . '/ui/package.json';

        if (!is_file($path)) {
            return false;
        }

        $package = json_decode((string) file_get_contents($path), true);

        if (!\is_array($package)) {
            return false;
        }

        $scripts = \is_array($package['scripts'] ?? null) ? $package['scripts'] : [];
        $command = 'vite --config .ground/vite.config.ts';

        if (($scripts['dev'] ?? null) === $command) {
            return false;
        }

        $scripts['dev']      = $command;
        $package['scripts']  = $scripts;

        file_put_contents(
            $path,
            json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );

        return true;
    }

    // ── Generated files ───────────────────────────────────────────────────────

    private function viteConfig(): string
    {
        $template = <<<'TS'
        // GENERATED by `hkm ground dev`. Gitignored — every path below is an
        // absolute checkout on THIS machine. Re-run the command after moving or
        // adding a plugin.
        import { defineConfig } from "vite";
        import react from "@vitejs/plugin-react";
        import fs from "node:fs";
        import { fileURLToPath } from "node:url";
        import { resolve } from "node:path";

        const here = (p: string) =>
          resolve(fileURLToPath(new URL(".", import.meta.url)), p);

        // Declared in ui.json. `--mode <surface>` picks one; the first is default.
        const SURFACES = __SURFACES__;

        /**
         * The handshake with PHP.
         *
         * ViteManifest looks for `{VITE_PUBLIC_PATH}/{surface}-hot` and reads the
         * dev server's base URL out of it. While that file exists the PHP layout
         * emits <script src="{base}/src/surfaces/{surface}/index.tsx"> plus
         * @vite/client instead of hashed production assets.
         *
         * It is removed on exit — including on SIGINT/SIGTERM, because a hot file
         * left behind by a Ctrl-C points PHP at a dev server that is no longer
         * listening, and every page then loads a script that never answers.
         */
        function hotFile(surface: string) {
          const file = resolve(here("public"), `${surface}-hot`);
          let removed = false;

          const clean = () => {
            if (!removed && fs.existsSync(file)) {
              fs.rmSync(file, { force: true });
            }
            removed = true;
          };

          return {
            name: "ground-hot-file",
            apply: "serve" as const,
            configureServer(server: any) {
              server.httpServer?.once("listening", () => {
                const address = server.httpServer.address();
                const port = typeof address === "object" && address ? address.port : __PORT__;
                const protocol = server.config.server.https ? "https" : "http";

                fs.mkdirSync(here("public"), { recursive: true });
                fs.writeFileSync(file, `${protocol}://127.0.0.1:${port}`);

                server.config.logger.info(
                  `\n  ground: ${surface} is hot — PHP will load modules from this server.\n`,
                );
              });

              for (const signal of ["SIGINT", "SIGTERM", "SIGHUP"] as const) {
                process.once(signal, () => {
                  clean();
                  process.exit();
                });
              }
              process.once("exit", clean);
            },
            closeBundle: clean,
          };
        }

        export default defineConfig(({ mode }) => {
          const surface = SURFACES.includes(mode) ? mode : SURFACES[0];

          return {
            root: here("."),
            plugins: [react(), hotFile(surface)],

            // esbuild otherwise walks up looking for the nearest tsconfig.json,
            // which outside a project is some other package's. Declaring the
            // options inline stops the lookup, so no file outside this plugin
            // decides how its UI compiles. (Same reasoning as the vitest config.)
            esbuild: {
              tsconfigRaw: {
                compilerOptions: {
                  jsx: "react-jsx",
                  target: "es2022",
                  useDefineForClassFields: true,
                  verbatimModuleSyntax: false,
                },
              },
            },

            resolve: {
              alias: {
        __ALIASES__
              },
            },

            server: {
              host: "127.0.0.1",
              port: __PORT__,
              // Fail rather than drift to another port: the port is written into
              // the hot file, but a PAGE already open would keep pointing at the
              // old one, and "my edits stopped applying" is a miserable way to
              // discover that.
              strictPort: true,
              // The page is served by PHP on a different origin, so the module
              // requests are cross-origin.
              cors: true,
            },

            // Pages resolve through import.meta.glob in the generated entry, so
            // there is no index.html and nothing to pre-bundle from one.
            optimizeDeps: {
              entries: SURFACES.map((s: string) => `src/surfaces/${s}/index.tsx`),
            },
          };
        });
        TS;

        return str_replace(
            ['__SURFACES__', '__ALIASES__', '__PORT__'],
            [
                json_encode($this->surfaces, JSON_UNESCAPED_SLASHES),
                $this->workspace->toViteAliases($this->directory()),
                (string) self::PORT,
            ],
            $template,
        ) . "\n";
    }

    /**
     * One surface's entry point.
     *
     * Mirrors what a project's own `src/surfaces/<name>/index.tsx` does, so a
     * page behaves here the way it will there: same component-key convention,
     * same ThemeProvider, same boot from `data-page` with the legacy
     * `window.initialPage` fallback.
     */
    private function surfaceEntry(string $surface): string
    {
        $template = <<<'TSX'
        // GENERATED by `hkm ground dev` for the "__SURFACE__" surface. Gitignored.
        import { createRoot } from "react-dom/client";
        import { createPageflowApp } from "@pageflow/react";
        import { ThemeProvider } from "@providers/theme";

        /*
         * Every page under this plugin's surfaces, lazily imported.
         *
         * BOTH surfaces are registered, not just this one — the server may
         * render a component authored under `site/Pages` onto the admin surface
         * (that is how, e.g., User/Register is reachable from an admin shell),
         * and the component key carries no surface in it. Resolving only the
         * active surface would 404 exactly those pages, and only at runtime.
         */
        const pages = {
        __GLOBS__
        } as Record<string, () => Promise<any>>;

        async function resolveComponent(name: string) {
          const key = Object.keys(pages).find((k) => k.endsWith(`/${name}.tsx`));

          if (!key) {
            throw new Error(
              `Page "${name}" not found. Known pages:\n  ` +
                Object.keys(pages)
                  .map((k) => k.replace(/^.*\/Pages\//, "").replace(/\.tsx$/, ""))
                  .join("\n  "),
            );
          }

          const mod = await pages[key]();

          return mod.default ?? mod;
        }

        const el = document.getElementById("app")!;

        // The current layout embeds the page object on the root element; the
        // legacy one publishes it as a global. Accept either.
        const legacyPage = (window as unknown as { initialPage?: unknown }).initialPage;
        const initialPage = el.dataset.page ? JSON.parse(el.dataset.page) : (legacyPage ?? {});

        createPageflowApp({
          page: initialPage,
          resolve: resolveComponent,
          setup({ el, App, props }: { el: HTMLElement; App: any; props: any }) {
            createRoot(el).render(
              <ThemeProvider>
                <App {...props} />
              </ThemeProvider>,
            );
          },
        });
        TSX;

        $globs = [];
        foreach ($this->ui->surfaces() as $name => $pagesDir) {
            // From ui/.ground/src/surfaces/<surface>/ back up to ui/.
            $globs[] = sprintf(
                '  ...import.meta.glob(%s),',
                json_encode('../../../../' . trim((string) $pagesDir, '/') . '/**/*.tsx', JSON_UNESCAPED_SLASHES),
            );
            unset($name);
        }

        return str_replace(
            ['__SURFACE__', '__GLOBS__'],
            [$surface, implode("\n", $globs)],
            $template,
        ) . "\n";
    }
}
