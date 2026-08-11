# Routing Cookbook — Worked Examples

Every recipe here was compiled through `CompileRouteManifestStage` and the output
below is what it actually produced. Copy, adjust the handler, done.

Recipes live in `module.json` (a plugin) or `proj.json` (a project) — the shape is
identical. See [02_MODULE.md](02_MODULE.md) for the complete key reference and
[11_PROJECT.md](11_PROJECT.md) for project-side wiring.

---

## 1. A CRUD resource

One group carries the prefix and the name stem, so no line repeats them.

```jsonc
"groups": [
  { "prefix": "/invoices", "name": "invoice.",
    "routes": [
      { "method": "GET",    "path": "",          "handler": "InvoiceController@index",   "name": "index"   },
      { "method": "POST",   "path": "",          "handler": "InvoiceController@store",   "name": "store"   },
      { "method": "GET",    "path": "/{id:num}", "handler": "InvoiceController@show",    "name": "show"    },
      { "method": "PUT",    "path": "/{id:num}", "handler": "InvoiceController@update",  "name": "update"  },
      { "method": "DELETE", "path": "/{id:num}", "handler": "InvoiceController@destroy", "name": "destroy" }
    ] }
]
```

**Compiles to:**

```
GET    /invoices                name=invoice.index
POST   /invoices                name=invoice.store
GET    /invoices/{id:num}       name=invoice.show
PUT    /invoices/{id:num}       name=invoice.update
DELETE /invoices/{id:num}       name=invoice.destroy
```

`"path": ""` is legal inside a prefixed group — the prefix supplies the path.
`{id:num}` means `/invoices/abc` 404s at the router, never reaching the controller.

---

## 2. An admin area behind auth

Groups nest. The inner group adds a throttle without repeating `auth`.

```jsonc
"groups": [
  { "prefix": "/admin", "filters": ["auth"], "name": "admin.",
    "routes": [
      { "method": "GET", "path": "/", "handler": "AdminController@home", "name": "home" }
    ],
    "groups": [
      { "prefix": "/users", "filters": ["throttle:30,1"], "name": "users.",
        "routes": [
          { "method": "GET",    "path": "",           "handler": "UserAdminController@index",   "name": "index"   },
          { "method": "DELETE", "path": "/{id:uuid}", "handler": "UserAdminController@destroy", "name": "destroy" }
        ] }
    ] }
]
```

**Compiles to:**

```
GET    /admin/                  [auth]                  name=admin.home
GET    /admin/users             [auth throttle:30,1]    name=admin.users.index
DELETE /admin/users/{id:uuid}   [auth throttle:30,1]    name=admin.users.destroy
```

Filters accumulate outward-in; names concatenate the same way.

---

## 3. A versioned API, rate limited by default

Module-wide defaults apply to every route in the file — no group needed.

```jsonc
{
  "routePrefix":  "/api/v2",
  "routeFilters": ["throttle:60,1"],

  "routes": [
    { "method": "GET",  "path": "/ping",   "handler": "ApiController@ping" },
    { "method": "POST", "path": "/import", "handler": "ApiController@import",
      "filters": ["auth", "throttle:5,1"] }
  ]
}
```

**Compiles to:**

```
GET  /api/v2/ping     [throttle:60,1]
POST /api/v2/import   [auth throttle:5,1]
```

Note `/import`: its `throttle:5,1` **replaced** the default `throttle:60,1` rather
than running the stage twice with two different budgets. De-duplication is by
**alias**, so a route always wins over the default it names.

---

## 4. A safe file download

```jsonc
{ "method": "GET", "path": "/download/{file:path}", "handler": "FileController@download" }
```

**Behaviour:**

```
GET /download/reports/q1.pdf     -> FileController@download   ($file = 'reports/q1.pdf')
GET /download/../../etc/passwd   -> 404
GET /download/a/..%2Fb           -> 404
```

`path` crosses `/` like `any`, but refuses `..` and control characters. The encoded
attempt fails too, because captured values are decoded and **re-validated** before
the controller sees them.

> Using `{file:any}` here would match all three — `any` has **no traversal guard**
> and is kept unchanged only so existing routes do not regress.

---

## 5. Optional pagination and a closed set

```jsonc
{ "method": "GET", "path": "/posts/{page:num?}",
  "handler": "PostController@index", "name": "post.index" },

{ "method": "GET", "path": "/posts/status/{s:enum(draft|published)}",
  "handler": "PostController@byStatus" }
```

**Behaviour:**

```
GET /posts                    -> PostController@index      ($page = '')
GET /posts/2                  -> PostController@index      ($page = '2')
GET /posts/two                -> 404      the type still applies
GET /posts/status/draft       -> PostController@byStatus
GET /posts/status/deleted     -> 404      not a member of the enum
```

The optional parameter takes its leading `/` with it, so one route serves both
`/posts` and `/posts/2`. `enum` members are `preg_quote`d — no regex can be
injected from JSON.

---

## 6. Two brands and a portal on one project

The case `faces` cannot express: the same path, a different handler per host.

```jsonc
{
  "domains": ["hkmvote.local", "africavoting.local", "organizer.africavoting.local"],

  "groups": [
    { "domain": "hkmvote.local",      "name": "vote.",
      "routes": [ { "method": "GET", "path": "/", "handler": "VoteHome@index",   "name": "home" } ] },

    { "domain": "africavoting.local", "name": "africa.",
      "routes": [ { "method": "GET", "path": "/", "handler": "AfricaHome@index", "name": "home" } ] },

    { "domain": "organizer.africavoting.local", "prefix": "/dashboard",
      "filters": ["auth"], "name": "organizer.",
      "routes": [ { "method": "GET", "path": "", "handler": "Organizer@index", "name": "home" } ] },

    { "domain": "*.africavoting.local",
      "routes": [ { "method": "GET", "path": "/", "handler": "TenantHome@index" } ] }
  ],

  "routes": [
    { "method": "GET", "path": "/health", "handler": "HealthController@show" }
  ]
}
```

**Compiles to:**

```
GET /health                                   ← ungrouped: GLOBAL
GET@hkmvote.local /                           name=vote.home
GET@africavoting.local /                      name=africa.home
GET@organizer.africavoting.local /dashboard   [auth]  name=organizer.home
GET@*.africavoting.local /
```

**Behaviour:**

```
GET /          @ hkmvote.local                -> VoteHome@index
GET /          @ africavoting.local           -> AfricaHome@index
GET /          @ news.africavoting.local      -> TenantHome@index    (wildcard)
GET /dashboard @ organizer.africavoting.local -> Organizer@index     (exact beats wildcard)
GET /health    @ hkmvote.local                -> HealthController@show
GET /health    @ anything.example             -> HealthController@show
```

Note the two `home` names had to become `vote.home` and `africa.home` — names are
one flat, application-wide namespace, and a group `name` prefix is the fix.

---

## 7. An `api.` subdomain that serves every domain

A bare `subdomain` belongs to no single host, which is the point.

```jsonc
"groups": [
  { "subdomain": "api", "prefix": "/v1", "filters": ["throttle:120,1"],
    "routes": [ { "method": "GET", "path": "/ping", "handler": "ApiController@ping" } ] }
]
```

**Compiles to** `GET@api /v1/ping   [throttle:120,1]`, and:

```
GET /v1/ping @ api.example.com      -> ApiController@ping
GET /v1/ping @ api.example2.com     -> ApiController@ping
GET /v1/ping @ api.brand-new.test   -> ApiController@ping   ← host never registered
GET /v1/ping @ www.example.com      -> 404
```

Because it answers on every domain, a bare subdomain is **never** validated
against `proj.json` `"domains"` — there is no single host to check it against.

---

## 8. Override a plugin page, veto another

```jsonc
{
  "routePolicy": {
    "disable": [
      "GET /register",   // drop the plugin's page — the key is now free
      "oauth.server"     // or drop EVERY route that module solves()
    ]
  },

  "routes": [
    { "method": "GET", "path": "/register", "handler": "Shop\\SignupController@show" }
  ]
}
```

Disable runs on plugin routes **before** project routes compile, so vetoing and
re-declaring the same key is not a duplicate-route failure. A project route that
overrides a plugin route **inherits its name** unless it declares one, so every
`route('auth.register')` in the plugin's own views keeps working.

> A disable spec matching nothing FAILS the boot — a silently-ignored disable
> would leave an endpoint exposed that you believed was gone.

---

## 9. A project page that needs exactly one plugin

Project routes run under the synthetic `__project__` scope, whose dependency graph
is **empty** — they load no plugins at all.

```jsonc
{ "method": "GET", "path": "/dashboard",
  "handler": "Shop\\DashboardController@index",
  "requires": ["view.rendering"] }
```

Without `requires`, the View plugin's contract is unbound and the controller
cannot render. This is the per-route alternative to making a plugin *essential*:

| Need | Mechanism |
|---|---|
| Stateless, every request | `withPorts([...])` — an app-lifetime port |
| Some routes need a plugin | `"requires"` on the route |
| Every request needs it | `"essentials"` in `proj.json` |

Requiring a plugin grants its **published contracts** only — `bindInternal()`
bindings still throw `ScopeViolationException` across scopes.

---

## 10. Restrict a route to one face

```jsonc
{ "method": "GET", "path": "/ops", "handler": "OpsController@index", "faces": ["admin"] }
```

Invisible on any other face, and a mismatch 404s rather than 403s — a route the
caller cannot reach here should not advertise that it exists elsewhere. Requires
the entry point to set `route_face`; with nothing set, the restriction is inert
rather than silently 404ing everywhere.

Use `faces` for the coarse admin/api/project/public split, and a **domain group**
when you need the same path to resolve differently per host (recipe 6).

---

## 11. An email verification link

```php
// Minting — in the service that sends the mail
$link = signed_route('email.verify', ['id' => $user->id()], expiresIn: 3600);
```

```jsonc
// The route enforces the signature declaratively
{ "method": "GET", "path": "/verify/{id:num}", "handler": "VerifyController@confirm",
  "name": "email.verify", "filters": ["signed"] }
```

The HMAC covers the path and query — never the host — so a proxy that rewrites
`Host` cannot invalidate the link. `expires` is inside the signature, so the
deadline cannot be extended by editing the URL. With an empty `APP_KEY`,
`signed_route()` throws rather than emitting a forgeable link.

---

## 12. A plugin publishing its own filter

```php
// Provider::boot() — the alias registry is shared, not owned by SecurityFilters
public function boot(
    HttpPipeline $http, CliPipeline $cli,
    WorkerPipeline $worker, EventBus $events,
): void {
    $http->filter('json', RequireJsonStage::class);
}
```

```jsonc
{ "method": "POST", "path": "/api/import", "handler": "…@import", "filters": ["json"] }
```

```php
final class RequireJsonStage implements HttpStageContract
{
    public function handle(Request $request, callable $next): Response
    {
        // "json:strict" → ['strict']
        $args = $request->attribute('filter_args')['json'] ?? [];

        if (!$request->expectsJson()) {
            return Response::json(['error' => ['code' => 'not_acceptable']], 406);
        }

        return $next($request);   // before → on the way in, after → on the way out
    }
}
```

> A stage is a global hook **or** a route filter — never both. Registered twice,
> it runs twice per request.

---

## 13. Reading route data in a controller

```php
final class ReportController extends ApiController   // RequestAware
{
    // Actions take route params ONLY — the Request arrives via $this->request.
    public function show(string $year, string $slug): Response
    {
        $this->request->attribute('route_entry');    // the compiled route entry
        $this->request->attribute('active_filters'); // ['auth', 'throttle']

        return $this->ok(['year' => $year, 'slug' => $slug]);
    }
}
```

A plain controller keeps `show(Request $request, string $year, string $slug)`.

---

## When it will not compile

Each of these stops the build with the route that caused it. Verified messages:

```
{id:number}          declares path [/u/{id:number}] with unknown parameter type [number] on {id}
"path": "users"      declares path [users] which does not start with '/'
/a/{id}/b/{id}       Route parameter {id} repeats the capture name [id]
/{2fa}               Route parameter {2fa} is not a usable capture name
"handler": "C"       has handler [C] — it must be in 'Controller@method' format (exactly one "@")
domain not served    Registered domains (proj.json "domains"): … Add it there, use a wildcard …
```

Every one previously compiled into a route that silently never matched — a 404
that reads like a missing controller rather than a typo.

---

## Production checklist

```bash
BOOT_CACHE=1            # build() otherwise recompiles every manifest per FPM request
APP_KEY=…               # signed URLs fail closed without it
APP_URL=https://…       # base for absolute URLs
ROUTE_VERIFY_HANDLERS=1 # CI only — verifies every handler class + method exists
```

And clear `var/cache/manifests/` on deploy.
