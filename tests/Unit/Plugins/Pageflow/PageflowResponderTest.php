<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Pageflow;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\CoreContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Pageflow\API\Contracts\PageflowSharerContract;
use Plugins\Pageflow\Http\CallablePageflowSharer;
use Plugins\Pageflow\Http\CompositePageflowSharer;
use Plugins\Pageflow\Http\PageflowPage;
use Plugins\Pageflow\Http\PageflowResponder;
use Plugins\Pageflow\Http\PageflowShares;
use Plugins\Pageflow\Http\RegistryPageflowSharer;
use Plugins\Pageflow\Http\PageflowStage;

#[CoversClass(PageflowResponder::class)]
#[CoversClass(PageflowPage::class)]
#[CoversClass(PageflowStage::class)]
final class PageflowResponderTest extends TestCase
{
    private ?string $layoutPath = null;

    protected function tearDown(): void
    {
        if ($this->layoutPath !== null && is_file($this->layoutPath)) {
            @unlink($this->layoutPath);
        }
        parent::tearDown();
    }

    private function responder(): PageflowResponder
    {
        // A PHP layout template rendered via ob_start with $FLOW_PAGE in scope.
        $this->layoutPath = tempnam(sys_get_temp_dir(), 'pf_') . '.php';
        file_put_contents(
            $this->layoutPath,
            '<html><body><div id="app"></div><?= $FLOW_PAGE->renderScript() ?></body></html>',
        );

        return new PageflowResponder(version: 'v1', layoutPath: $this->layoutPath, appId: 'app');
    }

    /** @param array<string,string> $headers */
    private function request(array $headers = [], string $method = 'GET', string $path = '/users'): Request
    {
        return Request::build(method: $method, path: $path, headers: $headers);
    }

    public function test_full_load_boots_client_from_window_initial_page(): void
    {
        $body = $this->responder()->render($this->request(), 'Users/Index', 'admin', ['users' => [['id' => 1]]])->body();

        $this->assertStringContainsString('window.initialPage =', $body);
        $this->assertStringContainsString('Users/Index', $body);
        $this->assertStringContainsString('<html>', $body);
    }

    public function test_accept_json_is_treated_as_pageflow_request(): void
    {
        $response = $this->responder()->render(
            $this->request(['Accept' => 'application/json']),
            'Users/Index', 'admin',
            ['a' => 1],
        );

        $this->assertSame('true', $response->headers()['X-Pageflow'] ?? null);
    }

    public function test_shared_data_is_merged_into_every_page(): void
    {
        $responder = $this->responder();
        $responder->share('auth', ['id' => 7]);

        $response = $responder->render($this->request(['X-Pageflow' => 'true']), 'Users/Index', 'admin', ['a' => 1]);
        $props = json_decode($response->body(), true)['props'];

        $this->assertSame(['id' => 7], $props['auth']);
        $this->assertSame(1, $props['a']);
    }

    public function test_page_props_override_shared_data(): void
    {
        $responder = $this->responder();
        $responder->mergeShared(['flash' => 'old']);

        $response = $responder->render($this->request(['X-Pageflow' => 'true']), 'Users/Index', 'admin', ['flash' => 'new']);

        $this->assertSame('new', json_decode($response->body(), true)['props']['flash']);
    }

    public function test_partial_navigation_rewrites_url_and_component_from_headers(): void
    {
        $response = $this->responder()->render(
            $this->request([
                'X-Pageflow'      => 'true',
                'X-Pageflow-Url'  => 'https://example.com/dashboard?tab=1',
                'X-Pageflow-Page' => 'Dashboard/Home',
            ]),
            'Users/Index', 'admin',
            ['a' => 1],
            loadPage: false,
        );
        $page = json_decode($response->body(), true);

        $this->assertSame('Dashboard/Home', $page['component']);
        $this->assertSame('/dashboard', $page['url']);
    }

    public function test_xhr_request_returns_json_page_object(): void
    {
        $response = $this->responder()->render($this->request(['X-Pageflow' => 'true']), 'Users/Index', 'admin', ['users' => [1, 2]]);
        $page = json_decode($response->body(), true);

        $this->assertSame('Users/Index', $page['component']);
        $this->assertSame('/users', $page['url']);
        $this->assertSame('v1', $page['version']);
        $this->assertSame('true', $response->headers()['X-Pageflow'] ?? null);
    }

    public function test_partial_reload_returns_only_requested_props(): void
    {
        $response = $this->responder()->render(
            $this->request([
                'X-Pageflow' => 'true',
                'X-Pageflow-Partial-Data' => 'a,b',
                'X-Pageflow-Partial-Component' => 'Users/Index',
            ]),
            'Users/Index', 'admin',
            ['a' => 1, 'b' => 2, 'c' => 3],
        );

        $this->assertSame(['a' => 1, 'b' => 2], (array) json_decode($response->body(), true)['props']);
    }

    public function test_partial_reload_except_drops_named_props(): void
    {
        $response = $this->responder()->render(
            $this->request([
                'X-Pageflow' => 'true',
                'X-Pageflow-Partial-Except' => 'c',
                'X-Pageflow-Partial-Component' => 'Users/Index',
            ]),
            'Users/Index', 'admin',
            ['a' => 1, 'b' => 2, 'c' => 3],
        );

        $this->assertSame(['a' => 1, 'b' => 2], (array) json_decode($response->body(), true)['props']);
    }

    public function test_partial_rules_ignored_when_component_differs(): void
    {
        $response = $this->responder()->render(
            $this->request([
                'X-Pageflow' => 'true',
                'X-Pageflow-Partial-Data' => 'a',
                'X-Pageflow-Partial-Component' => 'Other',
            ]),
            'Users/Index', 'admin',
            ['a' => 1, 'b' => 2],
        );

        $this->assertSame(['a' => 1, 'b' => 2], (array) json_decode($response->body(), true)['props']);
    }

    public function test_version_stage_returns_409_for_stale_client_version(): void
    {
        putenv('PAGEFLOW_VERSION=v2');
        $stage = new PageflowStage();
        $next = static fn(Request $r) => \AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response::text('OK');

        $response = $stage->handle(
            $this->request(['X-Pageflow' => 'true', 'X-Pageflow-Version' => 'v1']),
            $next,
        );

        $this->assertSame(409, $response->status());
        $this->assertSame('/users', $response->headers()['X-Pageflow-Location'] ?? null);
        putenv('PAGEFLOW_VERSION');
    }

    public function test_version_stage_passes_matching_version_through(): void
    {
        putenv('PAGEFLOW_VERSION=v2');
        $stage = new PageflowStage();
        $next = static fn(Request $r) => \AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response::text('OK');

        $response = $stage->handle(
            $this->request(['X-Pageflow' => 'true', 'X-Pageflow-Version' => 'v2']),
            $next,
        );

        $this->assertSame('OK', $response->body());
        putenv('PAGEFLOW_VERSION');
    }

    public function test_share_stage_invokes_bound_sharer_on_the_responder(): void
    {
        $core = new CoreContainer();
        $container = new ModuleContainer($core);
        $container->setScope('http.pageflow');

        $responder = $this->responder();
        $container->instance(PageflowResponder::class, $responder);
        $container->instance(PageflowSharerContract::class, new class implements PageflowSharerContract {
            public function share(Request $request, PageflowResponder $responder): void
            {
                $responder->share('auth', ['id' => 42]);
            }
        });

        $request = $this->request(['X-Pageflow' => 'true'])->withContainer($container);
        $reached = false;
        (new PageflowStage())->handle($request, static function (Request $r) use (&$reached) {
            $reached = true;
            return Response::text('OK');
        });

        $this->assertTrue($reached);
        $props = json_decode($responder->render($request, 'Users/Index', 'admin', [])->body(), true)['props'];
        $this->assertSame(['id' => 42], $props['auth']);
    }

    public function test_composite_runs_all_contributors_last_wins_on_collision(): void
    {
        $responder = $this->responder();
        $sharer = new CompositePageflowSharer(
            new CallablePageflowSharer(static function (Request $r, PageflowResponder $p): void {
                $p->mergeShared(['appName' => 'HKM', 'flash' => 'old']);
            }),
        );
        $sharer->add(new CallablePageflowSharer(static function (Request $r, PageflowResponder $p): void {
            $p->share('cartCount', 3);
            $p->share('flash', 'new'); // later contributor wins
        }));

        $request = $this->request(['X-Pageflow' => 'true']);
        $sharer->share($request, $responder);
        $props = json_decode($responder->render($request, 'Users/Index', 'admin', [])->body(), true)['props'];

        $this->assertSame('HKM', $props['appName']);
        $this->assertSame(3, $props['cartCount']);
        $this->assertSame('new', $props['flash']);
    }

    public function test_pageflow_share_helper_registers_keyed_and_raw_contributors(): void
    {
        PageflowShares::flush();
        pageflow_share('year', static fn(Request $r): string => '2026');
        pageflow_share(static function (Request $r, PageflowResponder $p): void {
            $p->mergeShared(['appName' => 'HKM', 'path' => $r->path()]);
        });

        $responder = $this->responder();
        $request = $this->request(['X-Pageflow' => 'true'], path: '/blog');
        (new RegistryPageflowSharer())->share($request, $responder);
        $props = json_decode($responder->render($request, 'Blog/Index', 'admin', [])->body(), true)['props'];

        $this->assertSame('2026', $props['year']);
        $this->assertSame('HKM', $props['appName']);
        $this->assertSame('/blog', $props['path']);
        PageflowShares::flush();
    }

    public function test_pageflow_share_rejects_a_key_without_a_resolver(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        pageflow_share('auth');
    }

    public function test_share_stage_passes_through_without_a_container(): void
    {
        $response = (new PageflowStage())->handle(
            $this->request(),
            static fn(Request $r) => Response::text('OK'),
        );

        $this->assertSame('OK', $response->body());
    }
}
