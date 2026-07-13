//! Plugin UI federation: mirror an enabled plugin's own `ui/` tree into a
//! project's `frontend/plugins/<name>/`, then regenerate the project-side glue
//! (a registry barrel, a manifest, and tsconfig path aliases) so the project
//! frontend can import every enabled plugin's UI through a stable alias.
//!
//! Design goals (mirrors the GDA "project-over-plugin, deterministic, manifest
//! tracked" model used by plugin_assets):
//!
//!   • A plugin OWNS its UI at `plugins/<Name>/ui/`. It stays independently
//!     developable/testable there (its own vitest.config.ts, package.json, …).
//!   • A project ACTIVATES a plugin's UI only when the plugin is enabled in the
//!     bootstrap. `hkm ui sync` copies a read-only mirror into the project and
//!     writes generated glue — never touching the project's own source.
//!   • `link` swaps the copied mirror for a symlink to the live plugin `ui/` so
//!     the plugin can be co-developed from inside the project (two-way edits).
//!
//! The autoload / bootstrap discovery is shared with plugin_assets via the same
//! plugin_bootstrap + plugin_sources helpers, so "enabled" means exactly what it
//! means everywhere else in the launcher.

const std = @import("std");
const prompt = @import("prompt.zig");
const util = @import("util.zig");
const sources = @import("plugin_sources.zig");
const boot = @import("plugin_bootstrap.zig");
const services = @import("services.zig");

const Dir = std.Io.Dir;
const Io = std.Io;
const EnvMap = std.process.Environ.Map;

/// Subtrees inside a plugin's `ui/` that are dev-only and never mirrored into a
/// project (kept lean; the plugin remains the source of truth for these).
const skip_dirs = [_][]const u8{ "node_modules", "tests", "dist", "build", ".turbo" };
const skip_exts = [_][]const u8{ ".pdf", ".map" };

/// One enabled plugin that ships a `ui/` tree.
pub const UiPlugin = struct {
    /// Plugin folder name, e.g. "Pageflow".
    name: []const u8,
    /// Lower-cased folder used for the project mirror dir + default alias.
    slug: []const u8,
    /// Absolute path to the plugin's `ui/` directory.
    uiDir: []const u8,
    /// Import alias exposed to the project, e.g. "@pageflow".
    alias: []const u8,
    /// Entry module relative to the ui root, e.g. "index.ts".
    entry: []const u8,
    /// UI framework hint ("react", "vue", "vanilla", …). Informational only.
    framework: []const u8,
    /// module.json version (best effort).
    version: []const u8,
    /// true when currently symlinked rather than copied.
    linked: bool = false,
};

/// Discover every enabled plugin in a project that ships a `ui/` folder.
pub fn discover(
    allocator: std.mem.Allocator,
    io: Io,
    env: *EnvMap,
    projectRoot: []const u8,
    out: *std.ArrayList(UiPlugin),
) !void {
    const bootstrap = try std.fmt.allocPrint(allocator, "{s}/app/bootstrap/app.php", .{util.trimSlash(projectRoot)});
    const source = Dir.cwd().readFileAlloc(io, bootstrap, allocator, .limited(4 * 1024 * 1024)) catch return;

    var aliases: std.ArrayList(boot.Alias) = .empty;
    try boot.collectAliases(allocator, source, &aliases);
    var enabled: std.ArrayList(boot.Enabled) = .empty;
    try boot.collectEnabled(allocator, source, aliases.items, &enabled);
    if (enabled.items.len == 0) return;

    const srcs = try sources.discoverSources(allocator, io, env, projectRoot);

    for (enabled.items) |e| {
        for ([_]sources.Source{ .project, .kernel }) |src| {
            const dir = srcs.dirFor(src) orelse continue;
            const folder = try std.fmt.allocPrint(allocator, "{s}/{s}", .{ dir, e.name });
            if (!util.dirExists(Dir.cwd(), io, folder)) continue;

            const uiDir = try std.fmt.allocPrint(allocator, "{s}/ui", .{folder});
            if (!util.dirExists(Dir.cwd(), io, uiDir)) break; // located, but ships no UI

            const slug = try util.lower(allocator, e.name);
            var p = UiPlugin{
                .name = e.name,
                .slug = slug,
                .uiDir = uiDir,
                .alias = try std.fmt.allocPrint(allocator, "@{s}", .{slug}),
                .entry = "index.ts",
                .framework = "vanilla",
                .version = "0.0.0",
                .linked = false,
            };
            try applyUiJson(allocator, io, uiDir, &p);
            if (try sources.readModuleMeta(allocator, io, dir, e.name)) |meta| {
                if (meta.version) |v| p.version = v;
            }
            // Reflect whether the current project mirror is a symlink.
            const mirror = try std.fmt.allocPrint(allocator, "{s}/frontend/plugins/{s}", .{ util.trimSlash(projectRoot), slug });
            p.linked = isSymlink(io, mirror);
            try out.append(allocator, p);
            break;
        }
    }
}

/// Read an optional `<uiDir>/ui.json` to override alias/entry/framework.
fn applyUiJson(allocator: std.mem.Allocator, io: Io, uiDir: []const u8, p: *UiPlugin) !void {
    const path = try std.fmt.allocPrint(allocator, "{s}/ui.json", .{uiDir});
    const content = Dir.cwd().readFileAlloc(io, path, allocator, .limited(1024 * 1024)) catch return;
    const trimmed = std.mem.trim(u8, content, " \t\r\n");
    if (trimmed.len == 0) return;
    const parsed = std.json.parseFromSliceLeaky(std.json.Value, allocator, trimmed, .{}) catch return;
    if (parsed != .object) return;
    if (strField(parsed.object, "alias")) |v| p.alias = v;
    if (strField(parsed.object, "entry")) |v| p.entry = v;
    if (strField(parsed.object, "framework")) |v| p.framework = v;
}

fn strField(obj: std.json.ObjectMap, key: []const u8) ?[]const u8 {
    const v = obj.get(key) orelse return null;
    return if (v == .string and v.string.len > 0) v.string else null;
}

// ── sync (copy mirror) ────────────────────────────────────────────────────────

/// Mirror one plugin's `ui/` into `<project>/frontend/plugins/<slug>/`, replacing
/// any prior copy. Returns the number of files written. Skips a linked mirror
/// (leaves the symlink intact) unless `force` is set.
pub fn syncPlugin(
    allocator: std.mem.Allocator,
    io: Io,
    projectRoot: []const u8,
    p: UiPlugin,
    force: bool,
) !usize {
    const dest = try std.fmt.allocPrint(allocator, "{s}/frontend/plugins/{s}", .{ util.trimSlash(projectRoot), p.slug });

    if (isSymlink(io, dest)) {
        if (!force) return 0; // linked for live dev — do not clobber
        Dir.cwd().deleteFile(io, dest) catch {};
    } else {
        Dir.cwd().deleteTree(io, dest) catch {};
    }

    var rels: std.ArrayList([]const u8) = .empty;
    try collectFiles(allocator, io, p.uiDir, "", &rels);

    const cwd = Dir.cwd();
    var written: usize = 0;
    for (rels.items) |rel| {
        const src = try std.fmt.allocPrint(allocator, "{s}/{s}", .{ p.uiDir, rel });
        const out = try std.fmt.allocPrint(allocator, "{s}/{s}", .{ dest, rel });
        const bytes = cwd.readFileAlloc(io, src, allocator, .limited(16 * 1024 * 1024)) catch continue;
        if (util.parentOf(out)) |parent| try cwd.createDirPath(io, parent);
        try cwd.writeFile(io, .{ .sub_path = out, .data = bytes });
        written += 1;
    }
    return written;
}

/// True when the copied mirror at `frontend/plugins/<slug>` differs from the
/// plugin's live `ui/` — missing entirely, missing files, extra files, or any
/// byte-different file. A symlinked mirror never differs (it IS the live tree).
pub fn mirrorDiffers(allocator: std.mem.Allocator, io: Io, projectRoot: []const u8, p: UiPlugin) !bool {
    if (p.linked) return false;
    const cwd = Dir.cwd();
    const dest = try std.fmt.allocPrint(allocator, "{s}/frontend/plugins/{s}", .{ util.trimSlash(projectRoot), p.slug });
    if (!util.dirExists(cwd, io, dest)) return true;

    var src_rels: std.ArrayList([]const u8) = .empty;
    var dest_rels: std.ArrayList([]const u8) = .empty;
    try collectFiles(allocator, io, p.uiDir, "", &src_rels);
    try collectFiles(allocator, io, dest, "", &dest_rels);
    if (src_rels.items.len != dest_rels.items.len) return true;

    for (src_rels.items) |rel| {
        const a = cwd.readFileAlloc(io, try std.fmt.allocPrint(allocator, "{s}/{s}", .{ p.uiDir, rel }), allocator, .limited(16 * 1024 * 1024)) catch continue;
        const b = cwd.readFileAlloc(io, try std.fmt.allocPrint(allocator, "{s}/{s}", .{ dest, rel }), allocator, .limited(16 * 1024 * 1024)) catch return true;
        if (!std.mem.eql(u8, a, b)) return true;
    }
    return false;
}

/// Recursively collect files under `root`, skipping dotfiles, dev-only subtrees
/// (node_modules/tests/dist/…) and non-shippable extensions (.pdf/.map).
fn collectFiles(allocator: std.mem.Allocator, io: Io, root: []const u8, prefix: []const u8, out: *std.ArrayList([]const u8)) !void {
    var d = Dir.cwd().openDir(io, root, .{ .iterate = true }) catch return;
    defer d.close(io);
    var it = d.iterate();
    while (try it.next(io)) |entry| {
        if (entry.name.len > 0 and entry.name[0] == '.') continue;
        const rel = if (prefix.len == 0)
            try allocator.dupe(u8, entry.name)
        else
            try std.fmt.allocPrint(allocator, "{s}/{s}", .{ prefix, entry.name });
        switch (entry.kind) {
            .file, .sym_link => {
                if (hasSkippedExt(entry.name)) continue;
                try out.append(allocator, rel);
            },
            .directory => {
                if (util.contains(&skip_dirs, entry.name)) continue;
                const sub = try std.fmt.allocPrint(allocator, "{s}/{s}", .{ root, entry.name });
                try collectFiles(allocator, io, sub, rel, out);
            },
            else => {},
        }
    }
}

fn hasSkippedExt(name: []const u8) bool {
    for (skip_exts) |ext| {
        if (std.mem.endsWith(u8, name, ext)) return true;
    }
    return false;
}

// ── link / unlink (live co-development) ───────────────────────────────────────

/// Replace the copied mirror with a symlink to the live plugin `ui/`, so edits
/// in either place are visible immediately. Returns false when symlinks are not
/// supported (caller falls back to `sync`).
pub fn linkPlugin(allocator: std.mem.Allocator, io: Io, projectRoot: []const u8, p: UiPlugin) !bool {
    const dest = try std.fmt.allocPrint(allocator, "{s}/frontend/plugins/{s}", .{ util.trimSlash(projectRoot), p.slug });
    if (util.parentOf(dest)) |parent| try Dir.cwd().createDirPath(io, parent);

    if (isSymlink(io, dest)) {
        Dir.cwd().deleteFile(io, dest) catch {};
    } else {
        Dir.cwd().deleteTree(io, dest) catch {};
    }

    Dir.cwd().symLink(io, p.uiDir, dest, .{ .is_directory = true }) catch return false;
    return true;
}

/// Drop a symlinked mirror (leaves copied mirrors untouched — use `clean`/`sync`
/// for those). Returns true when a symlink was removed.
pub fn unlinkPlugin(allocator: std.mem.Allocator, io: Io, projectRoot: []const u8, slug: []const u8) !bool {
    const dest = try std.fmt.allocPrint(allocator, "{s}/frontend/plugins/{s}", .{ util.trimSlash(projectRoot), slug });
    if (!isSymlink(io, dest)) return false;
    Dir.cwd().deleteFile(io, dest) catch return false;
    return true;
}

/// True when `path` is a symlink: readLink succeeds only on a symbolic link.
fn isSymlink(io: Io, path: []const u8) bool {
    var buf: [std.fs.max_path_bytes]u8 = undefined;
    _ = Dir.cwd().readLink(io, path, &buf) catch return false;
    return true;
}

// ── generated project glue ────────────────────────────────────────────────────

/// (Re)write the three generated project-side files from the given plugin set:
///   frontend/plugins/manifest.json   — machine-readable inventory
///   frontend/plugins/index.ts        — registry barrel (import one file)
///   frontend/tsconfig.plugins.json   — path aliases for bundler/TS resolution
pub fn writeGlue(allocator: std.mem.Allocator, io: Io, projectRoot: []const u8, plugins: []const UiPlugin) !void {
    const root = util.trimSlash(projectRoot);
    const dir = try std.fmt.allocPrint(allocator, "{s}/frontend/plugins", .{root});
    try Dir.cwd().createDirPath(io, dir);

    try writeManifest(allocator, io, dir, plugins);
    try writeRegistry(allocator, io, dir, plugins);
    try writeTsconfig(allocator, io, root, plugins);
}

fn writeManifest(allocator: std.mem.Allocator, io: Io, dir: []const u8, plugins: []const UiPlugin) !void {
    var out: std.ArrayList(u8) = .empty;
    try out.appendSlice(allocator, "{\n  \"$generated\": \"hkm ui\",\n  \"plugins\": [\n");
    for (plugins, 0..) |p, i| {
        try out.appendSlice(allocator, "    { \"name\": ");
        try util.appendJsonString(allocator, &out, p.name);
        try out.appendSlice(allocator, ", \"slug\": ");
        try util.appendJsonString(allocator, &out, p.slug);
        try out.appendSlice(allocator, ", \"alias\": ");
        try util.appendJsonString(allocator, &out, p.alias);
        try out.appendSlice(allocator, ", \"entry\": ");
        try util.appendJsonString(allocator, &out, p.entry);
        try out.appendSlice(allocator, ", \"framework\": ");
        try util.appendJsonString(allocator, &out, p.framework);
        try out.appendSlice(allocator, ", \"version\": ");
        try util.appendJsonString(allocator, &out, p.version);
        try out.appendSlice(allocator, ", \"linked\": ");
        try out.appendSlice(allocator, if (p.linked) "true" else "false");
        try out.appendSlice(allocator, " }");
        try out.appendSlice(allocator, if (i + 1 < plugins.len) ",\n" else "\n");
    }
    try out.appendSlice(allocator, "  ]\n}\n");
    const path = try std.fmt.allocPrint(allocator, "{s}/manifest.json", .{dir});
    try Dir.cwd().writeFile(io, .{ .sub_path = path, .data = out.items });
}

fn writeRegistry(allocator: std.mem.Allocator, io: Io, dir: []const u8, plugins: []const UiPlugin) !void {
    var out: std.ArrayList(u8) = .empty;
    try out.appendSlice(allocator,
        \\// ---------------------------------------------------------------------------
        \\// GENERATED by `hkm ui`. Do not edit by hand — re-run after enabling or
        \\// disabling a plugin. Import this file to reach every enabled plugin's UI.
        \\// ---------------------------------------------------------------------------
        \\
        \\export interface PluginUi {
        \\  alias: string
        \\  entry: string
        \\  framework: string
        \\  version: string
        \\}
        \\
        \\export const plugins = {
        \\
    );
    for (plugins) |p| {
        try out.appendSlice(allocator, "  ");
        try util.appendJsonString(allocator, &out, p.slug);
        try out.appendSlice(allocator, ": { alias: ");
        try util.appendJsonString(allocator, &out, p.alias);
        try out.appendSlice(allocator, ", entry: ");
        try util.appendJsonString(allocator, &out, p.entry);
        try out.appendSlice(allocator, ", framework: ");
        try util.appendJsonString(allocator, &out, p.framework);
        try out.appendSlice(allocator, ", version: ");
        try util.appendJsonString(allocator, &out, p.version);
        try out.appendSlice(allocator, " },\n");
    }
    try out.appendSlice(allocator,
        \\} as const satisfies Record<string, PluginUi>
        \\
        \\export type PluginName = keyof typeof plugins
        \\export const pluginNames = Object.keys(plugins) as PluginName[]
        \\
    );
    const path = try std.fmt.allocPrint(allocator, "{s}/index.ts", .{dir});
    try Dir.cwd().writeFile(io, .{ .sub_path = path, .data = out.items });
}

/// The project's fixed shared-kit aliases, emitted verbatim so TypeScript
/// resolves them too. Kept in step with the frontend template's tsconfig +
/// vite/aliases.ts. `tsconfig.json` EXTENDS this file, so these must live here
/// (TS `paths` do not merge across `extends`).
// Path VALUES are `./`-relative: TypeScript (with no `baseUrl`) requires
// non-relative path targets to start with "./" (TS5090). Vite resolves them
// against the frontend root all the same.
const shared_alias_lines = [_][]const u8{
    "      \"@/*\": [\"./src/*\"]",
    "      \"@ui\": [\"./src/shared/ui\"]",
    "      \"@ui/*\": [\"./src/shared/ui/*\"]",
    "      \"@lib\": [\"./src/shared/lib\"]",
    "      \"@lib/*\": [\"./src/shared/lib/*\"]",
    "      \"@hooks/*\": [\"./src/shared/hooks/*\"]",
    "      \"@providers/*\": [\"./src/shared/providers/*\"]",
    "      \"@shared/*\": [\"./src/shared/*\"]",
};

/// Write `tsconfig.plugins.json` = shared-kit aliases + one entry per plugin
/// alias. `tsconfig.json` extends this, so BOTH the bundler (vite reads it) and
/// TypeScript/editor resolve every alias from this single generated file.
fn writeTsconfig(allocator: std.mem.Allocator, io: Io, root: []const u8, plugins: []const UiPlugin) !void {
    // Collect all "key: value" path lines, then join — keeps commas valid
    // whether or not any plugins are present.
    var lines: std.ArrayList([]const u8) = .empty;
    for (shared_alias_lines) |l| try lines.append(allocator, l);

    for (plugins) |p| {
        // "@alias/*": ["./plugins/<slug>/*"]
        try lines.append(allocator, try std.fmt.allocPrint(
            allocator,
            "      \"{s}/*\": [\"./plugins/{s}/*\"]",
            .{ p.alias, p.slug },
        ));
        // "@alias": ["./plugins/<slug>/<entry>"]
        try lines.append(allocator, try std.fmt.allocPrint(
            allocator,
            "      \"{s}\": [\"./plugins/{s}/{s}\"]",
            .{ p.alias, p.slug, p.entry },
        ));
    }

    var out: std.ArrayList(u8) = .empty;
    try out.appendSlice(allocator,
        \\{
        \\  "//": "GENERATED by `hkm ui`. Shared + plugin path aliases. tsconfig.json extends this; do not edit by hand.",
        \\  "compilerOptions": {
        \\    "paths": {
        \\
    );
    for (lines.items, 0..) |l, i| {
        try out.appendSlice(allocator, l);
        try out.appendSlice(allocator, if (i + 1 < lines.items.len) ",\n" else "\n");
    }
    try out.appendSlice(allocator, "    }\n  }\n}\n");

    const path = try std.fmt.allocPrint(allocator, "{s}/frontend/tsconfig.plugins.json", .{root});
    try Dir.cwd().writeFile(io, .{ .sub_path = path, .data = out.items });
}

// ── init (scaffold a project frontend from the template) ──────────────────────

/// Copy the `frontend/` template tree into `<project>/frontend/`, skipping files
/// that already exist unless `force`. Returns the number of files written. The
/// caller then federates plugin UIs (discover + syncPlugin + writeGlue).
pub fn initFrontend(
    allocator: std.mem.Allocator,
    io: Io,
    env: *EnvMap,
    projectRoot: []const u8,
    force: bool,
    out_written: *usize,
) !bool {
    const templates = (try services.resolveTemplatesDir(allocator, io, env)) orelse return false;
    const srcRoot = try std.fmt.allocPrint(allocator, "{s}/frontend", .{templates});
    if (!util.dirExists(Dir.cwd(), io, srcRoot)) return false;

    const destRoot = try std.fmt.allocPrint(allocator, "{s}/frontend", .{util.trimSlash(projectRoot)});

    var rels: std.ArrayList([]const u8) = .empty;
    try collectAllFiles(allocator, io, srcRoot, "", &rels);

    const cwd = Dir.cwd();
    var written: usize = 0;
    for (rels.items) |rel| {
        const dest = try std.fmt.allocPrint(allocator, "{s}/{s}", .{ destRoot, rel });
        if (!force and util.fileExists(io, dest)) continue;
        const src = try std.fmt.allocPrint(allocator, "{s}/{s}", .{ srcRoot, rel });
        const bytes = cwd.readFileAlloc(io, src, allocator, .limited(16 * 1024 * 1024)) catch continue;
        if (util.parentOf(dest)) |parent| try cwd.createDirPath(io, parent);
        try cwd.writeFile(io, .{ .sub_path = dest, .data = bytes });
        written += 1;
    }
    out_written.* = written;
    return true;
}

/// Like collectFiles but does NOT apply the sync skip-lists — the template is
/// authored to be copied verbatim (it ships no node_modules/dist).
fn collectAllFiles(allocator: std.mem.Allocator, io: Io, root: []const u8, prefix: []const u8, out: *std.ArrayList([]const u8)) !void {
    var d = Dir.cwd().openDir(io, root, .{ .iterate = true }) catch return;
    defer d.close(io);
    var it = d.iterate();
    while (try it.next(io)) |entry| {
        const rel = if (prefix.len == 0)
            try allocator.dupe(u8, entry.name)
        else
            try std.fmt.allocPrint(allocator, "{s}/{s}", .{ prefix, entry.name });
        switch (entry.kind) {
            .file, .sym_link => try out.append(allocator, rel),
            .directory => {
                const sub = try std.fmt.allocPrint(allocator, "{s}/{s}", .{ root, entry.name });
                try collectAllFiles(allocator, io, sub, rel, out);
            },
            else => {},
        }
    }
}

/// Remove the entire generated federation (mirrors + glue). Symlinked mirrors are
/// unlinked, copied mirrors are deleted.
pub fn clean(allocator: std.mem.Allocator, io: Io, projectRoot: []const u8) !void {
    const root = util.trimSlash(projectRoot);
    const pluginsDir = try std.fmt.allocPrint(allocator, "{s}/frontend/plugins", .{root});
    Dir.cwd().deleteTree(io, pluginsDir) catch {};
    // Reset (do NOT delete) tsconfig.plugins.json to shared-only so tsconfig.json's
    // `extends` never dangles after a clean. Only the plugin aliases are dropped.
    try writeTsconfig(allocator, io, root, &.{});
}
