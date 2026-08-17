<?php

declare(strict_types=1);

namespace Tests\Unit\Kernel\Pipelines\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\{CoreContainer, ModuleContainer};
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\{Request, Response};
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\FilterRegistry;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\RouteMatcher;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Stages\{ExecuteStage, ResolveStage, RouteFilterStage};
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** A controller the stages can actually invoke. */
final class StageTestController
{
    public function show(Request $request, string $id = ''): Response
    {
        return Response::json(['id' => $id, 'body' => 'x']);
    }
}

/** Records that it ran and what arguments the route handed it. */
final class RecordingFilterStage implements HttpStageContract
{
    /** @var list<array<string, list<string>>> */
    public static array $seen = [];

    public function handle(Request $request, callable $next): Response
    {
        self::$seen[] = $request->attribute('filter_args', []);

        return $next($request);
    }
}

/**
 * The routing stages wired together — what the unit tests of RouteMatcher and
 * the compiler cannot show on their own: that the precompiled entry keys the
 * stages now read are actually the ones the pipeline produces, and that the old
 * un-precompiled entry shape still works.
 */
#[CoversClass(ResolveStage::class)]
#[CoversClass(ExecuteStage::class)]
#[CoversClass(RouteFilterStage::class)]
#[CoversClass(FilterRegistry::class)]
final class RoutingStagesTest extends TestCase
{
    protected function setUp(): void
    {
        RecordingFilterStage::$seen = [];
    }

    private function container(): ModuleContainer
    {
        return new ModuleContainer(new CoreContainer());
    }

    private function request(string $method, string $path): Request
    {
        return Request::create($path, $method)->withContainer($this->container());
    }

    /** @param array<string, mixed> $entry */
    private function matcher(array $entry, string $key = 'GET /u/{id:num}'): RouteMatcher
    {
        return new RouteMatcher([$key => $entry]);
    }

    /** @return array<string, mixed> */
    private function precompiledEntry(): array
    {
        return [
            'handler' => StageTestController::class . '@show',
            'class'   => StageTestController::class,
            'action'  => 'show',
            'solves'  => '__project__',
            'filters' => [],
        ];
    }

    // ── ResolveStage ────────────────────────────────────────────────────────

    public function test_a_match_publishes_the_route_attributes(): void
    {
        $stage = new ResolveStage($this->matcher($this->precompiledEntry()));

        $seen = null;
        $stage->handle($this->request('GET', '/u/7'), function (Request $r) use (&$seen): Response {
            $seen = $r;

            return Response::empty(200);
        });

        self::assertSame(['id' => '7'], $seen->attribute('route_params'));
        self::assertSame('__project__', $seen->attribute('target_service'));
        self::assertSame(StageTestController::class, $seen->attribute('route_entry')['class']);
    }

    public function test_a_miss_is_a_404_before_anything_downstream_runs(): void
    {
        $stage = new ResolveStage($this->matcher($this->precompiledEntry()));

        $response = $stage->handle(
            $this->request('GET', '/nope'),
            static fn(): Response => self::fail('the pipeline must stop at the miss'),
        );

        self::assertSame(404, $response->status());
    }

    public function test_a_wrong_method_is_a_404_by_default(): void
    {
        // 405 confirms that a path exists, so it stays opt-in.
        $stage = new ResolveStage($this->matcher($this->precompiledEntry()));

        self::assertSame(404, $stage->handle(
            $this->request('POST', '/u/7'),
            static fn(): Response => Response::empty(200),
        )->status());
    }

    public function test_405_is_returned_with_an_allow_header_when_enabled(): void
    {
        $stage = new ResolveStage($this->matcher($this->precompiledEntry()), methodNotAllowed: true);

        $response = $stage->handle(
            $this->request('POST', '/u/7'),
            static fn(): Response => Response::empty(200),
        );

        self::assertSame(405, $response->status());
        self::assertSame('GET, HEAD', $response->headers()['Allow']);
    }

    public function test_a_face_restricted_route_is_invisible_on_another_face(): void
    {
        $entry = ['faces' => ['admin']] + $this->precompiledEntry();
        $stage = new ResolveStage($this->matcher($entry));

        $request = $this->request('GET', '/u/7')->withAttribute('route_face', 'api');

        self::assertSame(404, $stage->handle(
            $request,
            static fn(): Response => Response::empty(200),
        )->status());
    }

    public function test_a_face_restricted_route_resolves_on_its_own_face(): void
    {
        $entry = ['faces' => ['admin']] + $this->precompiledEntry();
        $stage = new ResolveStage($this->matcher($entry));

        $request = $this->request('GET', '/u/7')->withAttribute('route_face', 'admin');

        self::assertSame(200, $stage->handle($request, static fn(): Response => Response::empty(200))->status());
    }

    public function test_an_unrestricted_route_is_unaffected_by_the_face(): void
    {
        $stage   = new ResolveStage($this->matcher($this->precompiledEntry()));
        $request = $this->request('GET', '/u/7')->withAttribute('route_face', 'api');

        self::assertSame(200, $stage->handle($request, static fn(): Response => Response::empty(200))->status());
    }

    // ── ExecuteStage ────────────────────────────────────────────────────────

    public function test_it_invokes_the_precompiled_class_and_action(): void
    {
        $request = $this->request('GET', '/u/7')
            ->withAttribute('route_entry', $this->precompiledEntry())
            ->withAttribute('route_params', ['id' => '7']);

        $response = (new ExecuteStage())->handle($request, static fn(): Response => Response::empty(200));

        self::assertSame(200, $response->status());
        self::assertStringContainsString('"id":"7"', $response->body());
    }

    public function test_a_legacy_entry_without_the_precompiled_split_still_runs(): void
    {
        // A manifest compiled by an older kernel has only `handler`.
        $entry = [
            'handler' => StageTestController::class . '@show',
            'solves'  => '__project__',
        ];

        $request = $this->request('GET', '/u/7')
            ->withAttribute('route_entry', $entry)
            ->withAttribute('route_params', ['id' => '9']);

        $response = (new ExecuteStage())->handle($request, static fn(): Response => Response::empty(200));

        self::assertStringContainsString('"id":"9"', $response->body());
    }

    public function test_a_head_request_keeps_the_headers_and_drops_the_body(): void
    {
        $request = $this->request('HEAD', '/u/7')
            ->withAttribute('route_entry', $this->precompiledEntry())
            ->withAttribute('route_params', ['id' => '7'])
            ->withAttribute('correlation_id', 'abc-123');

        $response = (new ExecuteStage())->handle($request, static fn(): Response => Response::empty(200));

        self::assertSame(200, $response->status());
        self::assertSame('', $response->body(), 'HEAD must not carry a body');
        self::assertSame('abc-123', $response->headers()['X-Correlation-ID']);
    }

    // ── RouteFilterStage ────────────────────────────────────────────────────

    public function test_precompiled_filter_specs_are_used_verbatim(): void
    {
        $registry = new FilterRegistry();
        $registry->register('throttle', RecordingFilterStage::class);

        $entry = $this->precompiledEntry();
        $entry['filter_specs'] = [['alias' => 'throttle', 'args' => ['60', '1']]];

        $request  = $this->request('GET', '/u/7')->withAttribute('route_entry', $entry);
        $response = (new RouteFilterStage($registry, new CoreContainer()))
            ->handle($request, static fn(): Response => Response::empty(204));

        self::assertSame(204, $response->status());
        self::assertSame([['throttle' => ['60', '1']]], RecordingFilterStage::$seen);
    }

    public function test_a_legacy_entry_falls_back_to_parsing_the_raw_specs(): void
    {
        $registry = new FilterRegistry();
        $registry->register('throttle', RecordingFilterStage::class);

        $entry = ['filters' => ['throttle:5,1']] + $this->precompiledEntry();

        $request = $this->request('GET', '/u/7')->withAttribute('route_entry', $entry);
        (new RouteFilterStage($registry, new CoreContainer()))
            ->handle($request, static fn(): Response => Response::empty(204));

        self::assertSame([['throttle' => ['5', '1']]], RecordingFilterStage::$seen);
    }

    public function test_an_unknown_alias_stops_the_request_rather_than_skipping_the_filter(): void
    {
        $registry = new FilterRegistry();
        $request  = $this->request('GET', '/u/7')->withAttribute(
            'route_entry',
            ['filter_specs' => [['alias' => 'ghost', 'args' => []]]] + $this->precompiledEntry(),
        );

        $this->expectExceptionMessageMatches('/Unknown route filter alias \[ghost\]/');

        (new RouteFilterStage($registry, new CoreContainer()))
            ->handle($request, static fn(): Response => Response::empty(204));
    }

    public function test_a_resolved_filter_stage_is_reused_across_requests(): void
    {
        $registry = new FilterRegistry();
        $registry->register('throttle', RecordingFilterStage::class);
        $core = new CoreContainer();

        self::assertSame(
            $registry->resolve('throttle', $core),
            $registry->resolve('throttle', $core),
            'stages are stateless — constructing one per request is pure waste',
        );
    }
}
