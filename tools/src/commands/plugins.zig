//! `hkm plugins` — analyse / enable / disable / create / delete / make plugins.
//!
//!   hkm plugins [path|name]                 analyse a project's enabled plugins
//!   hkm plugins enable|disable <plugin>     toggle a plugin in the bootstrap
//!   hkm plugins create|delete <name>        scaffold / remove a plugin
//!   hkm plugins make:migration <plugin> <n> add a migration into a plugin
//!
//! The heavy lifting lives in lib/: plugin_sources (discovery + module.json),
//! plugin_bootstrap (app.php editing), plugin_assets (publish/migrate). This
//! file is the command surface: argument parsing and per-action orchestration.

const std = @import("std");
const prompt = @import("../lib/prompt.zig");
const util = @import("../lib/util.zig");
const sources = @import("../lib/plugin_sources.zig");
const boot = @import("../lib/plugin_bootstrap.zig");
const assets = @import("../lib/plugin_assets.zig");
const deps = @import("../lib/plugin_deps.zig");
const services = @import("../lib/services.zig");

const Dir = std.Io.Dir;
const Io = std.Io;
const EnvMap = std.process.Environ.Map;

const Source = sources.Source;
const Located = sources.Located;
const Enabled = boot.Enabled;
const Activation = boot.Activation;

const Action = enum { analyze, verify, enable, disable, update, upgrade, create, delete, make_migration, make_seeder, make_factory };

pub fn run(allocator: std.mem.Allocator, io: Io, env: *EnvMap, args: []const []const u8) !u8 {
    var action: Action = .analyze;
    var show_all = false;
    var essential = false;
    var dry_run = false;
    var want_kernel = false;
    var fix = false;

    var operands: std.ArrayList([]const u8) = .empty;
    var saw_action = false;

    var i: usize = 2;
    while (i < args.len) : (i += 1) {
        const a = args[i];
        if (std.mem.eql(u8, a, "--all") or std.mem.eql(u8, a, "-a")) {
            show_all = true;
        } else if (std.mem.eql(u8, a, "--essential") or std.mem.eql(u8, a, "-e")) {
            essential = true;
        } else if (std.mem.eql(u8, a, "--dry-run") or std.mem.eql(u8, a, "-n")) {
            dry_run = true;
        } else if (std.mem.eql(u8, a, "--kernel") or std.mem.eql(u8, a, "-k")) {
            want_kernel = true;
        } else if (std.mem.eql(u8, a, "--fix") or std.mem.eql(u8, a, "-f")) {
            fix = true;
        } else if (std.mem.eql(u8, a, "--help") or std.mem.eql(u8, a, "-h")) {
            printHelp();
            return 0;
        } else if (a.len > 0 and a[0] == '-') {
            // unknown flag — ignore
        } else if (!saw_action and operands.items.len == 0 and isActionWord(a)) {
            action = actionFromWord(a);
            saw_action = true;
        } else {
            try operands.append(allocator, a);
        }
    }

    const ops = operands.items;

    switch (action) {
        .analyze => return analyze(allocator, io, env, op(ops, 0), show_all),
        .verify => return verifyPlugins(allocator, io, env, op(ops, 0), fix),
        .enable, .disable => {
            if (ops.len == 0) {
                prompt.err("Usage: hkm plugins enable|disable <plugin> [path|name] [--essential] [--dry-run]");
                return 2;
            }
            return mutate(allocator, io, env, action, op(ops, 0), op(ops, 1), essential, dry_run);
        },
        .update => {
            // hkm plugins update [plugin] [path|name]
            //   no plugin given      → update ALL enabled plugins
            //   <plugin> given       → update just that one
            // Disambiguate: if operand 0 resolves to a project root, treat it as
            // the target and update all; otherwise it is a plugin name.
            if (ops.len == 0) {
                return updatePlugins(allocator, io, env, "", "", dry_run);
            }
            if ((try services.resolveRoot(allocator, io, env, op(ops, 0))) != null) {
                return updatePlugins(allocator, io, env, "", op(ops, 0), dry_run);
            }
            return updatePlugins(allocator, io, env, op(ops, 0), op(ops, 1), dry_run);
        },
        .upgrade => return upgradeProject(allocator, io, env, op(ops, 0), dry_run),
        .create => {
            if (ops.len == 0) {
                prompt.err("Usage: hkm plugins create <name> [path|name] [--kernel] [--dry-run]");
                return 2;
            }
            return createPlugin(allocator, io, env, op(ops, 0), op(ops, 1), want_kernel, dry_run);
        },
        .delete => {
            if (ops.len == 0) {
                prompt.err("Usage: hkm plugins delete <name> [path|name] [--dry-run]");
                return 2;
            }
            return deletePlugin(allocator, io, env, op(ops, 0), op(ops, 1), want_kernel, dry_run);
        },
        .make_migration, .make_seeder, .make_factory => {
            if (ops.len < 2) {
                prompt.err("Usage: hkm plugins make:migration|make:seeder|make:factory <plugin> <name> [path|name] [--dry-run]");
                return 2;
            }
            const kind: MakeKind = switch (action) {
                .make_seeder => .seeder,
                .make_factory => .factory,
                else => .migration,
            };
            return makeInPlugin(allocator, io, env, kind, op(ops, 0), op(ops, 1), op(ops, 2), dry_run);
        },
    }
}

fn op(list: []const []const u8, n: usize) []const u8 {
    return if (n < list.len) list[n] else "";
}

fn isActionWord(a: []const u8) bool {
    return actionFromWordOpt(a) != null;
}
fn actionFromWord(a: []const u8) Action {
    return actionFromWordOpt(a).?;
}
fn actionFromWordOpt(a: []const u8) ?Action {
    if (std.mem.eql(u8, a, "enable") or std.mem.eql(u8, a, "add") or std.mem.eql(u8, a, "on")) return .enable;
    if (std.mem.eql(u8, a, "disable") or std.mem.eql(u8, a, "remove") or std.mem.eql(u8, a, "off")) return .disable;
    if (std.mem.eql(u8, a, "update") or std.mem.eql(u8, a, "sync")) return .update;
    if (std.mem.eql(u8, a, "upgrade") or std.mem.eql(u8, a, "reconcile") or std.mem.eql(u8, a, "migrate")) return .upgrade;
    if (std.mem.eql(u8, a, "create") or std.mem.eql(u8, a, "new") or std.mem.eql(u8, a, "scaffold")) return .create;
    if (std.mem.eql(u8, a, "delete") or std.mem.eql(u8, a, "del") or std.mem.eql(u8, a, "destroy") or
        std.mem.eql(u8, a, "rm")) return .delete;
    if (std.mem.eql(u8, a, "make:migration") or std.mem.eql(u8, a, "make-migration") or
        std.mem.eql(u8, a, "migration")) return .make_migration;
    if (std.mem.eql(u8, a, "make:seeder") or std.mem.eql(u8, a, "make-seeder") or
        std.mem.eql(u8, a, "seeder")) return .make_seeder;
    if (std.mem.eql(u8, a, "make:factory") or std.mem.eql(u8, a, "make-factory") or
        std.mem.eql(u8, a, "factory")) return .make_factory;
    if (std.mem.eql(u8, a, "list") or std.mem.eql(u8, a, "ls")) return .analyze;
    if (std.mem.eql(u8, a, "verify") or std.mem.eql(u8, a, "check") or std.mem.eql(u8, a, "doctor") or
        std.mem.eql(u8, a, "scan") or std.mem.eql(u8, a, "audit")) return .verify;
    return null;
}

/// Resolve a project root from `target` or error out. Shared by every action.
fn requireRoot(allocator: std.mem.Allocator, io: Io, env: *EnvMap, target: []const u8) !?[]const u8 {
    return (try services.resolveRoot(allocator, io, env, target)) orelse {
        prompt.err(try std.fmt.allocPrint(
            allocator,
            "'{s}' is neither a project folder (with proj.json) nor a registered name.",
            .{if (target.len == 0) "." else target},
        ));
        return null;
    };
}

fn readBootstrap(allocator: std.mem.Allocator, io: Io, bootstrap: []const u8) !?[]const u8 {
    return Dir.cwd().readFileAlloc(io, bootstrap, allocator, .limited(4 * 1024 * 1024)) catch {
        prompt.err(try std.fmt.allocPrint(allocator, "Cannot read {s}", .{bootstrap}));
        return null;
    };
}

// ── analyze ─────────────────────────────────────────────────────────────────

fn analyze(allocator: std.mem.Allocator, io: Io, env: *EnvMap, target: []const u8, show_all: bool) !u8 {
    const root = (try requireRoot(allocator, io, env, target)) orelse return 1;

    const bootstrap = try std.fmt.allocPrint(allocator, "{s}/app/bootstrap/app.php", .{root});
    const source = (try readBootstrap(allocator, io, bootstrap)) orelse {
        prompt.note("This command expects the standard app/bootstrap/app.php layout.");
        return 1;
    };

    var aliases: std.ArrayList(boot.Alias) = .empty;
    try boot.collectAliases(allocator, source, &aliases);
    var enabled: std.ArrayList(Enabled) = .empty;
    try boot.collectEnabled(allocator, source, aliases.items, &enabled);

    const srcs = try sources.discoverSources(allocator, io, env, root);
    const search = &[_]Source{ .project, .kernel };

    for (enabled.items) |*e| {
        for (search) |src| {
            const dir = srcs.dirFor(src) orelse continue;
            if (try sources.readModuleMeta(allocator, io, dir, e.name)) |meta| {
                e.solves = meta.solves;
                e.version = meta.version;
                e.resolved = true;
                e.source = src;
                break;
            }
        }
    }

    prompt.intro("hkm plugins");
    prompt.ok(try std.fmt.allocPrint(allocator, "project  {s}", .{root}));
    if (srcs.project_dir) |pd| prompt.muted(try std.fmt.allocPrint(allocator, "project plugins  {s}", .{pd}));
    if (srcs.kernel_dir) |kd| prompt.muted(try std.fmt.allocPrint(allocator, "kernel plugins   {s}", .{kd}));

    var on_demand_n: usize = 0;
    var essential_n: usize = 0;
    for (enabled.items) |e| {
        if (e.activation == .essential) essential_n += 1 else on_demand_n += 1;
    }

    if (enabled.items.len == 0) prompt.muted("No plugins wired in withModules()/withEssentialModules().");

    if (on_demand_n > 0) {
        prompt.section("On-demand modules (loaded per route dependency graph)");
        try renderEnabledTable(allocator, enabled.items, .on_demand);
    }
    if (essential_n > 0) {
        prompt.section("Essential modules (registered into every request)");
        try renderEnabledTable(allocator, enabled.items, .essential);
    }

    if (srcs.kernel_dir == null and srcs.project_dir == null) {
        prompt.warn("No plugins dir found — listing without module.json metadata.");
        prompt.note("Set HKM_KERNEL_HOME so plugins can be enriched and disabled ones listed.");
        prompt.outro(try std.fmt.allocPrint(allocator, "{d} enabled ({d} on-demand, {d} essential)", .{ enabled.items.len, on_demand_n, essential_n }));
        return 0;
    }

    var avail_n: usize = 0;
    var disabled: std.ArrayList(Located) = .empty;
    for (search) |src| {
        const dir = srcs.dirFor(src) orelse continue;
        var names: std.ArrayList([]const u8) = .empty;
        try sources.listPluginDirs(allocator, io, dir, &names);
        for (names.items) |p| {
            const prov = try std.fmt.allocPrint(allocator, "{s}/{s}/Provider.php", .{ dir, p });
            if (!util.fileExists(io, prov)) continue; // skip library folders
            avail_n += 1;
            if (!boot.isEnabled(enabled.items, p)) try disabled.append(allocator, .{ .name = p, .source = src, .dir = dir });
        }
    }

    if (show_all and disabled.items.len > 0) {
        prompt.section("Available but NOT enabled");
        var rows: std.ArrayList([]const []const u8) = .empty;
        for (disabled.items) |d| {
            const solves = if (try sources.readModuleMeta(allocator, io, d.dir, d.name)) |meta|
                (meta.solves orelse "—")
            else
                "—";
            const row = try allocator.dupe([]const u8, &.{ d.name, sources.sourceLabel(d.source), solves });
            try rows.append(allocator, row);
        }
        prompt.table(allocator, &.{ "Plugin", "Source", "Solves" }, rows.items);
    }

    prompt.outro(try std.fmt.allocPrint(
        allocator,
        "{d} enabled ({d} on-demand, {d} essential)  ·  {d} available  ·  {d} disabled",
        .{ enabled.items.len, on_demand_n, essential_n, avail_n, disabled.items.len },
    ));
    return 0;
}

/// Render the enabled plugins of one activation kind: Plugin · Source · Solves · Version.
fn renderEnabledTable(allocator: std.mem.Allocator, items: []const Enabled, activation: Activation) !void {
    var rows: std.ArrayList([]const []const u8) = .empty;
    for (items) |e| {
        if (e.activation != activation) continue;
        const src = if (e.source) |s| sources.sourceLabel(s) else "—";
        const solves = e.solves orelse if (e.resolved) "—" else "(unresolved)";
        const version = if (e.version) |v| try std.fmt.allocPrint(allocator, "v{s}", .{v}) else "—";
        const row = try allocator.dupe([]const u8, &.{ e.name, src, solves, version });
        try rows.append(allocator, row);
    }
    prompt.table(allocator, &.{ "Plugin", "Source", "Solves", "Version" }, rows.items);
}

// ── verify (audit wiring, deps + published assets) ────────────────────────────

/// Human label for an asset subtree in the verify report.
fn subtreeLabel(sub: []const u8) []const u8 {
    if (std.mem.eql(u8, sub, "resources")) return "resources (views)";
    if (std.mem.eql(u8, sub, "database/migrations")) return "migrations";
    if (std.mem.eql(u8, sub, "database/seeders")) return "seeders";
    if (std.mem.eql(u8, sub, "database/factories")) return "factories";
    return sub; // "config"
}

/// Audit every enabled plugin: is it resolvable on disk, are its `requires`
/// dependencies also enabled, and were its shipped assets (config, migrations,
/// seeders, factories, views) copied into the project + tracked in the manifest?
/// Also checks the Support/helpers.php require. Reports per plugin; `--fix`
/// delegates to `update` to publish anything missing and heal support requires.
fn verifyPlugins(allocator: std.mem.Allocator, io: Io, env: *EnvMap, target: []const u8, fix: bool) !u8 {
    const root = (try requireRoot(allocator, io, env, target)) orelse return 1;

    const bootstrap = try std.fmt.allocPrint(allocator, "{s}/app/bootstrap/app.php", .{root});
    const source = (try readBootstrap(allocator, io, bootstrap)) orelse return 1;

    var aliases: std.ArrayList(boot.Alias) = .empty;
    try boot.collectAliases(allocator, source, &aliases);
    var enabled: std.ArrayList(Enabled) = .empty;
    try boot.collectEnabled(allocator, source, aliases.items, &enabled);

    const srcs = try sources.discoverSources(allocator, io, env, root);
    const search = &[_]Source{ .project, .kernel };

    // Enrich each enabled entry with its solves/source from the plugin on disk,
    // so the requires-satisfaction check can match against enabled solves.
    for (enabled.items) |*e| {
        for (search) |src| {
            const dir = srcs.dirFor(src) orelse continue;
            if (try sources.readModuleMeta(allocator, io, dir, e.name)) |meta| {
                e.solves = meta.solves;
                e.version = meta.version;
                e.resolved = true;
                e.source = src;
                break;
            }
        }
    }

    var cat: std.ArrayList(deps.Provider) = .empty;
    try deps.catalogue(allocator, io, srcs, search, &cat);

    prompt.intro("hkm plugins verify");
    prompt.ok(try std.fmt.allocPrint(allocator, "project  {s}", .{root}));

    if (enabled.items.len == 0) {
        prompt.warn("No plugins enabled in this project.");
        prompt.outro("Nothing to verify");
        return 0;
    }

    var with_issues: usize = 0;
    var total_issues: usize = 0;

    for (enabled.items) |e| {
        var issues: usize = 0;
        const activation = if (e.activation == .essential) "essential" else "on-demand";
        prompt.section(try std.fmt.allocPrint(allocator, "{s}  [{s}]", .{ e.name, activation }));

        // Locate the plugin on disk (project shadows kernel).
        var plugins_dir: ?[]const u8 = null;
        var plugin_path: ?[]const u8 = null;
        var src_label: []const u8 = "—";
        for (search) |src| {
            const d = srcs.dirFor(src) orelse continue;
            const fp = try std.fmt.allocPrint(allocator, "{s}/{s}", .{ d, e.name });
            if (util.dirExists(Dir.cwd(), io, fp)) {
                plugins_dir = d;
                plugin_path = fp;
                src_label = sources.sourceLabel(src);
                break;
            }
        }

        if (e.solves) |s| {
            const ver = if (e.version) |v| try std.fmt.allocPrint(allocator, " · v{s}", .{v}) else "";
            prompt.muted(try std.fmt.allocPrint(allocator, "    solves: {s}{s}", .{ s, ver }));
        }
        if (plugin_path) |pp| {
            prompt.muted(try std.fmt.allocPrint(allocator, "    source: {s}  ({s})", .{ src_label, pp }));
        } else {
            prompt.err("    ✗ plugin folder not found on disk — cannot verify its assets");
            issues += 1;
        }

        // 1. requires[] — each must be solved by another ENABLED plugin, be a
        //    kernel port (no plugin provides it), else it is a real gap.
        const meta = if (plugins_dir) |pd| try sources.readModuleMeta(allocator, io, pd, e.name) else null;
        const requires = if (meta) |m| m.requires else &[_][]const u8{};
        if (requires.len > 0) {
            prompt.muted("    requires:");
            for (requires) |req| {
                if (enabledSolves(enabled.items, req)) |provider| {
                    prompt.muted(try std.fmt.allocPrint(allocator, "        ✓ {s}  ({s})", .{ req, provider }));
                } else if (deps.providerForDomain(cat.items, req)) |p| {
                    prompt.err(try std.fmt.allocPrint(allocator, "        ✗ {s} — provided by {s}, NOT enabled", .{ req, p.located.name }));
                    issues += 1;
                } else {
                    prompt.muted(try std.fmt.allocPrint(allocator, "        · {s}  (kernel port / external)", .{req}));
                }
            }
        }

        // 2. assets — compare what the plugin SHIPS with what exists in the
        //    project and what the manifest tracks.
        if (plugin_path) |pp| {
            var shipped: std.ArrayList([]const u8) = .empty;
            try assets.collectPublishable(allocator, io, pp, &shipped);
            const tracked = (try assets.publishedPathsFor(allocator, io, root, e.name)) orelse &[_][]const u8{};

            if (shipped.items.len == 0) {
                prompt.muted("    assets: none shipped");
            } else {
                prompt.muted("    assets:");
                var missing: std.ArrayList([]const u8) = .empty;
                var untracked: usize = 0;
                for (assets.subtrees) |sub| {
                    const pfx = try std.fmt.allocPrint(allocator, "{s}/", .{sub});
                    var total: usize = 0;
                    var present: usize = 0;
                    var trk: usize = 0;
                    for (shipped.items) |p| {
                        if (!std.mem.startsWith(u8, p, pfx)) continue;
                        total += 1;
                        const dest = try std.fmt.allocPrint(allocator, "{s}/{s}", .{ root, p });
                        if (util.fileExists(io, dest)) present += 1 else try missing.append(allocator, p);
                        if (containsStr(tracked, p)) trk += 1 else untracked += 1;
                    }
                    if (total == 0) continue;
                    const mark = if (present == total) "✓" else "✗";
                    prompt.muted(try std.fmt.allocPrint(allocator, "        {s} {s}  {d}/{d} present · {d} tracked", .{ mark, subtreeLabel(sub), present, total, trk }));
                }
                if (missing.items.len > 0) {
                    issues += 1;
                    prompt.err(try std.fmt.allocPrint(allocator, "        {d} file(s) NOT copied into the project:", .{missing.items.len}));
                    for (missing.items) |p| prompt.muted(try std.fmt.allocPrint(allocator, "            - {s}", .{p}));
                }
                if (untracked > 0)
                    prompt.warn(try std.fmt.allocPrint(allocator, "        {d} shipped asset(s) not recorded in the manifest (enable/update to track)", .{untracked}));
            }

            // 3. Support/helpers.php require wiring.
            const helpers = try std.fmt.allocPrint(allocator, "{s}/Support/helpers.php", .{pp});
            if (util.fileExists(io, helpers)) {
                const stag = try boot.supportTag(allocator, e.name);
                if (std.mem.indexOf(u8, source, stag) != null) {
                    prompt.muted("    ✓ Support/helpers.php require wired");
                } else {
                    prompt.err("    ✗ ships Support/helpers.php but its require is NOT wired in the bootstrap");
                    issues += 1;
                }
            }
        }

        if (issues == 0) {
            prompt.ok(try std.fmt.allocPrint(allocator, "    {s}: OK", .{e.name}));
        } else {
            with_issues += 1;
            total_issues += issues;
            prompt.warn(try std.fmt.allocPrint(allocator, "    {s}: {d} issue(s)", .{ e.name, issues }));
        }
    }

    if (total_issues == 0) {
        prompt.outro(try std.fmt.allocPrint(allocator, "All {d} enabled plugin(s) verified — no issues", .{enabled.items.len}));
        return 0;
    }

    prompt.warn(try std.fmt.allocPrint(allocator, "{d} plugin(s) with {d} issue(s) total", .{ with_issues, total_issues }));

    if (fix) {
        prompt.section("Fixing — publishing missing assets + wiring Support requires");
        _ = try updatePlugins(allocator, io, env, "", target, false);
        prompt.note("Re-run `hkm plugins verify` to confirm; unmet requires need `hkm plugins enable <dep>`.");
        return 0;
    }

    prompt.note("Fix with:  hkm plugins update   (or `hkm plugins verify --fix`)");
    prompt.note("Unmet requires need:  hkm plugins enable <dependency>");
    prompt.outro(try std.fmt.allocPrint(allocator, "{d} plugin(s) need attention", .{with_issues}));
    return 1;
}

/// The name of the first ENABLED plugin whose solves domain equals `domain`.
fn enabledSolves(items: []const Enabled, domain: []const u8) ?[]const u8 {
    for (items) |e| {
        if (e.solves) |s| {
            if (std.mem.eql(u8, s, domain)) return e.name;
        }
    }
    return null;
}

// ── enable / disable ──────────────────────────────────────────────────────────

fn mutate(
    allocator: std.mem.Allocator,
    io: Io,
    env: *EnvMap,
    action: Action,
    pluginArg: []const u8,
    target: []const u8,
    essential: bool,
    dry_run: bool,
) !u8 {
    const root = (try requireRoot(allocator, io, env, target)) orelse return 1;

    const bootstrap = try std.fmt.allocPrint(allocator, "{s}/app/bootstrap/app.php", .{root});
    const source = (try readBootstrap(allocator, io, bootstrap)) orelse return 1;

    const srcs = try sources.discoverSources(allocator, io, env, root);
    const search = &[_]Source{ .project, .kernel };
    var matches: std.ArrayList(Located) = .empty;
    try sources.locate(allocator, io, srcs, pluginArg, search, &matches);

    const located: ?Located = sources.chooseLocated(allocator, matches.items);
    const folder = if (located) |l| l.name else pluginArg;

    var aliases: std.ArrayList(boot.Alias) = .empty;
    try boot.collectAliases(allocator, source, &aliases);
    var enabled: std.ArrayList(Enabled) = .empty;
    try boot.collectEnabled(allocator, source, aliases.items, &enabled);

    // Catalogue every discoverable plugin (folder + solves + requires) so we can
    // resolve dependencies (enable) and dependents (disable). Tenancy → Database,
    // Auth, User → crypto.services, cache.redis, view.rendering, …
    var cat: std.ArrayList(deps.Provider) = .empty;
    try deps.catalogue(allocator, io, srcs, search, &cat);

    prompt.intro("hkm plugins");
    prompt.ok(try std.fmt.allocPrint(allocator, "project  {s}", .{root}));
    if (located) |l| prompt.muted(try std.fmt.allocPrint(allocator, "source  {s}  ({s})", .{ sources.sourceLabel(l.source), l.dir }));

    return switch (action) {
        .enable => enableWithDeps(allocator, io, env, root, bootstrap, source, cat.items, enabled.items, located, folder, essential, dry_run),
        .disable => disableWithDependents(allocator, io, env, root, bootstrap, source, cat.items, enabled.items, aliases.items, folder, dry_run),
        else => unreachable,
    };
}

// ── enable (dependency-resolving) ─────────────────────────────────────────────

/// Resolve `folder`'s transitive requires, then enable each missing dependency
/// (on-demand) before the requested plugin itself. Threads the bootstrap text
/// through each insert so the file is written exactly once.
fn enableWithDeps(
    allocator: std.mem.Allocator,
    io: Io,
    env: *EnvMap,
    root: []const u8,
    bootstrap: []const u8,
    source: []const u8,
    cat: []const deps.Provider,
    enabled: []const Enabled,
    located: ?Located,
    folder: []const u8,
    essential: bool,
    dry_run: bool,
) !u8 {
    if (located == null)
        prompt.warn(try std.fmt.allocPrint(allocator, "{s} not found in any plugins source — wiring by name anyway.", .{folder}));

    // Build the ordered enable list: unmet dependencies first, target last.
    var needed: std.ArrayList(deps.Provider) = .empty;
    var missing: std.ArrayList([]const u8) = .empty;
    try deps.requiredClosure(allocator, cat, folder, &needed, &missing);

    const Step = struct { folder: []const u8, dir: ?[]const u8, essential: bool, dependency: bool };
    var steps: std.ArrayList(Step) = .empty;
    for (needed.items) |dep| {
        if (boot.findEnabled(enabled, dep.located.name) != null) continue; // already wired
        // Deps are pulled into the route graph via requires[] → on-demand is correct.
        try steps.append(allocator, .{ .folder = dep.located.name, .dir = dep.located.dir, .essential = false, .dependency = true });
    }
    const target_enabled = boot.findEnabled(enabled, folder) != null;
    if (!target_enabled) {
        const dir = if (located) |l| l.dir else if (deps.findByName(cat, folder)) |p| p.located.dir else null;
        try steps.append(allocator, .{ .folder = folder, .dir = dir, .essential = essential, .dependency = false });
    }

    if (steps.items.len == 0) {
        prompt.warn(try std.fmt.allocPrint(allocator, "{s} and all its dependencies are already enabled.", .{folder}));
        if (missing.items.len > 0) noteMissingDomains(allocator, missing.items);
        prompt.outro("No changes made");
        return 0;
    }

    // Announce the resolved dependency plan.
    var dep_count: usize = 0;
    for (steps.items) |s| {
        if (s.dependency) dep_count += 1;
    }
    if (dep_count > 0) {
        prompt.section("Resolved dependencies");
        for (steps.items) |s| {
            if (!s.dependency) continue;
            const prov = deps.findByName(cat, s.folder);
            const solves = if (prov) |p| (p.solves orelse "—") else "—";
            prompt.muted(try std.fmt.allocPrint(allocator, "    {s}  (solves: {s})", .{ s.folder, solves }));
        }
    }
    if (missing.items.len > 0) noteMissingDomains(allocator, missing.items);

    var cur = source;
    for (steps.items) |s| {
        cur = (try enableOne(allocator, io, env, root, cur, s.folder, s.dir, s.essential, s.dependency, dry_run)) orelse return 1;
    }

    if (dry_run) {
        prompt.outro(try std.fmt.allocPrint(allocator, "Dry run — {s} NOT modified", .{bootstrap}));
        return 0;
    }
    try Dir.cwd().writeFile(io, .{ .sub_path = bootstrap, .data = cur });
    prompt.outro(try std.fmt.allocPrint(allocator, "Updated {s}  ({d} plugin(s) enabled)", .{ bootstrap, steps.items.len }));
    return 0;
}

/// Escape a filesystem path for embedding in a PHP single-quoted string literal
/// (only `\` and `'` are special inside single quotes).
fn phpQuote(allocator: std.mem.Allocator, s: []const u8) ![]const u8 {
    var out: std.ArrayList(u8) = .empty;
    for (s) |c| {
        if (c == '\\' or c == '\'') try out.append(allocator, '\\');
        try out.append(allocator, c);
    }
    return out.toOwnedSlice(allocator);
}

/// If the plugin at `pluginPath` ships a `Support/helpers.php`, return the PHP
/// `require_once` EXPRESSION (everything after `require_once `, incl. trailing
/// `;`) that references it. The expression is made as portable as the plugin's
/// location allows:
///   • inside the project (incl. its `vendor/` composer packages)
///       → `__DIR__ . '/../..<rel>'`      (relative to the bootstrap dir)
///   • inside the kernel home (HKM_KERNEL_HOME / discovered kernel root)
///       → `psp_kernel_home('<kroot>') . '<rel>'`  (resolves + guards at runtime)
///   • anywhere else (a globally-installed package, an odd mount)
///       → `'<abs>'`                       (absolute literal — last resort)
/// `null` when the plugin ships no helpers file to wire.
fn supportHelpersExpr(allocator: std.mem.Allocator, io: Io, env: *EnvMap, root: []const u8, pluginPath: []const u8) !?[]const u8 {
    const helpers = util.trimSlash(try std.fmt.allocPrint(allocator, "{s}/Support/helpers.php", .{pluginPath}));
    if (!util.fileExists(io, helpers)) return null;

    // 1. Inside the project — bootstrap lives at <root>/app/bootstrap, so
    //    `__DIR__ . '/../..'` is <root>. Covers project plugins AND anything under
    //    the project's own vendor/ (composer packages resolve relative too).
    const r = util.trimSlash(root);
    if (util.isInside(helpers, r)) {
        return try std.fmt.allocPrint(allocator, "__DIR__ . '{s}';", .{try phpQuote(allocator, try std.fmt.allocPrint(allocator, "/../..{s}", .{helpers[r.len..]}))});
    }

    // 2. Inside the kernel home — reference it through psp_kernel_home() (defined
    //    in kernel-autoload.php), which resolves HKM_KERNEL_HOME at runtime so a
    //    relocated kernel still works, falls back to the discovered dev-time path,
    //    and — crucially — hard-fails with a clear "framework not installed
    //    correctly" message instead of letting require_once fatal cryptically.
    if (try sources.kernelPluginsDir(allocator, io, env, root)) |kdir| {
        if (util.parentOf(kdir)) |kroot_raw| {
            const kroot = util.trimSlash(kroot_raw);
            if (util.isInside(helpers, kroot)) {
                return try std.fmt.allocPrint(
                    allocator,
                    "psp_kernel_home('{s}') . '{s}';",
                    .{ try phpQuote(allocator, kroot), try phpQuote(allocator, helpers[kroot.len..]) },
                );
            }
        }
    }

    // 3. Last resort — an absolute literal (e.g. a global composer install).
    return try std.fmt.allocPrint(allocator, "'{s}';", .{try phpQuote(allocator, helpers)});
}

/// Enable ONE plugin into `source`, returning the updated text (no file write).
/// Publishes assets + runs migrations as a side effect (skipped on dry-run).
fn enableOne(
    allocator: std.mem.Allocator,
    io: Io,
    env: *EnvMap,
    root: []const u8,
    source: []const u8,
    folder: []const u8,
    chosenDir: ?[]const u8,
    essential: bool,
    dependency: bool,
    dry_run: bool,
) !?[]const u8 {
    if (chosenDir) |cd| {
        const provFile = try std.fmt.allocPrint(allocator, "{s}/{s}/Provider.php", .{ cd, folder });
        if (!util.fileExists(io, provFile)) {
            prompt.err(try std.fmt.allocPrint(allocator, "{s} has no Provider.php — it is not an enableable module.", .{folder}));
            return null;
        }
    }

    const meta = if (chosenDir) |cd| try sources.readModuleMeta(allocator, io, cd, folder) else null;
    const block = try boot.buildEntryBlock(allocator, folder, meta);
    const marker = if (essential) "withEssentialModules(" else "withModules(";
    var updated = (try boot.insertIntoArray(allocator, source, marker, block)) orelse {
        prompt.err(try std.fmt.allocPrint(allocator, "Could not find ->{s}[...]) in the bootstrap to insert into.", .{marker[0 .. marker.len - 1]}));
        return null;
    };

    // Wire the plugin's Support/helpers.php (global functions — not autoloaded)
    // via a managed require_once in the bootstrap. Only when the plugin ships one.
    const support_expr: ?[]const u8 = if (chosenDir) |cd|
        try supportHelpersExpr(allocator, io, env, root, try std.fmt.allocPrint(allocator, "{s}/{s}", .{ cd, folder }))
    else
        null;
    if (support_expr) |expr| {
        updated = try boot.insertSupportRequire(allocator, updated, folder, expr);
    }

    const verb = if (dry_run) "Would enable" else "Enabled";
    const tag = if (dependency) "dependency · " else "";
    prompt.ok(try std.fmt.allocPrint(allocator, "{s} {s}  ({s}{s})", .{ verb, folder, tag, if (essential) "essential" else "on-demand" }));

    if (dry_run) {
        prompt.muted(try std.fmt.allocPrint(allocator, "    + into ->{s}[...]):", .{marker[0 .. marker.len - 1]}));
        printAddedLines(allocator, block);
        if (support_expr) |expr|
            prompt.muted(try std.fmt.allocPrint(allocator, "    + require_once {s}  (Support/helpers.php)", .{expr}));
        if (chosenDir) |cd| {
            const fp = try std.fmt.allocPrint(allocator, "{s}/{s}", .{ cd, folder });
            var preview: std.ArrayList([]const u8) = .empty;
            try assets.collectPublishable(allocator, io, fp, &preview);
            if (preview.items.len > 0) {
                prompt.muted("    + would publish assets:");
                for (preview.items) |p| prompt.muted(try std.fmt.allocPrint(allocator, "        {s}", .{p}));
                prompt.muted("    + would run migrate:run --force");
            }
        }
        return updated;
    }

    if (meta) |m| {
        if (m.solves) |s| prompt.muted(try std.fmt.allocPrint(allocator, "    solves: {s}", .{s}));
        if (m.doc != null or m.description != null) prompt.muted("    documentation comment added above the entry");
    }
    if (support_expr) |expr|
        prompt.muted(try std.fmt.allocPrint(allocator, "    wired Support/helpers.php  (require_once {s})", .{expr}));

    if (chosenDir) |cd| {
        const fp = try std.fmt.allocPrint(allocator, "{s}/{s}", .{ cd, folder });
        var published: std.ArrayList([]const u8) = .empty;
        try assets.publishAssets(allocator, io, fp, root, &published);
        if (published.items.len > 0) {
            try assets.recordPublished(allocator, io, root, folder, published.items);
            prompt.ok(try std.fmt.allocPrint(allocator, "Published {d} asset(s) into the project", .{published.items.len}));
            for (published.items) |p| prompt.muted(try std.fmt.allocPrint(allocator, "    {s}", .{p}));

            if (assets.hasMigrations(published.items)) {
                const autoload = try services.resolveAutoload(allocator, io, env);
                // Run this plugin's migrations as its OWN batch — central DB
                // first, then every tenant of the project.
                try assets.runPluginMigrations(allocator, io, env, root, autoload, folder, published.items);
            }
        }
    }
    return updated;
}

/// Domains required by the closure that no catalogued plugin solves — usually a
/// kernel port bound in withPorts(). Surfaced as a note, never a hard failure.
fn noteMissingDomains(allocator: std.mem.Allocator, missing: []const []const u8) void {
    prompt.note("Required domains with no plugin provider (expected from kernel withPorts()):");
    for (missing) |d| prompt.muted(std.fmt.allocPrint(allocator, "    {s}", .{d}) catch d);
}

// ── disable (dependent-aware) ─────────────────────────────────────────────────

/// Disable `folder`, refusing to orphan still-enabled plugins that depend on it
/// unless the user opts into cascading the disable to those dependents too.
fn disableWithDependents(
    allocator: std.mem.Allocator,
    io: Io,
    env: *EnvMap,
    root: []const u8,
    bootstrap: []const u8,
    source: []const u8,
    cat: []const deps.Provider,
    enabled: []const Enabled,
    aliases: []const boot.Alias,
    folder: []const u8,
    dry_run: bool,
) !u8 {
    if (boot.findEnabled(enabled, folder) == null) {
        prompt.warn(try std.fmt.allocPrint(allocator, "{s} is not enabled.", .{folder}));
        prompt.outro("No changes made");
        return 0;
    }

    // Enabled plugins that (transitively) depend on the domain `folder` provides.
    var dependents: std.ArrayList(deps.Provider) = .empty;
    try deps.enabledDependentsOf(allocator, cat, enabled, folder, &dependents);

    // Build the disable list: dependents first (top of the graph), target last.
    var order: std.ArrayList([]const u8) = .empty;
    if (dependents.items.len > 0) {
        prompt.warn(try std.fmt.allocPrint(allocator, "{d} enabled plugin(s) depend on {s}:", .{ dependents.items.len, folder }));
        for (dependents.items) |d| {
            const solves = d.solves orelse "—";
            prompt.muted(try std.fmt.allocPrint(allocator, "    {s}  (solves: {s})", .{ d.located.name, solves }));
        }
        if (!dry_run and !prompt.confirm(io, "Also disable these dependents? (declining cancels)", false)) {
            prompt.outro("Cancelled — nothing changed (disabling would break dependents)");
            return 0;
        }
        for (dependents.items) |d| try order.append(allocator, d.located.name);
    }
    try order.append(allocator, folder);

    // Prune dependencies that become unused once `order` is gone — keeping any
    // still required by a plugin that stays enabled (shared deps are safe).
    var orphans: std.ArrayList(deps.Provider) = .empty;
    try deps.orphanedDependencies(allocator, cat, enabled, order.items, &orphans);
    if (orphans.items.len > 0) {
        prompt.section("Now-unused dependencies (no other enabled plugin needs them)");
        for (orphans.items) |o| {
            const solves = o.solves orelse "—";
            prompt.muted(try std.fmt.allocPrint(allocator, "    {s}  (solves: {s})", .{ o.located.name, solves }));
        }
        const prune = dry_run or prompt.confirm(io, "Also disable these now-unused dependencies?", false);
        if (prune) {
            for (orphans.items) |o| try order.append(allocator, o.located.name);
        } else {
            prompt.muted("    left enabled.");
        }
    }

    var cur = source;
    var aborted = false;
    for (order.items) |name| {
        const e = boot.findEnabled(enabled, name) orelse continue;
        cur = (try disableOne(allocator, io, env, root, cur, name, e.token, aliases, dry_run)) orelse {
            aborted = true;
            break;
        };
    }
    if (aborted) return 1;

    if (dry_run) {
        prompt.outro(try std.fmt.allocPrint(allocator, "Dry run — {s} NOT modified", .{bootstrap}));
        return 0;
    }
    try Dir.cwd().writeFile(io, .{ .sub_path = bootstrap, .data = cur });
    prompt.outro(try std.fmt.allocPrint(allocator, "Updated {s}  ({d} plugin(s) disabled)", .{ bootstrap, order.items.len }));
    return 0;
}

/// Disable ONE plugin from `source`, returning the updated text (no file write).
/// Offers to unpublish its assets + roll back migrations as a side effect.
fn disableOne(
    allocator: std.mem.Allocator,
    io: Io,
    env: *EnvMap,
    root: []const u8,
    source: []const u8,
    folder: []const u8,
    token: []const u8,
    aliases: []const boot.Alias,
    dry_run: bool,
) !?[]const u8 {
    const result = try boot.removeFromArray(allocator, source, token, aliases);

    // Also drop the managed require_once for the plugin's Support/helpers.php.
    const support = try boot.removeSupportRequire(allocator, result.text, folder);

    const verb = if (dry_run) "Would disable" else "Disabled";
    prompt.ok(try std.fmt.allocPrint(allocator, "{s} {s}", .{ verb, folder }));

    const published = try assets.publishedPathsFor(allocator, io, root, folder);

    if (dry_run) {
        prompt.muted(try std.fmt.allocPrint(allocator, "    - {d} line(s) removed:", .{result.removed.len + support.removed.len}));
        printRemovedLines(allocator, result.removed);
        printRemovedLines(allocator, support.removed);
        if (published) |p| prompt.muted(try std.fmt.allocPrint(allocator, "    would offer to unpublish {d} asset(s) + roll back migrations", .{p.len}));
        return support.text;
    }

    if (support.removed.len > 0)
        prompt.muted("    unwired Support/helpers.php require");

    if (published) |p| {
        const label = try std.fmt.allocPrint(allocator, "Unpublish {s}'s {d} asset(s) and roll back its migrations?", .{ folder, p.len });
        if (prompt.confirm(io, label, false)) {
            const autoload = try services.resolveAutoload(allocator, io, env);
            try assets.unpublishPlugin(allocator, io, env, root, autoload, folder);
        } else {
            prompt.muted("    left published assets in place (DB untouched).");
        }
    }
    return support.text;
}

// ── update (re-publish new assets + migrate) ───────────────────────────────────

/// Update already-enabled plugins: publish any NEW assets (migrations, views,
/// config the plugin gained since it was enabled) WITHOUT clobbering existing
/// published files, then run the plugin's pending migrations (a fresh batch in
/// the shared tracking table; tenants too). `only` limits to one plugin.
fn updatePlugins(
    allocator: std.mem.Allocator,
    io: Io,
    env: *EnvMap,
    only: []const u8,
    target: []const u8,
    dry_run: bool,
) !u8 {
    const root = (try requireRoot(allocator, io, env, target)) orelse return 1;

    const bootstrap = try std.fmt.allocPrint(allocator, "{s}/app/bootstrap/app.php", .{root});
    const source = (try readBootstrap(allocator, io, bootstrap)) orelse return 1;

    var aliases: std.ArrayList(boot.Alias) = .empty;
    try boot.collectAliases(allocator, source, &aliases);
    var enabled: std.ArrayList(Enabled) = .empty;
    try boot.collectEnabled(allocator, source, aliases.items, &enabled);

    const srcs = try sources.discoverSources(allocator, io, env, root);
    const search = &[_]Source{ .project, .kernel };

    prompt.intro("hkm plugins update");
    prompt.ok(try std.fmt.allocPrint(allocator, "project  {s}", .{root}));

    if (enabled.items.len == 0) {
        prompt.warn("No plugins enabled in this project.");
        prompt.outro("Nothing to update");
        return 0;
    }

    const autoload = try services.resolveAutoload(allocator, io, env);

    var touched: usize = 0;
    var new_total: usize = 0;
    var matched = false;

    // Heal the Support-helpers require for enabled plugins that gained (or always
    // had) a Support/helpers.php but were never wired. Threaded through the loop so
    // the bootstrap is written once at the end only if something changed.
    var bootstrap_src = source;
    var wired: usize = 0;

    for (enabled.items) |e| {
        if (only.len > 0 and !std.mem.eql(u8, e.name, only)) continue;
        matched = true;

        // Locate the plugin's source dir (project first, then kernel).
        var dir: ?[]const u8 = null;
        for (search) |src| {
            const d = srcs.dirFor(src) orelse continue;
            const fp = try std.fmt.allocPrint(allocator, "{s}/{s}", .{ d, e.name });
            if (util.dirExists(Dir.cwd(), io, fp)) {
                dir = fp;
                break;
            }
        }
        const fp = dir orelse {
            prompt.muted(try std.fmt.allocPrint(allocator, "{s}: not found in any plugins source — skipped.", .{e.name}));
            continue;
        };

        // Wire a missing Support/helpers.php require (idempotent — a no-op when the
        // plugin ships none or the require is already present).
        if (try supportHelpersExpr(allocator, io, env, root, fp)) |expr| {
            const woven = try boot.insertSupportRequire(allocator, bootstrap_src, e.name, expr);
            if (woven.ptr != bootstrap_src.ptr) {
                bootstrap_src = woven;
                wired += 1;
                const verb = if (dry_run) "Would wire" else "Wired";
                prompt.ok(try std.fmt.allocPrint(allocator, "{s} Support/helpers.php for {s}", .{ verb, e.name }));
                prompt.muted(try std.fmt.allocPrint(allocator, "    + require_once {s}", .{expr}));
            }
        }

        // Detect (and, unless dry-run, copy) only assets not already published.
        var new_paths: std.ArrayList([]const u8) = .empty;
        try assets.publishNewAssets(allocator, io, fp, root, dry_run, &new_paths);

        if (new_paths.items.len == 0) {
            prompt.muted(try std.fmt.allocPrint(allocator, "{s}: up to date — no new assets.", .{e.name}));
            continue;
        }

        touched += 1;
        new_total += new_paths.items.len;
        const verb = if (dry_run) "Would publish" else "Published";
        prompt.ok(try std.fmt.allocPrint(allocator, "{s} {d} new asset(s) for {s}", .{ verb, new_paths.items.len, e.name }));
        for (new_paths.items) |p| prompt.muted(try std.fmt.allocPrint(allocator, "    + {s}", .{p}));

        if (dry_run) {
            if (assets.hasMigrations(new_paths.items)) prompt.muted("    + would run pending migrations (central + tenants)");
            continue;
        }

        // Merge new paths into the manifest's recorded path list, then migrate
        // the plugin's pending migrations (only the new ones apply).
        const existing = (try assets.publishedPathsFor(allocator, io, root, e.name)) orelse &[_][]const u8{};
        var merged: std.ArrayList([]const u8) = .empty;
        for (existing) |p| try merged.append(allocator, p);
        for (new_paths.items) |p| {
            if (!containsStr(merged.items, p)) try merged.append(allocator, p);
        }
        try assets.recordPublished(allocator, io, root, e.name, merged.items);

        if (assets.hasMigrations(new_paths.items)) {
            try assets.runPluginMigrations(allocator, io, env, root, autoload, e.name, merged.items);
        }
    }

    if (only.len > 0 and !matched) {
        prompt.warn(try std.fmt.allocPrint(allocator, "{s} is not enabled in this project.", .{only}));
        prompt.outro("Nothing to update");
        return 0;
    }

    if (dry_run) {
        prompt.outro(try std.fmt.allocPrint(allocator, "Dry run — {d} plugin(s) with {d} new asset(s) · {d} Support require(s) to wire", .{ touched, new_total, wired }));
        return 0;
    }
    if (wired > 0) try Dir.cwd().writeFile(io, .{ .sub_path = bootstrap, .data = bootstrap_src });
    prompt.outro(try std.fmt.allocPrint(allocator, "Updated {d} plugin(s) · {d} new asset(s) published · {d} Support require(s) wired", .{ touched, new_total, wired }));
    return 0;
}

// ── upgrade (heal deps + publish/migrate + reconcile split ownership) ──────────

/// Full project upgrade after its plugins changed. Three phases, each idempotent:
///
///   1. Dependency healing — a plugin that gained a new `requires` domain has its
///      missing provider auto-enabled (on-demand), so new cross-plugin deps that
///      appeared since the plugin was first enabled are wired in.
///   2. Assets + migrations — every enabled plugin's NEW assets are published and
///      its pending migrations run (delegates to `update`; already-applied
///      migrations are skipped by name, so nothing re-runs).
///   3. Split reconciliation — when a plugin SPLIT (a migration/table moved to a
///      new plugin), the manifest's migration ownership is transferred to the new
///      owner. No DDL runs: the shared `let_migrations` row (keyed by filename)
///      still marks the migration applied, so the table + its data are preserved.
///      This is the data-safety step — it prevents a later disable of the OLD
///      plugin from dropping a table the NEW plugin now owns.
fn upgradeProject(allocator: std.mem.Allocator, io: Io, env: *EnvMap, target: []const u8, dry_run: bool) !u8 {
    const root = (try requireRoot(allocator, io, env, target)) orelse return 1;

    const bootstrap = try std.fmt.allocPrint(allocator, "{s}/app/bootstrap/app.php", .{root});
    const source = (try readBootstrap(allocator, io, bootstrap)) orelse return 1;

    var aliases: std.ArrayList(boot.Alias) = .empty;
    try boot.collectAliases(allocator, source, &aliases);
    var enabled: std.ArrayList(Enabled) = .empty;
    try boot.collectEnabled(allocator, source, aliases.items, &enabled);

    const srcs = try sources.discoverSources(allocator, io, env, root);
    const search = &[_]Source{ .project, .kernel };

    var cat: std.ArrayList(deps.Provider) = .empty;
    try deps.catalogue(allocator, io, srcs, search, &cat);

    prompt.intro("hkm plugins upgrade");
    prompt.ok(try std.fmt.allocPrint(allocator, "project  {s}", .{root}));

    if (enabled.items.len == 0) {
        prompt.warn("No plugins enabled in this project.");
        prompt.outro("Nothing to upgrade");
        return 0;
    }

    // ── Phase 1: dependency healing ────────────────────────────────────────────
    const Step = struct { folder: []const u8, dir: ?[]const u8 };
    var plan: std.ArrayList(Step) = .empty;
    for (enabled.items) |e| {
        var needed: std.ArrayList(deps.Provider) = .empty;
        var missing: std.ArrayList([]const u8) = .empty;
        try deps.requiredClosure(allocator, cat.items, e.name, &needed, &missing);
        for (needed.items) |dep| {
            if (boot.findEnabled(enabled.items, dep.located.name) != null) continue; // already wired
            var seen = false;
            for (plan.items) |s| {
                if (util.eqlIgnoreCase(s.folder, dep.located.name)) seen = true;
            }
            if (seen) continue;
            try plan.append(allocator, .{ .folder = dep.located.name, .dir = dep.located.dir });
        }
    }

    if (plan.items.len > 0) {
        prompt.section("New dependencies to enable");
        for (plan.items) |s| {
            const prov = deps.findByName(cat.items, s.folder);
            const solves = if (prov) |p| (p.solves orelse "—") else "—";
            prompt.muted(try std.fmt.allocPrint(allocator, "    {s}  (solves: {s})", .{ s.folder, solves }));
        }
        var cur = source;
        for (plan.items) |s| {
            cur = (try enableOne(allocator, io, env, root, cur, s.folder, s.dir, false, true, dry_run)) orelse return 1;
        }
        if (!dry_run) {
            try Dir.cwd().writeFile(io, .{ .sub_path = bootstrap, .data = cur });
            prompt.ok(try std.fmt.allocPrint(allocator, "Wired {d} new dependency plugin(s) into the bootstrap", .{plan.items.len}));
        }
    } else {
        prompt.muted("Dependencies: all required providers already enabled.");
    }

    // ── Phase 2: publish NEW assets + run pending migrations for every plugin ────
    prompt.section("Assets + migrations");
    _ = try updatePlugins(allocator, io, env, "", target, dry_run);

    // ── Phase 3: reconcile migration ownership across plugin splits ─────────────
    prompt.section("Split reconciliation (migration ownership)");

    // Re-read the bootstrap: Phase 1 may have enabled new plugins.
    const source2 = (try readBootstrap(allocator, io, bootstrap)) orelse source;
    var aliases2: std.ArrayList(boot.Alias) = .empty;
    try boot.collectAliases(allocator, source2, &aliases2);
    var enabled2: std.ArrayList(Enabled) = .empty;
    try boot.collectEnabled(allocator, source2, aliases2.items, &enabled2);

    var plugin_dirs: std.ArrayList(assets.PluginDir) = .empty;
    for (enabled2.items) |e| {
        for (search) |src| {
            const d = srcs.dirFor(src) orelse continue;
            const fp = try std.fmt.allocPrint(allocator, "{s}/{s}", .{ d, e.name });
            if (util.dirExists(Dir.cwd(), io, fp)) {
                try plugin_dirs.append(allocator, .{ .name = e.name, .dir = fp });
                break;
            }
        }
    }

    var moves: std.ArrayList(assets.MigrationMove) = .empty;
    try assets.reconcileMigrationOwnership(allocator, io, root, plugin_dirs.items, dry_run, &moves);

    if (moves.items.len == 0) {
        prompt.muted("No migration moved between plugins — ownership already correct.");
    } else {
        const verb = if (dry_run) "Would transfer" else "Transferred";
        prompt.ok(try std.fmt.allocPrint(allocator, "{s} {d} migration(s) to their new plugin owner (no data touched)", .{ verb, moves.items.len }));
        for (moves.items) |mv| {
            prompt.muted(try std.fmt.allocPrint(allocator, "    {s}  {s} → {s}", .{ std.fs.path.basename(mv.path), mv.from, mv.to }));
        }
        prompt.note("Tables + data preserved — the migration stays applied; only manifest ownership changed.");
    }

    if (dry_run) {
        prompt.outro("Dry run — no files, bootstrap or database changed");
        return 0;
    }
    prompt.outro("Upgrade complete");
    return 0;
}

fn containsStr(haystack: []const []const u8, needle: []const u8) bool {
    for (haystack) |h| {
        if (std.mem.eql(u8, h, needle)) return true;
    }
    return false;
}

fn printAddedLines(allocator: std.mem.Allocator, block: []const u8) void {
    var it = std.mem.splitScalar(u8, block, '\n');
    while (it.next()) |l| {
        const t = std.mem.trim(u8, l, " \t\r");
        if (t.len == 0) continue;
        prompt.muted(std.fmt.allocPrint(allocator, "    + {s}", .{t}) catch t);
    }
}

fn printRemovedLines(allocator: std.mem.Allocator, removed: []const []const u8) void {
    for (removed) |l| {
        const t = std.mem.trim(u8, l, " \t\r");
        if (t.len == 0) continue;
        prompt.muted(std.fmt.allocPrint(allocator, "    - {s}", .{t}) catch t);
    }
}

// ── create / delete ───────────────────────────────────────────────────────────

fn createPlugin(allocator: std.mem.Allocator, io: Io, env: *EnvMap, nameArg: []const u8, target: []const u8, want_kernel: bool, dry_run: bool) !u8 {
    const projectRoot = try services.resolveRoot(allocator, io, env, target);
    const srcs = try sources.discoverSources(allocator, io, env, projectRoot);

    prompt.intro("hkm plugins create");
    const dest = (try chooseWriteDir(allocator, srcs, projectRoot, want_kernel, "create")) orelse return 1;

    const studlyName = try util.studly(allocator, nameArg);
    const lowerName = try util.lower(allocator, studlyName);
    const folderPath = try std.fmt.allocPrint(allocator, "{s}/{s}", .{ dest.dir, studlyName });

    if (util.dirExists(Dir.cwd(), io, folderPath)) {
        prompt.err(try std.fmt.allocPrint(allocator, "{s} already exists at {s}", .{ studlyName, folderPath }));
        return 1;
    }

    prompt.muted(try std.fmt.allocPrint(allocator, "source  {s}  ({s})", .{ sources.sourceLabel(dest.source), dest.dir }));

    if (dry_run) {
        prompt.ok(try std.fmt.allocPrint(allocator, "Would create plugin {s}", .{studlyName}));
        prompt.muted(try std.fmt.allocPrint(allocator, "    + {s}/", .{folderPath}));
        for (tpl_files) |f| {
            const rel = try renderTokens(allocator, f.dest, studlyName, lowerName);
            prompt.muted(try std.fmt.allocPrint(allocator, "    + {s}/{s}", .{ studlyName, rel }));
        }
        for (tpl_dirs) |d| prompt.muted(try std.fmt.allocPrint(allocator, "    + {s}/{s}/.gitkeep", .{ studlyName, d }));
        prompt.outro("Dry run — nothing written");
        return 0;
    }

    writeScaffold(allocator, io, env, folderPath, studlyName, lowerName) catch |e| {
        if (e == error.TemplatesNotFound) {
            prompt.err("Could not locate the plugin scaffolding templates directory.");
            prompt.muted("Set HKM_TEMPLATES_DIR or HKM_KERNEL_HOME, or run from inside the kernel repo.");
            return 1;
        }
        return e;
    };

    prompt.ok(try std.fmt.allocPrint(allocator, "Created plugin {s}  ({s})", .{ studlyName, sources.sourceLabel(dest.source) }));
    prompt.muted(try std.fmt.allocPrint(allocator, "    {s}", .{folderPath}));
    prompt.note(try std.fmt.allocPrint(allocator, "Enable it with:  hkm plugins enable {s}", .{studlyName}));
    prompt.outro("Plugin scaffolded");
    return 0;
}

fn deletePlugin(allocator: std.mem.Allocator, io: Io, env: *EnvMap, nameArg: []const u8, target: []const u8, want_kernel: bool, dry_run: bool) !u8 {
    const projectRoot = try services.resolveRoot(allocator, io, env, target);
    const srcs = try sources.discoverSources(allocator, io, env, projectRoot);

    prompt.intro("hkm plugins delete");

    var search: std.ArrayList(Source) = .empty;
    if (srcs.project_dir != null) try search.append(allocator, .project);
    if (srcs.in_kernel and srcs.kernel_dir != null) try search.append(allocator, .kernel);

    if (want_kernel and !srcs.in_kernel) {
        prompt.err("Kernel plugins can only be deleted from inside the kernel monorepo (contributors only).");
        return 1;
    }
    if (search.items.len == 0) {
        prompt.err("No deletable plugins source — run inside a project, or inside the kernel root for kernel plugins.");
        return 1;
    }

    var matches: std.ArrayList(Located) = .empty;
    try sources.locate(allocator, io, srcs, nameArg, search.items, &matches);
    if (want_kernel) {
        var only: std.ArrayList(Located) = .empty;
        for (matches.items) |m| {
            if (m.source == .kernel) try only.append(allocator, m);
        }
        matches = only;
    }

    const chosen = sources.chooseLocated(allocator, matches.items) orelse {
        prompt.err(try std.fmt.allocPrint(allocator, "Plugin '{s}' not found in any deletable source.", .{nameArg}));
        return 1;
    };

    const folderPath = try std.fmt.allocPrint(allocator, "{s}/{s}", .{ chosen.dir, chosen.name });
    prompt.muted(try std.fmt.allocPrint(allocator, "source  {s}  ({s})", .{ sources.sourceLabel(chosen.source), folderPath }));

    if (dry_run) {
        prompt.ok(try std.fmt.allocPrint(allocator, "Would delete plugin {s}", .{chosen.name}));
        prompt.muted(try std.fmt.allocPrint(allocator, "    - {s}/", .{folderPath}));
        prompt.outro("Dry run — nothing deleted");
        return 0;
    }

    const label = try std.fmt.allocPrint(allocator, "Permanently delete {s} plugin '{s}'?", .{ sources.sourceLabel(chosen.source), chosen.name });
    if (!prompt.confirm(io, label, false)) {
        prompt.outro("Cancelled — nothing deleted");
        return 0;
    }

    Dir.cwd().deleteTree(io, folderPath) catch |e| {
        prompt.err(try std.fmt.allocPrint(allocator, "Failed to delete {s}: {s}", .{ folderPath, @errorName(e) }));
        return 1;
    };

    prompt.ok(try std.fmt.allocPrint(allocator, "Deleted plugin {s}  ({s})", .{ chosen.name, sources.sourceLabel(chosen.source) }));
    prompt.note("Remember to disable it in any project that wired it (hkm plugins disable).");
    prompt.outro("Plugin deleted");
    return 0;
}

const WriteDir = struct { source: Source, dir: []const u8 };

/// Resolve where a `create` should write, honouring kernel protection and
/// prompting when both project and kernel destinations are available.
fn chooseWriteDir(allocator: std.mem.Allocator, srcs: sources.Sources, projectRoot: ?[]const u8, want_kernel: bool, verb: []const u8) !?WriteDir {
    const project_dir: ?[]const u8 = blk: {
        if (srcs.project_dir) |pd| break :blk pd;
        if (projectRoot) |pr| {
            if (srcs.kernel_root == null or !std.mem.eql(u8, util.trimSlash(pr), util.trimSlash(srcs.kernel_root.?)))
                break :blk try std.fmt.allocPrint(allocator, "{s}/plugins", .{util.trimSlash(pr)});
        }
        break :blk null;
    };
    const kernel_dir: ?[]const u8 = if (srcs.in_kernel) (srcs.kernel_dir orelse
        (if (srcs.kernel_root) |kr| try std.fmt.allocPrint(allocator, "{s}/plugins", .{util.trimSlash(kr)}) else null)) else null;

    if (want_kernel) {
        if (kernel_dir) |kd| return .{ .source = .kernel, .dir = kd };
        prompt.err(try std.fmt.allocPrint(allocator, "Kernel plugins can only be {s}d from inside the kernel monorepo (contributors only).", .{verb}));
        return null;
    }

    var cands: std.ArrayList(WriteDir) = .empty;
    if (project_dir) |pd| try cands.append(allocator, .{ .source = .project, .dir = pd });
    if (kernel_dir) |kd| try cands.append(allocator, .{ .source = .kernel, .dir = kd });

    if (cands.items.len == 0) {
        prompt.err(try std.fmt.allocPrint(allocator, "No destination to {s} into — run inside a project, or inside the kernel root.", .{verb}));
        return null;
    }
    if (cands.items.len == 1) return cands.items[0];

    var labels: std.ArrayList([]const u8) = .empty;
    for (cands.items) |c| try labels.append(allocator, try std.fmt.allocPrint(allocator, "{s}  ({s})", .{ sources.sourceLabel(c.source), c.dir }));
    const idx = prompt.select("Choose where to create the plugin", labels.items) orelse return null;
    return cands.items[idx];
}

// ── scaffolding (templates/plugin/) ─────────────────────────────────────────────

const TplFile = struct { src: []const u8, dest: []const u8 };

const tpl_files = [_]TplFile{
    .{ .src = "module.json", .dest = "module.json" },
    .{ .src = "Provider.php", .dest = "Provider.php" },
    .{ .src = "config.php", .dest = "config/{{LOWER}}.php" },
    .{ .src = "migration.php", .dest = "database/migrations/2026_01_01_000000_create_{{LOWER}}_table.php" },
    .{ .src = "seeder.php", .dest = "database/seeders/{{STUDLY}}Seeder.php" },
    .{ .src = "factory.php", .dest = "database/factories/{{STUDLY}}Factory.php" },
    .{ .src = "view.php", .dest = "resources/views/{{LOWER}}.php" },
};

const tpl_dirs = [_][]const u8{
    "API/Contracts",
    "Domain",
    "Application/Services",
    "Infrastructure/Http/Controllers",
};

/// Substitute `{{STUDLY}}`, `{{LOWER}}`, `{{UPPER}}` in `s`.
fn renderTokens(allocator: std.mem.Allocator, s: []const u8, studlyName: []const u8, lowerName: []const u8) ![]const u8 {
    const upper = try std.ascii.allocUpperString(allocator, lowerName);
    const a = try services.replace(allocator, s, "{{STUDLY}}", studlyName);
    const b = try services.replace(allocator, a, "{{LOWER}}", lowerName);
    return services.replace(allocator, b, "{{UPPER}}", upper);
}

/// Render the `templates/plugin/` dir into a new plugin folder.
fn writeScaffold(allocator: std.mem.Allocator, io: Io, env: *EnvMap, folderPath: []const u8, studlyName: []const u8, lowerName: []const u8) !void {
    const cwd = Dir.cwd();
    const tpl_dir = (try services.resolveTemplatesDir(allocator, io, env)) orelse return error.TemplatesNotFound;
    const plugin_dir = try std.fmt.allocPrint(allocator, "{s}/plugin", .{tpl_dir});

    try cwd.createDirPath(io, folderPath);
    for (tpl_files) |t| {
        const src = try std.fmt.allocPrint(allocator, "{s}/{s}", .{ plugin_dir, t.src });
        const raw = cwd.readFileAlloc(io, src, allocator, .limited(1024 * 1024)) catch return error.TemplatesNotFound;
        const data = try renderTokens(allocator, raw, studlyName, lowerName);
        const dest_rel = try renderTokens(allocator, t.dest, studlyName, lowerName);
        const dest = try std.fmt.allocPrint(allocator, "{s}/{s}", .{ folderPath, dest_rel });
        if (util.parentOf(dest)) |parent| try cwd.createDirPath(io, parent);
        try cwd.writeFile(io, .{ .sub_path = dest, .data = data });
    }

    for (tpl_dirs) |d| {
        const full = try std.fmt.allocPrint(allocator, "{s}/{s}", .{ folderPath, d });
        try cwd.createDirPath(io, full);
        try cwd.writeFile(io, .{ .sub_path = try std.fmt.allocPrint(allocator, "{s}/.gitkeep", .{full}), .data = "" });
    }
}

// ── make:migration / make:seeder / make:factory (into a plugin, NOT published) ──

const MakeKind = enum { migration, seeder, factory };

fn makeKindLabel(k: MakeKind) []const u8 {
    return switch (k) {
        .migration => "migration",
        .seeder => "seeder",
        .factory => "factory",
    };
}

fn makeInPlugin(allocator: std.mem.Allocator, io: Io, env: *EnvMap, kind: MakeKind, pluginArg: []const u8, name: []const u8, target: []const u8, dry_run: bool) !u8 {
    const projectRoot = try services.resolveRoot(allocator, io, env, target);
    const srcs = try sources.discoverSources(allocator, io, env, projectRoot);

    prompt.intro(try std.fmt.allocPrint(allocator, "hkm plugins make:{s}", .{makeKindLabel(kind)}));

    var search: std.ArrayList(Source) = .empty;
    if (srcs.project_dir != null) try search.append(allocator, .project);
    if (srcs.in_kernel and srcs.kernel_dir != null) try search.append(allocator, .kernel);
    if (search.items.len == 0) {
        prompt.err("No writable plugins source — run inside a project, or inside the kernel root for kernel plugins.");
        return 1;
    }

    var matches: std.ArrayList(Located) = .empty;
    try sources.locate(allocator, io, srcs, pluginArg, search.items, &matches);
    const chosen = sources.chooseLocated(allocator, matches.items) orelse {
        prompt.err(try std.fmt.allocPrint(allocator, "Plugin '{s}' not found in any writable source.", .{pluginArg}));
        return 1;
    };

    const studlyName = try util.studly(allocator, name);
    const lowerName = try util.lower(allocator, studlyName);

    const tpl_src = switch (kind) {
        .migration => "migration.php",
        .seeder => "seeder.php",
        .factory => "factory.php",
    };
    const dest_rel = switch (kind) {
        .migration => try std.fmt.allocPrint(allocator, "database/migrations/{s}_create_{s}_table.php", .{ try util.timestampPrefix(allocator), lowerName }),
        .seeder => try std.fmt.allocPrint(allocator, "database/seeders/{s}Seeder.php", .{studlyName}),
        .factory => try std.fmt.allocPrint(allocator, "database/factories/{s}Factory.php", .{studlyName}),
    };

    const folderPath = try std.fmt.allocPrint(allocator, "{s}/{s}", .{ chosen.dir, chosen.name });
    const dest = try std.fmt.allocPrint(allocator, "{s}/{s}", .{ folderPath, dest_rel });

    prompt.ok(try std.fmt.allocPrint(allocator, "plugin  {s}  ({s})", .{ chosen.name, sources.sourceLabel(chosen.source) }));

    if (util.fileExists(io, dest)) {
        prompt.err(try std.fmt.allocPrint(allocator, "Already exists: {s}", .{dest}));
        return 1;
    }

    if (dry_run) {
        prompt.ok(try std.fmt.allocPrint(allocator, "Would create {s}", .{makeKindLabel(kind)}));
        prompt.muted(try std.fmt.allocPrint(allocator, "    + {s}", .{dest}));
        prompt.outro("Dry run — nothing written (and NOT published)");
        return 0;
    }

    const tpl_dir = (try services.resolveTemplatesDir(allocator, io, env)) orelse {
        prompt.err("Could not locate the plugin scaffolding templates directory.");
        return 1;
    };
    const src = try std.fmt.allocPrint(allocator, "{s}/plugin/{s}", .{ tpl_dir, tpl_src });
    const raw = Dir.cwd().readFileAlloc(io, src, allocator, .limited(1024 * 1024)) catch {
        prompt.err(try std.fmt.allocPrint(allocator, "Missing template: {s}", .{src}));
        return 1;
    };
    const data = try renderTokens(allocator, raw, studlyName, lowerName);
    if (util.parentOf(dest)) |parent| try Dir.cwd().createDirPath(io, parent);
    try Dir.cwd().writeFile(io, .{ .sub_path = dest, .data = data });

    prompt.ok(try std.fmt.allocPrint(allocator, "Created {s} in plugin {s}", .{ makeKindLabel(kind), chosen.name }));
    prompt.muted(try std.fmt.allocPrint(allocator, "    {s}", .{dest}));
    prompt.note("Not published — it ships with the plugin and publishes on enable.");
    prompt.outro("Done");
    return 0;
}

// ── help ──────────────────────────────────────────────────────────────────────

fn printHelp() void {
    prompt.intro("hkm plugins");
    prompt.section("Usage");
    prompt.item("hkm plugins [path|name]", "show the plugins/modules a project enables");
    prompt.item("hkm plugins verify [proj]", "audit enabled plugins: wiring, deps + copied assets/views/migrations/configs");
    prompt.item("hkm plugins enable <plugin> [proj]", "wire a plugin into the project bootstrap");
    prompt.item("hkm plugins disable <plugin> [proj]", "remove a plugin from the project bootstrap");
    prompt.item("hkm plugins update [plugin] [proj]", "publish NEW assets of enabled plugin(s) + migrate them; wire any missing Support/helpers.php require");
    prompt.item("hkm plugins upgrade [proj]", "full upgrade after plugins changed: heal new deps, publish/migrate, reconcile plugin SPLITS (moves migration ownership without dropping data)");
    prompt.item("hkm plugins create <name> [proj]", "scaffold a new plugin (project, or --kernel)");
    prompt.item("hkm plugins delete <name> [proj]", "delete a plugin folder from disk");
    prompt.item("hkm plugins make:migration <plugin> <name>", "add a migration INTO a plugin (not published)");
    prompt.item("hkm plugins make:seeder|make:factory <plugin> <name>", "add a seeder/factory into a plugin");
    prompt.blank();
    prompt.section("Options");
    prompt.item("--all, -a", "also list available-but-disabled plugins");
    prompt.item("--essential, -e", "enable into withEssentialModules() (default: on-demand)");
    prompt.item("--kernel, -k", "create/delete a KERNEL plugin (kernel monorepo only)");
    prompt.item("--dry-run, -n", "preview the change without writing");
    prompt.item("--fix, -f", "verify: publish missing assets + wire Support requires");
    prompt.item("--help, -h", "show this help");
    prompt.blank();
    prompt.section("Sources");
    prompt.item("project", "<project>/plugins — a project's own local plugins");
    prompt.item("kernel", "<kernel>/plugins — shared, contributor-protected");
    prompt.item("(same name)", "you are prompted to choose which source to act on");
    prompt.blank();
    prompt.section("Notes");
    prompt.item("enable", "resolves requires[] deps (e.g. Tenancy → Database/Auth/User), publishes assets + migrate:run");
    prompt.item("disable", "won't orphan dependents (offers to cascade); offers to prune now-unused deps, keeping shared ones");
    prompt.item("upgrade", "split-safe: a migration moved to a new plugin keeps its data; only manifest ownership transfers, no DDL re-runs (aliases: reconcile/migrate)");
    prompt.item("create", "scaffolds a complete plugin (config, migration, seeder, factory, view)");
    prompt.item("Support helpers", "a plugin's Support/helpers.php is require_once'd in the bootstrap on enable, removed on disable");
    prompt.item("aliases", "enable=add/on · disable=remove/off · create=new/make · delete=del/rm");
    prompt.blank();
    prompt.section("Resolution");
    prompt.item("path", "a directory holding proj.json");
    prompt.item("name", "a project registered in the kernel registry");
    prompt.item("(none)", "the current working directory");
    prompt.outro("Reads app/bootstrap/app.php + kernel plugins/*/module.json");
}
