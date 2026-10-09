# Route Resolution and Project Policy

When multiple sources declare routes (plugins via `module.json` and projects via `proj.json`), the compiler resolves them following a predictable priority: **project routes override plugin routes by default**. A project can also disable specific plugin routes without forking the plugin.

## Compilation Order

Routes are compiled in three phases:

1. **Read all module manifests** — every plugin's `module.json` `routes[]` and `groups[]`
2. **Compile plugin routes** — with the project's disable/allow policy applied
3. **Compile project routes** — `proj.json` `routes[]`/`groups[]` and `Kernel::withRoutes()`

A project route declaring the same `METHOD /path` (and domain, if grouped) **overrides** a plugin route. The plugin route is removed and replaced.

## Project Override

Override a plugin route by redeclaring it with a different handler:

**Plugin (module.json):**

```jsonc
{
  "routes": [
    { "method": "GET", "path": "/register", "handler": "Auth\\RegisterController@show", "name": "auth.register" }
  ]
}
```

**Project (proj.json):**

```jsonc
{
  "routes": [
    { "method": "GET", "path": "/register", "handler": "Shop\\RegisterController@show" }
  ]
}
```

The project route replaces the plugin route. The route INHERITS the plugin's name (`auth.register`) unless the project declares its own.

This is the intended way to customize a plugin page without forking it.

## Route Disable Policy

Disable a plugin route WITHOUT replacing it, using `routePolicy.disable` in `proj.json` or `Kernel::withRoutePolicy()`:

```jsonc
{
  "routePolicy": {
    "disable": [
      "GET /register",              // one route: method + path
      "GET@api.example.com /admin", // one route in a domain group
      "auth.identity"               // a module domain: every route this module solves()
    ]
  }
}
```

Each disable spec is either:

- **`METHOD /path`** — one exact route (e.g., `GET /register`)
- **`METHOD@domain /path`** — one route in a domain group (e.g., `GET@api.example.com /admin`)
- **`module.domain`** — every route a plugin solves (e.g., `auth.identity` disables all auth routes)

A spec that matches **no** plugin route fails at boot with a descriptive message (preventing silent typos).

Programmatically:

```php
$kernel = Kernel::configure()
    ->withRoutePolicy(['GET /register', 'auth.identity'])
    ->build();
```

## Route Allow Policy

Complement to disable: declare ONLY the routes you want to expose. When an allowlist is present, a plugin route must match a spec or it is dropped:

```jsonc
{
  "routePolicy": {
    "only": [
      "GET /dashboard",
      "GET /profile",
      "invoicing.*"              // all routes the invoicing module solves()
    ]
  }
}
```

An empty allowlist means "allow everything" — treating it as "allow nothing" would silently break projects on upgrade. Unmatched specs do NOT fail the boot (unlike disable), so a shared configuration that names routes from an optional plugin is normal.

Programmatically:

```php
$kernel = Kernel::configure()
    ->withRouteAllowPolicy(['GET /dashboard', 'invoicing.*'])
    ->build();
```

Disable and allow compose: allow a module's whole domain, then disable the handful of routes you do not want.

### Disable Then Replace

Disable a plugin route and optionally declare your own:

```jsonc
{
  "routePolicy": {
    "disable": ["GET /login"]
  },
  "routes": [
    { "method": "GET", "path": "/login", "handler": "Custom\\LoginController@show" }
  ]
}
```

Because disable runs BEFORE project routes are compiled, the freed `GET /login` key is available for the project's own declaration without a duplicate-route conflict.

### Programmatic Policy

Use `Kernel::withRoutePolicy()` instead of `proj.json`:

```php
$kernel = Kernel::configure()
    ->withRoutePolicy(['GET /register', 'auth.identity'])
    ->build();
```

## Plugin Route Requirements

A route can declare `requires[]` to opt specific routes into plugins without loading them app-wide:

**Plugin (module.json):**

```jsonc
{
  "routes": [
    { "method": "GET", "path": "/dashboard", "handler": "...", "requires": ["view.rendering"] }
  ]
}
```

**Project (proj.json):**

```jsonc
{
  "routes": [
    { "method": "GET", "path": "/custom", "handler": "...", "requires": ["tenancy.routing"] }
  ]
}
```

When a request matches a route, its `requires[]` domains are seeded into the dependency graph, and only those plugins load (plus their transitive `requires`). Routes without `requires[]` load ZERO extra plugins — they resolve under the synthetic `__project__` scope.

An unknown domain in `requires[]` fails at boot.

## Multiple Plugins, Same Route

Two plugins cannot declare the same route. The compiler fails at boot:

```
Duplicate route [GET /register] declared by [Auth\\Provider] and [SignUp\\Provider].
```

This is not a coincidence — it is a guard. A silent "last-one-wins" would hide plugin conflicts from a deploy until the moment they matter.

The project resolves ties by disabling one and declaring its own.

## Route Name Uniqueness

Route names are a **flat, application-wide namespace**. Two routes cannot claim the same name, even on different domains:

```jsonc
{
  "groups": [
    { "domain": "a.example.com", "routes": [{ "name": "home", ... }] },
    { "domain": "b.example.com", "routes": [{ "name": "home", ... }] }  // ✗ boot failure
  ]
}
```

Use a group `name` prefix to disambiguate:

```jsonc
{
  "groups": [
    { "domain": "a.example.com", "name": "a.", "routes": [{ "name": "home", ... }] },
    { "domain": "b.example.com", "name": "b.", "routes": [{ "name": "home", ... }] }
  ]
}
```

Names: `a.home`, `b.home`

A project override inherits the plugin route's name unless it declares its own, so overridden routes keep their old names accessible.

## Compiled Manifest

After compilation, `ManifestWriter` creates three files:

| File | Purpose | Size |
|------|---------|------|
| `route-manifest.php` | Flat map: `"METHOD /path"` → entry. Public API for tools and RouteCatalog. | Full |
| `route-index.php` | Matcher-ready index: static table, dynamic buckets by first segment, precompiled regex per route. | Full (precompiled) |
| `route-names.php` | Name → `{path, method, domain}`. UrlGenerator reads this to build URLs without loading the full table. | Small |

Both derived files are **optional at runtime**: every consumer falls back to deriving from `route-manifest.php` if the index or names file is missing (e.g., an old deploy). The boot compiler writes all three.

## Matching at Request Time

`RouteMatcher` uses the compiled index. It handles:

1. **Static routes** first (literal paths, exact O(1) lookup)
2. **Dynamic routes** next (parameterized paths, regex scan)
3. **Domain groups** when present (most specific first)
4. **Trailing slash** behavior (strict / ignore / redirect)
5. **HEAD fallback** (HEAD → GET when no HEAD route)

See [Route Basics](/routing/basics) for environment variable control over these behaviors.

## Boot Failures

The compiler fails at boot on:

- **Duplicate route key** — two plugins claim the same `METHOD /path` (or `METHOD@domain /path`)
- **Duplicate route name** — two routes claim the same name (including across domain groups)
- **Duplicate disable spec matching** — never happens; a spec that matches nothing fails
- **Unknown domain in requires[]** — route requires a domain no module solves
- **Unknown domain in a group** — grouped route's host is not registered in `proj.json` `domains[]` (when the list exists)
- **Unknown type in parameter** — `{id:badtype}` is not in the type table
- **Duplicate capture name** — `/{a}/{a}` produces an invalid regex
- **Invalid capture name** — `{2fa}` becomes empty after sanitization
- **Handler missing '@'** — must be exactly one
- **Handler class/method does not exist** — when `ROUTE_VERIFY_HANDLERS=true`

## Caching (BOOT_CACHE)

When `BOOT_CACHE=1`, the compiler checks a stamp and skips recompilation if nothing read changed:

```php
BOOT_CACHE=1 php app/public/index.php
```

The stamp invalidates on:

- Changed `module.json` (any module)
- Changed `proj.json` (any key the builder reads)
- Changed `config/*.php` files
- Added/removed config file
- Changed `bootstrap/app.php` (the builder inputs)
- Missing compiled manifests

This saves ~2ms per request on FPM (86× faster than without cache). Clear the cache by deleting `var/cache/manifests/`.

## Static Content

The route manifest includes compiled metadata for each route:

- `path` — the path template (`/posts/{id}`)
- `method` — HTTP method (`GET`, `POST`, etc.)
- `handler` — class@method
- `regex` — precompiled anchored regex (for dynamic routes)
- `params` — parameter list with types (for URL generation and matching)
- `filters` — filter aliases to apply
- `requires` — module domains to seed into the graph
- `faces` — face restriction (if any)
- `domain` — host group ('' for ungrouped)
- `solves` — module domain or `__project__` for project routes
- `name` — route name (if any)
- `overrides` — the module class that was overridden (if applicable)
- `graph_key` — cachebusting key for the dependency graph

## Next Steps

- [Route Basics](/routing/basics) — route declaration reference
- [Route Disable Policy](/routing/resolution#route-disable-policy) — in this section
- [Route Cookbook](/routing/cookbook) — practical override and policy examples

## Source

- [CompileRouteManifestStage.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Boot/Stages/CompileRouteManifestStage.php) — compilation order and policy application
- [RoutePolicy.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Boot/Routing/RoutePolicy.php) — disable and allow policy logic
- [RouteMatcher.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Pipelines/Http/RouteMatcher.php) — request-time matching algorithm
