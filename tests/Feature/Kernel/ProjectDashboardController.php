<?php

declare(strict_types=1);

namespace Tests\Feature\Kernel;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\{Request, Response};
use Tests\Feature\Fixtures\BlogModule\API\Contracts\PostServiceContract;

/**
 * A project controller that DOES use a plugin — reachable only because its route
 * declares requires: ["blog.publishing"].
 */
final class ProjectDashboardController
{
    public function __construct(private readonly PostServiceContract $posts) {}

    public function index(Request $request): Response
    {
        return Response::json(['count' => count($this->posts->all())]);
    }
}
