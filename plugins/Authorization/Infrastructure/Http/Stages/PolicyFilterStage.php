<?php

declare(strict_types=1);

namespace Plugins\Authorization\Infrastructure\Http\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;
use Plugins\Authorization\API\Contracts\AuthorizationServiceContract;

/**
 * PolicyFilterStage — the declarative `can` route filter.
 *
 * A route opts into policy-backed protection in its module.json / proj.json:
 *
 *   { "method": "PUT", "path": "/api/users/{id}",
 *     "handler": "…",
 *     "filters":  ["auth", "can:users,edit"],
 *     "requires": ["authorization.policy"] }
 *
 * The stage enforces (subject = Identity->userId, object, action) against the
 * Casbin policy. FAIL-CLOSED: a guest, a missing enforcer (the route forgot to
 * require authorization.policy), or a deny all yield an error response —
 * never a pass-through.
 */
final class PolicyFilterStage implements HttpStageContract
{
    public function handle(Request $request, callable $next): Response
    {
        $args   = (array) ($request->attribute('filter_args')['can'] ?? []);
        $object = trim((string) ($args[0] ?? ''));
        $action = trim((string) ($args[1] ?? ''));

        if ($object === '' || $action === '') {
            // A malformed filter declaration is a config bug — fail closed loudly.
            return Response::serverError();
        }

        $identity = $request->identity();
        if ($identity === null || $identity->isGuest()) {
            return Response::unauthorized('Authentication required.');
        }

        $container = $request->container();
        if ($container === null || !$container->has(AuthorizationServiceContract::class)) {
            // Policy module not loaded for this route → the declaration is
            // incomplete (missing "requires": ["authorization.policy"]).
            return Response::json(['error' => [
                'code'    => 'authorization.unavailable',
                'message' => 'This route declares a policy filter but the authorization module is not loaded.',
            ]], 500);
        }

        $authz = $container->make(AuthorizationServiceContract::class);
        if (!$authz instanceof AuthorizationServiceContract
            || !$authz->allows($identity->userId, $object, $action)) {
            return Response::forbidden('You are not allowed to perform this action.');
        }

        return $next($request);
    }
}
