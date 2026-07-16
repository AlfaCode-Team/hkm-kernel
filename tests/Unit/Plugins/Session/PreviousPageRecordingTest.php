<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Session;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\CoreContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SessionPort;
use PHPUnit\Framework\TestCase;
use Plugins\Session\Infrastructure\Http\StartSessionStage;
use Tests\Unit\Plugins\Auth\Support\FakeSession;

/**
 * The previous-page recorder inside StartSessionStage: remembers the last
 * eligible page view so the Auth/SocialAuth login flows can redirect back.
 */
final class PreviousPageRecordingTest extends TestCase
{
    private FakeSession $session;

    protected function setUp(): void
    {
        $this->session = new FakeSession();
    }

    private function request(string $path, string $method = 'GET', array $query = []): Request
    {
        $container = new ModuleContainer(new CoreContainer());
        $container->setScope('session.management');
        $container->instance(SessionPort::class, $this->session);

        return Request::build(method: $method, path: $path, query: $query)
            ->withContainer($container);
    }

    private function recorded(): mixed
    {
        return $this->session->get(StartSessionStage::PREVIOUS_URL);
    }

    public function test_records_successful_html_page_view_with_query(): void
    {
        (new StartSessionStage())->handle(
            $this->request('/products', query: ['page' => '2']),
            static fn (): Response => Response::html('<h1>Products</h1>'),
        );

        $this->assertSame('/products?page=2', $this->recorded());
    }

    public function test_records_pageflow_page_object(): void
    {
        (new StartSessionStage())->handle(
            $this->request('/dashboard'),
            static fn (): Response => Response::json(['component' => 'Dashboard'])
                ->withHeader('X-Pageflow', 'true'),
        );

        $this->assertSame('/dashboard', $this->recorded());
    }

    public function test_skips_auth_registration_api_and_asset_paths(): void
    {
        $stage = new StartSessionStage();
        $html  = static fn (): Response => Response::html('<p>ok</p>');

        foreach (['/auth/login', '/login', '/register', '/oauth/authorize', '/password/reset',
                  '/api/users', '/ajx/users', '/verify-email', '/app.css'] as $path) {
            $stage->handle($this->request($path), $html);
        }

        $this->assertNull($this->recorded());
    }

    public function test_skips_non_get_plain_json_and_error_responses(): void
    {
        $stage = new StartSessionStage();

        $stage->handle($this->request('/orders', method: 'POST'), static fn (): Response => Response::html('ok'));
        $stage->handle($this->request('/orders'), static fn (): Response => Response::json(['data' => []]));
        $stage->handle($this->request('/orders'), static fn (): Response => Response::notFound());

        $this->assertNull($this->recorded());
    }

    public function test_last_page_wins(): void
    {
        $stage = new StartSessionStage();
        $html  = static fn (): Response => Response::html('ok');

        $stage->handle($this->request('/first'), $html);
        $stage->handle($this->request('/second'), $html);

        $this->assertSame('/second', $this->recorded());
    }
}
