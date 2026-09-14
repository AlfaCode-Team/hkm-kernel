<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\BootFailureException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Fixtures\BlogModule\Provider as BlogProvider;
use Tests\Feature\Kernel\ProjectPingController;
use Tests\Feature\Support\KernelTestCase;

/**
 * The routing behaviour the compiler and matcher promise, exercised through a
 * real request instead of a hand-built manifest.
 *
 * The traversal cases are the ones worth having: RouteMatcher decodes a captured
 * value and re-validates it against its declared type, and the only way to be
 * sure that survived is to send the encoded path and look at what the controller
 * actually received.
 */
#[Group('feature')]
final class RoutingEdgeCasesTest extends KernelTestCase
{
    /** @return iterable<string, array{string}> */
    public static function traversalAttempts(): iterable
    {
        yield 'encoded slash + dotdot' => ['/posts/file/..%2F..%2Fetc%2Fpasswd'];
        yield 'double-encoded'         => ['/posts/file/..%252F..%252Fetc'];
        yield 'plain dotdot'           => ['/posts/file/../../etc/passwd'];
        yield 'encoded nul'            => ['/posts/file/report%00.pdf'];
    }

    /**
     * `{path:path}` crosses '/' but rejects '..' and control characters — the
     * whole reason to prefer it over `{path:any}` for anything reaching a
     * filesystem or a StoragePort.
     */
    #[DataProvider('traversalAttempts')]
    public function testAPathParameterNeverYieldsATraversal(string $uri): void
    {
        $this->boot(modules: [BlogProvider::class]);

        $response = $this->get($uri);

        if ($response->status() === 200) {
            $captured = $response->json()['path'] ?? '';

            self::assertStringNotContainsString('..', $captured, "[{$uri}] decoded into a traversal");
            self::assertStringNotContainsString("\0", $captured, "[{$uri}] decoded into a NUL byte");
        } else {
            self::assertSame(404, $response->status(), "[{$uri}] should 404 if it does not match");
        }
    }

    public function testAPathParameterStillCrossesSlashesForLegitimateValues(): void
    {
        $this->boot(modules: [BlogProvider::class]);

        $this->get('/posts/file/reports/2026/january.pdf')
            ->assertOk()
            ->assertJsonFragment(['path' => 'reports/2026/january.pdf']);
    }

    /** A percent-encoded UTF-8 segment must arrive decoded, not raw. */
    public function testAnEncodedUnicodeSegmentIsDecoded(): void
    {
        $this->boot(modules: [BlogProvider::class]);

        $this->get('/posts/file/Jos%C3%A9.pdf')
            ->assertOk()
            ->assertJsonFragment(['path' => 'José.pdf']);
    }

    public function testTrailingSlashIsStrictByDefault(): void
    {
        $this->boot(modules: [BlogProvider::class]);

        $this->get('/posts')->assertOk();
        $this->get('/posts/')->assertNotFound();
    }

    public function testTrailingSlashCanBeIgnored(): void
    {
        $this->setEnv('ROUTE_TRAILING_SLASH', 'ignore');
        $this->boot(modules: [BlogProvider::class]);

        $this->get('/posts/')->assertOk();
    }

    public function testMethodNotAllowedCanBeTurnedOn(): void
    {
        $this->setEnv('ROUTE_METHOD_NOT_ALLOWED', 'true');
        $this->boot(modules: [BlogProvider::class]);

        $this->put('/posts/1')->assertStatus(405)->assertHeader('Allow');
    }

    public function testHeadFallbackCanBeTurnedOff(): void
    {
        $this->setEnv('ROUTE_HEAD_FALLBACK', 'false');
        $this->boot(modules: [BlogProvider::class]);

        $this->call('HEAD', '/posts')->assertNotFound();
    }

    /** A route path that could never match must fail the BOOT, not 404 forever. */
    public function testAPathWithoutALeadingSlashFailsTheBoot(): void
    {
        $this->expectException(BootFailureException::class);

        $this->boot(routes: [[
            'method'  => 'GET',
            'path'    => 'no-leading-slash',
            'handler' => ProjectPingController::class . '@ping',
        ]]);
    }

    public function testAHandlerWithoutExactlyOneAtSignFailsTheBoot(): void
    {
        $this->expectException(BootFailureException::class);

        $this->boot(routes: [[
            'method'  => 'GET',
            'path'    => '/x',
            'handler' => ProjectPingController::class,
        ]]);
    }

    public function testAnUnknownParameterTypeFailsTheBoot(): void
    {
        $this->expectException(BootFailureException::class);

        $this->boot(routes: [[
            'method'  => 'GET',
            'path'    => '/x/{id:notatype}',
            'handler' => ProjectPingController::class . '@ping',
        ]]);
    }

    public function testDuplicateCaptureNamesFailTheBoot(): void
    {
        $this->expectException(BootFailureException::class);

        $this->boot(routes: [[
            'method'  => 'GET',
            'path'    => '/x/{id}/{id}',
            'handler' => ProjectPingController::class . '@ping',
        ]]);
    }

    /** Route groups expand at boot into ordinary flat routes. */
    public function testAGroupPrefixAndNameAreAppliedToItsRoutes(): void
    {
        $this->boot(groups: [
            'groups' => [[
                'prefix' => '/api/v1',
                'name'   => 'api.',
                'routes' => [[
                    'method'  => 'GET',
                    'path'    => '/ping',
                    'handler' => ProjectPingController::class . '@ping',
                    'name'    => 'ping',
                ]],
            ]],
        ]);

        $this->get('/api/v1/ping')->assertOk()->assertExactJson(['pong' => true]);

        self::assertArrayHasKey('GET /api/v1/ping', $this->manifest('route-manifest.php'));
        self::assertSame('api.ping', $this->manifest('route-manifest.php')['GET /api/v1/ping']['name']);
    }

    /** Nested groups concatenate outward-in. */
    public function testNestedGroupsConcatenateTheirPrefixes(): void
    {
        $this->boot(groups: [
            'groups' => [[
                'prefix' => '/api',
                'groups' => [[
                    'prefix' => '/v2',
                    'routes' => [[
                        'method'  => 'GET',
                        'path'    => '/ping',
                        'handler' => ProjectPingController::class . '@ping',
                    ]],
                ]],
            ]],
        ]);

        $this->get('/api/v2/ping')->assertOk();
    }

    /** Two routes claiming the same NAME is a boot failure — names are one namespace. */
    public function testADuplicateRouteNameFailsTheBoot(): void
    {
        $this->expectException(BootFailureException::class);

        $this->boot(routes: [
            ['method' => 'GET', 'path' => '/a', 'handler' => ProjectPingController::class . '@ping', 'name' => 'same'],
            ['method' => 'GET', 'path' => '/b', 'handler' => ProjectPingController::class . '@ping', 'name' => 'same'],
        ]);
    }
}
