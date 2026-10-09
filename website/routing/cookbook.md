# Routing Cookbook

Worked examples of common routing patterns. Every example compiles exactly as shown — copy, adjust the handler, and go.

All examples use `module.json` syntax. The same shape works in `proj.json` for project-layer routes.

## Recipe 1: A Simple CRUD Resource

A group eliminates repetition of the prefix and route name stem:

```jsonc
{
  "groups": [
    {
      "prefix": "/invoices",
      "name": "invoice.",
      "routes": [
        { "method": "GET",    "path": "",          "handler": "Shop\\Http\\InvoiceController@index",   "name": "index" },
        { "method": "POST",   "path": "",          "handler": "Shop\\Http\\InvoiceController@store",   "name": "store" },
        { "method": "GET",    "path": "/{id:num}", "handler": "Shop\\Http\\InvoiceController@show",    "name": "show" },
        { "method": "PUT",    "path": "/{id:num}", "handler": "Shop\\Http\\InvoiceController@update",  "name": "update" },
        { "method": "DELETE", "path": "/{id:num}", "handler": "Shop\\Http\\InvoiceController@destroy", "name": "destroy" }
      ]
    }
  ]
}
```

**Compiled routes:**

```
GET    /invoices              → invoice.index
POST   /invoices              → invoice.store
GET    /invoices/{id:num}     → invoice.show
PUT    /invoices/{id:num}     → invoice.update
DELETE /invoices/{id:num}     → invoice.destroy
```

**Usage in a controller:**

```php
// Generate URLs
route('invoice.index');             // /invoices
route('invoice.show', ['id' => 7]); // /invoices/7

// In a form
<form method="POST" action="{{ route('invoice.store') }}">
```

## Recipe 2: Admin Section Behind Authentication

Nest groups to add filters without repeating parent constraints:

```jsonc
{
  "groups": [
    {
      "prefix": "/admin",
      "filters": ["auth"],
      "name": "admin.",
      "routes": [
        { "method": "GET", "path": "/", "handler": "Shop\\Http\\AdminController@home", "name": "home" }
      ],
      "groups": [
        {
          "prefix": "/users",
          "filters": ["shield"],
          "name": "users.",
          "routes": [
            { "method": "GET",    "path": "",           "handler": "Shop\\Http\\UserAdminController@index",   "name": "index" },
            { "method": "DELETE", "path": "/{id:uuid}", "handler": "Shop\\Http\\UserAdminController@destroy", "name": "destroy" }
          ]
        }
      ]
    }
  ]
}
```

**Compiled routes:**

```
GET    /admin/              [auth]              → admin.home
GET    /admin/users         [auth shield]       → admin.users.index
DELETE /admin/users/{id}    [auth shield]       → admin.users.destroy
```

Filters merge, names concatenate, prefixes concatenate. Each level inherits from its parent.

## Recipe 3: Versioned API with Global Rate Limiting

Use module-wide defaults to avoid repetition:

```jsonc
{
  "name": "api",
  "routePrefix": "/api/v2",
  "routeFilters": ["throttle:60,1"],
  "routes": [
    { "method": "GET",  "path": "/ping",   "handler": "Shop\\Http\\ApiController@ping" },
    { "method": "POST", "path": "/import", "handler": "Shop\\Http\\ApiController@import", "filters": ["auth", "throttle:5,1"] }
  ]
}
```

**Compiled routes:**

```
GET  /api/v2/ping     [throttle:60,1]
POST /api/v2/import   [auth throttle:5,1]
```

The route's `throttle:5,1` **replaces** (not stacks with) the default `throttle:60,1`. Filters de-duplicate by alias.

## Recipe 4: Safe File Download with Traversal Guard

Use the `path` type to safely serve files:

```jsonc
{
  "method": "GET",
  "path": "/download/{file:path}",
  "handler": "Shop\\Http\\FileController@download"
}
```

**Behavior:**

| Request | Result |
|---------|--------|
| `GET /download/reports/q1.pdf` | ✓ routes to `FileController@download` with `$file = 'reports/q1.pdf'` |
| `GET /download/../../etc/passwd` | ✗ 404 (contains `..`) |
| `GET /download/a/..%2Fb` | ✗ 404 (decodes to `a/../b`, rejected) |

The `path` type crosses `/` but refuses `..` anywhere and control characters. Captured values are percent-decoded and re-validated, so traversal escapes fail at the routing layer, never reaching the controller.

::: tip
Use `path` for anything serving files or reaching a filesystem. The `any` type has no traversal guard and is kept only for backward compatibility.
:::

## Recipe 5: Optional Parameters and Enums

Optional parameters take their leading `/` with them:

```jsonc
{
  "method": "GET",
  "path": "/posts/{page:num?}",
  "handler": "Shop\\Http\\PostController@index",
  "name": "posts.index"
},
{
  "method": "GET",
  "path": "/posts/status/{s:enum(draft|published|archived)}",
  "handler": "Shop\\Http\\PostController@byStatus"
}
```

**Behavior:**

| Request | Result |
|---------|--------|
| `GET /posts` | ✓ routes with `$page = ''` |
| `GET /posts/2` | ✓ routes with `$page = '2'` |
| `GET /posts/two` | ✗ 404 (not numeric) |
| `GET /posts/status/draft` | ✓ routes with `$s = 'draft'` |
| `GET /posts/status/deleted` | ✗ 404 (not in enum) |

Enum members are restricted to `[A-Za-z0-9_.-]` and `preg_quote`d — no regex injection from JSON.

## Recipe 6: Multiple Brands on One Project

The same path can exist on different hosts with different handlers:

```jsonc
{
  "domains": ["shop.example.com", "globex.example.com"],
  "groups": [
    {
      "domain": "shop.example.com",
      "name": "shop.",
      "routes": [
        { "method": "GET", "path": "/", "handler": "Shop\\HomeController@index", "name": "home" }
      ]
    },
    {
      "domain": "globex.example.com",
      "name": "globex.",
      "routes": [
        { "method": "GET", "path": "/", "handler": "Globex\\HomeController@index", "name": "home" }
      ]
    },
    {
      "domain": "*.example.com",
      "routes": [
        { "method": "GET", "path": "/", "handler": "Shop\\Http\\TenantHomeController@index" }
      ]
    }
  ],
  "routes": [
    { "method": "GET", "path": "/health", "handler": "Shop\\Http\\HealthController@show" }
  ]
}
```

**Compiled routes:**

```
GET@shop.example.com /    → shop.home
GET@globex.example.com /  → globex.home
GET@*.example.com /       → (wildcard, any tenant)
GET /health               → (global, every domain)
```

**Behavior:**

| Request | Handler |
|---------|---------|
| `GET /` @ `shop.example.com` | `Shop\HomeController@index` |
| `GET /` @ `globex.example.com` | `Globex\HomeController@index` |
| `GET /` @ `tenant1.example.com` | `TenantHomeController@index` (wildcard) |
| `GET /health` @ any domain | `HealthController@show` (global) |

Names are flat across all domains, so use group `name` prefixes: `shop.home` and `globex.home`, not two `home` routes.

## Recipe 7: API Subdomain Serving Every Host

A bare subdomain belongs to no single host — it answers on any domain:

```jsonc
{
  "groups": [
    {
      "subdomain": "api",
      "prefix": "/v1",
      "filters": ["throttle:120,1"],
      "routes": [
        { "method": "GET", "path": "/ping", "handler": "Shop\\Http\\ApiController@ping" }
      ]
    }
  ]
}
```

**Compiled route:** `GET@api /v1/ping [throttle:120,1]`

**Behavior:**

| Request | Result |
|---------|--------|
| `GET /v1/ping` @ `api.example.com` | ✓ `ApiController@ping` |
| `GET /v1/ping` @ `api.example.co.uk` | ✓ `ApiController@ping` |
| `GET /v1/ping` @ `api.brand-new.test` | ✓ `ApiController@ping` |
| `GET /v1/ping` @ `www.example.com` | ✗ 404 |

Bare subdomains are **never** validated against `proj.json` `domains[]` — there is no single host to check.

## Recipe 8: Override a Plugin Route

A project can replace a plugin route by redeclaring it, inheriting the plugin's name unless it declares its own:

```jsonc
{
  "routes": [
    { "method": "GET", "path": "/register", "handler": "Shop\\RegisterController@show" }
  ]
}
```

**What happens:**

- The project route replaces the plugin's `/register` route
- The plugin's name (`auth.register`) is inherited and preserved
- All `route('auth.register')` calls in the plugin's views keep working

## Recipe 9: Disable a Plugin Route

Use `routePolicy.disable` to remove a plugin route without replacing it:

```jsonc
{
  "routePolicy": {
    "disable": [
      "GET /register",   // drop one plugin route
      "oauth.server"     // drop EVERY route a plugin solves()
    ]
  }
}
```

A disable spec that matches nothing fails at boot (preventing silent typos). After disabling, the path key is free for the project to declare its own route.

## Recipe 10: Project Route with Plugin Dependency

Project routes run under the `__project__` scope with an **empty** dependency graph — they load no plugins by default. Use `requires` to opt specific routes into plugins:

```jsonc
{
  "method": "GET",
  "path": "/dashboard",
  "handler": "Shop\\DashboardController@index",
  "requires": ["view.rendering"]
}
```

When this route matches, the `view.rendering` plugin loads (plus its transitive `requires`). The controller can then use the view plugin's published contracts.

This is the per-route alternative to making a plugin essential:

| Need | Mechanism |
|------|-----------|
| Stateless, every request | `withPorts([...])` — app-lifetime port |
| Some routes need a plugin | `"requires"` on the route |
| Every request needs a plugin | `"essentials"` in `proj.json` |

## Recipe 11: Restrict a Route to One Face

A route can restrict itself to specific domain types:

```jsonc
{
  "method": "GET",
  "path": "/ops",
  "handler": "Shop\\Http\\OpsController@index",
  "faces": ["admin"]
}
```

The route is invisible on any other face. A mismatch returns 404, not 403 — a route the caller cannot reach should not advertise itself exists elsewhere.

Requires the entry point to set `route_face` on the request. Without it, the restriction is inert (useful for CLI/testing without a domain resolver).

## Recipe 12: Signed Links for Email Verification

Use signed URLs to send tamper-proof links in emails:

```php
// In your controller
$url = signed_route('email.verify', ['code' => $code], expiresIn: 86400);
// Send in email: https://example.com/verify?code=abc&expires=1234567890&signature=…

// Later, verify it
if (url()->hasValidSignature($request->path() . '?' . $request->server('QUERY_STRING'))) {
    // safe to process
}
```

The signature covers the path and query. Editing any parameter invalidates it. An optional `expires` timestamp is included in the signature, so the deadline cannot be extended by editing the URL.

## Recipe 13: Nested Sub-Resources

Build hierarchical URLs with nested parameters:

```jsonc
{
  "groups": [
    {
      "prefix": "/organizations/{org_id}",
      "name": "org.",
      "groups": [
        {
          "prefix": "/teams",
          "name": "teams.",
          "routes": [
            { "method": "GET", "path": "", "handler": "...", "name": "index" },
            { "method": "GET", "path": "/{team_id:num}", "handler": "...", "name": "show" }
          ]
        }
      ]
    }
  ]
}
```

**Compiled routes:**

```
GET /organizations/{org_id}/teams              → org.teams.index
GET /organizations/{org_id}/teams/{team_id:num} → org.teams.show
```

**Usage:**

```php
route('org.teams.show', ['org_id' => 5, 'team_id' => 12]);
// /organizations/5/teams/12
```

## Common Mistakes

::: danger
**Don't hardcode paths in links:**

```php
✗ <a href="/posts/{{ post.id }}">View</a>
✓ <a href="{{ route('posts.show', ['id' => post.id]) }}">View</a>
```

Hardcoded links break when a route moves. Named routes survive project overrides.
:::

::: danger
**Don't use the `any` type for file serving:**

```php
✗ { "path": "/files/{name:any}" }
✓ { "path": "/files/{name:path}" }
```

`any` has no traversal guard. `/files/..%2F..%2Fetc%2Fpasswd` matches with `any` but is rejected by `path`.
:::

::: warning
**Signed URLs expire:**

```php
$url = signed_route('reset', ['token' => $t], expiresIn: 3600);  // 1 hour
// If sent in an email and opened after 1 hour, hasValidSignature() returns false
```

Plan your expiry times accordingly. Email verification links often need 24–48 hours.
:::

## Next Steps

- [Route Basics](/routing/basics) — complete route syntax
- [Route Parameters](/routing/parameters) — type system and validation
- [Route Groups](/routing/groups) — grouping and inheritance
- [Route Domains](/routing/domains) — multi-host routing

## Source

- [Existing recipes](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/docs/guides/30_ROUTING_COOKBOOK.md) — internal reference with compiled output
