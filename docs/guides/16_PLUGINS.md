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
| `plugins/` | Business modules. Full GDA structure, autoloaded via the `Plugins\\` PSR-4 prefix. In a PROJECT this holds the plugins that project installed (each from its own repo) plus any project-authored ones. In the KERNEL repo it is empty and stays empty. |

The placement rule, stated once: **the framework holds only code that is for the
framework; `modules/` holds what the framework needs to run; `plugins/` holds
what extends projects.** Every business capability is a plugin, never the kernel.
Port *interfaces* live in `src/Kernel/Ports/` because the kernel defines the
contract; port *implementations* are always plugins.

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
the [project-layer docs](https://github.com/AlfaCode-Team/hkm-project-layer/blob/main/docs/PROJECT.md) "Per-route `requires`". Either way, every
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

## Which Plugins Exist

`hkm plugins domains` lists every installed plugin with the `solves` domain
it claims — live and authoritative. This repository keeps no static catalogue;
one would go stale, and it already had.

**A plugin documents itself, in its own repository.** Each one ships a
`README.md` (what it is, how to install it) and a `CLAUDE.md` (its contract,
its `config[]`, and the rules specific to it); some also ship a `docs/` deep
dive. `module.json` is the authoritative source for `requires[]`, `exposes[]`,
`emits[]` and `config[]` — read it there rather than from any summary.

```
✗ Documenting a plugin's behaviour, API, env vars or wiring in this repository —
  the copy in the kernel is the one that goes stale
✗ Inferring a plugin's requires[] from a table anywhere — open its module.json
```

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

1. `hkm plugins create {name}` scaffolds `plugins/{Name}/` from `templates/plugin/`.
   By hand: `mkdir -p plugins/{Name}/{API/Contracts,API/Dto,API/IntegrationEvents,Application/Services,Domain/Entities,Domain/ValueObjects,Domain/Events,Infrastructure/Http,Infrastructure/Persistence}`
2. Write `plugins/{Name}/module.json` — `"type": "module"`, a `"solves"` domain no
   other module claims, routes with `Plugins\\{Name}\\...` handlers, and **every
   env var the plugin reads** in `config[]` (with a `default` wherever one exists —
   that is the value `hkm plugins enable` seeds into the project `.env`).
3. Implement all layers under `namespace Plugins\{Name}\...`, obeying the five
   access rules.
4. Write `plugins/{Name}/Provider.php` — `namespace Plugins\{Name};` implements
   `ModuleContract`; `solves()`/`requires()`/`exposes()` must mirror `module.json`.
5. Add `Plugins\{Name}\Provider::class` to the relevant
   `projects/*/bootstrap/app.php` `withModules([...])`.
6. Run `composer dump-autoload` if the new namespace isn't picked up automatically.
7. Test it with the **Ground** plugin (`PluginGround::for(Provider::class)`) — a
   real kernel boot in a temp workspace, not a hand-rolled bootstrap. Gate CI on
   `hkm plugin:check`.
8. If it is going to its own repository, give it a `README.md` (install +
   capability) and a `CLAUDE.md` (its contract, `config[]` and plugin-specific
   rules). Those two files are where the plugin is documented — not in the kernel.
