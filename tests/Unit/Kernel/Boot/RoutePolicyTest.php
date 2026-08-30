<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Boot;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\BootException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\ManifestReader;
use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Stages\CompileRouteManifestStage;
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The project's control over the routes its PLUGINS publish.
 *
 * A plugin owns and declares its routes, and one `hkm plugins install` can add
 * thirty of them. The project deploying it is the final authority on what is
 * actually exposed — but only if it can express that. These cover both verbs:
 * `disable` (subtract) and `only` (allowlist).
 */
#[CoversClass(CompileRouteManifestStage::class)]
final class RoutePolicyTest extends TestCase
{
    private string $root;
    private ?string $previousProject = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hkm-routepolicy-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/var/cache/manifests', 0775, true);

        $this->previousProject = Paths::project();
        Paths::setProject($this->root);
    }

    protected function tearDown(): void
    {
        Paths::setProject($this->previousProject);
        $this->deleteTree($this->root);
    }

    /**
     * A throwaway plugin whose module.json declares the given routes.
     *
     * @param list<array<string, mixed>> $routes
     * @return class-string
     */
    private function plugin(string $solves, array $routes): string
    {
        $name = 'Px' . bin2hex(random_bytes(6));
        $dir  = $this->root . '/plugins/' . $name;
        mkdir($dir, 0775, true);

        file_put_contents($dir . '/module.json', json_encode([
            'name'     => strtolower($name),
            'version'  => '1.0.0',
            'solves'   => $solves,
            'type'     => 'module',
            'requires' => [],
            'exposes'  => [],
            'config'   => [],
            'routes'   => $routes,
        ], JSON_THROW_ON_ERROR));

        file_put_contents(
            $dir . '/Provider.php',
            "<?php\nnamespace PspRoutePolicy\\{$name};\nfinal class Provider {}\n"
        );
        require_once $dir . '/Provider.php';

        /** @var class-string */
        return 'PspRoutePolicy\\' . $name . '\\Provider';
    }

    /** The mail plugin's demo routes — the case that motivated the prefix form. */
    private function mailDemoPlugin(bool $withSixth = false): string
    {
        $routes = [
            ['method' => 'GET', 'path' => '/mail/demo', 'handler' => 'C@index'],
            ['method' => 'GET', 'path' => '/mail/demo/send', 'handler' => 'C@send'],
            ['method' => 'GET', 'path' => '/mail/demo/queue', 'handler' => 'C@queue'],
            ['method' => 'GET', 'path' => '/mail/demo/preview', 'handler' => 'C@preview'],
            ['method' => 'GET', 'path' => '/mail/demo/view', 'handler' => 'C@view'],
            ['method' => 'GET', 'path' => '/mail/status', 'handler' => 'C@status'],
        ];

        if ($withSixth) {
            // What a plugin UPGRADE looks like: one more demo route appears.
            $routes[] = ['method' => 'GET', 'path' => '/mail/demo/blast', 'handler' => 'C@blast'];
        }

        return $this->plugin('mail.delivery', $routes);
    }

    /** @return array<string, mixed> the compiled manifest */
    private function compile(array $modules, array $disable = [], array $only = []): array
    {
        (new CompileRouteManifestStage(
            $modules,
            disabledRoutes: $disable,
            reader: new ManifestReader(),
            allowedRoutes: $only,
        ))->run();

        return ManifestReader::readCompiled('route-manifest.php');
    }

    // ── disable: the prefix form ─────────────────────────────────────────────

    public function test_a_prefix_disable_drops_the_whole_subtree(): void
    {
        $manifest = $this->compile([$this->mailDemoPlugin()], disable: ['GET /mail/demo/*']);

        self::assertArrayNotHasKey('GET /mail/demo/send', $manifest);
        self::assertArrayNotHasKey('GET /mail/demo/queue', $manifest);
        self::assertArrayNotHasKey('GET /mail/demo/preview', $manifest);
        self::assertArrayNotHasKey('GET /mail/demo/view', $manifest);
        self::assertArrayNotHasKey('GET /mail/demo', $manifest, 'the stem itself is covered');

        self::assertArrayHasKey('GET /mail/status', $manifest, 'a sibling must survive');
    }

    public function test_a_prefix_disable_still_covers_a_route_added_by_a_plugin_upgrade(): void
    {
        // THE REASON THE PREFIX FORM EXISTS. Listing the five demo routes by
        // exact key stays green when the plugin's next release adds a sixth:
        // the five still match, the anti-typo guard is satisfied, and the
        // surface silently grew. Show both halves.
        $exact = [
            'GET /mail/demo',
            'GET /mail/demo/send',
            'GET /mail/demo/queue',
            'GET /mail/demo/preview',
            'GET /mail/demo/view',
        ];

        $byExact = $this->compile([$this->mailDemoPlugin(withSixth: true)], disable: $exact);
        self::assertArrayHasKey(
            'GET /mail/demo/blast',
            $byExact,
            'exact specs fail OPEN on upgrade — this is the behaviour being fixed',
        );

        $byPrefix = $this->compile([$this->mailDemoPlugin(withSixth: true)], disable: ['GET /mail/demo/*']);
        self::assertArrayNotHasKey('GET /mail/demo/blast', $byPrefix, 'a prefix keeps covering what arrives later');
    }

    public function test_a_prefix_does_not_match_a_sibling_sharing_the_string(): void
    {
        // "/mail/demo/*" must not swallow "/mail/demos" — a path-segment
        // boundary, not a substring.
        $module = $this->plugin('mail.delivery', [
            ['method' => 'GET', 'path' => '/mail/demo/send', 'handler' => 'C@a'],
            ['method' => 'GET', 'path' => '/mail/demos', 'handler' => 'C@b'],
        ]);

        $manifest = $this->compile([$module], disable: ['GET /mail/demo/*']);

        self::assertArrayNotHasKey('GET /mail/demo/send', $manifest);
        self::assertArrayHasKey('GET /mail/demos', $manifest);
    }

    public function test_a_prefix_disable_is_method_specific(): void
    {
        // "GET /admin/*" must not silently also drop the POST that mutates —
        // otherwise a policy meant to hide a page quietly changes write surface.
        $module = $this->plugin('admin.panel', [
            ['method' => 'GET', 'path' => '/admin/users', 'handler' => 'C@index'],
            ['method' => 'POST', 'path' => '/admin/users', 'handler' => 'C@store'],
        ]);

        $manifest = $this->compile([$module], disable: ['GET /admin/*']);

        self::assertArrayNotHasKey('GET /admin/users', $manifest);
        self::assertArrayHasKey('POST /admin/users', $manifest);
    }

    public function test_a_prefix_matching_nothing_still_fails_the_boot(): void
    {
        // The anti-typo guard must survive the new form.
        $this->expectException(BootException::class);
        $this->expectExceptionMessageMatches('/matched no plugin route/');

        $this->compile([$this->mailDemoPlugin()], disable: ['GET /nope/*']);
    }

    public function test_the_exact_and_domain_forms_are_unchanged(): void
    {
        $manifest = $this->compile([$this->mailDemoPlugin()], disable: ['GET /mail/demo/send']);
        self::assertArrayNotHasKey('GET /mail/demo/send', $manifest);
        self::assertArrayHasKey('GET /mail/demo/queue', $manifest);

        $all = $this->compile([$this->mailDemoPlugin()], disable: ['mail.delivery']);
        self::assertSame([], $all, 'the domain form drops every route the module solves');
    }

    // ── only: the allowlist ──────────────────────────────────────────────────

    public function test_an_allowlist_drops_everything_it_does_not_name(): void
    {
        $manifest = $this->compile(
            [$this->mailDemoPlugin()],
            only: ['GET /mail/status'],
        );

        self::assertSame(['GET /mail/status'], array_keys($manifest));
    }

    public function test_an_empty_allowlist_means_no_allowlist(): void
    {
        // NOT "allow nothing" — that would turn a project which never opted in
        // into an empty application the moment it upgraded.
        $manifest = $this->compile([$this->mailDemoPlugin()], only: []);

        self::assertCount(6, $manifest);
    }

    public function test_an_allowlist_accepts_a_domain_and_a_prefix(): void
    {
        $mail  = $this->plugin('mail.delivery', [
            ['method' => 'GET', 'path' => '/mail/status', 'handler' => 'C@s'],
        ]);
        $admin = $this->plugin('admin.panel', [
            ['method' => 'GET', 'path' => '/admin/a', 'handler' => 'C@a'],
            ['method' => 'GET', 'path' => '/admin/b', 'handler' => 'C@b'],
            ['method' => 'GET', 'path' => '/other', 'handler' => 'C@o'],
        ]);

        $manifest = $this->compile([$mail, $admin], only: ['mail.delivery', 'GET /admin/*']);

        self::assertArrayHasKey('GET /mail/status', $manifest);
        self::assertArrayHasKey('GET /admin/a', $manifest);
        self::assertArrayHasKey('GET /admin/b', $manifest);
        self::assertArrayNotHasKey('GET /other', $manifest);
    }

    public function test_allow_and_disable_compose(): void
    {
        // The intended workflow: allow a module's whole domain, then subtract
        // the handful of its routes you do not want.
        $manifest = $this->compile(
            [$this->mailDemoPlugin()],
            disable: ['GET /mail/demo/*'],
            only: ['mail.delivery'],
        );

        self::assertSame(['GET /mail/status'], array_keys($manifest));
    }

    public function test_an_allow_spec_matching_nothing_does_not_fail_the_boot(): void
    {
        // Unlike a disable spec. An allowlist naming routes from a plugin this
        // deployment has not enabled is normal for shared/base configuration,
        // and failing there would make the safer posture the harder one to adopt.
        $manifest = $this->compile(
            [$this->mailDemoPlugin()],
            only: ['GET /mail/status', 'GET /not/installed/yet', 'some.absent.domain'],
        );

        self::assertSame(['GET /mail/status'], array_keys($manifest));
    }

    private function deleteTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
