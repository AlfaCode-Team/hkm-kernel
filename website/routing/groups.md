# Route Groups

Groups nest routes and eliminate repetition of common prefixes, filters, and other properties. Everything declared in a group is expanded at boot into flat routes — grouping costs nothing at request time.

## Basic Grouping

Without groups, you repeat the same prefix and filters:

```jsonc
{
  "routes": [
    { "method": "GET",  "path": "/api/posts",      "handler": "Shop\\Http\\PostController@index", "filters": ["auth"] },
    { "method": "POST", "path": "/api/posts",      "handler": "Shop\\Http\\PostController@create", "filters": ["auth"] },
    { "method": "GET",  "path": "/api/posts/{id}", "handler": "Shop\\Http\\PostController@show", "filters": ["auth"] }
  ]
}
```

With groups, you say it once:

```jsonc
{
  "groups": [
    {
      "prefix": "/api",
      "filters": ["auth"],
      "routes": [
        { "method": "GET",  "path": "/posts",      "handler": "Shop\\Http\\PostController@index" },
        { "method": "POST", "path": "/posts",      "handler": "Shop\\Http\\PostController@create" },
        { "method": "GET",  "path": "/posts/{id}", "handler": "Shop\\Http\\PostController@show" }
      ]
    }
  ]
}
```

Both compile to identical route manifests.

## Inheritance Rules

A group inherits properties from its enclosing scope and passes them to its routes. Inner (more specific) declarations override or merge with outer (less specific) ones.

| Property | Inheritance | Notes |
|----------|-------------|-------|
| `prefix` | concatenated | `/api` + `/posts` = `/api/posts` |
| `name` | prepended | `"api."` + `"show"` = `"api.show"` |
| `filters` | merged, de-duplicated by alias | Route's `throttle:5,1` replaces group's `throttle:60,1` |
| `requires` | union | all domains from both levels |
| `domain` | inner overrides outer | only the innermost declaration is used |
| `subdomain` | inner overrides outer | only the innermost declaration is used |
| `faces` | inner overrides outer (when non-empty) | only the innermost declaration is used |

### Filter De-Duplication

Filters are merged by ALIAS to prevent double-execution:

```jsonc
{
  "routeFilters": ["throttle:60,1"],
  "groups": [
    {
      "filters": ["auth"],
      "routes": [
        { "filters": ["throttle:5,1"], ... }
      ]
    }
  ]
}
```

The route's `throttle:5,1` **replaces** (not stacks with) the module's `throttle:60,1`. The final filter stack is: `throttle:5,1`, `auth`.

### Prefix Concatenation

Prefixes are concatenated outward-in:

```jsonc
{
  "routePrefix": "/api",
  "groups": [
    {
      "prefix": "/admin",
      "routes": [
        { "path": "/stats" }
      ]
    }
  ]
}
```

Compiles to: `/api/admin/stats`

### Name Prepending

Route names are prefixed with all enclosing group names:

```jsonc
{
  "routeName": "v1.",
  "groups": [
    {
      "name": "admin.",
      "groups": [
        {
          "name": "users.",
          "routes": [
            { "name": "index" }
          ]
        }
      ]
    }
  ]
}
```

Route name: `v1.admin.users.index`

An unnamed route stays unnamed — a group's name prefix does not invent names.

## Nesting

Groups may nest arbitrarily deep (up to 16 levels to guard against self-referencing structures):

```jsonc
{
  "groups": [
    {
      "prefix": "/api",
      "name": "api.",
      "groups": [
        {
          "prefix": "/v1",
          "name": "v1.",
          "groups": [
            {
              "prefix": "/admin",
              "filters": ["shield"],
              "name": "admin.",
              "routes": [
                { "method": "GET", "path": "/users", "handler": "...", "name": "users" }
              ]
            }
          ]
        }
      ]
    }
  ]
}
```

Compiles to:

- Path: `/api/v1/admin/users`
- Name: `api.v1.admin.users`
- Filters: `shield`

## Group and Route Keys

A route's declaration key consists of `method`, `path`, and optional `domain`:

```jsonc
{
  "groups": [
    {
      "prefix": "/api",
      "domain": "api.example.com",
      "routes": [
        { "method": "GET", "path": "/users" }
      ]
    },
    {
      "prefix": "/web",
      "domain": "example.com",
      "routes": [
        { "method": "GET", "path": "/users" }
      ]
    }
  ]
}
```

Compiles to:
- `GET@api.example.com /api/users`
- `GET@example.com /web/users`

These are distinct routes because the domain differs. The same `path` on different domains does not collide.

## Module-Wide Defaults with Groups

Module-wide properties serve as defaults for groups and routes. Groups can override or extend them:

```jsonc
{
  "routePrefix": "/api",
  "routeFilters": ["auth"],
  "routeName": "api.",
  "groups": [
    {
      "prefix": "/admin",
      "filters": ["shield"],
      "name": "admin.",
      "routes": [...]
    }
  ]
}
```

Routes in the group inherit:
- `prefix`: `/api/admin` (both combined)
- `filters`: `auth`, `shield` (merged)
- `name`: `api.admin.` (prepended)

## Compiled Output

The compiler flattens all groups into a single `routes` array for the manifest. A route declared deep in nested groups becomes an ordinary flat route:

Input:

```jsonc
{
  "groups": [
    {
      "prefix": "/api",
      "name": "api.",
      "groups": [
        {
          "prefix": "/posts",
          "name": "posts.",
          "routes": [
            { "method": "GET", "path": "", "handler": "...", "name": "index" }
          ]
        }
      ]
    }
  ]
}
```

Compiled manifest entry:

```php
"GET /api/posts" => [
    'path'   => '/api/posts',
    'method' => 'GET',
    'name'   => 'api.posts.index',
    'handler' => '...',
    ...
]
```

## Nothing Subtracts

A group cannot remove a filter, require, or other property from an outer group. Removal is the project's prerogative and lives in `routePolicy.disable` or `Kernel::withRoutePolicy()`:

```jsonc
{
  "routeFilters": ["throttle:60,1"],
  "groups": [
    {
      "filters": [],   // <- does NOT remove throttle:60,1
      "routes": [...]
    }
  ]
}
```

The routes in this group still inherit `throttle:60,1`. To change a filter, re-declare it with different args: `"throttle:5,1"` overrides `"throttle:60,1"`.

## Practical Examples

### API Versioning

```jsonc
{
  "routePrefix": "/api",
  "routeName": "api.",
  "groups": [
    {
      "prefix": "/v1",
      "name": "v1.",
      "routes": [
        { "method": "GET", "path": "/products", "handler": "V1\\ProductController@index", "name": "products" }
      ]
    },
    {
      "prefix": "/v2",
      "name": "v2.",
      "routes": [
        { "method": "GET", "path": "/products", "handler": "V2\\ProductController@index", "name": "products" }
      ]
    }
  ]
}
```

Routes:
- `GET /api/v1/products` → `api.v1.products`
- `GET /api/v2/products` → `api.v2.products`

### Admin Section with Extra Filters

```jsonc
{
  "groups": [
    {
      "prefix": "/admin",
      "filters": ["auth", "shield"],
      "name": "admin.",
      "requires": ["admin.dashboard"],
      "routes": [
        { "method": "GET", "path": "/users", "handler": "...", "name": "users" },
        { "method": "GET", "path": "/settings", "handler": "...", "name": "settings" }
      ]
    }
  ]
}
```

All routes under `/admin` require both `auth` and `shield` filters, plus the `admin.dashboard` module.

### Nested Sub-Resources

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
            { "method": "GET", "path": "/{team_id}", "handler": "...", "name": "show" }
          ]
        }
      ]
    }
  ]
}
```

Routes:
- `GET /organizations/{org_id}/teams` → `org.teams.index`
- `GET /organizations/{org_id}/teams/{team_id}` → `org.teams.show`

## Next Steps

- [Route Domains](/routing/domains) — domain-specific routing and multi-host groups
- [Route Basics](/routing/basics) — route entry reference
- [Route Cookbook](/routing/cookbook) — more worked examples

## Source

- [GroupExpander.php](https://github.com/AlfaCode-Team/hkm-kernel/blob/main/src/Kernel/Boot/Routing/GroupExpander.php) — group expansion and inheritance logic
