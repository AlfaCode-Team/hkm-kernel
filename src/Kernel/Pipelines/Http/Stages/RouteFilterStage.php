<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Stages\CompileRouteManifestStage;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\CoreContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\{Request, Response};
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\FilterRegistry;

/**
 * RouteFilterStage — runs the filters DECLARED on the matched route.
 *
 * Sits at the `after.load` position (route already resolved by ResolveStage, the
 * ModuleContainer already built by LoadStage), so route filters can resolve ports
 * from the request-scoped container just like any other after.load hook.
 *
 * The route's `filters[]` (compiled into the route manifest from module.json /
 * proj.json) are resolved to stage instances and run as a NESTED onion that
 * terminates in the downstream `$next`. Because each filter is a normal
 * HttpStageContract, the usual before/after semantics hold:
 *
 *   - code before `$next($request)`  → runs on the way IN  (can short-circuit)
 *   - code after  `$next($request)`  → runs on the way OUT (decorate Response)
 *
 * Filters run left-to-right in declaration order. A `name:arg1,arg2` spec parses
 * the args and exposes them on the request as the `filter_args` attribute
 * (keyed by alias) so a stage can read its own configuration per route:
 *
 *   $args = $request->attribute('filter_args')['throttle'] ?? [];
 *
 * The spec parse is CONSTANT per route, so the boot compiler stores the result as
 * `filter_specs` and this stage reads it; the inline parse below is only the
 * fallback for a manifest compiled by an older kernel.
 */
final class RouteFilterStage implements HttpStageContract
{
    public function __construct(
        private readonly FilterRegistry $registry,
        private readonly CoreContainer  $core,
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        $entry = $request->attribute('route_entry');
        $specs = $this->specs($entry);

        if ($specs === []) {
            return $next($request);
        }

        /** @var list<HttpStageContract> $stages */
        $stages  = [];
        $aliases = [];
        $args    = [];

        foreach ($specs as $spec) {
            $alias    = $spec['alias'];
            $stages[] = $this->registry->resolve($alias, $this->core);
            $aliases[] = $alias;
            if ($spec['args'] !== []) {
                $args[$alias] = $spec['args'];
            }
        }

        // Let a stage tell it was invoked declaratively (vs. as a global hook)
        // and read its own per-route configuration.
        $request = $request->withAttribute('active_filters', $aliases);
        if ($args !== []) {
            $request = $request->withAttribute('filter_args', $args);
        }

        // Build the nested onion: last declared filter wraps $next first, so the
        // first declared filter is the outermost (runs first on the way in).
        $run = $next;
        foreach (array_reverse($stages) as $stage) {
            $inner = $run;
            $run   = static fn(Request $req): Response => $stage->handle($req, $inner);
        }

        return $run($request);
    }

    /**
     * @param mixed $entry
     * @return list<array{alias: string, args: list<string>}>
     */
    private function specs(mixed $entry): array
    {
        if (!is_array($entry)) {
            return [];
        }

        $specs = $entry['filter_specs'] ?? null;
        if (is_array($specs)) {
            return $specs;
        }

        $filters = $entry['filters'] ?? [];
        if (!is_array($filters) || $filters === []) {
            return [];
        }

        $parsed = [];
        foreach ($filters as $spec) {
            $parsed[] = CompileRouteManifestStage::parseFilterSpec((string) $spec);
        }

        return $parsed;
    }
}
