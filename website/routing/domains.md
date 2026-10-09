# Route Domains

Routes can be grouped by host, letting one application serve multiple brands or separate API and web interfaces on different subdomains. The domain is part of the route's unique key, so the same path can exist on multiple hosts with different handlers.

## Domain Grouping

A group may declare one or more domains:

```jsonc
{
  "groups": [
    {
      "domain": "shop.example.com",
      "routes": [
        { "method": "GET", "path": "/", "handler": "Shop\\HomeController@index" }
      ]
    },
    {
      "domain": "admin.example.com",
      "routes": [
        { "method": "GET", "path": "/", "handler": "Admin\\DashboardController@index" }
      ]
    }
  ]
}
```

Routes compile to:
- `GET@shop.example.com /` → Shop home
- `GET@admin.example.com /` → Admin dashboard

The same path `/` can exist on both hosts because the domain is part of the route key.

## Domain and Subdomain

A domain can be a full host, a wildcard, or a bare subdomain label:

| Declared | Meaning | Matches |
|----------|---------|---------|
| `"domain": "shop.example.com"` | exact host | `shop.example.com` only |
| `"domain": "*.example.com"` | wildcard | `api.example.com`, `admin.example.com`, etc. |
| `"subdomain": "api"` | bare label | `api.example.com`, `api.example.co.uk`, etc. (global) |
| `"domain": ["a.com", "b.com"]` | list | each host as a separate domain group |

### Bare Subdomains (Global)

A bare subdomain without a parent domain spans every host your application serves:

```jsonc
{
  "subdomain": "api",
  "routes": [
    { "method": "GET", "path": "/users", "handler": "..." }
  ]
}
```

Matches:
- `api.example.com/users`
- `api.shop.co.uk/users`
- Any host with the `api` subdomain

### Combining Domain and Subdomain

A domain is the parent; subdomains attach to it:

```jsonc
{
  "domain": "example.com",
  "subdomain": ["admin", "staff"],
  "routes": [...]
}
```

Compiles to routes on:
- `example.com`
- `admin.example.com`
- `staff.example.com`

### Multiple Hosts

Both `domain` and `subdomain` accept lists:

```jsonc
{
  "domain": ["example.com", "example.co.uk"],
  "subdomain": ["admin", "staff"],
  "routes": [...]
}
```

Compiles to routes on:
- `example.com`, `admin.example.com`, `staff.example.com`
- `example.co.uk`, `admin.example.co.uk`, `staff.example.co.uk`

(6 host × path combinations)

## Wildcards

A wildcard domain matches any subdomain of that parent:

```jsonc
{
  "domain": "*.example.com",
  "routes": [...]
}
```

Matches: `api.example.com`, `tenant1.example.com`, `anything.example.com`, etc.

A wildcard may NOT take a subdomain (`*.example.com` already covers every subdomain of `example.com`):

```jsonc
{
  "domain": "*.example.com",
  "subdomain": "admin",  // <- boot failure: already covered by wildcard
  "routes": [...]
}
```

Wildcards are exact-match at the group level — a request for `example.com` (without a subdomain) does not match `*.example.com`.

## Project Domain Validation

When a project declares its registered hosts in `proj.json` `domains[]`, the compiler validates that grouped routes only claim hosts the project actually serves:

```jsonc
// proj.json
{
  "domains": ["example.com", "api.example.com", "*.tenant.example.com"],
  "groups": [
    {
      "domain": "example.com",          // ✓ registered
      "routes": [...]
    },
    {
      "domain": "staging.example.com",  // ✗ boot failure: not registered
      "routes": [...]
    }
  ]
}
```

If a project declares NO `domains` list, validation is skipped (any domain is allowed).

Wildcards are valid when a registered host falls under them:

```jsonc
{
  "domains": ["example.com", "api.example.com"],
  "groups": [
    {
      "domain": "*.example.com",  // ✓ valid: example.com falls under it
      "routes": [...]
    }
  ]
}
```

## Route Keys with Domains

A domain-grouped route's key includes the domain:

```
GET@example.com /
GET@api.example.com /
GET@*.tenant.example.com /
GET /              (ungrouped — global route)
```

The format is `METHOD@domain /path`, where the `@` separates method and domain. This allows the same path to exist on multiple hosts without colliding.

## Request Matching with Domains

When a request arrives, `ResolveStage` expands the request's host into candidate domain keys, most specific first:

```
request host: organizer.acme.test

candidates (in order):
1. organizer.acme.test    (exact match)
2. *.acme.test            (wildcard — parent)
3. *.local                         (wildcard — parent of parent)
4. organizer                       (bare subdomain label)
5. ''                              (ungrouped/global)
```

The matcher tries each candidate in order. The first one that has the route wins.

### Preferred Domain (route_host attribute)

The entry point should set `route_host` on the request from the domain resolver (the validated host), not rely on the raw `Host` header:

```php
$request = $request
    ->withAttribute('route_host', $domain->host)  // DomainContext->host
    ->withAttribute('route_face', $domain->type->value);
```

`ResolveStage` uses this attribute when present, falling back to `Request::host()` only if the attribute is absent. This prevents an unauthenticated client from choosing which domain group serves them by setting the `Host` header.

## Route Names Across Domains

Route names are a **flat, application-wide namespace** even with domain groups. Two domains cannot both claim the name `home`:

```jsonc
{
  "groups": [
    { "domain": "globex.example.com", "routes": [{ "name": "home", ... }] },
    { "domain": "acme.example.com", "routes": [{ "name": "home", ... }] }  // ✗ boot failure: duplicate name
  ]
}
```

Use a group `name` prefix to distinguish them:

```jsonc
{
  "groups": [
    { "domain": "globex.example.com", "name": "globex.", "routes": [{ "name": "home", ... }] },
    { "domain": "acme.example.com", "name": "acme.", "routes": [{ "name": "home", ... }] }
  ]
}
```

Routes:
- `globex.home`
- `acme.home`

This design allows `UrlGenerator` to hold no request state, so CLI commands and queue workers can generate links from any context without knowing the current host.

## Absolute URLs with Domains

When generating absolute URLs for a route grouped under a concrete host, `UrlGenerator` uses that host:

```php
$url = url()->route('globex.home', absolute: true);
// https://globex.example.com/

$url = url()->route('acme.home', absolute: true);
// https://acme.example.com/
```

A wildcard or bare subdomain has no single host to build from, so it uses the configured `APP_URL` instead (or defaults to `https://`).

## Face Restriction (route_face Attribute)

Routes can restrict themselves to specific domain types via the `faces` key:

```jsonc
{
  "routes": [
    { "method": "GET", "path": "/admin", "handler": "...", "faces": ["admin"] }
  ]
}
```

The kernel checks the `route_face` request attribute; a mismatch results in a 404 (not 403, because a route the caller may not see should not advertise its existence). Set by the entry point:

```php
$request = $request->withAttribute('route_face', $domain->type->value);
```

When no `route_face` is set (CLI, tests without a domain resolver), face restrictions are inert.

## Practical Examples

### Multi-Brand Setup

```jsonc
{
  "domains": ["globex.test", "acme.test"],
  "groups": [
    {
      "domain": "globex.test",
      "name": "globex.",
      "routes": [
        { "method": "GET", "path": "/", "handler": "Globex\\HomeController@index", "name": "home" }
      ]
    },
    {
      "domain": "acme.test",
      "name": "acme.",
      "routes": [
        { "method": "GET", "path": "/", "handler": "Acme\\HomeController@index", "name": "home" }
      ]
    }
  ]
}
```

URL generation:
- `route('globex.home')` → `/` on `globex.test`
- `route('acme.home')` → `/` on `acme.test`

### Tenant Wildcard

```jsonc
{
  "domains": ["example.com", "*.example.com"],
  "groups": [
    {
      "domain": "example.com",
      "routes": [
        { "method": "GET", "path": "/", "handler": "Public\\HomeController@index" }
      ]
    },
    {
      "domain": "*.example.com",
      "routes": [
        { "method": "GET", "path": "/", "handler": "Tenant\\HomeController@index" }
      ]
    }
  ]
}
```

Requests:
- `example.com/` → Public home
- `tenant1.example.com/` → Tenant home (router expands `tenant1.example.com` to include `*.example.com`)

### API and Web Separation

```jsonc
{
  "groups": [
    {
      "subdomain": "api",
      "prefix": "/v1",
      "name": "api.",
      "filters": ["auth"],
      "routes": [
        { "method": "GET", "path": "/users", "handler": "Api\\UserController@index", "name": "users" }
      ]
    },
    {
      "prefix": "/users",
      "name": "web.",
      "routes": [
        { "method": "GET", "path": "", "handler": "Web\\UserController@index", "name": "index" }
      ]
    }
  ]
}
```

Routes:
- `GET api.example.com/v1/users` → `api.users` (JSON API)
- `GET example.com/users` → `web.index` (HTML)

## Next Steps

- [Route Groups](/routing/groups) — grouping with prefixes and filters
- [Route Basics](/routing/basics) — route entry reference
- [Route Cookbook](/routing/cookbook) — worked routing examples

## Source

- [DomainComposer.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Boot/Routing/DomainComposer.php) — domain composition and validation
- [RouteIndex.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Routing/RouteIndex.php) — hostCandidates() and domain-aware route indexing
- [ResolveStage.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Pipelines/Http/Stages/ResolveStage.php) — domain matching at request time
