<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Tests;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ScopeViolationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use AlfacodeTeam\Ground\PluginGround;
use AlfacodeTeam\Ground\PluginGroundTestCase;
use AlfacodeTeam\Ground\Tests\Fixtures\Essential\AmbientContract;
use AlfacodeTeam\Ground\Tests\Fixtures\Essential\Provider as EssentialProvider;
use AlfacodeTeam\Ground\Tests\Fixtures\Sample\Persistence\SampleRepository;
use AlfacodeTeam\Ground\Tests\Fixtures\Sample\Provider;
use AlfacodeTeam\Ground\Tests\Fixtures\Sample\SampleServiceContract;

/**
 * The harness, tested against a real plugin.
 *
 * These assert the things the ground CLAIMS: that it runs the real pipeline,
 * that scope isolation is genuinely enforced rather than simulated, and that a
 * boot writes nothing outside its workspace.
 */
final class PluginGroundTest extends PluginGroundTestCase
{
    protected function plugin(): string
    {
        return Provider::class;
    }

    // ── Boot ──────────────────────────────────────────────────────────────────

    public function testItBootsAPluginWithNoProjectAndNoInfrastructure(): void
    {
        self::assertSame('ground.sample', $this->ground()->manifest()->solves());
    }

    public function testItReportsRequiredConfigThatHadNoDefault(): void
    {
        // GROUND_SAMPLE_SECRET is required with no default, so the ground had to
        // substitute one. Reporting it is what stops a test asserting against a
        // placeholder without knowing.
        self::assertSame(['GROUND_SAMPLE_SECRET'], $this->ground()->placeholders());
    }

    public function testDeclaredDefaultsAreSeededFromTheManifest(): void
    {
        $response = $this->ground()->get('/samples');

        self::assertSame(25, $response->at('limit'), $response->describe());
    }

    public function testCallerSuppliedEnvBeatsTheDeclaredDefault(): void
    {
        $ground = $this->bootGround(
            static fn(PluginGround $g): PluginGround => $g->env(['GROUND_SAMPLE_LIMIT' => 3]),
        );

        try {
            self::assertSame(3, $ground->get('/samples')->at('limit'));
        } finally {
            $ground->destroy();
        }
    }

    // ── Routing ───────────────────────────────────────────────────────────────

    public function testRoutesCompileWithTheModuleWidePrefix(): void
    {
        $this->assertRouteExists('GET', '/samples');
        $this->assertRouteExists('POST', '/samples');
        $this->assertRouteExists('GET', '/samples/{id:num}');
    }

    public function testRequestsGoThroughTheRealMatcher(): void
    {
        $response = $this->ground()->get('/samples/7');

        $this->assertOk($response);
        self::assertSame('7', $response->at('id'));
    }

    public function testTheRealParameterTypesAreEnforced(): void
    {
        // Proof the ground is not simulating routing: {id:num} refusing 'abc' is
        // the kernel's matcher, not anything this harness implements.
        $this->assertStatus(404, $this->ground()->get('/samples/abc'));
    }

    // ── Ports ─────────────────────────────────────────────────────────────────

    public function testTheFakeDatabaseFeedsTheRepository(): void
    {
        $this->ground()->db()->onQuery('select', ['id' => 1, 'title' => 'Recorded']);

        $response = $this->ground()->get('/samples');

        self::assertSame('Recorded', $response->at('items.0.title'), $response->describe());
    }

    public function testWritesAreRecordedWithTheirBoundParameters(): void
    {
        $this->ground()->post('/samples', ['title' => 'Written']);

        self::assertTrue($this->ground()->db()->ran('INSERT INTO samples'));
        self::assertSame('Written', $this->ground()->db()->paramsFor('INSERT')[0]['title'] ?? null);
    }

    public function testTheSameFakeInstanceIsVisibleToTheTestAndTheModule(): void
    {
        // The port is bound eagerly for exactly this reason: a lazy factory would
        // build a second instance on resolution and every assertion above would
        // read an empty double.
        self::assertSame(
            $this->ground()->db(),
            $this->ground()->core()->get(\AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort::class),
        );
    }

    // ── Events ────────────────────────────────────────────────────────────────

    public function testDeclaredEventsAreRecorded(): void
    {
        $this->ground()->post('/samples', ['title' => 'Eventful']);

        $this->assertDispatched('ground.sample.created', times: 1);
        self::assertSame('Eventful', $this->ground()->events()->payloadOf('ground.sample.created')['title']);
    }

    public function testAnEventThatNeverFiredIsNotReported(): void
    {
        $this->assertNotDispatched('ground.sample.created');
    }

    // ── Scope isolation ───────────────────────────────────────────────────────

    public function testTheExposedContractResolvesInThePluginScope(): void
    {
        self::assertInstanceOf(SampleServiceContract::class, $this->ground()->service(SampleServiceContract::class));
    }

    public function testAnInternalBindingIsUnreachableFromAnotherModule(): void
    {
        // The rule the container enforces at runtime — asserted here so a plugin
        // author can prove their own bindInternal() choices actually hold.
        $this->expectException(ScopeViolationException::class);

        $this->ground()->makeFromScope(SampleRepository::class, 'some.other.module');
    }

    public function testTheOwningScopeReachesItsOwnInternalBinding(): void
    {
        self::assertInstanceOf(
            SampleRepository::class,
            $this->ground()->makeFromScope(SampleRepository::class, 'ground.sample'),
        );
    }

    // ── Essential modules ─────────────────────────────────────────────────────

    public function testAnEssentialModulesBindingsReachTheGroundsOwnContainer(): void
    {
        // The divergence this covers: HttpPipeline builds its OnDemandLoader
        // WITH the essential providers (HttpPipeline.php:114) while the ground
        // built its own WITHOUT them — so `$ground->get()` saw an essential's
        // bindings and `$ground->service()` did not, silently.
        $ground = $this->bootGround(static fn(PluginGround $g): PluginGround => $g
            ->with(EssentialProvider::class)
            ->essential('ground.essential'));

        try {
            self::assertInstanceOf(
                AmbientContract::class,
                $ground->container()->make(AmbientContract::class),
            );
        } finally {
            $ground->destroy();
        }
    }

    public function testAnEssentialDeclaredByCLASSAlsoResolves(): void
    {
        // essential() accepts class-strings as well as solves domains; the
        // kernel resolves domains internally and keeps the result private, so
        // the ground has to resolve both shapes itself.
        $ground = $this->bootGround(static fn(PluginGround $g): PluginGround => $g
            ->essential(EssentialProvider::class));

        try {
            self::assertSame('ambient', $ground->container()->make(AmbientContract::class)->marker());
        } finally {
            $ground->destroy();
        }
    }

    public function testWithoutTheEssentialFlagTheBindingIsAbsent(): void
    {
        // Proves the test above is measuring the flag and not something ambient:
        // merely REGISTERING the module must not bind it for every request.
        $ground = $this->bootGround(static fn(PluginGround $g): PluginGround => $g
            ->with(EssentialProvider::class));

        try {
            $this->expectException(\Throwable::class);
            $ground->container()->make(AmbientContract::class);
        } finally {
            $ground->destroy();
        }
    }

    public function testUsingAGroundAfterDestroyFailsLoudly(): void
    {
        $ground = $this->bootGround(static fn(PluginGround $g): PluginGround => $g);
        $ground->destroy();

        // Otherwise Paths points at a deleted directory and every manifest reads
        // as empty — surfacing as "route not found" far from the real cause.
        self::assertTrue($ground->isDestroyed());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/was destroyed/');
        $ground->get('/samples');
    }

    // ── Identity ──────────────────────────────────────────────────────────────

    public function testIdentityReachesTheRequestScopedContainer(): void
    {
        $this->ground()->as(Identity::asAdmin('tenant-7'));

        $identity = $this->ground()->container()->make(Identity::class);

        self::assertSame('tenant-7', $identity->tenantId);
        self::assertTrue($identity->hasRole('admin'));
    }

    public function testWithoutAnIdentityTheRequestIsAGuest(): void
    {
        self::assertTrue($this->ground()->container()->make(Identity::class)->isGuest());
    }

    // ── Isolation ─────────────────────────────────────────────────────────────

    public function testManifestsAreWrittenIntoTheWorkspaceNotTheProject(): void
    {
        $root = $this->ground()->workspace()->root;

        // The whole reason the workspace exists: compiling into the project would
        // overwrite its manifests with one describing only this plugin.
        self::assertFileExists($root . '/var/cache/manifests/route-manifest.php');
    }

    public function testDestroyRemovesTheWorkspaceAndRestoresTheEnvironment(): void
    {
        $ground = $this->bootGround(static fn(PluginGround $g): PluginGround => $g);
        $root   = $ground->workspace()->root;

        self::assertDirectoryExists($root);
        self::assertArrayHasKey('GROUND_SAMPLE_LIMIT', $_ENV);

        $ground->destroy();

        self::assertDirectoryDoesNotExist($root);
        self::assertArrayNotHasKey('GROUND_SAMPLE_LIMIT', $_ENV);
    }

    public function testTwoGroundsDoNotStealEachOthersWorkspace(): void
    {
        // The second ground declares a route the first does NOT have.
        //
        // Booting two grounds with the SAME plugin cannot test this at all:
        // both workspaces would hold byte-identical manifests, so reading the
        // wrong one is indistinguishable from reading the right one and the
        // assertion passes even when isolation is completely broken. The extra
        // route is what makes this test capable of failing.
        $other = $this->bootGround(static fn(PluginGround $g): PluginGround => $g->routes([
            ['method' => 'GET', 'path' => '/only-in-the-second-ground', 'handler' => 'Second\\Controller@index'],
        ]));

        try {
            self::assertNotSame($this->ground()->workspace()->root, $other->workspace()->root);

            // Booting the second re-pointed the global Paths singleton. The
            // first must still read its OWN manifests — that is activate().
            self::assertTrue($this->ground()->hasRoute('GET', '/samples'));
            self::assertFalse(
                $this->ground()->hasRoute('GET', '/only-in-the-second-ground'),
                'The first ground is reading the second ground\'s compiled manifest.',
            );

            // And the second still sees its own.
            self::assertTrue($other->hasRoute('GET', '/only-in-the-second-ground'));
        } finally {
            $other->destroy();
        }
    }
}
