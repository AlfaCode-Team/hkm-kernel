# HKM Kernel — Plugins Layer

> **A plugin is a standalone package in its own git repository.** Since 1.1.0 the kernel
> depends on zero plugins and ships none: `plugins/` in the kernel repo is empty. In a
> PROJECT, `plugins/` holds the plugins that project has installed.
> Every plugin follows identical GDA rules — only the folder and namespace differ.

**Repo:** `github.com/AlfaCode-Team/hkm-plugin-<slug>` · **Package:**
`alfacode-team/hkm-plugin-<slug>` · **Namespace:** `Plugins\{Name}\` · **Test doubles:**
`AlfaCode-Team/hkm-test-support`

Slug = lower-cased folder name, except `DevTools→dev-tools`, `HttpClient→http-client`,
`RedisCache→redis-cache`, `SecurityFilters→security-filters`, `SocialAuth→social-auth`,
and the unhyphenated `SiteSEO→siteseo`, `ViteManifest→vitemanifest`, `OAuth2→oauth2`.

**Managed with `hkm plugins`** — `install`, `enable` (auto-installs), `disable`,
`uninstall`, `versions`, `outdated`, `update`, `lock`, `verify`, `store`, `domains`,
`create`. Installs resolve to a TAG, never a branch; `plugins.lock.json` records remote,
tag, commit and kernel version (commit it, never hand-edit). A global plugin store keyed
`<Name>/<version>-<origin-hash>` shares one download across projects. `module.json`
`"kernel": "^1.2"` gates install. A dependency is a DOMAIN, not a repo name — 13 of 28
domains do not match their repo name, so use `hkm plugins domains` rather than guessing.

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
use Plugins\Invoice\Provider as InvoiceModule;
use Plugins\MyOtherModule\Provider as MyOtherModule;

return $builder
    ->withModules([
        InvoiceModule::class,
        MyOtherModule::class,
    ])
    ->build();
```

---

## Registered Plugins

| Plugin | Namespace | Solves |
|---|---|---|
| SiteSEO | `Plugins\SiteSEO\` | `seo.management` |
| I18n | `Plugins\I18n\` | `i18n.translation` |
| Logger | `Plugins\Logger\` | `logging.application` |
| Crypto | `Plugins\Crypto\` | `crypto.services` |
| Database | `Plugins\Database\` | `database.management` |
| Authorization | `Plugins\Authorization\` | `authorization.policy` |
| Audit | `Plugins\Audit\` | `audit.trail` |
| Settings | `Plugins\Settings\` | `tenant.settings` |
| SocialAuth | `Plugins\SocialAuth\` | `auth.social` |
| Commands | `Plugins\Commands\` | `system.commands` |
| Edge | `Plugins\Edge\` | `edge.routing` |
| DevTools | `Plugins\DevTools\` | `dev.tooling` |

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
// {Invoice plugin}/module.json
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
✗ Do NOT author plugin source in the KERNEL repo's plugins/ — it ships no plugins
✗ Do NOT add a hkm-plugin-* require to the kernel's composer.json
✗ Do NOT hand-edit plugins.lock.json, or guess a plugin's repo from its solves domain
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
