<?php

declare(strict_types=1);

namespace Tests\Unit\Project\Bootstrap;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Project\Bootstrap\EntryHelpers;

/**
 * What the project layer reads out of proj.json and hands to the kernel.
 *
 * These helpers deliberately do NOT validate: they pass declarations straight
 * through to the route-manifest compiler, which is the one place that can report
 * a problem with the surrounding context (which module, which route key).
 */
#[CoversClass(EntryHelpers::class)]
final class ProjectRouteDeclarationTest extends TestCase
{
    private string $project;

    protected function setUp(): void
    {
        $this->project = sys_get_temp_dir() . '/hkm-projroutes-' . bin2hex(random_bytes(6));
        mkdir($this->project, 0775, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->project . '/proj.json');
        @rmdir($this->project);
    }

    /** @param array<string, mixed> $data */
    private function proj(array $data): void
    {
        file_put_contents($this->project . '/proj.json', json_encode($data));
    }

    public function test_route_groups_and_source_defaults_are_read(): void
    {
        $this->proj([
            'name'        => 'hkmvote',
            'routePrefix' => '/app',
            'groups'      => [['domain' => 'hkmvote.local', 'routes' => []]],
            'ignored'     => 'not a route declaration',
        ]);

        $source = EntryHelpers::projectRouteGroups($this->project);

        self::assertSame('/app', $source['routePrefix']);
        self::assertCount(1, $source['groups']);
        self::assertArrayNotHasKey('ignored', $source);
    }

    public function test_a_project_without_groups_yields_nothing(): void
    {
        $this->proj(['name' => 'plain']);

        self::assertSame([], EntryHelpers::projectRouteGroups($this->project));
    }

    public function test_a_missing_proj_json_yields_nothing(): void
    {
        self::assertSame([], EntryHelpers::projectRouteGroups($this->project . '/nope'));
        self::assertSame([], EntryHelpers::projectRoutes($this->project . '/nope'));
    }

    public function test_routes_pass_through_the_domain_name_and_faces_keys(): void
    {
        $this->proj([
            'routes' => [[
                'method' => 'GET', 'path' => '/', 'handler' => 'A\\C@home',
                'name' => 'home', 'domain' => 'hkmvote.local', 'faces' => ['project'],
                'filters' => ['auth'], 'requires' => ['view.rendering'],
            ]],
        ]);

        $route = EntryHelpers::projectRoutes($this->project)[0];

        self::assertSame('hkmvote.local', $route['domain']);
        self::assertSame('home', $route['name']);
        self::assertSame(['project'], $route['faces']);
        self::assertSame(['auth'], $route['filters']);
        self::assertSame(['view.rendering'], $route['requires']);
    }

    public function test_a_subdomain_declaration_passes_through_too(): void
    {
        $this->proj([
            'routes' => [[
                'method' => 'GET', 'path' => '/', 'handler' => 'A\\C@home',
                'subdomain' => 'organizer',
            ]],
        ]);

        self::assertSame('organizer', EntryHelpers::projectRoutes($this->project)[0]['subdomain']);
    }

    public function test_the_projects_registered_domains_are_read(): void
    {
        $this->proj(['name' => 'hkmvote', 'domains' => ['HKMVote.local', ' africavoting.local ', '']]);

        self::assertSame(
            ['hkmvote.local', 'africavoting.local'],
            EntryHelpers::projectDomains($this->project),
        );
    }

    public function test_a_project_without_domains_reads_as_unregistered(): void
    {
        // Which disables the route-domain check entirely — a project that does
        // not register its hosts has no registry to validate against.
        $this->proj(['name' => 'plain']);

        self::assertSame([], EntryHelpers::projectDomains($this->project));
        self::assertSame([], EntryHelpers::projectDomains($this->project . '/nope'));
    }

    public function test_a_malformed_route_is_dropped_rather_than_fatal(): void
    {
        $this->proj(['routes' => [['method' => 'GET'], ['method' => 'GET', 'path' => '/ok', 'handler' => 'A\\C@ok']]]);

        $routes = EntryHelpers::projectRoutes($this->project);

        self::assertCount(1, $routes);
        self::assertSame('/ok', $routes[0]['path']);
    }
}
