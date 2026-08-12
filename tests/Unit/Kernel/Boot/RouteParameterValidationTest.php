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
 * A misspelled parameter type must fail the BOOT, not compile into a route that
 * silently never matches. `{id:number}` looks right and would 404 every request
 * to that endpoint, reading as a missing controller rather than a typo.
 *
 * Same anti-typo guard already applied to unknown requires[] domains and to
 * route-disable specs that match nothing.
 */
#[CoversClass(CompileRouteManifestStage::class)]
final class RouteParameterValidationTest extends TestCase
{
    private string $root;
    private ?string $previousProject = null;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hkm-routeparam-' . bin2hex(random_bytes(6));
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

    /** @param list<array<string, mixed>> $projectRoutes */
    private function compile(array $projectRoutes): void
    {
        (new CompileRouteManifestStage(
            [],
            projectRoutes: $projectRoutes,
            reader: new ManifestReader(),
        ))->run();
    }

    public function test_a_valid_typed_route_compiles(): void
    {
        $this->compile([
            ['method' => 'GET', 'path' => '/users/{id:num}', 'handler' => 'App\\C@show'],
        ]);

        $manifest = ManifestReader::readCompiled('route-manifest.php');
        self::assertArrayHasKey('GET /users/{id:num}', $manifest);
    }

    public function test_an_untyped_route_still_compiles_unchanged(): void
    {
        $this->compile([
            ['method' => 'GET', 'path' => '/users/{id}', 'handler' => 'App\\C@show'],
        ]);

        self::assertArrayHasKey('GET /users/{id}', ManifestReader::readCompiled('route-manifest.php'));
    }

    public function test_an_unknown_type_fails_the_boot(): void
    {
        $this->expectException(BootException::class);
        $this->expectExceptionMessageMatches('/unknown parameter type \[number\]/');

        $this->compile([
            ['method' => 'GET', 'path' => '/users/{id:number}', 'handler' => 'App\\C@show'],
        ]);
    }

    public function test_the_error_names_the_placeholder_and_lists_valid_types(): void
    {
        try {
            $this->compile([
                ['method' => 'GET', 'path' => '/p/{slug:txt}', 'handler' => 'App\\C@show'],
            ]);
            self::fail('expected a BootException');
        } catch (BootException $e) {
            self::assertStringContainsString('{slug}', $e->getMessage());
            self::assertStringContainsString('num', $e->getMessage(), 'valid types are listed');
        }
    }
}
