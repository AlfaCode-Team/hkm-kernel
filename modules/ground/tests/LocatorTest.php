<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Tests;

use PHPUnit\Framework\TestCase;
use AlfacodeTeam\Ground\Inspection\PluginLocator;

/**
 * Where the locator looks, and in what order.
 *
 * It previously globbed `plugins/*` and `modules/*` under the working directory
 * and nothing else, so the two layouts a plugin author actually works in — being
 * INSIDE a plugin repo, and having every plugin as a sibling checkout — both
 * reported "No plugins found". That is the gap these tests pin down.
 */
final class LocatorTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/hkm-locator-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o700, true);
    }

    protected function tearDown(): void
    {
        self::removeTree($this->root);

        parent::tearDown();
    }

    // ── Where it looks ────────────────────────────────────────────────────────

    public function testItFindsThePluginYouAreStandingIn(): void
    {
        $this->writePlugin($this->root . '/hkm-plugin-alpha', 'alpha', 'Alpha', 'alpha.domain');

        $found = (new PluginLocator($this->root . '/hkm-plugin-alpha'))->all();

        self::assertArrayHasKey('alpha', $found);
    }

    public function testItFindsPluginsUnderAProjectsPluginsDirectory(): void
    {
        $this->writePlugin($this->root . '/plugins/Beta', 'beta', 'Beta', 'beta.domain');

        self::assertArrayHasKey('beta', (new PluginLocator($this->root))->all());
    }

    public function testItFindsPluginsUnderModules(): void
    {
        $this->writePlugin($this->root . '/modules/gamma', 'gamma', 'Gamma', 'gamma.domain');

        self::assertArrayHasKey('gamma', (new PluginLocator($this->root))->all());
    }

    /**
     * The sibling-workspace layout — the one that makes cross-plugin
     * development possible before anything is published.
     */
    public function testItFindsSiblingRepositoriesOfThePluginYouAreIn(): void
    {
        $this->writePlugin($this->root . '/hkm-plugin-alpha', 'alpha', 'Alpha', 'alpha.domain');
        $this->writePlugin($this->root . '/hkm-plugin-delta', 'delta', 'Delta', 'delta.domain');

        $found = (new PluginLocator($this->root . '/hkm-plugin-alpha'))->all();

        self::assertArrayHasKey('alpha', $found);
        self::assertArrayHasKey('delta', $found, 'A sibling checkout must be discoverable.');
    }

    public function testSiblingScanningCanBeTurnedOffForCi(): void
    {
        $this->writePlugin($this->root . '/hkm-plugin-alpha', 'alpha', 'Alpha', 'alpha.domain');
        $this->writePlugin($this->root . '/hkm-plugin-delta', 'delta', 'Delta', 'delta.domain');

        $found = (new PluginLocator($this->root . '/hkm-plugin-alpha', includeSiblings: false))->all();

        self::assertArrayHasKey('alpha', $found);
        self::assertArrayNotHasKey('delta', $found);
    }

    public function testADirectoryWithoutAProviderIsNotAPlugin(): void
    {
        mkdir($this->root . '/plugins/Empty', 0o700, true);
        file_put_contents($this->root . '/plugins/Empty/module.json', '{"name":"empty"}');

        self::assertSame([], (new PluginLocator($this->root))->all());
    }

    public function testMalformedJsonIsSkippedRatherThanStoppingTheScan(): void
    {
        $this->writePlugin($this->root . '/plugins/Good', 'good', 'Good', 'good.domain');

        mkdir($this->root . '/plugins/Broken', 0o700, true);
        file_put_contents($this->root . '/plugins/Broken/module.json', '{not json');
        file_put_contents(
            $this->root . '/plugins/Broken/Provider.php',
            "<?php\nnamespace Broken;\nfinal class Provider {}\n",
        );

        self::assertSame(['good'], array_keys((new PluginLocator($this->root))->all()));
    }

    /**
     * Nearest-first. The copy you are editing must beat a sibling or a vendored
     * release of the same plugin, or you debug the wrong file.
     */
    public function testTheNearestCopyOfAPluginWins(): void
    {
        $this->writePlugin($this->root . '/hkm-plugin-alpha', 'alpha', 'AlphaHere', 'alpha.domain');
        $this->writePlugin($this->root . '/hkm-plugin-alpha-old', 'alpha', 'AlphaThere', 'alpha.domain');

        $found = (new PluginLocator($this->root . '/hkm-plugin-alpha'))->all();

        self::assertSame('AlphaHere\\Provider', $found['alpha']->providerClass);
    }

    public function testItReportsWhereItLooked(): void
    {
        $searched = (new PluginLocator($this->root . '/hkm-plugin-alpha'))->searchedPaths();

        self::assertNotSame([], $searched);
        self::assertNotSame(
            [],
            array_filter($searched, static fn(string $g): bool => str_contains($g, '/module.json')),
        );
    }

    // ── The plugin you are standing in ────────────────────────────────────────

    public function testItKnowsThePluginYouAreStandingIn(): void
    {
        $this->writePlugin($this->root . '/hkm-plugin-alpha', 'alpha', 'Alpha', 'alpha.domain');

        $current = (new PluginLocator($this->root . '/hkm-plugin-alpha'))->current();

        self::assertNotNull($current);
        self::assertSame('alpha', $current->name());
    }

    /** You are as often in ui/ or tests/ as at the top of the repo. */
    public function testItWalksUpFromASubdirectory(): void
    {
        $this->writePlugin($this->root . '/hkm-plugin-alpha', 'alpha', 'Alpha', 'alpha.domain');
        mkdir($this->root . '/hkm-plugin-alpha/ui/admin/Pages', 0o700, true);

        $current = (new PluginLocator($this->root . '/hkm-plugin-alpha/ui/admin/Pages'))->current();

        self::assertNotNull($current);
        self::assertSame('alpha', $current->name());
    }

    /** A workspace directory holding plugins is not itself a plugin. */
    public function testTheWorkspaceRootIsNotAPlugin(): void
    {
        $this->writePlugin($this->root . '/hkm-plugin-alpha', 'alpha', 'Alpha', 'alpha.domain');

        self::assertNull((new PluginLocator($this->root))->current());
    }

    /** A module.json with no Provider beside it is not a plugin either. */
    public function testAManifestWithoutAProviderIsNotCurrent(): void
    {
        mkdir($this->root . '/loose', 0o700, true);
        file_put_contents($this->root . '/loose/module.json', '{"name":"loose"}');

        self::assertNull((new PluginLocator($this->root . '/loose'))->current());
    }

    /**
     * The walk is bounded. Without a stop it climbs to / and can pick up an
     * unrelated module.json from a parent of the whole workspace.
     */
    public function testTheWalkUpIsBounded(): void
    {
        $this->writePlugin($this->root . '/hkm-plugin-alpha', 'alpha', 'Alpha', 'alpha.domain');

        $deep = $this->root . '/hkm-plugin-alpha/a/b/c/d/e/f/g';
        mkdir($deep, 0o700, true);

        self::assertNull((new PluginLocator($deep))->current());
    }

    // ── Autoloading ───────────────────────────────────────────────────────────

    /**
     * A discovered plugin is useless if its Provider cannot be constructed: the
     * inspector's drift check needs the class and `plugin:probe` has to boot it.
     * A sibling repo is in nobody's composer autoloader, so the locator maps the
     * namespace in Provider.php onto the directory it found it in.
     */
    public function testADiscoveredPluginBecomesAutoloadable(): void
    {
        $namespace = 'GroundLocatorFixture' . bin2hex(random_bytes(4));
        $this->writePlugin($this->root . '/plugins/Auto', 'auto', $namespace, 'auto.domain');

        self::assertFalse(class_exists($namespace . '\\Provider', autoload: true), 'precondition');

        $found = (new PluginLocator($this->root))->all();

        self::assertArrayHasKey('auto', $found);
        self::assertTrue(
            class_exists($found['auto']->providerClass),
            'The locator must register a PSR-4 mapping for what it discovers.',
        );
    }

    // ── Dependency resolution ─────────────────────────────────────────────────

    public function testItResolvesRequiredDomainsTransitively(): void
    {
        $this->writePlugin($this->root . '/plugins/A', 'a', 'PkgA', 'a.domain', requires: ['b.domain']);
        $this->writePlugin($this->root . '/plugins/B', 'b', 'PkgB', 'b.domain', requires: ['c.domain']);
        $this->writePlugin($this->root . '/plugins/C', 'c', 'PkgC', 'c.domain');

        $locator = new PluginLocator($this->root);
        $result  = $locator->dependenciesFor($locator->find('a'));

        self::assertSame([], $result['missing']);
        self::assertSame(['PkgB\\Provider', 'PkgC\\Provider'], $result['providers']);
    }

    /** Route-level requires[] are just as hard a boot dependency as module-level. */
    public function testItResolvesDomainsRequiredByAROUTEOnly(): void
    {
        $this->writePlugin(
            $this->root . '/plugins/A',
            'a',
            'RouteA',
            'a.domain',
            routes: [[
                'method'   => 'GET',
                'path'     => '/x',
                'handler'  => 'RouteA\\Http\\C@x',
                'requires' => ['b.domain'],
            ]],
        );
        $this->writePlugin($this->root . '/plugins/B', 'b', 'RouteB', 'b.domain');

        $locator = new PluginLocator($this->root);
        $result  = $locator->dependenciesFor($locator->find('a'));

        self::assertSame(['RouteB\\Provider'], $result['providers']);
    }

    public function testAnUnprovidedDomainIsReportedNotGuessedAt(): void
    {
        $this->writePlugin($this->root . '/plugins/A', 'a', 'LoneA', 'a.domain', requires: ['nobody.provides']);

        $locator = new PluginLocator($this->root);
        $result  = $locator->dependenciesFor($locator->find('a'));

        self::assertSame(['nobody.provides'], $result['missing']);
        self::assertSame([], $result['providers']);
    }

    public function testAPluginRequiringItselfDoesNotLoopForever(): void
    {
        $this->writePlugin($this->root . '/plugins/A', 'a', 'SelfA', 'a.domain', requires: ['a.domain']);

        $locator = new PluginLocator($this->root);
        $result  = $locator->dependenciesFor($locator->find('a'));

        self::assertSame([], $result['providers']);
        self::assertSame([], $result['missing']);
    }

    public function testACycleBetweenTwoPluginsTerminates(): void
    {
        $this->writePlugin($this->root . '/plugins/A', 'a', 'CycA', 'a.domain', requires: ['b.domain']);
        $this->writePlugin($this->root . '/plugins/B', 'b', 'CycB', 'b.domain', requires: ['a.domain']);

        $locator = new PluginLocator($this->root);
        $result  = $locator->dependenciesFor($locator->find('a'));

        self::assertSame(['CycB\\Provider'], $result['providers']);
    }

    public function testByDomainIndexesWhatEachPluginSolves(): void
    {
        $this->writePlugin($this->root . '/plugins/A', 'a', 'IdxA', 'a.domain');

        self::assertArrayHasKey('a.domain', (new PluginLocator($this->root))->byDomain());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @param list<string>              $requires
     * @param list<array<string, mixed>> $routes
     */
    private function writePlugin(
        string $dir,
        string $name,
        string $namespace,
        string $solves,
        array $requires = [],
        array $routes = [],
    ): void {
        mkdir($dir, 0o700, true);

        file_put_contents($dir . '/module.json', (string) json_encode([
            'name'     => $name,
            'solves'   => $solves,
            'type'     => 'module',
            'requires' => $requires,
            'routes'   => $routes,
        ]));

        file_put_contents(
            $dir . '/Provider.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$namespace};\n\nfinal class Provider {}\n",
        );
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
