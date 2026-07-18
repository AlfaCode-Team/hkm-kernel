#!/usr/bin/env node
// Build (or list) every discovered surface. Replaces the old package.json wall
// of `build:project:*` / `build:module:*` scripts — surfaces are discovered, so
// this never needs editing when an app is added.
//
//   node vite/build-all.mjs          build every surface
//   node vite/build-all.mjs --list   just print them

import { readdirSync, statSync, existsSync, readFileSync } from "fs";
import { resolve, join } from "path";
import { spawnSync } from "child_process";

const ROOT = resolve(import.meta.dirname, "..");
const SURFACES = resolve(ROOT, "src/surfaces");

function discover() {
  if (!existsSync(SURFACES)) return [];
  return readdirSync(SURFACES)
    .filter((n) => statSync(join(SURFACES, n)).isDirectory())
    .filter((n) => existsSync(join(SURFACES, n, "index.tsx")) || hasEntry(join(SURFACES, n)))
    .map((n) => {
      const cfg = join(SURFACES, n, "surface.json");
      if (existsSync(cfg)) {
        try {
          return JSON.parse(readFileSync(cfg, "utf8")).name ?? n;
        } catch {
          /* ignore */
        }
      }
      return n;
    });
}

function hasEntry(dir) {
  const cfg = join(dir, "surface.json");
  if (!existsSync(cfg)) return false;
  try {
    const entry = JSON.parse(readFileSync(cfg, "utf8")).entry ?? "index.tsx";
    return existsSync(join(dir, entry));
  } catch {
    return false;
  }
}

const surfaces = discover();
if (surfaces.length === 0) {
  console.error("No surfaces found under src/surfaces/*.");
  process.exit(1);
}

if (process.argv.includes("--list")) {
  console.log("surfaces:\n" + surfaces.map((s) => `  • ${s}`).join("\n"));
  process.exit(0);
}

for (const name of surfaces) {
  console.log(`\n▶ building surface: ${name}`);
  const r = spawnSync("vite", ["build", "--mode", name], { cwd: ROOT, stdio: "inherit", shell: true });
  if (r.status !== 0) process.exit(r.status ?? 1);
}
