# HKM Kernel — Project Layer

> The Project layer contains no business logic. It wires kernel contracts to infrastructure adapters and chooses which business modules are active per project.

---

## Current Project Bootstrap Architecture

The repository now uses inheritance-safe project bootstrapping:

- Shared base builder: `app/bootstrap/base.php` (returns an unbuilt `Kernel` builder)
- Per-project bootstrap: `projects/{project}/bootstrap/app.php` (extends base and calls `->build()`)
- Backward-compatible shim: `bootstrap/app.php` delegates to `projects/admin/bootstrap/app.php`
- Runtime selection: entry points resolve `HKM_PROJECT` (default: `admin`) and load `projects/{HKM_PROJECT}/bootstrap/app.php`, falling back to `bootstrap/app.php`

---

## Why This Shape

The kernel freezes `CoreContainer` when it materializes (the first entry-point call), not in `build()`. Inherited projects must still share the builder, not a built kernel instance — each project finalizes its own ports/modules with its own `->build()`.

This allows:

- one shared admin base in `app/`
- many child projects with their own module sets
- identical entry points reused across projects

---

## Builder Semantics (Inheritance-Safe)

`Kernel` builder methods are additive so child projects can extend base config safely:

- `withPorts([...])`: merges with existing bindings (later keys override earlier ones)
- `withSecurity([...])`: appends layers (base first, project additions later)
- `withModules([...])`: appends and de-duplicates module class names preserving order

---

## File Layout (As Implemented)

```text
app/
├── Infrastructure/
│   ├── InMemoryCache.php
│   └── PdoDatabase.php
├── bootstrap/
│   └── base.php
├── api/server.php
├── cli/run.php
├── worker/run.php
└── public_html/index.php

projects/
└── admin/
    └── bootstrap/app.php

bootstrap/
└── app.php   # legacy shim
```

---

## Base Builder Pattern

```php
// app/bootstrap/base.php (shared defaults, NO ->build())
return Kernel::configure()
    ->withBasePath(dirname(__DIR__, 2))
    ->withPorts([
        DatabasePort::class => new PdoDatabase(...),
        CachePort::class    => new InMemoryCache(),
    ])
    ->withSecurity([
        new CsrfTokenLayer(exemptPaths: ['/api']),
    ]);
```

---

## Project Bootstrap Pattern

```php
// projects/admin/bootstrap/app.php
/** @var Kernel $builder */
$builder = require __DIR__ . '/../../../app/bootstrap/base.php';

return $builder
    ->withModules([
        TaskModule::class,
    ])
    ->build();
```

---

## Entry Point Resolution Pattern

All entry points in `app/` follow this runtime bootstrap selection logic. Note the
fixed order: resolve the project, **load the environment, install the error net, THEN
require the kernel bootstrap** (so a pre-kernel failure is caught and cannot leak):

```php
$rootPath = dirname(__DIR__, 2);
$domain   = EntryHelpers::resolveDomain($rootPath, $host);   // HTTP only; null in CLI/worker
$project  = (string) (getenv('HKM_PROJECT') ?: 'admin');     // ← legitimate pre-env getenv

LoadEnvironment::load($rootPath, $domain, $argv);            // 1. .env cascade → $_ENV
ErrorGuard::install($rootPath . '/projects/' . $project . '/var/logs/errors.log'); // 2. error net

$kernel = require EntryHelpers::bootstrapPathFor($rootPath, $project);  // 3. kernel
```

`HKM_PROJECT` is read with `getenv()` on purpose — it selects which project to boot and
is a genuine OS/server variable evaluated *before* `LoadEnvironment` runs. Everything the
kernel and modules read afterwards must use the `env()` helper, not `getenv()` (see
`app/Bootstrap/Environment/`).

Applied to:

- `app/api/server.php` (env + guard installed once per worker in `workerStart`; guard is ini-only)
- `app/cli/run.php`
- `app/worker/run.php`
- `app/public_html/index.php`

---

## Project Routes & Views (Project-Over-Plugin Priority)

A project can declare its OWN routes and view paths — they take precedence over
plugin resources by default (deterministic, compiled at boot).

```jsonc
// projects/<name>/proj.json  (or the flat project-root proj.json)
{
  "name": "shop",
  "views": "resources",                                  // project view root (priority 0)
  "routes": [
    { "method": "GET", "path": "/",     "handler": "Shop\\Http\\HomeController@index" },
    { "method": "GET", "path": "/ping", "handler": "Shop\\Http\\HomeController@ping"  }
  ]
}
```

- Routes: `EntryHelpers::projectRoutes($projectPath)` reads `proj.json`
  `routes[]`; the project bootstrap passes them to `Kernel::withRoutes(...)`.
  They compile AFTER all plugin routes and OVERRIDE a plugin route with the same
  `METHOD path`. They resolve under the synthetic `__project__` scope (no module
  graph); the full-class-path controller autowires from the request container.
  Keep project controllers thin — orchestrate published plugin contracts.
- Views: project view paths sort to priority `0` (highest). `render('welcome')`
  resolves the project copy before any plugin's; `render('plugin::view')` can be
  overridden by dropping `{project-views}/plugin/view.php`.

### Per-route `requires` — project routes opting into plugins

The `__project__` scope has an EMPTY dependency graph, so a project route loads
NO plugins by default: on-demand modules' `register()` never runs, their published
contracts are unbound, and a `ViewController` (which constructor-injects
`ViewRendererContract`) cannot even be built. To pull a plugin into ONE project
route without making it essential, declare a route-level `requires[]`:

```jsonc
// proj.json
{ "method": "GET", "path": "/dashboard",
  "handler": "Shop\\Http\\DashboardController@index",
  "requires": ["view.rendering"] }
```

- `CompileRouteManifestStage` validates each `requires[]` entry at BOOT against
  the set of domains some module `solves()` — an unknown/typo'd domain fails the
  build with a descriptive message (never a request-time 500).
- `LoadStage` reads the matched route's `requires[]` and seeds those domains
  (plus their transitive `requires`) into THAT request's graph only, via
  `DependencyGraphCalculator::resolve($service, $additional)`. Routes without
  `requires[]` stay lean.
- Scope isolation is unchanged: the required plugin's PUBLIC contract resolves in
  the project controller, but its `bindInternal` bindings still throw
  `ScopeViolationException` cross-scope.

| Need | Mechanism |
| --- | --- |
| Some project routes need a plugin | route-level `requires[]` in `proj.json` |
| Every request needs a plugin | `withEssentialModules([...])` |
| The endpoint IS the plugin's domain | declare the route in the plugin's `module.json` |

Project routes also pass `filters[]` through to the compiler; plugin routes MAY
carry `requires[]` too (they normally get deps via their module's `solves` graph).

### Route policy — DISABLE plugin routes (the third verb)

A plugin OWNS and declares its routes, but the deploying project is the FINAL
authority: it can veto plugin routes it will not expose — without forking the
plugin. Declared in `proj.json` and wired by the bootstrap via
`Kernel::withRoutePolicy(EntryHelpers::projectRoutePolicy($projectRoot))`:

```jsonc
// proj.json
"routePolicy": {
  "disable": [
    "GET /register",   // one plugin route (method + path)
    "oauth.server"     // a module DOMAIN — every route that module solves()
  ]
}
```

- Two spec forms: `"METHOD /path"` (one exact plugin route) or a bare module
  domain (all of that module's routes — the whole-plugin off switch).
- `CompileRouteManifestStage` applies the policy to plugin routes AFTER they
  compile and BEFORE project routes — so a project can disable a plugin route
  and re-declare its OWN on the freed `METHOD path` with no duplicate-route
  boot failure.
- A spec matching NOTHING fails the build with a descriptive message (same
  anti-typo guard as unknown `requires[]` domains). Never a silent no-op.
- Project routes (`withRoutes`) are the project's own and are unaffected.

| Route verb | Mechanism | Result |
| --- | --- | --- |
| add | project `routes[]` | new project route |
| override | project route on a plugin's `METHOD path` | project controller wins |
| disable | `routePolicy.disable[]` | plugin route dropped (404) |

See the project-over-plugin resource-resolution model in [16_PLUGINS.md](16_PLUGINS.md).

---

### Global (essential) modules — proj.json `"essentials"`

Which plugins register on EVERY request is a per-project deployment decision,
declared in `proj.json` — not a bootstrap code edit:

```jsonc
// proj.json — module DOMAINS (a plugin's solves value)
"essentials": ["tenancy.routing", "auth.identity", "user.management"]
```

Wired by the bootstrap via
`Kernel::withEssentialModules(EntryHelpers::projectEssentials($projectRoot))`.
Semantics:

- `withEssentialModules()` accepts provider class-strings AND module domains; a
  domain must name a module already in `withModules()` and resolves to its
  provider at `build()` — an unknown domain FAILS the boot (never a silent
  no-op essential).
- Essential domains are seeded into every request's dependency graph, so an
  essential's transitive `requires[]` load with it; each module still registers
  exactly once per request.
- Keep the list SHORT — every essential (and its requires graph) is
  per-request `register()` cost.
- Session-cookie apps declare `auth.identity` + `user.management` so Auth's
  `SessionAuthStage` resolves the logged-in user on every page; JWT/PAT-only
  APIs need neither (token layers run before any module loads).
- Multi-tenant projects declare `tenancy.routing`; single-tenant projects leave
  Tenancy out of `withModules` entirely.

---

## Rules For Future Project Work

- Keep business logic out of `app/`, `bootstrap/`, and project bootstrap files
- Project routes go in `proj.json` routes[] (or `Kernel::withRoutes()`), never in PHP
- Unwanted plugin routes go in `proj.json` routePolicy.disable[] — never fork a plugin to hide an endpoint
- Put only port/adapters/security/module lists in bootstrap wiring
- Add new projects under `projects/{name}/bootstrap/app.php`
- Ensure module classes listed in `withModules()` have valid `module.json`
- Prefer extending `app/bootstrap/base.php` over copy-pasting full kernel wiring
