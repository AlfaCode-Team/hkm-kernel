<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Ui;

use AlfacodeTeam\Ground\Inspection\PluginLocator;
use AlfacodeTeam\Ground\PluginManifest;

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
        /** The plugin's module.json, for the bench's route list. Null if unreadable. */
        public readonly ?PluginManifest $manifest,
    ) {}

    public static function for(string $pluginDirectory, PluginLocator $locator): self
    {
        $ui = UiManifest::for($pluginDirectory);

        // The bench lists the plugin's routes, so it needs module.json. Ask the
        // locator first — it carries the real Provider class — and read the file
        // directly only when the plugin is outside its scan.
        //
        // Either way a failure is swallowed: a malformed manifest must not stop
        // the dev server being generated. Losing the route panel is a smaller
        // problem than being unable to look at the plugin at all, and reporting
        // a bad manifest is `ground check`'s job, not this one's.
        $directory = rtrim($pluginDirectory, '/');
        $manifest  = null;

        foreach ($locator->all() as $candidate) {
            if ($candidate->directory() === $directory) {
                $manifest = $candidate;
                break;
            }
        }

        if ($manifest === null) {
            try {
                $manifest = PluginManifest::fromPath(\stdClass::class, $directory . '/module.json');
            } catch (\InvalidArgumentException) {
                // reported elsewhere
            }
        }

        return new self(
            rtrim($pluginDirectory, '/'),
            $ui,
            UiWorkspace::for($pluginDirectory, $locator),
            array_keys($ui->surfaces()),
            $manifest,
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

        // The generated entry imports AdminLayout as the DEFAULT layout, so the
        // admin export is now a hard requirement of the entry itself — not only
        // of the pages that happen to import it. Reported separately from
        // @pageflow/react because an older pageflow checkout can have one export
        // and not the other, and "@pageflow/react cannot resolve" would then be
        // a false explanation of a real failure.
        if (!isset($this->workspace->aliases['@pageflow/admin'])) {
            return 'the pageflow checkout beside this one exports no @pageflow/admin, '
                . 'so the generated entry cannot import the admin shell';
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
        @mkdir($dir . '/src/styles', 0o775, true);
        @mkdir($this->publicPath(), 0o775, true);

        file_put_contents($dir . '/vite.config.ts', $this->viteConfig());
        $written[] = self::DIRECTORY . '/vite.config.ts';

        file_put_contents($dir . '/src/styles/index.css', $this->stylesheet());
        $written[] = self::DIRECTORY . '/src/styles/index.css';

        file_put_contents($dir . '/src/ground.manifest.ts', $this->benchManifest());
        $written[] = self::DIRECTORY . '/src/ground.manifest.ts';

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
        import tailwindcss from "@tailwindcss/vite";
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
        /*
         * A hot file for EVERY declared surface, not only the active mode.
         *
         * `--mode` picks which surface is primary; it does not limit what this
         * server can serve, because every entry lives under the same root and
         * Vite resolves them all. PHP, meanwhile, looks for `{surface}-hot` for
         * whichever surface the CONTROLLER named — so a plugin whose pages
         * render on `site` got no script tags at all while `yarn dev` sat there
         * reporting itself ready on `admin`. The page came back as a bare shell
         * with an empty #app and nothing anywhere said why.
         *
         * One server, every surface hot. Which one `--mode` selected is now
         * only a label in the log line.
         */
        function hotFile(surfaces: string[]) {
          const files = surfaces.map((s) => resolve(here("public"), `${s}-hot`));

          /*
           * Only THIS process's own hot file is ever removed.
           *
           * The cleanup used to be armed in configureServer, which runs before
           * the server binds. With strictPort, a second `yarn dev` on a port
           * already in use exits during startup — and on the way out deleted the
           * hot file belonging to the server that was running perfectly well.
           * PHP then stopped emitting any script tag at all, so every page came
           * back as a bare shell with an empty #app: no error, no console
           * message, just a blank document that looks like the app is broken.
           *
           * So ownership is claimed only once we are actually listening, and
           * everything below is a no-op until then.
           */
          let owned = false;

          const clean = () => {
            if (!owned) return;
            owned = false;
            for (const file of files) {
              fs.rmSync(file, { force: true });
            }
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
                for (const file of files) {
                  fs.writeFileSync(file, `${protocol}://127.0.0.1:${port}`);
                }
                owned = true;

                // Armed HERE, not in configureServer, so a start that never
                // bound cannot tear down somebody else's session on its way out.
                for (const signal of ["SIGINT", "SIGTERM", "SIGHUP"] as const) {
                  process.once(signal, () => {
                    clean();
                    process.exit();
                  });
                }
                process.once("exit", clean);

                server.config.logger.info(
                  `\n  ground: ${surfaces.join(", ")} hot — PHP will load modules from this server.\n`,
                );
              });
            },
            closeBundle: clean,
          };
        }

        // `--mode` no longer selects anything: one server serves every surface's
        // entry and marks them all hot. It is kept because `ground dev` passes
        // it and it still names the run in Vite's own output.
        export default defineConfig(() => {
          return {
            root: here("."),
            // Tailwind is not optional here. The pages, the shared @ui kit and
            // @pageflow/admin's shell are ALL Tailwind-only — they carry no CSS
            // of their own — so without this plugin every page under `yarn dev`
            // renders as unstyled HTML, which reads like the component is broken
            // rather than like the stylesheet is missing.
            plugins: [react(), tailwindcss(), hotFile(SURFACES)],

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
     * The one stylesheet every generated entry imports.
     *
     * ─── WHY IT IS INLINED RATHER THAN IMPORTED ─────────────────────────────
     *
     * The obvious version is `@import "@shared/styles/theme.css"`, the way a
     * project's surface does it. It does not work here. That file opens with
     * `@import "tailwindcss"`, which resolves by walking up from the file that
     * imports it — the KERNEL's templates/frontend, whose node_modules a plugin
     * author has no reason to have installed. Inlining the theme's BODY and
     * importing tailwindcss from a file inside `ui/.ground/` instead makes the
     * lookup land in `ui/node_modules`, which `ground install` did populate.
     *
     * The theme is READ at generate time rather than copied into this class, so
     * there is still exactly one definition of the tokens. The whole directory
     * is regenerated on every run, so it cannot go stale against the kernel.
     *
     * ─── WHY THE @source LINES ARE NOT OPTIONAL ─────────────────────────────
     *
     * Tailwind v4 discovers classes by scanning outward from the stylesheet,
     * and stops at .gitignore'd directories. Every file that matters here is
     * outside that scan: the pages sit above a gitignored `.ground/`, and the
     * @ui kit and @pageflow/admin shell are in sibling CHECKOUTS reached by
     * alias. Without the explicit sources the build succeeds and emits a
     * stylesheet containing none of the classes the shell uses — the failure
     * being a bare-looking page, not an error.
     */
    private function stylesheet(): string
    {
        // …/.ground/src/styles/index.css is what the @source paths are relative to.
        $from = $this->directory() . '/src/styles';

        $sources = [];

        // The plugin's own tree, one entry per top-level directory rather than
        // a single glob over ui/. An explicit @source is taken literally —
        // unlike Tailwind's automatic detection it does NOT skip node_modules —
        // so `@source "../../../**"` would walk every installed package on
        // every rebuild.
        foreach (glob($this->ui->directory . '/*', GLOB_ONLYDIR) ?: [] as $face) {
            if (\in_array(basename($face), ['node_modules', '.ground'], true)) {
                continue;
            }

            $sources[] = $this->source($from, $face);
        }

        // The shell and the kit, by the same aliases the pages import them by,
        // so this follows a checkout that moves instead of guessing at one.
        // The bench's own chrome is Tailwind too, and it lives in a THIRD tree
        // (this package) that neither the plugin's sources nor the aliases
        // above reach.
        $bench = $this->workspace->aliases['@ground/dev'] ?? null;
        if ($bench !== null) {
            $sources[] = $this->source($from, \dirname($bench));
        }

        foreach (['@pageflow/admin', '@ui'] as $alias) {
            $path = $this->workspace->aliases[$alias] ?? null;

            if ($path === null) {
                continue;
            }

            // An export alias names a FILE (admin/index.ts); scan its directory.
            $dir = is_dir($path) ? $path : \dirname($path);

            $sources[] = $this->source($from, $dir);
        }

        return "/*\n"
            . " * GENERATED by `hkm ground dev`. Gitignored.\n"
            . " *\n"
            . " * The kernel's shared theme, inlined — see DevWorkspace::stylesheet() for\n"
            . " * why it is not an @import. Edit the kernel's\n"
            . " * templates/frontend/src/shared/styles/theme.css and re-run the command.\n"
            . " */\n"
            . "@import \"tailwindcss\";\n"
            . "@import \"tw-animate-css\";\n\n"
            . implode("\n", $sources) . "\n\n"
            . $this->sharedTheme() . "\n";
    }

    /**
     * What the bench knows about this plugin, as a TypeScript module.
     *
     * GENERATED rather than fetched. The alternative is an endpoint the bench
     * calls on load, and it would be wrong twice over: `ground serve` boots a
     * fresh kernel per request, so there is no process holding this to be
     * asked, and the data is static anyway — it is the manifest on disk, which
     * this command has already read. Writing it out means the bench needs no
     * server round-trip, works with the dev server alone, and cannot drift,
     * because the whole workspace is regenerated on every run.
     *
     * A page is matched to a route by its handler naming the component; when
     * nothing matches, `path` is null and the bench shows it as unreachable
     * rather than offering a link that 404s.
     */
    private function benchManifest(): string
    {
        $routes = $this->manifest?->expandedRoutes() ?? [];

        $pages = [];
        foreach (['admin', 'site'] as $face) {
            foreach ($this->ui->pageFilesFor($face) as $file) {
                $component = preg_replace('#^.*/' . $face . '/Pages/#', '', substr($file, 0, -4)) ?? '';

                if ($component === '') {
                    continue;
                }

                $pages[] = [
                    'component' => $component,
                    'face'      => $face,
                    'path'      => self::pathRendering($component, $routes),
                ];
            }
        }

        $manifest = [
            'name'     => $this->manifest?->name() ?? basename($this->pluginDirectory),
            'solves'   => $this->manifest?->solves() ?? '',
            'requires' => $this->manifest?->requires() ?? [],
            'exposes'  => $this->manifest?->exposes() ?? [],
            'emits'    => $this->manifest?->emits() ?? [],
            'surfaces' => $this->ui->surfaces(),
            'pages'    => $pages,
            'routes'   => $routes,
        ];

        return "// GENERATED by `hkm ground dev`. Gitignored — regenerated every run.\n"
            . "import type { GroundManifest } from \"@ground/dev\";\n\n"
            . 'export const manifest: GroundManifest = '
            . json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . ";\n";
    }

    /**
     * A GET route whose handler renders this component, or null.
     *
     * Matched on the handler's METHOD name against the component's last
     * segment, which is the convention every plugin here follows
     * (`UserController@index` → `User/Index`) — and checked case-insensitively,
     * because the controller method is camelCase and the component is not.
     *
     * Deliberately conservative. A wrong link sends you to another page and
     * looks like the page you asked for is broken; "no route" is at worst a
     * missing convenience, and the bench says which it is.
     *
     * @param list<array{method: string, path: string, handler: string, dynamic: bool}> $routes
     */
    private static function pathRendering(string $component, array $routes): ?string
    {
        $leaf = strtolower(substr($component, (int) strrpos('/' . $component, '/')));

        foreach ($routes as $route) {
            if ($route['method'] !== 'GET' || $route['dynamic']) {
                continue;
            }

            $at = strrpos($route['handler'], '@');

            if ($at !== false && strtolower(substr($route['handler'], $at + 1)) === $leaf) {
                return $route['path'];
            }
        }

        return null;
    }

    /** One `@source` directive for a directory, relative to the stylesheet. */
    private function source(string $from, string $directory): string
    {
        return '@source ' . json_encode(
            UiWorkspace::relative($from, $directory) . '/**/*.{ts,tsx}',
            JSON_UNESCAPED_SLASHES,
        ) . ';';
    }

    /**
     * The kernel theme's body — everything but its own two `@import` lines,
     * which {@see stylesheet()} re-emits from a resolvable location.
     *
     * Returns a minimal stand-in when the template is not on disk (an installed
     * bundle without it). A page then renders with Tailwind's utilities and no
     * design tokens, which is degraded but legible — better than a build that
     * fails on a missing file the author cannot supply.
     */
    private function sharedTheme(): string
    {
        $path = ($this->workspace->aliases['@shared'] ?? '') . '/styles/theme.css';

        if (!is_file($path)) {
            return ":root { --radius: 0.5rem; }";
        }

        $css = (string) file_get_contents($path);

        return trim(preg_replace('/^@import\s+"(tailwindcss|tw-animate-css)";\s*$/m', '', $css) ?? $css);
    }

    /**
     * One surface's entry point.
     *
     * Mirrors what a project's own `src/surfaces/<name>/index.tsx` does, so a
     * page behaves here the way it will there: same component-key convention,
     * same ThemeProvider, same boot from `data-page` with the legacy
     * `window.initialPage` fallback.
     *
     * ─── WITH ONE DELIBERATE DIFFERENCE: THE DEFAULT LAYOUT ─────────────────
     *
     * Pageflow's <App> applies `Component.layout` and, when a page declares
     * none, renders the page bare. That is right in production — a public page
     * has no admin chrome — but under `ground dev` it means a page whose author
     * simply has not written the one-line `.layout` assignment yet opens as
     * unstyled markup on a white document, which reads like the page is broken.
     *
     * So this entry passes <App> the `children` render-prop, which REPLACES its
     * default renderChildren, and falls back to <AdminLayout>. A page that
     * declares its own layout keeps it — the fallback is only reached when
     * there is nothing to honour.
     *
     * The function must not call hooks. <App> invokes it INLINE during its own
     * render, so a hook there joins App's hook list, and App's hook count then
     * varies with whichever page is mounted — React throws "rendered more hooks
     * than during the previous render" on the first navigation between a page
     * that has a layout and one that does not.
     */
    private function surfaceEntry(string $surface): string
    {
        $template = <<<'TSX'
        // GENERATED by `hkm ground dev` for the "__SURFACE__" surface. Gitignored.
        import "../../styles/index.css";
        import { createElement } from "react";
        import { createRoot } from "react-dom/client";
        import { createPageflowApp } from "@pageflow/react";
        import { GroundFrame } from "@ground/dev";
        import { ThemeProvider } from "@providers/theme";
        import { manifest } from "../../ground.manifest";

        /*
         * The plugin's nav contributions, imported for their SIDE EFFECT.
         *
         * `ui/admin/nav.ts` calls registerModule()/registerFeature() at import
         * time, and nothing had ever imported it — not this entry, and not the
         * kernel's own surface template, though @pageflow/admin's README says
         * the surface globs it. So the registry was empty everywhere and the
         * sidebar could not be made to show a single item. Eager, because a
         * registration that has not run by first paint is a nav that renders
         * empty and then jumps.
         */
        import.meta.glob("../../../../admin/nav.ts", { eager: true });

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

        /*
         * Replaces <App>'s own renderChildren so every page opens on the bench.
         *
         * GroundFrame decides the layout — honouring the page's own `.layout`
         * and falling back to the admin shell by default, or forcing one when
         * the bar says so. It renders the page UNTOUCHED inside its chrome, so
         * what is on screen is still what a project renders.
         *
         * NO HOOKS IN HERE. App calls this inline during its own render, so a
         * hook would join App's hook list and the count would then change with
         * whichever page is mounted — React throws "rendered more hooks than
         * during the previous render" on the first navigation between a page
         * that has a layout and one that does not. GroundFrame is a COMPONENT,
         * so its own hooks are its own; this function must stay a plain call.
         */
        function renderPage({ Component, props, key }: { Component: any; props: any; key: any }) {
          return (
            <GroundFrame manifest={manifest} component={Component}>
              {createElement(Component, { key, ...props })}
            </GroundFrame>
          );
        }

        createPageflowApp({
          page: initialPage,
          resolve: resolveComponent,
          setup({ el, App, props }: { el: HTMLElement; App: any; props: any }) {
            createRoot(el).render(
              <ThemeProvider>
                <App {...props}>{renderPage}</App>
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
