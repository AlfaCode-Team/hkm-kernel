//! Kernel location resolution shared by the launcher passthrough (main.zig) and
//! `hkm doctor`. Given the environment, returns the path to the kernel's PHP CLI
//! (`<kernel>/bin/hkm`) that the launcher invokes as `php <cli> …`.
//!
//! RESOLUTION ORDER, AND WHY A CONFIG PIN NO LONGER WINS
//! ----------------------------------------------------
//! A machine can hold two installs at once — the .deb's /opt/hkm-kernel and a
//! user's ~/.local/lib/hkm-kernel (see lib/install_scope.zig). Both launchers
//! read the SAME ~/.config/hkm/config.env, and HKM_KERNEL_HOME used to be
//! checked before anything else. So whichever installer wrote that pin last
//! silently redirected the other install too:
//!
//!     $ /usr/bin/hkm --version          → 1.3.1   (the .deb's launcher)
//!     $ /usr/bin/hkm doctor
//!       kernel root  /home/me/.local/share/hkm/kernel     ← the USER's kernel
//!       resolved via HKM_KERNEL_HOME override
//!
//! Upgrading either scope then appeared to do nothing, because the version on
//! screen came from a launcher whose kernel belonged to the other install. The
//! order below fixes that by ranking the sources by how specific they are to
//! THIS invocation:
//!
//!   1. HKM_CLI_PATH / HKM_KERNEL_HOME exported in the real environment —
//!      this command's explicit instruction, always wins.
//!   2. Self-location relative to this launcher's own executable, which is
//!      per-install by construction and cannot be affected by the other one.
//!      For a launcher in a system bin dir (/usr/bin) that includes claiming
//!      /opt/hkm-kernel, since no relative probe can reach it from there.
//!   3. HKM_KERNEL_HOME from config.env — now a FALLBACK, for installs at a
//!      custom path that self-location genuinely cannot find.
//!   4. /opt/hkm-kernel, the last-resort default.
//!
//! The behaviour change is narrow: a config pin still works whenever the
//! launcher cannot self-locate a kernel, which is the case it was added for. It
//! no longer overrides an install that is sitting right next to the binary.

const std = @import("std");
const install_scope = @import("install_scope.zig");
const userconfig = @import("userconfig.zig");
const util = @import("util.zig");

const Io = std.Io;
const EnvMap = std.process.Environ.Map;
const Dir = std.Io.Dir;

/// How the kernel CLI path was determined — surfaced by `hkm doctor`.
pub const Source = enum {
    cli_path_env,
    /// HKM_KERNEL_HOME exported in the real environment.
    kernel_home_env,
    /// HKM_KERNEL_HOME from ~/.config/hkm/config.env (a fallback, not an override).
    kernel_home_config,
    self_located,
    default,
};

pub const Resolved = struct {
    path: []const u8,
    source: Source,
    /// true when the resolved path actually exists on disk.
    exists: bool,
};

fn envGet(allocator: std.mem.Allocator, map: *EnvMap, key: []const u8) !?[]const u8 {
    const v = map.get(key) orelse return null;
    return try allocator.dupe(u8, v);
}

/// The PHP CLI inside a kernel root.
///
/// A BUNDLE installs the PHP CLI as `bin/hkm`, because that is what this
/// launcher's passthrough invokes. The DEV MONOREPO cannot: `bin/hkm` there is
/// this launcher's own compiled binary, and the PHP CLI keeps its source name,
/// `bin/hkm-cli`. Handing the Mach-O/ELF launcher to `php` is what produced
///
///     PHP Parse error: syntax error, unexpected token "/" … bin/hkm line 16855
///
/// on every `hkm --dev <passthrough>` — a fatal for `--dev` as a whole, not
/// just for one command.
///
/// So: take `bin/hkm` when it is a PHP script, otherwise `bin/hkm-cli`. The
/// check reads the first bytes rather than trusting the layout, because "is
/// this the interpreter's input or the interpreter" is exactly the question,
/// and a name cannot answer it.
pub fn cliPathIn(allocator: std.mem.Allocator, io: Io, root: []const u8) ![]const u8 {
    const installed = try std.fs.path.join(allocator, &.{ root, "bin", "hkm" });

    if (util.fileExists(io, installed) and isPhpScript(allocator, io, installed)) {
        return installed;
    }

    const source = try std.fs.path.join(allocator, &.{ root, "bin", "hkm-cli" });

    if (util.fileExists(io, source)) {
        return source;
    }

    // Neither is usable. Return the installed name so the caller's `exists`
    // check and its diagnostics still describe the layout that was expected.
    return installed;
}

/// Does this file begin like a PHP script (`#!` line or `<?php`)?
fn isPhpScript(allocator: std.mem.Allocator, io: Io, path: []const u8) bool {
    // Capped hard: the alternative candidate is a multi-megabyte native
    // executable, and reading it to decide it is not a script would be absurd.
    const head = Dir.cwd().readFileAlloc(io, path, allocator, .limited(64)) catch |e| switch (e) {
        // A file LONGER than the limit is the normal case for the launcher
        // binary, and the error says nothing about the first bytes — but a
        // native executable never begins with `#!` or `<?php`, so treating an
        // over-long read as "not a script" is only wrong for a PHP CLI whose
        // first 64 bytes we could not get, which cannot happen.
        error.StreamTooLong => return false,
        else => return false,
    };
    defer allocator.free(head);

    return std.mem.startsWith(u8, head, "#!") or std.mem.startsWith(u8, head, "<?php");
}

/// HKM_KERNEL_HOME, split by where it came from.
const Pin = struct {
    value: []const u8,
    /// From config.env rather than a real export — see the header.
    from_config: bool,
};

fn kernelHomePin(allocator: std.mem.Allocator, env: *EnvMap) !?Pin {
    const raw = (try envGet(allocator, env, "HKM_KERNEL_HOME")) orelse return null;
    const v = util.trimSlash(std.mem.trim(u8, raw, " \t\r\n"));
    if (v.len == 0) return null;
    return .{ .value = v, .from_config = userconfig.isFileSourced(env, "HKM_KERNEL_HOME") };
}

/// Resolve the kernel PHP CLI path with full provenance (for diagnostics).
pub fn resolve(allocator: std.mem.Allocator, io: Io, env: *EnvMap) !Resolved {
    // 1. An explicit CLI path is the most specific instruction there is.
    if (try envGet(allocator, env, "HKM_CLI_PATH")) |v| {
        return .{ .path = v, .source = .cli_path_env, .exists = util.fileExists(io, v) };
    }

    const pin = try kernelHomePin(allocator, env);

    // 2. A REAL exported HKM_KERNEL_HOME outranks everything below it.
    if (pin) |p| {
        if (!p.from_config) {
            const path = try cliPathIn(allocator, io, p.value);
            return .{ .path = path, .source = .kernel_home_env, .exists = util.fileExists(io, path) };
        }
    }

    // 3. Self-locate relative to this launcher's own executable.
    if (try selfLocateRoot(allocator, io)) |root| {
        const path = try cliPathIn(allocator, io, root);
        return .{ .path = path, .source = .self_located, .exists = util.fileExists(io, path) };
    }

    // 4. A config-file pin — the fallback for a custom install layout.
    if (pin) |p| {
        const path = try cliPathIn(allocator, io, p.value);
        return .{ .path = path, .source = .kernel_home_config, .exists = util.fileExists(io, path) };
    }

    // 5. Default for a system package install (Linux .deb → /opt/hkm-kernel).
    const def = try cliPathIn(allocator, io, install_scope.system_root);
    return .{ .path = def, .source = .default, .exists = util.fileExists(io, def) };
}

/// Convenience wrapper used by the launcher passthrough — just the path.
pub fn findCliPath(allocator: std.mem.Allocator, io: Io, env: *EnvMap) ![]const u8 {
    return (try resolve(allocator, io, env)).path;
}

/// Public probe: is `dir` a kernel root (holds composer.json)? Used to validate
/// an explicit HKM_DEV_HOME before pinning the invocation to it.
pub fn isKernelDir(io: Io, dir: []const u8) bool {
    return isKernelRoot(io, dir);
}

/// A directory is a kernel root if it holds composer.json (true for both the
/// dev monorepo and an installed /opt/hkm-kernel).
fn isKernelRoot(io: Io, dir: []const u8) bool {
    var buf: [4096]u8 = undefined;
    const marker = std.fmt.bufPrint(&buf, "{s}/composer.json", .{dir}) catch return false;
    return util.fileExists(io, marker);
}

/// The kernel root belonging to THIS launcher, found from its own location.
///
/// Candidates cover every layout tools/bundle.sh and tools/install.sh produce:
///   macOS .app:  <dir>/hkm       + ../Resources/opt/hkm-kernel
///   Windows zip: <dir>/hkm.exe   + hkm-kernel
///   portable:    <dir>/hkm       + ../opt/hkm-kernel
///   user install:<prefix>/bin/hkm + ../lib/hkm-kernel
///   dev monorepo:repo/bin/hkm    + repo root
///
/// Plus one case that is NOT a relative probe: a launcher installed in a system
/// bin directory belongs to the .deb, whose kernel is /opt/hkm-kernel by
/// construction. There is no fixed relative path from /usr/bin to /opt, so
/// without this branch the system launcher has no self-location at all and
/// falls through to whatever pin a user-level installer happened to write —
/// which is exactly the hijack described in the header.
fn selfLocateRoot(allocator: std.mem.Allocator, io: Io) !?[]const u8 {
    const dir = std.process.executableDirPathAlloc(io, allocator) catch return null;
    const parent = std.fs.path.dirname(dir) orelse dir;

    const rels = [_][]const []const u8{
        &.{ parent, "Resources", "opt", "hkm-kernel" }, // macOS .app (MacOS→Contents)
        &.{ dir, "hkm-kernel" }, // windows/portable zip
        &.{ parent, "opt", "hkm-kernel" }, // portable
        &.{ parent, "lib", "hkm-kernel" }, // install.sh (bin/ + lib/ pairing)
        &.{parent}, // dev monorepo: repo/bin/hkm → repo root
    };
    for (rels) |parts| {
        const cand = try std.fs.path.join(allocator, parts);
        if (isKernelRoot(io, cand)) return cand;
    }

    if (install_scope.isSystemBinDir(dir) and isKernelRoot(io, install_scope.system_root)) {
        return install_scope.system_root;
    }

    return null;
}

/// Resolve the kernel ROOT directory (the folder holding composer.json, vendor/,
/// projects/). Used by `run`, the registry, and `hkm-config`.
///
/// Same precedence as `resolve` — see the header. Returns null when no kernel
/// can be found.
pub fn resolveHome(allocator: std.mem.Allocator, io: Io, env: *EnvMap) !?[]const u8 {
    return (try resolveHomeDetailed(allocator, io, env)).root;
}

pub const ResolvedHome = struct {
    root: ?[]const u8,
    source: Source,
};

/// `resolveHome` with provenance, so a caller can act on HOW the kernel was
/// found. `hkm-config check` uses it to avoid writing a pin for a kernel that
/// self-location already reaches — writing one is what created the machine-wide
/// pin that redirected the other install in the first place.
pub fn resolveHomeDetailed(allocator: std.mem.Allocator, io: Io, env: *EnvMap) !ResolvedHome {
    const pin = try kernelHomePin(allocator, env);

    if (pin) |p| {
        if (!p.from_config) return .{ .root = p.value, .source = .kernel_home_env };
    }

    if (try selfLocateRoot(allocator, io)) |root| {
        return .{ .root = root, .source = .self_located };
    }

    if (pin) |p| return .{ .root = p.value, .source = .kernel_home_config };

    if (isKernelRoot(io, install_scope.system_root)) {
        return .{ .root = install_scope.system_root, .source = .default };
    }
    return .{ .root = null, .source = .default };
}

/// Resolve the DEVELOPMENT kernel root by walking UP the directory tree from
/// this launcher's own executable until a kernel root (a dir with composer.json)
/// is found. Unlike resolveHome's self-location — which only checks fixed bundle
/// layouts one level up — this handles a launcher run from anywhere inside the
/// monorepo, e.g. `tools/zig-out/bin/hkm` (three levels below the repo root).
/// Returns null when no ancestor is a kernel root.
pub fn resolveDevHome(allocator: std.mem.Allocator, io: Io) !?[]const u8 {
    const dir = std.process.executableDirPathAlloc(io, allocator) catch return null;
    var cur: []const u8 = dir;
    // Bound the climb so we never loop forever on a malformed path.
    var depth: usize = 0;
    while (depth < 32) : (depth += 1) {
        if (isKernelRoot(io, cur)) return cur;
        const parent = std.fs.path.dirname(cur) orelse return null;
        if (std.mem.eql(u8, parent, cur)) return null; // reached filesystem root
        cur = parent;
    }
    return null;
}

pub fn sourceLabel(s: Source) []const u8 {
    return switch (s) {
        .cli_path_env => "HKM_CLI_PATH override",
        .kernel_home_env => "HKM_KERNEL_HOME (exported)",
        .kernel_home_config => "HKM_KERNEL_HOME (config.env fallback)",
        .self_located => "self-located (relative to launcher)",
        .default => "default (/opt/hkm-kernel)",
    };
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test "an exported HKM_KERNEL_HOME still overrides everything" {
    // The escape hatch has to keep working: a real export is this invocation's
    // explicit instruction and must not be demoted along with the config file.
    var arena = std.heap.ArenaAllocator.init(std.testing.allocator);
    defer arena.deinit();
    const a = arena.allocator();

    var env = EnvMap.init(a);
    defer env.deinit();
    try env.put("HKM_KERNEL_HOME", "/somewhere/custom");

    const pin = (try kernelHomePin(a, &env)).?;
    try std.testing.expectEqualStrings("/somewhere/custom", pin.value);
    try std.testing.expect(!pin.from_config);
}

test "a config.env pin is marked as such so it can be demoted" {
    // This is the regression guard for the reported bug: /usr/bin/hkm (v1.3.1)
    // resolving its kernel to ~/.local/share/hkm/kernel because a user-level
    // install had written that pin into the shared config file.
    var arena = std.heap.ArenaAllocator.init(std.testing.allocator);
    defer arena.deinit();
    const a = arena.allocator();

    var env = EnvMap.init(a);
    defer env.deinit();
    try env.put("HKM_KERNEL_HOME", "/home/me/.local/share/hkm/kernel");
    try env.put(userconfig.file_keys_marker, "HKM_KERNEL_HOME,HKM_USERDATA_DIR");

    const pin = (try kernelHomePin(a, &env)).?;
    try std.testing.expect(pin.from_config);
}

test "a pin's trailing slash is trimmed so comparisons hold" {
    var arena = std.heap.ArenaAllocator.init(std.testing.allocator);
    defer arena.deinit();
    const a = arena.allocator();

    var env = EnvMap.init(a);
    defer env.deinit();
    try env.put("HKM_KERNEL_HOME", "  /opt/hkm-kernel/  ");

    try std.testing.expectEqualStrings("/opt/hkm-kernel", (try kernelHomePin(a, &env)).?.value);
}

test "an empty pin is treated as absent, not as the root directory" {
    var arena = std.heap.ArenaAllocator.init(std.testing.allocator);
    defer arena.deinit();
    const a = arena.allocator();

    var env = EnvMap.init(a);
    defer env.deinit();
    try env.put("HKM_KERNEL_HOME", "   ");

    try std.testing.expect((try kernelHomePin(a, &env)) == null);
}

test "every source has a distinct human label" {
    // doctor prints these; two sources sharing a label would make the one
    // diagnostic that explains a hijack unable to distinguish its two causes.
    const sources = [_]Source{ .cli_path_env, .kernel_home_env, .kernel_home_config, .self_located, .default };
    for (sources, 0..) |a, i| {
        for (sources[i + 1 ..]) |b| {
            try std.testing.expect(!std.mem.eql(u8, sourceLabel(a), sourceLabel(b)));
        }
    }
}
