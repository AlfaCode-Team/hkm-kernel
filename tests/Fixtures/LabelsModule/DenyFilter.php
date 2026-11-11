<?php

declare(strict_types=1);

namespace Tests\Fixtures\LabelsModule;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\{Request, Response};
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;

/** A route filter that refuses, like `auth` or `throttle` do. */
final class DenyFilter implements HttpStageContract
{
    public function handle(Request $request, callable $next): Response
    {
        return Response::forbidden();
    }
}
