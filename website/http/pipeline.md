# HTTP Pipeline

The `HttpPipeline` assembles and executes the complete request lifecycle through a series of stages. It coordinates routing, module loading, security, and error handling in a deterministic order.

## Request Lifecycle

Every HTTP request passes through these stages in order:

1. **CorrelationIdStage** — generate/propagate X-Correlation-ID (outermost, so error responses carry it)
2. **ErrorStage** — catch all Throwables, route to ErrorPipeline
3. **ObservabilityStage** — RED metrics + root span (no-op unless a port is bound)
4. **SecurityStage** — run SecurityGateway, attach Identity
   - ↳ `after.security` hooks (module-registered stages)
5. **ResolveStage** — match route via RouteMatcher, look up service
6. **LoadStage** — build dependency graph, instantiate ModuleContainer
   - ↳ `after.load` hooks (module-registered stages)
7. **RouteFilterStage** — run declared filters (auth, throttle, etc.)
   - ↳ `after.execute` hooks (module-registered; each wraps the controller call and sees its response)
8. **ExecuteStage** — instantiate controller, invoke action (terminal)

Each stage is an `HttpStageContract` — a callable that receives the request and a `$next` closure.

## Stage Order Guarantees

**Correlation ID is first, errors are second — this is load-bearing.** If an exception is raised anywhere, it propagates back OUT through CorrelationIdStage, which stamps the X-Correlation-ID header onto the error response. This ensures every response — success or failure — carries the correlation ID that logs can trace.

**ObservabilityStage sits inside correlation but outside everything else.** It observes the correlation ID and every request, including those denied by security or unmatched paths, so the metrics are complete.

**SecurityStage runs before routing**, so a denied request never pays for route matching or module loading.

**RouteFilterStage runs after LoadStage**, so declarative filters can resolve ports from the request-scoped container (same as any business logic).

## Stages in Detail

### CorrelationIdStage

Generates a unique request ID (or propagates one from the `X-Correlation-ID` header):

```php
$request->attribute('correlation_id'); // 'YYYYMMDD-xxxxxxxx' format
```

### SecurityStage

Runs the `SecurityGateway` to check all configured security layers:

```php
$request->withIdentity($identity); // attached if allowed
```

Deny short-circuits immediately (no modules load).

### ResolveStage

Matches the request path against `route-manifest.php` and looks up the target service:

```php
$request->attribute('route_entry');  // { handler, solves, filters, ... }
$request->attribute('route_params'); // { id => '123', ... } from path
$request->attribute('route_host');   // validated host from DomainContext
$request->attribute('route_face');   // admin / api / project / public
```

Sets a 404 if no route matches (or 405 if the method is wrong and `ROUTE_METHOD_NOT_ALLOWED=true`).

Handles `ROUTE_TRAILING_SLASH` policy (strict / ignore / redirect).

### LoadStage

Builds the dependency graph for the matched route's service and instantiates a `ModuleContainer`:

```php
$request->withContainer($container); // request-scoped, discarded after response
```

The container holds all module bindings, ports, and services for this request only.

### RouteFilterStage

Runs the filters declared on the matched route in a nested onion:

```php
$request->attribute('active_filters');  // ['auth', 'throttle']
$request->attribute('filter_args');     // { throttle: ['60', '1'], ... }
```

Filters run left-to-right in declaration order and may short-circuit (return a response early).

### ExecuteStage

Instantiates the controller, invokes the action method with route params, and returns the response:

```php
$controller->$method($request, ...$routeParams);
// or (if RequestAware)
$controller->setRequest($request);
$controller->$method(...$routeParams);
```

Attaches the correlation ID to the response.

### ErrorStage

Wraps all others and catches any `Throwable`. Routes it to the ErrorPipeline for classification, logging, and error response generation.

## HttpPipeline API

### Using HttpPipeline

```php
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;

// Build from the kernel
$pipeline = $kernel->http();  // lazy-materialized on first call

// Handle a request
$response = $pipeline->handle($request);
```

### Registering hooks

Module providers register stages to run at fixed slots:

```php
final class SomePlugin implements ModuleContract
{
    public function boot(HttpPipeline $http, ...): void
    {
        // Run after security (e.g., locale resolution)
        $http->hook('after.security', LocaleResolverStage::class, priority: 40);
        
        // Run after load (e.g., custom authorization beyond auth layer)
        $http->hook('after.load', PermissionCheckStage::class, priority: 50);
        
        // Decorate every response: act on what $next returns
        $http->hook('after.execute', SecurityHeadersStage::class, priority: 90);
    }
}
```

### Registering filters

Plugins also publish route-filter aliases:

```php
public function boot(HttpPipeline $http, ...): void
{
    $http->filter('auth',     RequireAuthStage::class);
    $http->filter('throttle', RateLimitStage::class);
    $http->filter('hmac',     RequireHmacStage::class);
}
```

## Hook Slots and Priorities

| Slot | Position | Typical use |
|---|---|---|
| `after.security` | Right after the SecurityGateway | locale/timezone resolution, extra authorization |
| `after.load` | After modules are loaded | per-request setup that needs the container |
| `after.execute` | Directly around `ExecuteStage`, after the route filters | post-processing the controller's response: headers, cache directives, timing |

`hook()` throws `InvalidArgumentException` for any other slot name. Priority is an `int`, default `50`, lower runs first, and stages within a slot are sorted by it. The bands below are a **convention** for keeping plugins ordered predictably; the kernel does not enforce them:

- 1-9: system (kernel infrastructure)
- 10-19: security (auth, rate limiting)
- 40-59: feature (business logic, localization)
- 80-99: observability (tracing, logging)

::: tip How an `after.execute` hook works
`ExecuteStage` is terminal: it returns the controller's response and calls no `$next`. So `after.execute` hooks run in the position just **in front of** it, and each one sees the controller's response on the way back out:

```php
public function handle(Request $request, callable $next): Response
{
    $response = $next($request);   // the controller runs here

    return $response->withHeader('X-Frame-Options', 'DENY');
}
```

(Kernels before this fix appended these hooks behind `ExecuteStage`, where they were never reached. On such a kernel, hook at `after.load` instead.)
:::

## Writing a Custom Stage

A stage implements `HttpStageContract`:

```php
<?php
declare(strict_types=1);

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\{Request, Response};
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;

final class LocaleResolverStage implements HttpStageContract
{
    public function handle(Request $request, callable $next): Response
    {
        // BEFORE the next stage
        $locale = $request->negotiate()->language(['en', 'fr', 'ar']);
        $request = $request->withAttribute('locale', $locale);
        
        // Proceed down the pipeline
        $response = $next($request);
        
        // AFTER the controller runs
        // (decorating the response if needed)
        return $response->withHeader('Content-Language', $locale);
    }
}
```

### Hook stage with container access

If your stage needs to resolve ports or services:

```php
final class PermissionCheckStage implements HttpStageContract
{
    public function handle(Request $request, callable $next): Response
    {
        $container = $request->container();  // request-scoped
        $identity = $request->identity();
        
        if ($identity->isGuest()) {
            return Response::unauthorized();
        }
        
        // e.g., check against a permission service
        if (!$identity->hasPermission('admin')) {
            return Response::forbidden();
        }
        
        return $next($request);
    }
}
```

### Filter stage with arguments

A filter stage reads its route-specific arguments:

```php
final class RateLimitStage implements HttpStageContract
{
    public function handle(Request $request, callable $next): Response
    {
        $args = $request->attribute('filter_args')['throttle'] ?? [];
        
        if ($args === []) {
            return $next($request);  // filter not declared on this route
        }
        
        $maxRequests = (int) ($args[0] ?? 60);
        $minutes = (int) ($args[1] ?? 1);
        
        $clientId = $request->ip() ?? 'unknown';
        
        // Implement throttling logic...
        
        return $next($request);
    }
}
```

## Environment Flags

These flags control pipeline behavior via env vars:

| Var | Default | Effect |
|---|---|---|
| `ROUTE_HEAD_FALLBACK` | `true` | HEAD requests served by GET route with body stripped |
| `ROUTE_METHOD_NOT_ALLOWED` | `false` | return 405 + Allow instead of 404 on method mismatch |
| `ROUTE_TRAILING_SLASH` | `strict` | `strict` / `ignore` / `redirect` |
| `ROUTE_STRICT_FILTERS` | `true` | unknown filter aliases fail at pipeline build (vs. first request) |

## Manifest Compilation

The pipeline loads compiled manifests on first request:

- `var/cache/manifests/service-manifest.php` — service locations (solves/requires/routes)
- `var/cache/manifests/route-manifest.php` — flat route table (flat key → entry)
- `var/cache/manifests/route-index.php` — matcher-ready index (static/dynamic bucketing)

If manifests are missing, the boot compiler regenerates them automatically.

## Common Patterns

### Adding a middleware-like stage

```php
final class CorsStage implements HttpStageContract
{
    public function handle(Request $request, callable $next): Response
    {
        // Check preflight
        if ($request->method() === 'OPTIONS') {
            return Response::empty(204)
                ->withHeader('Access-Control-Allow-Origin', '*')
                ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
        }
        
        // Proceed and decorate response
        $response = $next($request);
        
        return $response->withHeader('Access-Control-Allow-Origin', '*');
    }
}

// In the plugin's boot()
$http->hook('after.security', CorsStage::class, priority: 15);
```

### Per-route configuration via filter arguments

```json
{
  "method": "POST",
  "path": "/api/invoices",
  "handler": "Shop\\Http\\InvoiceController@create",
  "filters": ["auth", "throttle:120,1"]
}
```

The stage reads the arguments:

```php
$args = $request->attribute('filter_args')['throttle'] ?? [];
$max = (int) ($args[0] ?? 60);
$window = (int) ($args[1] ?? 1);
```

### Stage composition

Stages are just functions — compose them:

```php
final class AuthorizedStage implements HttpStageContract
{
    public function __construct(private readonly string $permission) {}
    
    public function handle(Request $request, callable $next): Response
    {
        if (!$request->identity()?->hasPermission($this->permission)) {
            return Response::forbidden();
        }
        
        return $next($request);
    }
}
```

## Common Mistakes

::: danger Never throw from a security layer

```php
// ✗ WRONG — SecurityLayer must return a verdict
throw new \Exception('Not authorized');

// ✓ Correct
return SecurityVerdict::deny(403, 'Not authorized');
```

Security layers run before modules load, so an exception would leave the application in a partially-initialized state.

:::

::: danger Don't mutate stages

```php
// ✗ WRONG — stages are shared across requests
public function handle(Request $request, callable $next): Response
{
    $this->requestCount++;  // leaked across requests!
    // ...
}

// ✓ Correct — carry state on the request
$request = $request->withAttribute('request_count', $count + 1);
```

Stages are instantiated once per worker and reused for every request.

:::

::: danger Don't call $next twice

```php
// ✗ WRONG
$response1 = $next($request);
$response2 = $next($request);

// $next only works once per request
```

Calling `$next` twice means processing the remainder of the pipeline twice, which is almost never intentional.

:::

## Source

- [HttpPipeline.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Pipelines/Http/HttpPipeline.php)
- [Contracts/HttpStageContract.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Pipelines/Http/Contracts/HttpStageContract.php)
- [Stages/](https://github.com/AlfaCode-Team/hkm-kernel/tree/main/src/Kernel/Pipelines/Http/Stages)
