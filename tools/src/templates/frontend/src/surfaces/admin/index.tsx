import "./styles/index.css";
import { createRoot } from "react-dom/client";
import { createPageflowApp } from "@pageflow/react";
import { ThemeProvider } from "@providers/theme";

// ── Pageflow bootstrap ──────────────────────────────────────────────────────
// The server (Plugins\Pageflow\Http\PageflowResponder) renders a page object
// { component, props, url, version }. @pageflow/* is FEDERATED from the enabled
// Pageflow plugin by `hkm ui sync` — never vendored/copied into this project.

// Every page under ./Pages is a lazily-imported chunk, keyed "Dir/Name".
// Own pages first (project overrides), then pages contributed by any federated
// plugin. This admin surface resolves BOTH the plugins' admin faces AND their
// site faces, so a page authored under a plugin's site/Pages (e.g. User/Register)
// can still be served on the admin surface. Same component-key convention, so
// the server's `render('User/Register', 'admin')` finds it either way.
const pages = {
  ...import.meta.glob("./Pages/**/*.tsx"),
  ...import.meta.glob("/plugins/*/admin/Pages/**/*.tsx"),
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
    // Stale HTML after a deploy → old chunk hash 404s. Reload ONCE for fresh HTML.
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
    createRoot(el).render(
      <ThemeProvider>
        <App {...props} />
      </ThemeProvider>,
    );
  },
  progress: { delay: 0, color: "#6366f1" },
});
