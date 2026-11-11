<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\{Request, Response};
use AlfacodeTeam\PhpServicePlatform\Kernel\Observability\RequestTelemetry;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\{ClockPort, LoggerPort, SystemClock};
use AlfacodeTeam\PhpServicePlatform\Kernel\Routing\Labels\{AuditLabelSink, RouteEmittedEvent};

/**
 * RouteLabelStage — applies a route's declared `audit` / `metric` / `emits`.
 *
 * WHERE IT SITS: the innermost `after.execute` position — directly in front of
 * ExecuteStage, behind every registered after.execute hook. That placement is
 * deliberate on two counts:
 *
 *   - it sees BOTH the matched `route_entry` (ResolveStage attached it) and the
 *     controller's final Response, which is what "record that this happened"
 *     needs;
 *   - a hook that publishes a request-scoped sink (a per-request accumulator
 *     put on the request as an attribute) is OUTSIDE this stage, so the
 *     attribute is already there by the time this one reads it.
 *
 * WHAT IT RECORDS: only what actually happened.
 * ---------------------------------------------
 * Labels are applied when the handler RAN and returned a status below 400. They
 * are NOT applied when the request was answered earlier — an unmatched route, a
 * security denial, an `auth` / `throttle` filter refusal never reach this stage
 * (it sits behind all of them) — nor when the handler returned an error or threw.
 *
 * That is a decision, not an accident of position. A label names the thing the
 * route DOES ("invoice.created"); a 401, a 429 or a 422 means it did not happen,
 * and counting or auditing or announcing it would make the label lie. Refusals
 * are not lost: ObservabilityStage wraps the whole pipeline, sees the matched
 * route template for any request that got that far, and counts every request
 * with its status as `http.server.requests{route,status}`. Denials are measured
 * THERE, once, rather than duplicated here under a name that means something
 * else. A HEAD is skipped too: it is served by the GET route as a courtesy and
 * is not the action the label describes.
 *
 * ABSENT SINKS ARE INERT. The kernel cannot require a plugin, so a label whose
 * sink is not installed does nothing — never an error, never a changed
 * response. It is logged at debug so "why is nothing recorded?" has a place to
 * start. And a sink that THROWS is caught and logged: the work being observed
 * has already succeeded, and observing it must not turn that into a 500.
 *
 * Holds no per-request state (stages are memoized per worker; under OpenSwoole
 * requests interleave).
 */
final class RouteLabelStage implements HttpStageContract
{
    /** Longest value an audit meta entry carries; a path parameter is client-controlled. */
    private const MAX_META_VALUE = 256;

    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request);

        $entry  = $request->attribute('route_entry');
        $labels = is_array($entry) ? ($entry['labels'] ?? null) : null;

        // The overwhelmingly common case — a route with no labels — costs the
        // attribute lookup above and nothing else.
        //
        // The `!is_array($labels)` arm is a PERFORMANCE guard, not a behavioural
        // one: revert-checked, removing it changes no test result, because the
        // loops below iterate nothing when there are no labels. What it saves is
        // a LoggerPort lookup in the container on every unlabelled request. The
        // status and HEAD arms ARE behavioural and are pinned by tests.
        if (!is_array($labels) || $response->status() >= 400 || $request->method() === 'HEAD') {
            return $response;
        }

        $logger = $this->service($request, LoggerPort::class);

        foreach ($labels['metric'] ?? [] as $metric) {
            $this->guarded($logger, 'metric', (string) $metric, fn() => $this->count($request, (string) $metric, $logger));
        }

        if (isset($labels['audit'])) {
            $this->guarded($logger, 'audit', (string) $labels['audit'], fn() => $this->audit($request, (string) $labels['audit'], $response, $logger));
        }

        foreach ($labels['emits'] ?? [] as $event) {
            $this->guarded($logger, 'emits', (string) $event, fn() => $this->emit($request, (string) $event, $response, $logger));
        }

        return $response;
    }

    /**
     * The metric sink is the request-scoped accumulator a metering plugin puts on
     * the request as the `usage` attribute. The contract is one method —
     * `count(string $metric, int $by = 1)` — and it is checked structurally
     * rather than by class, because the kernel names no plugin class. A
     * `\Countable` is excluded: its `count()` takes no name and is not this.
     */
    private function count(Request $request, string $metric, ?LoggerPort $logger): void
    {
        $sink = $request->attribute('usage');

        if (!is_object($sink) || $sink instanceof \Countable || !method_exists($sink, 'count')) {
            $logger?->debug('Route metric label has no sink installed', ['metric' => $metric]);

            return;
        }

        $sink->count($metric, 1);
    }

    private function audit(Request $request, string $action, Response $response, ?LoggerPort $logger): void
    {
        $sink = $this->service($request, AuditLabelSink::class);

        if (!$sink instanceof AuditLabelSink) {
            $logger?->debug('Route audit label has no sink installed', ['action' => $action]);

            return;
        }

        $meta = [
            'method'         => $request->method(),
            'route'          => $this->template($request),
            'status'         => $response->status(),
            'correlation_id' => self::clip((string) $request->attribute('correlation_id', '')),
        ];

        foreach ($this->params($request) as $name => $value) {
            $meta['param.' . $name] = $value;
        }

        $sink->record($action, $meta);
    }

    private function emit(Request $request, string $event, Response $response, ?LoggerPort $logger): void
    {
        $bus = $this->service($request, EventBus::class);

        if (!$bus instanceof EventBus) {
            $logger?->debug('Route emits label has no event bus in this scope', ['event' => $event]);

            return;
        }

        $clock    = $this->service($request, ClockPort::class) ?? new SystemClock();
        $identity = $request->identity();

        $failures = $bus->dispatch(new RouteEmittedEvent(
            eventName:     $event,
            route:         $this->template($request),
            method:        $request->method(),
            status:        $response->status(),
            params:        $this->params($request),
            userId:        $identity?->userId ?? '',
            tenantId:      $identity?->tenantId ?? '',
            correlationId: (string) $request->attribute('correlation_id', ''),
            occurredAt:    $clock->now()->format(\DateTimeInterface::RFC3339),
        ));

        // EventBus isolates and logs listener failures itself, but only when it
        // was built with a logger. Say so here too: a listener that never ran is
        // otherwise indistinguishable from an event nobody subscribed to.
        foreach ($failures as $listener => $e) {
            $logger?->warning('Route emits label: a listener failed', [
                'event' => $event, 'listener' => $listener, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Run one label, containing any failure. The response already exists and the
     * work it describes already happened.
     */
    private function guarded(?LoggerPort $logger, string $label, string $name, \Closure $apply): void
    {
        try {
            $apply();
        } catch (\Throwable $e) {
            $logger?->warning('Route label could not be applied', [
                'label' => $label, 'name' => $name, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T|null null when absent, or bound in a scope this stage cannot read
     */
    private function service(Request $request, string $id): ?object
    {
        $container = $request->container();

        if ($container === null || !$container->has($id)) {
            return null;
        }

        try {
            $service = $container->make($id);
        } catch (\Throwable) {
            return null;   // e.g. an internal binding: treated as not installed
        }

        return is_object($service) ? $service : null;
    }

    private function template(Request $request): string
    {
        $telemetry = $request->attribute('telemetry');

        return $telemetry instanceof RequestTelemetry && $telemetry->route !== ''
            ? $telemetry->route
            : $request->method() . ' ' . $request->path();
    }

    /** @return array<string, string> */
    private function params(Request $request): array
    {
        $params = $request->attribute('route_params', []);
        $out    = [];

        foreach (is_array($params) ? $params : [] as $name => $value) {
            if (is_scalar($value)) {
                $out[(string) $name] = self::clip((string) $value);
            }
        }

        return $out;
    }

    private static function clip(string $value): string
    {
        return strlen($value) > self::MAX_META_VALUE ? substr($value, 0, self::MAX_META_VALUE) : $value;
    }
}
