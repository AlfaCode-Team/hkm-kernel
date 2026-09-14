<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Observability;

/**
 * RequestTelemetry — the one thing that must travel UP an inward-only pipeline.
 *
 * WHY THIS IS MUTABLE IN A PIPELINE BUILT ON IMMUTABILITY
 * -------------------------------------------------------
 * Stages compose as an onion: each hands a NEW Request inward and gets a
 * Response back. That is deliberate and must not change — it is what makes a
 * stage safe to reason about and safe under OpenSwoole.
 *
 * But the observability stage has to run OUTSIDE the router (it must time the
 * routing, and it must still emit a metric when routing 404s), while the only
 * label worth having — the route TEMPLATE — is not known until the router has
 * run, several layers further in. An immutable attribute cannot carry a value
 * outward: ResolveStage's `withAttribute()` produces a request the outer stage
 * never sees.
 *
 * So exactly one mutable object is threaded through as an attribute, and it is
 * a WRITE-ONLY SINK: it holds no request state, influences no behaviour, and is
 * read only after the inner stages have returned. Nothing downstream branches on
 * it. If it were dropped entirely the request would be byte-identical.
 *
 * It is created per request by {@see ObservabilityStage} and referenced by
 * nothing afterwards, so it cannot leak between requests.
 */
final class RequestTelemetry
{
    /**
     * The matched route TEMPLATE — '/users/{id}', never '/users/8134'.
     *
     * Metric labels become one time series per distinct value, so the raw path
     * would mint an unbounded series set. Empty until the router matches; stays
     * empty on a 404, which is why the metric labels it 'unmatched' rather than
     * dropping the observation.
     */
    public string $route = '';

    /** The `solves` domain that owns the matched route — '' for a project route. */
    public string $module = '';

    /** Set by ObservabilityStage when a Throwable escaped to the error pipeline. */
    public ?\Throwable $error = null;

    /** Record the matched route. Called once, by ResolveStage. */
    public function matched(string $template, string $module = ''): void
    {
        $this->route  = $template;
        $this->module = $module;
    }

    /** The route label to report, with the low-cardinality fallback for a miss. */
    public function routeLabel(): string
    {
        return $this->route !== '' ? $this->route : 'unmatched';
    }
}
