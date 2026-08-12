# How the frontend works

A per-project frontend built on three ideas:

1. **Surfaces** — each buildable app (admin, project, …) is a self-describing
   folder under `src/surfaces/*`. Vite discovers them; there is no mode registry.
2. **Plugin UI federation** — plugin front-ends (Pageflow, …) are mirrored in by
   `hkm ui` into `frontend/plugins/` and reached through aliases like
   `@pageflow/react`. Never vendored/copied by hand.
3. **Pageflow** — the server (PHP) returns a page object `{ component, props,
   url, version }`; the React client swaps components without full reloads. The
   HTML shell's `<script>`/`<link>` tags are resolved by the **ViteManifest**
   PHP plugin (`vite()` helper) — dev-server URLs under HMR, hashed assets in
   prod.

```
  PHP route ──▶ Controller ──▶ PageflowResponder.render('Shop/Home', props)
                                        │
                    full load ─────────┤────────── XHR navigation
                        ▼                                  ▼
              HTML shell + vite()                JSON { component, props }
              (ViteManifest tags)                        │
                        ▼                                  ▼
        surface index.tsx boots ──▶ resolve 'Shop/Home' ──▶ Pages/Shop/Home.tsx
```

---

## Command usage

### One-time / whenever plugins change

```bash
hkm ui init [project]     # scaffold frontend/ from the template + federate plugin UIs
hkm ui sync  [project]     # re-mirror enabled plugins' ui/ + regenerate glue
hkm ui list  [project]     # list enabled plugins that ship a UI
hkm ui link  <plugin>      # symlink a plugin ui/ for live co-development
hkm ui clean [project]     # remove generated mirrors + glue
```

Run `hkm ui sync` after `hkm plugins enable|disable <plugin>` so the federated
`frontend/plugins/` and `tsconfig.plugins.json` stay in step.

### Day-to-day (inside `frontend/`)

```bash
npm install                       # once
npm run dev -- --mode admin       # dev server for the admin surface (HMR)
npm run dev -- --mode project     # dev server for the public surface
npm run build -- --mode admin     # build one surface → ../public_html/build/admin/
npm run build:all                 # build every discovered surface
npm run surfaces                  # list discovered surfaces
```

Each build writes `../public_html/build/<surface>/[name].[hash].js` +
`manifest-<surface>.json`; dev writes `../public_html/<surface>-hot`. The PHP
`vite()` helper reads those, so the shell auto-switches between dev and prod.

### Adding a whole new surface (a new app)

```bash
cp -r src/surfaces/admin src/surfaces/storefront
# edit src/surfaces/storefront/surface.json → "name": "storefront"
npm run dev -- --mode storefront
```

No `vite.config.ts` or npm-script edits — it's discovered.

---

## Adding a page

A "page" is a **React component** whose file name (relative to the surface's
`Pages/`) is the **component key** the server sends. `Pages/Shop/Home.tsx`
→ component `"Shop/Home"`. Two halves: the React page + the PHP route that
renders it.

### On the ADMIN surface

**1. Create the page** — `src/surfaces/admin/Pages/Reports/Sales.tsx`:

```tsx
import { usePage, Head } from "@pageflow/react";
import { Button } from "@ui/button";           // shared shadcn kit

interface SalesProps { total: number; rows: { day: string; amount: number }[] }

export default function Sales() {
  const { props } = usePage<SalesProps>();
  return (
    <>
      <Head title="Sales" />
      <main className="mx-auto max-w-3xl p-8">
        <h1 className="text-2xl font-semibold">Sales — ${props.total}</h1>
        {/* … render props.rows … */}
        <Button className="mt-4">Export</Button>
      </main>
    </>
  );
}
```

The file name decides the key: `Pages/Reports/Sales.tsx` → **`Reports/Sales`**.

**2. Add the server route** that renders it. In a plugin this lives in the
plugin's `module.json`; for a project route, add it to `proj.json` `routes[]`
with `requires: ["http.pageflow"]`:

```jsonc
// proj.json
{ "method": "GET", "path": "/admin/reports/sales",
  "handler": "Shop\\Infrastructure\\Http\\ReportsController@sales",
  "requires": ["http.pageflow"] }
```

**3. Controller** returns the component name + props:

```php
public function sales(): Response
{
    return $this->pageflow->render($this->request, 'Reports/Sales', [
        'total' => 1234,
        'rows'  => [['day' => 'Mon', 'amount' => 200]],
    ]);
}
```

Navigate to it in-app with `<Link href="/admin/reports/sales">` — no full reload.

### On the PROJECT (public) surface

Identical pattern, different `Pages/` tree. **1.** Create
`src/surfaces/project/Pages/Pricing.tsx`:

```tsx
import { usePage, Head, Link } from "@pageflow/react";

interface PricingProps { plans: { name: string; price: number }[] }

export default function Pricing() {
  const { props } = usePage<PricingProps>();
  return (
    <>
      <Head title="Pricing" />
      <main className="mx-auto max-w-4xl px-4 py-16">
        <h1 className="text-3xl font-bold">Pricing</h1>
        <div className="mt-8 grid gap-6 sm:grid-cols-3">
          {props.plans.map((p) => (
            <div key={p.name} className="rounded-lg border border-border p-6">
              <h3 className="font-semibold">{p.name}</h3>
              <p className="mt-2 text-2xl">${p.price}/mo</p>
            </div>
          ))}
        </div>
        <Link href="/" className="mt-8 inline-block hover:underline">← Home</Link>
      </main>
    </>
  );
}
```

File name `Pages/Pricing.tsx` → component **`Pricing`**.

**2.** Route in `proj.json` (public path, still Pageflow):

```jsonc
{ "method": "GET", "path": "/pricing",
  "handler": "Shop\\Infrastructure\\Http\\SiteController@pricing",
  "requires": ["http.pageflow"] }
```

**3.** Controller:

```php
public function pricing(): Response
{
    return $this->pageflow->render($this->request, 'Pricing', [
        'plans' => [['name' => 'Starter', 'price' => 9], ['name' => 'Pro', 'price' => 29]],
    ]);
}
```

### Which surface serves a route?

Admin and project are separate builds. The **HTML shell** the responder renders
(`PAGEFLOW_ROOT_VIEW`, e.g. `resources/layouts/pageflow.php`) decides which
surface entry loads via `vite('src/surfaces/<surface>/index.tsx', '<surface>')`.
To serve two surfaces from one app, use two shells (one per surface/host) — each
calling `vite()` with its own surface name. Admin routes point at the admin
shell, public routes at the project shell.

---

## Pages contributed by a plugin (both faces)

A plugin can ship pages for **both** the admin and the public surface. It lays
them out by face and declares them in `ui/ui.json`:

```
plugins/<Name>/ui/
├─ ui.json            { "alias": "@name", "surfaces": { "admin": "admin/Pages", "site": "site/Pages" } }
├─ admin/Pages/**     → admin-surface pages (e.g. component "User/Index")
└─ site/Pages/**      → public-surface pages (e.g. component "User/Register")
```

`hkm ui sync` federates them into `frontend/plugins/<name>/`, and each surface
entry globs the pages **for its face** (project pages first, so the project can
override a plugin page):

```ts
// admin surface index.tsx
const pages = {
  ...import.meta.glob("./Pages/**/*.tsx"),
  ...import.meta.glob("../../../plugins/*/admin/Pages/**/*.tsx"),
};
// public surface globs ".../plugins/*/site/Pages/**"
```

The plugin's own controller renders the component names (`render('User/Index', …)`),
with routes in its `module.json` (`requires: ["http.pageflow", "<its-domain>"]`).
One Pageflow shell serves both surfaces by picking by URL face:

```php
$surface = str_starts_with($FLOW_PAGE->url, '/admin') ? 'admin' : 'shop';
echo vite("src/surfaces/{$surface}/index.tsx", $surface);
```

See `plugins/User/ui/README.md` for a complete worked example (admin list/detail
+ public register/profile), including the auth + permission notes.

## The building blocks you'll use in a page

| Import | From | Use |
|---|---|---|
| `usePage<T>()` | `@pageflow/react` | read `{ props, url, component }` the server sent |
| `Head` | `@pageflow/react` | `<title>` + meta (SEO) |
| `Link` | `@pageflow/react` | in-app navigation (no full reload); `only`, `as`, `preserveScroll` |
| `useForm` | `@pageflow/react` | forms with CSRF, `processing`, `errors` |
| `router` | `@pageflow/react` | imperative visits / partial reloads (`only: [...]`) |
| `AdminLayout`, `useSetPageHeader`, `ResourceListShell`, `DataTable` | `@pageflow/admin` | the admin shell + kit (see below) |
| `Button`, `Dialog`, … | `@ui/*` | the shared shadcn design system (49 components) |
| `cn` | `@lib/utils` | Tailwind class merge |
| `useTheme`, `ThemeProvider` | `@providers/theme` | light / dark / system |

---

## The admin shell — `@pageflow/admin`

A third Pageflow entry point (beside `core` and `react`) carrying the admin
shell, the navigation registry and the domain-free building blocks. Nothing in
`@pageflow/core` or `@pageflow/react` imports it, so a public-only surface never
bundles it.

### Attach the layout as a PERSISTENT layout

```tsx
import type { ReactNode } from "react";
import { AdminLayout, useSetPageHeader } from "@pageflow/admin";

export default function Sales() {
  useSetPageHeader({ title: "Sales", actions: [{ label: "Export", onClick: exportCsv }] });
  return <main>…</main>;
}

Sales.layout = (page: ReactNode) => <AdminLayout>{page}</AdminLayout>;
```

Pageflow applies `Component.layout` **outside** the swapped page, so the shell
survives navigation — sidebar scroll, open menus and the nav overflow
calculation are all preserved. Wrapping the page's own return instead remounts
the entire sidebar on every click.

`AdminLayout` takes no data props: it reads the reserved **`adminShell`** shared
prop (user, tenant, switchable tenants, feature flags, logout/account/settings
URLs). Share it once server-side and every page has it.

`AuthLayout` is the nav-free equivalent for login / register / consent pages.

### Contribute a sidebar section from a plugin

Each plugin declares its own navigation in `ui/admin/nav.ts`; the admin surface
globs `/plugins/*/admin/nav.ts`, so the registry never names a business domain:

```ts
import { Building2 } from "lucide-react";
import { registerModule, registerFeature } from "@pageflow/admin";

registerFeature({ id: "rental", label: "Rental management" });

registerModule({
  id: "rental",
  sectionLabel: "Rental",
  order: 40,
  features: ["rental"],                 // hidden unless proj.json enables it
  items: [{ id: "properties", label: "Properties", icon: Building2,
            path: "/admin/rental/properties" }],
});
```

Visibility is driven by the server: `proj.json` `features[]` →
`DomainContext->features` → `adminShell.features`. A flag matching nothing logs a
warning naming it rather than silently doing nothing.

Full reference — every export, the settings-tab registry, the list/table kit:
`plugins/hkm-plugin-pageflow/ui/admin/README.md`.

Add more shadcn components with `npx shadcn add <name>` (writes into
`src/shared/ui/`, driven by `components.json`).

---

## Cheatsheet

```
Add a page      →  drop Pages/<Dir>/<Name>.tsx  +  proj.json route → render('<Dir>/<Name>', props)
Add a surface   →  cp -r src/surfaces/admin src/surfaces/<name>  (edit surface.json name)
New shadcn part →  npx shadcn add card
Enable a plugin →  hkm plugins enable <name>  &&  hkm ui sync
Dev / build     →  npm run dev -- --mode <surface>  /  npm run build:all
```
