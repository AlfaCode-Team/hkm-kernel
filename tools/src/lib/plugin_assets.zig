//! Plugin asset publishing: copying a plugin's config/migrations/seeders/
//! factories/resources into a project, tracking them in a manifest, running the
//! project migrate CLI, and unpublishing (rollback + delete). The autoload path
//! for the spawned PHP CLI is passed in by the caller so this module stays free
//! of any command-file dependency.

const std = @import("std");
const prompt = @import("prompt.zig");
const util = @import("util.zig");
const sources = @import("plugin_sources.zig");
const boot = @import("plugin_bootstrap.zig");

const Dir = std.Io.Dir;
const Io = std.Io;
const EnvMap = std.process.Environ.Map;

/// The plugin subtrees copied into a project on publish. Each maps 1:1 onto the
/// project's own layout, so a plugin file lands at the same relative path.
pub const subtrees = [_][]const u8{
    "config",
    "database/migrations",
    "database/tenant-template",
    "database/seeders",
    "database/factories",
    "resources",
};

/// Subtrees whose files are MIGRATIONS (applied to a DB), used when reconciling
/// migration ownership after a plugin split. `database/migrations` runs on the
/// central DB; `database/tenant-template` is provisioned into every tenant DB.
pub const migration_subtrees = [_][]const u8{
    "database/migrations",
    "database/tenant-template",
};

/// True when `rel` is a migration file (lives under a migration subtree).
pub fn isMigrationPath(rel: []const u8) bool {
    for (migration_subtrees) |sub| {
        const pfx = if (sub.len > 0) sub else "";
        if (std.mem.startsWith(u8, rel, pfx) and rel.len > pfx.len and rel[pfx.len] == '/') return true;
    }
    return false;
}

/// Copy a plugin's publishable assets into the project (OVERWRITING existing
/// files). Project-relative paths of every written file are appended to `out`.
pub fn publishAssets(
    allocator: std.mem.Allocator,
    io: Io,
    pluginFolder: []const u8,
    projectRoot: []const u8,
    out: *std.ArrayList([]const u8),
) !void {
    const cwd = Dir.cwd();
    for (subtrees) |sub| {
        const srcDir = try std.fmt.allocPrint(allocator, "{s}/{s}", .{ pluginFolder, sub });
        if (!util.dirExists(cwd, io, srcDir)) continue;

        var rels: std.ArrayList([]const u8) = .empty;
        try collectFiles(allocator, io, srcDir, "", &rels);
        for (rels.items) |rel| {
            const src = try std.fmt.allocPrint(allocator, "{s}/{s}", .{ srcDir, rel });
            const relDest = try std.fmt.allocPrint(allocator, "{s}/{s}", .{ sub, rel });
            const dest = try std.fmt.allocPrint(allocator, "{s}/{s}", .{ projectRoot, relDest });
            const bytes = cwd.readFileAlloc(io, src, allocator, .limited(16 * 1024 * 1024)) catch continue;
            if (util.parentOf(dest)) |parent| try cwd.createDirPath(io, parent);
            try cwd.writeFile(io, .{ .sub_path = dest, .data = bytes });
            try out.append(allocator, relDest);
        }
    }
}

/// Analyse a plugin's publishable subtrees (config, database, resources)
/// against the project's published copies, then bring the project in sync:
/// files the plugin gained are published (appended to `new_out`) and files
/// whose CONTENT drifted from the plugin's version are overwritten with the
/// plugin copy (appended to `changed_out`) — the plugin is the source of
/// truth on `update`. Pass `dry_run` to detect without writing.
pub fn syncAssets(
    allocator: std.mem.Allocator,
    io: Io,
    pluginFolder: []const u8,
    projectRoot: []const u8,
    dry_run: bool,
    new_out: *std.ArrayList([]const u8),
    changed_out: *std.ArrayList([]const u8),
) !void {
    const cwd = Dir.cwd();
    for (subtrees) |sub| {
        const srcDir = try std.fmt.allocPrint(allocator, "{s}/{s}", .{ pluginFolder, sub });
        if (!util.dirExists(cwd, io, srcDir)) continue;

        var rels: std.ArrayList([]const u8) = .empty;
        try collectFiles(allocator, io, srcDir, "", &rels);
        for (rels.items) |rel| {
            const relDest = try std.fmt.allocPrint(allocator, "{s}/{s}", .{ sub, rel });
            const dest = try std.fmt.allocPrint(allocator, "{s}/{s}", .{ projectRoot, relDest });
            const src = try std.fmt.allocPrint(allocator, "{s}/{s}", .{ srcDir, rel });
            const bytes = cwd.readFileAlloc(io, src, allocator, .limited(16 * 1024 * 1024)) catch continue;

            var kind: enum { new, changed } = .new;
            if (cwd.readFileAlloc(io, dest, allocator, .limited(16 * 1024 * 1024)) catch null) |have| {
                if (std.mem.eql(u8, have, bytes)) continue; // identical — in sync
                kind = .changed;
            }

            if (!dry_run) {
                if (util.parentOf(dest)) |parent| try cwd.createDirPath(io, parent);
                try cwd.writeFile(io, .{ .sub_path = dest, .data = bytes });
            }
            switch (kind) {
                .new => try new_out.append(allocator, relDest),
                .changed => try changed_out.append(allocator, relDest),
            }
        }
    }
}

/// List the project-relative destination paths a publish WOULD write (no copy).
pub fn collectPublishable(allocator: std.mem.Allocator, io: Io, pluginFolder: []const u8, out: *std.ArrayList([]const u8)) !void {
    for (subtrees) |sub| {
        const srcDir = try std.fmt.allocPrint(allocator, "{s}/{s}", .{ pluginFolder, sub });
        if (!util.dirExists(Dir.cwd(), io, srcDir)) continue;
        var rels: std.ArrayList([]const u8) = .empty;
        try collectFiles(allocator, io, srcDir, "", &rels);
        for (rels.items) |rel| {
            try out.append(allocator, try std.fmt.allocPrint(allocator, "{s}/{s}", .{ sub, rel }));
        }
    }
}

/// Collect file paths (relative to `root`) recursively, skipping dotfiles.
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
            .file, .sym_link => try out.append(allocator, rel),
            .directory => {
                const sub = try std.fmt.allocPrint(allocator, "{s}/{s}", .{ root, entry.name });
                try collectFiles(allocator, io, sub, rel, out);
            },
            else => {},
        }
    }
}

/// True if any published path is a migration (used to decide migrate:run).
pub fn hasMigrations(paths: []const []const u8) bool {
    for (paths) |p| if (std.mem.startsWith(u8, p, "database/migrations/")) return true;
    return false;
}

/// True if any path is a migration on EITHER surface — central
/// (database/migrations) or tenant (database/tenant-template). Used by
/// `update` to decide whether a migrate pass is needed at all.
pub fn hasAnyMigrations(paths: []const []const u8) bool {
    for (paths) |p| if (isMigrationPath(p)) return true;
    return false;
}

// ── manifest (<project>/var/plugin-assets.json) ────────────────────────────────

/// One plugin's published assets plus the migration batch it was applied in.
/// `batch` is the CENTRAL-DB batch number from the last enable (null when the
/// plugin shipped no migrations or was published without migrating).
const Entry = struct {
    name: []const u8,
    paths: []const []const u8,
    batch: ?i64 = null,
};

fn manifestPath(allocator: std.mem.Allocator, projectRoot: []const u8) ![]const u8 {
    return std.fmt.allocPrint(allocator, "{s}/var/plugin-assets.json", .{util.trimSlash(projectRoot)});
}

fn readManifest(allocator: std.mem.Allocator, io: Io, projectRoot: []const u8) !std.ArrayList(Entry) {
    var out: std.ArrayList(Entry) = .empty;
    const path = try manifestPath(allocator, projectRoot);
    const content = Dir.cwd().readFileAlloc(io, path, allocator, .limited(8 * 1024 * 1024)) catch return out;
    const trimmed = std.mem.trim(u8, content, " \t\r\n");
    if (trimmed.len == 0) return out;

    const parsed = std.json.parseFromSliceLeaky(std.json.Value, allocator, trimmed, .{}) catch return out;
    if (parsed != .object) return out;
    for (parsed.object.keys()) |key| {
        const v = parsed.object.get(key) orelse continue;

        var paths: std.ArrayList([]const u8) = .empty;
        var batch: ?i64 = null;

        switch (v) {
            // Legacy shape: "Name": ["path", ...]
            .array => |arr| {
                for (arr.items) |item| {
                    if (item == .string) try paths.append(allocator, item.string);
                }
            },
            // Current shape: "Name": { "paths": [...], "batch": N }
            .object => |obj| {
                if (obj.get("paths")) |p| {
                    if (p == .array) {
                        for (p.array.items) |item| {
                            if (item == .string) try paths.append(allocator, item.string);
                        }
                    }
                }
                if (obj.get("batch")) |b| {
                    if (b == .integer) batch = b.integer;
                }
            },
            else => continue,
        }

        try out.append(allocator, .{ .name = key, .paths = try paths.toOwnedSlice(allocator), .batch = batch });
    }
    return out;
}

fn writeManifest(allocator: std.mem.Allocator, io: Io, projectRoot: []const u8, entries: []const Entry) !void {
    const path = try manifestPath(allocator, projectRoot);
    if (util.parentOf(path)) |parent| try Dir.cwd().createDirPath(io, parent);

    var out: std.ArrayList(u8) = .empty;
    if (entries.len == 0) {
        try out.appendSlice(allocator, "{}\n");
    } else {
        try out.appendSlice(allocator, "{\n");
        for (entries, 0..) |e, i| {
            try out.appendSlice(allocator, "    ");
            try util.appendJsonString(allocator, &out, e.name);
            try out.appendSlice(allocator, ": {\n");

            // "paths": [ ... ]
            try out.appendSlice(allocator, "        \"paths\": [");
            if (e.paths.len == 0) {
                try out.appendSlice(allocator, "]");
            } else {
                try out.appendSlice(allocator, "\n");
                for (e.paths, 0..) |p, pi| {
                    try out.appendSlice(allocator, "            ");
                    try util.appendJsonString(allocator, &out, p);
                    if (pi + 1 < e.paths.len) try out.appendSlice(allocator, ",");
                    try out.appendSlice(allocator, "\n");
                }
                try out.appendSlice(allocator, "        ]");
            }

            // "batch": N   (only when known)
            if (e.batch) |b| {
                try out.appendSlice(allocator, ",\n        \"batch\": ");
                try out.appendSlice(allocator, try std.fmt.allocPrint(allocator, "{d}", .{b}));
                try out.appendSlice(allocator, "\n");
            } else {
                try out.appendSlice(allocator, "\n");
            }

            try out.appendSlice(allocator, "    }");
            if (i + 1 < entries.len) try out.appendSlice(allocator, ",");
            try out.appendSlice(allocator, "\n");
        }
        try out.appendSlice(allocator, "}\n");
    }
    try Dir.cwd().writeFile(io, .{ .sub_path = path, .data = out.items });
}

/// Upsert (name → paths) in the manifest, preserving any recorded batch.
pub fn recordPublished(allocator: std.mem.Allocator, io: Io, projectRoot: []const u8, name: []const u8, paths: []const []const u8) !void {
    var entries = try readManifest(allocator, io, projectRoot);
    for (entries.items) |*e| {
        if (util.eqlIgnoreCase(e.name, name)) {
            e.paths = paths;
            try writeManifest(allocator, io, projectRoot, entries.items);
            return;
        }
    }
    try entries.append(allocator, .{ .name = name, .paths = paths });
    try writeManifest(allocator, io, projectRoot, entries.items);
}

/// Record the central-DB migration batch a plugin was applied in (no-op when
/// the plugin is not yet tracked — recordPublished runs first on enable).
pub fn recordBatch(allocator: std.mem.Allocator, io: Io, projectRoot: []const u8, name: []const u8, batch: i64) !void {
    const entries = try readManifest(allocator, io, projectRoot);
    for (entries.items) |*e| {
        if (util.eqlIgnoreCase(e.name, name)) {
            e.batch = batch;
            try writeManifest(allocator, io, projectRoot, entries.items);
            return;
        }
    }
}

/// Look up a plugin's published paths, or null when not tracked.
pub fn publishedPathsFor(allocator: std.mem.Allocator, io: Io, projectRoot: []const u8, name: []const u8) !?[]const []const u8 {
    const entries = try readManifest(allocator, io, projectRoot);
    for (entries.items) |e| {
        if (util.eqlIgnoreCase(e.name, name)) return e.paths;
    }
    return null;
}

fn forgetPublished(allocator: std.mem.Allocator, io: Io, projectRoot: []const u8, name: []const u8) !void {
    const entries = try readManifest(allocator, io, projectRoot);
    var kept: std.ArrayList(Entry) = .empty;
    for (entries.items) |e| {
        if (!util.eqlIgnoreCase(e.name, name)) try kept.append(allocator, e);
    }
    try writeManifest(allocator, io, projectRoot, kept.items);
}

// ── running the project migrate CLI ────────────────────────────────────────────

/// Spawn `php <project>/app/cli/run.php <args...>`. `autoload`, when provided,
/// is exported as PSP_GLOBAL_AUTOLOAD so the kernel resolves. Returns the
/// child's exit code (255 if it could not run).
pub fn spawnCli(allocator: std.mem.Allocator, io: Io, env: *EnvMap, projectRoot: []const u8, autoload: ?[]const u8, args: []const []const u8) !u8 {
    const entry = try std.fmt.allocPrint(allocator, "{s}/app/cli/run.php", .{util.trimSlash(projectRoot)});
    if (!util.fileExists(io, entry)) {
        prompt.warn("No app/cli/run.php — skipping the migration step.");
        return 255;
    }
    // Set PSP_GLOBAL_AUTOLOAD only when it is not already present. resolveAutoload
    // returns the map's OWN stored value once it is set, so re-putting it would
    // pass a slice that aliases the entry the map is about to free — a
    // use-after-free that corrupts the value for later spawns (the first plugin
    // works, subsequent ones get garbage and fail to load the kernel).
    if (autoload) |a| {
        const existing = env.get("PSP_GLOBAL_AUTOLOAD");
        if (existing == null or existing.?.len == 0) try env.put("PSP_GLOBAL_AUTOLOAD", a);
    }
    const php = env.get("HKM_PHP_BIN") orelse "php";

    var argv: std.ArrayList([]const u8) = .empty;
    try argv.append(allocator, php);
    try argv.append(allocator, entry);
    for (args) |a| try argv.append(allocator, a);

    var child = std.process.spawn(io, .{
        .argv = argv.items,
        .environ_map = env,
        .stdin = .inherit,
        .stdout = .inherit,
        .stderr = .inherit,
    }) catch return 255;
    const term = child.wait(io) catch return 255;
    return switch (term) {
        .exited => |c| c,
        else => 1,
    };
}

/// Outcome of a captured `--json` migrate run.
const MigrateOutcome = struct {
    /// false when the CLI could not even run (spawn error / missing entry).
    ran: bool = false,
    /// child exit code (0 = success).
    code: u8 = 255,
    /// batch number from the JSON, when present.
    batch: ?i64 = null,
    /// how many migrations were applied (0 = nothing new).
    applied: i64 = 0,
};

/// Like spawnCli but captures stdout (instead of inheriting it) and parses a
/// `--json` migrate result (batch + applied_count). Used to record which batch
/// a plugin's migrations landed in and to tell "nothing new" from "failed".
fn spawnCliCaptureBatch(allocator: std.mem.Allocator, io: Io, env: *EnvMap, projectRoot: []const u8, autoload: ?[]const u8, args: []const []const u8) !MigrateOutcome {
    const entry = try std.fmt.allocPrint(allocator, "{s}/app/cli/run.php", .{util.trimSlash(projectRoot)});
    if (!util.fileExists(io, entry)) return .{};

    if (autoload) |a| {
        const existing = env.get("PSP_GLOBAL_AUTOLOAD");
        if (existing == null or existing.?.len == 0) try env.put("PSP_GLOBAL_AUTOLOAD", a);
    }
    const php = env.get("HKM_PHP_BIN") orelse "php";

    var argv: std.ArrayList([]const u8) = .empty;
    try argv.append(allocator, php);
    try argv.append(allocator, entry);
    for (args) |a| try argv.append(allocator, a);

    const result = std.process.run(allocator, io, .{
        .argv = argv.items,
        .environ_map = env,
    }) catch return .{};

    const code: u8 = switch (result.term) {
        .exited => |c| c,
        else => 1,
    };

    return .{
        .ran = true,
        .code = code,
        .batch = parseBatch(result.stdout),
        .applied = parseIntField(result.stdout, "\"applied_count\"") orelse 0,
    };
}

/// Pull an integer field's value out of a let-migrate JSON result.
fn parseIntField(json: []const u8, key: []const u8) ?i64 {
    const at = std.mem.indexOf(u8, json, key) orelse return null;
    var i = at + key.len;
    while (i < json.len and (json[i] == ' ' or json[i] == ':' or json[i] == '\t' or json[i] == '\n' or json[i] == '\r')) : (i += 1) {}
    const start = i;
    while (i < json.len and (json[i] >= '0' and json[i] <= '9')) : (i += 1) {}
    if (i == start) return null;
    return std.fmt.parseInt(i64, json[start..i], 10) catch null;
}

/// Pull the integer value of `"batch":` out of a let-migrate JSON result.
fn parseBatch(json: []const u8) ?i64 {
    const key = "\"batch\"";
    const at = std.mem.indexOf(u8, json, key) orelse return null;
    var i = at + key.len;
    // skip ws + ':'
    while (i < json.len and (json[i] == ' ' or json[i] == ':' or json[i] == '\t' or json[i] == '\n' or json[i] == '\r')) : (i += 1) {}
    const start = i;
    while (i < json.len and (json[i] >= '0' and json[i] <= '9')) : (i += 1) {}
    if (i == start) return null;
    return std.fmt.parseInt(i64, json[start..i], 10) catch null;
}

/// A temp let-migrate config scoped to ONE plugin's migration files, so the
/// plugin's migrations run/rollback as their OWN batch (isolated from every
/// other plugin and from the project's own migrations). `defer cwd.deleteTree`
/// the returned `tmp_root` after use.
const ScopedConfig = struct {
    tmp_root: []const u8,
    /// `--config=<path>` argument ready to pass to the migrate CLI.
    config_arg: []const u8,
    /// True when the project base config declares a tenant resolver, so the
    /// caller may also drive the tenant:* commands.
    has_tenants: bool,
};

/// Materialise a per-plugin scoped let-migrate config. Returns null when the
/// plugin ships no migrations or the project has no base config.
fn scopedPluginConfig(
    allocator: std.mem.Allocator,
    io: Io,
    projectRoot: []const u8,
    folder: []const u8,
    paths: []const []const u8,
) !?ScopedConfig {
    const cwd = Dir.cwd();

    var migs: std.ArrayList([]const u8) = .empty;
    for (paths) |p| {
        if (std.mem.startsWith(u8, p, "database/migrations/")) try migs.append(allocator, p);
    }
    if (migs.items.len == 0) return null;

    const cfg = try std.fmt.allocPrint(allocator, "{s}/config/let-migrate.php", .{util.trimSlash(projectRoot)});
    if (!util.fileExists(io, cfg)) {
        prompt.warn("No config/let-migrate.php — skipping DB migration; files handled regardless.");
        return null;
    }

    const tmpRoot = try std.fmt.allocPrint(allocator, "{s}/var/tmp/.plugin-migrate/{s}", .{ util.trimSlash(projectRoot), folder });
    const migDir = try std.fmt.allocPrint(allocator, "{s}/migrations", .{tmpRoot});
    Dir.cwd().deleteTree(io, tmpRoot) catch {};
    try cwd.createDirPath(io, migDir);

    for (migs.items) |rel| {
        const base = std.fs.path.basename(rel);
        const src = try std.fmt.allocPrint(allocator, "{s}/{s}", .{ util.trimSlash(projectRoot), rel });
        const bytes = cwd.readFileAlloc(io, src, allocator, .limited(16 * 1024 * 1024)) catch continue;
        try cwd.writeFile(io, .{
            .sub_path = try std.fmt.allocPrint(allocator, "{s}/{s}", .{ migDir, base }),
            .data = bytes,
        });
    }

    const tmpCfg = try std.fmt.allocPrint(allocator, "{s}/let-migrate.php", .{tmpRoot});
    // A SINGLE shared tracking table (the project's own `let_migrations`) hosts
    // every plugin — no per-plugin tables (which would not scale to dozens of
    // plugins). Isolation comes from two knobs instead:
    //   • paths          → narrowed to THIS plugin's migrations, so migrate:run
    //                       applies only this plugin (its own batch number) and
    //                       reset/refresh only re-run this plugin.
    //   • ignore_missing  → reset/refresh SKIP every other plugin's recorded
    //                       migration (its file is out of scope) instead of
    //                       failing, so this plugin rolls back in isolation while
    //                       the shared table keeps all other plugins' records.
    // transactional=false: MySQL implicitly COMMITs on DDL, so a transaction
    // wrapper would leave "no active transaction" on commit/rollback.
    const cfgBody = try std.fmt.allocPrint(allocator,
        \\<?php
        \\$base = require '{s}';
        \\$base['paths'] = ['{s}'];
        \\$base['transactional'] = false;
        \\$base['ignore_missing'] = true;
        \\return $base;
        \\
    , .{ cfg, migDir });
    try cwd.writeFile(io, .{ .sub_path = tmpCfg, .data = cfgBody });

    // Detect a tenant resolver in the base config so we can also migrate tenants.
    const cfgText = cwd.readFileAlloc(io, cfg, allocator, .limited(4 * 1024 * 1024)) catch "";
    const has_tenants = std.mem.indexOf(u8, cfgText, "'tenants'") != null or
        std.mem.indexOf(u8, cfgText, "\"tenants\"") != null;

    return .{
        .tmp_root = tmpRoot,
        .config_arg = try std.fmt.allocPrint(allocator, "--config={s}", .{tmpCfg}),
        .has_tenants = has_tenants,
    };
}

/// Run ONLY this plugin's migrations as its own batch: install + run on the
/// CENTRAL database first, then `tenant:migrate` for every active tenant of
/// the project. The two passes are independent — a plugin shipping only
/// tenant-template migrations still gets its tenant pass, and vice versa.
/// Best-effort — a non-zero exit is surfaced as a warning, never fatal.
pub fn runPluginMigrations(
    allocator: std.mem.Allocator,
    io: Io,
    env: *EnvMap,
    projectRoot: []const u8,
    autoload: ?[]const u8,
    folder: []const u8,
    paths: []const []const u8,
) !void {
    // 1. Central database — migrate:run applies ONLY this plugin's pending
    //    migrations (paths are scoped to it) as a NEW batch in the shared
    //    tracking table. We do NOT refresh/drop: a clean enable has nothing
    //    applied yet (disable rolled it back), and dropping tables here would
    //    fail on cross-plugin foreign keys. We capture --json output to learn
    //    the batch number and record it in the plugin-assets manifest.
    //    scopedPluginConfig is null when the plugin ships no central migrations.
    const scoped = try scopedPluginConfig(allocator, io, projectRoot, folder, paths);
    defer if (scoped) |s| Dir.cwd().deleteTree(io, s.tmp_root) catch {};

    if (scoped) |s| {
        prompt.muted(try std.fmt.allocPrint(allocator, "    migrating {s} on central DB (own batch)…", .{folder}));
        _ = try spawnCli(allocator, io, env, projectRoot, autoload, &.{ "migrate:install", "--force", s.config_arg });
        const central = try spawnCliCaptureBatch(allocator, io, env, projectRoot, autoload, &.{ "migrate:run", "--force", "--json", s.config_arg });
        if (!central.ran or central.code != 0) {
            prompt.warn("Central migrate:run returned non-zero — check the DB state.");
        } else if (central.applied > 0 and central.batch != null) {
            try recordBatch(allocator, io, projectRoot, folder, central.batch.?);
            prompt.ok(try std.fmt.allocPrint(allocator, "{s} migrated on central DB — {d} migration(s), batch {d}", .{ folder, central.applied, central.batch.? }));
        } else {
            prompt.muted(try std.fmt.allocPrint(allocator, "    {s}: no new migrations to apply on central DB.", .{folder}));
        }
    }

    // 2. Tenant databases — tenant:migrate is registry-driven (Tenancy's
    //    MigrateTenantsCommand): it applies the PROJECT's published
    //    database/tenant-template against every active tenant, each with its
    //    own tracking table, and skips tenants already up to date. Run it when
    //    the plugin ships tenant-template migrations (regardless of central
    //    ones) or the base let-migrate config declares a tenants resolver.
    var ships_tenant_migs = false;
    for (paths) |p| {
        if (std.mem.startsWith(u8, p, "database/tenant-template/")) {
            ships_tenant_migs = true;
            break;
        }
    }
    const has_tenants_cfg = if (scoped) |s| s.has_tenants else false;
    if (ships_tenant_migs or has_tenants_cfg) {
        prompt.muted(try std.fmt.allocPrint(allocator, "    migrating tenant template across all active tenants…", .{}));
        const code = try spawnCli(allocator, io, env, projectRoot, autoload, &.{"tenant:migrate"});
        if (code != 0 and code != 255) prompt.warn("tenant:migrate returned non-zero — check tenant DBs (is the Tenancy plugin enabled?).");
    }
}

/// Roll back ONLY this plugin's migrations (its own batch) on the CENTRAL
/// database, then across every tenant. Best-effort.
fn rollbackPluginMigrations(
    allocator: std.mem.Allocator,
    io: Io,
    env: *EnvMap,
    projectRoot: []const u8,
    autoload: ?[]const u8,
    folder: []const u8,
    paths: []const []const u8,
) !void {
    const scoped = (try scopedPluginConfig(allocator, io, projectRoot, folder, paths)) orelse return;
    defer Dir.cwd().deleteTree(io, scoped.tmp_root) catch {};
    const cfgArg = scoped.config_arg;

    // 1. Tenants first — drop the plugin's batch from every tenant DB.
    if (scoped.has_tenants) {
        prompt.muted(try std.fmt.allocPrint(allocator, "    rolling back {s} across all tenants…", .{folder}));
        const tcode = try spawnCli(allocator, io, env, projectRoot, autoload, &.{ "tenant:reset", "--all", cfgArg });
        if (tcode != 0 and tcode != 255) prompt.warn("tenant:reset returned non-zero — check tenant DBs.");
    }

    // 2. Central database last.
    prompt.muted(try std.fmt.allocPrint(allocator, "    rolling back {s} on central DB (migrate:reset)…", .{folder}));
    _ = try spawnCli(allocator, io, env, projectRoot, autoload, &.{ "migrate:install", "--force", cfgArg });
    const code = try spawnCli(allocator, io, env, projectRoot, autoload, &.{ "migrate:reset", "--force", cfgArg });
    if (code != 0) prompt.warn("Central migration rollback returned non-zero — check the DB state.");
}

/// Unpublish a plugin: roll back its migrations, delete every published file
/// recorded in the manifest, and drop it from the manifest.
pub fn unpublishPlugin(allocator: std.mem.Allocator, io: Io, env: *EnvMap, projectRoot: []const u8, autoload: ?[]const u8, folder: []const u8) !void {
    const paths = (try publishedPathsFor(allocator, io, projectRoot, folder)) orelse {
        prompt.muted("    no published assets tracked for this plugin.");
        return;
    };

    try rollbackPluginMigrations(allocator, io, env, projectRoot, autoload, folder, paths);

    var removed: usize = 0;
    for (paths) |rel| {
        const abs = try std.fmt.allocPrint(allocator, "{s}/{s}", .{ util.trimSlash(projectRoot), rel });
        Dir.cwd().deleteFile(io, abs) catch continue;
        removed += 1;
    }
    try forgetPublished(allocator, io, projectRoot, folder);
    prompt.muted(try std.fmt.allocPrint(allocator, "    removed {d} published file(s).", .{removed}));
}

// ── migration-ownership reconciliation (plugin splits) ─────────────────────────

/// A plugin folder on disk: its name + absolute path (e.g. .../plugins/Feedback).
pub const PluginDir = struct { name: []const u8, dir: []const u8 };

/// A migration whose manifest ownership was transferred from one plugin to
/// another because the file moved between plugins (a split).
pub const MigrationMove = struct { path: []const u8, from: []const u8, to: []const u8 };

/// The name of the plugin in `plugins` that currently SHIPS the migration file
/// `base` (matched by basename across every migration subtree), or null.
fn currentMigrationOwner(allocator: std.mem.Allocator, io: Io, plugins: []const PluginDir, base: []const u8) ?[]const u8 {
    for (plugins) |pl| {
        for (migration_subtrees) |sub| {
            const f = std.fmt.allocPrint(allocator, "{s}/{s}/{s}", .{ util.trimSlash(pl.dir), sub, base }) catch continue;
            if (util.fileExists(io, f)) return pl.name;
        }
    }
    return null;
}

fn removePathFromEntry(allocator: std.mem.Allocator, entries: *std.ArrayList(Entry), name: []const u8, path: []const u8) !void {
    for (entries.items) |*e| {
        if (!util.eqlIgnoreCase(e.name, name)) continue;
        var kept: std.ArrayList([]const u8) = .empty;
        for (e.paths) |p| {
            if (!std.mem.eql(u8, p, path)) try kept.append(allocator, p);
        }
        e.paths = try kept.toOwnedSlice(allocator);
        return;
    }
}

fn addPathToEntry(allocator: std.mem.Allocator, entries: *std.ArrayList(Entry), name: []const u8, path: []const u8) !void {
    for (entries.items) |*e| {
        if (!util.eqlIgnoreCase(e.name, name)) continue;
        for (e.paths) |p| {
            if (std.mem.eql(u8, p, path)) return; // already owned
        }
        var list: std.ArrayList([]const u8) = .empty;
        for (e.paths) |p| try list.append(allocator, p);
        try list.append(allocator, path);
        e.paths = try list.toOwnedSlice(allocator);
        return;
    }
    // No entry for the new owner yet — create one (the plugin was just enabled).
    var list: std.ArrayList([]const u8) = .empty;
    try list.append(allocator, path);
    try entries.append(allocator, .{ .name = try allocator.dupe(u8, name), .paths = try list.toOwnedSlice(allocator) });
}

/// Reassign, in the plugin-assets manifest, every tracked migration to the plugin
/// that currently SHIPS it. When a plugin split moves a migration file to a new
/// plugin (same filename, new owner), its manifest ownership transfers WITHOUT
/// touching the database: the migration's row in the shared `let_migrations`
/// table is keyed by filename and still marks it applied, so the table AND its
/// data are preserved. This is what stops a later `disable` of the OLD plugin
/// from rolling back — and DROPPING — a table the NEW plugin now owns. When
/// `dry_run`, moves are detected and returned but the manifest is not rewritten.
pub fn reconcileMigrationOwnership(
    allocator: std.mem.Allocator,
    io: Io,
    projectRoot: []const u8,
    plugins: []const PluginDir,
    dry_run: bool,
    moves: *std.ArrayList(MigrationMove),
) !void {
    var entries = try readManifest(allocator, io, projectRoot);
    if (entries.items.len == 0) return;

    // Detect: any tracked migration whose current shipping owner differs from
    // the plugin it is recorded under.
    for (entries.items) |e| {
        for (e.paths) |p| {
            if (!isMigrationPath(p)) continue;
            const base = std.fs.path.basename(p);
            const owner = currentMigrationOwner(allocator, io, plugins, base) orelse continue;
            if (!util.eqlIgnoreCase(owner, e.name)) {
                try moves.append(allocator, .{ .path = p, .from = e.name, .to = owner });
            }
        }
    }
    if (moves.items.len == 0 or dry_run) return;

    for (moves.items) |mv| {
        try removePathFromEntry(allocator, &entries, mv.from, mv.path);
        try addPathToEntry(allocator, &entries, mv.to, mv.path);
    }
    try writeManifest(allocator, io, projectRoot, entries.items);
}

/// Publish every plugin currently enabled in a project's bootstrap (copy only,
/// no auto-migrate). Used by `hkm new`. Safe to call repeatedly.
pub fn publishEnabled(allocator: std.mem.Allocator, io: Io, env: *EnvMap, projectRoot: []const u8) !void {
    const bootstrap = try std.fmt.allocPrint(allocator, "{s}/app/bootstrap/app.php", .{util.trimSlash(projectRoot)});
    const source = Dir.cwd().readFileAlloc(io, bootstrap, allocator, .limited(4 * 1024 * 1024)) catch return;

    var aliases: std.ArrayList(boot.Alias) = .empty;
    try boot.collectAliases(allocator, source, &aliases);
    var enabled: std.ArrayList(boot.Enabled) = .empty;
    try boot.collectEnabled(allocator, source, aliases.items, &enabled);
    if (enabled.items.len == 0) return;

    const srcs = try sources.discoverSources(allocator, io, env, projectRoot);
    var total: usize = 0;
    for (enabled.items) |e| {
        for ([_]sources.Source{ .project, .kernel }) |src| {
            const dir = srcs.dirFor(src) orelse continue;
            const folderPath = try std.fmt.allocPrint(allocator, "{s}/{s}", .{ dir, e.name });
            if (!util.dirExists(Dir.cwd(), io, folderPath)) continue;
            var paths: std.ArrayList([]const u8) = .empty;
            try publishAssets(allocator, io, folderPath, projectRoot, &paths);
            if (paths.items.len > 0) {
                try recordPublished(allocator, io, projectRoot, e.name, paths.items);
                total += paths.items.len;
            }
            break;
        }
    }
    if (total > 0) prompt.muted(try std.fmt.allocPrint(allocator, "published {d} plugin asset(s)", .{total}));
}

/// The result of a manifest recovery pass.
pub const RecoverResult = struct {
    /// plugins with at least one recovered (on-disk) asset.
    plugins: usize = 0,
    /// total asset files recorded across all plugins.
    files: usize = 0,
    /// enabled plugins whose source folder could not be located on disk.
    unresolved: usize = 0,
};

/// One recovered plugin, reported back to the caller for display.
pub const RecoveredPlugin = struct {
    name: []const u8,
    files: usize,
    source: sources.Source,
};

/// Rebuild `var/plugin-assets.json` from ground truth: for every plugin ENABLED
/// in the project bootstrap, compute the assets its source folder WOULD publish
/// and record the subset that actually exists in the project. Recovers a lost,
/// truncated, or drifted manifest without copying or overwriting any asset.
///
/// Migration `batch` numbers cannot be derived from the filesystem (they come
/// from the migrate CLI), so any batch recorded in the CURRENT manifest is
/// preserved for a plugin that still resolves. `dry_run` computes + reports but
/// writes nothing. Discovered plugins are appended to `found` for the caller to
/// print; the returned tally summarises the pass.
pub fn recoverEnabled(
    allocator: std.mem.Allocator,
    io: Io,
    env: *EnvMap,
    projectRoot: []const u8,
    dry_run: bool,
    found: *std.ArrayList(RecoveredPlugin),
) !RecoverResult {
    var result: RecoverResult = .{};

    const bootstrap = try std.fmt.allocPrint(allocator, "{s}/app/bootstrap/app.php", .{util.trimSlash(projectRoot)});
    const source = Dir.cwd().readFileAlloc(io, bootstrap, allocator, .limited(4 * 1024 * 1024)) catch return result;

    var aliases: std.ArrayList(boot.Alias) = .empty;
    try boot.collectAliases(allocator, source, &aliases);
    var enabled: std.ArrayList(boot.Enabled) = .empty;
    try boot.collectEnabled(allocator, source, aliases.items, &enabled);
    if (enabled.items.len == 0) return result;

    // Existing manifest — consulted ONLY to carry a known migration batch across
    // the rebuild (the filesystem cannot tell us which batch a migration ran in).
    const old = try readManifest(allocator, io, projectRoot);

    const srcs = try sources.discoverSources(allocator, io, env, projectRoot);
    var entries: std.ArrayList(Entry) = .empty;

    for (enabled.items) |e| {
        // Locate the plugin's source folder (project copy wins over kernel).
        var folder: ?[]const u8 = null;
        var src_kind: sources.Source = .project;
        for ([_]sources.Source{ .project, .kernel }) |src| {
            const dir = srcs.dirFor(src) orelse continue;
            const candidate = try std.fmt.allocPrint(allocator, "{s}/{s}", .{ dir, e.name });
            if (util.dirExists(Dir.cwd(), io, candidate)) {
                folder = candidate;
                src_kind = src;
                break;
            }
        }
        const plugin_folder = folder orelse {
            result.unresolved += 1;
            continue;
        };

        // Every relative path this plugin publishes, filtered to those present.
        var candidates: std.ArrayList([]const u8) = .empty;
        try collectPublishable(allocator, io, plugin_folder, &candidates);

        var present: std.ArrayList([]const u8) = .empty;
        for (candidates.items) |rel| {
            const full = try std.fmt.allocPrint(allocator, "{s}/{s}", .{ util.trimSlash(projectRoot), rel });
            if (util.fileExists(io, full)) try present.append(allocator, rel);
        }
        if (present.items.len == 0) continue;

        try entries.append(allocator, .{
            .name = e.name,
            .paths = try present.toOwnedSlice(allocator),
            .batch = batchFor(old.items, e.name),
        });
        try found.append(allocator, .{ .name = e.name, .files = entries.items[entries.items.len - 1].paths.len, .source = src_kind });
        result.plugins += 1;
        result.files += entries.items[entries.items.len - 1].paths.len;
    }

    if (!dry_run) try writeManifest(allocator, io, projectRoot, entries.items);
    return result;
}

/// The migration batch recorded for `name` in the previous manifest, if any.
fn batchFor(old: []const Entry, name: []const u8) ?i64 {
    for (old) |e| {
        if (util.eqlIgnoreCase(e.name, name)) return e.batch;
    }
    return null;
}
