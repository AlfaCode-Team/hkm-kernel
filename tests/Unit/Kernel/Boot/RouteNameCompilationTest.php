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
 * Route names are what make project-over-plugin overrides survivable: a plugin
 * view linking to route('auth.register') must keep working after a project
 * replaces that page. These tests pin the three rules that make that true.
 */
#[CoversClass(CompileRouteManifestStage::class)]
final class RouteNameCompilationTest extends TestCase
{
    private string $root;
    private ?string $previousProject = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hkm-routename-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/var/cache/manifests', 0775, true);

        $this->previousProject = Paths::project();
        Paths::setBase($this->root);
        Paths::setProject($this->root);
    }

    protected function tearDown(): void
    {
        Paths::setProject($this->previousProject);

        foreach (glob($this->root . '/var/cache/manifests/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->root . '/var/cache/manifests');
        @rmdir($this->root . '/var/cache');
        @rmdir($this->root);
    }

    /**
     * Build a stub plugin whose module.json declares $routes, so the compiler
     * exercises its real plugin path rather than a project-only shortcut.
     *
     * @param list<array<string, mixed>> $routes
     * @return class-string
     */
    private function plugin(array $routes, string $solves = 'demo.domain'): string
    {
        $dir = $this->root . '/plugin' . bin2hex(random_bytes(4));
        mkdir($dir, 0775, true);

        file_put_contents($dir . '/module.json', json_encode([
            'name'   => 'demo',
            'solves' => $solves,
            'routes' => $routes,
        ]));

        $class = 'StubProvider' . bin2hex(random_bytes(4));
        file_put_contents($dir . '/Provider.php', "<?php class {$class} {}");
        require $dir . '/Provider.php';

        return $class;
    }

    /**
     * @param list<class-string>         $modules
     * @param list<array<string, mixed>> $projectRoutes
     * @param list<string>               $disabled
     * @return array<string, array<string, mixed>>
     */
    private function compile(array $modules, array $projectRoutes = [], array $disabled = []): array
    {
        (new CompileRouteManifestStage(
            $modules,
            projectRoutes: $projectRoutes,
            disabledRoutes: $disabled,
            reader: new ManifestReader(),
        ))->run();

        return ManifestReader::readCompiled('route-manifest.php');
    }

    public function test_a_declared_name_is_compiled_onto_the_route(): void
    {
        $manifest = $this->compile([
            $this->plugin([
                ['method' => 'GET', 'path' => '/register', 'handler' => 'P\\C@show', 'name' => 'auth.register'],
            ]),
        ]);

        self::assertSame('auth.register', $manifest['GET /register']['name']);
    }

    public function test_a_route_without_a_name_compiles_with_null(): void
    {
        $manifest = $this->compile([
            $this->plugin([['method' => 'GET', 'path' => '/health', 'handler' => 'P\\C@show']]),
        ]);

        self::assertNull($manifest['GET /health']['name']);
    }

    public function test_duplicate_names_fail_the_boot(): void
    {
        // Last-one-wins would make route('auth.register') resolve to whichever
        // plugin happened to load last — a silent, ordering-dependent bug.
        $this->expectException(BootException::class);
        $this->expectExceptionMessageMatches('/Duplicate route name \[auth\.register\]/');

        $this->compile([
            $this->plugin([
                ['method' => 'GET',  'path' => '/register',  'handler' => 'P\\C@a', 'name' => 'auth.register'],
                ['method' => 'POST', 'path' => '/register2', 'handler' => 'P\\C@b', 'name' => 'auth.register'],
            ]),
        ]);
    }

    public function test_a_project_override_inherits_the_plugin_route_name(): void
    {
        // THE point of names: the plugin's own links keep resolving after the
        // project takes over the page.
        $manifest = $this->compile(
            [$this->plugin([
                ['method' => 'GET', 'path' => '/register', 'handler' => 'P\\C@show', 'name' => 'auth.register'],
            ])],
            projectRoutes: [
                ['method' => 'GET', 'path' => '/register', 'handler' => 'App\\C@show'],
            ],
        );

        self::assertSame('App\\C@show', $manifest['GET /register']['handler'], 'project wins');
        self::assertSame('auth.register', $manifest['GET /register']['name'], 'name survives');
    }

    public function test_a_project_override_may_declare_its_own_name(): void
    {
        $manifest = $this->compile(
            [$this->plugin([
                ['method' => 'GET', 'path' => '/register', 'handler' => 'P\\C@show', 'name' => 'auth.register'],
            ])],
            projectRoutes: [
                ['method' => 'GET', 'path' => '/register', 'handler' => 'App\\C@show', 'name' => 'signup'],
            ],
        );

        self::assertSame('signup', $manifest['GET /register']['name']);
    }

    public function test_disabling_a_route_releases_its_name_for_reuse(): void
    {
        // Without the release, a project that vetoes GET /register and declares
        // its own 'auth.register' would collide with the route it just removed.
        $manifest = $this->compile(
            [$this->plugin([
                ['method' => 'GET', 'path' => '/register', 'handler' => 'P\\C@show', 'name' => 'auth.register'],
            ])],
            projectRoutes: [
                ['method' => 'GET', 'path' => '/signup', 'handler' => 'App\\C@show', 'name' => 'auth.register'],
            ],
            disabled: ['GET /register'],
        );

        self::assertArrayNotHasKey('GET /register', $manifest);
        self::assertSame('auth.register', $manifest['GET /signup']['name']);
    }
}
