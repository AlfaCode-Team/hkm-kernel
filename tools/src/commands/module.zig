//! `hkm module` — manage FIRST-PARTY kernel packages under the monorepo's
//! `modules/` directory (bind-it, php-io-cli, let-migrate, …) as git submodules.
//!
//! These are standalone composer *path repositories*, each its own git repo,
//! registered in `.gitmodules` — NOT plugins (which live in `plugins/`, use the
//! `Plugins\` namespace and are managed by `hkm plugins`).
//!
//!   hkm module                                 list the kernel modules
//!   hkm module add <name> <git-url> [org]      add a submodule + wire composer.json
//!   hkm module remove <name>                   deinit + remove the submodule + unwire
//!
//! Options:
//!   --org / --vendor <v>   composer vendor (default: alfacode-team; or 3rd arg)
//!   --namespace <N>        PSR-4 root (default: <StudlyOrg>\<StudlyName>)
//!   --desc <text>          composer description for a generated composer.json
//!   --offline              composer update with the network disabled (--no-dev)
//!   --no-composer          skip the composer update step
//!   --dry-run, -n          preview the git/composer actions without running them
//!
//! `add` mirrors the proven module.sh flow: `git submodule add <url> modules/<name>`
//! → `git submodule update --init --recursive` → ensure src/ + composer.json exist
//! → wire the root composer.json (path repo + require "*") → `composer update`.
//! The remote at <git-url> must already exist (create it empty first).
//!
//! Only runnable from inside the kernel monorepo (a dir holding composer.json AND
//! a modules/ subtree). Git operations are STAGED, never committed — review with
//! `git status`/`git diff` and commit yourself.

const std = @import("std");
const prompt = @import("../lib/prompt.zig");
const util = @import("../lib/util.zig");

const Dir = std.Io.Dir;
const Io = std.Io;
const EnvMap = std.process.Environ.Map;

const Action = enum { list, add, remove };

const Opts = struct {
    vendor: []const u8 = "alfacode-team",
    namespace: []const u8 = "",
    desc: []const u8 = "",
    url: []const u8 = "",
    offline: bool = false,
    no_composer: bool = false,
    dry_run: bool = false,
};

pub fn run(allocator: std.mem.Allocator, io: Io, env: *EnvMap, args: []const []const u8) !u8 {
    var action: Action = .list;
    var saw_action = false;
    var opts: Opts = .{};

    var operands: std.ArrayList([]const u8) = .empty;

    var i: usize = 2;
    while (i < args.len) : (i += 1) {
        const a = args[i];
        if (std.mem.eql(u8, a, "--help") or std.mem.eql(u8, a, "-h")) {
            printHelp();
            return 0;
        } else if (std.mem.eql(u8, a, "--dry-run") or std.mem.eql(u8, a, "-n")) {
            opts.dry_run = true;
        } else if (std.mem.eql(u8, a, "--offline")) {
            opts.offline = true;
        } else if (std.mem.eql(u8, a, "--no-composer")) {
            opts.no_composer = true;
        } else if (std.mem.eql(u8, a, "--org") or std.mem.eql(u8, a, "--vendor")) {
            i += 1;
            if (i < args.len) opts.vendor = args[i];
        } else if (std.mem.startsWith(u8, a, "--org=")) {
            opts.vendor = a["--org=".len..];
        } else if (std.mem.startsWith(u8, a, "--vendor=")) {
            opts.vendor = a["--vendor=".len..];
        } else if (std.mem.eql(u8, a, "--namespace")) {
            i += 1;
            if (i < args.len) opts.namespace = args[i];
        } else if (std.mem.startsWith(u8, a, "--namespace=")) {
            opts.namespace = a["--namespace=".len..];
        } else if (std.mem.eql(u8, a, "--desc")) {
            i += 1;
            if (i < args.len) opts.desc = args[i];
        } else if (std.mem.startsWith(u8, a, "--desc=")) {
            opts.desc = a["--desc=".len..];
        } else if (std.mem.eql(u8, a, "--url")) {
            i += 1;
            if (i < args.len) opts.url = args[i];
        } else if (std.mem.startsWith(u8, a, "--url=")) {
            opts.url = a["--url=".len..];
        } else if (a.len > 0 and a[0] == '-') {
            // unknown flag — ignore
        } else if (!saw_action and operands.items.len == 0 and actionFromWord(a) != null) {
            action = actionFromWord(a).?;
            saw_action = true;
        } else {
            try operands.append(allocator, a);
        }
    }

    const root = (try kernelRoot(allocator, io, env)) orelse {
        prompt.err("Not inside the kernel monorepo — no ancestor holds composer.json + modules/.");
        prompt.note("Kernel modules are first-party packages; manage them from the kernel repo root.");
        return 1;
    };

    const ops = operands.items;
    return switch (action) {
        .list => listModules(allocator, io, root),
        .add => {
            // positional: add <name> [git-url] [org]
            if (ops.len == 0) {
                prompt.err("Usage: hkm module add <name> <git-url> [org] [--offline]");
                return 2;
            }
            if (opts.url.len == 0 and ops.len >= 2) opts.url = ops[1];
            if (ops.len >= 3) opts.vendor = ops[2];
            if (opts.url.len == 0) {
                prompt.err("A git remote URL is required — kernel modules are submodules.");
                prompt.note("Create an (empty) repo first, then: hkm module add <name> <git-url> [org]");
                return 2;
            }
            return addModule(allocator, io, env, root, ops[0], opts);
        },
        .remove => {
            if (ops.len == 0) {
                prompt.err("Usage: hkm module remove <name> [--dry-run]");
                return 2;
            }
            return removeModule(allocator, io, env, root, ops[0], opts);
        },
    };
}

fn actionFromWord(a: []const u8) ?Action {
    if (std.mem.eql(u8, a, "list") or std.mem.eql(u8, a, "ls")) return .list;
    if (std.mem.eql(u8, a, "add") or std.mem.eql(u8, a, "create") or std.mem.eql(u8, a, "new") or
        std.mem.eql(u8, a, "scaffold")) return .add;
    if (std.mem.eql(u8, a, "remove") or std.mem.eql(u8, a, "delete") or std.mem.eql(u8, a, "del") or
        std.mem.eql(u8, a, "rm") or std.mem.eql(u8, a, "destroy")) return .remove;
    return null;
}

/// Climb from PWD until an ancestor is the kernel monorepo root: it holds both a
/// composer.json AND a modules/ subtree. Returns null when none is found.
fn kernelRoot(allocator: std.mem.Allocator, io: Io, env: *EnvMap) !?[]const u8 {
    var cur = try util.absPath(allocator, env, ".");
    var depth: usize = 0;
    while (depth < 32) : (depth += 1) {
        const composer = try util.join(allocator, cur, "composer.json");
        const modules = try util.join(allocator, cur, "modules");
        if (util.fileExists(io, composer) and util.dirExists(Dir.cwd(), io, modules)) return cur;
        const parent = std.fs.path.dirname(cur) orelse return null;
        if (std.mem.eql(u8, parent, cur)) return null;
        cur = parent;
    }
    return null;
}

// ── list ──────────────────────────────────────────────────────────────────────

fn listModules(allocator: std.mem.Allocator, io: Io, root: []const u8) !u8 {
    const modules_dir = try util.join(allocator, root, "modules");

    prompt.intro("hkm module");
    prompt.ok(try std.fmt.allocPrint(allocator, "kernel  {s}", .{root}));

    var rows: std.ArrayList([]const []const u8) = .empty;
    var d = Dir.cwd().openDir(io, modules_dir, .{ .iterate = true }) catch {
        prompt.muted("No modules/ directory.");
        prompt.outro("0 modules");
        return 0;
    };
    defer d.close(io);
    var it = d.iterate();
    while (try it.next(io)) |entry| {
        if (entry.kind != .directory) continue;
        if (entry.name.len > 0 and entry.name[0] == '.') continue;
        const folder = try allocator.dupe(u8, entry.name);
        const meta = try readComposerMeta(allocator, io, modules_dir, folder);
        const row = try allocator.dupe([]const u8, &.{
            folder,
            meta.name orelse "—",
            meta.version orelse "—",
        });
        try rows.append(allocator, row);
    }

    if (rows.items.len == 0) {
        prompt.muted("No modules found.");
    } else {
        prompt.table(allocator, &.{ "Folder", "Package", "Version" }, rows.items);
    }
    prompt.outro(try std.fmt.allocPrint(allocator, "{d} module(s)  ·  modules/", .{rows.items.len}));
    return 0;
}

const ComposerMeta = struct { name: ?[]const u8 = null, version: ?[]const u8 = null };

fn readComposerMeta(allocator: std.mem.Allocator, io: Io, modules_dir: []const u8, folder: []const u8) !ComposerMeta {
    const path = try std.fmt.allocPrint(allocator, "{s}/{s}/composer.json", .{ modules_dir, folder });
    const content = Dir.cwd().readFileAlloc(io, path, allocator, .limited(1024 * 1024)) catch return .{};
    const parsed = std.json.parseFromSliceLeaky(std.json.Value, allocator, content, .{}) catch return .{};
    if (parsed != .object) return .{};
    var meta: ComposerMeta = .{};
    if (parsed.object.get("name")) |v| {
        if (v == .string) meta.name = v.string;
    }
    if (parsed.object.get("version")) |v| {
        if (v == .string) meta.version = v.string;
    }
    return meta;
}

// ── add (git submodule + bootstrap + wire + composer update) ──────────────────

fn addModule(allocator: std.mem.Allocator, io: Io, env: *EnvMap, root: []const u8, nameArg: []const u8, opts: Opts) !u8 {
    const folder = try kebab(allocator, nameArg);
    const rel = try std.fmt.allocPrint(allocator, "modules/{s}", .{folder});
    const modulePath = try std.fmt.allocPrint(allocator, "{s}/{s}", .{ root, rel });
    const pkg = try std.fmt.allocPrint(allocator, "{s}/{s}", .{ opts.vendor, folder });

    const ns = if (opts.namespace.len > 0)
        std.mem.trimEnd(u8, opts.namespace, "\\")
    else
        try std.fmt.allocPrint(allocator, "{s}\\{s}", .{ try util.studly(allocator, opts.vendor), try util.studly(allocator, folder) });

    prompt.intro("hkm module add");
    prompt.ok(try std.fmt.allocPrint(allocator, "kernel  {s}", .{root}));
    prompt.muted(try std.fmt.allocPrint(allocator, "package    {s}", .{pkg}));
    prompt.muted(try std.fmt.allocPrint(allocator, "namespace  {s}\\", .{ns}));
    prompt.muted(try std.fmt.allocPrint(allocator, "path       {s}", .{rel}));
    prompt.muted(try std.fmt.allocPrint(allocator, "remote     {s}", .{opts.url}));

    // Idempotency — already a registered submodule?
    if (try gitmodulesHasPath(allocator, io, root, rel)) {
        prompt.warn(try std.fmt.allocPrint(allocator, "{s} is already a registered submodule.", .{rel}));
        prompt.outro("No changes made");
        return 0;
    }
    if (util.dirExists(Dir.cwd(), io, modulePath)) {
        prompt.err(try std.fmt.allocPrint(allocator, "{s} already exists on disk but is not a submodule — resolve manually.", .{rel}));
        return 1;
    }

    if (opts.dry_run) {
        prompt.section("Would run");
        prompt.muted(try std.fmt.allocPrint(allocator, "    git submodule add {s} {s}", .{ opts.url, rel }));
        prompt.muted("    git submodule update --init --recursive");
        prompt.muted(try std.fmt.allocPrint(allocator, "    ensure {s}/src/ + composer.json", .{rel}));
        prompt.muted("    wire composer.json  (repositories[] + require)");
        if (!opts.no_composer) prompt.muted(if (opts.offline) "    COMPOSER_DISABLE_NETWORK=1 composer update --no-dev" else "    composer update");
        prompt.outro("Dry run — nothing changed");
        return 0;
    }

    // 1. git submodule add + init (clones the remote into modules/<folder>).
    prompt.ok("Adding submodule…");
    if (!(try runGit(allocator, io, env, root, &.{ "submodule", "add", opts.url, rel }))) {
        prompt.err("git submodule add failed — is the remote reachable and empty/matching?");
        return 1;
    }
    _ = try runGit(allocator, io, env, root, &.{ "submodule", "update", "--init", "--recursive" });

    // 2. Bootstrap structure — only what the cloned repo does not already have.
    const cwd = Dir.cwd();
    const srcDir = try std.fmt.allocPrint(allocator, "{s}/src", .{modulePath});
    if (!util.dirExists(cwd, io, srcDir)) {
        try cwd.createDirPath(io, srcDir);
        try cwd.writeFile(io, .{ .sub_path = try std.fmt.allocPrint(allocator, "{s}/.gitkeep", .{srcDir}), .data = "" });
        prompt.muted("    created src/");
    }
    const composerPath = try std.fmt.allocPrint(allocator, "{s}/composer.json", .{modulePath});
    if (!util.fileExists(io, composerPath)) {
        const desc = if (opts.desc.len > 0) opts.desc else try std.fmt.allocPrint(allocator, "{s} — a first-party PhpServicePlatform module.", .{pkg});
        try cwd.writeFile(io, .{ .sub_path = composerPath, .data = try moduleComposer(allocator, pkg, ns, desc) });
        prompt.muted("    created composer.json");
    } else {
        prompt.muted("    composer.json already present in the repo — kept as is");
    }

    // 3. Wire the root composer.json (path repo + require "*").
    switch (try wireComposer(allocator, io, root, pkg, folder)) {
        .wired => prompt.ok("Wired into composer.json  (repositories[] + require)"),
        .already => prompt.muted("composer.json already references this module — left as is."),
        .failed => prompt.warn("Could not edit composer.json automatically — add the path repo + require by hand."),
    }

    // 4. composer update.
    if (!opts.no_composer) {
        try composerUpdate(allocator, io, env, root, opts.offline);
    } else {
        prompt.muted("Skipped composer update (--no-composer).");
    }

    prompt.note("Changes are STAGED, not committed — review with `git status` and commit yourself.");
    prompt.outro(try std.fmt.allocPrint(allocator, "Module {s} added", .{folder}));
    return 0;
}

// ── remove (deinit + rm + unwire) ─────────────────────────────────────────────

fn removeModule(allocator: std.mem.Allocator, io: Io, env: *EnvMap, root: []const u8, nameArg: []const u8, opts: Opts) !u8 {
    const folder = try kebab(allocator, nameArg);
    const rel = try std.fmt.allocPrint(allocator, "modules/{s}", .{folder});
    const modulePath = try std.fmt.allocPrint(allocator, "{s}/{s}", .{ root, rel });

    prompt.intro("hkm module remove");
    prompt.ok(try std.fmt.allocPrint(allocator, "kernel  {s}", .{root}));
    prompt.muted(try std.fmt.allocPrint(allocator, "path  {s}", .{rel}));

    const is_submodule = try gitmodulesHasPath(allocator, io, root, rel);
    if (!is_submodule and !util.dirExists(Dir.cwd(), io, modulePath)) {
        prompt.err(try std.fmt.allocPrint(allocator, "{s} is neither a registered submodule nor present on disk.", .{rel}));
        return 1;
    }

    if (opts.dry_run) {
        prompt.section("Would run");
        prompt.muted(try std.fmt.allocPrint(allocator, "    git submodule deinit -f {s}", .{rel}));
        prompt.muted(try std.fmt.allocPrint(allocator, "    git rm -f {s}", .{rel}));
        prompt.muted(try std.fmt.allocPrint(allocator, "    rm -rf .git/modules/{s}  and  {s}", .{ rel, rel }));
        prompt.muted(try std.fmt.allocPrint(allocator, "    git config -f .gitmodules --remove-section submodule.{s}", .{rel}));
        prompt.muted("    unwire composer.json  (repositories[] + require)");
        prompt.outro("Dry run — nothing changed");
        return 0;
    }

    const label = try std.fmt.allocPrint(allocator, "Permanently remove submodule {s}?", .{rel});
    if (!prompt.confirm(io, label, false)) {
        prompt.outro("Cancelled — nothing removed");
        return 0;
    }

    // Mirror module.sh remove — each step best-effort (|| true), then clean up.
    _ = try runGit(allocator, io, env, root, &.{ "submodule", "deinit", "-f", rel });
    _ = try runGit(allocator, io, env, root, &.{ "rm", "-f", rel });
    Dir.cwd().deleteTree(io, try std.fmt.allocPrint(allocator, "{s}/.git/modules/{s}", .{ root, rel })) catch {};
    Dir.cwd().deleteTree(io, modulePath) catch {};
    // `git rm` already strips the .gitmodules section on modern git, so this is a
    // best-effort fallback for older git — silence its "no such section" noise.
    _ = try runGitQuiet(allocator, io, env, root, &.{ "config", "-f", ".gitmodules", "--remove-section", try std.fmt.allocPrint(allocator, "submodule.{s}", .{rel}) });
    _ = try runGit(allocator, io, env, root, &.{ "add", ".gitmodules" });
    prompt.ok(try std.fmt.allocPrint(allocator, "Removed submodule {s}", .{rel}));

    // Unwire the root composer.json too (the script left this to composer update).
    if (try unwireComposer(allocator, io, root, folder))
        prompt.ok("Unwired from composer.json  (repositories[] + require)")
    else
        prompt.muted("composer.json had no reference to this module.");

    prompt.note("Changes are STAGED, not committed — review with `git status` and commit yourself.");
    prompt.note("Run `composer update` to refresh the autoloader.");
    prompt.outro("Module removed");
    return 0;
}

// ── git / composer runners ────────────────────────────────────────────────────

/// Run `git <args>` with `cwd` = root, inheriting stdio. Returns true on exit 0.
/// A missing git binary is reported and returns false.
fn runGit(allocator: std.mem.Allocator, io: Io, env: *EnvMap, cwd: []const u8, args: []const []const u8) !bool {
    return runGitImpl(allocator, io, env, cwd, args, false);
}

/// Like `runGit` but discards stdout/stderr — for best-effort calls whose
/// failure is expected and whose noise would alarm the user.
fn runGitQuiet(allocator: std.mem.Allocator, io: Io, env: *EnvMap, cwd: []const u8, args: []const []const u8) !bool {
    return runGitImpl(allocator, io, env, cwd, args, true);
}

fn runGitImpl(allocator: std.mem.Allocator, io: Io, env: *EnvMap, cwd: []const u8, args: []const []const u8, quiet: bool) !bool {
    const git = env.get("HKM_GIT_BIN") orelse "git";
    var argv: std.ArrayList([]const u8) = .empty;
    try argv.append(allocator, git);
    try argv.appendSlice(allocator, args);

    var child = std.process.spawn(io, .{
        .argv = argv.items,
        .environ_map = env,
        .cwd = .{ .path = cwd },
        .stdin = .inherit,
        .stdout = if (quiet) .ignore else .inherit,
        .stderr = if (quiet) .ignore else .inherit,
    }) catch {
        if (!quiet) prompt.warn("git not found — install it or set HKM_GIT_BIN.");
        return false;
    };
    const term = child.wait(io) catch return false;
    return switch (term) {
        .exited => |code| code == 0,
        else => false,
    };
}

fn composerUpdate(allocator: std.mem.Allocator, io: Io, env: *EnvMap, root: []const u8, offline: bool) !void {
    const composer = env.get("HKM_COMPOSER_BIN") orelse "composer";
    if (offline) {
        try env.put("COMPOSER_DISABLE_NETWORK", "1");
        prompt.ok("Running composer update --no-dev (offline)…");
    } else {
        prompt.ok("Running composer update…");
    }

    const argv: []const []const u8 = if (offline)
        &.{ composer, "update", "--no-dev" }
    else
        &.{ composer, "update" };

    var child = std.process.spawn(io, .{
        .argv = argv,
        .environ_map = env,
        .cwd = .{ .path = root },
        .stdin = .inherit,
        .stdout = .inherit,
        .stderr = .inherit,
    }) catch {
        prompt.warn("composer not found — skipped. Run `composer update` yourself (or set HKM_COMPOSER_BIN).");
        return;
    };
    const term = child.wait(io) catch {
        prompt.warn("composer update did not complete cleanly.");
        return;
    };
    switch (term) {
        .exited => |code| {
            if (code == 0) prompt.ok("Dependencies updated") else prompt.warn(try std.fmt.allocPrint(allocator, "composer update exited with code {d}.", .{code}));
        },
        else => prompt.warn("composer update was terminated."),
    }
}

// ── .gitmodules probe ─────────────────────────────────────────────────────────

/// True when `.gitmodules` already declares a submodule at `rel` (modules/<name>).
fn gitmodulesHasPath(allocator: std.mem.Allocator, io: Io, root: []const u8, rel: []const u8) !bool {
    const path = try util.join(allocator, root, ".gitmodules");
    const src = Dir.cwd().readFileAlloc(io, path, allocator, .limited(1024 * 1024)) catch return false;
    const needle = try std.fmt.allocPrint(allocator, "path = {s}", .{rel});
    if (std.mem.indexOf(u8, src, needle) != null) return true;
    // Also match a section header form `[submodule "modules/<name>"]`.
    const hdr = try std.fmt.allocPrint(allocator, "\"{s}\"", .{rel});
    return std.mem.indexOf(u8, src, hdr) != null;
}

// ── composer.json wiring ──────────────────────────────────────────────────────

const WireResult = enum { wired, already, failed };

/// Insert a `{ type: path, url: modules/<folder> }` repository and a
/// `"<pkg>": "*"` require line into the root composer.json via targeted string
/// insertion (preserves the file's existing formatting). Idempotent.
fn wireComposer(allocator: std.mem.Allocator, io: Io, root: []const u8, pkg: []const u8, folder: []const u8) !WireResult {
    const path = try util.join(allocator, root, "composer.json");
    const src = Dir.cwd().readFileAlloc(io, path, allocator, .limited(4 * 1024 * 1024)) catch return .failed;

    const url_needle = try std.fmt.allocPrint(allocator, "modules/{s}\"", .{folder});
    if (std.mem.indexOf(u8, src, url_needle) != null) return .already;

    var out: []const u8 = src;

    // 1. require — insert as the first entry right after `"require": {`.
    const req_marker = "\"require\": {";
    if (std.mem.indexOf(u8, out, req_marker)) |ri| {
        const after = ri + req_marker.len;
        const line = try std.fmt.allocPrint(allocator, "\n        \"{s}\": \"*\",", .{pkg});
        out = try spliceAt(allocator, out, after, line);
    } else return .failed;

    // 2. repositories — insert a new path object right after `"repositories": [`.
    const repo_marker = "\"repositories\": [";
    if (std.mem.indexOf(u8, out, repo_marker)) |pi| {
        const after = pi + repo_marker.len;
        const block = try std.fmt.allocPrint(
            allocator,
            "\n        {{\n            \"type\": \"path\",\n            \"url\": \"modules/{s}\"\n        }},",
            .{folder},
        );
        out = try spliceAt(allocator, out, after, block);
    } else return .failed;

    Dir.cwd().writeFile(io, .{ .sub_path = path, .data = out }) catch return .failed;
    return .wired;
}

/// Remove the `require` line and the `repositories[]` path object for
/// `modules/<folder>` from the root composer.json. Returns true when anything
/// was removed. Line-oriented so it stays robust against surrounding formatting.
fn unwireComposer(allocator: std.mem.Allocator, io: Io, root: []const u8, folder: []const u8) !bool {
    const path = try util.join(allocator, root, "composer.json");
    const src = Dir.cwd().readFileAlloc(io, path, allocator, .limited(4 * 1024 * 1024)) catch return false;

    const req_needle = try std.fmt.allocPrint(allocator, "/{s}\":", .{folder}); // "<vendor>/<folder>":
    const url_needle = try std.fmt.allocPrint(allocator, "\"url\": \"modules/{s}\"", .{folder});

    var out: std.ArrayList(u8) = .empty;
    var changed = false;
    // Buffer lines so we can drop a whole `{ … }` repo object when its url matches.
    var lines: std.ArrayList([]const u8) = .empty;
    var it = std.mem.splitScalar(u8, src, '\n');
    while (it.next()) |l| try lines.append(allocator, l);

    var skip_until_brace = false;
    for (lines.items, 0..) |line, li| {
        if (skip_until_brace) {
            // We are inside a repo object being dropped; end at its closing `},`.
            const t = std.mem.trim(u8, line, " \t\r");
            if (std.mem.eql(u8, t, "},") or std.mem.eql(u8, t, "}")) skip_until_brace = false;
            changed = true;
            continue;
        }
        // Drop the require line for this package.
        if (std.mem.indexOf(u8, line, req_needle) != null and std.mem.indexOf(u8, line, "\": \"") != null) {
            changed = true;
            continue;
        }
        // A `{` opening a repo object whose url line is this module → drop the block.
        const t = std.mem.trim(u8, line, " \t\r");
        if (std.mem.eql(u8, t, "{") and repoObjectMatches(lines.items, li, url_needle)) {
            skip_until_brace = true;
            changed = true;
            continue;
        }
        try out.appendSlice(allocator, line);
        if (li + 1 < lines.items.len) try out.append(allocator, '\n');
    }

    if (!changed) return false;
    Dir.cwd().writeFile(io, .{ .sub_path = path, .data = out.items }) catch return false;
    return true;
}

/// Does the repo object opening at `open` contain `url_needle` before it closes?
fn repoObjectMatches(lines: []const []const u8, open: usize, url_needle: []const u8) bool {
    var j = open + 1;
    while (j < lines.len) : (j += 1) {
        const t = std.mem.trim(u8, lines[j], " \t\r");
        if (std.mem.eql(u8, t, "},") or std.mem.eql(u8, t, "}")) return false;
        if (std.mem.indexOf(u8, lines[j], url_needle) != null) return true;
    }
    return false;
}

/// Return `src` with `insert` spliced in at byte offset `at`.
fn spliceAt(allocator: std.mem.Allocator, src: []const u8, at: usize, insert: []const u8) ![]const u8 {
    var buf: std.ArrayList(u8) = .empty;
    try buf.appendSlice(allocator, src[0..at]);
    try buf.appendSlice(allocator, insert);
    try buf.appendSlice(allocator, src[at..]);
    return buf.toOwnedSlice(allocator);
}

// ── module composer.json template ─────────────────────────────────────────────

fn moduleComposer(allocator: std.mem.Allocator, pkg: []const u8, ns: []const u8, desc: []const u8) ![]const u8 {
    const ns_json = try replaceAll(allocator, ns, "\\", "\\\\");
    return std.fmt.allocPrint(allocator,
        \\{{
        \\    "name": "{s}",
        \\    "description": "{s}",
        \\    "type": "library",
        \\    "license": "MIT",
        \\    "autoload": {{
        \\        "psr-4": {{
        \\            "{s}\\": "src/"
        \\        }}
        \\    }},
        \\    "require": {{
        \\        "php": "^8.2"
        \\    }},
        \\    "minimum-stability": "dev",
        \\    "prefer-stable": true
        \\}}
        \\
    , .{ pkg, desc, ns_json });
}

// ── helpers ───────────────────────────────────────────────────────────────────

/// kebab-case a name: `BillingEngine` / `billing_engine` / `Billing Engine`
/// → `billing-engine`. A hyphen already present is preserved.
fn kebab(allocator: std.mem.Allocator, name: []const u8) ![]const u8 {
    var out: std.ArrayList(u8) = .empty;
    var prev_sep = true; // suppress a leading separator
    for (name, 0..) |c, idx| {
        if (c == '-' or c == '_' or c == ' ') {
            if (!prev_sep and out.items.len > 0) try out.append(allocator, '-');
            prev_sep = true;
            continue;
        }
        if (std.ascii.isUpper(c)) {
            if (idx > 0 and !prev_sep) try out.append(allocator, '-');
            try out.append(allocator, std.ascii.toLower(c));
        } else {
            try out.append(allocator, c);
        }
        prev_sep = false;
    }
    return out.toOwnedSlice(allocator);
}

fn replaceAll(allocator: std.mem.Allocator, input: []const u8, needle: []const u8, value: []const u8) ![]const u8 {
    var out: std.ArrayList(u8) = .empty;
    var i: usize = 0;
    while (i < input.len) {
        if (std.mem.startsWith(u8, input[i..], needle)) {
            try out.appendSlice(allocator, value);
            i += needle.len;
        } else {
            try out.append(allocator, input[i]);
            i += 1;
        }
    }
    return out.toOwnedSlice(allocator);
}

// ── help ──────────────────────────────────────────────────────────────────────

fn printHelp() void {
    prompt.intro("hkm module — first-party kernel packages (modules/ submodules)");
    prompt.section("Usage");
    prompt.item("hkm module", "list the kernel modules");
    prompt.item("hkm module add <name> <git-url> [org]", "add a submodule + wire composer.json + update");
    prompt.item("hkm module remove <name>", "deinit + remove the submodule + unwire composer.json");
    prompt.blank();
    prompt.section("Options");
    prompt.item("--org, --vendor <v>", "composer vendor (default: alfacode-team; or the 3rd arg)");
    prompt.item("--namespace <N>", "PSR-4 root (default: <StudlyOrg>\\<StudlyName>)");
    prompt.item("--desc <text>", "description for a generated composer.json");
    prompt.item("--offline", "composer update with the network disabled (--no-dev)");
    prompt.item("--no-composer", "skip the composer update step");
    prompt.item("--dry-run, -n", "preview the git/composer actions without running them");
    prompt.item("--help, -h", "show this help");
    prompt.blank();
    prompt.section("Notes");
    prompt.item("submodule", "add runs `git submodule add <url> modules/<name>` — the remote must exist");
    prompt.item("scope", "kernel modules are reusable libraries (NOT plugins — see `hkm plugins`)");
    prompt.item("location", "run from inside the kernel monorepo (dir with composer.json + modules/)");
    prompt.item("commits", "git changes are STAGED, never committed — review + commit yourself");
    prompt.item("aliases", "add=create/new/scaffold · remove=delete/del/rm · list=ls");
    prompt.outro("Mirrors the module.sh add/remove flow, git-submodule aware");
}
