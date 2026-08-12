<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Boot;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\BootException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\ManifestReader;
use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Stages\CompileRouteManifestStage;
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\PrefixedModule\Provider as PrefixedProvider;

/**
 * What the boot compiler now precomputes, validates and refuses.
 *
 * The precompilation cases matter for cost (they move per-request and per-worker
 * work to build time); the validation cases matter because each one used to
 * compile into a route that silently never matched, which reads as a missing
 * controller rather than a typo.
 */
#[CoversClass(CompileRouteManifestStage::class)]
final class RouteCompilationTest extends TestCase
{
    private string $root;
    private ?string $previousProject = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hkm-routecompile-' . bin2hex(random_bytes(6));
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
        @rmdir($this->root . '/var');
        @rmdir($this->root);
    }

    /**
     * @param list<array<string, mixed>> $projectRoutes
     * @param list<class-string>         $modules
     * @param list<string>               $disable
     */
    private function compile(array $projectRoutes = [], array $modules = [], array $disable = []): void
    {
        (new CompileRouteManifestStage(
            $modules,
            projectRoutes: $projectRoutes,
            disabledRoutes: $disable,
            reader: new ManifestReader(),
        ))->run();
    }

    /** @return array<string, mixed> */
    private function manifest(string $file = 'route-manifest.php'): array
    {
        return ManifestReader::readCompiled($file);
    }

    // ── Precompilation ──────────────────────────────────────────────────────

    public function test_the_handler_split_is_baked_into_the_entry(): void
    {
        $this->compile([['method' => 'GET', 'path' => '/x', 'handler' => 'App\\C@show']]);

        $entry = $this->manifest()['GET /x'];
        self::assertSame('App\\C', $entry['class']);
        self::assertSame('show', $entry['action']);
        self::assertSame('App\\C@show', $entry['handler'], 'the original stays for existing readers');
    }

    public function test_filter_specs_are_parsed_at_boot(): void
    {
        $this->compile([[
            'method' => 'GET', 'path' => '/x', 'handler' => 'App\\C@show',
            'filters' => ['auth', 'throttle:60,1'],
        ]]);

        $entry = $this->manifest()['GET /x'];

        self::assertSame(['auth', 'throttle:60,1'], $entry['filters'], 'raw specs are preserved');
        self::assertSame(
            [
                ['alias' => 'auth', 'args' => []],
                ['alias' => 'throttle', 'args' => ['60', '1']],
            ],
            $entry['filter_specs'],
        );
    }

    public function test_the_dependency_graph_key_is_precomputed(): void
    {
        $this->compile([['method' => 'GET', 'path' => '/x', 'handler' => 'App\\C@show']]);

        self::assertSame('__project__|', $this->manifest()['GET /x']['graph_key']);
    }

    public function test_a_dynamic_route_carries_its_compiled_regex(): void
    {
        $this->compile([['method' => 'GET', 'path' => '/u/{id:num}', 'handler' => 'App\\C@show']]);

        $entry = $this->manifest()['GET /u/{id:num}'];

        self::assertStringEndsWith('$#D', $entry['regex'], 'anchored with the D modifier');
        self::assertSame([['name' => 'id', 'type' => 'num', 'optional' => false]], $entry['params']);
    }

    public function test_a_static_route_carries_no_regex(): void
    {
        $this->compile([['method' => 'GET', 'path' => '/x', 'handler' => 'App\\C@show']]);

        self::assertArrayNotHasKey('regex', $this->manifest()['GET /x']);
    }

    // ── The derived manifests ───────────────────────────────────────────────

    public function test_the_matcher_index_is_written_alongside_the_manifest(): void
    {
        $this->compile([
            ['method' => 'GET', 'path' => '/health',    'handler' => 'App\\C@up'],
            ['method' => 'GET', 'path' => '/u/{id}',    'handler' => 'App\\C@show'],
            ['method' => 'GET', 'path' => '/{slug}',    'handler' => 'App\\C@page'],
        ]);

        $index = $this->manifest('route-index.php');

        // '' is the shared site every unscoped route lives in.
        self::assertArrayHasKey('GET /health', $index['static']['']);
        self::assertArrayHasKey('u', $index['dynamic']['']['GET']['buckets']);
        self::assertCount(1, $index['dynamic']['']['GET']['wild']);
        self::assertSame(['GET'], $index['methods']);
        self::assertSame([], $index['domains']);
    }

    public function test_the_name_index_is_written_alongside_the_manifest(): void
    {
        $this->compile([
            ['method' => 'GET',  'path' => '/u/{id}', 'handler' => 'App\\C@show', 'name' => 'user.show'],
            ['method' => 'POST', 'path' => '/u',      'handler' => 'App\\C@store'],
        ]);

        self::assertSame(
            ['user.show' => ['path' => '/u/{id}', 'method' => 'GET', 'domain' => '']],
            $this->manifest('route-names.php'),
        );
    }

    // ── Module-level declarations ───────────────────────────────────────────

    public function test_a_module_route_prefix_is_applied_to_every_route(): void
    {
        $this->compile(modules: [PrefixedProvider::class]);

        $manifest = $this->manifest();

        self::assertArrayHasKey('GET /api/v1/things', $manifest);
        self::assertArrayHasKey('POST /api/v1/things', $manifest);
        self::assertArrayHasKey('GET /api/v1/things/{id:num}', $manifest);
    }

    public function test_module_default_filters_are_merged_in_front(): void
    {
        $this->compile(modules: [PrefixedProvider::class]);

        self::assertSame(
            ['auth', 'throttle:60,1'],
            $this->manifest()['GET /api/v1/things']['filters'],
        );
    }

    public function test_a_route_overrides_a_module_default_of_the_same_alias(): void
    {
        $this->compile(modules: [PrefixedProvider::class]);

        // The route declares throttle:5,1 — it must replace the module's 60,1
        // rather than run the throttle stage twice with different budgets.
        self::assertSame(
            ['auth', 'throttle:5,1'],
            $this->manifest()['POST /api/v1/things']['filters'],
        );
    }

    public function test_a_prefixed_route_keeps_its_name(): void
    {
        $this->compile(modules: [PrefixedProvider::class]);

        self::assertSame(
            ['things.index' => ['path' => '/api/v1/things', 'method' => 'GET', 'domain' => '']],
            $this->manifest('route-names.php'),
        );
    }

    // ── Validation: each of these used to compile to a dead route ───────────

    public function test_a_path_without_a_leading_slash_fails_the_boot(): void
    {
        $this->expectException(BootException::class);
        $this->expectExceptionMessageMatches('/does not start with/');

        $this->compile([['method' => 'GET', 'path' => 'users', 'handler' => 'App\\C@show']]);
    }

    public function test_a_duplicate_parameter_name_fails_the_boot(): void
    {
        $this->expectException(BootException::class);
        $this->expectExceptionMessageMatches('/repeats the capture name/');

        $this->compile([['method' => 'GET', 'path' => '/a/{id}/b/{id}', 'handler' => 'App\\C@show']]);
    }

    public function test_a_parameter_name_pcre_rejects_fails_the_boot(): void
    {
        $this->expectException(BootException::class);
        $this->expectExceptionMessageMatches('/not a usable capture name/');

        $this->compile([['method' => 'GET', 'path' => '/{2fa}', 'handler' => 'App\\C@show']]);
    }

    public function test_a_handler_without_a_separator_fails_the_boot(): void
    {
        $this->expectException(BootException::class);
        $this->expectExceptionMessageMatches("/'Controller@method' format/");

        $this->compile([['method' => 'GET', 'path' => '/x', 'handler' => 'App\\C']]);
    }

    public function test_a_handler_with_two_separators_fails_the_boot(): void
    {
        $this->expectException(BootException::class);
        $this->expectExceptionMessageMatches("/'Controller@method' format/");

        $this->compile([['method' => 'GET', 'path' => '/x', 'handler' => 'App\\C@a@b']]);
    }

    public function test_a_non_string_filter_fails_the_boot(): void
    {
        $this->expectException(BootException::class);
        $this->expectExceptionMessageMatches('/not a string/');

        $this->compile([[
            'method' => 'GET', 'path' => '/x', 'handler' => 'App\\C@show',
            'filters' => [['auth']],
        ]]);
    }

    // ── Compatibility: a name that sanitises to something valid still works ──

    public function test_a_hyphenated_parameter_name_still_compiles(): void
    {
        // Sanitisation to 'userid' predates typing; tightening the grammar here
        // would silently kill routes that work today.
        $this->compile([['method' => 'GET', 'path' => '/u/{user-id}', 'handler' => 'App\\C@show']]);

        self::assertSame(
            [['name' => 'userid', 'type' => '', 'optional' => false]],
            $this->manifest()['GET /u/{user-id}']['params'],
        );
    }

    public function test_handler_verification_is_off_by_default(): void
    {
        // A controller class that does not exist must NOT fail the build unless
        // ROUTE_VERIFY_HANDLERS is explicitly enabled.
        $this->compile([['method' => 'GET', 'path' => '/x', 'handler' => 'No\\Such\\Controller@show']]);

        self::assertArrayHasKey('GET /x', $this->manifest());
    }
}
