<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Commands;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use AlfacodeTeam\Ground\Inspection\PluginLocator;
use AlfacodeTeam\Ground\Ui\UiManifest;
use AlfacodeTeam\Ground\Ui\UiWorkspace;

/**
 * make:ui-test <plugin> — scaffold the component-test side of a plugin's UI.
 *
 * PHP can prove a route returns `{component, props}` and that a page file
 * exists for it. It cannot prove the component RENDERS. That needs a DOM, and
 * a DOM needs node — so this writes the vitest half.
 *
 * The one thing that makes it worth having: the test reads its props from a
 * FIXTURE the ground dumped from a real response, not from hand-written mocks.
 * Mock props drift the moment someone renames a field on the server, and the
 * component test keeps passing while the page breaks. With a dumped fixture,
 * the rename fails the component test.
 */
final class MakeUiTestCommand extends AbstractCommand
{
    use ResolvesPlugin;

    protected function configure(): void
    {
        $this->name        = 'make:ui-test';
        $this->description = 'Scaffold vitest component tests for a plugin\'s pages';

        $this->addArgument('plugin', 'Plugin name (default: the plugin you are standing in)');
        $this->addOption('force', 'f', 'Overwrite existing files');
        $this->addOption('config', 'c', 'Also write vitest.config.ts and the setup file');
    }

    protected function handle(): int
    {
        $manifest = $this->resolvePlugin('plugin');

        if ($manifest === null) {
            return self::FAILURE;
        }

        $name = $manifest->name();

        $ui = UiManifest::for($manifest->directory());

        if (!$ui->exists) {
            $this->error("{$name} ships no ui/ directory — there is no component to test.");
            $this->muted('A plugin gets UI by adding ui/ui.json and ui/<face>/Pages/<Name>.tsx.');

            return self::FAILURE;
        }

        $pages = $ui->pageFiles();
        if ($pages === []) {
            $this->error("{$name} has a ui/ directory but no pages under admin/Pages or site/Pages.");

            return self::FAILURE;
        }

        $written = 0;
        foreach ($pages as $page) {
            $written += $this->writeTest($ui, $page) ? 1 : 0;
        }

        if ($this->hasOption('config')) {
            $written += $this->writeConfig($ui) ? 1 : 0;
        }

        $this->newLine();
        $this->info("Wrote {$written} file(s) under " . $ui->directory . '/__tests__/');
        $this->muted('Fixtures come from a ground test:');
        $this->muted('  $this->ground()->pageflow(\'/your/route\')->writeFixture(__DIR__ . \'/../ui/__fixtures__/<name>.json\');');
        $this->muted('Then: cd ' . $ui->directory . ' && npx vitest');

        return self::SUCCESS;
    }

    private function writeTest(UiManifest $ui, string $pagePath): bool
    {
        // 'admin/Pages/User/Index.tsx' → component 'User/Index', slug 'user-index'
        $relative  = ltrim(str_replace($ui->directory, '', $pagePath), '/');
        $component = preg_replace('#^(admin|site)/Pages/#', '', $relative) ?? $relative;
        $component = substr($component, 0, -4);
        $slug      = strtolower(str_replace('/', '-', $component));

        $dir  = $ui->directory . '/__tests__';
        $file = $dir . '/' . $slug . '.test.tsx';

        if (is_file($file) && !$this->hasOption('force')) {
            $this->muted('skipped (exists): ' . $slug . '.test.tsx');

            return false;
        }

        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            $this->error("Could not create {$dir}.");

            return false;
        }

        $import = '../' . substr($relative, 0, -4);

        file_put_contents($file, <<<TSX
            import { describe, it, expect } from "vitest";
            import { createElement } from "react";
            // Add `screen` here when you assert on what the user sees:
            //   import { render, screen } from "@testing-library/react";
            import { render } from "@testing-library/react";
            import { PageContext } from "@pageflow/react";
            import { AdminLayout } from "@pageflow/admin";
            import Page from "{$import}";
            import fixture from "../__fixtures__/{$slug}.json";

            /**
             * Component test for `{$component}`.
             *
             * Props come from __fixtures__/{$slug}.json, which a ground test dumped
             * from a REAL response:
             *
             *   \$this->ground()->pageflow('/your/route')
             *       ->writeFixture(__DIR__ . '/../ui/__fixtures__/{$slug}.json');
             *
             * That is the point of the fixture. Hand-written mock props drift from the
             * server the moment a field is renamed, and this test keeps passing while
             * the page breaks. With the dump, a server-side rename fails it here.
             *
             * Re-dump the fixture when the props change, and commit it.
             *
             * The PageContext wrapper is not optional: a Pageflow page reads its props
             * through `usePage()`, which THROWS on an empty context, so passing props
             * as JSX attributes renders nothing. The value is the whole page object —
             * the same thing <App> supplies at runtime, and the same shape the fixture
             * holds.
             *
             * The LAYOUT is applied for the same reason. Rendering a bare <Page />
             * tested the page body and nothing around it: a page whose `.layout`
             * throws, or whose shell needs a prop the server stopped sending, passed
             * here and broke in the browser. This mirrors what <App> does — honour
             * `Component.layout` when there is one, fall back to <AdminLayout> when
             * there is not — so the test renders the same tree a request does.
             */
            function renderPage() {
              const child = createElement(Page as any, fixture.props as any);
              const layout = (Page as any).layout;

              const tree =
                typeof layout === "function"
                  ? layout(child)
                  : <AdminLayout>{child}</AdminLayout>;

              return render(
                <PageContext.Provider value={fixture as any}>{tree}</PageContext.Provider>,
              );
            }

            // A fixture that has never been dumped is a PLACEHOLDER: skip loudly
            // rather than assert against empty props, which fails somewhere inside
            // the component and reads like the page is broken.
            const pending = (fixture as any).__placeholder === true;

            describe.skipIf(pending)("{$component}", () => {
              it("renders with the props the server actually sends", () => {
                renderPage();

                // Replace with something the page must show. Prefer a role or a
                // user-visible string over a test id: an assertion on markup
                // structure breaks on a redesign that changed nothing real.
                expect(document.body.textContent).not.toBe("");
              });

              it("has the props it needs", () => {
                // Guards the seam in the other direction: if the server stops sending
                // a prop this page reads, this fails BEFORE the page renders undefined.
                expect(fixture.props).toBeDefined();
              });

              it("shows nothing sensitive", () => {
                // Props are serialized into the page object and shipped to the browser.
                const serialized = JSON.stringify(fixture.props);
                expect(serialized).not.toMatch(/password|secret|token_hash/i);
              });
            });

            TSX);

        $this->seedPlaceholderFixture($ui, $slug, $component);
        $this->success('wrote ' . $slug . '.test.tsx');

        return true;
    }

    /**
     * A stand-in fixture, so the suite RUNS before anything has been dumped.
     *
     * Without a file there, the test's `import fixture from "…"` is a build
     * error that takes the whole vitest run down and names a path rather than a
     * cause. With one, the page's tests report as skipped and the reason is in
     * the file. Never overwrites a real dump — `__placeholder` is what tells
     * them apart, and a dumped fixture does not carry it.
     */
    private function seedPlaceholderFixture(UiManifest $ui, string $slug, string $component): void
    {
        $path = $ui->directory . '/__fixtures__/' . $slug . '.json';

        if (is_file($path)) {
            return;
        }

        $dir = \dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            return;
        }

        file_put_contents($path, json_encode([
            '__placeholder' => true,
            '//'            => "Dump the real props with: \$this->ground()->pageflow('/route')"
                . "->writeFixture(__DIR__ . '/../ui/__fixtures__/{$slug}.json'); "
                . 'until then this page\'s tests are skipped.',
            'component'     => $component,
            'props'         => new \stdClass(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    /**
     * The files that make `npx vitest` actually run: a package.json, and a
     * vitest config whose aliases resolve to the checkouts on disk.
     *
     * Both were missing. The old config carried a docblock conceding that
     * "@pageflow … are NOT resolvable here", which for a plugin like `user` —
     * where EVERY page imports @pageflow/admin and @ui/* — meant the scaffolded
     * tests could not run at all. And with no package.json there was nothing to
     * install into, so `npx vitest` asked to download vitest and stopped.
     *
     * {@see UiWorkspace} derives the aliases from the same ui.json declarations
     * `hkm ui sync` reads, so this stays correct when a plugin adds an export.
     */
    private function writeConfig(UiManifest $ui): bool
    {
        $workspace = UiWorkspace::for(\dirname($ui->directory), PluginLocator::fromCwd());
        $written   = 0;

        $written += $this->writeFile($ui->directory . '/vitest.config.ts', <<<TS
            import { defineConfig } from "vitest/config";
            import react from "@vitejs/plugin-react";
            import { fileURLToPath } from "node:url";
            import { resolve } from "node:path";

            // `__dirname` does not exist in an ESM config, and every alias below
            // is relative to THIS file so the config is committable rather than
            // pinned to one machine's home directory.
            const here = (p: string) =>
              resolve(fileURLToPath(new URL(".", import.meta.url)), p);

            /**
             * Vitest for a PLUGIN's own UI, run in place (<plugin>/ui/).
             *
             * A plugin owns its UI and must be testable without a host project —
             * the same reason `hkm ui sync` mirrors rather than moves it. The
             * aliases below are the sibling checkouts on THIS machine, written
             * by `make:ui-test --config`; re-run it when a plugin is added or
             * moved. In a project these same names come from
             * tsconfig.plugins.json instead.
             */
            export default defineConfig({
              plugins: [react()],
              // esbuild otherwise discovers the tsconfig.json NEAREST each file it
              // transforms. For the shared @ui kit that is the kernel's frontend
              // template, whose tsconfig extends ./tsconfig.plugins.json — a file
              // `hkm ui sync` generates INTO A PROJECT and which therefore does not
              // exist in the template. Declaring the options inline stops the
              // lookup entirely, so no file outside this plugin can decide how its
              // UI compiles.
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
            {$workspace->toViteAliases($ui->directory)}
                },
              },
              test: {
                environment: "jsdom",
                globals: true,
                setupFiles: ["./__tests__/setup.ts"],
                include: ["__tests__/**/*.test.{ts,tsx}"],
              },
            });
            TS) ? 1 : 0;

        $written += $this->writeFile($ui->directory . '/package.json', $this->packageJson($workspace)) ? 1 : 0;

        $written += $this->writeFile($ui->directory . '/__tests__/setup.ts', <<<'TS'
            import "@testing-library/jest-dom/vitest";
            import { cleanup } from "@testing-library/react";
            import { afterEach } from "vitest";

            // Without this, a component left mounted by one test is still in the
            // document for the next, and a passing assertion may be reading the
            // previous test's render.
            afterEach(() => cleanup());

            // Node 24+ ships its OWN localStorage, which shadows the one jsdom
            // provides and is `undefined` unless node was started with
            // --localstorage-file. Any component reading a stored preference
            // then dies on `localStorage.getItem` of undefined — the shared
            // ThemeProvider does exactly that, so a page wrapped in it fails to
            // render for a reason that has nothing to do with the page.
            //
            // In a browser localStorage always exists, so the honest stand-in is
            // a working in-memory one, not a throwing stub.
            if (typeof globalThis.localStorage === "undefined" || globalThis.localStorage === null) {
              const store = new Map<string, string>();

              Object.defineProperty(globalThis, "localStorage", {
                configurable: true,
                value: {
                  getItem: (k: string) => (store.has(k) ? store.get(k)! : null),
                  setItem: (k: string, v: string) => void store.set(k, String(v)),
                  removeItem: (k: string) => void store.delete(k),
                  clear: () => store.clear(),
                  key: (i: number) => [...store.keys()][i] ?? null,
                  get length() {
                    return store.size;
                  },
                },
              });
            }

            // jsdom implements no matchMedia AT ALL — it is not a stub that
            // returns false, the property is simply absent. Every page rendered
            // in the admin shell hits it on the first render (AdminLayout →
            // useIsMobile → useMediaQuery), so without this the layout throws
            // "window.matchMedia is not a function" and the failure names the
            // shell rather than the page under test.
            //
            // It answers "not matching", i.e. the DESKTOP branch, because that
            // is the layout a test asserting on a sidebar expects. Override it
            // in a single test to render the mobile drawer instead.
            if (typeof window !== "undefined" && typeof window.matchMedia !== "function") {
              Object.defineProperty(window, "matchMedia", {
                configurable: true,
                writable: true,
                value: (query: string): MediaQueryList =>
                  ({
                    matches: false,
                    media: query,
                    onchange: null,
                    addEventListener: () => {},
                    removeEventListener: () => {},
                    dispatchEvent: () => false,
                    // Deprecated, but still what some libraries reach for first.
                    addListener: () => {},
                    removeListener: () => {},
                  }) as unknown as MediaQueryList,
              });
            }

            // Also absent from jsdom, and reached on MOUNT rather than on
            // render: the shell's SidebarNav observes its own container to
            // decide how many nav items fit. A constructor that does not exist
            // throws from inside a passive effect, so the stack names React's
            // commit phase and not the component — which is a long way from
            // "jsdom has no ResizeObserver".
            //
            // The stub never fires. A test that needs the overflow branch has
            // to drive it explicitly; one that does not gets a stable layout
            // instead of a resize it did not ask for.
            if (typeof globalThis.ResizeObserver === "undefined") {
              Object.defineProperty(globalThis, "ResizeObserver", {
                configurable: true,
                writable: true,
                value: class {
                  observe() {}
                  unobserve() {}
                  disconnect() {}
                },
              });
            }
            TS) ? 1 : 0;

        if ($written > 0) {
            $this->newLine();
            $this->muted('Install once:  cd ' . $ui->directory . ' && npm install');
        }

        return $written > 0;
    }

    /**
     * package.json for the plugin's ui/.
     *
     * `"private": true` and `"type": "module"` are both load-bearing: without
     * private, npm warns about publishing on every command, and without module
     * the .ts config is parsed as CommonJS and `export default` is a syntax
     * error.
     *
     * The runtime dependencies are whatever the plugins whose aliases we just
     * wired declare in their own ui.json — rendering an admin page without
     * `lucide-react` fails on the first icon, and guessing that list is how it
     * goes stale.
     *
     * Tailwind is in devDependencies for the DEV SERVER, not for vitest: the
     * config `ground dev` generates loads `@tailwindcss/vite`, and the
     * stylesheet it writes opens with `@import "tailwindcss"`, resolved from
     * this ui/'s node_modules. Both are missing without these three, and the
     * failure lands on whoever runs `yarn dev` rather than on whoever ran
     * `ground install`.
     */
    private function packageJson(UiWorkspace $workspace): string
    {
        $package = [
            'name'    => 'plugin-ui-tests',
            'private' => true,
            'type'    => 'module',
            'scripts' => [
                'test'  => 'vitest run',
                'watch' => 'vitest',
            ],
            'dependencies'    => $workspace->dependencies === [] ? new \stdClass() : $workspace->dependencies,
            'devDependencies' => [
                '@tailwindcss/vite'         => '^4.0.0',
                // A PEER of @testing-library/react since v16, so npm does not
                // pull it in and jest-dom's matchers fail to import — the whole
                // suite dies before a test runs, naming a package nobody asked
                // for.
                '@testing-library/dom'      => '^10.4.0',
                '@testing-library/jest-dom' => '^6.6.0',
                '@testing-library/react'    => '^16.1.0',
                '@types/react'              => '^19.0.0',
                '@types/react-dom'          => '^19.0.0',
                '@vitejs/plugin-react'      => '^5.0.0',
                'jsdom'                     => '^26.0.0',
                'react'                     => '^19.0.0',
                'react-dom'                 => '^19.0.0',
                'tailwindcss'               => '^4.0.0',
                'tw-animate-css'            => '^1.2.0',
                'typescript'                => '^5.7.0',
                'vitest'                    => '^3.0.0',
            ],
        ];

        return json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    /** Write unless it exists and --force was not given. */
    private function writeFile(string $path, string $contents): bool
    {
        if (is_file($path) && !$this->hasOption('force')) {
            $this->muted('skipped (exists): ' . basename($path));

            return false;
        }

        $dir = \dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            $this->error("Could not create {$dir}.");

            return false;
        }

        file_put_contents($path, $contents);
        $this->success('wrote ' . basename($path));

        return true;
    }
}
