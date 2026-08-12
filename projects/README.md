# `projects/` — The Project Layer

> Namespace `Project\` → `projects/` (composer psr-4 `"Project\\": "projects/"`).

The third of the Three Worlds:

```
┌─────────────────────────────────────────────────┐
│  PROJECT LAYER  (wiring only — no business logic)│
│  ┌─────────────────────────────────────────────┐ │
│  │  MODULE / PLUGIN LAYER (bounded domains)    │ │
│  │  ┌───────────────────────────────────────┐  │ │
│  │  │  KERNEL (boot, security, DI, ports)   │  │ │
│  │  └───────────────────────────────────────┘  │ │
│  └─────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────┘
```

The project **knows everything and contains no business logic**. It decides which
project is served, which plugins are active, which port implementations back the
kernel's interfaces, and how output is shaped — nothing more. Domain logic lives
in `plugins/`.

| Where | For |
|---|---|
| `src/` (repo root) | Kernel internals — never project or business logic |
| `plugins/` | Reusable business modules (full GDA, `Plugins\` namespace) |
| `projects/` | Wiring, adapters, shared project-layer support (`Project\`) |
| `projects/<name>/src/` | Logic for ONE project only (`Projects\<Name>\`) — not reusable |

---

## Contents

| Path | Namespace | Role | Doc |
|---|---|---|---|
| `Bootstrap/` | `Project\Bootstrap\` | Entry-point helpers, domain resolution, env loading, pre-kernel error net | [README](Bootstrap/README.md) |
| `Http/Controllers/` | `Project\Http\Controllers\` | Base controllers (`ApiController`, `ViewController`) + concern traits | [README](Http/Controllers/README.md) |
| `Infrastructure/` | `Project\Infrastructure\` | Dependency-free port adapters (PDO, file cache/queue/lock, lazy ports) | [README](Infrastructure/README.md) |
| `Support/` | `Project\Support\` | DI-free helpers: `Arr`, `Str`, `Collection`, `Resource`, casting, hydration, `Entity`, SEO | [README](Support/README.md) |
| `platform.json` | — | Subdomain registry: which subdomains are the admin / api face | [Bootstrap README](Bootstrap/README.md) |
| `projects.json` | — | Project registry: name → version, path, `domains[]` | [Bootstrap README](Bootstrap/README.md) |
| `<name>/` | `Projects\<Name>\` | A registered project: `bootstrap/`, `config/`, `src/`, `app/`, `database/`, `var/`, `userdata/` | `docs/ai-context/11_PROJECT.md` |

Sub-docs for the deeper Support areas:
[`Support/Casting/README.md`](Support/Casting/README.md) ·
[`Support/Entity/README.md`](Support/Entity/README.md) ·
[`Support/Seo/README.md`](Support/Seo/README.md).

A registered project may live **outside this directory** — `projects.json` records
an absolute `path`, so a standalone project created with `hkm new` is booted from
its own tree (`EntryHelpers::bootstrapPathForContext()` handles both layouts).

---

## Registries

```jsonc
// projects/platform.json — which subdomain means which face
{ "subdomains": { "admin": ["app", "admin"], "api": ["api"] } }

// projects/projects.json — host → project
{
  "shop": {
    "name": "shop", "version": "1.0.0",
    "path": "/abs/path/to/psp-shop",
    "domains": ["shop.com"]
  }
}
```

Both are **deploy-time artifacts**: `DomainResolver` caches them per worker and
never invalidates on the hot path. A redeploy spawns new workers.

---

## Per-project layout

| Path | Role |
|---|---|
| `projects/<name>/bootstrap/app.php` | Extends the shared base builder, adds this project's modules/ports, calls `->build()` |
| `projects/<name>/proj.json` | `features[]`, `routes[]`, `essentials[]`, `routePolicy.disable[]` |
| `projects/<name>/config/` | Project configuration files (deep-merged over plugin config at boot) |
| `projects/<name>/src/` | Project-only PHP under `Projects\<Name>\` — wiring glue, project-only services/listeners/commands |
| `projects/<name>/app/` | Project-local entry points for a standalone deploy (docroot = `app/public_html`) |
| `projects/<name>/database/` | Project migrations / seeders / factories (LetMigrate) |
| `projects/<name>/var/` | Ephemeral runtime: logs, cache, compiled manifests, tmp, locks |
| `projects/<name>/userdata/` | Persisted tenant data: uploads, reports, exports |

`Paths::var()/logs()/cache()/userdata()/config()` resolve under the project root
when `withProjectPath()` is set, and under the base roots otherwise.

---

## What the project layer decides

1. **Which project** serves a request — `DomainResolver` (Host header) →
   `HKM_PROJECT` → `'admin'`.
2. **Which ports** back the kernel interfaces — `->withPorts([...])` with the
   adapters in `Infrastructure/` (or a plugin's).
3. **Which plugins** are active — `->withModules([...])`, plus
   `->withEssentialModules([...])` / `proj.json` `essentials[]` for the always-on
   ones.
4. **Which routes exist** — `proj.json` `routes[]` (project routes override plugin
   routes) and `routePolicy.disable[]` (veto a plugin route without forking it).
5. **What the environment is** — the `.env` cascade and the pre-kernel error net.

---

## Rules

```
✓ Wiring only — every business rule belongs to a plugin.
✓ Project resources (routes, views) override plugin resources by default.
✓ Port implementations are bound in bootstrap and injected — never instantiated inside a module.
✓ Support/ classes are DI-free and safe to call from any layer.
✗ Business logic in projects/ — if it is reusable it is a plugin, if it is project-only it goes in projects/<name>/src/.
✗ New local modules under projects/ — use plugins/ with the Plugins\ namespace.
✗ Routes defined in PHP — declare them in proj.json / withRoutes().
✗ DomainContext in a container — it rides on the immutable Request.
✗ Kernel code importing anything from Project\ — the dependency only points inward.
```
