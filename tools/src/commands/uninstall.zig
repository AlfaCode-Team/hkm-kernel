//! `hkm uninstall` — remove every trace of hkm from this machine, EXCEPT the
//! project registry and the projects themselves.
//!
//!   hkm uninstall              # remove everything present that this user can
//!   sudo hkm uninstall         # …including the system (.deb) install
//!   hkm uninstall --dry-run    # show the plan, delete nothing
//!
//! WHAT IS NEVER TOUCHED
//! ---------------------
//! Two things, and they are protected by CONSTRUCTION rather than by a filter:
//!
//!   1. **Your projects.** hkm never owned them. Every path this command can
//!      delete is COMPUTED from the install layout (lib/install_scope.zig,
//!      lib/userconfig.zig, lib/plugin_store.zig) — none of them is read from
//!      the registry, from the current directory, or from an argument. A
//!      project directory therefore cannot appear in the plan at all.
//!   2. **projects.json + platform.json.** Before any kernel root is removed,
//!      its copy of the registry is RESCUED into the userdata directory, and
//!      that directory is never itself a target — only the stale kernel that
//!      may sit inside it. So the registry survives even when the only copy was
//!      inside the tree being deleted.
//!
//! A machine can hold two installs (system `.deb` and user tarball — see
//! lib/install_scope.zig), plus config, plus a plugin cache. "Uninstall" that
//! removes only one of those leaves the others to be discovered months later,
//! which is why the default here is everything this user is able to remove
//! rather than a single scope.
//!
//! The command deletes the binary it is running from. On Linux that is safe:
//! unlinking an executable does not disturb the running process, which finishes
//! normally.

const std = @import("std");
const install_scope = @import("../lib/install_scope.zig");
const prompt = @import("../lib/prompt.zig");
const run_cmd = @import("run.zig");
const userconfig = @import("../lib/userconfig.zig");
const util = @import("../lib/util.zig");

const Dir = std.Io.Dir;
const Io = std.Io;
const EnvMap = std.process.Environ.Map;

/// One thing to delete.
const Target = struct {
    path: []const u8,
    /// Human label for the plan table.
    what: []const u8,
    is_dir: bool,
    present: bool,
    /// Removing it needs root (a system path).
    needs_root: bool,
};

/// The registry filenames that must outlive the uninstall.
const registry_files = [_][]const u8{ "projects.json", "platform.json" };

pub fn run(allocator: std.mem.Allocator, io: Io, env: *EnvMap, args: []const []const u8) !u8 {
    // Reject anything not recognised BEFORE acting on what is.
    //
    // The house style elsewhere is to ignore unknown flags so future ones do not
    // hard fail. That is wrong for this command: the token most likely to be
    // misspelled is the one that makes it safe, and ignoring it inverts the
    // meaning of the line. `hkm uninstall --dryrun --yes` parsed as "no dry run,
    // and don't ask" and deleted the install without a prompt.
    const known = [_][]const u8{ "--dry-run", "-n", "--yes", "-y", "--help", "-h" };
    if (util.unknownFlag(args[1..], &known)) |bad| {
        prompt.err(std.fmt.allocPrint(allocator, "unknown option: {s}", .{bad}) catch "unknown option");
        prompt.hintLine("  nothing was removed. Run `hkm uninstall --help` for the accepted flags.");
        return 1;
    }

    var dry_run = false;
    var assume_yes = false;
    for (args[1..]) |a| {
        if (std.mem.eql(u8, a, "--")) break; // end of options, as unknownFlag treats it
        if (std.mem.eql(u8, a, "--dry-run") or std.mem.eql(u8, a, "-n")) dry_run = true;
        if (std.mem.eql(u8, a, "--yes") or std.mem.eql(u8, a, "-y")) assume_yes = true;
        if (std.mem.eql(u8, a, "--help") or std.mem.eql(u8, a, "-h")) {
            printHelp();
            return 0;
        }
    }

    const is_root = install_scope.isRoot(env);
    prompt.intro("hkm uninstall");

    // ── The directory that must survive ─────────────────────────────────────
    const keep_dir = userdataDir(allocator, env);

    // ── Build the plan ──────────────────────────────────────────────────────
    var targets: std.ArrayList(Target) = .empty;

    // System scope: the .deb's kernel and launchers.
    try addTarget(allocator, io, &targets, install_scope.system_root, "system kernel", true, true);
    for ([_][]const u8{ "hkm", "hkm-config" }) |name| {
        const p = try std.fs.path.join(allocator, &.{ install_scope.system_bin_dir, name });
        try addTarget(allocator, io, &targets, p, "system launcher", false, true);
    }

    // User scope: the tarball install, its launchers, and the pre-1.4 kernel.
    if (install_scope.userRoot(allocator, env)) |root| {
        try addTarget(allocator, io, &targets, root, "user kernel", true, false);
    }
    if (install_scope.userBinDir(allocator, env)) |bin| {
        for ([_][]const u8{ "hkm", "hkm-config" }) |name| {
            const p = try std.fs.path.join(allocator, &.{ bin, name });
            try addTarget(allocator, io, &targets, p, "user launcher", false, false);
        }
    }
    if (install_scope.legacyUserRoot(allocator, env)) |legacy| {
        try addTarget(allocator, io, &targets, legacy, "legacy user kernel", true, false);
    }

    // Config: ~/.config/hkm (holds config.env).
    if (configDir(allocator, env)) |cfg| {
        try addTarget(allocator, io, &targets, cfg, "launcher config", true, false);
    }

    // Plugin cache: the shared plugin store.
    if (pluginStore(allocator, env)) |store| {
        try addTarget(allocator, io, &targets, store, "plugin cache", true, false);
    }

    var present: usize = 0;
    var blocked: usize = 0;
    for (targets.items) |t| {
        if (!t.present) continue;
        present += 1;
        if (t.needs_root and !is_root) blocked += 1;
    }

    if (present == 0) {
        prompt.ok("nothing to uninstall — no hkm install found on this machine.");
        if (keep_dir) |k| prompt.item("registry (untouched)", k);
        return 0;
    }

    // ── The registry must not live inside something we are about to delete ───
    //
    // "Kept" and "removed" are only different outcomes while the kept path sits
    // OUTSIDE every target. HKM_USERDATA_DIR is operator-settable and can point
    // straight into one — `/opt/hkm-kernel/projects` is the obvious case, since
    // that is where the registry lived before it was migrated out. The plan
    // would then list that directory under "Will KEEP" and delete its parent a
    // moment later, which is precisely the loss this command promises cannot
    // happen. Refuse instead of guessing: moving the registry is the operator's
    // decision, and either choice they make is destructive to get wrong.
    if (keep_dir) |k| {
        for (targets.items) |t| {
            if (!t.present or !t.is_dir) continue;
            if (t.needs_root and !is_root) continue; // not ours to delete
            if (!util.isInside(k, t.path)) continue;

            prompt.err(try std.fmt.allocPrint(
                allocator,
                "the registry directory is inside a directory this would delete.",
                .{},
            ));
            prompt.item("registry", k);
            prompt.item("would delete", t.path);
            prompt.muted("  nothing was removed. Move the registry somewhere outside the install first:");
            prompt.muted("    hkm-config set HKM_USERDATA_DIR ~/.local/share/hkm   # then re-run");
            return 1;
        }
    }

    // ── Show it ─────────────────────────────────────────────────────────────
    prompt.section("Will remove");
    var rows: std.ArrayList([]const []const u8) = .empty;
    for (targets.items) |t| {
        if (!t.present) continue;
        try rows.append(allocator, try allocator.dupe([]const u8, &.{
            t.what,
            t.path,
            if (t.needs_root and !is_root) "NEEDS ROOT — skipped" else "will be removed",
        }));
    }
    prompt.table(allocator, &.{ "what", "path", "" }, rows.items);

    prompt.section("Will KEEP");
    if (keep_dir) |k| {
        prompt.item("registry dir", k);
        for (registry_files) |f| {
            const p = try std.fs.path.join(allocator, &.{ k, f });
            prompt.item(f, if (util.fileExists(io, p)) "kept" else "not present");
        }
    } else {
        prompt.warn("could not resolve a registry directory (no HOME) — nothing to preserve.");
    }
    prompt.hintLine("  your projects are never touched: no path above is read from the registry,");
    prompt.hintLine("  the working directory, or an argument — every one is a fixed install path.");

    if (dry_run) {
        prompt.blank();
        prompt.outro("Dry run — nothing was removed");
        return 0;
    }

    prompt.blank();
    if (!assume_yes and !prompt.confirm(io, "Remove everything listed above?", false)) {
        prompt.muted("cancelled — nothing was removed");
        return 1;
    }

    // ── Rescue the registry BEFORE anything is deleted ───────────────────────
    //
    // The only copy of projects.json may live inside a kernel tree that is about
    // to go (that is where it lived before `hkm-config check` migrated it out).
    // Copying first means the guarantee holds even then.
    if (keep_dir) |k| {
        rescueRegistry(allocator, io, k, targets.items, is_root) catch |e| {
            prompt.err(try std.fmt.allocPrint(
                allocator,
                "could not preserve the project registry ({t}) — nothing was removed.",
                .{e},
            ));
            prompt.muted("  the registry is the one thing this command guarantees, so it stops");
            prompt.muted("  rather than delete a kernel whose copy it failed to save.");
            return 1;
        };
    }

    // ── Remove ───────────────────────────────────────────────────────────────
    prompt.section("Removing");

    // Let dpkg forget the package first, so its database does not keep claiming
    // an install that no longer exists. `remove`, never `purge`: purge deletes
    // the conffiles, and projects.json + platform.json are marked as conffiles
    // by tools/bundle.sh precisely because they are user data.
    if (is_root) removeDebPackage(allocator, io, env);

    var removed: usize = 0;
    var failed: usize = 0;
    var skipped: usize = 0;

    for (targets.items) |t| {
        if (!t.present) continue;
        if (t.needs_root and !is_root) {
            skipped += 1;
            continue;
        }
        // Belt and braces. Every path here is computed, but the cost of a bug
        // in that computation is an unrecoverable deletion, so the guard runs
        // anyway and a rejected path is reported rather than silently skipped.
        if (!removable(env, t.path)) {
            failed += 1;
            prompt.err(try std.fmt.allocPrint(allocator, "refused to remove an implausible path: {s}", .{t.path}));
            continue;
        }

        const result = if (t.is_dir)
            Dir.cwd().deleteTree(io, t.path)
        else
            Dir.cwd().deleteFile(io, t.path);

        if (result) |_| {
            removed += 1;
            prompt.item("removed", t.path);
        } else |e| {
            // Already gone between the plan and now is success, not failure.
            if (e == error.FileNotFound) {
                removed += 1;
                continue;
            }
            failed += 1;
            prompt.err(try std.fmt.allocPrint(allocator, "{s}: {t}", .{ t.path, e }));
        }
    }

    // ── Report ───────────────────────────────────────────────────────────────
    prompt.blank();
    prompt.ok(try std.fmt.allocPrint(allocator, "removed {d} item(s)", .{removed}));
    if (keep_dir) |k| {
        prompt.item("registry kept at", k);
        prompt.muted("  reinstall later and your projects are still registered: hkm list");
    }

    if (skipped > 0) {
        prompt.blank();
        prompt.warn(try std.fmt.allocPrint(
            allocator,
            "{d} system item(s) were NOT removed — they need root.",
            .{skipped},
        ));
        prompt.item("finish with", "sudo hkm uninstall");
        prompt.muted("  if `hkm` is already gone from your PATH, use the full path:");
        prompt.muted("    sudo /usr/bin/hkm uninstall");
        return 1;
    }

    if (failed > 0) {
        prompt.err(try std.fmt.allocPrint(allocator, "{d} item(s) could not be removed.", .{failed}));
        return 1;
    }

    prompt.outro("hkm is uninstalled. Your projects and their registry were left alone.");
    return 0;
}

/// Record a target, resolving whether it is actually there.
fn addTarget(
    allocator: std.mem.Allocator,
    io: Io,
    list: *std.ArrayList(Target),
    path: []const u8,
    what: []const u8,
    is_dir: bool,
    needs_root: bool,
) !void {
    const p = util.trimSlash(path);
    if (p.len == 0) return;
    const there = if (is_dir) util.dirExists(Dir.cwd(), io, p) else util.fileExists(io, p);
    try list.append(allocator, .{
        .path = try allocator.dupe(u8, p),
        .what = what,
        .is_dir = is_dir,
        .present = there,
        .needs_root = needs_root,
    });
}

/// Copy any registry file found inside a doomed kernel tree into `keep`.
///
/// Only fills gaps: a registry already in `keep` is the live one and is never
/// overwritten by a copy from inside an install being deleted.
///
/// TWO RULES, both learned by running this against a machine that had both
/// installs. Without either, the rescue could REPLACE the user's real registry
/// with an empty default and report success:
///
///  1. **Only rescue from a tree that is actually being removed.** A system
///     kernel skipped for lack of root keeps its own registry where it is;
///     copying from it is not a rescue, it is an unrelated file winning the
///     race to an empty destination.
///  2. **Prefer the user's kernel over the system one.** A `.deb` ships
///     `projects/projects.json` as `{}` (it is a packaged default, marked a
///     conffile). Scanning in target order put that `{}` first, and because the
///     destination then existed, the user's real project list was skipped.
fn rescueRegistry(
    allocator: std.mem.Allocator,
    io: Io,
    keep: []const u8,
    targets: []const Target,
    is_root: bool,
) !void {
    Dir.cwd().createDirPath(io, keep) catch |e| switch (e) {
        error.PathAlreadyExists => {},
        // Nowhere to rescue TO. Continuing would delete the only copy.
        else => return e,
    };

    // Pass 1 = the user's own kernels, pass 2 = system. Ordering is the fix for
    // rule 2 above and must not be flattened back into one loop.
    for ([_]bool{ false, true }) |system_pass| {
        for (targets) |t| {
            if (!t.present or !t.is_dir) continue;
            if (t.needs_root != system_pass) continue;
            // Only kernel roots carry a projects/ directory.
            if (std.mem.indexOf(u8, t.what, "kernel") == null) continue;
            // Rule 1: a tree we cannot remove keeps its own copy.
            if (t.needs_root and !is_root) continue;

            for (registry_files) |f| {
                const dest = try std.fs.path.join(allocator, &.{ keep, f });
                if (util.fileExists(io, dest)) continue; // the live copy wins

                const src = try std.fs.path.join(allocator, &.{ t.path, "projects", f });

                // An ABSENT source is the only tolerable failure: this kernel
                // simply has no registry, and the next one may. Every other
                // error propagates, because the caller is about to delete this
                // tree and a swallowed write error means the only copy is gone
                // while the command reports success.
                const data = Dir.cwd().readFileAlloc(io, src, allocator, .limited(8 * 1024 * 1024)) catch |e| switch (e) {
                    error.FileNotFound => continue,
                    else => return e,
                };

                // Atomic, for the same reason the registry writer is: a partial
                // rescue is indistinguishable from a complete one afterwards.
                try util.writeFileAtomic(io, dest, data);
                prompt.item("rescued", dest);
            }
        }
    }
}

/// `apt-get remove` (falling back to `dpkg -r`) so dpkg stops believing the
/// package is installed. Best effort: a machine that installed from the tarball
/// has no package here, and that is not an error.
fn removeDebPackage(allocator: std.mem.Allocator, io: Io, env: *EnvMap) void {
    if (@import("builtin").os.tag != .linux) return;

    // Only act when dpkg actually knows the package, so a tarball-only machine
    // does not run apt for nothing (and does not print apt's error as if the
    // uninstall had gone wrong).
    const q = std.process.run(allocator, io, .{
        .argv = &.{ "dpkg-query", "-W", "-f=${Status}", "hkm-kernel" },
        .environ_map = env,
    }) catch return;
    switch (q.term) {
        .exited => |c| if (c != 0) return,
        else => return,
    }
    if (std.mem.indexOf(u8, q.stdout, "installed") == null) return;

    prompt.item("dpkg", "removing the hkm-kernel package");
    var apt = [_][]const u8{ "apt-get", "remove", "-y", "hkm-kernel" };
    if ((run_cmd.spawnWait(io, env, &apt) catch 1) == 0) return;

    var dpkg = [_][]const u8{ "dpkg", "-r", "hkm-kernel" };
    _ = run_cmd.spawnWait(io, env, &dpkg) catch {};
}

/// `$HKM_USERDATA_DIR`, else `$XDG_DATA_HOME/hkm`, else `~/.local/share/hkm`.
///
/// Mirrors config.zig's ensureUserdata so the directory preserved here is
/// exactly the one the registry was migrated into.
fn userdataDir(allocator: std.mem.Allocator, env: *EnvMap) ?[]const u8 {
    if (env.get("HKM_USERDATA_DIR")) |d| {
        if (d.len > 0) return util.trimSlash(d);
    }
    if (env.get("XDG_DATA_HOME")) |x| {
        if (x.len > 0) return std.fmt.allocPrint(allocator, "{s}/hkm", .{util.trimSlash(x)}) catch null;
    }
    const home = install_scope.homeDir(allocator, env) orelse return null;
    return std.fmt.allocPrint(allocator, "{s}/.local/share/hkm", .{home}) catch null;
}

/// `$XDG_CONFIG_HOME/hkm`, else `~/.config/hkm` — the directory holding
/// config.env. Mirrors lib/userconfig.zig's path().
fn configDir(allocator: std.mem.Allocator, env: *EnvMap) ?[]const u8 {
    if (env.get("XDG_CONFIG_HOME")) |x| {
        if (x.len > 0) return std.fmt.allocPrint(allocator, "{s}/hkm", .{util.trimSlash(x)}) catch null;
    }
    const home = install_scope.homeDir(allocator, env) orelse return null;
    return std.fmt.allocPrint(allocator, "{s}/.config/hkm", .{home}) catch null;
}

/// The shared plugin store. Mirrors lib/plugin_store.zig's root().
fn pluginStore(allocator: std.mem.Allocator, env: *EnvMap) ?[]const u8 {
    if (env.get("HKM_PLUGIN_STORE")) |v| {
        if (v.len > 0) return util.trimSlash(v);
    }
    if (env.get("XDG_CACHE_HOME")) |x| {
        if (x.len > 0) return std.fmt.allocPrint(allocator, "{s}/hkm/plugin-store", .{util.trimSlash(x)}) catch null;
    }
    const home = install_scope.homeDir(allocator, env) orelse return null;
    return std.fmt.allocPrint(allocator, "{s}/.cache/hkm/plugin-store", .{home}) catch null;
}

/// Could this plausibly be an hkm-owned path?
///
/// Pure defence in depth — every path reaching it was computed from a fixed
/// layout, so a rejection means a bug upstream. It exists because the cost of
/// that bug is an unrecoverable `deleteTree`, and three cheap invariants rule
/// out every catastrophic target: a relative path, a top-level directory, the
/// user's home itself, and anything with no `hkm` in it at all.
fn removable(env: *EnvMap, path: []const u8) bool {
    if (path.len < 2 or path[0] != '/') return false;

    // At least two components, so "/" and "/opt" can never be targets.
    var comps: usize = 0;
    var it = std.mem.tokenizeScalar(u8, path, '/');
    while (it.next()) |_| comps += 1;
    if (comps < 2) return false;

    // Never the home directory itself, however it was derived.
    if (env.get("HOME")) |h| {
        if (h.len > 0 and std.mem.eql(u8, util.trimSlash(h), path)) return false;
    }

    return std.mem.indexOf(u8, path, "hkm") != null;
}

fn printHelp() void {
    prompt.intro("hkm uninstall");
    prompt.section("Usage");
    prompt.item("hkm uninstall", "remove every hkm install this user can remove");
    prompt.item("sudo hkm uninstall", "…including the system (.deb) install under /opt");
    prompt.item("hkm uninstall --dry-run", "show exactly what would go, remove nothing");
    prompt.blank();
    prompt.section("Removes");
    prompt.item("kernels", "/opt/hkm-kernel, ~/.local/lib/hkm-kernel, the pre-1.4 user kernel");
    prompt.item("launchers", "/usr/bin/{hkm,hkm-config}, ~/.local/bin/{hkm,hkm-config}");
    prompt.item("config", "~/.config/hkm (config.env)");
    prompt.item("cache", "the shared plugin store");
    prompt.item("package", "deregisters hkm-kernel from dpkg (remove, never purge)");
    prompt.blank();
    prompt.section("Never removes");
    prompt.item("your projects", "no path is read from the registry, cwd, or an argument");
    prompt.item("the registry", "projects.json + platform.json — rescued out of any kernel first");
    prompt.blank();
    prompt.section("Options");
    prompt.item("--dry-run, -n", "print the plan and exit");
    prompt.item("--yes, -y", "skip the confirmation prompt");
    prompt.item("--help, -h", "show this help");
    prompt.outro("reinstall later and `hkm list` still shows every project");
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test "removable rejects every catastrophic target" {
    var arena = std.heap.ArenaAllocator.init(std.testing.allocator);
    defer arena.deinit();
    const a = arena.allocator();

    var env = EnvMap.init(a);
    defer env.deinit();
    try env.put("HOME", "/home/tester");

    // The ones that would be unrecoverable.
    try std.testing.expect(!removable(&env, "/"));
    try std.testing.expect(!removable(&env, "/opt"));
    try std.testing.expect(!removable(&env, "/usr"));
    try std.testing.expect(!removable(&env, "/home/tester")); // the home itself
    try std.testing.expect(!removable(&env, ""));
    try std.testing.expect(!removable(&env, "relative/path"));
    // No "hkm" anywhere means it is not ours, whatever computed it.
    try std.testing.expect(!removable(&env, "/home/tester/Documents"));
    try std.testing.expect(!removable(&env, "/home/tester/.config"));
}

test "removable accepts every real install path" {
    var arena = std.heap.ArenaAllocator.init(std.testing.allocator);
    defer arena.deinit();
    const a = arena.allocator();

    var env = EnvMap.init(a);
    defer env.deinit();
    try env.put("HOME", "/home/tester");

    for ([_][]const u8{
        "/opt/hkm-kernel",
        "/usr/bin/hkm",
        "/usr/bin/hkm-config",
        "/home/tester/.local/lib/hkm-kernel",
        "/home/tester/.local/bin/hkm",
        "/home/tester/.local/share/hkm/kernel",
        "/home/tester/.config/hkm",
        "/home/tester/.cache/hkm/plugin-store",
    }) |p| {
        try std.testing.expect(removable(&env, p));
    }
}

test "the preserved registry directory is never itself a target" {
    // The guarantee the whole command rests on. The userdata dir holds
    // projects.json; the legacy KERNEL sits inside it and must still go, so the
    // two paths are deliberately different and must not be confused.
    var arena = std.heap.ArenaAllocator.init(std.testing.allocator);
    defer arena.deinit();
    const a = arena.allocator();

    var env = EnvMap.init(a);
    defer env.deinit();
    try env.put("HOME", "/home/tester");

    const keep = userdataDir(a, &env).?;
    try std.testing.expectEqualStrings("/home/tester/.local/share/hkm", keep);

    const legacy = install_scope.legacyUserRoot(a, &env).?;
    try std.testing.expectEqualStrings("/home/tester/.local/share/hkm/kernel", legacy);

    // The kernel is INSIDE the kept dir, and strictly deeper than it.
    try std.testing.expect(!std.mem.eql(u8, keep, legacy));
    try std.testing.expect(util.isInside(legacy, keep));
}

test "userdata, config and cache directories match the writers' own resolution" {
    // Each mirrors a different module (config.zig, userconfig.zig,
    // plugin_store.zig). If one drifts, uninstall silently leaves that data
    // behind — the failure mode is invisible, so it is pinned here.
    var arena = std.heap.ArenaAllocator.init(std.testing.allocator);
    defer arena.deinit();
    const a = arena.allocator();

    var env = EnvMap.init(a);
    defer env.deinit();
    try env.put("HOME", "/home/tester");

    try std.testing.expectEqualStrings("/home/tester/.local/share/hkm", userdataDir(a, &env).?);
    try std.testing.expectEqualStrings("/home/tester/.config/hkm", configDir(a, &env).?);
    try std.testing.expectEqualStrings("/home/tester/.cache/hkm/plugin-store", pluginStore(a, &env).?);

    // XDG overrides win, exactly as the writers do it.
    try env.put("XDG_CONFIG_HOME", "/cfg");
    try env.put("XDG_CACHE_HOME", "/cache");
    try env.put("XDG_DATA_HOME", "/data");
    try std.testing.expectEqualStrings("/data/hkm", userdataDir(a, &env).?);
    try std.testing.expectEqualStrings("/cfg/hkm", configDir(a, &env).?);
    try std.testing.expectEqualStrings("/cache/hkm/plugin-store", pluginStore(a, &env).?);

    // And an explicit userdata pin wins over XDG, as registry.zig reads it.
    try env.put("HKM_USERDATA_DIR", "/srv/hkm-data");
    try std.testing.expectEqualStrings("/srv/hkm-data", userdataDir(a, &env).?);
}

test "sudo preserves the invoking user's paths" {
    // `sudo hkm uninstall` must remove the USER's install too — under sudo HOME
    // is /root, so resolving from it would silently leave the user's kernel,
    // config and cache on disk while reporting a complete uninstall.
    var arena = std.heap.ArenaAllocator.init(std.testing.allocator);
    defer arena.deinit();
    const a = arena.allocator();

    var env = EnvMap.init(a);
    defer env.deinit();
    try env.put("HOME", "/root");
    try env.put("SUDO_USER", "tester");

    try std.testing.expectEqualStrings("/home/tester/.local/share/hkm", userdataDir(a, &env).?);
    try std.testing.expectEqualStrings("/home/tester/.config/hkm", configDir(a, &env).?);
    try std.testing.expectEqualStrings("/home/tester/.cache/hkm/plugin-store", pluginStore(a, &env).?);
}

test "a registry inside a deletion target is detected, not deleted" {
    // The critical case: HKM_USERDATA_DIR pointing into a kernel tree. The plan
    // would list it under "Will KEEP" and delete its parent moments later — the
    // exact loss this command promises cannot happen. isInside is what the
    // guard keys on, so pin its behaviour for the real layouts.
    try std.testing.expect(util.isInside("/opt/hkm-kernel/projects", "/opt/hkm-kernel"));
    try std.testing.expect(util.isInside("/home/u/.local/lib/hkm-kernel/projects", "/home/u/.local/lib/hkm-kernel"));

    // …and the SAFE default must not trip the guard, or uninstall never runs.
    try std.testing.expect(!util.isInside("/home/u/.local/share/hkm", "/home/u/.local/lib/hkm-kernel"));
    try std.testing.expect(!util.isInside("/home/u/.local/share/hkm", "/opt/hkm-kernel"));

    // A sibling whose name merely starts the same way is not "inside".
    try std.testing.expect(!util.isInside("/opt/hkm-kernel-old", "/opt/hkm-kernel"));
}
