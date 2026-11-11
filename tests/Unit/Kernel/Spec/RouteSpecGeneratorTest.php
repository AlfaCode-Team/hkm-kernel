<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Spec;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\ManifestReader;
use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Stages\CompileRouteManifestStage;
use AlfacodeTeam\PhpServicePlatform\Kernel\Spec\RouteSpecGenerator;
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The generator is tested against a manifest compiled by the REAL stage, not a
 * hand-written fixture.
 *
 * That matters more than it looks. A fixture encodes what the author BELIEVES
 * the manifest shape is, so the test keeps passing after the compiler changes
 * shape and the generator quietly stops understanding its input. Compiling for
 * real means a change to the entry format breaks this test, which is the only
 * way the two stay in step.
 */
#[CoversClass(RouteSpecGenerator::class)]
final class RouteSpecGeneratorTest extends TestCase
{
    private string $root;

    private ?string $previousProject = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hkm-spec-' . bin2hex(random_bytes(6));
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
     * @param list<array<string, mixed>> $routes
     * @return array<string, mixed> the generated spec
     */
    private function specFor(array $routes, ?RouteSpecGenerator $generator = null): array
    {
        (new CompileRouteManifestStage(
            [],
            projectRoutes: $routes,
            reader: new ManifestReader(),
        ))->run();

        /** @var array<string, array<string, mixed>> $manifest */
        $manifest = require $this->root . '/var/cache/manifests/route-manifest.php';

        return ($generator ?? new RouteSpecGenerator('Test API', '2.0.0'))
            ->generate($manifest)
            ->toArray();
    }

    // ── Paths and parameters ─────────────────────────────────────────────────

    public function test_a_static_route_becomes_a_path_item(): void
    {
        $spec = $this->specFor([
            ['method' => 'GET', 'path' => '/things', 'handler' => 'App\\ThingController@index', 'name' => 'things.index'],
        ]);

        $this->assertArrayHasKey('/things', $spec['paths']);
        $this->assertSame('things.index', $spec['paths']['/things']['get']['operationId']);
        $this->assertSame('ThingController::index()', $spec['paths']['/things']['get']['summary']);
    }

    /** The type moves out of the path and into the schema. */
    public function test_a_typed_placeholder_loses_its_type_in_the_path(): void
    {
        $spec = $this->specFor([
            ['method' => 'GET', 'path' => '/things/{id:num}', 'handler' => 'App\\ThingController@show'],
        ]);

        $this->assertArrayHasKey('/things/{id}', $spec['paths']);
        $this->assertArrayNotHasKey('/things/{id:num}', $spec['paths']);

        $param = $spec['paths']['/things/{id}']['get']['parameters'][0];
        $this->assertSame('id', $param['name']);
        $this->assertSame('path', $param['in']);
        $this->assertTrue($param['required']);
        $this->assertSame('integer', $param['schema']['type']);
    }

    public function test_a_uuid_placeholder_is_documented_with_its_format(): void
    {
        $spec = $this->specFor([
            ['method' => 'GET', 'path' => '/things/{id:uuid}', 'handler' => 'App\\ThingController@show'],
        ]);

        $schema = $spec['paths']['/things/{id}']['get']['parameters'][0]['schema'];
        $this->assertSame('string', $schema['type']);
        $this->assertSame('uuid', $schema['format']);
    }

    public function test_an_enum_placeholder_becomes_an_enum_schema(): void
    {
        $spec = $this->specFor([
            ['method' => 'GET', 'path' => '/posts/{status:enum(draft|published)}', 'handler' => 'App\\PostController@byStatus'],
        ]);

        $schema = $spec['paths']['/posts/{status}']['get']['parameters'][0]['schema'];
        $this->assertSame(['draft', 'published'], $schema['enum']);
    }

    /**
     * The documented constraint is the router's own regex, so the two cannot
     * disagree. A `{slug:slug}` is described as exactly what will be accepted.
     */
    public function test_the_documented_pattern_is_the_routers_pattern(): void
    {
        $spec = $this->specFor([
            ['method' => 'GET', 'path' => '/p/{slug:slug}', 'handler' => 'App\\PostController@show'],
        ]);

        $schema = $spec['paths']['/p/{slug}']['get']['parameters'][0]['schema'];
        $this->assertSame('^[a-zA-Z0-9_-]+$', $schema['pattern']);
    }

    public function test_a_path_type_is_described_rather_than_given_a_raw_pattern(): void
    {
        $spec = $this->specFor([
            ['method' => 'GET', 'path' => '/files/{file:path}', 'handler' => 'App\\FileController@show'],
        ]);

        $schema = $spec['paths']['/files/{file}']['get']['parameters'][0]['schema'];
        $this->assertSame('string', $schema['type']);
        $this->assertArrayNotHasKey('pattern', $schema);
        $this->assertStringContainsString('May contain "/"', $schema['description']);
    }

    /**
     * THE case OpenAPI cannot express directly. `/pages/{page?}` matches both
     * `/pages` and `/pages/2`, and a path parameter is always required, so the
     * route has to become two templates. Describing it as one required
     * parameter would hide the `/pages` form from every generated client.
     */
    public function test_an_optional_segment_becomes_two_paths(): void
    {
        $spec = $this->specFor([
            ['method' => 'GET', 'path' => '/pages/{page?}', 'handler' => 'App\\PageController@index'],
        ]);

        $this->assertArrayHasKey('/pages', $spec['paths']);
        $this->assertArrayHasKey('/pages/{page}', $spec['paths']);

        $this->assertArrayNotHasKey('parameters', $spec['paths']['/pages']['get']);
        $this->assertCount(1, $spec['paths']['/pages/{page}']['get']['parameters']);
        $this->assertTrue($spec['paths']['/pages/{page}']['get']['parameters'][0]['required']);
    }

    /**
     * The two forms are two operations, and operationIds must be unique
     * document-wide — so they cannot both carry the route's name. The short form
     * keeps it; the parameterised form appends what it adds.
     */
    public function test_the_two_forms_of_an_optional_route_get_distinct_operation_ids(): void
    {
        $spec = $this->specFor([
            ['method' => 'GET', 'path' => '/pages/{page?}', 'handler' => 'App\\PageController@index', 'name' => 'pages.index'],
        ]);

        $this->assertSame('pages.index', $spec['paths']['/pages']['get']['operationId']);
        $this->assertSame('pages.index.withPage', $spec['paths']['/pages/{page}']['get']['operationId']);
    }

    /** The short form receives no identifier, so a 404 for one would be noise. */
    public function test_the_short_form_of_an_optional_route_documents_no_404(): void
    {
        $spec = $this->specFor([
            ['method' => 'GET', 'path' => '/pages/{page?}', 'handler' => 'App\\PageController@index'],
        ]);

        $this->assertArrayNotHasKey('404', $spec['paths']['/pages']['get']['responses']);
        $this->assertArrayHasKey('404', $spec['paths']['/pages/{page}']['get']['responses']);
    }

    // ── Filters become security and responses ────────────────────────────────

    public function test_an_unauthenticated_route_declares_no_security(): void
    {
        $spec = $this->specFor([
            ['method' => 'GET', 'path' => '/public', 'handler' => 'App\\PublicController@index'],
        ]);

        $operation = $spec['paths']['/public']['get'];
        $this->assertArrayNotHasKey('security', $operation);
        $this->assertArrayNotHasKey('401', $operation['responses']);
    }

    public function test_the_auth_filter_becomes_a_security_requirement(): void
    {
        $spec = $this->specFor([
            ['method' => 'GET', 'path' => '/me', 'handler' => 'App\\MeController@show', 'filters' => ['auth']],
        ]);

        $operation = $spec['paths']['/me']['get'];

        $this->assertSame(
            [['session' => []], ['bearer' => []]],
            $operation['security'],
        );
        $this->assertArrayHasKey('401', $operation['responses']);
        $this->assertArrayHasKey('403', $operation['responses']);
    }

    /** `can:posts.manage` documents the permission as a scope. */
    public function test_a_can_filter_contributes_its_permission_as_a_scope(): void
    {
        $spec = $this->specFor([
            ['method' => 'GET', 'path' => '/admin/posts', 'handler' => 'App\\AdminController@posts', 'filters' => ['can:posts.manage']],
        ]);

        $this->assertSame(
            [['session' => ['posts.manage']], ['bearer' => ['posts.manage']]],
            $spec['paths']['/admin/posts']['get']['security'],
        );
    }

    public function test_the_throttle_filter_documents_a_429(): void
    {
        $spec = $this->specFor([
            ['method' => 'POST', 'path' => '/login', 'handler' => 'App\\AuthController@login', 'filters' => ['throttle:5,1']],
        ]);

        $this->assertArrayHasKey('429', $spec['paths']['/login']['post']['responses']);
    }

    // ── Method-shaped defaults ───────────────────────────────────────────────

    public function test_a_post_documents_201_a_request_body_and_422(): void
    {
        $spec = $this->specFor([
            ['method' => 'POST', 'path' => '/things', 'handler' => 'App\\ThingController@create'],
        ]);

        $operation = $spec['paths']['/things']['post'];

        $this->assertArrayHasKey('201', $operation['responses']);
        $this->assertArrayHasKey('422', $operation['responses']);
        $this->assertArrayHasKey('requestBody', $operation);

        // The validation error carries field messages — the kernel's envelope.
        $fields = $operation['responses']['422']['content']['application/json']['schema']
            ['properties']['error']['properties'];
        $this->assertArrayHasKey('fields', $fields);
    }

    public function test_a_delete_documents_204_with_no_body(): void
    {
        $spec = $this->specFor([
            ['method' => 'DELETE', 'path' => '/things/{id:num}', 'handler' => 'App\\ThingController@destroy'],
        ]);

        $responses = $spec['paths']['/things/{id}']['delete']['responses'];

        $this->assertArrayHasKey('204', $responses);
        $this->assertArrayNotHasKey('content', $responses['204']);
    }

    public function test_a_route_with_parameters_documents_a_404(): void
    {
        $spec = $this->specFor([
            ['method' => 'GET', 'path' => '/things/{id:num}', 'handler' => 'App\\ThingController@show'],
        ]);

        $this->assertArrayHasKey('404', $spec['paths']['/things/{id}']['get']['responses']);
    }

    // ── Grouping, exclusion, determinism ─────────────────────────────────────

    public function test_project_routes_are_tagged_project(): void
    {
        $spec = $this->specFor([
            ['method' => 'GET', 'path' => '/things', 'handler' => 'App\\ThingController@index'],
        ]);

        $this->assertSame(['project'], $spec['paths']['/things']['get']['tags']);
        $this->assertSame([['name' => 'project']], $spec['tags']);
    }

    public function test_excluded_prefixes_are_left_out(): void
    {
        $spec = $this->specFor(
            [
                ['method' => 'GET', 'path' => '/things', 'handler' => 'App\\ThingController@index'],
                ['method' => 'GET', 'path' => '/_internal/debug', 'handler' => 'App\\DebugController@index'],
            ],
            new RouteSpecGenerator('Test API', '2.0.0', excludePrefixes: ['/_internal']),
        );

        $this->assertArrayHasKey('/things', $spec['paths']);
        $this->assertArrayNotHasKey('/_internal/debug', $spec['paths']);
    }

    public function test_the_security_schemes_are_declared_once_in_components(): void
    {
        $spec = $this->specFor([
            ['method' => 'GET', 'path' => '/things', 'handler' => 'App\\ThingController@index'],
        ]);

        $this->assertSame(['bearer', 'session'], array_keys($spec['components']['securitySchemes']));
    }

    public function test_info_comes_from_the_generator(): void
    {
        $spec = $this->specFor([
            ['method' => 'GET', 'path' => '/things', 'handler' => 'App\\ThingController@index'],
        ]);

        $this->assertSame('Test API', $spec['info']['title']);
        $this->assertSame('2.0.0', $spec['info']['version']);
        $this->assertSame('3.1.0', $spec['openapi']);
    }

    /** Regenerating an unchanged route table must produce an identical document. */
    public function test_generation_is_deterministic(): void
    {
        $routes = [
            ['method' => 'DELETE', 'path' => '/z/{id:num}', 'handler' => 'App\\ZController@destroy'],
            ['method' => 'GET', 'path' => '/a', 'handler' => 'App\\AController@index'],
            ['method' => 'POST', 'path' => '/a', 'handler' => 'App\\AController@create'],
        ];

        $first = json_encode($this->specFor($routes));
        $second = json_encode($this->specFor($routes));

        $this->assertSame($first, $second);
    }
}
