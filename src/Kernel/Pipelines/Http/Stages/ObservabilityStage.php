<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\{Request, Response};
use AlfacodeTeam\PhpServicePlatform\Kernel\Observability\RequestTelemetry;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\{MetricsPort, Span, TracerPort};

/**
 * ObservabilityStage — the RED metrics and the root span for every request.
 *
 * WHERE IT SITS, AND WHY
 * ----------------------
 * Immediately inside CorrelationIdStage and OUTSIDE everything else — security,
 * routing, module loading, execution. That position is the whole point:
 *
 *   - a request denied by SecurityStage still produces a metric. Instrumentation
 *     that only counts requests which reached a controller cannot show you an
 *     attack, which is the one time you most want the graph;
 *   - a 404 still produces a metric, labelled 'unmatched';
 *   - the timing includes routing and module loading, which is where a
 *     regression in this framework will actually show up.
 *
 * It runs inside CorrelationIdStage rather than outside so the span can carry the
 * correlation id — the two identifiers are only useful together.
 *
 * COST WHEN NOTHING IS BOUND
 * --------------------------
 * NullMetrics/NullTracer are shared no-op singletons, so an application that
 * binds neither pays one hrtime() pair, one small object, and four calls into
 * empty method bodies. That is cheap enough that this stage is unconditional —
 * making it opt-in would mean the numbers are missing from exactly the
 * production deployments that later need them.
 *
 * IT MUST NEVER CHANGE THE RESPONSE. The stage returns whatever the inner
 * stages produced, unmodified, on both the normal and the exceptional path.
 */
final class ObservabilityStage implements HttpStageContract
{
    public function __construct(
        private readonly MetricsPort $metrics,
        private readonly TracerPort  $tracer,
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        $telemetry = new RequestTelemetry();
        $method    = $request->method();

        // Join the caller's trace when one was propagated. Malformed or absent
        // values are the adapter's problem to ignore, not ours to validate.
        $this->tracer->continueFrom($request->header('traceparent', '') ?? '');

        $span = $this->tracer->start('http.request', [
            'http.request.method' => $method,
            // The raw path, on the SPAN only. High cardinality is fine here —
            // attributes are stored per span, not per time series — and it is
            // the field that makes one slow trace actionable.
            'url.path'            => $request->path(),
            'correlation.id'      => (string) $request->attribute('correlation_id', ''),
        ]);

        $started = hrtime(true);

        try {
            $response = $next($request->withAttribute('telemetry', $telemetry));
        } catch (\Throwable $e) {
            // ErrorStage wraps this one, so in the assembled pipeline nothing
            // should reach here. It is handled anyway because a hook stage can
            // be inserted between them, and an observability stage that loses
            // its own metric on the failure path is worse than useless.
            $telemetry->error = $e;
            $span->recordException($e);
            $this->finish($span, $telemetry, $method, 500, $started);

            throw $e;
        }

        $this->finish($span, $telemetry, $method, $response->status(), $started);

        return $response;
    }

    /**
     * Emit both signals and close the span. One place, so the normal and
     * exceptional paths cannot drift apart.
     */
    private function finish(
        Span $span,
        RequestTelemetry $telemetry,
        string $method,
        int $status,
        int|float $startedAt,
    ): void {
        $ms = (hrtime(true) - $startedAt) / 1_000_000;

        // Labels are deliberately three and low-cardinality: method (a closed
        // set), route TEMPLATE (bounded by the route table), status (bounded).
        // Their product is the series count this stage is responsible for.
        $labels = [
            'method' => $method,
            'route'  => $telemetry->routeLabel(),
            'status' => (string) $status,
        ];

        $this->metrics->counter('http.server.requests', 1, $labels);
        $this->metrics->timing('http.server.duration', $ms, $labels);

        $span->setAttributes([
            'http.response.status_code' => $status,
            'http.route'                => $telemetry->routeLabel(),
            'hkm.module'                => $telemetry->module,
        ]);

        // A 5xx is a failed span even when nothing was thrown — an error the
        // application handled cleanly is still an error to whoever called us.
        if ($status >= 500) {
            $span->setError('HTTP ' . $status);
        }

        $span->end();
    }
}
