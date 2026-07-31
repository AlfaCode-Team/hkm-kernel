# HKM Kernel — Plugins Layer

> The `plugins/` folder is the home for **locally developed business modules** that belong to
> this specific application but are not published as standalone packages.
> Every module here follows identical GDA rules — only the folder and namespace differ.

---

## Why `plugins/` Exists

| Folder | Purpose |
|---|---|
| `modules/` | First-party framework packages (`bind-it`, `php-io-cli`, etc.) loaded as Composer path repositories. These are git submodules and may be published to Packagist. |
| `projects/` | Project-layer wiring only — bootstrap files, domain resolution, `platform.json`, `projects.json`. No business logic lives here. |
| `plugins/` | Local business modules unique to this application. Full GDA structure. Autoloaded via `Plugins\\` PSR-4 prefix. Never git submodules. |

---

## Namespace and Autoload

```json
// composer.json autoload.psr-4
"Plugins\\": "plugins/"
```

Every plugin's root namespace is `Plugins\{ModuleName}\`.

---

## Plugin Directory Structure

Identical to the standard GDA module layout:

```
plugins/{Name}/
├── module.json                              ← single source of truth
├── Provider.php                             ← implements ModuleContract
├── API/
│   ├── Contracts/{Name}ServiceContract.php
│   ├── Dto/
│   └── IntegrationEvents/
├── Domain/
│   ├── Entities/
│   ├── ValueObjects/
│   ├── Events/
│   └── Rules/
├── Application/
│   └── Services/{Name}Service.php
└── Infrastructure/
    ├── Http/Controllers/{Name}Controller.php
    ├── Persistence/{Name}Repository.php
    └── Gateways/
```

---

## module.json Handler Paths

Because handlers are in the `Plugins\` namespace, use the fully-qualified class string:

```json
{
  "routes": [
    {
      "method": "GET",
      "path": "/api/things",
      "handler": "Plugins\\MyModule\\Infrastructure\\Http\\MyController@index"
    }
  ],
  "exposes": ["Plugins\\MyModule\\Api\\Contracts\\MyServiceContract"]
}
```

A route entry may also carry `filters[]` (auth, throttle, …) and an optional
`requires[]` of extra module domains. A plugin route normally gets its deps via
its own `solves` graph, so `requires[]` is rarely needed here — it is the primary
mechanism for PROJECT routes (whose `__project__` scope has no graph); see
[11_PROJECT.md](11_PROJECT.md) "Per-route `requires`". Either way, every
`requires[]` domain is validated at BOOT — an unknown domain fails the build.

---

## Registering a Plugin

Add the `Provider` class to the appropriate project bootstrap:

```php
// projects/admin/bootstrap/app.php
use Plugins\Task\Provider as TaskModule;
use Plugins\MyOtherModule\Provider as MyOtherModule;

return $builder
    ->withModules([
        TaskModule::class,
        MyOtherModule::class,
    ])
    ->build();
```

---

## Registered Plugins

| Plugin | Namespace | Solves | Routes |
|---|---|---|---|
| Task | `Plugins\Task\` | `task.management` | `GET/POST /api/tasks`, `GET/POST/DELETE /api/tasks/{id}` |

Infrastructure plugins (port adapters / pipeline stages, no routes) — see
[20_FIRST_PARTY_PLUGINS.md](20_FIRST_PARTY_PLUGINS.md) for the full list and the
module-activation notes (on-demand vs essential):

| Plugin | Solves | Provides | Activation |
|---|---|---|---|
| Storage | `storage.local` | `StoragePort` (local + S3) | on-demand |
| HttpClient | `http.client` | `HttpClientPort` (cURL) | on-demand |
| Session | `session.management` | `SessionPort` (file/array/cookie drivers) | essential |
| Cookie | `http.cookies` | `CookieJar` + flush stage | essential |
| RedisCache | `cache.redis` | `CachePort` + `QueuePort` | essential |
| SecurityFilters | `http.security_filters` | global hooks: CORS, SecureHeaders. Route-filter aliases: `auth`, `throttle`, `hmac`, `shield` | hooked + filters |
| Tenancy | `tenancy.routing` | `TenantRegistryContract` + `TenantConnectionResolverContract` + `MembershipServiceContract` + `InvitationServiceContract` (database-per-tenant routing + selection/invitation flows; STRICT: every request must resolve a tenant or 404 — no unscoped passthrough; refresh tokens in `Plugins\Auth`; `requires: ["database.management"]` — route-level `requires[]` carry auth/user/audit for its own endpoints) | essential (declare `"essentials": ["tenancy.routing"]` in proj.json) |

---

## Plugin Views — Project-First Cascade + Namespacing

A plugin may ship its own templates and register them via a `views` key in
`module.json`. `CompileViewManifestStage` folds every plugin's `views` plus the
project's `proj.json` `views` into `view-manifest.php`, which the View plugin's
renderer consumes.

```jsonc
// plugins/Task/module.json
"views": "resources/views"                                   // namespace defaults to "task"
"views": { "path": "resources/views", "namespace": "task",
           "priority": 100, "global": true }                 // explicit form
```

Resolution is DETERMINISTIC — lower `priority` wins:

- PROJECT view paths default to priority `0` (highest) → a project view
  overrides a plugin view of the same name BY DEFAULT.
- PLUGIN view paths default to priority `100` → fallbacks.
- `render('welcome')` walks the global cascade (project first).
- `render('task::welcome')` targets the `task` namespace, but the project can
  override it by placing `{project-views}/task/welcome.php` (checked first).
- A plugin may preempt the project ONLY with an explicit lower priority
  (e.g. `"priority": -1`). `"global": false` exposes a source under its
  namespace only (collision-proof).

The resource-resolution model (project-over-plugin, deterministic at boot) is described above.

---

## Rules

```
✓ plugins/{Name}/  →  Plugins\{Name}\  (PascalCase folder = PascalCase namespace)
✓ Project resources (routes/views) override plugin resources by default — deterministic
✓ A project may DISABLE plugin routes via proj.json routePolicy.disable[] — never fork a plugin to hide an endpoint
✓ Use namespace::view to target a plugin view and to avoid cross-plugin name collisions
✓ module.json handlers use fully-qualified Plugins\... class strings
✓ Provider registered in projects/{project}/bootstrap/app.php
✗ Do NOT place plugin files under projects/ — that folder is for wiring only
✗ Do NOT add plugins as Composer path repositories — Plugins\ PSR-4 covers autoloading
✗ All GDA five-layer access rules apply exactly as for any other module
```

---

## Adding a New Plugin (Checklist)

1. `mkdir -p plugins/{Name}/{API/Contracts,API/Dto,API/IntegrationEvents,Application/Services,Domain/Entities,Domain/ValueObjects,Domain/Events,Infrastructure/Http,Infrastructure/Persistence}`
2. Write `plugins/{Name}/module.json` — set `"type": "module"`, `"solves"`, routes with `Plugins\\{Name}\\...` handlers
3. Implement all layers under `namespace Plugins\{Name}\...`
4. Write `plugins/{Name}/Provider.php` — `namespace Plugins\{Name};` implements `ModuleContract`
5. Add `Plugins\{Name}\Provider::class` to the relevant `projects/*/bootstrap/app.php`
6. Run `composer dump-autoload` if the new namespace isn't picked up automatically
