<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Tests;

use AlfacodeTeam\Ground\PluginGround;
use AlfacodeTeam\Ground\PluginGroundTestCase;
use AlfacodeTeam\Ground\Tests\Fixtures\Sample\Provider;
use AlfacodeTeam\Ground\Ui\PageResolver;
use AlfacodeTeam\Ground\Ui\UiManifest;

/**
 * The visual half: the Pageflow page object, and whether a page file exists for
 * the component a route names.
 *
 * The live integration against the real Pageflow plugin cannot live here — this
 * package does not depend on it, and requiring it would mean nothing in this
 * suite runs without it installed. The fixture reproduces the page shape from
 * PageflowPage::toArray() and the X-Pageflow header from
 * PageflowResponder::jsonResponse() instead.
 */
final class UiTest extends PluginGroundTestCase
{
    /** @var list<string> */
    private array $temporary = [];

    protected function plugin(): string
    {
        return Provider::class;
    }

    protected function tearDown(): void
    {
        foreach ($this->temporary as $dir) {
            self::removeTree($dir);
        }

        parent::tearDown();
    }

    // ── The page object ───────────────────────────────────────────────────────

    public function testItReadsTheComponentAndPropsFromAnXhrNavigation(): void
    {
        $page = $this->ground()->pageflow('/samples/page');

        $this->assertComponent('Sample/Index', $page);
        $this->assertPageProp($page, 'title', 'Samples');
        $this->assertPageProp($page, 'user.id', 'u1');
        self::assertSame('/samples/page', $page->url());
    }

    public function testItParsesThePageObjectBackOutOfAFullPageLoad(): void
    {
        $page = $this->ground()->pageflowLoad('/samples/shell');

        // The current client boots from the root element's data-page attribute.
        // A ground that only understood the legacy window.initialPage would
        // report an empty page for every real application.
        self::assertTrue($page->fromHtml);
        $this->assertComponent('Sample/Index', $page);
        $this->assertPageProp($page, 'title', 'Samples');
    }

    public function testAResponseWithNoPageObjectIsReportedAsSuch(): void
    {
        $page = $this->ground()->pageflow('/samples/7');

        self::assertFalse($page->isPage());
        self::assertStringContainsString('No Pageflow page object', $page->describe());
    }

    public function testAbsentPropsAreDetectable(): void
    {
        $page = $this->ground()->pageflow('/samples/page');

        // The assertion that keeps a secret out of a page: props are serialized
        // into the page object and shipped to the browser.
        $this->assertNotProp($page, 'user.password_hash');
        $this->assertHasProp($page, 'user.id');
    }

    public function testTheFixtureDumpCarriesTheRealResponse(): void
    {
        $dir  = $this->tempDir();
        $path = $dir . '/nested/page.json';

        $this->ground()->pageflow('/samples/page')->writeFixture($path);

        $decoded = json_decode((string) file_get_contents($path), true);

        self::assertSame('Sample/Index', $decoded['component']);
        self::assertSame('Samples', $decoded['props']['title']);
    }

    // ── Page-file resolution ──────────────────────────────────────────────────

    public function testTheComponentARouteNamesHasAPageFile(): void
    {
        $page = $this->ground()->pageflow('/samples/page');

        // Both halves — the route returns this component AND a file serves it.
        $this->assertRendersPage($page, 'Sample/Index');
    }

    public function testAComponentWithNoPageFileDoesNotResolve(): void
    {
        self::assertFalse($this->ground()->pages('admin')->resolves('Sample/Missing'));
    }

    public function testTheAdminSurfaceAlsoSeesSitePages(): void
    {
        $dir = $this->pluginWithPages(['site/Pages/Public/Home.tsx']);

        // Not a quirk — src/surfaces/admin/index.tsx globs BOTH faces, so a page
        // authored under site/Pages is servable on the admin surface.
        self::assertTrue(PageResolver::forPlugin($dir, 'admin')->resolves('Public/Home'));
        self::assertTrue(PageResolver::forPlugin($dir, 'project')->resolves('Public/Home'));
    }

    public function testTheProjectSurfaceDoesNotSeeAdminPages(): void
    {
        $dir = $this->pluginWithPages(['admin/Pages/Secret/Panel.tsx']);

        self::assertTrue(PageResolver::forPlugin($dir, 'admin')->resolves('Secret/Panel'));
        self::assertFalse(PageResolver::forPlugin($dir, 'project')->resolves('Secret/Panel'));
    }

    public function testResolutionIsBySuffixExactlyAsTheSurfaceDoesIt(): void
    {
        $dir = $this->pluginWithPages(['admin/Pages/Deep/Nested/User/Index.tsx']);

        // The surface compares `k.endsWith('/' + name + '.tsx')` and nothing
        // else. Resolving "more correctly" here would report a page missing
        // that the browser finds.
        self::assertTrue(PageResolver::forPlugin($dir, 'admin')->resolves('User/Index'));
        self::assertTrue(PageResolver::forPlugin($dir, 'admin')->resolves('Nested/User/Index'));
    }

    public function testAProjectPageIsSearchedBeforeAPluginPage(): void
    {
        $plugin  = $this->pluginWithPages(['admin/Pages/User/Index.tsx']);
        $project = $this->tempDir();
        self::write($project . '/src/surfaces/admin/Pages/User/Index.tsx', 'export default () => null;');

        $resolved = PageResolver::empty()
            ->withPluginDirectory($plugin, 'admin')
            ->withProject($project, 'admin')
            ->resolve('User/Index');

        // Project-over-plugin, the same precedence the whole framework applies
        // to routes and views.
        self::assertStringStartsWith($project, (string) $resolved);
    }

    public function testItSuggestsTheNearestNameForATypo(): void
    {
        $dir = $this->pluginWithPages(['admin/Pages/User/Index.tsx']);

        self::assertSame(['User/Index'], PageResolver::forPlugin($dir, 'admin')->nearest('User/Idnex', 1));
    }

    // ── ui.json ───────────────────────────────────────────────────────────────

    public function testTheGroundExposesThePluginsUiManifest(): void
    {
        self::assertTrue($this->ground()->ui()->exists);
        self::assertSame('@ground-sample', $this->ground()->ui()->alias());
    }

    public function testAPluginWithNoUiDirectoryIsNotAFinding(): void
    {
        $ui = UiManifest::for($this->tempDir());

        self::assertFalse($ui->exists);
        self::assertFalse($ui->hasPages());
    }

    /**
     * A PHP source directory is not a frontend, even when it is spelled `Ui/`.
     *
     * macOS and Windows match `Ui/` when the code asks for `ui/`, so a plugin
     * with a `Plugins\X\Ui\` namespace — this package has one — was reported as
     * shipping an undeclared frontend, with a `ui.no-manifest` warning no edit
     * could clear. And because Linux answers differently, CI and a laptop
     * disagreed about whether the same plugin was clean.
     */
    public function testAPhpSourceDirectoryNamedUiIsNotMistakenForAFrontend(): void
    {
        $root = $this->tempDir();
        mkdir($root . '/ui', 0o700, true);
        file_put_contents($root . '/ui/PageResolver.php', "<?php\nfinal class PageResolver {}\n");

        self::assertFalse(
            UiManifest::for($root)->exists,
            'A directory holding only PHP is a namespace, not a UI.',
        );
    }

    public function testADirectoryWithSurfacePagesIsAFrontendEvenWithoutUiJson(): void
    {
        $root = $this->tempDir();
        mkdir($root . '/ui/admin/Pages', 0o700, true);

        self::assertTrue(UiManifest::for($root)->exists);
    }

    public function testAnEntryFileAloneIsEnoughToCountAsAFrontend(): void
    {
        $root = $this->tempDir();
        mkdir($root . '/ui', 0o700, true);
        file_put_contents($root . '/ui/index.ts', "export {};\n");

        self::assertTrue(UiManifest::for($root)->exists);
    }

    // ── Route filters ─────────────────────────────────────────────────────────

    public function testUnregisteredFilterAliasesAreStubbedAndReported(): void
    {
        // Without this the pipeline refuses to build (ROUTE_STRICT_FILTERS), and
        // almost no real plugin — Pageflow's own /pageflow/csrf carries
        // throttle:60,1 — could be tested at all.
        self::assertSame(['auth', 'throttle'], $this->ground()->stubbedFilters());
    }

    public function testAStubbedFilterLetsTheRouteThroughAndSaysItRan(): void
    {
        $response = $this->ground()->get('/samples/guarded');

        $this->assertOk($response);

        // The caveat that matters: `auth` was STUBBED, so this route was not
        // authenticated during this test. Asserting it is protected here would
        // be asserting nothing.
        self::assertTrue($this->ground()->filterStub()->ran('/samples/guarded'));
    }

    public function testRealFiltersRefusesToStandInSoTheGapIsVisible(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/declares filter \[(auth|throttle)\]/');

        $ground = $this->bootGround(static fn(PluginGround $g): PluginGround => $g->realFilters());

        try {
            $ground->get('/samples/guarded');
        } finally {
            $ground->destroy();
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @param list<string> $pages paths relative to ui/ */
    private function pluginWithPages(array $pages): string
    {
        $dir = $this->tempDir();

        foreach ($pages as $page) {
            self::write($dir . '/ui/' . $page, 'export default () => null;');
        }

        return $dir;
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/ground-ui-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o700, true);
        $this->temporary[] = $dir;

        return $dir;
    }

    private static function write(string $path, string $contents): void
    {
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0o700, true);
        }
        file_put_contents($path, $contents);
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }
}
