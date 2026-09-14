<?php

declare(strict_types=1);

namespace Tests\Feature\Kernel;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\{Request, Response};

/** A project controller with no module dependencies at all. */
final class ProjectPingController
{
    public function ping(Request $request): Response
    {
        return Response::json(['pong' => true]);
    }
}
