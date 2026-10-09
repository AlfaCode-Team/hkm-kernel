# Routing Basics

Declare your application's routes in `module.json` for plugins or `proj.json` for projects, or programmatically via `Kernel::withRoutes()`. Routes map HTTP requests to handlers and are compiled at boot into an optimized matcher that runs once per request.

## Route Declaration

Routes live in the `routes` array of a module's `module.json` or a project's `proj.json`. Each entry specifies the HTTP method, path, and handler:

```jsonc
{
  "routes": [
    { "method": "GET",  "path": "/",           "handler": "Shop\\Http\\HomeController@index" },
    { "method": "GET",  "path": "/posts/{id}", "handler": "Shop\\Http\\PostController@show" },
    { "method": "POST", "path": "/posts",      "handler": "Shop\\Http\\PostController@create" }
  ]
}
```

### Route Entry Keys

| Key | Required | Type | Description |
|-----|----------|------|-------------|
| `method` | yes | string | HTTP method: `GET`, `POST`, `PUT`, `PATCH`, `DELETE`, `HEAD`, `OPTIONS` |
| `path` | yes | string | Absolute path starting with `/`. May include typed parameters: `/posts/{id:num}` |
| `handler` | yes | string | `ClassName@methodName` — the controller and method to run. Plugins must use full path: `Plugins\PostPlugin\Http\Controllers\PostController@show` |
| `name` | no | string | Route name for URL generation: `route('posts.show', ['id' => 7])`. Must be unique application-wide. |
| `filters` | no | string or list | Pipeline filters: `"auth"` or `["auth", "throttle:60,1"]`. De-duplicated by alias; a route's filter overrides a group's. |
| `requires` | no | string or list | Module domains this route pulls in: `"view.rendering"` or `["tenancy.routing", "mail.sending"]`. Seeded into the dependency graph for this request only. |
| `faces` | no | string or list | Restrict to specific domain types: `["admin"]` hides the route on other faces. The kernel reads `route_face` from the request attribute. |
| `domain` | no | string or list | Host(s) this route answers on. See [Route domains](/routing/domains). |
| `subdomain` | no | string or list | Subdomain(s) — bare labels like `"api"` or `["admin", "staff"]`. |

## Module-Wide Defaults

Avoid repeating the same prefix or filters on every route by declaring them once at the module level:

```jsonc
{
  "name": "posts",
  "routePrefix":  "/api/posts",
  "routeFilters": ["auth"],
  "routeName":    "post.",
  "routeDomain":  "api.example.com",
  "routeFaces":   ["api"],
  
  "routes": [
    { "method": "GET",    "path": "", "handler": "Shop\\Http\\PostController@index", "name": "index" },
    { "method": "GET",    "path": "/{id}", "handler": "Shop\\Http\\PostController@show", "name": "show" },
    { "method": "POST",   "path": "", "handler": "Shop\\Http\\PostController@create" }
  ]
}
```

Compiles to (the domain is part of each key):
- `GET@api.example.com /api/posts` → `post.index`
- `GET@api.example.com /api/posts/{id}` → `post.show`
- `POST@api.example.com /api/posts`

`api.example.com` must be one of the project's `proj.json` `domains[]`, or the boot fails. See [Domains](/routing/domains).

Every module-wide key:

| Key | Applies to each route as |
|---|---|
| `routePrefix` | prepended to `path` |
| `routeName` | prepended to `name` (unnamed routes stay unnamed) |
| `routeFilters` | merged in front of the route's `filters`, de-duplicated by alias (the route's own spec for an alias wins) |
| `routeRequires` | unioned with the route's `requires` |
| `routeDomain` | default `domain`, string or list |
| `routeSubdomain` | default `subdomain`, string or list |
| `routeFaces` | default `faces` |

The same inheritance applies to [groups](/routing/groups), which nest inside these defaults.

## Handler Grammar

Handlers must be exactly one `ClassName@methodName` pair with no spaces.

**Plugin handler** — full namespace:
```json
{ "handler": "Plugins\\PostPlugin\\Http\\Controllers\\PostController@show" }
```

**Project handler** — full namespace:
```json
{ "handler": "Shop\\Http\\Controllers\\PostController@show" }
```

The method must be public and non-static. The kernel passes the request and route parameters to it (see [Controllers](/http/controllers)).

## Programmatic Routes

Use `Kernel::withRoutes()` for routes that need logic to build:

```php
$kernel = Kernel::configure()
    ->withRoutes([
        ['method' => 'GET', 'path' => '/health', 'handler' => 'HealthController@check'],
    ])
    ->withRouteGroups([...])
    ->build();
```

Routes passed to `withRoutes()` and any declared in `proj.json` `routes[]` are concatenated — neither silently overwrites the other.

## HTTP Methods

Standard HTTP methods are supported: `GET`, `POST`, `PUT`, `PATCH`, `DELETE`, `HEAD`, `OPTIONS`, `TRACE`, `CONNECT`. The method is case-insensitive in `module.json` and normalized to uppercase.

### HEAD Fallback

`HEAD` requests automatically fall back to the `GET` route if no `HEAD` route is declared (a W3C recommendation). This enables link checkers and uptime monitors to probe your site. The kernel strips the response body before returning.

Enable or disable with the `ROUTE_HEAD_FALLBACK` env var (default: `true`).

## Trailing Slash Behavior

Configure how trailing slashes are handled via `ROUTE_TRAILING_SLASH` (default: `strict`):

| Mode | Behavior |
|------|----------|
| `strict` | `/posts` and `/posts/` are different routes. No fallback. |
| `ignore` | Try the alternate form if the first does not match. No redirect. |
| `redirect` | Redirect to the canonical form (with or without trailing slash). |

## Status-Code Responses

| Condition | Status | Notes |
|-----------|--------|-------|
| Route matched, executed | 200 (or configured) | Normal response |
| Route not found | 404 | No match for method+path |
| Method not allowed | 405 | Same path, different method; requires `ROUTE_METHOD_NOT_ALLOWED=true` |
| Method not allowed (default) | 404 | By default, a 404 confirms nothing; 405 can leak path existence |

## Boot-Time Failures

The compiler rejects malformed routes and halts the boot:

- **Path does not start with `/`** — routes never match without an absolute path.
- **Duplicate route key** — two plugins claim the same `METHOD /path` (or `METHOD@domain /path`).
- **Unknown parameter type** — `{id:badtype}` is not in the type table.
- **Duplicate capture name** — `/{a}/{a}` produces an invalid regex.
- **Invalid capture name** — `{2fa}` becomes empty after stripping non-word chars; use `{twoFactor}`.
- **Unknown `requires` domain** — no module solves that domain.
- **Unknown `domain` or `subdomain`** — when the project declares a `domains` list, a grouped route's host must be in it or a registered wildcard/suffix parent.
- **Named route spans multiple domains** — route names are a flat, application-wide namespace; one name cannot mean different URLs per host.
- **Unregistered filter alias** — when `ROUTE_STRICT_FILTERS=true` (default), an unknown filter fails at boot instead of at request time.
- **Handler missing '@'** — must be exactly one: `Controller@method`.
- **Handler class or method does not exist** — when `ROUTE_VERIFY_HANDLERS=true` (development/CI only).

## Environment Variables

| Variable | Default | Effect |
|----------|---------|--------|
| `ROUTE_HEAD_FALLBACK` | `true` | `HEAD` falls back to `GET` when no `HEAD` route is declared. |
| `ROUTE_METHOD_NOT_ALLOWED` | `false` | Return `405` + `Allow` header instead of `404` on a method mismatch. Off by default because 405 confirms a path exists. |
| `ROUTE_TRAILING_SLASH` | `strict` | `strict`, `ignore`, or `redirect` — how to handle trailing-slash mismatches. |
| `ROUTE_STRICT_FILTERS` | `true` | Fail at boot on an unregistered filter alias (vs. at request time). |
| `ROUTE_VERIFY_HANDLERS` | `false` | Dev/CI: verify every handler class + public method exists at boot. Off in production — forces the autoloader to load every controller. |

## Route Resolution Order

When multiple domain groups exist, the router tries them in specificity order (most specific first), then falls back to the shared (ungrouped) table:

1. Exact domain (e.g., `acme.test`)
2. Wildcard domains (e.g., `*.acme.test`, most specific suffix first)
3. Bare subdomain label (e.g., `organizer`)
4. Shared (every ungrouped route)

Within each domain, static routes (literal paths) are checked first, then dynamic routes (with parameters). This ensures `/users/me` always beats `/users/{id}`.

## Route Filters

Filters are stages that run in the HTTP pipeline for matching routes. They are declared in the route's `filters` array and run at the `after.load` position (after the route is resolved and modules are loaded).

Filters de-duplicate by alias, so a route's `throttle:5,1` overrides a group's `throttle:60,1` rather than running twice. See [Route filters](/http/filters) for the full mechanics and how to register custom filters.

## Next Steps

- [Route Parameters](/routing/parameters) — type grammar and validation
- [Route Groups](/routing/groups) — nesting and inheritance
- [Route Domains](/routing/domains) — multi-host and multi-brand setup
- [Named Routes](/routing/named-routes) — URL generation and signed links
- [Route Resolution](/routing/resolution) — project override/disable policy and dependency injection

## Source

- [CompileRouteManifestStage.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Boot/Stages/CompileRouteManifestStage.php) — route compilation at boot
- [ResolveStage.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Pipelines/Http/Stages/ResolveStage.php) — route matching at request time
- [RouteMatcher.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Pipelines/Http/RouteMatcher.php) — matching algorithm and policy
- [RouteParameter.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Routing/RouteParameter.php) — parameter type definitions
