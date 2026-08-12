<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\{Request, Response};
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Contracts\RequestAware;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;

final class ExecuteStage implements HttpStageContract
{
    public function handle(Request $request, callable $next): Response
    {
        $entry = $request->attribute('route_entry');
        $container = $request->container();
        $scope = $entry['solves'] ?? '';

        // The handler split is constant per route, so the boot compiler bakes it
        // into the entry. explode() is the fallback for a manifest compiled by an
        // older kernel.
        if (isset($entry['class'], $entry['action'])) {
            $controllerClass = $entry['class'];
            $method          = $entry['action'];
        } else {
            [$controllerClass, $method] = explode('@', $entry['handler'], 2);
        }

        $controller = $container->makeInScope($controllerClass, $scope);

        $params = array_values($request->attribute('route_params', []));

        // A RequestAware controller holds the Request itself (set here with the
        // same copy the action would receive — the one carrying the request-scoped
        // container), so its actions take ONLY route params, not $request.
        // Other controllers keep the conventional ($request, ...$params) signature.
        if ($controller instanceof RequestAware) {
            $controller->setRequest($request);
            $response = $controller->$method(...$params);
        } else {
            $response = $controller->$method($request, ...$params);
        }

        $response = $response->withHeader('X-Correlation-ID', $request->attribute('correlation_id', ''));

        // A HEAD request is served by the GET route (see RouteMatcher) and must
        // return the GET headers with NO body. Rebuilding an empty response also
        // discards any stream callback or file path, so a HEAD probe on a large
        // download never reads the file.
        return $request->method() === 'HEAD' ? self::withoutBody($response) : $response;
    }

    /**
     * Same status and headers, empty body. Content-Length is dropped rather than
     * faked: RFC 9110 permits omitting it on a HEAD response, and computing it
     * would mean generating the very body we are trying not to produce.
     */
    private static function withoutBody(Response $response): Response
    {
        $headers = $response->headers();
        unset($headers['content-length'], $headers['Content-Length']);

        return Response::empty($response->status())->withHeaders($headers);
    }
}
