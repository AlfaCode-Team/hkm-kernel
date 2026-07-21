// Alias resolution — SINGLE source of truth.
//
// The old frontend duplicated aliases between tsconfig `paths` and vite
// `resolve.alias`, kept in sync by hand. Here:
//
//   • Shared-kit aliases (@ui, @lib, @hooks, @shared) are defined ONCE, below.
//   • Plugin-UI aliases (@pageflow/*, …) are read straight from the GENERATED
//     `tsconfig.plugins.json` that `hkm ui sync` writes — so enabling a plugin
//     UI never requires touching vite.config.ts.
//   • React is de-duped to a single instance (mandatory once plugin UIs and the
//     app share the runtime).

import fs from "fs";
import { resolve } from "path";
import type { Alias } from "vite";

/** Shared-kit aliases, defined once and mirrored in tsconfig.json `paths`. */
export function sharedAliases(frontendRoot: string): Alias[] {
  const src = resolve(frontendRoot, "src");
  return [
    { find: /^@ui\/(.*)/, replacement: resolve(src, "shared/ui") + "/$1" },
    { find: "@ui", replacement: resolve(src, "shared/ui") },
    { find: /^@lib\/(.*)/, replacement: resolve(src, "shared/lib") + "/$1" },
    { find: "@lib", replacement: resolve(src, "shared/lib") },
    { find: /^@hooks\/(.*)/, replacement: resolve(src, "shared/hooks") + "/$1" },
    { find: /^@providers\/(.*)/, replacement: resolve(src, "shared/providers") + "/$1" },
    { find: /^@shared\/(.*)/, replacement: resolve(src, "shared") + "/$1" },
    { find: /^@\/(.*)/, replacement: src + "/$1" },
  ];
}

/**
 * Turn the generated `tsconfig.plugins.json` `compilerOptions.paths` into vite
 * aliases. Each `"@pageflow/*": ["plugins/pageflow/*"]` becomes a regex alias
 * rooted at the frontend dir. Missing file → no plugin aliases (fine before a
 * first `hkm ui sync`).
 */
export function pluginAliases(frontendRoot: string): Alias[] {
  const file = resolve(frontendRoot, "tsconfig.plugins.json");
  if (!fs.existsSync(file)) return [];

  let json: any;
  try {
    json = JSON.parse(fs.readFileSync(file, "utf8"));
  } catch {
    return [];
  }
  const paths = json?.compilerOptions?.paths ?? {};
  const out: Alias[] = [];
  for (const key of Object.keys(paths)) {
    const target = (paths[key]?.[0] ?? "").replace(/\/\*$/, "");
    if (!target) continue;
    const abs = resolve(frontendRoot, target);
    if (key.endsWith("/*")) {
      const find = new RegExp("^" + escapeRe(key.slice(0, -2)) + "/(.*)");
      out.push({ find, replacement: abs + "/$1" });
    } else {
      out.push({ find: key, replacement: abs });
    }
  }
  return out;
}

/** Force a single React instance (app + every federated plugin UI share it). */
export function reactDedupe(frontendRoot: string): Alias[] {
  const nm = resolve(frontendRoot, "node_modules");
  return [
    { find: "react/jsx-runtime", replacement: resolve(nm, "react/jsx-runtime") },
    { find: "react/jsx-dev-runtime", replacement: resolve(nm, "react/jsx-dev-runtime") },
    { find: "react-dom", replacement: resolve(nm, "react-dom") },
    { find: "react", replacement: resolve(nm, "react") },
    { find: "scheduler", replacement: resolve(nm, "scheduler") },
  ];
}

function escapeRe(s: string): string {
  return s.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
}
