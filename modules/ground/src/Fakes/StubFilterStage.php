<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Fakes;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;

/**
 * A route filter that does nothing but let the request past — and says so.
 *
 * WHY THIS HAS TO EXIST. Route filter aliases are registered by the plugin that
 * PROVIDES them (`auth`, `throttle`, `hmac`, `shield` all come from
 * SecurityFilters), and `ROUTE_STRICT_FILTERS` defaults to true, so the pipeline
 * refuses to build when a compiled route names an alias nothing registered.
 * Almost every real plugin declares at least one — Pageflow's own
 * `/pageflow/csrf` carries `throttle:60,1` — so without this a ground could only
 * ever boot a plugin whose routes have no filters at all, which is not the
 * interesting set.
 *
 * The stand-in is registered ONLY for aliases nothing else claimed, and the
 * ground reports them through `stubbedFilters()`. That distinction is the whole
 * point: a stubbed filter DID NOT RUN, so a test asserting "this route is
 * protected" while `auth` is stubbed is asserting nothing. Load the real plugin
 * (`->with(SecurityFilters\Provider::class)`) when the filter is the subject, or
 * turn stubbing off entirely with `PluginGround::realFilters()`.
 */
final class StubFilterStage implements HttpStageContract
{
    /** @var list<array{path: string, filters: list<string>}> */
    public array $seen = [];

    public function handle(Request $request, callable $next): Response
    {
        /** @var list<string> $active */
        $active = (array) $request->attribute('active_filters', []);

        // One instance serves every stubbed alias, so the same route arrives
        // once per stubbed filter. Recording it once keeps the log readable.
        $entry = ['path' => $request->path(), 'filters' => array_values($active)];
        if (!\in_array($entry, $this->seen, true)) {
            $this->seen[] = $entry;
        }

        return $next($request);
    }

    /** Did a request to this path pass through a stubbed filter? */
    public function ran(string $path): bool
    {
        foreach ($this->seen as $entry) {
            if ($entry['path'] === $path) {
                return true;
            }
        }

        return false;
    }
}
