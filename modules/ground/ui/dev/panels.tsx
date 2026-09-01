import { useMemo, useState } from "react";
import { router } from "@pageflow/react";
import { getAllFeatures } from "@pageflow/admin";
import type { GroundManifest, GroundPage, GroundRoute } from "./types";
import type { GroundState } from "./state";

// ── Shared bits ───────────────────────────────────────────────────────────────

function Search({ value, onChange, placeholder }: { value: string; onChange: (v: string) => void; placeholder: string }) {
  return (
    <input
      value={value}
      onChange={(e) => onChange(e.target.value)}
      placeholder={placeholder}
      className="mb-2 w-full rounded border border-neutral-700 bg-neutral-900 px-2 py-1 text-xs text-neutral-100 placeholder:text-neutral-500 focus:border-neutral-500 focus:outline-none"
    />
  );
}

function Empty({ children }: { children: React.ReactNode }) {
  return <p className="px-1 py-6 text-center text-xs text-neutral-500">{children}</p>;
}

const METHOD_TONE: Record<string, string> = {
  GET: "text-emerald-400",
  POST: "text-sky-400",
  PUT: "text-amber-400",
  PATCH: "text-amber-400",
  DELETE: "text-rose-400",
};

// ── Pages ─────────────────────────────────────────────────────────────────────

/**
 * Every page the plugin ships, whether or not a route reaches it.
 *
 * That last part is the reason this exists. A page with no route is
 * unreachable in a browser — there is no URL to type — so the only way to look
 * at one was to add a throwaway route to module.json. Here it is one click,
 * because Pageflow can be told to swap the component directly.
 */
export function PagesPanel({ pages, current }: { pages: GroundPage[]; current: string }) {
  const [query, setQuery] = useState("");

  const shown = useMemo(() => {
    const needle = query.trim().toLowerCase();
    return needle === "" ? pages : pages.filter((p) => p.component.toLowerCase().includes(needle));
  }, [pages, query]);

  if (pages.length === 0) {
    return <Empty>This plugin ships no pages under admin/Pages or site/Pages.</Empty>;
  }

  return (
    <div>
      <Search value={query} onChange={setQuery} placeholder="Filter pages…" />
      <ul className="space-y-0.5">
        {shown.map((page) => {
          const active = page.component === current;

          return (
            <li key={page.face + "/" + page.component}>
              <button
                type="button"
                disabled={page.path === null}
                title={page.path ?? "No route renders this page — nothing to visit."}
                onClick={() => page.path !== null && router.visit(page.path)}
                className={
                  "flex w-full items-center justify-between gap-2 rounded px-2 py-1 text-left text-xs " +
                  (active
                    ? "bg-neutral-700 text-neutral-50"
                    : page.path === null
                      ? "cursor-not-allowed text-neutral-600"
                      : "text-neutral-300 hover:bg-neutral-800")
                }
              >
                <span className="truncate">{page.component}</span>
                <span className="shrink-0 text-[10px] uppercase tracking-wide text-neutral-500">
                  {page.path === null ? "no route" : page.face}
                </span>
              </button>
            </li>
          );
        })}
        {shown.length === 0 && <Empty>No page matches “{query}”.</Empty>}
      </ul>
    </div>
  );
}

// ── Routes ────────────────────────────────────────────────────────────────────

/**
 * The plugin's routes, with every group and module prefix already applied.
 *
 * A dynamic path is listed but not clickable: `/users/{id:num}` is not a URL,
 * and a bench that navigated to the literal string would 404 and look like the
 * route was broken.
 */
export function RoutesPanel({ routes, current }: { routes: GroundRoute[]; current: string }) {
  const [query, setQuery] = useState("");

  const shown = useMemo(() => {
    const needle = query.trim().toLowerCase();
    return needle === ""
      ? routes
      : routes.filter((r) => (r.path + " " + r.method + " " + (r.name ?? "")).toLowerCase().includes(needle));
  }, [routes, query]);

  if (routes.length === 0) return <Empty>module.json declares no routes.</Empty>;

  return (
    <div>
      <Search value={query} onChange={setQuery} placeholder="Filter routes…" />
      <ul className="space-y-0.5">
        {shown.map((route) => {
          const visitable = !route.dynamic && route.method === "GET";

          return (
            <li key={route.method + " " + route.path}>
              <button
                type="button"
                disabled={!visitable}
                title={
                  route.dynamic
                    ? "Takes a parameter — not a URL that can be visited as written."
                    : route.method !== "GET"
                      ? `${route.method} — the bench only navigates with GET.`
                      : route.path
                }
                onClick={() => visitable && router.visit(route.path)}
                className={
                  "w-full rounded px-2 py-1 text-left text-xs " +
                  (route.path === current
                    ? "bg-neutral-700"
                    : visitable
                      ? "hover:bg-neutral-800"
                      : "cursor-not-allowed opacity-60")
                }
              >
                <span className="flex items-baseline gap-2">
                  <span className={"shrink-0 font-mono text-[10px] " + (METHOD_TONE[route.method] ?? "text-neutral-400")}>
                    {route.method}
                  </span>
                  <span className="truncate text-neutral-300">{route.path}</span>
                </span>
                {(route.filters.length > 0 || route.requires.length > 0) && (
                  <span className="mt-0.5 flex flex-wrap gap-1">
                    {route.filters.map((f) => (
                      <span key={f} className="rounded bg-neutral-800 px-1 text-[10px] text-neutral-400">
                        {f}
                      </span>
                    ))}
                    {route.requires.map((r) => (
                      <span key={r} className="rounded bg-neutral-800 px-1 text-[10px] text-sky-300/70">
                        {r}
                      </span>
                    ))}
                  </span>
                )}
              </button>
            </li>
          );
        })}
        {shown.length === 0 && <Empty>No route matches “{query}”.</Empty>}
      </ul>
    </div>
  );
}

// ── The page object ───────────────────────────────────────────────────────────

/**
 * `{component, url, props}` — the server's actual contract with the client.
 *
 * Ground's whole testing doctrine is to assert on the page object and never on
 * markup, because the markup belongs to a component a designer may rewrite this
 * afternoon while the props are the thing the server promised. Showing it here
 * is that doctrine made visible: the prop you cannot find in this tree is the
 * one the page is about to render as `undefined`.
 */
export function InspectPanel({ page }: { page: { component?: string; url?: string; props?: unknown } }) {
  const props = (page.props ?? {}) as Record<string, unknown>;
  const names = Object.keys(props);

  return (
    <div className="space-y-3 text-xs">
      <dl className="space-y-1">
        <Row label="component" value={page.component ?? "—"} />
        <Row label="url" value={page.url ?? "—"} />
        <Row label="props" value={names.length === 0 ? "(none)" : `${names.length} key(s)`} />
      </dl>

      {names.map((name) => (
        <PropNode key={name} name={name} value={props[name]} />
      ))}
    </div>
  );
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex gap-2">
      <dt className="w-20 shrink-0 text-neutral-500">{label}</dt>
      <dd className="truncate font-mono text-neutral-200" title={value}>
        {value}
      </dd>
    </div>
  );
}

/** One prop, collapsed. Objects and arrays open on demand rather than by default:
 *  a page with a 200-row table would otherwise fill the panel with its own data. */
function PropNode({ name, value }: { name: string; value: unknown }) {
  const [open, setOpen] = useState(false);
  const complex = value !== null && typeof value === "object";

  const summary = complex
    ? Array.isArray(value)
      ? `Array(${value.length})`
      : `{${Object.keys(value as object).length}}`
    : JSON.stringify(value);

  return (
    <div className="rounded border border-neutral-800">
      <button
        type="button"
        onClick={() => complex && setOpen((o) => !o)}
        className="flex w-full items-baseline justify-between gap-2 px-2 py-1 text-left hover:bg-neutral-800/60"
      >
        <span className="truncate font-mono text-neutral-300">{name}</span>
        <span className="shrink-0 font-mono text-[10px] text-neutral-500">
          {complex ? (open ? "▾ " : "▸ ") : ""}
          {summary}
        </span>
      </button>
      {open && complex && (
        <pre className="max-h-64 overflow-auto border-t border-neutral-800 px-2 py-1 font-mono text-[10px] leading-relaxed text-neutral-400">
          {safeStringify(value)}
        </pre>
      )}
    </div>
  );
}

/** Props reach here straight off the wire, so a cycle is not impossible and a
 *  throw inside the inspector would take down the page it is inspecting. */
function safeStringify(value: unknown): string {
  const seen = new WeakSet<object>();

  try {
    return JSON.stringify(
      value,
      (_key, val) => {
        if (val !== null && typeof val === "object") {
          if (seen.has(val)) return "[circular]";
          seen.add(val);
        }
        return val;
      },
      2,
    );
  } catch (error) {
    return `[not serialisable: ${(error as Error).message}]`;
  }
}

// ── adminShell ────────────────────────────────────────────────────────────────

/**
 * Fake the `adminShell` shared prop the server does not send yet.
 *
 * No PHP anywhere writes `adminShell`, so `useAdminShell()` returns a guest with
 * no features, `selectNavSections([])` returns nothing, and the sidebar renders
 * permanently empty. That is not a bug you can see by looking harder — there is
 * no state of the application in which it fills up.
 *
 * So the bench supplies one. The overrides are merged over the server's value
 * (which stays authoritative when it exists) and only for this browser, so
 * nothing here can be mistaken for the server having been fixed.
 */
export function ShellPanel({
  shell,
  onChange,
}: {
  shell: GroundState["shell"];
  onChange: (next: GroundState["shell"]) => void;
}) {
  // getModules() filters by the ENABLED set, so with nothing enabled it returns
  // empty even when every module registered correctly — it would report "none
  // registered" for the exact state this panel exists to fix. The feature
  // registry is the honest signal: it is populated at import time and does not
  // depend on what is switched on.
  const features = getAllFeatures();
  const active = (shell?.features as string[] | undefined) ?? [];

  const set = (patch: Record<string, unknown>) => onChange({ ...(shell ?? {}), ...patch });

  const toggle = (id: string) =>
    set({ features: active.includes(id) ? active.filter((f) => f !== id) : [...active, id] });

  return (
    <div className="space-y-3 text-xs">
      <Field
        label="displayName"
        value={((shell?.user as { displayName?: string } | undefined)?.displayName) ?? ""}
        onChange={(v) => set({ user: { ...(shell?.user ?? { id: "ground" }), displayName: v } })}
      />
      <Field
        label="tenant"
        value={((shell?.tenant as { name?: string } | undefined)?.name) ?? ""}
        onChange={(v) => set({ tenant: v === "" ? null : { id: "ground", name: v } })}
      />

      <div>
        <p className="mb-1 text-neutral-500">features</p>
        {features.length === 0 ? (
          <p className="text-neutral-500">
            No nav module is registered. A plugin contributes one from{" "}
            <code className="text-neutral-400">ui/admin/nav.ts</code>; the bench imports that file
            if it exists.
          </p>
        ) : (
          <ul className="space-y-0.5">
            {features.map((feature) => (
              <li key={feature.id}>
                <label className="flex cursor-pointer items-center gap-2 rounded px-1 py-0.5 hover:bg-neutral-800">
                  <input
                    type="checkbox"
                    checked={active.includes(feature.id)}
                    onChange={() => toggle(feature.id)}
                    className="accent-emerald-500"
                  />
                  <span className="truncate text-neutral-300">{feature.label ?? feature.id}</span>
                </label>
              </li>
            ))}
          </ul>
        )}
      </div>

      {shell !== null && (
        <button
          type="button"
          onClick={() => onChange(null)}
          className="w-full rounded border border-neutral-700 px-2 py-1 text-neutral-400 hover:bg-neutral-800"
        >
          Clear overrides — use what the server sent
        </button>
      )}
    </div>
  );
}

function Field({ label, value, onChange }: { label: string; value: string; onChange: (v: string) => void }) {
  return (
    <label className="block">
      <span className="mb-1 block text-neutral-500">{label}</span>
      <input
        value={value}
        onChange={(e) => onChange(e.target.value)}
        className="w-full rounded border border-neutral-700 bg-neutral-900 px-2 py-1 text-neutral-100 focus:border-neutral-500 focus:outline-none"
      />
    </label>
  );
}
