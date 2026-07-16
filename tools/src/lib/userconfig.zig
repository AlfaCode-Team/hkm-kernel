//! Persistent user config for the hkm launcher: `~/.config/hkm/config.env`.
//!
//! A tiny KEY=VALUE file (e.g. HKM_KERNEL_HOME=/opt/hkm-kernel). The launcher
//! LOADS it into the environment at startup, so values written by `hkm-config`
//! actually take effect for `hkm run`, the registry, and the PHP passthrough.
//! REAL process-environment values always win (a shell export overrides the file).

const std = @import("std");
const Io = std.Io;
const EnvMap = std.process.Environ.Map;
const Dir = std.Io.Dir;

/// Absolute path to the config file, honouring XDG_CONFIG_HOME then HOME.
pub fn path(allocator: std.mem.Allocator, env: *EnvMap) !?[]const u8 {
    if (env.get("XDG_CONFIG_HOME")) |x| {
        if (x.len > 0) return try std.fmt.allocPrint(allocator, "{s}/hkm/config.env", .{x});
    }
    if (env.get("HOME")) |home| {
        if (home.len > 0) return try std.fmt.allocPrint(allocator, "{s}/.config/hkm/config.env", .{home});
    }
    return null;
}

/// Load KEY=VALUE lines into `env`, WITHOUT overriding keys already set in the
/// real environment. Silently no-ops if the file is absent. Best-effort.
pub fn load(allocator: std.mem.Allocator, io: Io, env: *EnvMap) void {
    const cfg = (path(allocator, env) catch return) orelse return;
    const content = Dir.cwd().readFileAlloc(io, cfg, allocator, .limited(64 * 1024)) catch return;
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
    }
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
