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
const penv = @import("../lib/plugin_env.zig");
const ui = @import("../lib/plugin_ui.zig");
const deps = @import("../lib/plugin_deps.zig");
const installer = @import("../lib/plugin_install.zig");
const pgit = @import("../lib/plugin_git.zig");
const plock = @import("../lib/plugin_lock.zig");
const pregistry = @import("../lib/plugin_registry.zig");
const banner = @import("../lib/banner.zig");
const plugin_ui = @import("../lib/plugin_ui.zig");
const registry = @import("../lib/registry.zig");
const domains = @import("../lib/plugin_domains.zig");
const pstore = @import("../lib/plugin_store.zig");
const userconfig = @import("../lib/userconfig.zig");
const plugin_assets = @import("../lib/plugin_assets.zig");
const services = @import("../lib/services.zig");

const Dir = std.Io.Dir;
const Io = std.Io;
const EnvMap = std.process.Environ.Map;

const Source = sources.Source;
const Located = sources.Located;
const Enabled = boot.Enabled;
const Activation = boot.Activation;

const Action = enum { analyze, verify, recover, enable, disable, update, upgrade, create, delete, make_migration, make_seeder, make_factory, install, uninstall, versions, outdated, sync_lock, prune, domain_map, store_cmd };

pub fn run(allocator: std.mem.Allocator, io: Io, env: *EnvMap, args: []const []const u8) !u8 {
    var action: Action = .analyze;
    var show_all = false;
    var essential = false;
    var dry_run = false;
    var want_kernel = false;
    var fix = false;
    // --version=vX.Y.Z for install/update; empty means "newest allowed".
    var want_version: []const u8 = "";
    // --force: overwrite a working copy with uncommitted changes.
    var force = false;
    // --full: clone full history instead of --depth 1.
    var full_clone = false;
    // --no-verify: install without running the plugin's test suite. The run
    // costs a composer install per plugin, so it has to be skippable.
    var verify = true;
    // Was --verify / --no-verify given explicitly? A single deliberate install
    // verifies by default; a BATCH (restore, or a dependency closure) does not,
    // because the cost is a composer install plus a phpunit run PER PLUGIN and
    // the versions being restored were tested when they were released. An
    // explicit flag overrides either default.
    var verify_explicit = false;
    // --no-deps: install ONLY what was named. Dependencies come from the
    // plugin's requires[] and are fetched by default, because a plugin without
    // them is on disk and still cannot boot.
    var with_deps = true;
    // --set=<path> / --migrate for `hkm plugins store`.
    var set_store: []const u8 = "";
    // --latest: ignore the lock's pinned versions and take the newest release
    // of every plugin. The opposite of the default, which is reproducibility.
    var want_latest = false;
    var migrate_store = false;

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
        } else if (std.mem.startsWith(u8, a, "--version=")) {
            want_version = a["--version=".len..];
        } else if (std.mem.eql(u8, a, "--force")) {
            force = true;
        } else if (std.mem.eql(u8, a, "--full")) {
            full_clone = true;
        } else if (std.mem.eql(u8, a, "--no-verify") or std.mem.eql(u8, a, "--skip-tests")) {
            verify = false;
            verify_explicit = true;
        } else if (std.mem.eql(u8, a, "--verify") or std.mem.eql(u8, a, "--run-tests")) {
            verify = true;
            verify_explicit = true;
        } else if (std.mem.eql(u8, a, "--no-deps")) {
            with_deps = false;
        } else if (std.mem.startsWith(u8, a, "--set=")) {
            set_store = a["--set=".len..];
        } else if (std.mem.eql(u8, a, "--migrate")) {
            migrate_store = true;
        } else if (std.mem.eql(u8, a, "--latest") or std.mem.eql(u8, a, "--upgrade")) {
            want_latest = true;
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
        .install => {
            // No plugin named → install everything the PROJECT declares, the
            // way `composer install` does. Disambiguated the same way `update`
            // is: an operand that resolves to a project root is the target, not
            // a plugin — otherwise `hkm plugins install ./my-app` would try to
            // install the project directory as a git remote.
            const batch_verify = verify_explicit and verify;
            if (ops.len == 0) {
                return restoreCmd(allocator, io, env, ".", .{
                    .dry_run = dry_run,
                    .force = force,
                    .full = full_clone,
                    .verify = batch_verify,
                }, with_deps, want_latest);
            }
            if (ops.len == 1) {
                if ((try services.resolveRoot(allocator, io, env, op(ops, 0))) != null) {
                    return restoreCmd(allocator, io, env, op(ops, 0), .{
                        .dry_run = dry_run,
                        .force = force,
                        .full = full_clone,
                        .verify = batch_verify,
                    }, with_deps, want_latest);
                }
            }
            return installCmd(allocator, io, env, op(ops, 0), op(ops, 1), .{
                .version = want_version,
                .dry_run = dry_run,
                .force = force,
                .full = full_clone,
                .verify = verify,
            }, with_deps);
        },
        .uninstall => {
            if (ops.len == 0) {
                prompt.err("Usage: hkm plugins uninstall <plugin> [path|name] [--dry-run]");
                return 2;
            }
            return uninstallCmd(allocator, io, env, op(ops, 0), op(ops, 1), dry_run, force);
        },
        .versions => {
            if (ops.len == 0) {
                prompt.err("Usage: hkm plugins versions <plugin>");
                return 2;
            }
            return versionsCmd(allocator, io, env, op(ops, 0));
        },
        .outdated => return outdatedCmd(allocator, io, env, op(ops, 0)),
        .prune => return pruneCmd(allocator, io, env, op(ops, 0), dry_run),
        .domain_map => return domainsCmd(allocator, io, env, op(ops, 0)),
        .store_cmd => return storeCmd(allocator, io, env, set_store, migrate_store, dry_run),
        .sync_lock => return lockCmd(allocator, io, env, op(ops, 0), dry_run, .{
            .version = want_version,
            .force = force,
            .full = full_clone,
        }),
        .verify => return verifyPlugins(allocator, io, env, op(ops, 0), fix, dry_run),
        .recover => return recoverAssets(allocator, io, env, op(ops, 0), dry_run),
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
    if (std.mem.eql(u8, a, "create") or std.mem.eql(u8, a, "new") or
        std.mem.eql(u8, a, "make") or std.mem.eql(u8, a, "scaffold")) return .create;
    if (std.mem.eql(u8, a, "delete") or std.mem.eql(u8, a, "del") or std.mem.eql(u8, a, "destroy") or
        std.mem.eql(u8, a, "rm")) return .delete;
    if (std.mem.eql(u8, a, "make:migration") or std.mem.eql(u8, a, "make-migration") or
        std.mem.eql(u8, a, "migration")) return .make_migration;
    if (std.mem.eql(u8, a, "make:seeder") or std.mem.eql(u8, a, "make-seeder") or
        std.mem.eql(u8, a, "seeder")) return .make_seeder;
    if (std.mem.eql(u8, a, "make:factory") or std.mem.eql(u8, a, "make-factory") or
        std.mem.eql(u8, a, "factory")) return .make_factory;
    // Git-backed lifecycle. `fetch`/`get` alias install because that is what
    // people type first.
    if (std.mem.eql(u8, a, "install") or std.mem.eql(u8, a, "fetch") or std.mem.eql(u8, a, "get")) return .install;
    if (std.mem.eql(u8, a, "uninstall")) return .uninstall;
    if (std.mem.eql(u8, a, "versions") or std.mem.eql(u8, a, "releases")) return .versions;
    if (std.mem.eql(u8, a, "outdated")) return .outdated;
    if (std.mem.eql(u8, a, "lock")) return .sync_lock;
    if (std.mem.eql(u8, a, "prune") or std.mem.eql(u8, a, "gc")) return .prune;
    if (std.mem.eql(u8, a, "domains")) return .domain_map;
    if (std.mem.eql(u8, a, "store") or std.mem.eql(u8, a, "cache")) return .store_cmd;
    if (std.mem.eql(u8, a, "list") or std.mem.eql(u8, a, "ls") or
        std.mem.eql(u8, a, "analyze") or std.mem.eql(u8, a, "analyse") or
        std.mem.eql(u8, a, "status")) return .analyze;
    if (std.mem.eql(u8, a, "verify") or std.mem.eql(u8, a, "check") or std.mem.eql(u8, a, "doctor") or
        std.mem.eql(u8, a, "scan") or std.mem.eql(u8, a, "audit")) return .verify;
    if (std.mem.eql(u8, a, "recover") or std.mem.eql(u8, a, "recover-assets") or
        std.mem.eql(u8, a, "rebuild") or std.mem.eql(u8, a, "reindex")) return .recover;
    return null;
}

/// Resolve a project root from `target` or error out. Shared by every action.
fn requireRoot(allocator: std.mem.Allocator, io: Io, env: *EnvMap, target: []const u8) !?[]const u8 {
    return (try services.resolveRoot(allocator, io, env, target)) orelse {
        // Distinguish "you named something wrong" from "you are standing in the
        // wrong directory". The second is what happens when a command that
        // defaults to the cwd is run from the kernel checkout, and telling
        // someone that '.' is not a registered name explains nothing.
        const implicit = target.len == 0 or std.mem.eql(u8, target, ".");
        if (implicit) {
            prompt.err("This directory is not a project — there is no proj.json here.");
        } else {
            prompt.err(try std.fmt.allocPrint(
                allocator,
                "'{s}' is neither a project folder (with proj.json) nor a registered name.",
                .{target},
            ));
        }

        prompt.muted("  run it from inside a project, or name one:  hkm plugins <cmd> <path|name>");
        listKnownProjects(allocator, io, env);
        return null;
    };
}

/// Name the projects the kernel already knows, so "name one" is actionable
/// rather than an instruction to go and remember what they are called.
fn listKnownProjects(allocator: std.mem.Allocator, io: Io, env: *EnvMap) void {
    const jsonPath = (registry.resolvePath(allocator, io, env) catch return) orelse return;
    const entries = registry.list(allocator, io, jsonPath) catch return;
    if (entries.len == 0) return;

    prompt.muted("");
    prompt.muted("  registered projects:");
    for (entries) |e| {
        prompt.muted(std.fmt.allocPrint(allocator, "    {s: <16}{s}", .{ e.name, e.path }) catch continue);
    }
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
fn verifyPlugins(allocator: std.mem.Allocator, io: Io, env: *EnvMap, target: []const u8, fix: bool, dry_run: bool) !u8 {
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
        // A link into the shared store whose target is gone. dirExists follows
        // symlinks, so this is indistinguishable from "never installed" unless
        // it is checked for separately — and the two need different repairs.
        var dangling: ?[]const u8 = null;
        for (search) |src| {
            const d = srcs.dirFor(src) orelse continue;
            const fp = try std.fmt.allocPrint(allocator, "{s}/{s}", .{ d, e.name });
            if (util.dirExists(Dir.cwd(), io, fp)) {
                plugins_dir = d;
                plugin_path = fp;
                src_label = sources.sourceLabel(src);
                break;
            }
            if (dangling == null and util.isSymlink(io, fp)) dangling = fp;
        }

        if (e.solves) |s| {
            const ver = if (e.version) |v| try std.fmt.allocPrint(allocator, " · v{s}", .{v}) else "";
            prompt.muted(try std.fmt.allocPrint(allocator, "    solves: {s}{s}", .{ s, ver }));
        }
        if (plugin_path) |pp| {
            prompt.muted(try std.fmt.allocPrint(allocator, "    source: {s}  ({s})", .{ src_label, pp }));
        } else if (dangling) |dp| {
            prompt.err(try std.fmt.allocPrint(
                allocator,
                "    \u{2717} broken link — {s} points into the shared store, but the target is gone",
                .{dp},
            ));
            prompt.muted("        repair: hkm plugins lock        (restores every plugin at its locked version)");
            issues += 1;
        } else {
            prompt.err("    \u{2717} plugin folder not found on disk — cannot verify its assets");
            prompt.muted(try std.fmt.allocPrint(allocator, "        install: hkm plugins install {s}", .{e.name}));
            issues += 1;
        }

        // 1. requires[] — each must be solved by another ENABLED plugin, be a
        //    kernel port (no plugin provides it), else it is a real gap.
        const meta = if (plugins_dir) |pd| try sources.readModuleMeta(allocator, io, pd, e.name) else null;
        const requires = if (meta) |m| m.requires else &[_]sources.Requirement{};
        if (requires.len > 0) {
            prompt.muted("    requires:");
            for (requires) |r| {
                const req = r.domain;
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
                // Checked against the require itself, not just its marker
                // comment — see supportRequireWired.
                const expr = (try supportHelpersExpr(allocator, io, env, root, pp)) orelse "";
                if (boot.supportRequireWired(allocator, source, e.name, expr)) {
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
        // --dry-run is threaded through, not dropped. It used to pass a
        // hardcoded false, so `verify --fix --dry-run` — a command whose whole
        // purpose is to show what WOULD change — published assets, ran
        // migrations and rewrote the bootstrap.
        prompt.section(if (dry_run)
            "Fixing (dry run) — what would be published and wired"
        else
            "Fixing — publishing missing assets + wiring Support requires");
        _ = try updatePlugins(allocator, io, env, "", target, dry_run);
        prompt.note("Re-run `hkm plugins verify` to confirm; unmet requires need `hkm plugins enable <dep>`.");
        return 0;
    }

    prompt.note("Fix with:  hkm plugins update   (or `hkm plugins verify --fix`)");
    prompt.note("Unmet requires need:  hkm plugins enable <dependency>");
    prompt.outro(try std.fmt.allocPrint(allocator, "{d} plugin(s) need attention", .{with_issues}));
    return 1;
}

// ── recover (rebuild var/plugin-assets.json from the filesystem) ───────────────

/// `hkm plugins recover [path|name] [--dry-run]` — rebuild the project's
/// `var/plugin-assets.json` manifest from ground truth. For every ENABLED
/// plugin, it records the published assets that actually exist in the project,
/// healing a lost, truncated, or drifted manifest. It copies nothing; use
/// `hkm plugins update` to re-publish assets that are physically missing.
fn recoverAssets(allocator: std.mem.Allocator, io: Io, env: *EnvMap, target: []const u8, dry_run: bool) !u8 {
    const root = (try requireRoot(allocator, io, env, target)) orelse return 1;

    prompt.intro(try std.fmt.allocPrint(allocator, "Recover plugin-assets manifest for {s}", .{root}));

    var found: std.ArrayList(assets.RecoveredPlugin) = .empty;
    const res = try assets.recoverEnabled(allocator, io, env, root, dry_run, &found);

    if (found.items.len == 0) {
        prompt.muted("No enabled plugins with on-disk assets were found.");
        prompt.note("Is the project bootstrap wiring plugins in withModules()/withEssentialModules()?");
        prompt.outro(try std.fmt.allocPrint(allocator, "{s}/var/plugin-assets.json", .{root}));
        return 0;
    }

    for (found.items) |p| {
        prompt.item(p.name, sources.sourceLabel(p.source));
        prompt.muted(try std.fmt.allocPrint(allocator, "    {d} asset(s)", .{p.files}));
    }

    if (res.unresolved > 0) {
        prompt.warn(try std.fmt.allocPrint(allocator, "{d} enabled plugin(s) could not be located on disk — skipped.", .{res.unresolved}));
    }

    if (dry_run) {
        prompt.note("--dry-run: manifest left unchanged.");
        prompt.outro(try std.fmt.allocPrint(allocator, "{d} plugin(s), {d} asset(s) would be recorded", .{ res.plugins, res.files }));
    } else {
        prompt.ok(try std.fmt.allocPrint(allocator, "Rebuilt manifest: {d} plugin(s), {d} asset(s)", .{ res.plugins, res.files }));
        prompt.outro(try std.fmt.allocPrint(allocator, "{s}/var/plugin-assets.json", .{root}));
    }
    return 0;
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
    // Not on disk: fetch it from its git remote before wiring it.
    //
    // This used to wire the plugin into the bootstrap "by name anyway", which
    // produced a project that referenced a Provider class that did not exist —
    // the failure landed at boot, as a class-not-found, rather than here where
    // the cause is obvious. Fetching first means enable either works or says
    // why it cannot.
    var fetched: ?installer.Outcome = null;
    if (located == null and !dry_run) {
        prompt.muted(try std.fmt.allocPrint(allocator, "{s} is not installed — fetching it…", .{folder}));

        // Honour a remote the lock already records: a plugin installed from a
        // URL must be re-fetched from that URL, not from the registry's guess
        // at the same name.
        const known = if (plock.read(allocator, io, root)) |l| l.find(folder) else |_| null;
        const outcome = try installer.install(allocator, io, env, root, folder, .{
            .remote = if (known) |k| k.remote else "",
        });
        switch (outcome) {
            .refused => |why| {
                prompt.err(why);
                return 1;
            },
            .installed, .up_to_date, .linked, .updated => {
                fetched = outcome;
                _ = try installer.report(allocator, folder, outcome, false);
            },
        }
    } else if (located == null) {
        prompt.warn(try std.fmt.allocPrint(
            allocator,
            "{s} is not installed. A real run would fetch it from git first.",
            .{folder},
        ));
    }

    // Build the ordered enable list: unmet dependencies first, target last.
    var needed: std.ArrayList(deps.Provider) = .empty;
    var missing: std.ArrayList([]const u8) = .empty;
    try deps.requiredClosure(allocator, cat, folder, &needed, &missing);

    const Step = struct { folder: []const u8, dir: ?[]const u8, essential: bool, dependency: bool };
    var steps: std.ArrayList(Step) = .empty;
    for (needed.items) |dep| {
        if (boot.findEnabled(enabled, dep.located.name) != null) continue; // already wired
        // Deps reach the route graph through requires[], so on-demand is right
        // for them — UNLESS the plugin itself says it cannot work that way.
        try steps.append(allocator, .{
            .folder = dep.located.name,
            .dir = dep.located.dir,
            .essential = declaresEssential(allocator, io, dep.located.dir, dep.located.name),
            .dependency = true,
        });
    }
    const target_enabled = boot.findEnabled(enabled, folder) != null;
    if (!target_enabled) {
        const dir = if (located) |l| l.dir else if (deps.findByName(cat, folder)) |p| p.located.dir else null;
        // -e forces it; otherwise the plugin's own manifest decides.
        const as_essential = essential or
            (if (dir) |d| declaresEssential(allocator, io, d, folder) else false);
        if (as_essential and !essential) {
            prompt.muted(try std.fmt.allocPrint(
                allocator,
                "{s} declares activation: essential — wiring it into withEssentialModules()",
                .{folder},
            ));
        }
        try steps.append(allocator, .{ .folder = folder, .dir = dir, .essential = as_essential, .dependency = false });
    }

    if (fetched) |outcome| {
        // Record it only after the fetch succeeded, so the lock never names a
        // plugin the project does not actually have.
        switch (outcome) {
            .installed, .up_to_date, .linked => |e| try installer.recordInLock(allocator, io, root, e),
            .updated => |u| try installer.recordInLock(allocator, io, root, u.to),
            .refused => {},
        }
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


/// Which kernel-home helper THIS project defines: `hkm_kernel_home` after the
/// rename, `psp_kernel_home` before it.
///
/// The name is emitted into the project's own config, and the function is
/// defined by the project's own bootstrap — so writing the new name into a
/// project generated before the rename produces a config that fatals on an
/// undefined function the next time the project boots. Read the bootstrap and
/// use what is actually there.
fn kernelHomeFn(allocator: std.mem.Allocator, io: Io, root: []const u8) []const u8 {
    var buf: [4096]u8 = undefined;
    const path = std.fmt.bufPrint(&buf, "{s}/app/bootstrap/kernel-autoload.php", .{util.trimSlash(root)}) catch
        return "hkm_kernel_home";

    const source = Dir.cwd().readFileAlloc(io, path, allocator, .limited(256 * 1024)) catch
        return "hkm_kernel_home";

    // Only the legacy name present → this project predates the rename.
    if (std.mem.indexOf(u8, source, "function hkm_kernel_home") == null and
        std.mem.indexOf(u8, source, "function psp_kernel_home") != null)
    {
        return "psp_kernel_home";
    }

    return "hkm_kernel_home";
}

/// If the plugin at `pluginPath` ships a `Support/helpers.php`, return the PHP
/// `require_once` EXPRESSION (everything after `require_once `, incl. trailing
/// `;`) that references it. The expression is made as portable as the plugin's
/// location allows:
///   • inside the project (incl. its `vendor/` composer packages)
///       → `__DIR__ . '/../..<rel>'`      (relative to the bootstrap dir)
///   • inside the kernel home (HKM_KERNEL_HOME / discovered kernel root)
///       → `hkm_kernel_home('<kroot>') . '<rel>'`  (resolves + guards at runtime)
///   • anywhere else (a globally-installed package, an odd mount)
///       → `'<abs>'`                       (absolute literal — last resort)
/// `null` when the plugin ships no helpers file to wire.
pub fn supportHelpersExpr(allocator: std.mem.Allocator, io: Io, env: *EnvMap, root: []const u8, pluginPath: []const u8) !?[]const u8 {
    const helpers = util.trimSlash(try std.fmt.allocPrint(allocator, "{s}/Support/helpers.php", .{pluginPath}));
    if (!util.fileExists(io, helpers)) return null;

    // 1. Inside the project — bootstrap lives at <root>/app/bootstrap, so
    //    `__DIR__ . '/../..'` is <root>. Covers project plugins AND anything under
    //    the project's own vendor/ (composer packages resolve relative too).
    const r = util.trimSlash(root);
    if (util.isInside(helpers, r)) {
        return try std.fmt.allocPrint(allocator, "__DIR__ . '{s}';", .{try phpQuote(allocator, try std.fmt.allocPrint(allocator, "/../..{s}", .{helpers[r.len..]}))});
    }

    // 2. Inside the kernel home — reference it through hkm_kernel_home() (defined
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
                    "{s}('{s}') . '{s}';",
                    .{
                        kernelHomeFn(allocator, io, root),
                        try phpQuote(allocator, kroot),
                        try phpQuote(allocator, helpers[kroot.len..]),
                    },
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

            const vars = try penv.readVars(allocator, io, cd, folder);
            if (vars.len > 0) {
                const plan = try penv.seed(allocator, io, root, folder, vars, true);
                if (plan.added.len > 0) {
                    prompt.muted(try std.fmt.allocPrint(
                        allocator,
                        "    + would add {d} env var(s) to .env ({d} already present):",
                        .{ plan.added.len, plan.skipped },
                    ));
                    for (plan.added) |v| prompt.muted(try std.fmt.allocPrint(allocator, "        {s}", .{v.key}));
                }
            }
        }
        return updated;
    }

    if (meta) |m| {
        if (m.solves) |s| prompt.muted(try std.fmt.allocPrint(allocator, "    solves: {s}", .{s}));
        // Enabling a plugin activates EVERY route it declares, at once. Saying
        // so is the difference between a project that chose its HTTP surface
        // and one that inherited it: you cannot review, or veto, what you were
        // never shown.
        if (m.route_count > 0) {
            prompt.muted(try std.fmt.allocPrint(
                allocator,
                "    publishes {d} HTTP route(s) — audit with `hkm route:list --plugin`",
                .{m.route_count},
            ));
            // Not an error. A login form, robots.txt, or a page shell whose data
            // sits behind a filtered endpoint are all legitimately unfiltered —
            // this is the subset that has to be justified one by one.
            if (m.unfiltered_routes > 0) {
                prompt.note(try std.fmt.allocPrint(
                    allocator,
                    "{d} of them run NO route filter — review with `hkm route:list --unfiltered --plugin`, " ++
                        "and veto what you will not expose via proj.json routePolicy.",
                    .{m.unfiltered_routes},
                ));
            }
        }
        if (m.doc != null or m.description != null) prompt.muted("    documentation comment added above the entry");
    }
    if (support_expr) |expr|
        prompt.muted(try std.fmt.allocPrint(allocator, "    wired Support/helpers.php  (require_once {s})", .{expr}));

    if (chosenDir) |cd| {
        // Seed the plugin's declared env vars BEFORE migrations run: a
        // migration reads the database config, and the whole point of writing
        // the block is that the operator can see and set it first.
        const vars = try penv.readVars(allocator, io, cd, folder);
        if (vars.len > 0) {
            const seeded = penv.seed(allocator, io, root, folder, vars, false) catch |e| blk: {
                prompt.warn(try std.fmt.allocPrint(
                    allocator,
                    "could not write .env ({t}) — add {s}'s config[] variables by hand.",
                    .{ e, folder },
                ));
                break :blk penv.Seeded{ .added = &.{}, .skipped = 0, .path = "", .created = false };
            };

            if (seeded.added.len > 0) {
                if (seeded.created) prompt.muted("    created .env");
                prompt.ok(try std.fmt.allocPrint(
                    allocator,
                    "Added {d} env var(s) to .env ({d} already present)",
                    .{ seeded.added.len, seeded.skipped },
                ));

                // Name the ones that BLOCK a boot separately. Everything else is
                // a knob with a working default; these are the ones the operator
                // has to act on, and burying them in a list of twenty would mean
                // finding out from a failed boot instead.
                var needs_value: usize = 0;
                for (seeded.added) |v| {
                    if (v.required and v.default == null) needs_value += 1;
                }
                if (needs_value > 0) {
                    prompt.warn(try std.fmt.allocPrint(
                        allocator,
                        "{d} of them are REQUIRED and have no default — the boot fails until you set them:",
                        .{needs_value},
                    ));
                    for (seeded.added) |v| {
                        if (v.required and v.default == null) {
                            prompt.muted(try std.fmt.allocPrint(allocator, "    {s}", .{v.key}));
                        }
                    }
                }
            }
        }

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

// ── update (analyse + sync assets/ui + migrate) ────────────────────────────────

/// Update already-enabled plugins. Per plugin, ANALYSE every publishable
/// surface (config, database migrations/tenant-template/seeders/factories,
/// resources, ui) against what the project holds, then bring the project in
/// sync: NEW plugin files are published, files whose content DRIFTED from the
/// plugin's version are refreshed (plugin wins), a drifted ui/ mirror is
/// re-synced, and — when any central OR tenant migration was new/changed —
/// the plugin's pending migrations run (a fresh batch in the shared tracking
/// table; tenants too). `only` limits to one plugin.
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

    // Discover plugin UIs once so each plugin's frontend mirror can be
    // analysed alongside its assets (only when the project has a frontend).
    const frontend = try std.fmt.allocPrint(allocator, "{s}/frontend", .{root});
    const has_frontend = util.dirExists(Dir.cwd(), io, frontend);
    var ui_plugins: std.ArrayList(ui.UiPlugin) = .empty;
    if (has_frontend) try ui.discover(allocator, io, env, root, &ui_plugins);

    var touched: usize = 0;
    var new_total: usize = 0;
    var changed_total: usize = 0;
    var ui_synced: usize = 0;
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

        // Analyse every publishable surface (config, database, resources)
        // against the project: NEW files are published, content-drifted files
        // are refreshed with the plugin's version.
        var new_paths: std.ArrayList([]const u8) = .empty;
        var changed_paths: std.ArrayList([]const u8) = .empty;
        try assets.syncAssets(allocator, io, fp, root, dry_run, &new_paths, &changed_paths);

        // Analyse the plugin's ui/ mirror (frontend/plugins/<slug>) the same
        // way — re-sync when it drifted; a symlinked mirror is always current.
        var ui_dirty: ?ui.UiPlugin = null;
        for (ui_plugins.items) |up| {
            if (!util.eqlIgnoreCase(up.name, e.name)) continue;
            if (try ui.mirrorDiffers(allocator, io, root, up)) ui_dirty = up;
            break;
        }

        if (new_paths.items.len == 0 and changed_paths.items.len == 0 and ui_dirty == null) {
            prompt.muted(try std.fmt.allocPrint(allocator, "{s}: up to date — config, database, resources and ui all match.", .{e.name}));
            continue;
        }

        touched += 1;
        new_total += new_paths.items.len;
        changed_total += changed_paths.items.len;
        if (new_paths.items.len > 0) {
            const verb = if (dry_run) "Would publish" else "Published";
            prompt.ok(try std.fmt.allocPrint(allocator, "{s} {d} new asset(s) for {s}", .{ verb, new_paths.items.len, e.name }));
            for (new_paths.items) |p| prompt.muted(try std.fmt.allocPrint(allocator, "    + {s}", .{p}));
        }
        if (changed_paths.items.len > 0) {
            const verb = if (dry_run) "Would refresh" else "Refreshed";
            prompt.ok(try std.fmt.allocPrint(allocator, "{s} {d} changed asset(s) for {s}", .{ verb, changed_paths.items.len, e.name }));
            for (changed_paths.items) |p| prompt.muted(try std.fmt.allocPrint(allocator, "    ~ {s}", .{p}));
        }
        if (ui_dirty) |up| {
            if (dry_run) {
                ui_synced += 1;
                prompt.ok(try std.fmt.allocPrint(allocator, "Would sync ui for {s} → frontend/plugins/{s}", .{ e.name, up.slug }));
            } else {
                const n = try ui.syncPlugin(allocator, io, root, up, false);
                ui_synced += 1;
                prompt.ok(try std.fmt.allocPrint(allocator, "Synced ui for {s} → frontend/plugins/{s} ({d} file(s))", .{ e.name, up.slug, n }));
            }
        }

        const migrations_dirty = assets.hasAnyMigrations(new_paths.items) or assets.hasAnyMigrations(changed_paths.items);
        if (dry_run) {
            if (migrations_dirty) prompt.muted("    + would run pending migrations (central + tenants)");
            continue;
        }

        // Merge the synced paths into the manifest's recorded list, then run
        // the plugin's pending migrations when any central or tenant migration
        // was new/changed (already-applied ones are skipped by name).
        const existing = (try assets.publishedPathsFor(allocator, io, root, e.name)) orelse &[_][]const u8{};
        var merged: std.ArrayList([]const u8) = .empty;
        for (existing) |p| try merged.append(allocator, p);
        for (new_paths.items) |p| {
            if (!containsStr(merged.items, p)) try merged.append(allocator, p);
        }
        for (changed_paths.items) |p| {
            if (!containsStr(merged.items, p)) try merged.append(allocator, p);
        }
        try assets.recordPublished(allocator, io, root, e.name, merged.items);

        if (migrations_dirty) {
            try assets.runPluginMigrations(allocator, io, env, root, autoload, e.name, merged.items);
        }
    }

    // A re-synced ui/ may have changed its alias/entry — regenerate the glue
    // files (manifest.json, index.ts, tsconfig.plugins.json) from the live set.
    if (ui_synced > 0 and !dry_run) try ui.writeGlue(allocator, io, root, ui_plugins.items);

    if (only.len > 0 and !matched) {
        prompt.warn(try std.fmt.allocPrint(allocator, "{s} is not enabled in this project.", .{only}));
        prompt.outro("Nothing to update");
        return 0;
    }

    if (dry_run) {
        prompt.outro(try std.fmt.allocPrint(allocator, "Dry run — {d} plugin(s): {d} new · {d} changed asset(s) · {d} ui mirror(s) to sync · {d} Support require(s) to wire", .{ touched, new_total, changed_total, ui_synced, wired }));
        return 0;
    }
    if (wired > 0) try Dir.cwd().writeFile(io, .{ .sub_path = bootstrap, .data = bootstrap_src });
    prompt.outro(try std.fmt.allocPrint(allocator, "Updated {d} plugin(s) · {d} new · {d} refreshed asset(s) · {d} ui mirror(s) synced · {d} Support require(s) wired", .{ touched, new_total, changed_total, ui_synced, wired }));
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

    // The name is the user's, and it survives verbatim.
    //
    // It used to go through studly()+lower(), which silently ate the
    // underscores: `make:migration Demo add_widgets` wrote
    // `create_addwidgets_table.php` around `$schema->create('addwidgets')` — a
    // name nobody typed, describing a table nobody wanted.
    const snakeName = try util.snake(allocator, name);
    const suffix = switch (kind) {
        .migration => "",
        .seeder => "Seeder",
        .factory => "Factory",
    };
    // `make:seeder WidgetSeeder` means WidgetSeeder, not WidgetSeederSeeder.
    const baseName = util.stripSuffix(name, suffix);
    const studlyName = try util.studly(allocator, baseName);
    const migrationName = try migrationFileName(allocator, snakeName);
    const tableName = try migrationTable(allocator, snakeName);

    const tpl_src = switch (kind) {
        // A name that alters gets a body that alters. Scaffolding create() for
        // `add_widgets_to_orders` generated code that fails on any environment
        // where `orders` already exists — which is all of them.
        .migration => if (std.mem.startsWith(u8, migrationName, "create_"))
            "migration.php"
        else
            "migration_alter.php",
        .seeder => "seeder.php",
        .factory => "factory.php",
    };
    const dest_rel = switch (kind) {
        .migration => try std.fmt.allocPrint(allocator, "database/migrations/{s}_{s}.php", .{ try util.timestampPrefix(allocator), migrationName }),
        .seeder => try std.fmt.allocPrint(allocator, "database/seeders/{s}Seeder.php", .{studlyName}),
        .factory => try std.fmt.allocPrint(allocator, "database/factories/{s}Factory.php", .{studlyName}),
    };
    // The migration template names a TABLE; the others name a class.
    const lowerName = if (kind == .migration) tableName else try util.lower(allocator, studlyName);

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


/// Verbs a migration name can start with. A name beginning with one already
/// says what it does, so it is used as written; anything else is wrapped as
/// `create_<name>_table`, which is what a bare noun ("widgets") means.
const migration_verbs = [_][]const u8{
    "create_", "add_", "update_", "drop_", "remove_", "rename_", "alter_", "change_", "modify_",
};

fn startsWithVerb(snakeName: []const u8) bool {
    for (migration_verbs) |v| {
        if (std.mem.startsWith(u8, snakeName, v)) return true;
    }
    return false;
}

/// The migration file name (no timestamp, no extension).
fn migrationFileName(allocator: std.mem.Allocator, snakeName: []const u8) ![]const u8 {
    if (startsWithVerb(snakeName)) return snakeName;
    return std.fmt.allocPrint(allocator, "create_{s}_table", .{snakeName});
}

/// The table a migration name is about.
///
/// A best guess, and deliberately a plain one — it seeds the scaffold, and the
/// author edits it. `add_widgets_to_orders` is about `orders`, not `widgets`:
/// the thing after `_to_` is the table being changed.
fn migrationTable(allocator: std.mem.Allocator, snakeName: []const u8) ![]const u8 {
    var t = snakeName;

    if (std.mem.indexOf(u8, t, "_to_")) |i| return allocator.dupe(u8, t[i + 4 ..]);
    if (std.mem.indexOf(u8, t, "_from_")) |i| return allocator.dupe(u8, t[i + 6 ..]);
    if (std.mem.indexOf(u8, t, "_on_")) |i| return allocator.dupe(u8, t[i + 4 ..]);

    for (migration_verbs) |v| {
        if (std.mem.startsWith(u8, t, v)) {
            t = t[v.len..];
            break;
        }
    }
    if (std.mem.endsWith(u8, t, "_table")) t = t[0 .. t.len - "_table".len];

    return allocator.dupe(u8, if (t.len > 0) t else snakeName);
}

// ── help ──────────────────────────────────────────────────────────────────────

fn printHelp() void {
    prompt.intro("hkm plugins");
    prompt.section("Usage");
    prompt.item("hkm plugins [path|name]", "show the plugins/modules a project enables (aliases: list/ls/analyze/status)");
    prompt.item("hkm plugins verify [proj]", "audit enabled plugins: wiring, deps + copied assets/views/migrations/configs");
    prompt.item("hkm plugins recover [proj]", "rebuild var/plugin-assets.json from on-disk assets (aliases: rebuild/reindex)");
    prompt.item("hkm plugins enable <plugin> [proj]", "wire a plugin into the project bootstrap");
    prompt.item("hkm plugins disable <plugin> [proj]", "remove a plugin from the project bootstrap");
    prompt.item("hkm plugins update [plugin] [proj]", "analyse enabled plugin(s) vs the project (config/database/resources/ui): publish new + refresh changed assets, re-sync a drifted ui mirror, migrate central + tenant DBs; wire any missing Support/helpers.php require");
    prompt.item("hkm plugins upgrade [proj]", "full upgrade after plugins changed: heal new deps, publish/migrate, reconcile plugin SPLITS (moves migration ownership without dropping data)");
    prompt.item("hkm plugins create <name> [proj]", "scaffold a new plugin (project, or --kernel)");
    prompt.item("hkm plugins delete <name> [proj]", "delete a plugin folder from disk");
    prompt.blank();
    prompt.section("From git");
    prompt.item("hkm plugins install [proj]", "install every plugin the project declares but does not have yet");
    prompt.item("hkm plugins install --latest", "…and move every one to its newest release (alias: --upgrade)");
    prompt.item("hkm plugins install <plugin> [proj]", "fetch one plugin from its git remote (aliases: fetch/get)");
    prompt.item("hkm plugins install <git-url> [proj]", "…or from any remote directly — a fork, a mirror, an unregistered plugin");
    prompt.item("hkm plugins uninstall <plugin> [proj]", "delete an installed plugin and drop it from the lock");
    prompt.item("hkm plugins versions <plugin>", "list the releases available on the remote");
    prompt.item("hkm plugins outdated [proj]", "show which locked plugins have a newer release");
    prompt.item("hkm plugins lock [proj]", "restore every plugin at the exact version plugins.lock.json records");
    prompt.item("hkm plugins prune [proj]", "delete shared-store versions no project pins any more (alias: gc)");
    prompt.item("hkm plugins domains [proj]", "show which plugin provides each domain a requires[] can name");
    prompt.item("hkm plugins store", "show the global plugin cache (--set=<path>, --migrate; alias: cache)");
    prompt.item("hkm plugins make:migration <plugin> <name>", "add a migration INTO a plugin (not published)");
    prompt.item("hkm plugins make:seeder|make:factory <plugin> <name>", "add a seeder/factory into a plugin");
    prompt.blank();
    prompt.section("Options");
    prompt.item("--all, -a", "also list available-but-disabled plugins");
    prompt.item("--essential, -e", "enable into withEssentialModules() (default: on-demand)");
    prompt.item("--kernel, -k", "create/delete a KERNEL plugin (kernel monorepo only)");
    prompt.item("--dry-run, -n", "preview the change without writing");
    prompt.item("--version=<tag>", "install/update to a specific release instead of the newest");
    prompt.item("--force", "overwrite a plugin working copy that has uncommitted changes");
    prompt.item("--full", "clone full history instead of a shallow --depth 1");
    prompt.item("--no-verify", "install without running the plugin's test suite first");
    prompt.item("--verify", "run the suite even for a batch install, where it is off by default");
    prompt.item("--no-deps", "install only the named plugin — skip the plugins its requires[] needs");
    prompt.item("--latest", "restore: ignore the locked versions and take the newest release of each");
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
    prompt.item("update", "the plugin is the source of truth: a project file that drifted from the plugin's copy is OVERWRITTEN (dry-run first to preview); migrations run only when a migration file was new/changed");
    prompt.item("upgrade", "split-safe: a migration moved to a new plugin keeps its data; only manifest ownership transfers, no DDL re-runs (aliases: reconcile/migrate)");
    prompt.item("create", "scaffolds a complete plugin (config, migration, seeder, factory, view)");
    prompt.item("Support helpers", "a plugin's Support/helpers.php is require_once'd in the bootstrap on enable, removed on disable");
    prompt.item("aliases", "enable=add/on · disable=remove/off · create=new/make/scaffold · delete=del/rm/destroy");
    prompt.blank();
    prompt.section("Resolution");
    prompt.item("path", "a directory holding proj.json");
    prompt.item("name", "a project registered in the kernel registry");
    prompt.item("(none)", "the current working directory");
    prompt.outro("Reads app/bootstrap/app.php + kernel plugins/*/module.json");
}

// ── git-backed plugin lifecycle ───────────────────────────────────────────────

/// `hkm plugins install <plugin> [proj]` — fetch a plugin from its git remote
/// into the project's plugins/ and record it in plugins.lock.json.
fn installCmd(
    allocator: std.mem.Allocator,
    io: Io,
    env: *EnvMap,
    plugin: []const u8,
    target: []const u8,
    opts: installer.Options,
    with_deps: bool,
) !u8 {
    const root = (try requireRoot(allocator, io, env, target)) orelse return 1;

    prompt.intro("hkm plugins install");
    prompt.ok(try std.fmt.allocPrint(allocator, "project  {s}", .{root}));

    // A URL in place of a name installs straight from that remote. The display
    // name is only a first guess taken from the repository — the installer
    // replaces it with whatever the plugin's module.json declares.
    const by_url = pregistry.isRemoteUrl(plugin);
    var call_opts = opts;
    const name = if (by_url) blk: {
        call_opts.remote = std.mem.trim(u8, plugin, " \t\r\n");
        break :blk pregistry.nameFromRemote(allocator, plugin) catch {
            prompt.err(try std.fmt.allocPrint(
                allocator,
                "Could not work out a plugin name from '{s}' — it has no repository name in it.",
                .{plugin},
            ));
            return 2;
        };
    } else plugin;

    const remote = if (by_url) call_opts.remote else try pregistry.remoteFor(allocator, env, name);
    prompt.muted(try std.fmt.allocPrint(allocator, "remote   {s}", .{remote}));
    if (by_url) {
        prompt.muted(try std.fmt.allocPrint(allocator, "name     {s}  (from the repository)", .{name}));
        // Where it lands is the one thing a URL install changes silently, and
        // it decides which composer resolves the plugin.
        if (!pregistry.remoteIsFirstParty(env, remote)) {
            prompt.muted("target   this project's plugins/ (not a first-party remote)");
        }
    }

    const outcome = try installer.install(allocator, io, env, root, name, call_opts);

    // Report — and later wire in — the name the INSTALLER settled on, not the
    // argument. A URL install's argument is a URL, and a plugin whose
    // module.json disagreed with its repository name is now on disk under the
    // module.json name; using the argument printed a name nothing has, and had
    // enable try to fetch a plugin called "https://…".
    const final_name = switch (outcome) {
        .installed, .up_to_date, .linked => |e| e.name,
        .updated => |u| u.to.name,
        .refused => name,
    };

    const code = try installer.report(allocator, final_name, outcome, opts.dry_run);

    // ── Its dependencies ────────────────────────────────────────────────────
    //
    // A plugin declares what it needs as DOMAINS, and a plugin whose domains
    // are not on disk installs cleanly and then fails at boot — the same
    // class of failure as a project scaffolded without its plugins. Fetch the
    // closure now, while there is somewhere to report it.
    if (with_deps and code == 0 and !opts.dry_run) {
        _ = installDependencies(allocator, io, env, root, final_name, opts, null) catch |e| {
            prompt.warn(try std.fmt.allocPrint(
                allocator,
                "could not resolve {s}'s dependencies ({s}) — run 'hkm plugins verify' to see what is missing.",
                .{ final_name, @errorName(e) },
            ));
            return 0;
        };
    }

    // Only a real change touches the lock file; a dry run must leave the
    // project byte-identical.
    if (!opts.dry_run) {
        switch (outcome) {
            .installed, .up_to_date, .linked => |e| try installer.recordInLock(allocator, io, root, e),
            .updated => |u| try installer.recordInLock(allocator, io, root, u.to),
            .refused => {},
        }
    }

    if (code != 0 or opts.dry_run) {
        if (opts.dry_run) prompt.outro("Dry run — nothing was written");
        return code;
    }

    // ── Finish the job ──────────────────────────────────────────────────────
    //
    // An installed plugin that is not wired into the bootstrap does nothing,
    // and one whose assets are not published is wired but half-present. Doing
    // the whole sequence here is the difference between "downloaded" and
    // "usable"; each step is reported so a partial result is visible rather
    // than assumed.
    return finishInstall(allocator, io, env, root, final_name);
}

/// `hkm plugins install` with no plugin — install everything the project
/// declares but does not have.
///
/// The gap this closes: a project cloned from git carries its bootstrap and its
/// plugins.lock.json, and nothing else. `lock` restores only what the lock
/// records, so a plugin enabled in the bootstrap but never locked was invisible
/// to it; `update` and `upgrade` operate on plugins already on disk and reported
/// "0 plugins" on a checkout with none. The only way through was `hkm plugins
/// enable <name>` once per plugin, relying on enable's auto-fetch.
///
/// Two sources, and the lock wins where they overlap — a locked version is a
/// deliberate pin, and restoring it as "newest" would defeat having a lock:
///
///   plugins.lock.json  → the exact version + remote recorded
///   the bootstrap      → enabled plugins the lock has never heard of, newest
fn restoreCmd(
    allocator: std.mem.Allocator,
    io: Io,
    env: *EnvMap,
    target: []const u8,
    base: installer.Options,
    with_deps: bool,
    latest: bool,
) !u8 {
    const root = (try requireRoot(allocator, io, env, target)) orelse return 1;

    prompt.intro("hkm plugins install");
    if (latest) {
        prompt.muted("--latest: taking the newest release of every plugin, ignoring the locked versions");
    }
    prompt.ok(try std.fmt.allocPrint(allocator, "project  {s}", .{root}));

    const Want = struct { name: []const u8, version: []const u8, remote: []const u8, from_lock: bool };
    var wanted: std.ArrayList(Want) = .empty;

    const lock = plock.read(allocator, io, root) catch plock.Lock{};
    for (lock.entries.items) |e| {
        // A local plugin is not fetched from anywhere — it IS the project's.
        if (std.mem.eql(u8, e.source, "local")) continue;
        try wanted.append(allocator, .{
            .name = e.name,
            // An empty version means "newest allowed". Dropping the pin is the
            // whole of --latest: everything else about the restore is the same.
            .version = if (latest) "" else e.version,
            .remote = e.remote,
            .from_lock = true,
        });
    }

    const bootstrap = try std.fmt.allocPrint(allocator, "{s}/app/bootstrap/app.php", .{root});
    if (try readBootstrap(allocator, io, bootstrap)) |source| {
        var aliases: std.ArrayList(boot.Alias) = .empty;
        try boot.collectAliases(allocator, source, &aliases);
        var enabled: std.ArrayList(Enabled) = .empty;
        try boot.collectEnabled(allocator, source, aliases.items, &enabled);

        for (enabled.items) |e| {
            var known = false;
            for (wanted.items) |w| {
                if (util.eqlIgnoreCase(w.name, e.name)) known = true;
            }
            if (!known) try wanted.append(allocator, .{
                .name = e.name,
                .version = "",
                .remote = "",
                .from_lock = false,
            });
        }
    }

    if (wanted.items.len == 0) {
        prompt.warn("This project declares no plugins — nothing to install.");
        prompt.muted("  plugins come from app/bootstrap/app.php and plugins.lock.json.");
        prompt.outro("Nothing to do");
        return 0;
    }

    // What the project can already see, in either source.
    const srcs = try sources.discoverSources(allocator, io, env, root);
    const search = &[_]Source{ .project, .kernel };

    var present: usize = 0;
    var installed: usize = 0;
    var pulled: usize = 0; // dependencies the project never listed
    var pulled_names: std.ArrayList([]const u8) = .empty;
    var failed: std.ArrayList([]const u8) = .empty;
    // Plugins whose wiring still has to be done. Fetching one is only half the
    // job: a plugin on disk that no bootstrap names is inert, and a DEPENDENCY
    // that is installed-but-not-enabled fails the boot outright — the kernel
    // refuses a requires[] domain no enabled module solves.
    var to_wire: std.ArrayList([]const u8) = .empty;

    for (wanted.items) |w| {
        const folder = try pregistry.canonicalName(allocator, w.name);

        var found = false;
        for (search) |src| {
            const d = srcs.dirFor(src) orelse continue;
            const fp = try std.fmt.allocPrint(allocator, "{s}/{s}", .{ d, folder });
            if (util.dirExists(Dir.cwd(), io, fp)) found = true;
        }
        // --latest has to reach a plugin that is already installed — that is
        // exactly the plugin it exists to move forward.
        if (found and !base.force and !latest) {
            present += 1;
            continue;
        }

        if (base.dry_run) {
            prompt.muted(try std.fmt.allocPrint(allocator, "would install  {s}  {s}", .{
                folder,
                if (w.version.len > 0) w.version else "(newest)",
            }));
            installed += 1;
            continue;
        }

        var opts = base;
        opts.version = w.version;
        opts.remote = w.remote;
        // One classmap rebuild for the whole run, not one per plugin.
        opts.defer_autoload = true;

        const outcome = installer.install(allocator, io, env, root, folder, opts) catch {
            try failed.append(allocator, folder);
            continue;
        };
        switch (outcome) {
            .refused => |why| {
                prompt.warn(why);
                try failed.append(allocator, folder);
            },
            .installed, .up_to_date, .linked, .updated => {
                _ = try installer.report(allocator, folder, outcome, false);
                switch (outcome) {
                    .installed, .up_to_date, .linked => |e| try installer.recordInLock(allocator, io, root, e),
                    .updated => |u| try installer.recordInLock(allocator, io, root, u.to),
                    .refused => {},
                }
                installed += 1;
                try to_wire.append(allocator, folder);
            },
        }
    }

    // Dependencies, for EVERY declared plugin — not only the ones just fetched.
    //
    // Scoping this to fresh installs meant a project whose plugins were all
    // present never had its dependency graph checked at all. That is precisely
    // when it matters: testpp had every plugin it listed, and still could not
    // boot, because Tenancy's ROUTES require http.pageflow and nothing had ever
    // gone looking for it.
    if (!base.dry_run and with_deps) {
        var dep_base = base;
        dep_base.defer_autoload = true;
        for (wanted.items) |w| {
            const folder = try pregistry.canonicalName(allocator, w.name);
            pulled += installDependencies(allocator, io, env, root, folder, dep_base, &pulled_names) catch 0;
        }
    }

    // Everything is on disk; make it visible to PHP, once.
    if (!base.dry_run and (installed > 0 or pulled > 0)) {
        installer.refreshAllAutoload(allocator, io, env, root);
    }

    // Then wire it in — for EVERY plugin the project declares, not only the
    // ones just downloaded.
    //
    // "Nothing to download" and "nothing to do" are different states. A project
    // can have every plugin on disk and still not boot, because a dependency
    // was fetched but never added to the bootstrap; scoping this to fresh
    // installs meant re-running the command on such a project reported success
    // and changed nothing. enable is idempotent — a plugin whose whole closure
    // is already wired costs one no-op — so running it over everything is both
    // cheap and the only way this command can promise a runnable project.
    if (!base.dry_run) {
        prompt.section("Wiring into the bootstrap");
        for (wanted.items) |w| {
            const folder = try pregistry.canonicalName(allocator, w.name);
            wirePlugin(allocator, io, env, root, folder) catch {};
        }
        for (to_wire.items) |name| {
            wirePlugin(allocator, io, env, root, name) catch {};
        }
        // Anything the dependency walk pulled in is on disk but not yet wired.
        for (pulled_names.items) |name| {
            wirePlugin(allocator, io, env, root, name) catch {};
        }

        // Assets and UI once, after all the wiring — not per plugin.
        plugin_assets.publishEnabled(allocator, io, env, root) catch {
            prompt.warn("assets could not be published — run: hkm plugins update");
        };
    }

    // A plugin's Support/helpers.php defines global functions its own code
    // calls; nothing autoloads a bare function file, so an unwired one is an
    // undefined-function fatal at the first call. enable wires it for plugins
    // it newly enables — this catches the ones that were already enabled and
    // never had it wired.
    if (!base.dry_run) {
        _ = healSupportRequires(allocator, io, env, root, false) catch 0;
    }

    if (present > 0) {
        prompt.muted(try std.fmt.allocPrint(allocator, "{d} already installed", .{present}));
    }

    if (failed.items.len > 0) {
        prompt.warn(try std.fmt.allocPrint(
            allocator,
            "{d} plugin(s) could not be installed — the project will not boot until they are:",
            .{failed.items.len},
        ));
        for (failed.items) |name| {
            prompt.muted(try std.fmt.allocPrint(allocator, "  hkm plugins install {s}", .{name}));
        }
        prompt.outro(try std.fmt.allocPrint(allocator, "{d} installed, {d} failed", .{ installed, failed.items.len }));
        return 1;
    }

    if (base.dry_run) {
        prompt.outro("Dry run — nothing was written");
        return 0;
    }

    if (installed == 0) {
        prompt.outro("Everything this project declares is already installed");
        return 0;
    }

    if (pulled > 0) {
        // Counted separately because they are not what was asked for: they are
        // what the declared plugins turned out to need.
        prompt.outro(try std.fmt.allocPrint(
            allocator,
            "{d} plugin(s) installed, plus {d} pulled in as dependencies",
            .{ installed, pulled },
        ));
        return 0;
    }
    prompt.outro(try std.fmt.allocPrint(allocator, "{d} plugin(s) installed", .{installed}));
    return 0;
}

/// Install everything `folder` declares in its requires[], transitively.
///
/// Breadth-first over a queue rather than recursion, so a dependency cycle
/// costs a `seen` lookup instead of a stack overflow — and plugins DO form
/// long chains here (OAuth2 → Auth → User → Database, Crypto, Mail, …).
///
/// Returns the number of plugins installed. Domains that resolve to nothing are
/// reported rather than failed on: a requires[] entry with no provider is
/// usually satisfied by a kernel port bound in withPorts(), which is not
/// something to fetch.
fn installDependencies(
    allocator: std.mem.Allocator,
    io: Io,
    env: *EnvMap,
    root: []const u8,
    folder: []const u8,
    base: installer.Options,
    pulled_out: ?*std.ArrayList([]const u8),
) !usize {
    var queue: std.ArrayList([]const u8) = .empty;
    try queue.append(allocator, folder);

    var seen: std.ArrayList([]const u8) = .empty;
    try seen.append(allocator, folder);

    var unresolved: std.ArrayList([]const u8) = .empty;
    var installed: usize = 0;
    var announced = false;

    var head: usize = 0;
    while (head < queue.items.len) : (head += 1) {
        const current = queue.items[head];

        // Re-discovered each round: the previous iteration installed plugins,
        // so a domain unresolvable a moment ago may now be answered by disk.
        const srcs = try sources.discoverSources(allocator, io, env, root);
        var cat: std.ArrayList(deps.Provider) = .empty;
        try deps.catalogue(allocator, io, srcs, &.{ .project, .kernel }, &cat);

        const prov = deps.findByName(cat.items, current) orelse continue;

        for (prov.requires) |req| {
            const domain = req.domain;
            // Already provided by something on disk: nothing to fetch.
            if (deps.providerForDomain(cat.items, domain) != null) continue;

            var overridden = false;
            const hit = domains.resolveRequirement(cat.items, req, &overridden) orelse {
                if (!util.contains(unresolved.items, domain)) {
                    try unresolved.append(allocator, domain);
                }
                continue;
            };
            if (util.contains(seen.items, hit.folder)) continue;
            try seen.append(allocator, hit.folder);

            if (!announced) {
                prompt.section("Dependencies");
                announced = true;
            }
            prompt.muted(try std.fmt.allocPrint(
                allocator,
                "{s}  ← needed for {s}{s}",
                .{
                    hit.folder,
                    domain,
                    if (hit.origin == .declared) "  (repo declared by the plugin)" else "",
                },
            ));
            if (overridden) {
                // Never silent: the manifest asked for one repository and it is
                // being fetched from another.
                prompt.muted(try std.fmt.allocPrint(
                    allocator,
                    "    ignoring the declared repo — {s} is a platform domain, provided by {s}",
                    .{ domain, hit.folder },
                ));
            }

            var dep_opts = base;
            // Batched: one classmap rebuild after the closure, not one per
            // dependency. Left alone when the CALLER is already batching.
            dep_opts.defer_autoload = true;
            // The root's VERSION does not carry to a different plugin — it would
            // ask for a tag that does not exist there. A requirement that names
            // its own ref does apply.
            dep_opts.version = hit.version;
            // Likewise the remote: resolved from the dependency's own name,
            // unless the requirement declared where to get it.
            dep_opts.remote = hit.repo;

            const outcome = installer.install(allocator, io, env, root, hit.folder, dep_opts) catch |e| {
                prompt.warn(try std.fmt.allocPrint(
                    allocator,
                    "{s}: could not be installed ({s}).",
                    .{ hit.folder, @errorName(e) },
                ));
                continue;
            };

            switch (outcome) {
                .refused => |why| prompt.warn(why),
                .installed, .up_to_date, .linked, .updated => {
                    _ = try installer.report(allocator, hit.folder, outcome, false);
                    switch (outcome) {
                        .installed, .up_to_date, .linked => |e| try installer.recordInLock(allocator, io, root, e),
                        .updated => |u| try installer.recordInLock(allocator, io, root, u.to),
                        .refused => {},
                    }
                    installed += 1;
                    if (pulled_out) |out| try out.append(allocator, hit.folder);
                    // Its own requires[] are now in scope.
                    try queue.append(allocator, hit.folder);
                },
            }
        }
    }

    // Only when this call owns the batch — a caller that set defer_autoload is
    // installing more and will dump once itself.
    if (installed > 0 and !base.defer_autoload) {
        installer.refreshAllAutoload(allocator, io, env, root);
    }

    if (unresolved.items.len > 0) {
        // Not an error. Ports are bound in withPorts() and have no plugin to
        // fetch — but a genuinely missing third-party plugin looks identical
        // from here, so name them and let the reader judge.
        prompt.muted("");
        prompt.muted("Not provided by any known plugin — kernel ports, or plugins to install by name/URL:");
        for (unresolved.items) |d| {
            prompt.muted(try std.fmt.allocPrint(allocator, "  {s}", .{d}));
        }
    }

    return installed;
}

/// Wire every enabled plugin's `Support/helpers.php` require that is missing.
///
/// A plugin's helpers file defines global functions its OWN code and the
/// project's code call directly (`__()`, `vite()`, `storage_config()`). Nothing
/// autoloads a plain function file — composer's `files` entry only covers
/// packages, and these plugins are linked in, not required as packages — so it
/// has to be `require_once`'d from the bootstrap or every call to it is an
/// undefined-function fatal. A plugin enabled before it shipped helpers, or
/// enabled by a path that predates the wiring, ends up exactly there: present,
/// loaded, and broken at the first helper call.
///
/// Returns how many were wired (or would be, when `dry_run`).
pub fn healSupportRequires(
    allocator: std.mem.Allocator,
    io: Io,
    env: *EnvMap,
    root: []const u8,
    dry_run: bool,
) !usize {
    const bootstrap = try std.fmt.allocPrint(allocator, "{s}/app/bootstrap/app.php", .{root});
    const source = (try readBootstrap(allocator, io, bootstrap)) orelse return 0;

    var aliases: std.ArrayList(boot.Alias) = .empty;
    try boot.collectAliases(allocator, source, &aliases);
    var enabled: std.ArrayList(Enabled) = .empty;
    try boot.collectEnabled(allocator, source, aliases.items, &enabled);
    if (enabled.items.len == 0) return 0;

    const srcs = try sources.discoverSources(allocator, io, env, root);
    const search = &[_]Source{ .project, .kernel };

    var out = source;
    var wired: usize = 0;

    for (enabled.items) |e| {
        var path: ?[]const u8 = null;
        for (search) |src| {
            const d = srcs.dirFor(src) orelse continue;
            const fp = try std.fmt.allocPrint(allocator, "{s}/{s}", .{ d, e.name });
            if (util.dirExists(Dir.cwd(), io, fp)) {
                path = fp;
                break;
            }
        }
        const pp = path orelse continue;

        const expr = (try supportHelpersExpr(allocator, io, env, root, pp)) orelse continue;
        const woven = try boot.insertSupportRequire(allocator, out, e.name, expr);
        if (woven.ptr == out.ptr) continue; // already wired

        out = woven;
        wired += 1;
        const verb = if (dry_run) "Would wire" else "Wired";
        prompt.ok(try std.fmt.allocPrint(allocator, "{s} Support/helpers.php for {s}", .{ verb, e.name }));
        prompt.muted(try std.fmt.allocPrint(allocator, "    + require_once {s}", .{expr}));
    }

    if (wired > 0 and !dry_run) {
        try Dir.cwd().writeFile(io, .{ .sub_path = bootstrap, .data = out });
    }
    return wired;
}

/// Wire a freshly installed plugin in: enable it, publish its assets, federate
/// its UI. Failures downgrade to warnings — the plugin IS installed, and a
/// missing UI mirror should not read as a failed install.
/// Does this plugin's module.json say it must be registered on every request?
///
/// A plugin whose pipeline stage runs globally needs its bindings present
/// globally. Enabling such a plugin on-demand yields a project that installs,
/// boots, and then throws on the first request — a failure three steps removed
/// from its cause. `"activation": "essential"` moves that knowledge into the
/// plugin, where it is known, instead of the user's head.
fn declaresEssential(allocator: std.mem.Allocator, io: Io, dir: []const u8, name: []const u8) bool {
    const meta = (sources.readModuleMeta(allocator, io, dir, name) catch return false) orelse return false;
    const a = meta.activation orelse return false;
    return util.eqlIgnoreCase(std.mem.trim(u8, a, " \t\r\n"), "essential");
}

/// Wire ONE plugin and its unmet requires[] closure into the bootstrap.
///
/// Split out of finishInstall so a batch can wire many plugins and then publish
/// assets ONCE. Calling the full finish per plugin re-published every enabled
/// plugin's assets each time — quadratic, for a result identical to doing it
/// once at the end.
///
/// Re-reads the bootstrap on every call: the previous plugin's wiring changed
/// it, and enabling against a stale copy would drop those edits.
fn wirePlugin(
    allocator: std.mem.Allocator,
    io: Io,
    env: *EnvMap,
    root: []const u8,
    plugin: []const u8,
) !void {
    const bootstrap = try std.fmt.allocPrint(allocator, "{s}/app/bootstrap/app.php", .{root});
    const source = (try readBootstrap(allocator, io, bootstrap)) orelse return;

    var aliases: std.ArrayList(boot.Alias) = .empty;
    try boot.collectAliases(allocator, source, &aliases);
    var enabled: std.ArrayList(Enabled) = .empty;
    try boot.collectEnabled(allocator, source, aliases.items, &enabled);

    const srcs = try sources.discoverSources(allocator, io, env, root);
    const search = &[_]Source{ .project, .kernel };
    var cat: std.ArrayList(deps.Provider) = .empty;
    try deps.catalogue(allocator, io, srcs, search, &cat);

    var matches: std.ArrayList(Located) = .empty;
    try sources.locate(allocator, io, srcs, plugin, search, &matches);
    const located = sources.chooseLocated(allocator, matches.items);

    _ = enableWithDeps(
        allocator, io, env, root, bootstrap, source,
        cat.items, enabled.items, located, plugin, false, false,
    ) catch |e| {
        prompt.warn(try std.fmt.allocPrint(
            allocator,
            "{s}: could not be enabled ({t}) — run: hkm plugins enable {s}",
            .{ plugin, e, plugin },
        ));
    };
}

fn finishInstall(
    allocator: std.mem.Allocator,
    io: Io,
    env: *EnvMap,
    root: []const u8,
    plugin: []const u8,
) !u8 {
    const bootstrap = try std.fmt.allocPrint(allocator, "{s}/app/bootstrap/app.php", .{root});
    const source = (try readBootstrap(allocator, io, bootstrap)) orelse {
        prompt.warn("No app/bootstrap/app.php — installed, but not enabled.");
        return 0;
    };

    var aliases: std.ArrayList(boot.Alias) = .empty;
    try boot.collectAliases(allocator, source, &aliases);
    var enabled: std.ArrayList(Enabled) = .empty;
    try boot.collectEnabled(allocator, source, aliases.items, &enabled);

    // Run the enable pass even when the plugin ITSELF is already wired.
    //
    // Skipping it on that basis meant a plugin listed in the bootstrap never had
    // its requires[] closure resolved: `hkm plugins install` fetched Tenancy's
    // Database and I18n, put them on disk, and left the bootstrap naming only
    // Tenancy — a project that boots straight into "requires a domain no
    // enabled module solves". Being enabled says nothing about whether what it
    // DEPENDS on is.
    //
    // enableWithDeps is already the right shape for this: it computes the
    // unmet closure and reports "already enabled" only when the plugin AND
    // everything under it is wired, so the call costs nothing when there is
    // nothing to do.
    {
        const srcs = try sources.discoverSources(allocator, io, env, root);
        const search = &[_]Source{ .project, .kernel };
        var cat: std.ArrayList(deps.Provider) = .empty;
        try deps.catalogue(allocator, io, srcs, search, &cat);

        var matches: std.ArrayList(Located) = .empty;
        try sources.locate(allocator, io, srcs, plugin, search, &matches);
        const located = sources.chooseLocated(allocator, matches.items);

        _ = enableWithDeps(
            allocator, io, env, root, bootstrap, source,
            cat.items, enabled.items, located, plugin, false, false,
        ) catch |e| {
            prompt.warn(try std.fmt.allocPrint(
                allocator,
                "installed, but could not be enabled ({t}) — run: hkm plugins enable {s}",
                .{ e, plugin },
            ));
            return 0;
        };
    }

    plugin_assets.publishEnabled(allocator, io, env, root) catch {
        prompt.warn("assets could not be published — run: hkm plugins update");
    };

    syncPluginUi(allocator, io, env, root, plugin);

    prompt.outro("Installed, enabled, assets published");
    return 0;
}

/// Mirror the plugin's ui/ into the project frontend, when it ships one.
fn syncPluginUi(allocator: std.mem.Allocator, io: Io, env: *EnvMap, root: []const u8, plugin: []const u8) void {
    var uis: std.ArrayList(plugin_ui.UiPlugin) = .empty;
    plugin_ui.discover(allocator, io, env, root, &uis) catch return;

    for (uis.items) |u| {
        if (!util.eqlIgnoreCase(u.name, plugin)) continue;
        if (u.linked) return; // a live symlink must not be overwritten by a copy
        const n = plugin_ui.syncPlugin(allocator, io, root, u, false) catch return;
        prompt.ok(std.fmt.allocPrint(allocator, "ui  {s} → {s} ({d} file(s))", .{ u.name, u.alias, n }) catch return);
        plugin_ui.writeGlue(allocator, io, root, uis.items) catch {};
        return;
    }
}

/// `hkm plugins uninstall <plugin> [proj]` — delete the plugin folder and drop
/// it from the lock. Deliberately does NOT touch the bootstrap: disabling is a
/// separate, reversible decision, and removing wiring here would make an
/// uninstall silently change which routes a project serves.
fn uninstallCmd(
    allocator: std.mem.Allocator,
    io: Io,
    env: *EnvMap,
    plugin: []const u8,
    target: []const u8,
    dry_run: bool,
    force: bool,
) !u8 {
    const root = (try requireRoot(allocator, io, env, target)) orelse return 1;

    // Canonical folder, not the raw argument: `install crypto` creates Crypto,
    // so `uninstall crypto` has to look for Crypto or it finds nothing.
    const folder = try pregistry.canonicalName(allocator, plugin);

    prompt.intro("hkm plugins uninstall");

    // What this project actually has is the ENTRY under its own plugins/ —
    // usually a symlink into the shared store. Looking at the store path
    // directly (as this used to) reported "not installed" for every plugin
    // installed the modern way, while the link and the lock entry sat right
    // there.
    const link = try std.fs.path.join(allocator, &.{ root, "plugins", folder });

    var lock = try plock.read(allocator, io, root);
    const locked = lock.find(folder);

    if (!util.dirExists(Dir.cwd(), io, link) and locked == null) {
        prompt.warn(try std.fmt.allocPrint(allocator, "{s} is not installed in this project.", .{folder}));
        return 0;
    }

    // A REAL directory here (not a link) is either a third-party plugin or a
    // working copy someone is editing — worth the dirty check. A store link is
    // a pristine clone, so the check cannot fire on it.
    // The dirty check only makes sense for a REAL directory here — a
    // third-party plugin, or a working copy someone is editing. A symlink
    // points at a managed store copy, which install deliberately strips of
    // tests/ and vendor/ — so `git status` there always reports deletions and
    // the check would refuse EVERY uninstall unless forced.
    const managed = util.isSymlink(io, link);
    if (!force and !managed and pgit.isRepo(io, link, allocator) and pgit.isDirty(allocator, io, env, link)) {
        prompt.err(try std.fmt.allocPrint(
            allocator,
            "{s} has uncommitted local changes. Commit or stash them, or pass --force to delete anyway.",
            .{folder},
        ));
        return 1;
    }

    if (dry_run) {
        prompt.muted(try std.fmt.allocPrint(allocator, "would remove  {s}", .{link}));
        if (locked) |e| prompt.muted(try std.fmt.allocPrint(allocator, "would drop lock entry  {s} {s}", .{ e.name, e.version }));
        prompt.muted("the shared store copy is kept — other projects may pin that version (hkm plugins prune)");
        prompt.outro("Dry run — nothing was written");
        return 0;
    }

    // deleteFile first: deleteTree on a SYMLINK would follow it and delete the
    // shared store copy every other project depends on.
    Dir.cwd().deleteFile(io, link) catch {
        Dir.cwd().deleteTree(io, link) catch {
            prompt.err(try std.fmt.allocPrint(allocator, "could not remove {s}", .{link}));
            return 1;
        };
    };

    _ = lock.remove(folder);
    try plock.write(allocator, io, root, &lock, banner.version());

    // The project's autoloader still lists the old path until it is rebuilt.
    installer.refreshAutoload(allocator, io, env, try std.fs.path.join(allocator, &.{ root, "plugins" }));

    prompt.ok(try std.fmt.allocPrint(allocator, "removed  {s}", .{folder}));
    prompt.muted("the shared store copy is kept for other projects — reclaim it with: hkm plugins prune");
    prompt.outro("It may still be wired in the bootstrap — run: hkm plugins disable");
    return 0;
}

/// `hkm plugins versions <plugin>` — list the releases on the remote, marking
/// which ones this kernel can actually run.
fn versionsCmd(allocator: std.mem.Allocator, io: Io, env: *EnvMap, plugin: []const u8) !u8 {
    if (!pgit.available(allocator, io, env)) {
        prompt.err("git is not installed or not on PATH — it is required to query plugin releases.");
        return 1;
    }

    const remote = if (pregistry.isRemoteUrl(plugin))
        std.mem.trim(u8, plugin, " \t\r\n")
    else
        try pregistry.remoteFor(allocator, env, plugin);

    prompt.intro(try std.fmt.allocPrint(allocator, "Releases of {s}", .{plugin}));
    prompt.muted(try std.fmt.allocPrint(allocator, "remote  {s}", .{remote}));

    const tags = pgit.listTags(allocator, io, env, remote) catch |e| {
        prompt.err(try std.fmt.allocPrint(allocator, "{s}: {s}", .{ plugin, pgit.explain(e) }));
        return 1;
    };

    if (tags.len == 0) {
        prompt.warn("no release tags — this plugin has never been tagged.");
        prompt.outro("A plugin must be tagged before it can be installed");
        return 0;
    }

    prompt.blank();
    prompt.section("Available");
    for (tags) |t| {
        prompt.item(t.name, "");
    }
    prompt.outro(try std.fmt.allocPrint(allocator, "kernel {s} — install with --version=<tag>", .{banner.version()}));
    return 0;
}

/// `hkm plugins outdated [proj]` — compare every locked plugin against its
/// remote and report what has a newer release.
fn outdatedCmd(allocator: std.mem.Allocator, io: Io, env: *EnvMap, target: []const u8) !u8 {
    const root = (try requireRoot(allocator, io, env, target)) orelse return 1;

    if (!pgit.available(allocator, io, env)) {
        prompt.err("git is not installed or not on PATH.");
        return 1;
    }

    const lock = try plock.read(allocator, io, root);

    prompt.intro("hkm plugins outdated");
    prompt.ok(try std.fmt.allocPrint(allocator, "project  {s}", .{root}));

    if (lock.entries.items.len == 0) {
        prompt.muted("no plugins are recorded in plugins.lock.json.");
        prompt.outro("Install one with: hkm plugins install <plugin>");
        return 0;
    }

    var behind: usize = 0;
    prompt.blank();
    for (lock.entries.items) |e| {
        const remote = if (e.remote.len > 0) e.remote else try pregistry.remoteFor(allocator, env, e.name);
        const latest = pgit.resolveVersion(allocator, io, env, remote, "") catch null;

        if (latest) |l| {
            if (!std.mem.eql(u8, l.name, e.version)) {
                behind += 1;
                prompt.item(e.name, try std.fmt.allocPrint(allocator, "{s} → {s}", .{
                    if (e.version.len > 0) e.version else "(unpinned)",
                    l.name,
                }));
            } else {
                prompt.muted(try std.fmt.allocPrint(allocator, "{s}  {s} (current)", .{ e.name, e.version }));
            }
        } else {
            prompt.warn(try std.fmt.allocPrint(allocator, "{s}  could not reach {s}", .{ e.name, remote }));
        }
    }

    if (behind == 0) {
        prompt.outro("Everything is on its latest release");
        return 0;
    }
    prompt.note("");
    prompt.muted("move them all forward:  hkm plugins install --latest");
    prompt.muted("or just one:            hkm plugins install <plugin>");
    prompt.muted("or pin one exactly:     hkm plugins install <plugin> --version=vX.Y.Z");
    prompt.outro(try std.fmt.allocPrint(allocator, "{d} plugin(s) behind", .{behind}));
    return 0;
}

/// `hkm plugins lock [proj]` — reconcile the project against plugins.lock.json.
///
/// This is the command a fresh checkout runs: it installs every plugin the lock
/// names, at the exact version it names. Without it a lock file is only a
/// record; with it, it is reproducible.
fn lockCmd(
    allocator: std.mem.Allocator,
    io: Io,
    env: *EnvMap,
    target: []const u8,
    dry_run: bool,
    base: installer.Options,
) !u8 {
    const root = (try requireRoot(allocator, io, env, target)) orelse return 1;
    const lock = try plock.read(allocator, io, root);

    prompt.intro("hkm plugins lock");
    prompt.ok(try std.fmt.allocPrint(allocator, "project  {s}", .{root}));

    if (lock.entries.items.len == 0) {
        prompt.muted("plugins.lock.json is empty or absent — nothing to restore.");
        return 0;
    }

    var failed: usize = 0;
    prompt.blank();
    for (lock.entries.items) |e| {
        if (std.mem.eql(u8, e.source, "local")) {
            prompt.muted(try std.fmt.allocPrint(allocator, "{s}  local copy — skipped", .{e.name}));
            continue;
        }
        // Pin to the EXACT locked version. Restoring a lock and getting a
        // different version than it records would defeat the entire point.
        const outcome = try installer.install(allocator, io, env, root, e.name, .{
            .version = e.version,
            .dry_run = dry_run,
            .force = base.force,
            .full = base.full,
            // Restore from where it actually came from. Re-deriving the remote
            // from the name would send a URL-installed plugin to the registry's
            // guess instead — a different repository, at the same version.
            .remote = e.remote,
        });
        if ((try installer.report(allocator, e.name, outcome, dry_run)) != 0) failed += 1;
    }

    if (failed > 0) {
        prompt.outro(try std.fmt.allocPrint(allocator, "{d} plugin(s) could not be restored", .{failed}));
        return 1;
    }
    prompt.outro(if (dry_run) "Dry run — nothing was written" else "Project matches plugins.lock.json");
    return 0;
}

/// `hkm plugins store` — where the global plugin cache is, and moving it.
///
/// One download per (plugin, version, origin), shared by every project: project
/// A fetching Auth v1.2.0 pays for it once, project B links at what is already
/// there. This command is how that location is inspected, relocated, and how
/// caches left in older layouts are folded into it.
fn storeCmd(
    allocator: std.mem.Allocator,
    io: Io,
    env: *EnvMap,
    set_to: []const u8,
    migrate: bool,
    dry_run: bool,
) !u8 {
    prompt.intro("hkm plugins store");

    const kernel_fallback = blk: {
        const p = installer.pluginsRoot(allocator, io, env, ".") catch break :blk ".";
        break :blk util.parentOf(p) orelse ".";
    };

    if (set_to.len > 0) {
        const abs = util.trimSlash(std.mem.trim(u8, set_to, " \t\r\n"));
        if (abs.len == 0 or abs[0] != '/') {
            prompt.err("--set needs an ABSOLUTE path — the store is shared by projects in different directories.");
            return 2;
        }
        if (dry_run) {
            prompt.muted(try std.fmt.allocPrint(allocator, "would set HKM_PLUGIN_STORE={s}", .{abs}));
            prompt.outro("Dry run — nothing was written");
            return 0;
        }
        Dir.cwd().createDirPath(io, abs) catch {
            prompt.err(try std.fmt.allocPrint(allocator, "could not create {s}", .{abs}));
            return 1;
        };
        userconfig.set(allocator, io, env, "HKM_PLUGIN_STORE", abs) catch {
            prompt.err("could not write the config file.");
            return 1;
        };
        prompt.ok(try std.fmt.allocPrint(allocator, "store set to {s}", .{abs}));
        prompt.muted("  existing caches stay where they are — fold them in with: hkm plugins store --migrate");
        // Read back through the same path resolution the installer uses, so
        // what is reported is what will actually be used.
        try env.put("HKM_PLUGIN_STORE", abs);
    }

    const root_dir = try pstore.root(allocator, env, kernel_fallback);
    prompt.ok(try std.fmt.allocPrint(allocator, "store  {s}", .{root_dir}));
    prompt.muted(try std.fmt.allocPrint(allocator, "layout  <Name>/<version>-<origin-hash>", .{}));

    if (migrate) {
        const moved = try migrateStores(allocator, io, env, root_dir, kernel_fallback, dry_run);
        if (moved == 0) prompt.muted("nothing to migrate — no cache found in an older location.");
    }

    // Contents.
    var plugins: usize = 0;
    var versions: usize = 0;
    if (util.dirExists(Dir.cwd(), io, root_dir)) {
        var d = Dir.cwd().openDir(io, root_dir, .{ .iterate = true }) catch {
            prompt.outro("store is not readable");
            return 1;
        };
        defer d.close(io);
        var it = d.iterate();
        while (try it.next(io)) |e| {
            if (e.kind != .directory) continue;
            plugins += 1;
            const pd = try std.fs.path.join(allocator, &.{ root_dir, e.name });
            var vd = Dir.cwd().openDir(io, pd, .{ .iterate = true }) catch continue;
            defer vd.close(io);
            var vit = vd.iterate();
            while (try vit.next(io)) |v| {
                if (v.kind == .directory) versions += 1;
            }
        }
    }

    prompt.blank();
    prompt.item("cached", try std.fmt.allocPrint(allocator, "{d} plugin(s), {d} version(s)", .{ plugins, versions }));
    prompt.muted("reclaim unreferenced versions with:  hkm plugins prune");
    prompt.outro("Shared by every project on this machine");
    return 0;
}

/// Fold caches left in older locations into the current store.
///
/// Two layouts predate it: `<kernel>/plugin-store` (when the store lived beside
/// the kernel) and `<project>/plugin-store` (when it followed the install
/// target, so every project kept its own copy). Entries are MOVED, never
/// merged over: a destination that already exists is left alone, because the
/// two directories are the same (plugin, version, origin) and the one already
/// in place is the one projects are linked to.
fn migrateStores(
    allocator: std.mem.Allocator,
    io: Io,
    env: *EnvMap,
    dest_root: []const u8,
    kernel_root: []const u8,
    dry_run: bool,
) !usize {
    var sources_list: std.ArrayList([]const u8) = .empty;
    try sources_list.append(allocator, try std.fs.path.join(allocator, &.{ kernel_root, pstore.dir_name }));
    if (try registry.resolvePath(allocator, io, env)) |jsonPath| {
        for (try registry.list(allocator, io, jsonPath)) |e| {
            try sources_list.append(allocator, try std.fs.path.join(allocator, &.{ e.path, pstore.dir_name }));
        }
    }

    var moved: usize = 0;
    for (sources_list.items) |src| {
        if (std.mem.eql(u8, src, dest_root)) continue;
        if (!util.dirExists(Dir.cwd(), io, src)) continue;

        prompt.section(try std.fmt.allocPrint(allocator, "migrating {s}", .{src}));

        var d = Dir.cwd().openDir(io, src, .{ .iterate = true }) catch continue;
        defer d.close(io);
        var it = d.iterate();
        while (try it.next(io)) |plugin| {
            if (plugin.kind != .directory) continue;
            const from_plugin = try std.fs.path.join(allocator, &.{ src, plugin.name });
            var vd = Dir.cwd().openDir(io, from_plugin, .{ .iterate = true }) catch continue;
            defer vd.close(io);
            var vit = vd.iterate();
            while (try vit.next(io)) |v| {
                if (v.kind != .directory) continue;
                const from = try std.fs.path.join(allocator, &.{ from_plugin, v.name });
                const to_plugin = try std.fs.path.join(allocator, &.{ dest_root, plugin.name });
                const to = try std.fs.path.join(allocator, &.{ to_plugin, v.name });

                if (util.dirExists(Dir.cwd(), io, to)) {
                    prompt.muted(try std.fmt.allocPrint(allocator, "  {s}/{s} already cached — left in place", .{ plugin.name, v.name }));
                    continue;
                }
                if (dry_run) {
                    prompt.muted(try std.fmt.allocPrint(allocator, "  would move {s}/{s}", .{ plugin.name, v.name }));
                    moved += 1;
                    continue;
                }
                Dir.cwd().createDirPath(io, to_plugin) catch {};
                Dir.cwd().rename(from, Dir.cwd(), to, io) catch {
                    prompt.warn(try std.fmt.allocPrint(allocator, "  could not move {s}/{s}", .{ plugin.name, v.name }));
                    continue;
                };
                prompt.ok(try std.fmt.allocPrint(allocator, "  moved {s}/{s}", .{ plugin.name, v.name }));
                moved += 1;
            }
        }
    }

    if (moved > 0 and !dry_run) {
        // The project links point at the OLD paths and are now dangling.
        prompt.muted("");
        prompt.muted("project links still point at the old paths — repoint them with:");
        prompt.muted("  hkm plugins lock        (in each project)");
    }
    return moved;
}

/// `hkm plugins prune` — drop shared-store versions nothing pins any more.
///
/// The store keeps one copy per (plugin, version) so projects can share a
/// download and pin independently. Nothing ever removed from it, so every
/// version any project EVER used accumulated forever. This is the other half of
/// that design.
///
/// A version is kept if ANY known project's plugins.lock.json still names it.
/// "Known" means the kernel registry plus, if given, the project argument — so a
/// project that was never registered is invisible here. That is why an
/// unreadable or missing lock aborts rather than being treated as "pins
/// nothing": guessing wrong deletes a version a live project depends on.
fn pruneCmd(allocator: std.mem.Allocator, io: Io, env: *EnvMap, target: []const u8, dry_run: bool) !u8 {
    prompt.intro("hkm plugins prune");

    const plugins_dir = try installer.pluginsRoot(allocator, io, env, if (target.len > 0) target else ".");
    const kernel_root = util.parentOf(plugins_dir) orelse ".";
    const store = try pstore.root(allocator, env, kernel_root);

    prompt.muted(try std.fmt.allocPrint(allocator, "store  {s}", .{store}));

    if (!util.dirExists(Dir.cwd(), io, store)) {
        prompt.muted("no shared store — nothing to prune.");
        return 0;
    }

    // Collect every project that might pin something.
    var roots: std.ArrayList([]const u8) = .empty;
    if (try registry.resolvePath(allocator, io, env)) |jsonPath| {
        for (try registry.list(allocator, io, jsonPath)) |e| {
            try roots.append(allocator, e.path);
        }
    }
    if (target.len > 0) {
        if (try services.resolveRoot(allocator, io, env, target)) |r| try roots.append(allocator, r);
    }

    if (roots.items.len == 0) {
        prompt.err("no registered projects found — refusing to prune.");
        prompt.muted("  every store version would look unreferenced, and pruning would delete all of them.");
        prompt.muted("  register a project first (hkm discover), or pass one: hkm plugins prune <path>");
        return 1;
    }

    // Everything still pinned, as "<Name>/<version>".
    var pinned: std.ArrayList([]const u8) = .empty;
    for (roots.items) |root| {
        const lock = plock.read(allocator, io, root) catch continue;
        for (lock.entries.items) |e| {
            if (e.version.len == 0) continue;
            // The exact directory name, origin hash included — a fork's copy
            // must not be kept alive by the upstream's lock entry.
            const key = try pstore.versionKey(allocator, e.version, e.remote);
            try pinned.append(allocator, try std.fmt.allocPrint(allocator, "{s}/{s}", .{ e.name, key }));
            // Entries written before origin hashing are bare versions.
            if (e.remote.len > 0) {
                try pinned.append(allocator, try std.fmt.allocPrint(allocator, "{s}/{s}", .{ e.name, e.version }));
            }
        }
    }

    prompt.ok(try std.fmt.allocPrint(allocator, "{d} project(s) pin {d} version(s)", .{ roots.items.len, pinned.items.len }));
    // Said out loud because it is the one way this can do damage: a project the
    // kernel has never been told about pins nothing as far as prune can see, so
    // its versions look free. Deleting one breaks that project's plugin links.
    prompt.muted("  only registered projects are consulted — run hkm discover first if any are missing.");

    var freed: usize = 0;
    var kept: usize = 0;
    var names = Dir.cwd().openDir(io, store, .{ .iterate = true }) catch {
        prompt.err("could not read the store.");
        return 1;
    };
    defer names.close(io);

    var name_it = names.iterate();
    while (try name_it.next(io)) |plugin_entry| {
        if (plugin_entry.kind != .directory) continue;

        const plugin_dir = try std.fs.path.join(allocator, &.{ store, plugin_entry.name });
        var versions = Dir.cwd().openDir(io, plugin_dir, .{ .iterate = true }) catch continue;
        defer versions.close(io);

        var v_it = versions.iterate();
        while (try v_it.next(io)) |v| {
            if (v.kind != .directory) continue;

            const key = try std.fmt.allocPrint(allocator, "{s}/{s}", .{ plugin_entry.name, v.name });
            if (util.contains(pinned.items, key)) {
                kept += 1;
                continue;
            }

            const path = try std.fs.path.join(allocator, &.{ plugin_dir, v.name });
            if (dry_run) {
                prompt.muted(try std.fmt.allocPrint(allocator, "would delete  {s}", .{key}));
            } else {
                Dir.cwd().deleteTree(io, path) catch {
                    prompt.warn(try std.fmt.allocPrint(allocator, "could not delete {s}", .{key}));
                    continue;
                };
                prompt.ok(try std.fmt.allocPrint(allocator, "deleted  {s}", .{key}));
            }
            freed += 1;
        }
    }

    if (freed == 0) {
        prompt.outro(try std.fmt.allocPrint(allocator, "nothing to prune — all {d} stored version(s) are still pinned", .{kept}));
        return 0;
    }
    prompt.outro(try std.fmt.allocPrint(
        allocator,
        "{s} {d} version(s); {d} still pinned",
        .{ if (dry_run) "would free" else "freed", freed, kept },
    ));
    return 0;
}

/// `hkm plugins domains` — the domain → plugin lookup, and where each entry
/// came from.
///
/// Exists because the mapping is invisible otherwise: a plugin's requires[]
/// names domains, and nothing in a project says which plugin answers one. When
/// an install reports a domain it could not resolve, this is the table to read.
fn domainsCmd(allocator: std.mem.Allocator, io: Io, env: *EnvMap, target: []const u8) !u8 {
    const root = (try services.resolveRoot(allocator, io, env, if (target.len > 0) target else ".")) orelse "";

    prompt.intro("hkm plugins domains");

    // Installed plugins first — their module.json is the authority, and seeing
    // them separated from the built-in table is the point: one is fact, the
    // other is this tool's last known good guess.
    var cat: std.ArrayList(deps.Provider) = .empty;
    if (root.len > 0) {
        const srcs = try sources.discoverSources(allocator, io, env, root);
        try deps.catalogue(allocator, io, srcs, &.{ .project, .kernel }, &cat);
        prompt.ok(try std.fmt.allocPrint(allocator, "project  {s}", .{root}));
    }

    var installed: usize = 0;
    for (cat.items) |p| {
        if (p.solves == null) continue;
        installed += 1;
    }

    if (installed > 0) {
        prompt.section("Installed — read from each plugin's module.json");
        for (cat.items) |p| {
            const d = p.solves orelse continue;
            prompt.item(d, p.located.name);
        }
    }

    prompt.section("Built in — used for plugins not installed yet");
    var seeded: usize = 0;
    for (domains.seed) |m| {
        // Don't repeat what disk already answered above.
        if (deps.providerForDomain(cat.items, m.domain) != null) continue;
        prompt.item(m.domain, m.folder);
        seeded += 1;
    }
    if (seeded == 0) prompt.muted("  (every seeded domain is already installed)");

    prompt.outro(try std.fmt.allocPrint(
        allocator,
        "{d} from disk, {d} from the built-in table",
        .{ installed, seeded },
    ));
    return 0;
}
