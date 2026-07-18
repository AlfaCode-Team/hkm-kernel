import { defineConfig, loadEnv } from "vite";
import react from "@vitejs/plugin-react";
import tailwindcss from "@tailwindcss/vite";
import { resolve } from "path";
import { readFileSync } from "fs";
import { activeSurface, discoverSurfaces } from "./vite/surfaces";
import { sharedAliases, pluginAliases, reactDedupe } from "./vite/aliases";
import { hkmPlugin, gzipPlugin } from "./vite/plugins";

// frontend/  →  the per-project frontend root.
// One config, ZERO hardcoded app registry: the active app ("surface") is
// discovered from src/surfaces/* and selected by `--mode <name>`.
const ROOT = __dirname;
const PROJECT = resolve(ROOT, "..");

// Runtime dependencies to pre-bundle (see optimizeDeps below). Derived from
// package.json so the list is self-maintaining — adding a dependency never
// needs a config edit.
const RUNTIME_DEPS: string[] = Object.keys(
  (JSON.parse(readFileSync(resolve(ROOT, "package.json"), "utf8")) as {
    dependencies?: Record<string, string>;
  }).dependencies ?? {},
);

export default defineConfig((config) => {
  const env = loadEnv(config.mode, PROJECT, "");
  const surface = activeSurface(ROOT, config.mode);

  return {
    root: ROOT,
    // Built assets are served by PHP from /build/<surface>/...; dev keeps "/".
    base: config.command === "build" ? "/build/" : "/",
    envDir: PROJECT,
    cacheDir: resolve(ROOT, "node_modules/.vite"),

    define: {
      __APP_ENV__: JSON.stringify(env.APP_ENV ?? "local"),
      __SURFACE__: JSON.stringify(surface.name),
    },

    plugins: [
      hkmPlugin(ROOT, surface),
      tailwindcss(),
      gzipPlugin(ROOT),
      react(),
    ],

    resolve: {
      // Order matters: plugin + shared aliases before the React dedupe catch-all.
      alias: [
        ...pluginAliases(ROOT),
        ...sharedAliases(ROOT),
        ...reactDedupe(ROOT),
      ],
      dedupe: ["react", "react-dom"],
    },

    // The dependency pre-bundling SCAN (rolldown) crawls up from the entry to the
    // project root to resolve modules. When that absolute path contains a "#"
    // (e.g. .../#PROJECTS/...), the "#" is read as a URL fragment and the crawl
    // fails with `Could not load ../../..  (Is a directory)`. Disabling automatic
    // discovery skips that scan.
    //
    // With discovery OFF, deps are ONLY pre-bundled from this include list. That
    // matters for CommonJS packages (e.g. `qs`, pulled in by axios): unbundled,
    // the browser hits `require is not defined`. So we pre-bundle every runtime
    // dependency from package.json (esbuild converts CJS→ESM and inlines their
    // transitive CJS deps), plus React's JSX runtime subpaths.
    optimizeDeps: {
      noDiscovery: true,
      include: [
        ...RUNTIME_DEPS,
        "react/jsx-runtime",
        "react/jsx-dev-runtime",
        "react-dom/client",
      ],
    },

    server: {
      port: 5173,
      headers: {
        "Access-Control-Allow-Origin": "*",
        "Access-Control-Allow-Methods": "GET,POST,PUT,DELETE,OPTIONS",
        "Access-Control-Allow-Headers": "*",
      },
      proxy: {
        // Forward API/Pageflow XHR to the PHP dev server.
        "/api": env.VITE_BACKEND ?? "http://127.0.0.1:9501",
      },
      // Only the ACTIVE surface is being served in `--mode <name>`. Ignore the
      // OTHER surfaces' source so editing them never triggers a full-page reload
      // of the surface you're working on (they're not in this graph anyway).
      watch: {
        ignored: discoverSurfaces(ROOT)
          .filter((s) => s.name !== surface.name)
          .map((s) => resolve(s.dir, "**")),
      },
    },

    build: {
      manifest: `manifest-${surface.name}.json`,
      outDir: resolve(PROJECT, "app", "public", "build"),
      emptyOutDir: false, // per-surface cleanup handled by surfacePlugin
      rollupOptions: {
        input: resolve(ROOT, surface.entry),
        output: {
          entryFileNames: `${surface.name}/[name].[hash].js`,
          chunkFileNames: `${surface.name}/[name].[hash].js`,
          assetFileNames: `${surface.name}/[name].[hash].[ext]`,
        },
      },
    },
  };
});
