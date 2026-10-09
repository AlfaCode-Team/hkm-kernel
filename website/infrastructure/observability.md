# Observability — logging, tracing, and metrics

The kernel provides three observability ports — `LoggerPort`, `TracerPort`, and `MetricsPort` — plus a correlation ID propagated across HTTP, CLI, and worker surfaces. Together they give operators the data to correlate a request across services, find slow paths, and detect anomalies.

## Correlation IDs

Every request, CLI invocation, and job carries an `X-Correlation-ID` header/attribute. The kernel generates one if missing; propagating it through your entire stack makes a request traceable end-to-end:

```
Request A (user-facing)
├─ call API (X-Correlation-ID: req-abc-123)
│  └─ log to database        (correlation_id: req-abc-123)
└─ dispatch job              (correlation_id: req-abc-123)
   └─ job logs               (correlation_id: req-abc-123)
```

If a request times out calling a downstream service, that service's logs for the same correlation ID show what happened on its end.

### Where it's set

- **HTTP**: `CorrelationIdStage` reads or generates, stores in request attribute.
- **CLI**: `CliCorrelationIdStage` generates one per invocation.
- **Worker**: `WorkerLoop` copies the correlation ID from the job payload.

Use the correlation ID in every log message your code writes:

```php
$logger->info('Invoice created', [
    'correlation_id' => $request->attribute('correlation_id'),
    'invoice_id'     => $invoiceId,
]);
```

## LoggerPort

Application logging. Use it to record routine events (user login, slow query, state transition) that aren't errors. For errors, the error pipeline handles classification and notification.

```php
$logger->info('Invoice issued', ['invoice_id' => $invoiceId]);
$logger->warning('Slow query', ['duration_ms' => 2500, 'query' => $sql]);
```

Eight levels (RFC 5424):

- `emergency()` — system unusable
- `alert()` — immediate action required
- `critical()` — component unavailable
- `error()` — runtime error
- `warning()` — not an error, but worth attention
- `notice()` — normal but significant
- `info()` — interesting events
- `debug()` — detailed debug information

Adapters must not throw — a logger that fails takes down the operation it was meant to observe.

### Context arrays

Pass structured data as the second argument. Keys named in the message as `{placeholder}` are interpolated:

```php
$logger->info('User {user_id} logged in', [
    'user_id'      => $userId,
    'tenant_id'    => $tenantId,
    'ip_address'   => $request->ip(),
]);
```

## LogLevel enum

LogLevel constants as an enum for type safety:

```php
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LogLevel;

// Inside your LoggerPort adapter
public function log(string $level, string|\Stringable $message, array $context = []): void
{
    if (!LogLevel::parse($level)->passes($this->minimumLevel)) {
        return;   // below the configured threshold
    }
    // ... write the record
}
```

Methods:

- `severity()` — numeric score (0 = Emergency, 7 = Debug)
- `passes(LogLevel $minimum)` — true if at least as severe as minimum
- `parse(string $level)` — parse a string, falling back to Debug

## TracerPort

Distributed tracing. Shaped after OpenTelemetry; no vendor dependency.

```php
$span = $tracer->start('db.query', ['query' => $sql, 'rows' => count($rows)]);
// ... work ...
$span->end();

// Or let tracer manage the lifecycle:
$result = $tracer->measure('db.query', fn($span) =>
    $db->query($sql, $params)
);
```

### Request telemetry

`ObservabilityStage` records the kernel pipeline's own execution:

```
HTTP request
├─ CorrelationIdStage        (span: http.correlation)
├─ SecurityStage             (span: security.verify)
├─ ResolveStage              (span: http.resolve)
├─ LoadStage                 (span: http.load)
├─ ExecuteStage              (span: http.execute)
└─ ErrorStage (if needed)    (span: http.error)
```

Each span carries attributes (route, method, status, errors). Tracing is opt-in — without a `TracerPort` bound, `NullTracer` runs the work and records nothing, at zero cost.

### W3C traceparent propagation

Outbound requests must carry the W3C `traceparent` header so downstream services join the same trace:

```php
// In a gateway making an outbound call
$traceparent = $tracer->traceparent();
$response = $httpClient->get($url, [
    'headers' => ['traceparent' => $traceparent],
]);

// Downstream service adopts it
$tracer->continueFrom($request->header('traceparent'));
```

## Span

A timed unit of work inside a trace.

```php
public function setAttribute(string $key, string|int|float|bool $value): void;
public function recordException(\Throwable $e): void;
public function setError(string $description = ''): void;
public function end(): void;
public function traceparent(): string;
```

- `setAttribute()` — attach high-cardinality dimensions (user id, full path, SQL statement). Stored per span, not per series.
- `recordException()` — mark the span as failed and attach the throwable. Does not end the span; the caller may still add a fallback result.
- `setError()` — mark the outcome explicitly (when no exception is appropriate).
- `end()` — stop the clock and hand to exporter. Idempotent.
- `traceparent()` — the span's W3C id, or '' when not recording.

## MetricsPort

Counters, gauges, and distributions for operational observability.

```php
$metrics->counter('http.requests', labels: ['route' => '/api/invoices', 'status' => '200']);
$metrics->gauge('queue.depth', 42, labels: ['queue' => 'default']);
$metrics->histogram('response_size_bytes', 5120);
$metrics->timing('db.query.duration_ms', 125.5);
```

- `counter()` — increment a monotonic count. Use for requests, errors, cache hits.
- `gauge()` — current value. Use for queue depth, memory, pool size.
- `histogram()` — observation into a distribution. The backend keeps quantiles (p50, p95, p99).
- `timing()` — duration in **milliseconds**. Separate from histogram because most backends have dedicated timing metadata.

### Label cardinality

Labels are low-cardinality dimensions: status code, route template, service name. A label value per UNIQUE input (route path instead of template, user id) mints unbounded series and collapses observability. Adapters may truncate or drop high-cardinality labels.

```php
// OK — bounded cardinality
$metrics->counter('http.requests', labels: ['route' => '/users/{id}', 'status' => '200']);

// WRONG — unbounded cardinality
$metrics->counter('http.requests', labels: ['route' => '/users/1234', 'status' => '200']);
$metrics->counter('http.requests', labels: ['route' => '/users/5678', 'status' => '200']);
// Mints a new series for each user, crashing the metrics store
```

Adapters must not throw — a metrics adapter that fails takes down the operation it was meant to observe.

## NullTracer, NullSpan, NullMetrics

No-op implementations shipped in the kernel. Used when no adapter is bound.

```php
$tracer = NullTracer::instance();  // shared singleton
$span = $tracer->start('work');    // returns NullSpan
$tracer->measure('work', $callback);  // runs callback, returns result, records nothing
```

All methods are no-op, but `measure()` still runs the callback and propagates exceptions. Writing kernel code unconditionally against these ports is safe — when observability is off, you pay two method calls per operation.

## ObservabilityStage

Runs at `after.load` in the HTTP pipeline, instrumenting the request's execution. Records:

- Span for each stage (security, resolve, load, execute)
- Stage duration (attribute on span)
- Exceptions (if any)
- Request attributes (method, path, status)

Requires a `TracerPort` bound; with none, `NullTracer` is used and nothing is recorded.

## Wiring observability

Bind adapters in the kernel bootstrap:

```php
Kernel::configure()
    ->withPorts([
        LoggerPort::class  => new SomethingLoggerAdapter(),
        TracerPort::class  => new OpenTelemetryAdapter(),
        MetricsPort::class => new PrometheusAdapter(),
    ])
```

For each port you don't bind, the kernel uses a no-op default:

- `LoggerPort` — defaults to a no-op (not bound by default)
- `TracerPort` — defaults to `NullTracer`
- `MetricsPort` — defaults to `NullMetrics`

## Testing observability

Create in-memory fakes:

```php
<?php declare(strict_types=1);
namespace Tests\Fixtures;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;

final class RecordingLogger implements LoggerPort
{
    private array $records = [];

    public function info(string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => 'info', 'message' => (string) $message, 'context' => $context];
    }

    public function getRecords(): array
    {
        return $this->records;
    }

    // ... other levels
}

// In a test:
$logger = new RecordingLogger();
$service = new InvoiceService(..., logger: $logger);
$service->issue($invoiceId);

$this->assertContains(['level' => 'info', 'message' => 'Invoice issued'], $logger->getRecords());
```

## Common mistakes

::: warning

**Logging with coroutine-unsafe globals.** Use `env()` and `config()` helpers, never `getenv()` / `putenv()` or $_SERVER directly.

**Forgetting to propagate traceparent.** Outbound calls must carry the `traceparent` header, or spans are orphaned from the original trace.

**High-cardinality metric labels.** A label value per unique input (user id, full path) explodes series count and crashes the backend. Use route templates and status codes, not paths and user ids.

**Throwing in adapters.** LoggerPort, TracerPort, MetricsPort adapters must not throw. An observation that breaks the thing it observes is worse than losing the observation.

:::

## Source

- [LoggerPort](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Ports/LoggerPort.php)
- [TracerPort](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Ports/TracerPort.php)
- [MetricsPort](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Ports/MetricsPort.php)
- [LogLevel](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Ports/LogLevel.php)
- [CorrelationIdStage](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Pipelines/Http/Stages/CorrelationIdStage.php)
- [NullTracer, NullSpan, NullMetrics](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Ports/)
