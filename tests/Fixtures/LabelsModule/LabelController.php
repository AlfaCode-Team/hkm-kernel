<?php

declare(strict_types=1);

namespace Tests\Fixtures\LabelsModule;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\{Request, Response};

final class LabelController
{
    public function created(Request $request): Response
    {
        return Response::json(['ok' => true], 201);
    }

    public function show(Request $request, string $id): Response
    {
        return Response::json(['id' => $id]);
    }

    public function invalid(Request $request): Response
    {
        return Response::unprocessable(['name' => 'Required.']);
    }

    public function boom(Request $request): Response
    {
        throw new \RuntimeException('handler failed');
    }
}
