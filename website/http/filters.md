# Route Filters

Route filters let you declare cross-cutting behaviour on a per-route basis in `module.json` or `proj.json`, rather than coupling every request to a self-gating stage. A route opts into filters by name, and they run as a nested onion before and after the controller.

## Overview

A **filter** is a route-level opt-in to a `HttpStageContract`. Unlike global hooks, which run on every request, filters only run on routes that declare them:

```jsonc
{
  "method": "POST",
  "path": "/api/invoices",
  "handler": "Shop\\Http\\InvoiceController@create",
  "filters": ["auth", "throttle:60,1"]  // only this route pays for these
}
```

Filters run left-to-right in declaration order. They form a nested onion that terminates in the controller, so each filter can run code before and after the action.

## Declaring Filters

### In module.json

```jsonc
{
  "routes": [
    {
      "method": "GET",
      "path": "/posts/{id:num}",
      "handler": "Shop\\Http\\PostController@show",
      "filters": ["public"]  // single filter
    },
    {
      "method": "DELETE",
      "path": "/posts/{id:num}",
      "handler": "Shop\\Http\\PostController@destroy",
      "filters": ["auth", "throttle:60,1"]  // multiple filters, left-to-right
    }
  ]
}
```

### With arguments

Filters can take configuration via `:arg1,arg2` syntax:

```jsonc
"filters": [
  "throttle:120,1",    // max 120 requests per 1 minute
  "signed",            // verify signed URL
  "hmac:webhook"       // HMAC verification for webhook scope
]
```

Arguments are parsed at boot and attached to each route entry, so parsing cost is zero at request time.

### Route groups

Use groups to avoid repeating filters:

```jsonc
{
  "prefix": "/admin",
  "filters": ["auth", "shield"],  // inherited by all routes in group
  "routes": [
    { "method": "GET", "path": "/users", "handler": "Shop\\Http\\AdminController@users" },
    { "method": "GET", "path": "/settings", "handler": "Shop\\Http\\AdminController@settings" }
  ]
}
```

A route can override an inherited filter:

```jsonc
{
  "prefix": "/api",
  "filters": ["throttle:100,1"],
  "routes": [
    {
      "method": "POST",
      "path": "/expensive",
      "handler": "Shop\\Http\\ExpensiveController@process",
      "filters": ["throttle:10,1"]  // replaces parent's, doesn't stack
    }
  ]
}
```

## Publishing Filters

Plugins publish filters by registering them in `Provider::boot()`:

```php
<?php
declare(strict_types=1);

namespace SecurityFiltersPlugin;

use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;

class Provider implements ModuleContract
{
    public function boot(HttpPipeline $http, ...): void
    {
        // Publish filter aliases
        $http->filter('auth',     RequireAuthStage::class);
        $http->filter('throttle', RateLimitStage::class);
        $http->filter('shield',   IpFilterStage::class);
        $http->filter('signed',   VerifySignedUrlStage::class);
        $http->filter('hmac',     RequireHmacStage::class);
    }

    // other ModuleContract methods...
}
```

The alias is what routes declare; the stage is what runs.

## Filter Registry

`FilterRegistry` maps aliases to stage classes. Routes opt into filters by alias, and the registry resolves each alias to a stage instance at request time:

| Mechanism | When | Cost |
|---|---|---|
| Global hook | Every request | One per-request invocation |
| Route filter | Only declared routes | One per-request invocation (memoized) |

Stages are **memoized per worker**: a filter is instantiated once and reused for every request that declares it, saving construction overhead. This is safe because `HttpStageContract` implementations hold no per-request state.

## Writing a Filter

A filter is a normal `HttpStageContract`:

```php
<?php
declare(strict_types=1);

namespace YourPlugin\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\{Request, Response};
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;

final class RequireAuthStage implements HttpStageContract
{
    public function handle(Request $request, callable $next): Response
    {
        $identity = $request->identity();
        
        if ($identity === null || $identity->isGuest()) {
            return Response::unauthorized('You must log in.');
        }
        
        return $next($request);
    }
}
```

### Filters with arguments

A filter can read its route-specific arguments:

```php
final class RateLimitStage implements HttpStageContract
{
    public function handle(Request $request, callable $next): Response
    {
        // Read arguments if the filter is declared on this route
        $args = $request->attribute('filter_args')['throttle'] ?? [];
        
        if ($args === []) {
            // Filter not declared on this route
            return $next($request);
        }
        
        $maxRequests = (int) ($args[0] ?? 60);
        $windowMinutes = (int) ($args[1] ?? 1);
        
        $clientId = $request->ip() ?? 'unknown';
        $key = "throttle:{$clientId}";
        
        // Pseudo-code: check cache, increment counter
        $current = $cache->increment($key);
        if ($current === 1) {
            $cache->set($key, 1, $windowMinutes * 60);
        }
        
        if ($current > $maxRequests) {
            return Response::tooManyRequests(retryAfter: $windowMinutes * 60);
        }
        
        return $next($request);
    }
}
```

### Filters that resolve from the container

If your filter needs ports or services:

```php
final class CustomAuthStage implements HttpStageContract
{
    // Constructor can inject ports from CoreContainer
    public function __construct(
        private readonly DatabasePort $db,
    ) {}
    
    public function handle(Request $request, callable $next): Response
    {
        $token = $request->bearerToken();
        
        if ($token === null) {
            return Response::unauthorized();
        }
        
        // Use the injected port
        $session = $this->db->queryOne(
            'SELECT * FROM sessions WHERE token = ?',
            [$token]
        );
        
        if ($session === null) {
            return Response::unauthorized('Token invalid');
        }
        
        return $next($request);
    }
}
```

The kernel instantiates the filter via `CoreContainer`, which autowires constructor dependencies.

## Filter Execution Order

Filters run left-to-right as a nested onion:

```
Request → [Filter 1 before] → [Filter 2 before] → Controller → [Filter 2 after] → [Filter 1 after] → Response
```

```jsonc
"filters": ["auth", "throttle:60,1"]
```

1. `auth` runs **before** `throttle`
2. `throttle` runs **before** the controller
3. Controller runs
4. `throttle` runs **after** the controller (can decorate response)
5. `auth` runs **after** the controller (can decorate response)

This means:

- An earlier filter can short-circuit (return early) and skip later filters and the controller
- A later filter can see earlier filters' decisions
- Each filter can decorate the response before handing it upstream

```php
final class AuthStage implements HttpStageContract
{
    public function handle(Request $request, callable $next): Response
    {
        if ($request->identity()?->isGuest()) {
            // Short-circuit — throttle and controller never run
            return Response::unauthorized();
        }
        
        $response = $next($request);
        
        // Decorate the response on the way back
        return $response->withHeader('X-Authenticated', 'true');
    }
}
```

## Route-Level Configuration

The `active_filters` and `filter_args` attributes let a stage detect whether it's running declaratively (on a route) vs. as a global hook:

```php
$activeFilters = $request->attribute('active_filters') ?? [];  // ['auth', 'throttle']
$filterArgs = $request->attribute('filter_args') ?? [];        // { throttle: ['60', '1'] }

if (in_array('throttle', $activeFilters)) {
    // This route declared throttle — read its config
    $args = $filterArgs['throttle'] ?? [];
}
```

## Kernel-Provided Filters

The kernel ships no filters. All standard filters (auth, throttle, HMAC, shield, signed) are provided by the `SecurityFilters` plugin. Consult that plugin's documentation for:

- `auth` — require authenticated user
- `throttle:max,minutes` — rate limiting
- `hmac` — HMAC signature verification (machine-to-machine)
- `shield` — IP filtering / geofencing
- `signed` — verify signed URL (for email links)

## ROUTE_STRICT_FILTERS

By default, an unknown filter alias (one no plugin published) fails at pipeline build with a clear error:

```
Route [POST /api/invoices] declares filter [wrong_name], which no Provider::boot() registered.
Registered aliases: auth, throttle, shield, signed, hmac.
```

This catches typos immediately instead of silently doing nothing.

To fall back to the old behaviour (unknown filter throws when that route is requested, not at build):

```bash
ROUTE_STRICT_FILTERS=false php-app
```

::: info Prefer strict filters

Build-time errors are better than request-time errors. A typo in a route filter should fail the application startup, not silently pass the first thousand requests.

:::

## Common Patterns

### Conditional filtering

A filter can check whether it's needed:

```php
final class SignedUrlStage implements HttpStageContract
{
    public function handle(Request $request, callable $next): Response
    {
        // Only if this route declares the filter
        if (!in_array('signed', $request->attribute('active_filters', []))) {
            return $next($request);
        }
        
        if (!$request->url()->hasValidSignature()) {
            return Response::forbidden('Link has expired');
        }
        
        return $next($request);
    }
}
```

### Stacking multiple authentication methods

```jsonc
{
  "method": "POST",
  "path": "/webhook",
  "handler": "Shop\\Http\\WebhookController@handle",
  "filters": ["hmac:webhook", "throttle:1000,1"]
}
```

Both run; either can deny the request.

### Permission-based filtering

```php
final class RequirePermissionStage implements HttpStageContract
{
    public function handle(Request $request, callable $next): Response
    {
        $permission = $request->attribute('filter_args')['permission'][0] ?? null;
        
        if ($permission && !$request->identity()?->hasPermission($permission)) {
            return Response::forbidden("Need: $permission");
        }
        
        return $next($request);
    }
}

// Usage: "filters": ["permission:invoice.create"]
```

## Differences: Global Hook vs Route Filter

| Aspect | Global Hook | Route Filter |
|---|---|---|
| Registration | `$http->hook(slot, Stage::class, priority)` | `$http->filter(alias, Stage::class)` |
| Runs on | Every request | Only routes that declare it |
| Configuration | Via env vars, route context is generic | Via route-specific arguments |
| Use case | Always-on infrastructure (CORS, security headers) | Opt-in per-route (auth, rate limits) |
| Cost for unused routes | Always paid | Zero |

::: danger Never use both for the same concern

```php
// ✗ WRONG
$http->hook('after.load', RateLimitStage::class);  // global
// AND routes also declare
"filters": ["throttle:60,1"]  // filter with same logic

// The stage runs twice per request on that route — double-counting, double-delay
```

Use one or the other:

- **Global hook**: always-on infrastructure (CORS headers, tracing)
- **Route filter**: per-route opt-in (auth, rate limiting)

:::

## Common Mistakes

::: danger Don't register the same alias twice

```php
// ✗ WRONG — throws at boot
$http->filter('auth', AuthStage::class);
$http->filter('auth', OtherAuthStage::class);  // conflict!

// Only one plugin should provide each alias
```

:::

::: danger Don't hardcode route checks in a global hook

```php
// ✗ WRONG — tight coupling, doesn't scale
public function handle(Request $request, callable $next): Response
{
    $path = $request->path();
    if (str_starts_with($path, '/admin')) {
        // check admin permission
    }
}

// ✓ Correct
"filters": ["auth", "shield"]  // declare on the routes that need it
```

:::

## Source

- [RouteFilterStage.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Pipelines/Http/Stages/RouteFilterStage.php)
- [FilterRegistry.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Pipelines/Http/FilterRegistry.php)
- [HttpPipeline.php (filter registration)](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Pipelines/Http/HttpPipeline.php)
