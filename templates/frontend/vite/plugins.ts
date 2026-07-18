// Build plugins for the per-project frontend.
//
//   • hkmPlugin  — the `hkmPlugin` feature from the old frontend, upgraded to
//     fold in the richer dev-integration that used to live in src/index.ts:
//       – per-surface build cleanup (never wipes sibling surfaces)
//       – index.html entry/style injection (the PHP asset contract)
//       – dev-server ORIGIN injection so served asset URLs are absolute to vite
//         (`__hkm_vite_placeholder__` → the resolved dev URL)
//       – a proper hot file: `<devUrl><base>` (APP_URL host + HTTPS aware) so a
//         PHP `vite()`/Pageflow helper can point <script> tags at the dev server
//       – full-reload on PHP / view / route changes (inline watcher — no extra
//         dependency), so editing a server-rendered page refreshes the browser
//   • gzipPlugin  — per-build gzip of only the files THIS run emitted.
//
// PHP reads:  app/public/build/manifest-<surface>.json  (hashed asset names)
//             app/public/<surface>-hot                    (dev server URL; dev only)

import fs from "fs";
import { resolve } from "path";
import { createGzip } from "zlib";
import { pipeline } from "stream/promises";
import { createReadStream, createWriteStream } from "fs";
import colors from "picocolors";
import type { Plugin, ResolvedConfig, UserConfig } from "vite";
import type { Surface } from "./surfaces";

export interface HkmPluginOptions {
  /**
   * Paths/globs (relative to the PROJECT root) that trigger a FULL browser
   * reload when changed — for server-rendered files Vite's HMR can't see.
   * Defaults to the project's views, routes and PHP entry points.
   */
  refresh?: string[];
}

const PLACEHOLDER = "__hkm_vite_placeholder__";

export function hkmPlugin(frontendRoot: string, surface: Surface, options: HkmPluginOptions = {}): Plugin {
  const project = resolve(frontendRoot, "..");
  const publicDir = resolve(project, "app", "public");
  const outDir = resolve(publicDir, "build");
  const hotFile = resolve(publicDir, `${surface.name}-hot`);

  const refresh = (options.refresh ?? [
    "resources/views/**",
    "resources/lang/**",
    "proj.json",
    "app/**/*.php",
    "src/**/*.php",
  ]).map((p) => resolve(project, p));

  let cfg: ResolvedConfig;
  let devUrl = "";

  return {
    name: "hkm",
    enforce: "post",

    // In dev, set a placeholder origin so every emitted asset URL is absolute.
    // `transform` below swaps it for the real dev-server URL once it's known.
    config(_user: UserConfig, { command }): UserConfig | void {
      if (command === "serve") {
        return { server: { origin: PLACEHOLDER } };
      }
    },

    configResolved(c) {
      cfg = c;
    },

    buildStart() {
      if (cfg.command !== "build") return;
      // Clean ONLY this surface's prior output (emptyOutDir stays false).
      fs.rmSync(resolve(outDir, surface.name), { recursive: true, force: true });
      fs.rmSync(resolve(outDir, `manifest-${surface.name}.json`), { force: true });
    },

    transform(code) {
      if (cfg.command === "serve" && devUrl) {
        return code.replace(new RegExp(PLACEHOLDER, "g"), devUrl);
      }
    },

    configureServer(server) {
      fs.mkdirSync(publicDir, { recursive: true });

      server.httpServer?.once("listening", () => {
        const addr = server.httpServer?.address();
        if (typeof addr !== "object" || !addr) return;

        const https = !!server.config.server.https;
        const proto = https ? "https" : "http";
        // Honour APP_URL's host (custom domain / container) when it isn't the
        // default localhost — otherwise fall back to the bound address.
        const appHost = hostFromEnv(project);
        const host = appHost ?? (addr.family === "IPv6" ? `[${addr.address}]` : addr.address);
        devUrl = `${proto}://${host}:${addr.port}`;

        // Hot file = dev URL + base (no trailing slash), the contract a PHP
        // vite()/Pageflow helper reads to decide "use the dev server".
        const base = server.config.base.replace(/\/$/, "");
        fs.writeFileSync(hotFile, `${devUrl}${base}`);

        server.config.logger.info(
          `\n  ${colors.green("➜")}  ${colors.bold("hkm")} surface ${colors.cyan(surface.name)} → ${colors.dim(devUrl)}`,
        );
      });

      // Full-reload watcher for server-rendered files (inline; no dependency).
      server.watcher.add(refresh);
      const reload = (file: string) => {
        if (matches(file, refresh)) {
          server.ws.send({ type: "full-reload", path: "*" });
        }
      };
      server.watcher.on("add", reload);
      server.watcher.on("change", reload);
      server.watcher.on("unlink", reload);

      const clean = () => fs.existsSync(hotFile) && fs.rmSync(hotFile);
      process.once("exit", clean);
      process.once("SIGINT", clean);
      process.once("SIGTERM", clean);
    },

    transformIndexHtml(html) {
      const styleTag = surface.style
        ? `\n    <link rel="stylesheet" href="/${surface.style}" />`
        : "";
      return html
        .replace("</head>", `${styleTag}\n  </head>`)
        .replace(
          /<script type="module" src="[^"]*"><\/script>/,
          `<script type="module" src="/${surface.entry}"></script>`,
        );
    },
  };
}

/** Read APP_URL from the project's .env and return a non-localhost host, if any. */
function hostFromEnv(project: string): string | undefined {
  try {
    const envFile = resolve(project, ".env");
    if (!fs.existsSync(envFile)) return undefined;
    const m = fs.readFileSync(envFile, "utf8").match(/^\s*APP_URL\s*=\s*(.+)\s*$/m);
    if (!m) return undefined;
    const host = new URL(m[1].trim().replace(/^["']|["']$/g, "")).hostname;
    return host && host !== "localhost" && host !== "127.0.0.1" ? host : undefined;
  } catch {
    return undefined;
  }
}

/** Simple prefix/`**` glob match against absolute refresh roots. */
function matches(file: string, roots: string[]): boolean {
  const f = file.replace(/\\/g, "/");
  return roots.some((r) => {
    const base = r.replace(/\\/g, "/").replace(/\/\*\*.*$/, "").replace(/\/\*.*$/, "");
    return f === base || f.startsWith(base + "/");
  });
}

export function gzipPlugin(frontendRoot: string, threshold = 1024): Plugin {
  const outDir = resolve(frontendRoot, "..", "app", "public", "build");
  const emitted = new Set<string>();

  return {
    name: "hkm-gzip",
    enforce: "post",
    apply: "build",
    generateBundle(_o, bundle) {
      for (const f of Object.keys(bundle)) emitted.add(f);
    },
    async closeBundle() {
      const tasks = [...emitted].map(async (file) => {
        const src = resolve(outDir, file);
        if (!fs.existsSync(src)) return null;
        const { size } = fs.statSync(src);
        if (size < threshold) return null;
        await pipeline(
          createReadStream(src),
          createGzip({ level: 9 }),
          createWriteStream(src + ".gz"),
        );
        const gz = fs.statSync(src + ".gz").size;
        return `  ${colors.cyan(file + ".gz")}  ${colors.dim(`${(size / 1024).toFixed(1)}kb`)} → ${colors.green(`${(gz / 1024).toFixed(1)}kb`)}`;
      });
      const lines = (await Promise.all(tasks)).filter(Boolean) as string[];
      if (lines.length) {
        console.log(`\n${colors.bold(colors.green("✨ [hkm-gzip]"))} ${lines.length} file(s)\n${lines.join("\n")}`);
      }
    },
  };
}
