<?php

declare(strict_types=1);

namespace Tests\Feature\Fixtures\HookModule;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\{Request, Response};
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;

/** Marks the response on the way out, recording the status it saw. */
final class AfterSecurityStage implements HttpStageContract
{
    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request);

        return $response->withHeader('X-Hook-Security', (string) $response->status());
    }
}
