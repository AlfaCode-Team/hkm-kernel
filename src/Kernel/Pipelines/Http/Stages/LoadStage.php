<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\{Request, Response};
use AlfacodeTeam\PhpServicePlatform\Kernel\Loading\{DependencyGraph, DependencyGraphCalculator, OnDemandLoader};
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;

final class LoadStage implements HttpStageContract
{
    /**
     * @param list<string> $essentialDomains solves domains of the essential
     *        modules — seeded into EVERY request graph so an essential module's
     *        own requires[] load with it (each module still registers once).
     */
    /**
     * Resolved graphs memoized per worker, keyed by service + route extras.
     * Manifests are deploy-time artifacts, so a graph never changes within a
     * worker's lifetime; the key space is bounded by the route manifest. The
     * cache is write-once and idempotent — a coroutine race recomputes the
     * same immutable value, so last-write-wins is harmless under OpenSwoole.
     *
     * @var array<string, DependencyGraph>
     */
    private array $graphs = [];

    public function __construct(
        private readonly DependencyGraphCalculator $calculator,
        private readonly OnDemandLoader $loader,
        private readonly array $essentialDomains = [],
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        $service = $request->attribute('target_service');

        // A route may declare its OWN requires[] (compiled into the route entry)
        // to pull specific plugins into THIS request's graph. This is how a
        // project route — whose '__project__' scope carries no requires — opts
        // into, say, view.rendering for one page without loading it app-wide.
        $entry    = $request->attribute('route_entry');
        $extra    = is_array($entry) && is_array($entry['requires'] ?? null) ? $entry['requires'] : [];

        // Essential modules are registered on every request anyway (see
        // OnDemandLoader) — resolving their domains THROUGH the graph as well
        // brings their transitive requires[] with them, so an essential like
        // Tenancy gets its Database dependency bound instead of failing with an
        // unbound contract on routes that never pulled it in. The calculator
        // visits each domain once, so nothing registers twice. Graphs are
        // memoized per worker (see $graphs).
        $key = $service . '|' . implode(',', $extra);
        $graph = $this->graphs[$key]
            ??= $this->calculator->resolve($service, [...$extra, ...$this->essentialDomains]);
        $container = $this->loader->load($graph, $request);

        return $next($request->withContainer($container));
    }
}
