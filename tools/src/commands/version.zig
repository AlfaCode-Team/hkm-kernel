//! `hkm version` — the banner, plus WHICH kernel each install scope holds.
//!
//! The old version command printed one number: `build_info.version`, stamped
//! into the launcher binary at compile time. On a machine with a single install
//! that is the right answer. On a machine with two — a .deb under /opt and a
//! user install under ~/.local, which is an ordinary state — it answers a
//! question nobody asked, and produces exactly the confusion that makes an
//! upgrade look like it did nothing:
//!
//!     $ hkm --version              → 0.0.0-dev   (a stale ~/.local/bin launcher)
//!     $ /usr/bin/hkm --version     → 1.3.1       (the .deb, first on nobody's PATH)
//!     $ sudo hkm upgrade           → updates /opt … and the number never moves
//!
//! Three separate versions are in play and they can all differ:
//!
//!   • the LAUNCHER binary's stamp — what `--version` reports;
//!   • the KERNEL on disk, from its composer.json — what actually runs;
//!   • and one of each, per scope.
//!
//! So this prints all of them, marks which kernel this invocation resolves, and
//! says why. `hkm --version` keeps its single-line, script-friendly output.

const std = @import("std");
const banner = @import("../lib/banner.zig");
const install_scope = @import("../lib/install_scope.zig");
const kernel = @import("../lib/kernel.zig");
const prompt = @import("../lib/prompt.zig");
const util = @import("../lib/util.zig");

const Io = std.Io;
const EnvMap = std.process.Environ.Map;
const Scope = install_scope.Scope;

pub fn run(allocator: std.mem.Allocator, io: Io, env: *EnvMap, args: []const []const u8) !u8 {
    for (args[1..]) |a| {
        if (std.mem.eql(u8, a, "--help") or std.mem.eql(u8, a, "-h")) {
            printHelp();
            return 0;
        }
    }

    banner.print(allocator, io, env);

    const active = try kernel.resolveHomeDetailed(allocator, io, env);
    const self_exe = std.process.executableDirPathAlloc(io, allocator) catch null;

    // ── Installs ────────────────────────────────────────────────────────────
    prompt.section("Installs");

    var rows: std.ArrayList([]const []const u8) = .empty;
    var any_present = false;

    for ([_]Scope{ .system, .user }) |scope| {
        const inst = install_scope.detect(allocator, io, env, scope);
        if (inst.present) any_present = true;

        const marker: []const u8 = blk: {
            const root = active.root orelse break :blk " ";
            break :blk if (std.mem.eql(u8, util.trimSlash(root), util.trimSlash(inst.root))) "→" else " ";
        };

        const kernel_ver: []const u8 = if (inst.present)
            install_scope.versionLabel(inst.version)
        else
            "not installed";

        try rows.append(allocator, try allocator.dupe([]const u8, &.{
            marker,
            scope.label(),
            inst.root,
            kernel_ver,
            try launcherCell(allocator, io, env, inst, self_exe),
        }));
    }

    prompt.table(
        allocator,
        &.{ "", "scope", "kernel", "kernel version", "launcher" },
        rows.items,
    );

    if (!any_present) {
        prompt.blank();
        prompt.warn("no kernel is installed in either scope.");
        prompt.muted("  user-local (no root):  hkm upgrade --user");
        prompt.muted("  system-wide:           sudo hkm upgrade --system");
    }

    // ── Active ──────────────────────────────────────────────────────────────
    prompt.section("Active");
    if (active.root) |root| {
        prompt.item("kernel", root);
        prompt.item("resolved via", kernel.sourceLabel(active.source));
        if (install_scope.scopeOf(allocator, env, root)) |s| {
            prompt.item("scope", s.label());
        } else {
            // A dev checkout or a custom prefix. Worth naming so nobody reads
            // the table above and concludes the CLI is running one of those two.
            prompt.item("scope", "neither — a checkout or a custom prefix");
        }
    } else {
        prompt.item("kernel", "NONE FOUND");
    }
    prompt.item("this launcher", banner.version());
    if (self_exe) |d| prompt.item("launcher path", try std.fs.path.join(allocator, &.{ d, install_scope.launcher_name }));

    // ── Anything that will mislead the reader later ─────────────────────────
    try warnings(allocator, io, env, active, self_exe);

    prompt.outro("upgrade this scope with: hkm upgrade   (sudo hkm upgrade for system)");
    return 0;
}

/// The launcher column: its path and the version IT reports.
///
/// The version is obtained by running `<launcher> --version`, not read off
/// disk, because it is compiled into the binary and there is no other way to
/// see it. That is the point of the column: a launcher whose stamp differs from
/// its kernel's version is the single most common reason an upgrade "did
/// nothing", and it is invisible from any file on disk.
fn launcherCell(
    allocator: std.mem.Allocator,
    io: Io,
    env: *EnvMap,
    inst: install_scope.Install,
    self_exe: ?[]const u8,
) ![]const u8 {
    const exe = inst.launcher orelse return "absent";

    // Never spawn ourselves: we already know this binary's version, and running
    // it would be a pointless subprocess on the hot path of a trivial command.
    if (self_exe) |d| {
        const own = try std.fs.path.join(allocator, &.{ d, install_scope.launcher_name });
        if (std.mem.eql(u8, own, exe)) {
            return std.fmt.allocPrint(allocator, "{s} ({s}, this one)", .{ exe, banner.version() });
        }
    }

    const v = launcherVersion(allocator, io, env, exe) orelse return exe;
    return std.fmt.allocPrint(allocator, "{s} ({s})", .{ exe, v });
}

/// Ask a launcher binary what version it was built as.
///
/// `hkm --version` prints "hkm (HKM Kernel) <version>" to stdout; take the last
/// whitespace-separated token. Null on any failure — an unreadable version is
/// never a reason to fail the command that reports it.
fn launcherVersion(allocator: std.mem.Allocator, io: Io, env: *EnvMap, exe: []const u8) ?[]const u8 {
    const res = std.process.run(allocator, io, .{
        .argv = &.{ exe, "--version" },
        .environ_map = env,
    }) catch return null;
    switch (res.term) {
        .exited => |c| if (c != 0) return null,
        else => return null,
    }
    const line = std.mem.trim(u8, res.stdout, " \t\r\n");
    if (line.len == 0) return null;
    const last = std.mem.lastIndexOfScalar(u8, line, ' ') orelse return line;
    const v = line[last + 1 ..];
    return if (v.len == 0) null else v;
}

/// The states that make a later "my upgrade did nothing" report inevitable.
fn warnings(
    allocator: std.mem.Allocator,
    io: Io,
    env: *EnvMap,
    active: kernel.ResolvedHome,
    self_exe: ?[]const u8,
) !void {
    var said = false;
    const note = struct {
        fn head(flag: *bool) void {
            if (flag.*) return;
            prompt.section("Worth knowing");
            flag.* = true;
        }
    };

    // 1. A different `hkm` earlier on PATH than this one. The upgrade you run
    //    and the binary your next command uses are then two different installs.
    if (findOnPath(allocator, io, env, install_scope.launcher_name)) |first| {
        if (self_exe) |d| {
            const own = try std.fs.path.join(allocator, &.{ d, install_scope.launcher_name });
            if (!std.mem.eql(u8, own, first)) {
                note.head(&said);
                prompt.warn("another hkm comes first on your PATH — that is the one a bare `hkm` runs.");
                prompt.item("first on PATH", first);
                prompt.item("this binary", own);
            }
        }
    }

    // 2. A user install still at the pre-1.4 location. It is a second kernel on
    //    disk that only a config pin can reach, and it is what the pin usually
    //    points at on a machine that hit this bug.
    const user = install_scope.detect(allocator, io, env, .user);
    if (user.legacy_root) |legacy| {
        note.head(&said);
        prompt.warn("a user kernel exists at the old location and is no longer updated.");
        prompt.item("legacy", legacy);
        prompt.item("current", user.root);
        prompt.item("migrate", "hkm upgrade --user   (then delete the legacy directory)");
    }

    // 3. Resolution falling back to a config pin. Legitimate for a custom
    //    prefix, and the fingerprint of a stale pin otherwise.
    if (active.source == .kernel_home_config) {
        note.head(&said);
        prompt.warn("the active kernel comes from a config.env pin, not from this launcher's own install.");
        prompt.item("clear it", "hkm-config unset HKM_KERNEL_HOME");
    }

    // 4. A resolved kernel with no dependencies cannot run anything, and the
    //    version above would otherwise look perfectly healthy.
    if (active.root) |root| {
        const autoload = try std.fs.path.join(allocator, &.{ root, "vendor", "autoload.php" });
        if (!util.fileExists(io, autoload)) {
            note.head(&said);
            prompt.warn("the active kernel has no vendor/ — it cannot boot.");
            prompt.item("fix", try std.fmt.allocPrint(allocator, "cd {s} && ./install.sh", .{root}));
        }
    }
}

/// First match for `name` on PATH — the one a bare command actually runs.
fn findOnPath(allocator: std.mem.Allocator, io: Io, env: *EnvMap, name: []const u8) ?[]const u8 {
    const path = env.get("PATH") orelse return null;
    var it = std.mem.splitScalar(u8, path, ':');
    while (it.next()) |dir| {
        if (dir.len == 0) continue;
        const cand = std.fs.path.join(allocator, &.{ dir, name }) catch continue;
        if (util.fileExists(io, cand)) return cand;
    }
    return null;
}

fn printHelp() void {
    prompt.intro("hkm version");
    prompt.section("Usage");
    prompt.item("hkm version", "banner + the kernel version in each install scope");
    prompt.item("hkm --version", "one line, for scripts (this launcher's version only)");
    prompt.blank();
    prompt.section("What the columns mean");
    prompt.item("kernel version", "from <kernel>/composer.json — the code that actually runs");
    prompt.item("launcher", "the hkm binary for that scope, and the version it was built as");
    // Spelled out rather than printed as the bare glyph: prompt.item pads keys
    // by byte length, and a 3-byte arrow would misalign the whole block.
    prompt.item("arrow marker", "the install this invocation resolves");
    prompt.outro("a launcher and kernel that disagree is why an upgrade can look like a no-op");
}
