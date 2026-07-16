# Tenancy plugin UI — admin + tenant pages

The Tenancy plugin ships a Pageflow (React) UI with pages for **both faces**:

```
plugins/Tenancy/ui/
├─ ui.json                    alias "@tenancy" + surfaces map { admin: admin/Pages, site: site/Pages }
├─ index.ts                   barrel — exposes shared bits + the API client as @tenancy
├─ lib/client.ts              typed /ajx client (CSRF + envelope handling)
├─ components/
│   ├─ TenantBadge.tsx        shared tenant identity chip
│   └─ StatusBadge.tsx        shared status pill (active/verified/pending/…)
├─ admin/Pages/Tenant/        ADMIN surface pages (platform-admin control plane)
│   ├─ Manage.tsx             component "Tenant/Manage"  (fleet list + delete)
│   ├─ Create.tsx             component "Tenant/Create"  (provision a tenant)
│   └─ Edit.tsx               component "Tenant/Edit"    (name/slug/status)
└─ site/Pages/Tenant/         TENANT surface pages
    ├─ Index.tsx              component "Tenant/Index"   (tenant picker)
    └─ Hosts.tsx              component "Tenant/Hosts"   (custom domains)
```

## How it reaches a project

1. **Federation** — `hkm ui sync` mirrors this `ui/` into the project's
   `frontend/plugins/tenancy/` and adds the `@tenancy` alias to
   `tsconfig.plugins.json`.
2. **Per-face discovery** — each surface globs the plugin pages for its face, so
   there is no per-page wiring:
   - admin surface: `import.meta.glob("../../../plugins/*/admin/Pages/**/*.tsx")`
   - site surface:  `import.meta.glob("../../../plugins/*/site/Pages/**/*.tsx")`
   Project pages are spread first, so a project can override a plugin page.
3. **Server** — the plugin's `TenantPageController` renders the component names:
   `render($request, 'Tenant/Index'|'Tenant/Manage'|'Tenant/Create'|'Tenant/Edit'|'Tenant/Hosts', …)`.
   Its page routes live in `module.json` with `requires: ["http.pageflow"]`.

## Routes (module.json)

| Method · Path | Face | Component |
|---|---|---|
| GET `/tenants` | site | `Tenant/Index` |
| GET `/tenant/hosts` | site | `Tenant/Hosts` |
| GET `/tenants/manage` | admin | `Tenant/Manage` |
| GET `/tenants/create` | admin | `Tenant/Create` |
| GET `/tenants/{tenantId}/edit` | admin | `Tenant/Edit` |

`/tenants/manage|create|{id}/edit` render through the **admin** surface; the
tenant-facing `/tenants` and `/tenant/hosts` through the **site** surface.

Every page here is private control-plane surface, so `TenantPageController`
passes the reserved `seoHead` prop built with `seoPrivate()` (branded title +
`noindex, nofollow`). Pages must NOT set `<Head title>` — the client syncs the
tab title from `seoHead` on every navigation (see `plugins/Pageflow/README.md`).

## Data flow

The page shells carry no data props (except `Tenant/Edit`, which gets
`{ tenantId }`). Every page **hydrates over the JSON API** using the typed
`@tenancy` client (`lib/client.ts`), a TypeScript port of the plugin's original
vanilla `TenancyApp` client:

- picker: `GET /ajx/me/tenants`, `POST /ajx/tenants/{id}/select`
- fleet:  `GET|POST|PUT|DELETE /ajx/admin/tenants[/{id}]` (platform-admin)
- hosts:  `GET|POST /ajx/tenant/hosts`, `POST …/{id}/verify|primary`, `DELETE …/{id}`

Auth is same-site: the browser sends the session cookie automatically; unsafe
requests carry the kernel CSRF token (read from the `<meta name="csrf-token">`
tag Pageflow embeds in the shell) in the `X-CSRF-Token` header.

> The former server-rendered PHP views under `resources/views/` are superseded by
> these federated pages. They remain in the repo as a zero-JS-build fallback but
> are no longer wired — the page routes now render Pageflow components.
