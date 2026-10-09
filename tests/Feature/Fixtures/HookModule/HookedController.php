<?php

declare(strict_types=1);

namespace Tests\Feature\Fixtures\HookModule;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\{Request, Response};

final class HookedController
{
    public function create(Request $request): Response
    {
        return Response::json(['created' => true], 201);
    }
}
