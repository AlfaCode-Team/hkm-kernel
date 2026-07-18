<?php

declare(strict_types=1);

namespace {{STUDLY}}\Infrastructure\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use {{STUDLY}}\Application\GreetingService;

/**
 * INFRASTRUCTURE LAYER — a DRIVING adapter (HTTP controller).
 *
 * Adapters on the "driving" side translate an outside trigger (an HTTP request)
 * into a call on the application core, then translate the result back out (a
 * Response). They are the only place that knows about the framework's Request /
 * Response — the Application and Domain layers stay web-agnostic.
 *
 * Keep controllers THIN: read input → call a use case → return a Response. No
 * business logic here. The kernel autowires this controller from the request
 * container, so type-hinting GreetingService is enough to get it injected.
 *
 * Wired to routes in proj.json:
 *   { "method": "GET", "path": "/",     "handler": "{{STUDLY}}\\Infrastructure\\Http\\HomeController@index" }
 *   { "method": "GET", "path": "/ping", "handler": "{{STUDLY}}\\Infrastructure\\Http\\HomeController@ping"  }
 */
final class HomeController
{
    public function __construct(
        private readonly GreetingService $greetings,
    ) {}

    public function index(Request $request): Response
    {
        $greeting = $this->greetings->greet('{{PROJECT_NAME}}');

        return Response::html('<h1>' . $greeting->message() . '</h1>');
    }

    public function ping(Request $request): Response
    {
        return Response::json(['pong' => true, 'project' => '{{PROJECT_NAME}}']);
    }
}
