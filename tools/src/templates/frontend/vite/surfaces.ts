// Surface discovery — the replacement for the old hardcoded MODES registry.
//
// A "surface" is one buildable app. It is declared by a `surface.json` next to
// its entry, so adding an app is a filesystem operation (drop a folder), NOT an
// edit to vite.config.ts + two npm scripts. Vite picks the active surface from
// `--mode <name>` (default: the first surface, or one named "admin").
//
//   src/surfaces/<name>/surface.json   { name, entry?, style?, base? }
//   src/surfaces/<name>/<entry>        default: index.tsx
//   src/surfaces/<name>/<style>        default: styles/index.css (optional)

import fs from "fs";
import { resolve, join } from "path";

export interface Surface {
  /** Unique surface name — also the build sub-dir and manifest suffix. */
  name: string;
  /** Entry module, relative to the frontend root. */
  entry: string;
  /** Optional CSS entry, relative to the frontend root. */
  style?: string;
  /** Public base for built assets (PHP serves /build/<...>). */
  base: string;
  /** Absolute path to the surface directory. */
  dir: string;
}

const SURFACES_DIR = "src/surfaces";

/** Discover every surface under `<frontendRoot>/src/surfaces/*`. */
export function discoverSurfaces(frontendRoot: string): Surface[] {
  const root = resolve(frontendRoot, SURFACES_DIR);
  if (!fs.existsSync(root)) return [];

  const out: Surface[] = [];
  for (const name of fs.readdirSync(root)) {
    const dir = join(root, name);
    if (!fs.statSync(dir).isDirectory()) continue;

    let cfg: Partial<Surface> = {};
    const cfgPath = join(dir, "surface.json");
    if (fs.existsSync(cfgPath)) {
      try {
        cfg = JSON.parse(fs.readFileSync(cfgPath, "utf8"));
      } catch {
        /* fall through to conventions */
      }
    }

    const entryRel = cfg.entry ?? "index.tsx";
    const entryAbs = join(dir, entryRel);
    if (!fs.existsSync(entryAbs)) continue; // no entry → not buildable

    const styleRel = cfg.style ?? "styles/index.css";
    const styleAbs = join(dir, styleRel);

    out.push({
      name: cfg.name ?? name,
      entry: `${SURFACES_DIR}/${name}/${entryRel}`,
      style: fs.existsSync(styleAbs) ? `${SURFACES_DIR}/${name}/${styleRel}` : undefined,
      base: cfg.base ?? "/build/",
      dir,
    });
  }
  return out.sort((a, b) => a.name.localeCompare(b.name));
}

/** Pick the active surface for a given vite `--mode`. */
export function activeSurface(frontendRoot: string, mode: string | undefined): Surface {
  const surfaces = discoverSurfaces(frontendRoot);
  if (surfaces.length === 0) {
    throw new Error(
      `No surfaces found under ${SURFACES_DIR}/. Create src/surfaces/<name>/index.tsx.`,
    );
  }
  const wanted = mode && mode !== "development" && mode !== "production" ? mode : undefined;
  return (
    (wanted && surfaces.find((s) => s.name === wanted)) ||
    surfaces.find((s) => s.name === "admin") ||
    surfaces[0]
  );
}
