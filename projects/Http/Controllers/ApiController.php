<?php

declare(strict_types=1);

namespace Project\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Contracts\RequestAware;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Project\Http\Controllers\Concerns\InteractsWithCsrf;
use Project\Http\Controllers\Concerns\InteractsWithSession;

/**
 * Base controller for JSON / API endpoints.
 *
 * Pure kernel-typed surface — no plugin or vendor coupling. Extend it for any
 * controller that speaks JSON, and use the protected helpers so every endpoint
 * returns the SAME envelope shape:
 *
 *   { "data": ... }                              // success
 *   { "error": { "code", "message"[, "fields"] } } // failure (kernel Response)
 *
 * Controllers stay thin: build a DTO, call a service, hand the result to one of
 * these helpers. Never put business logic here.
 */
abstract class ApiController implements RequestAware
{
    use InteractsWithCsrf;
    use InteractsWithSession;

    /** 200 OK with a `data` envelope. */
    protected function ok(mixed $data = null, int $status = 200): Response
    {
        return Response::json(['data' => $data], $status);
    }

    /** 201 Created with a `data` envelope and optional Location header. */
    protected function created(mixed $data, ?string $location = null): Response
    {
        return Response::created(['data' => $data], $location);
    }

    /** 202 Accepted — work queued/async. */
    protected function accepted(mixed $data = null): Response
    {
        return Response::accepted($data === null ? null : ['data' => $data]);
    }

    /** 204 No Content — successful mutation with nothing to return. */
    protected function noContent(): Response
    {
        return Response::noContent();
    }

    /**
     * 200 with a paginated collection envelope.
     *
     * @param array<int, mixed> $items
     */
    protected function paginated(array $items, int $total, int $page, int $perPage): Response
    {
        return Response::json([
            'data' => $items,
            'meta' => [
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
            ],
        ]);
    }

    /** 404 — resource not found. */
    protected function notFound(string $message = 'Resource not found.'): Response
    {
        return Response::notFound($message);
    }

    /** 422 — field-level validation failure. */
    protected function unprocessable(array $errors, string $message = 'Validation failed.'): Response
    {
        return Response::unprocessable($errors, $message);
    }

    /** 403 — authenticated but not allowed. */
    protected function forbidden(string $message = 'Forbidden.'): Response
    {
        return Response::forbidden($message);
    }

    /** Resolve a result to a 200 envelope, or 404 when it is null. */
    protected function okOrNotFound(mixed $data, string $message = 'Resource not found.'): Response
    {
        return $data === null ? $this->notFound($message) : $this->ok($data);
    }

    /**
     * The authenticated Identity, or a guest when none was attached. Uses the
     * request injected by ExecuteStage; pass one explicitly to override.
     */
    protected function identity(?Request $request = null): Identity
    {
        return ($request ?? $this->request)?->identity() ?? Identity::guest();
    }
}
