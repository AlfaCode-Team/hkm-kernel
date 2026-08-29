//! Persistent user config for the hkm launcher: `~/.config/hkm/config.env`.
//!
//! A tiny KEY=VALUE file (e.g. HKM_KERNEL_HOME=/opt/hkm-kernel). The launcher
//! LOADS it into the environment at startup, so values written by `hkm-config`
//! actually take effect for `hkm run`, the registry, and the PHP passthrough.
//! REAL process-environment values always win (a shell export overrides the file).

const std = @import("std");
const util = @import("util.zig");
const Io = std.Io;
const EnvMap = std.process.Environ.Map;
const Dir = std.Io.Dir;

/// Absolute path to the config file, honouring XDG_CONFIG_HOME then HOME.
///
/// Under `sudo`, HOME is root's (/root) but the config was written by the
/// invoking user — so `sudo hkm --dev` would otherwise lose HKM_DEV_HOME and
/// everything else in config.env. When SUDO_USER is set we resolve the config in
/// that user's home instead, so a privileged run (e.g. editing /etc/hosts) still
/// sees the same configuration as a normal run.
pub fn path(allocator: std.mem.Allocator, env: *EnvMap) !?[]const u8 {
    if (env.get("SUDO_USER")) |user| {
        if (util.sudoUserHome(allocator, user)) |h| {
            return try std.fmt.allocPrint(allocator, "{s}/.config/hkm/config.env", .{h});
        }
    }
    if (env.get("XDG_CONFIG_HOME")) |x| {
        if (x.len > 0) return try std.fmt.allocPrint(allocator, "{s}/hkm/config.env", .{x});
    }
    if (env.get("HOME")) |home| {
        if (home.len > 0) return try std.fmt.allocPrint(allocator, "{s}/.config/hkm/config.env", .{home});
    }
    return null;
}

/// Sentinel variable recording which keys in `env` came from the CONFIG FILE
/// rather than from the real process environment.
///
/// The distinction matters because the two carry different authority. A real
/// `export HKM_KERNEL_HOME=…` is this invocation's explicit instruction. A value
/// in config.env is a machine-wide default that BOTH the system launcher
/// (/usr/bin/hkm) and a user launcher (~/.local/bin/hkm) read — so treating it
/// as an override let whichever installer wrote it last silently redirect the
/// other install's kernel. Resolution (lib/kernel.zig) demotes a file-sourced
/// pin below self-location for exactly that reason, and needs this to tell them
/// apart after load() has flattened both into one map.
pub const file_keys_marker = "HKM_CONFIG_FILE_KEYS";

/// Load KEY=VALUE lines into `env`, WITHOUT overriding keys already set in the
/// real environment. Silently no-ops if the file is absent. Best-effort.
///
/// Also records the loaded keys under `file_keys_marker`, so a later reader can
/// ask whether a value was the operator's explicit export or just the config
/// file's default. See `isFileSourced`.
pub fn load(allocator: std.mem.Allocator, io: Io, env: *EnvMap) void {
    const cfg = (path(allocator, env) catch return) orelse return;
    const content = Dir.cwd().readFileAlloc(io, cfg, allocator, .limited(64 * 1024)) catch return;

    var sourced: std.ArrayList(u8) = .empty;

    var lines = std.mem.splitScalar(u8, content, '\n');
    while (lines.next()) |raw| {
        const line = std.mem.trim(u8, raw, " \t\r");
        if (line.len == 0 or line[0] == '#') continue;
        const eq = std.mem.indexOfScalar(u8, line, '=') orelse continue;
        const key = std.mem.trim(u8, line[0..eq], " \t");
        const val = std.mem.trim(u8, line[eq + 1 ..], " \t");
        if (key.len == 0) continue;
        // Process env wins — only fill in what isn't already set.
        if (env.get(key) != null) continue;
        env.put(key, val) catch continue;

        if (sourced.items.len > 0) sourced.append(allocator, ',') catch {};
        sourced.appendSlice(allocator, key) catch {};
    }

    if (sourced.items.len > 0) env.put(file_keys_marker, sourced.items) catch {};
}

/// Did `key`'s current value in `env` come from the config file?
///
/// False for a key the operator exported themselves (load() skips those), and
/// false in any process that never called load().
pub fn isFileSourced(env: *EnvMap, key: []const u8) bool {
    const list = env.get(file_keys_marker) orelse return false;
    var it = std.mem.splitScalar(u8, list, ',');
    while (it.next()) |k| {
        if (std.mem.eql(u8, k, key)) return true;
    }
    return false;
}

/// Read a single key from the config file (not the environment). Null if absent.
pub fn get(allocator: std.mem.Allocator, io: Io, env: *EnvMap, key: []const u8) !?[]const u8 {
    const cfg = (try path(allocator, env)) orelse return null;
    const content = Dir.cwd().readFileAlloc(io, cfg, allocator, .limited(64 * 1024)) catch return null;
    var lines = std.mem.splitScalar(u8, content, '\n');
    while (lines.next()) |raw| {
        const line = std.mem.trim(u8, raw, " \t\r");
        const eq = std.mem.indexOfScalar(u8, line, '=') orelse continue;
        if (std.mem.eql(u8, std.mem.trim(u8, line[0..eq], " \t"), key)) {
            return try allocator.dupe(u8, std.mem.trim(u8, line[eq + 1 ..], " \t"));
        }
    }
    return null;
}

/// Set (insert or replace) KEY=VALUE in the config file, preserving other keys.
pub fn set(allocator: std.mem.Allocator, io: Io, env: *EnvMap, key: []const u8, value: []const u8) !void {
    const cfg = (try path(allocator, env)) orelse return error.MissingHome;
    if (std.fs.path.dirname(cfg)) |dir| try Dir.cwd().createDirPath(io, dir);

    var out: std.ArrayList(u8) = .empty;
    var replaced = false;

    if (Dir.cwd().readFileAlloc(io, cfg, allocator, .limited(64 * 1024))) |content| {
        var lines = std.mem.splitScalar(u8, content, '\n');
        while (lines.next()) |raw| {
            const line = std.mem.trim(u8, raw, "\r");
            if (line.len == 0) continue;
            const eq = std.mem.indexOfScalar(u8, line, '=');
            if (eq != null and std.mem.eql(u8, std.mem.trim(u8, line[0..eq.?], " \t"), key)) {
                try out.appendSlice(allocator, key);
                try out.append(allocator, '=');
                try out.appendSlice(allocator, value);
                try out.append(allocator, '\n');
                replaced = true;
            } else {
                try out.appendSlice(allocator, line);
                try out.append(allocator, '\n');
            }
        }
    } else |_| {}

    if (!replaced) {
        try out.appendSlice(allocator, key);
        try out.append(allocator, '=');
        try out.appendSlice(allocator, value);
        try out.append(allocator, '\n');
    }
    try Dir.cwd().writeFile(io, .{ .sub_path = cfg, .data = out.items });
    // Owner-only: this file may later hold overrides an operator considers private.
    @import("util.zig").chmod600(io, cfg);
}

/// Remove KEY from the config file, preserving every other line. Returns true
/// when a line was actually removed.
///
/// The counterpart to `set`, and needed for one specific repair: a stale
/// `HKM_KERNEL_HOME` pointing at an install that no longer exists (or at the
/// OTHER scope's kernel). Repointing it perpetuates a machine-wide pin that
/// both launchers read; deleting it hands resolution back to self-location,
/// where each launcher finds its own kernel and neither can affect the other.
pub fn unset(allocator: std.mem.Allocator, io: Io, env: *EnvMap, key: []const u8) !bool {
    const cfg = (try path(allocator, env)) orelse return error.MissingHome;

    const content = Dir.cwd().readFileAlloc(io, cfg, allocator, .limited(64 * 1024)) catch return false;

    var out: std.ArrayList(u8) = .empty;
    var removed = false;
    var lines = std.mem.splitScalar(u8, content, '\n');
    while (lines.next()) |raw| {
        const line = std.mem.trim(u8, raw, "\r");
        if (line.len == 0) continue;
        const eq = std.mem.indexOfScalar(u8, line, '=');
        if (eq != null and std.mem.eql(u8, std.mem.trim(u8, line[0..eq.?], " \t"), key)) {
            removed = true;
            continue;
        }
        try out.appendSlice(allocator, line);
        try out.append(allocator, '\n');
    }
    if (!removed) return false;

    try Dir.cwd().writeFile(io, .{ .sub_path = cfg, .data = out.items });
    @import("util.zig").chmod600(io, cfg);
    return true;
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test "isFileSourced separates a config default from an explicit export" {
    // The whole point: a value the operator exported is this invocation's
    // instruction, while a value from config.env is a machine-wide default that
    // BOTH launchers read. Conflating them let a user install's pin redirect the
    // system launcher's kernel.
    var arena = std.heap.ArenaAllocator.init(std.testing.allocator);
    defer arena.deinit();
    const a = arena.allocator();

    var env = EnvMap.init(a);
    defer env.deinit();

    try env.put(file_keys_marker, "HKM_KERNEL_HOME,HKM_USERDATA_DIR");

    try std.testing.expect(isFileSourced(&env, "HKM_KERNEL_HOME"));
    try std.testing.expect(isFileSourced(&env, "HKM_USERDATA_DIR"));
    try std.testing.expect(!isFileSourced(&env, "HKM_DEV_HOME"));
    // A prefix of a listed key must not match — splitting on ',' is what makes
    // that true, and a substring search would not.
    try std.testing.expect(!isFileSourced(&env, "HKM_KERNEL"));
}

test "isFileSourced is false when nothing was loaded" {
    var arena = std.heap.ArenaAllocator.init(std.testing.allocator);
    defer arena.deinit();
    const a = arena.allocator();

    var env = EnvMap.init(a);
    defer env.deinit();
    try env.put("HKM_KERNEL_HOME", "/opt/hkm-kernel");

    // No marker → the value can only have come from the real environment.
    try std.testing.expect(!isFileSourced(&env, "HKM_KERNEL_HOME"));
}
