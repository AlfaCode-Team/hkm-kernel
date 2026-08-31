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
            'name'   => 'sample',
            'solves' => 'sample.domain',
            'type'   => 'module',
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

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function workspace(): DevWorkspace
    {
        return DevWorkspace::for($this->plugin, new PluginLocator($this->plugin, includeSiblings: false));
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
