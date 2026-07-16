//! Shared path / string / filesystem helpers used across the hkm commands.
//! Keep these dependency-light and command-agnostic.

const std = @import("std");

const Dir = std.Io.Dir;
const Io = std.Io;
const EnvMap = std.process.Environ.Map;

/// Restrict a file to owner-only (chmod 0600). No-op on Windows. Best-effort —
/// used for secret-bearing files like a project's .env.
pub fn chmod600(io: Io, path: []const u8) void {
    if (@import("builtin").os.tag == .windows) return;
    const f = Dir.cwd().openFile(io, path, .{}) catch return;
    defer f.close(io);
    f.setPermissions(io, @enumFromInt(0o600)) catch {};
}

// ── path strings ────────────────────────────────────────────────────────────

/// Trim trailing path separators (keeps a lone "/").
pub fn trimSlash(path: []const u8) []const u8 {
    var p = path;
    while (p.len > 1 and (p[p.len - 1] == '/' or p[p.len - 1] == '\\')) p = p[0 .. p.len - 1];
    return p;
}

/// Drop leading "./" (or ".\\") segments so joined absolute paths stay clean.
pub fn stripDotSlash(path: []const u8) []const u8 {
    var p = path;
    while (p.len >= 2 and p[0] == '.' and (p[1] == '/' or p[1] == '\\')) p = p[2..];
    return p;
}

/// Parent directory of a path (null when there is no separator).
pub fn parentOf(path: ?[]const u8) ?[]const u8 {
    const p = path orelse return null;
    const t = trimSlash(p);
    const idx = std.mem.lastIndexOfScalar(u8, t, '/') orelse return null;
    if (idx == 0) return "/";
    return t[0..idx];
}

/// Join `base` and `sub` with a single separator.
pub fn join(allocator: std.mem.Allocator, base: []const u8, sub: []const u8) ![]const u8 {
    return std.fmt.allocPrint(allocator, "{s}/{s}", .{ base, sub });
}

/// Resolve a possibly-relative path to an absolute one via PWD. An already
/// absolute path is returned as-is; "." and "" resolve to PWD itself.
pub fn absPath(allocator: std.mem.Allocator, env: *EnvMap, raw: []const u8) ![]const u8 {
    const path = stripDotSlash(raw);
    if (path.len > 0 and (path[0] == '/' or path[0] == '\\')) return path;
    const pwd = env.get("PWD") orelse return path;
    if (pwd.len == 0) return path;
    if (path.len == 0 or std.mem.eql(u8, path, ".")) return pwd;
    return std.fmt.allocPrint(allocator, "{s}/{s}", .{ pwd, path });
}

// ── filesystem probes ─────────────────────────────────────────────────────────

/// True if `path` is accessible (relative to the process cwd).
pub fn fileExists(io: Io, path: []const u8) bool {
    Dir.cwd().access(io, path, .{}) catch return false;
    return true;
}

/// True if `path` is an openable directory under `dir`.
pub fn dirExists(dir: Dir, io: Io, path: []const u8) bool {
    var d = dir.openDir(io, path, .{}) catch return false;
    d.close(io);
    return true;
}

/// True if `path` is missing or contains no entries.
pub fn dirIsEmpty(dir: Dir, io: Io, path: []const u8) bool {
    var d = dir.openDir(io, path, .{ .iterate = true }) catch return true;
    defer d.close(io);
    var it = d.iterate();
    const first = it.next(io) catch return true;
    return first == null;
}

// ── string lists ──────────────────────────────────────────────────────────────

/// Join a string list with ", " for display.
pub fn joinList(allocator: std.mem.Allocator, items: []const []const u8) ![]const u8 {
    var out: std.ArrayList(u8) = .empty;
    for (items, 0..) |it, i| {
        if (i > 0) try out.appendSlice(allocator, ", ");
        try out.appendSlice(allocator, it);
    }
    return out.toOwnedSlice(allocator);
}

/// Shallow copy of a string list into a growable list.
pub fn dupeList(allocator: std.mem.Allocator, items: []const []const u8) !std.ArrayList([]const u8) {
    var out: std.ArrayList([]const u8) = .empty;
    try out.appendSlice(allocator, items);
    return out;
}

/// True if `needle` is present in `haystack` (string equality).
pub fn contains(haystack: []const []const u8, needle: []const u8) bool {
    for (haystack) |h| {
        if (std.mem.eql(u8, h, needle)) return true;
    }
    return false;
}

// ── case / naming helpers ─────────────────────────────────────────────────────

/// PascalCase a name: `billing-engine` / `billing_engine` → `BillingEngine`.
pub fn studly(allocator: std.mem.Allocator, name: []const u8) ![]const u8 {
    var out: std.ArrayList(u8) = .empty;
    var upper_next = true;
    for (name) |c| {
        if (c == '-' or c == '_' or c == ' ') {
            upper_next = true;
            continue;
        }
        if (upper_next) {
            try out.append(allocator, std.ascii.toUpper(c));
            upper_next = false;
        } else {
            try out.append(allocator, c);
        }
    }
    return out.toOwnedSlice(allocator);
}

/// Lowercase a string into freshly allocated memory.
pub fn lower(allocator: std.mem.Allocator, s: []const u8) ![]const u8 {
    const out = try allocator.alloc(u8, s.len);
    for (s, 0..) |c, idx| out[idx] = std.ascii.toLower(c);
    return out;
}

/// Case-insensitive ASCII string equality.
pub fn eqlIgnoreCase(a: []const u8, b: []const u8) bool {
    if (a.len != b.len) return false;
    for (a, b) |x, y| {
        if (std.ascii.toLower(x) != std.ascii.toLower(y)) return false;
    }
    return true;
}

/// True when `path` is `root` or lives beneath it (path-segment aware).
pub fn isInside(path: []const u8, root: []const u8) bool {
    const p = trimSlash(path);
    const r = trimSlash(root);
    if (std.mem.eql(u8, p, r)) return true;
    return p.len > r.len and std.mem.startsWith(u8, p, r) and p[r.len] == '/';
}

// ── time ──────────────────────────────────────────────────────────────────────

/// UTC timestamp prefix `YYYY_MM_DD_HHMMSS` for ordered filenames (migrations).
pub fn timestampPrefix(allocator: std.mem.Allocator) ![]const u8 {
    var ts: std.os.linux.timespec = undefined;
    _ = std.os.linux.clock_gettime(.REALTIME, &ts);
    const secs: u64 = @intCast(@max(ts.sec, 0));
    const es = std.time.epoch.EpochSeconds{ .secs = secs };
    const day = es.getEpochDay();
    const yd = day.calculateYearDay();
    const md = yd.calculateMonthDay();
    const ds = es.getDaySeconds();
    return std.fmt.allocPrint(allocator, "{d:0>4}_{d:0>2}_{d:0>2}_{d:0>2}{d:0>2}{d:0>2}", .{
        yd.year,
        md.month.numeric(),
        @as(u32, md.day_index) + 1,
        ds.getHoursIntoDay(),
        ds.getMinutesIntoHour(),
        ds.getSecondsIntoMinute(),
    });
}

// ── JSON ──────────────────────────────────────────────────────────────────────

/// Append a JSON string BODY (no surrounding quotes), escaping backslash and
/// double-quote — the only chars that appear in the names/paths/domains these
/// tools serialise.
pub fn appendJsonEscaped(allocator: std.mem.Allocator, out: *std.ArrayList(u8), s: []const u8) !void {
    for (s) |c| switch (c) {
        '\\' => try out.appendSlice(allocator, "\\\\"),
        '"' => try out.appendSlice(allocator, "\\\""),
        else => try out.append(allocator, c),
    };
}

/// Append a JSON-quoted, escaped string (`"…"`) to `out`.
pub fn appendJsonString(allocator: std.mem.Allocator, out: *std.ArrayList(u8), s: []const u8) !void {
    try out.append(allocator, '"');
    try appendJsonEscaped(allocator, out, s);
    try out.append(allocator, '"');
}

