//! Kernel project registry — read/merge/write `<kernel>/projects/projects.json`.
//!
//! The registry maps a project name to `{ name, version, path, domains }` so the
//! global kernel's DomainResolver can dispatch an incoming Host to an external
//! standalone project by its absolute path. Both `hkm new` and `hkm update`
//! register/refresh a project through this module.

const std = @import("std");
const util = @import("util.zig");
const kernel = @import("kernel.zig");

const Dir = std.Io.Dir;
const Io = std.Io;
const EnvMap = std.process.Environ.Map;

/// One registry entry (also the in-memory shape we read existing ones into).
pub const Entry = struct {
    name: []const u8,
    version: []const u8,
    path: []const u8, // absolute project root
    domains: []const []const u8,
};

/// Resolve the absolute path to projects.json, or null if it cannot be located.
///
/// Order:
///   1. PSP_PROJECTS_DIR        — explicit dir holding projects.json
///   2. HKM_KERNEL_HOME/projects
///   3. walk up from PWD for an existing `projects/projects.json` (dev: the
///      kernel monorepo root)
pub fn resolvePath(allocator: std.mem.Allocator, io: Io, env: *EnvMap) !?[]const u8 {
    // HKM_USERDATA_DIR holds the persistent registry (projects.json + platform.json)
    // OUTSIDE the kernel install, so a kernel update never overwrites it.
    if (env.get("HKM_USERDATA_DIR")) |d| {
        if (d.len > 0) return try std.fmt.allocPrint(allocator, "{s}/projects.json", .{trimSlash(d)});
    }
    if (env.get("PSP_PROJECTS_DIR")) |d| {
        if (d.len > 0) return try std.fmt.allocPrint(allocator, "{s}/projects.json", .{trimSlash(d)});
    }
    if (env.get("HKM_KERNEL_HOME")) |h| {
        if (h.len > 0) return try std.fmt.allocPrint(allocator, "{s}/projects/projects.json", .{trimSlash(h)});
    }
    // Self-locate the kernel relative to THIS executable (installed .deb/.app/zip
    // or the dev monorepo). This is what makes `hkm run --pick` work on a packaged
    // install with no env vars set — the registry lives at <kernel>/projects/.
    if (try kernel.resolveHome(allocator, io, env)) |home| {
        const p = try std.fmt.allocPrint(allocator, "{s}/projects/projects.json", .{home});
        if (Dir.cwd().access(io, p, .{})) |_| return p else |_| {}
    }
    if (env.get("PWD")) |pwd| {
        if (pwd.len > 0) return findUpwards(allocator, io, pwd);
    }
    return null;
}

/// Walk up the directory chain looking for an existing `projects/projects.json`.
fn findUpwards(allocator: std.mem.Allocator, io: Io, start: []const u8) !?[]const u8 {
    const cwd = Dir.cwd();
    var dir = trimSlash(start);
    while (dir.len > 0) {
        const candidate = try std.fmt.allocPrint(allocator, "{s}/projects/projects.json", .{dir});
        cwd.access(io, candidate, .{}) catch {
            dir = parentDir(dir) orelse break;
            continue;
        };
        return candidate;
    }
    return null;
}

/// Look up an entry by project name in the registry at `jsonPath`.
/// Returns null if the file is missing/empty or no entry matches.
pub fn find(allocator: std.mem.Allocator, io: Io, jsonPath: []const u8, name: []const u8) !?Entry {
    const cwd = Dir.cwd();
    const content = cwd.readFileAlloc(io, jsonPath, allocator, .limited(8 * 1024 * 1024)) catch return null;
    const trimmed = std.mem.trim(u8, content, " \t\r\n");
    if (trimmed.len == 0) return null;

    var entries: std.ArrayList(Entry) = .empty;
    try parseInto(allocator, trimmed, &entries);
    for (entries.items) |e| {
        if (std.mem.eql(u8, e.name, name)) return e;
    }
    return null;
}

/// Read every registered project entry from `jsonPath`.
/// Returns an empty slice if the file is missing or empty.
pub fn list(allocator: std.mem.Allocator, io: Io, jsonPath: []const u8) ![]const Entry {
    const cwd = Dir.cwd();
    const content = cwd.readFileAlloc(io, jsonPath, allocator, .limited(8 * 1024 * 1024)) catch return &.{};
    const trimmed = std.mem.trim(u8, content, " \t\r\n");
    if (trimmed.len == 0) return &.{};

    var entries: std.ArrayList(Entry) = .empty;
    try parseInto(allocator, trimmed, &entries);
    return entries.toOwnedSlice(allocator);
}

/// Insert or replace `entry` in the registry at `jsonPath`, preserving the other
/// entries. Writes pretty 4-space JSON.
pub fn upsert(allocator: std.mem.Allocator, io: Io, jsonPath: []const u8, entry: Entry) !void {
    const cwd = Dir.cwd();

    // Ensure the parent directory exists (first-ever registration).
    if (parentDir(jsonPath)) |parent| {
        try cwd.createDirPath(io, parent);
    }

    var entries: std.ArrayList(Entry) = .empty;

    // Read + parse existing entries, if the file is present and non-empty.
    if (cwd.readFileAlloc(io, jsonPath, allocator, .limited(8 * 1024 * 1024))) |content| {
        const trimmed = std.mem.trim(u8, content, " \t\r\n");
        if (trimmed.len > 0) try parseInto(allocator, trimmed, &entries);
    } else |_| {
        // missing/unreadable — start fresh
    }

    // Replace in place if the name already exists, else append.
    var replaced = false;
    for (entries.items) |*e| {
        if (std.mem.eql(u8, e.name, entry.name)) {
            e.* = entry;
            replaced = true;
            break;
        }
    }
    if (!replaced) try entries.append(allocator, entry);

    const out = try render(allocator, entries.items);
    try cwd.writeFile(io, .{ .sub_path = jsonPath, .data = out });
}

/// Parse the registry JSON object into `entries`.
fn parseInto(allocator: std.mem.Allocator, content: []const u8, entries: *std.ArrayList(Entry)) !void {
    const parsed = std.json.parseFromSliceLeaky(std.json.Value, allocator, content, .{}) catch return;
    if (parsed != .object) return;

    for (parsed.object.keys()) |key| {
        const val = parsed.object.get(key) orelse continue;
        if (val != .object) continue;
        const obj = val.object;

        const name = strField(obj, "name") orelse key;
        const version = strField(obj, "version") orelse "1.0.0";
        const path = strField(obj, "path") orelse continue;

        var domains: std.ArrayList([]const u8) = .empty;
        if (obj.get("domains")) |d| {
            if (d == .array) {
                for (d.array.items) |item| {
                    if (item == .string) try domains.append(allocator, item.string);
                }
            }
        }

        try entries.append(allocator, .{
            .name = name,
            .version = version,
            .path = path,
            .domains = try domains.toOwnedSlice(allocator),
        });
    }
}

fn strField(obj: std.json.ObjectMap, key: []const u8) ?[]const u8 {
    const v = obj.get(key) orelse return null;
    return if (v == .string) v.string else null;
}

/// Serialise entries to pretty 4-space JSON matching the registry's style.
fn render(allocator: std.mem.Allocator, entries: []const Entry) ![]const u8 {
    var out: std.ArrayList(u8) = .empty;
    const w = &out;

    if (entries.len == 0) {
        try w.appendSlice(allocator, "{}\n");
        return out.toOwnedSlice(allocator);
    }

    try w.appendSlice(allocator, "{\n");
    for (entries, 0..) |e, i| {
        try w.appendSlice(allocator, "    \"");
        try util.appendJsonEscaped(allocator, w, e.name);
        try w.appendSlice(allocator, "\": {\n");

        try appendKv(allocator, w, "name", e.name, true);
        try appendKv(allocator, w, "version", e.version, true);
        try appendKv(allocator, w, "path", e.path, true);

        // domains array
        try w.appendSlice(allocator, "        \"domains\": [");
        if (e.domains.len == 0) {
            try w.appendSlice(allocator, "]\n");
        } else {
            try w.appendSlice(allocator, "\n");
            for (e.domains, 0..) |d, di| {
                try w.appendSlice(allocator, "            \"");
                try util.appendJsonEscaped(allocator, w, d);
                try w.appendSlice(allocator, "\"");
                if (di + 1 < e.domains.len) try w.appendSlice(allocator, ",");
                try w.appendSlice(allocator, "\n");
            }
            try w.appendSlice(allocator, "        ]\n");
        }

        try w.appendSlice(allocator, "    }");
        if (i + 1 < entries.len) try w.appendSlice(allocator, ",");
        try w.appendSlice(allocator, "\n");
    }
    try w.appendSlice(allocator, "}\n");

    return out.toOwnedSlice(allocator);
}

fn appendKv(allocator: std.mem.Allocator, w: *std.ArrayList(u8), key: []const u8, value: []const u8, comptime trailingComma: bool) !void {
    try w.appendSlice(allocator, "        \"");
    try w.appendSlice(allocator, key);
    try w.appendSlice(allocator, "\": \"");
    try util.appendJsonEscaped(allocator, w, value);
    try w.appendSlice(allocator, if (trailingComma) "\",\n" else "\"\n");
}

fn trimSlash(s: []const u8) []const u8 {
    var end = s.len;
    while (end > 1 and (s[end - 1] == '/' or s[end - 1] == '\\')) end -= 1;
    return s[0..end];
}

fn parentDir(path: []const u8) ?[]const u8 {
    var end = path.len;
    while (end > 0 and path[end - 1] != '/' and path[end - 1] != '\\') end -= 1;
    if (end <= 1) return null;
    return path[0 .. end - 1];
}
