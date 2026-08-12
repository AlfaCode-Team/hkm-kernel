# `Project\Bootstrap` — Entry Points, Domain Resolution & Environment

> Namespace `Project\Bootstrap\` → `projects/Bootstrap/`.

Everything an entry point needs **before** the kernel exists: which project is
being served, which `.env` files apply, and the error net that catches failures
the kernel can never see (parse errors, fatals, a throw during bootstrap).

All of it lives in the PROJECT layer so the kernel stays domain-agnostic and
environment-agnostic.

```
projects/Bootstrap/
├── EntryHelpers.php            ← shared helpers for all four entry points
├── Domain/
│   ├── DomainType.php          ← enum: Admin | Api | Project | Public
│   ├── DomainContext.php       ← final readonly value object (name, path, type, host, features)
│   └── DomainResolver.php      ← pure static resolve(basePath, host): ?DomainContext
└── Environment/
    ├── LoadEnvironment.php     ← .env three-tier cascade loader
    └── ErrorGuard.php          ← pre-kernel + fatal safety net
```

---

## Entry-point order — all four entries

```php
require vendor/autoload.php;
$domain  = EntryHelpers::resolveDomain($rootPath, $host);      // HTTP only (null in CLI/worker)
LoadEnvironment::load($rootPath, $domain, $argv);              // 1. env FIRST
ErrorGuard::install($logRoot . '/var/logs/errors.log');        // 2. error net
$project = EntryHelpers::projectFromContext($domain);
$kernel  = require EntryHelpers::bootstrapPathForContext($domain, $rootPath, $project);   // 3. THEN the kernel

$request = Request::capture();
if ($domain !== null) {
    $request = $request->withAttribute('domain', $domain);     // context rides on the REQUEST
}
$kernel->http()->handle($request)->send();
```

---

## `EntryHelpers`

| Method | Returns |
|---|---|
| `resolveDomain(string $rootPath, ?string $host): ?DomainContext` | `null` for an empty host (CLI/worker); never throws — `null` on any registry error |
| `projectFromContext(?DomainContext $ctx): string` | context name → `HKM_PROJECT` env → `'admin'`; always sanitised |
| `bootstrapPathFor(string $rootPath, string $project): string` | `projects/{project}/bootstrap/app.php`, falling back to the legacy `bootstrap/app.php` shim |
| `bootstrapPathForContext(?DomainContext $ctx, string $rootPath, string $project): string` | honours a project registered at an EXTERNAL absolute path (a flat standalone project from `hkm new`): tries `<projectPath>/app/bootstrap/app.php` then `<projectPath>/bootstrap/app.php`, else falls back to `bootstrapPathFor()` |
| `projectRoutes(string $projectPath): array` | `proj.json` `routes[]` — method/path/handler + optional `filters`/`requires`/`name`/`faces`/`domain`/`subdomain`, for `->withRoutes(...)` |
| `projectRouteGroups(string $projectPath): array` | `proj.json` `groups[]` + source-wide `routePrefix`/`routeFilters`/`routeRequires`/`routeName`/`routeDomain`, for `->withRouteGroups(...)` |
| `projectDomains(string $projectPath): array` | `proj.json` `domains[]` — the hosts this project serves, for `->withProjectDomains(...)`. The route compiler rejects a route grouped under a host absent from this list |
| `projectEssentials(string $projectPath): array` | `proj.json` `essentials[]` — module domains/classes for `->withEssentialModules(...)` |
| `projectRoutePolicy(string $projectPath): array` | `proj.json` `routePolicy.disable[]` — for `->withRoutePolicy(...)` |

Project names from JSON/env are validated against `/^[a-zA-Z0-9_\-]+$/` before
being concatenated into a filesystem path — traversal defence.

Malformed entries in `proj.json` are dropped silently here **on purpose**: a typo
must not break the boot at read time. Real errors (an invalid handler, an unknown
`requires` domain, a disable spec matching nothing) are caught later by the
route-manifest compiler with a descriptive message.

---

## Domain resolution

Maps an incoming `Host` header to a `DomainContext` (project + face + features).

### `DomainType` — the face

`Admin = 'admin'` · `Api = 'api'` · `Project = 'project'` · `Public = 'public'`.
Add a case here when a new face needs its own routing/navigation treatment.

### `DomainContext` — `final readonly`

| Field | Meaning |
|---|---|
| `name` | project name from `projects.json`, or `DomainContext::PLATFORM` (`'__platform__'`) when no project matched |
| `projectPath` | absolute path to the project directory |
| `type` | resolved `DomainType` face |
| `host` | clean lowercased hostname (no port, no trailing dot) |
| `features` | feature flags from `{projectPath}/proj.json` `"features"` |

Helpers: `isPlatformOnly()`, `isAdmin()`, `isApi()`, `isProject()`, `isPublic()`.

### `DomainResolver::resolve(string $basePath, string $host): ?DomainContext`

```
1. Normalise host: lowercase, strip port, strip trailing dot, unwrap IPv6 brackets
2. Determine face from the subdomain via projects/platform.json
3. PASS 1 — exact host match against projects.json domains[]
4. PASS 2 — '.domain' suffix match against projects.json domains[]
5. Neither matched but the subdomain is admin/api → platform-only context
6. Otherwise null (the entry point falls back to HKM_PROJECT, then 'admin')
```

Exact match beats suffix match, so a project registering `app.example.com`
directly wins over one registering `example.com`.

**Swoole / coroutine safety.** The context is NEVER bound into a container — it
travels on the immutable `Request` (`withAttribute('domain', …)`), so coroutines
sharing a worker cannot bleed it between in-flight requests. The resolver's cache
is a worker-level static keyed by `basePath`, populated once and never mutated on
the hot path; registry files are deploy-time artifacts and a redeploy spawns new
workers. `flushCache()` exists for tests only.

Registry files: `projects/platform.json` (which subdomains are admin/api faces),
`projects/projects.json` (project → `domains[]`), `projects/<name>/proj.json`
(optional `features[]`, `routes[]`, `essentials[]`, `routePolicy`).

---

## `LoadEnvironment` — the `.env` cascade

`load(string $rootPath, ?DomainContext $domain = null, ?array $argv = null): void`

```
TIER 1  base     {root}/.env, then {root}/.env.{APP_ENV|--env}
TIER 2  domain   {root}/.env.{sld}, .env.{sub}, .env.{sub}.{sld}     (parsed from the host)
TIER 3  project  {projectPath}/.env  (+ the same domain cascade)
```

Every file is optional; a later file overrides an earlier key. Values already
present in the REAL process environment are **never** clobbered — true OS/server
config always wins. The parser is self-contained (no `vlucas/phpdotenv`), because
native distributions ship without `vendor/`.

### `env()` is the canonical reader — never `getenv()` in first-party code

Values are injected into `$_ENV` and `$_SERVER` only. `putenv()` is **not**
called by default: it was ~98 % of injection cost (~1.7 µs/var) and is
coroutine-unsafe under OpenSwoole. Therefore `getenv()` does **not** see `.env`
values.

```php
$secret = env('JWT_SECRET', '');        // ✅ correct
$secret = getenv('JWT_SECRET') ?: '';   // ✗ empty for any .env-provided key
```

`useProcessEnv(true)` enables process-env mirroring — only for a third-party SDK
that reads the OS env directly (AWS/Vault).

`useCache(true)` (or `ENV_CACHE=1`) writes a compiled `var/cache/env.<scope>.php`
that opcache serves, stat-invalidated by mtime+size of every examined file. Off
by default: under OpenSwoole env loads once per worker anyway, and in dev the 1 s
mtime granularity is unsafe for a rapidly-edited `.env`. `reset()` is for tests.

---

## `ErrorGuard` — the outer error net

`install(?string $logFile = null, bool $registerHandlers = true): void`

```
ErrorGuard (SAPI-level, pre-kernel)  ── outer net ── pre-kernel throws + PHP fatals/parse/OOM
   └── Kernel ErrorStage/ErrorPipeline ── inner net ── Throwables inside a running pipeline
```

- Forces `display_errors=off` in production and renders a generic, secret-free
  500; in debug it renders the kernel's dependency-free `DebugPageRenderer` page.
- Catches what the kernel never can — a throw during bootstrap (e.g. the `APP_KEY`
  guard), parse errors, fatals, OOM.
- Both layers write to ONE log, `{project}/var/logs/errors.log`; the guard's JSON
  line is tagged `source=error_guard`. It never calls the ErrorPipeline (no global
  singletons) — the shared log file is the only connection.
- The debug page renders ONLY for real browser navigations; API/AJAX/JSON callers
  always get JSON. It never renders in production.
- `registerHandlers: false` installs ini settings only — used under OpenSwoole.
  `debugEnabled()` reports the gate; `capture(ErrorContext)` feeds it a context;
  `reset()` is for tests.

---

## Rules

```
✓ env → error guard → kernel. That order, in every entry point.
✓ DomainContext rides on the immutable Request; read it with $request->attribute('domain').
✓ env() everywhere in first-party code — $_ENV is the source of truth, putenv() is not called.
✓ Project names from JSON/env are sanitised before touching the filesystem.
✗ Binding DomainContext into CoreContainer or ModuleContainer — coroutine leak.
✗ Reading $_SERVER['HTTP_HOST'] inside a module to get the host — use the DomainContext.
✗ Hard-coding project names in modules — read DomainContext->name.
✗ Loading env or wiring the error net inside the kernel — both are Project layer.
✗ Rendering DebugPageRenderer without an APP_DEBUG gate, or returning HTML to a JSON client.
✗ Mutating the resolver cache at runtime in production — registries are deploy-time artifacts.
```
