import { useEffect, useMemo, type ComponentType, type ReactNode } from "react";
import { PageContext, usePage } from "@pageflow/react";
import { AdminLayout, AuthLayout } from "@pageflow/admin";
import { PagesPanel, RoutesPanel, InspectPanel, ShellPanel } from "./panels";
import { useGroundState, type LayoutChoice, type PanelChoice, type ViewportChoice } from "./state";
import { setSimulatedWidth, VIEWPORT_WIDTH } from "./viewport";
import type { GroundManifest } from "./types";

export interface GroundFrameProps {
  manifest: GroundManifest;
  /** The page component, read for its `.layout` — NOT rendered by the frame. */
  component: ComponentType & { layout?: unknown };
  /** The page element, already created by the entry. */
  children: ReactNode;
}

/**
 * The bench: dev chrome around a page, and nothing inside it.
 *
 * ─── WHY IT IS A FRAME AND NOT A LAYOUT ─────────────────────────────────────
 *
 * The tempting design is a "ground layout" that REPLACES the admin shell with
 * something friendlier to develop against. It would also destroy the one
 * property that makes this package worth having: `ground serve` runs the real
 * HttpPipeline so that a page rendering here is a page the browser renders. Swap
 * the shell for a bench-specific one and you are looking at chrome no project
 * ships, which is exactly where the interesting bugs hide — the crash that
 * started this frame was `AdminLayout` calling `matchMedia`, and no substitute
 * layout would ever have found it.
 *
 * So the production tree is rendered UNTOUCHED, and the bench is chrome outside
 * it. That also makes the layout a control rather than a decision: `page`
 * (honour the page's own, fall back to the admin shell), `bare`, `admin`,
 * `auth` — the same page, four ways, without editing it.
 *
 * ─── WHY THE CHROME IS `position: fixed` ────────────────────────────────────
 *
 * `AdminLayout` is `h-screen`, i.e. 100vh. Put a toolbar in normal flow above
 * it and the shell is a toolbar's height taller than the window on every page,
 * so the sidebar scrolls when it should not and the bench has introduced a
 * layout bug of its own. Fixed chrome overlays instead: the page keeps the
 * whole viewport and 100vh stays true.
 */
export function GroundFrame({ manifest, component, children }: GroundFrameProps) {
  const page = usePage<Record<string, unknown>>();
  const [state, patch] = useGroundState();

  // The matchMedia shim installs itself when ./viewport is imported — it has to
  // be ahead of the shell's own subscription, and React runs child effects
  // before a parent's. All that is left here is telling it which width to
  // report, and restoring the truth when the bench goes away.
  useEffect(() => {
    setSimulatedWidth(VIEWPORT_WIDTH[state.viewport]);
    return () => setSimulatedWidth(null);
  }, [state.viewport]);

  /**
   * The page object the tree below sees.
   *
   * Overriding `adminShell` through a NESTED PageContext, rather than by
   * mutating the page, is what keeps this honest: `usePage` reads the nearest
   * provider, so the shell picks the override up with no knowledge of the
   * bench, and the real page object is still what the inspector shows and what
   * the next navigation replaces.
   */
  const scoped = useMemo(() => {
    if (state.shell === null) return page;

    const props = (page.props ?? {}) as Record<string, unknown>;
    const server = (props.adminShell ?? {}) as Record<string, unknown>;

    return { ...page, props: { ...props, adminShell: { ...server, ...state.shell } } };
  }, [page, state.shell]);

  /**
   * Which face this page was authored under, from the generated manifest.
   *
   * The layout fallback keys on it, so a `site/Pages` page renders the way a
   * project renders it — bare — instead of wearing admin chrome it never has in
   * production. An unknown component (not one this plugin ships) keeps the
   * admin fallback: the point of having a fallback at all is that a page never
   * opens as unstyled markup, and guessing "site" for something unclassified
   * would reintroduce exactly that.
   */
  const face = useMemo(
    () => manifest.pages.find((candidate) => candidate.component === page.component)?.face ?? "admin",
    [manifest, page.component],
  );

  const shelled = applyLayout(state.layout, component, children, face);
  const width = VIEWPORT_WIDTH[state.viewport];

  return (
    <PageContext.Provider value={scoped as never}>
      <div
        className={width === null ? "contents" : "flex min-h-screen justify-center bg-neutral-950 py-0"}
        style={width === null ? undefined : { colorScheme: "normal" }}
      >
        <div
          className={width === null ? "contents" : "h-screen overflow-hidden border-x border-neutral-800 shadow-2xl"}
          style={width === null ? undefined : { width }}
        >
          {shelled}
        </div>
      </div>

      <Chrome manifest={manifest} page={page} state={state} patch={patch} />
    </PageContext.Provider>
  );
}

/**
 * `page` reproduces what a PROJECT would render, and nothing more.
 *
 * A page's own `.layout` wins, as it does everywhere. Failing that the fallback
 * is face-aware: an `admin/Pages` page gets the admin shell, because opening it
 * as unstyled markup reads like the page is broken rather than like the
 * one-line `.layout` assignment has not been written yet — and a `site/Pages`
 * page gets nothing, because that is what it gets in production. Falling back
 * to the shell on both faces put an admin sidebar and a breadcrumb around
 * /register, which is chrome no deployment of that page has ever had.
 *
 * The other three FORCE a layout. That is why the bar keeps showing which one
 * is active, in amber when it is not `page`: a forced `bare` looks exactly like
 * a page that has lost its layout.
 */
function applyLayout(
    choice: LayoutChoice,
    component: GroundFrameProps["component"],
    child: ReactNode,
    face: string,
): ReactNode {
  if (choice === "bare") return child;
  if (choice === "admin") return <AdminLayout>{child}</AdminLayout>;
  if (choice === "auth") return <AuthLayout>{child}</AuthLayout>;

  const layout = component.layout;

  if (typeof layout === "function") return (layout as (node: ReactNode) => ReactNode)(child);

  // Pageflow also accepts an ARRAY of nested layout components. Reproduced from
  // App's own renderChildren rather than dropped, so a page using that form
  // behaves under the bench exactly as it does in a project.
  if (Array.isArray(layout)) {
    return layout
      .concat(child as never)
      .reverse()
      .reduce((children: ReactNode, Layout: ComponentType<{ children: ReactNode }>) => (
        <Layout>{children}</Layout>
      ));
  }

  return face === "site" ? child : <AdminLayout>{child}</AdminLayout>;
}

// ── The chrome ────────────────────────────────────────────────────────────────

const PANELS: { id: PanelChoice; label: string }[] = [
  { id: "pages", label: "Pages" },
  { id: "routes", label: "Routes" },
  { id: "inspect", label: "Page object" },
  { id: "shell", label: "adminShell" },
];

const LAYOUTS: { id: LayoutChoice; label: string; title: string }[] = [
  {
    id: "page",
    label: "page",
    title:
      "What a project renders: the page's own .layout, else the admin shell for an admin/Pages page and nothing for a site/Pages one",
  },
  { id: "bare", label: "bare", title: "No layout at all" },
  { id: "admin", label: "admin", title: "Force AdminLayout" },
  { id: "auth", label: "auth", title: "Force AuthLayout" },
];

const VIEWPORTS: { id: ViewportChoice; label: string; title: string }[] = [
  { id: "full", label: "▭", title: "The real window" },
  { id: "tablet", label: "▯", title: "Tablet — 834px, and matchMedia answers as one" },
  { id: "mobile", label: "▮", title: "Phone — 390px, so the shell renders its mobile drawer" },
];

function Chrome({
  manifest,
  page,
  state,
  patch,
}: {
  manifest: GroundManifest;
  page: { component?: string; url?: string; props?: unknown };
  state: ReturnType<typeof useGroundState>[0];
  patch: ReturnType<typeof useGroundState>[1];
}) {
  const path = useMemo(() => {
    try {
      return new URL(page.url ?? "/", window.location.origin).pathname;
    } catch {
      return page.url ?? "/";
    }
  }, [page.url]);

  return (
    // z-index above Radix's portalled overlays (they land on document.body at
    // the shell's own stacking level), so the bar is never buried under a Sheet
    // the bench itself asked the page to open.
    <div className="pointer-events-none fixed inset-x-0 bottom-0 z-[2147483000] flex flex-col items-stretch font-sans">
      {state.open && (
        <section className="pointer-events-auto mx-auto mb-1 flex h-[min(60vh,32rem)] w-full max-w-3xl flex-col overflow-hidden rounded-t-lg border border-neutral-800 bg-neutral-900/95 backdrop-blur">
          <nav className="flex shrink-0 gap-1 border-b border-neutral-800 px-2 py-1.5">
            {PANELS.map((p) => (
              <button
                key={p.id}
                type="button"
                onClick={() => patch({ panel: p.id })}
                className={
                  "rounded px-2 py-1 text-xs " +
                  (state.panel === p.id
                    ? "bg-neutral-700 text-neutral-50"
                    : "text-neutral-400 hover:bg-neutral-800")
                }
              >
                {p.label}
              </button>
            ))}
          </nav>

          <div className="min-h-0 flex-1 overflow-auto p-2">
            {state.panel === "pages" && <PagesPanel pages={manifest.pages} current={page.component ?? ""} />}
            {state.panel === "routes" && <RoutesPanel routes={manifest.routes} current={path} />}
            {state.panel === "inspect" && <InspectPanel page={page} />}
            {state.panel === "shell" && <ShellPanel shell={state.shell} onChange={(next) => patch({ shell: next })} />}
          </div>
        </section>
      )}

      <div className="pointer-events-auto flex items-center gap-3 border-t border-neutral-800 bg-neutral-900/95 px-3 py-1.5 text-xs text-neutral-400 backdrop-blur">
        <button
          type="button"
          onClick={() => patch({ open: !state.open })}
          title={state.open ? "Collapse the bench" : "Open the bench"}
          className="rounded px-1.5 py-0.5 font-medium text-neutral-200 hover:bg-neutral-800"
        >
          {state.open ? "▾" : "▴"} ground
        </button>

        <span className="truncate text-neutral-500" title={`${manifest.name} — ${manifest.solves}`}>
          {manifest.name}
        </span>

        <span className="ml-auto flex items-center gap-1">
          <Group>
            {LAYOUTS.map((l) => (
              <Toggle
                key={l.id}
                active={state.layout === l.id}
                title={l.title}
                onClick={() => patch({ layout: l.id })}
                // A forced layout is not the default and must not look like it.
                tone={state.layout === l.id && l.id !== "page" ? "warn" : "normal"}
              >
                {l.label}
              </Toggle>
            ))}
          </Group>

          <Group>
            {VIEWPORTS.map((v) => (
              <Toggle
                key={v.id}
                active={state.viewport === v.id}
                title={v.title}
                onClick={() => patch({ viewport: v.id })}
                tone={state.viewport === v.id && v.id !== "full" ? "warn" : "normal"}
              >
                {v.label}
              </Toggle>
            ))}
          </Group>

          {state.shell !== null && (
            <span
              title="adminShell is being faked by the bench — the server is not sending this."
              className="rounded bg-amber-500/15 px-1.5 py-0.5 text-[10px] text-amber-300"
            >
              shell faked
            </span>
          )}
        </span>
      </div>
    </div>
  );
}

function Group({ children }: { children: ReactNode }) {
  return <span className="flex overflow-hidden rounded border border-neutral-700">{children}</span>;
}

function Toggle({
  active,
  tone,
  title,
  onClick,
  children,
}: {
  active: boolean;
  tone: "normal" | "warn";
  title: string;
  onClick: () => void;
  children: ReactNode;
}) {
  return (
    <button
      type="button"
      title={title}
      onClick={onClick}
      className={
        "px-1.5 py-0.5 text-[11px] " +
        (active
          ? tone === "warn"
            ? "bg-amber-500/20 text-amber-200"
            : "bg-neutral-700 text-neutral-100"
          : "text-neutral-400 hover:bg-neutral-800")
      }
    >
      {children}
    </button>
  );
}
