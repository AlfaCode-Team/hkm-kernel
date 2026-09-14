<?php

declare(strict_types=1);

namespace Tests\Feature\Kernel;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\BootFailureException;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Fixtures\BlogModule\Provider as BlogProvider;
use Tests\Feature\Support\Fakes\{DenyingLayer, PassThroughLayer};
use Tests\Feature\Support\KernelTestCase;

/**
 * The claims the architecture is SOLD on, asserted against a running kernel
 * rather than against a docblock.
 *
 * Each of these is something the README states as a guarantee. A guarantee with
 * no executable test is a description of intent.
 */
#[Group('feature')]
final class ArchitectureGuaranteesTest extends KernelTestCase
{
    /**
     * "Security before everything. Denied = zero module cost."
     *
     * Proven by the DATABASE staying untouched: the blog module's register()
     * binds a repository that queries on every index request, so a denial that
     * still loaded modules would show up as a statement.
     */
    public function testADenialCostsZeroModuleLoading(): void
    {
        $this->db->answer('FROM posts', [['id' => '1']]);
        $this->boot(modules: [BlogProvider::class], security: [new DenyingLayer(403)]);

        $this->get('/posts')->assertForbidden();

        self::assertSame([], $this->db->statements, 'A denied request must not reach any module.');
    }

    public function testTheGatewayRunsForEveryRequestIncludingUnroutableOnes(): void
    {
        $layer = new PassThroughLayer();
        $this->boot(modules: [BlogProvider::class], security: [$layer]);

        $this->get('/posts')->assertOk();
        $this->get('/does-not-exist')->assertNotFound();

        self::assertSame(['/posts', '/does-not-exist'], $layer->saw);
    }

    /**
     * "Isolation by default. Modules cannot access each other's internals."
     *
     * The controller reaches for a bindInternal() binding from the project
     * scope. That must throw, and the throw must become a 500 rather than a
     * leaked repository.
     */
    public function testAnInternalBindingIsUnreachableFromAnotherScope(): void
    {
        $this->boot(modules: [BlogProvider::class]);

        $response = $this->get('/posts/internal');

        $response->assertServerError();
        self::assertArrayNotHasKey('leaked', $response->json());
    }

    /**
     * "Load only what is needed."
     *
     * A project route resolves under the synthetic __project__ scope with an
     * EMPTY dependency graph, so a registered-but-unrequired module's bindings
     * never run for it.
     */
    public function testAProjectRouteLoadsNoModulesByDefault(): void
    {
        $this->db->answer('FROM posts', [['id' => '1']]);
        $this->boot(
            modules: [BlogProvider::class],
            routes: [[
                'method'  => 'GET',
                'path'    => '/ping',
                'handler' => ProjectPingController::class . '@ping',
            ]],
        );

        $this->get('/ping')->assertOk()->assertExactJson(['pong' => true]);

        self::assertSame([], $this->db->statements);
    }

    /** A project route CAN opt one module in, per route, via requires[]. */
    public function testAProjectRouteCanRequireOneModule(): void
    {
        $this->boot(
            modules: [BlogProvider::class],
            routes: [[
                'method'   => 'GET',
                'path'     => '/dashboard',
                'handler'  => ProjectDashboardController::class . '@index',
                'requires' => ['blog.publishing'],
            ]],
        );

        $this->db->answer('FROM posts', [['id' => '1', 'title' => 'Visible']]);

        $this->get('/dashboard')->assertOk()->assertJsonFragment(['count' => 1]);
    }

    /** An unknown requires[] domain must fail the BUILD, not a request. */
    public function testAnUnknownRequiresDomainFailsTheBoot(): void
    {
        $this->expectException(BootFailureException::class);
        $this->expectExceptionMessageMatches('/blog\.nonexistent/');

        $this->boot(
            modules: [BlogProvider::class],
            routes: [[
                'method'   => 'GET',
                'path'     => '/x',
                'handler'  => ProjectPingController::class . '@ping',
                'requires' => ['blog.nonexistent'],
            ]],
        );
    }

    /** "Project resources override plugin resources by default." */
    public function testAProjectRouteOverridesAPluginRouteOnTheSameMethodAndPath(): void
    {
        $this->db->answer('FROM posts', [['id' => '1', 'title' => 'From the module']]);

        $this->boot(
            modules: [BlogProvider::class],
            routes: [[
                'method'  => 'GET',
                'path'    => '/posts',
                'handler' => ProjectPingController::class . '@ping',
            ]],
        );

        $this->get('/posts')->assertOk()->assertExactJson(['pong' => true]);
    }

    /** The third route verb: a project vetoes a plugin route without forking it. */
    public function testAProjectCanDisableAPluginRoute(): void
    {
        $this->boot(modules: [BlogProvider::class], disable: ['GET /posts']);

        $this->get('/posts')->assertNotFound();
        $this->get('/posts/me')->assertOk();
    }

    /** Disabling by module DOMAIN takes the whole plugin off. */
    public function testAProjectCanDisableAnEntireModulesRoutes(): void
    {
        $this->boot(modules: [BlogProvider::class], disable: ['blog.publishing']);

        $this->get('/posts')->assertNotFound();
        $this->get('/posts/me')->assertNotFound();
    }

    /** A disable spec matching nothing is a typo, and must fail loudly. */
    public function testADisableSpecThatMatchesNothingFailsTheBoot(): void
    {
        $this->expectException(BootFailureException::class);

        $this->boot(modules: [BlogProvider::class], disable: ['GET /not-a-real-route']);
    }

    /** "The kernel refuses to boot with no security layer." */
    public function testTheKernelRefusesToBootWithNoSecurityLayer(): void
    {
        $this->expectException(BootFailureException::class);
        $this->expectExceptionMessageMatches('/No security layers configured/');

        // Bypass the harness default and hand the builder an explicit empty set.
        \AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths::setBase($this->root);
        \AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths::setProject($this->root);

        \AlfacodeTeam\PhpServicePlatform\Kernel\Kernel::configure()
            ->withBasePath($this->root)
            ->withProjectPath($this->root)
            ->withPorts([
                \AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort::class => $this->db,
                \AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort::class    => $this->cache,
            ])
            ->withModules([BlogProvider::class])
            ->build();
    }

    /** "The kernel refuses to boot without its required ports." */
    public function testTheKernelRefusesToBootWithoutARequiredPort(): void
    {
        $this->expectException(BootFailureException::class);
        $this->expectExceptionMessageMatches('/Missing port bindings.*CachePort/s');

        \AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths::setBase($this->root);
        \AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths::setProject($this->root);

        \AlfacodeTeam\PhpServicePlatform\Kernel\Kernel::configure()
            ->withBasePath($this->root)
            ->withProjectPath($this->root)
            ->withPorts([
                \AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort::class => $this->db,
            ])
            ->withSecurity([new PassThroughLayer()])
            ->withModules([BlogProvider::class])
            ->build();
    }

    /** The core container is frozen once the kernel materializes. */
    public function testTheCoreContainerIsFrozenAfterMaterialize(): void
    {
        $kernel = $this->boot(modules: [BlogProvider::class]);
        $this->get('/posts');

        self::assertTrue($kernel->container()->isFrozen());

        $this->expectException(\LogicException::class);
        $kernel->container()->bind('anything', static fn() => 1);
    }
}
