import "./styles/index.css";
import { createRoot, hydrateRoot } from "react-dom/client";
import { createPageflowApp } from "@pageflow/react";
import { ThemeProvider } from "@providers/theme";

// ── Project (public) surface bootstrap ───────────────────────────────────────
// Same Pageflow protocol as the admin surface, but its OWN entry + Pages tree,
// so it builds to /build/project/ and can be deployed on the public host. This
// surface `hydrateRoot`s when the server pre-rendered markup into #app (SSR/SSG),
// falling back to a fresh `createRoot` otherwise — good default for a public,
// SEO-facing app.

// Own pages first (project overrides), then public pages contributed by any
// federated plugin (frontend/plugins/*/site/Pages/**).
const pages = {
  ...import.meta.glob("./Pages/**/*.tsx"),
  ...import.meta.glob("/plugins/*/site/Pages/**/*.tsx"),
} as Record<string, () => Promise<any>>;
const CHUNK_RELOAD_KEY = "__hkm_chunk_reload__";

async function resolveComponent(name: string) {
  const key = Object.keys(pages).find((k) => k.endsWith(`/${name}.tsx`));
  if (!key) throw new Error(`Page "${name}" not found under Pages/`);
  try {
    const mod = await pages[key]();
    sessionStorage.removeItem(CHUNK_RELOAD_KEY);
    return mod.default ?? mod;
  } catch (err) {
    if (!sessionStorage.getItem(CHUNK_RELOAD_KEY)) {
      sessionStorage.setItem(CHUNK_RELOAD_KEY, "1");
      window.location.reload();
    }
    throw err;
  }
}

const el = document.getElementById("app")!;
// Boot from EITHER mode: the current layout embeds the page object in the root
// element's data-page attribute; the legacy layout publishes it as the
// window.initialPage global (see plugins/Pageflow/resources/layouts/app.php).
// Prefer data-page, fall back to the legacy global, then an empty object.
const legacyPage = (window as unknown as { initialPage?: unknown }).initialPage;
const initialPage = el.dataset.page
  ? JSON.parse(el.dataset.page)
  : (legacyPage ?? {});

createPageflowApp({
  page: initialPage,
  resolve: resolveComponent,
  setup({ el, App, props }: { el: HTMLElement; App: any; props: any }) {
    const tree = (
      <ThemeProvider>
        <App {...props} />
      </ThemeProvider>
    );
    // Hydrate server-rendered HTML when present; otherwise mount fresh.
    if (el.hasChildNodes()) {
      hydrateRoot(el, tree);
    } else {
      createRoot(el).render(tree);
    }
  },
  progress: { delay: 100, color: "#0ea5e9" },
});
