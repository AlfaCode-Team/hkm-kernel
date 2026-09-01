<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Tests;

use AlfacodeTeam\Ground\Inspection\PluginLocator;
use AlfacodeTeam\Ground\Ui\DevWorkspace;
use PHPUnit\Framework\TestCase;

/**
 * `yarn dev` inside a plugin: the generated Vite workspace.
 *
 * The parts asserted here are the ones whose breakage is SILENT. A wrong alias
 * or a wrong glob depth does not fail a build — the dev server starts, reports
 * itself ready, and then cannot resolve a page, which looks like a problem with
 * the page.
 */
final class DevWorkspaceTest extends TestCase
{
    private string $plugin;

    protected function setUp(): void
    {
        $this->plugin = sys_get_temp_dir() . '/ground-dev-' . bin2hex(random_bytes(6));

        // A plugin with two surfaces and one page under each.
        mkdir($this->plugin . '/ui/admin/Pages/Thing', 0o775, true);
        mkdir($this->plugin . '/ui/site/Pages', 0o775, true);

        file_put_contents($this->plugin . '/module.json', json_encode([
            'name'         => 'sample',
            'solves'       => 'sample.domain',
            'type'         => 'module',
            'requires'     => ['database.management'],
            'routePrefix'  => '/api',
            'routeFilters' => ['auth'],
            'routes'       => [
                ['method' => 'GET', 'path' => '/things', 'handler' => 'X\\ThingController@index'],
            ],
            'groups'       => [
                [
                    'prefix'  => '/admin',
                    'name'    => 'sample.admin.',
                    'filters' => ['throttle:60,1'],
                    'routes'  => [
                        ['method' => 'GET', 'path' => '/audit', 'handler' => 'X\\AuditController@audit', 'name' => 'audit'],
                        ['method' => 'GET', 'path' => '/things/{id:num}', 'handler' => 'X\\ThingController@show'],
                    ],
                ],
            ],
        ]));

        file_put_contents($this->plugin . '/ui/ui.json', json_encode([
            'alias'     => '@sample',
            'entry'     => 'index.ts',
            'framework' => 'react',
            'surfaces'  => ['admin' => 'admin/Pages', 'site' => 'site/Pages'],
        ]));

        file_put_contents($this->plugin . '/ui/admin/Pages/Thing/Index.tsx', 'export default () => null;');
        file_put_contents($this->plugin . '/ui/site/Pages/Home.tsx', 'export default () => null;');
    }

    protected function tearDown(): void
    {
        self::removeTree($this->plugin);
    }

    public function testGeneratesOneEntryPerDeclaredSurface(): void
    {
        $this->workspace()->generate();

        self::assertFileExists($this->plugin . '/ui/.ground/vite.config.ts');
        self::assertFileExists($this->plugin . '/ui/.ground/src/surfaces/admin/index.tsx');
        self::assertFileExists($this->plugin . '/ui/.ground/src/surfaces/site/index.tsx');
    }

    /**
     * The entry path must be the one the Pageflow layout asks for by DEFAULT.
     *
     * The layout requests `src/surfaces/{surface}/index.tsx` unless a controller
     * overrides it. Rooting Vite at ui/.ground and generating exactly that path
     * is what removes the need to inject a `viteEntry` prop — so if this path
     * ever moves, every page 404s its own JavaScript.
     */
    public function testEntryPathMatchesTheLayoutsDefault(): void
    {
        $this->workspace()->generate();

        foreach (['admin', 'site'] as $surface) {
            self::assertFileExists(
                $this->plugin . "/ui/.ground/src/surfaces/{$surface}/index.tsx",
                "The layout asks for src/surfaces/{$surface}/index.tsx relative to the vite root.",
            );
        }
    }

    /**
     * The globs are relative to the generated entry, four directories deep.
     *
     * Counting those hops wrong yields an EMPTY page map: Vite resolves the
     * glob to nothing, starts happily, and every page then throws "not found"
     * at runtime. So they are resolved here against the real tree.
     */
    public function testGlobsResolveToTheRealPages(): void
    {
        $this->workspace()->generate();

        $entry = (string) file_get_contents($this->plugin . '/ui/.ground/src/surfaces/admin/index.tsx');
        $base  = $this->plugin . '/ui/.ground/src/surfaces/admin';

        self::assertSame(
            2,
            preg_match_all('/import\.meta\.glob\("([^"]+)"\)/', $entry, $matches),
            'Both surfaces should be registered in every entry.',
        );

        // PHP's glob() has no `**` — that is a fast-glob feature Vite provides,
        // and PHP silently treats it as a single `*`. So the DIRECTORY prefix is
        // resolved here and scanned recursively, which is what `**` means and
        // what actually has to be right: the number of `../` hops from the
        // generated entry back to the plugin's ui/.
        $found = [];
        foreach ($matches[1] as $glob) {
            $prefix = substr($glob, 0, (int) strpos($glob, '/**/'));
            $dir    = realpath($base . '/' . $prefix);

            self::assertNotFalse($dir, "The glob prefix '{$prefix}' resolves to no directory.");

            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() === 'tsx') {
                    $found[] = $file->getPathname();
                }
            }
        }

        self::assertCount(2, $found, 'The globs must reach the plugin\'s actual page files.');
    }

    /**
     * BOTH surfaces are registered in EVERY entry, deliberately.
     *
     * The server may render a component authored under site/Pages onto the
     * admin surface; the component key carries no surface in it. Registering
     * only the active surface would 404 exactly those pages — and only at
     * runtime, on the one page that crosses over.
     */
    public function testEverySurfaceEntryRegistersEverySurfacesPages(): void
    {
        $this->workspace()->generate();

        foreach (['admin', 'site'] as $surface) {
            $entry = (string) file_get_contents($this->plugin . "/ui/.ground/src/surfaces/{$surface}/index.tsx");

            self::assertStringContainsString('admin/Pages/**/*.tsx', $entry);
            self::assertStringContainsString('site/Pages/**/*.tsx', $entry);
        }
    }

    /** The hot file is the entire handshake with PHP; its location is a contract. */
    public function testPublicPathIsWhereViteManifestLooks(): void
    {
        $dev = $this->workspace();

        self::assertSame(
            $this->plugin . '/ui/.ground/public',
            $dev->publicPath(),
            'VITE_PUBLIC_PATH points here; ViteManifest reads {publicPath}/{surface}-hot.',
        );
    }

    public function testAddsTheDevScriptWithoutDisturbingPackageJson(): void
    {
        file_put_contents($this->plugin . '/ui/package.json', json_encode([
            'name'    => 'plugin-ui-tests',
            'private' => true,
            'scripts' => ['test' => 'vitest run'],
        ]));

        $this->workspace()->generate();

        $package = json_decode((string) file_get_contents($this->plugin . '/ui/package.json'), true);

        self::assertSame('vite --config .ground/vite.config.ts', $package['scripts']['dev']);
        self::assertSame('vitest run', $package['scripts']['test'], 'Existing scripts must survive.');
        self::assertTrue($package['private']);
    }

    public function testReportsWhyAPluginWithNoUiIsUnsupported(): void
    {
        $bare = sys_get_temp_dir() . '/ground-bare-' . bin2hex(random_bytes(6));
        mkdir($bare, 0o775, true);
        file_put_contents($bare . '/module.json', '{"name":"bare","solves":"bare.d","type":"module"}');

        try {
            $dev = DevWorkspace::for($bare, new PluginLocator($bare, includeSiblings: false));

            self::assertFalse($dev->supported());
            self::assertStringContainsString('no ui/ui.json', (string) $dev->unsupportedReason());
        } finally {
            self::removeTree($bare);
        }
    }

    public function testSurfacesWithoutPagesAreReportedRatherThanServed(): void
    {
        self::removeTree($this->plugin . '/ui/admin/Pages');
        self::removeTree($this->plugin . '/ui/site/Pages');

        $dev = $this->workspace();

        self::assertFalse($dev->supported());
        self::assertStringContainsString('Pages', (string) $dev->unsupportedReason());
    }

    /**
     * A page that declares NO layout still opens inside the admin shell.
     *
     * Pageflow's <App> renders such a page bare. That is right in production —
     * a public page has no admin chrome — and wrong on the bench, where the
     * author is looking at the page to find out whether it works and a
     * chromeless white document reads like the page is broken rather than like
     * the one-line `.layout` assignment has not been written yet.
     *
     * Asserted against the bench source, because that is where the decision
     * moved when the frame took it over. The behaviour itself is covered by the
     * plugin-side vitest suite, which mounts the real components.
     */
    public function testAPageWithoutItsOwnLayoutStillGetsTheAdminShell(): void
    {
        $frame = $this->benchSource('GroundFrame.tsx');

        self::assertStringContainsString(
            '<AdminLayout>{child}</AdminLayout>',
            $frame,
            'A page declaring no layout must fall back to the admin shell.',
        );
        self::assertStringContainsString(
            '(layout as (node: ReactNode) => ReactNode)(child)',
            $frame,
            "A page's OWN layout must still win over the fallback.",
        );
        self::assertStringContainsString(
            'Array.isArray(layout)',
            $frame,
            'Pageflow also accepts an array of layouts; dropping that form would change behaviour under the bench.',
        );
    }

    /**
     * A site page renders the way a PROJECT renders it: bare.
     *
     * The fallback used to apply on both faces, which put an admin sidebar,
     * a breadcrumb and a clock around `/register` — chrome no deployment of
     * that page has ever had. It is the exact failure the frame exists to avoid
     * in the other direction: the bench stopped showing what the browser shows.
     *
     * The admin face keeps the fallback, because there the alternative is
     * unstyled markup on a white document, which reads like the page is broken
     * rather than like its one-line `.layout` has not been written yet.
     */
    public function testTheFallbackIsFaceAware(): void
    {
        $frame = $this->benchSource('GroundFrame.tsx');

        self::assertStringContainsString(
            'face === "site" ? child : <AdminLayout>{child}</AdminLayout>',
            $frame,
            'A site/Pages page must fall back to nothing, an admin/Pages page to the shell.',
        );

        // The face comes from the generated manifest, which already records it
        // per page — not from a second convention invented in the frame.
        self::assertStringContainsString(
            'manifest.pages.find((candidate) => candidate.component === page.component)?.face',
            $frame,
        );
        self::assertStringContainsString(
            '?? "admin"',
            $frame,
            'An unclassified component keeps the shell; guessing "site" reintroduces the unstyled-markup case.',
        );
    }

    /**
     * The entry imports a stylesheet, and that stylesheet loads Tailwind.
     *
     * The pages, the shared @ui kit and @pageflow/admin's shell carry no CSS of
     * their own — they are Tailwind-only. Without this the dev server starts,
     * the page renders, and every class in it resolves to nothing.
     */
    public function testTheGeneratedEntryHasAStylesheetToImport(): void
    {
        $this->workspace()->generate();

        $css = $this->plugin . '/ui/.ground/src/styles/index.css';

        self::assertFileExists($css);

        $contents = (string) file_get_contents($css);

        self::assertStringContainsString('@import "tailwindcss";', $contents);

        foreach (['admin', 'site'] as $surface) {
            $entry = (string) file_get_contents($this->plugin . "/ui/.ground/src/surfaces/{$surface}/index.tsx");

            self::assertStringContainsString('import "../../styles/index.css";', $entry);
        }

        // The path in that import has to resolve, not merely look plausible.
        self::assertFileExists($this->plugin . '/ui/.ground/src/surfaces/admin/../../styles/index.css');
    }

    /**
     * The shell's chrome tokens are in the stylesheet the entry loads.
     *
     * @pageflow/admin's shell names eleven `--sidebar-*` custom properties
     * (`bg-sidebar-bg`, `text-sidebar-fg-muted`, `w-[var(--sidebar-width)]`, …).
     * They were defined nowhere for a while — the shell was ported out of HKM
     * 0.3 and its stylesheet was left behind — so the sidebar rendered
     * transparent with no width. That is invisible to every build: an undefined
     * custom property is not an error.
     */
    public function testTheSidebarTokensTheShellReadsAreDefined(): void
    {
        $this->workspace()->generate();

        $css = (string) file_get_contents($this->plugin . '/ui/.ground/src/styles/index.css');

        foreach ([
            'bg', 'fg', 'fg-muted', 'fg-active', 'border', 'hover', 'active', 'section',
        ] as $token) {
            self::assertStringContainsString("--sidebar-{$token}:", $css, "--sidebar-{$token} is unset.");
            self::assertStringContainsString(
                "--color-sidebar-{$token}: hsl(var(--sidebar-{$token}));",
                $css,
                "sidebar-{$token} has a value but no Tailwind utility maps onto it.",
            );
        }

        self::assertStringContainsString('--sidebar-width:', $css);
        self::assertStringContainsString('@utility sidebar-transition', $css);
    }

    /**
     * The `@source` directives reach the code, and stop short of node_modules.
     *
     * Tailwind v4 scans outward from the stylesheet and stops at gitignored
     * directories, so nothing that matters here is found automatically: the
     * pages sit above a gitignored `.ground/`, and the kit and the shell are in
     * sibling checkouts. But an EXPLICIT @source is taken literally and does not
     * skip node_modules — so a single `@source "../../../**"` would walk every
     * installed package on every rebuild.
     */
    public function testSourceDirectivesReachThePagesWithoutWalkingNodeModules(): void
    {
        mkdir($this->plugin . '/ui/node_modules/react', 0o775, true);

        $this->workspace()->generate();

        $base = $this->plugin . '/ui/.ground/src/styles';
        $css  = (string) file_get_contents($base . '/index.css');

        preg_match_all('/@source "([^"]+)";/', $css, $matches);

        self::assertNotEmpty($matches[1], 'Without an @source, Tailwind emits none of the shell\'s classes.');

        $resolved = [];
        foreach ($matches[1] as $glob) {
            $prefix = substr($glob, 0, (int) strpos($glob, '/**/'));

            self::assertStringNotContainsString(
                'node_modules',
                $prefix,
                'An explicit @source is literal — it would scan every installed package.',
            );

            $dir = realpath($base . '/' . $prefix);
            if ($dir !== false) {
                $resolved[] = $dir;
            }
        }

        self::assertContains(
            realpath($this->plugin . '/ui/admin'),
            $resolved,
            'The plugin\'s own pages have to be scanned, or their classes are purged.',
        );
    }

    /**
     * EVERY declared surface goes hot, not only the one `--mode` names.
     *
     * PHP looks for `{surface}-hot` for whichever surface the CONTROLLER named.
     * A plugin whose pages render on `site` therefore got no script tags at all
     * while `yarn dev` sat there reporting itself ready on `admin` — the page
     * came back as a bare shell with an empty #app, no error anywhere, which is
     * indistinguishable from the app being broken.
     *
     * One server serves every entry under the same root, so marking them all
     * hot is honest rather than generous.
     */
    public function testEverySurfaceIsMarkedHotByOneServer(): void
    {
        $config = $this->generatedConfig();

        self::assertStringContainsString('hotFile(SURFACES)', $config);
        self::assertStringContainsString('surfaces.map((s) => resolve(here("public"), `${s}-hot`))', $config);
        self::assertStringNotContainsString(
            'hotFile(surface)',
            $config,
            'A single-surface hot file is what left site-rendered pages with no scripts.',
        );
    }

    /**
     * A dev server that never bound must not delete a running one's hot file.
     *
     * The cleanup used to be armed in configureServer, which runs BEFORE the
     * port is bound. With strictPort, a second `yarn dev` on a taken port exits
     * during startup — and on the way out removed the hot file belonging to the
     * server that was working fine. PHP then stopped emitting any script tag,
     * so every page went blank, and nothing connected that to the command
     * someone had just run in another terminal.
     */
    public function testAFailedStartCannotClearARunningServersHotFile(): void
    {
        $config = $this->generatedConfig();

        self::assertStringContainsString('let owned = false;', $config);
        self::assertStringContainsString('if (!owned) return;', $config);

        // Ownership is claimed inside the `listening` handler, and the process
        // hooks are armed there too — after the bind, never before it.
        $listening = substr($config, (int) strpos($config, 'once("listening"'));
        $armed     = strpos($listening, 'process.once("exit", clean)');
        $claimed   = strpos($listening, 'owned = true;');

        self::assertNotFalse($armed, 'The exit hook must live inside the listening handler.');
        self::assertNotFalse($claimed);
        self::assertLessThan($armed, $claimed, 'Ownership is claimed before the teardown hooks are armed.');
    }

    /** Tailwind has to be a Vite plugin too, not only an @import. */
    public function testTheViteConfigLoadsTailwind(): void
    {
        $this->workspace()->generate();

        $config = (string) file_get_contents($this->plugin . '/ui/.ground/vite.config.ts');

        self::assertStringContainsString('import tailwindcss from "@tailwindcss/vite";', $config);
        self::assertStringContainsString('tailwindcss()', $config);
    }

    /**
     * The bench is chrome AROUND the page, never a replacement for the shell.
     *
     * A ground-specific layout in place of the admin one would cost the single
     * property that makes this package worth having: `ground serve` runs the
     * real pipeline so that a page rendering here is a page the browser
     * renders. So the entry hands the real component to the frame and the frame
     * renders it untouched — it does not construct the page itself.
     */
    public function testTheEntryFramesThePageRatherThanReplacingItsLayout(): void
    {
        $this->workspace()->generate();

        $entry = (string) file_get_contents($this->plugin . '/ui/.ground/src/surfaces/admin/index.tsx');

        self::assertStringContainsString('import { GroundFrame } from "@ground/dev";', $entry);
        self::assertStringContainsString('<GroundFrame manifest={manifest} component={Component}>', $entry);
        self::assertStringContainsString('createElement(Component, { key, ...props })', $entry);
    }

    /**
     * The plugin's nav.ts is imported for its side effect.
     *
     * `registerModule()` runs at import time and NOTHING had ever imported the
     * file — not this entry and not the kernel's surface template, although the
     * admin README says the surface globs it. The registry was therefore empty
     * everywhere, and no configuration of any project could put an item in the
     * sidebar.
     */
    public function testTheEntryImportsTheNavRegistration(): void
    {
        $this->workspace()->generate();

        $entry = (string) file_get_contents($this->plugin . '/ui/.ground/src/surfaces/admin/index.tsx');

        self::assertMatchesRegularExpression(
            '/import\.meta\.glob\("[^"]*admin\/nav\.ts", \{ eager: true \}\)/',
            $entry,
            'Eager: a registration that has not run by first paint is a nav that renders empty and then jumps.',
        );
    }

    /**
     * The bench manifest carries paths a browser can be pointed at.
     *
     * Prefixes are the whole point. A route written `/things/{id:num}` inside
     * `{"prefix": "/admin"}` under `"routePrefix": "/api"` is served at
     * `/api/admin/things/{id:num}`, and a route list showing the declaration
     * would be listing URLs that 404.
     */
    public function testTheBenchManifestResolvesPrefixesFiltersAndNames(): void
    {
        $this->workspace()->generate();

        $manifest = $this->benchManifest();

        $paths = array_column($manifest['routes'], 'path');
        self::assertContains('/api/things', $paths);
        self::assertContains('/api/admin/audit', $paths);
        self::assertContains('/api/admin/things/{id:num}', $paths);

        $audit = $this->routeFor($manifest, '/api/admin/audit');
        self::assertSame('sample.admin.audit', $audit['name'], 'Group names concatenate outward-in.');
        self::assertSame(['auth', 'throttle:60,1'], $audit['filters'], 'Module filters, then the group\'s.');
        self::assertFalse($audit['dynamic']);

        $show = $this->routeFor($manifest, '/api/admin/things/{id:num}');
        self::assertNull($show['name'], 'An unnamed route stays unnamed, prefix or not.');
        self::assertTrue($show['dynamic'], 'A parameterised path is not a URL and must not be offered as one.');
    }

    /**
     * A page reachable by no route is listed, and says so.
     *
     * That is the case the bench exists for: with no URL to type, such a page
     * could not be looked at in a browser at all without adding a throwaway
     * route to module.json.
     */
    public function testPagesAreMatchedToTheRouteThatRendersThem(): void
    {
        file_put_contents($this->plugin . '/ui/admin/Pages/Thing/Orphan.tsx', 'export default () => null;');

        $this->workspace()->generate();

        $pages = [];
        foreach ($this->benchManifest()['pages'] as $page) {
            $pages[$page['component']] = $page['path'];
        }

        self::assertSame('/api/things', $pages['Thing/Index'], 'ThingController@index renders Thing/Index.');
        self::assertNull($pages['Thing/Orphan'], 'No handler names it — the bench must not invent a link.');
        self::assertArrayHasKey('Home', $pages, 'Site pages are listed too.');
    }

    /**
     * The bench's own alias comes from UiWorkspace, so BOTH generated configs
     * carry it.
     *
     * It cannot come from the locator: that globs `*&#47;module.json` one level
     * down and ground keeps its manifest at `src/module.json`, so ground never
     * appears in its own scan. When it lived in DevWorkspace instead, the dev
     * server resolved `@ground/dev` and vitest did not — and the failure named
     * the generated entry rather than the missing alias.
     */
    public function testTheBenchAliasIsPartOfTheSharedWorkspace(): void
    {
        $aliases = $this->workspace()->workspace->aliases;

        self::assertArrayHasKey('@ground/dev', $aliases);
        self::assertFileExists($aliases['@ground/dev']);

        self::assertStringContainsString('"@ground/dev"', $this->generatedConfig());
    }

    /** The bench's own chrome is Tailwind, and lives in a third tree the plugin's sources never reach. */
    public function testTheStylesheetScansTheBenchToo(): void
    {
        $this->workspace()->generate();

        $base = $this->plugin . '/ui/.ground/src/styles';
        $css  = (string) file_get_contents($base . '/index.css');

        preg_match_all('/@source "([^"]+)";/', $css, $matches);

        // Matched on the SUFFIX rather than resolved with realpath: the plugin
        // here lives under sys_get_temp_dir(), which on macOS is /var/folders
        // symlinked from /private/var, so a relative hop count computed against
        // one spelling does not resolve against the other. That is an artefact
        // of the temporary directory, not of the generated path — the real
        // checkouts this runs against share a root.
        $found = false;
        foreach ($matches[1] as $glob) {
            if (str_ends_with($glob, '/ui/dev/**/*.{ts,tsx}')) {
                $found = true;
            }
        }

        self::assertTrue($found, "Without this, every class in the bench's own chrome is purged.");
        unset($base);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function workspace(): DevWorkspace
    {
        return DevWorkspace::for($this->plugin, new PluginLocator($this->plugin, includeSiblings: false));
    }

    /**
     * A file from ground's own `ui/dev`, located the way the workspace locates
     * it — by reflection, not by counting directories up from the test.
     */
    private function benchSource(string $file): string
    {
        $bench = \AlfacodeTeam\Ground\Ui\UiWorkspace::benchPath();

        self::assertNotNull($bench, 'ground ships ui/dev; without it there is no bench to generate.');

        $path = \dirname($bench) . '/' . $file;

        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function generatedConfig(): string
    {
        $this->workspace()->generate();

        return (string) file_get_contents($this->plugin . '/ui/.ground/vite.config.ts');
    }

    /**
     * The generated ground.manifest.ts, decoded.
     *
     * It is a TypeScript module wrapping one JSON object literal, so the object
     * is read back out rather than the file being parsed — asserting on the
     * DATA is what these tests are about, and asserting on the wrapper would
     * break on a comment change.
     *
     * @return array{name: string, routes: list<array<string, mixed>>, pages: list<array<string, mixed>>}
     */
    private function benchManifest(): array
    {
        $source = (string) file_get_contents($this->plugin . '/ui/.ground/src/ground.manifest.ts');

        // Anchored past the `import type { GroundManifest }` line: its braces
        // are the first in the file, so a naive strpos('{') reads the import.
        $marker = 'export const manifest: GroundManifest = ';
        $start  = strpos($source, $marker);

        self::assertNotFalse($start, 'The manifest module must export `manifest`.');

        $start += \strlen($marker);
        $end    = strrpos($source, '}');

        self::assertNotFalse($end);

        $decoded = json_decode(substr($source, $start, $end - $start + 1), true);

        self::assertIsArray($decoded, 'The generated manifest must be valid JSON inside the module.');

        return $decoded;
    }

    /** @param array{routes: list<array<string, mixed>>} $manifest */
    private function routeFor(array $manifest, string $path): array
    {
        foreach ($manifest['routes'] as $route) {
            if ($route['path'] === $path) {
                return $route;
            }
        }

        self::fail("No route compiled to {$path}.");
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($path);
    }
}
