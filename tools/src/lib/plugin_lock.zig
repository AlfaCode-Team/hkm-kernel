//! `plugins.lock.json` — what a project actually has installed.
//!
//! Written at the project root next to proj.json. It records, per plugin, the
//! remote it came from, the exact tag and commit installed, and the kernel
//! version in force at the time.
//!
//! WHY A LOCK FILE
//! ---------------
//! Without one, "the project uses the auth plugin" is the only fact anyone has.
//! Two developers running the same enable command a week apart get different
//! code, a CI box gets a third version, and nothing in the repository records
//! which one a given release was built against. The lock turns an install into
//! something reproducible and reviewable: it is a normal file, it goes in git,
//! and a version change shows up in a diff like any other.
//!
//! The commit is recorded alongside the tag deliberately. A tag can be moved on
//! the remote; the commit is what was actually installed, so the two disagreeing
//! is itself a useful signal.

const std = @import("std");
const util = @import("util.zig");

const Dir = std.Io.Dir;
const Io = std.Io;

pub const file_name = "plugins.lock.json";

/// One installed plugin.
pub const Entry = struct {
    /// Plugin folder name, as it appears under plugins/ (e.g. "SocialAuth").
    name: []const u8,
    /// Git remote it was fetched from.
    remote: []const u8 = "",
    /// Tag installed, e.g. "v1.0.0". Empty when installed from a local copy.
    version: []const u8 = "",
    /// Commit actually checked out. Survives a tag being moved on the remote.
    commit: []const u8 = "",
    /// The plugin's declared kernel constraint at install time.
    kernel: []const u8 = "",
    /// "git" or "local" — how it got there.
    source: []const u8 = "git",
};

pub const Lock = struct {
    /// Kernel version that performed the last write.
    kernel: []const u8 = "",
    entries: std.ArrayList(Entry) = .empty,

    pub fn find(self: *const Lock, name: []const u8) ?Entry {
        for (self.entries.items) |e| {
            if (util.eqlIgnoreCase(e.name, name)) return e;
        }
        return null;
    }

    /// Insert or replace an entry, keeping the list sorted by name so the file
    /// diffs cleanly. An unsorted lock file produces noisy diffs that hide the
    /// one line that actually changed.
    pub fn put(self: *Lock, allocator: std.mem.Allocator, entry: Entry) !void {
        for (self.entries.items, 0..) |e, i| {
            if (util.eqlIgnoreCase(e.name, entry.name)) {
                self.entries.items[i] = entry;
                return;
            }
        }
        try self.entries.append(allocator, entry);
        std.mem.sort(Entry, self.entries.items, {}, struct {
            fn lessThan(_: void, a: Entry, b: Entry) bool {
                return std.mem.order(u8, a.name, b.name) == .lt;
            }
        }.lessThan);
    }

    pub fn remove(self: *Lock, name: []const u8) bool {
        for (self.entries.items, 0..) |e, i| {
            if (util.eqlIgnoreCase(e.name, name)) {
                _ = self.entries.orderedRemove(i);
                return true;
            }
        }
        return false;
    }
};

pub fn path(allocator: std.mem.Allocator, projectRoot: []const u8) ![]const u8 {
    return std.fs.path.join(allocator, &.{ projectRoot, file_name });
}

/// Read the lock file. A missing or unreadable file yields an EMPTY lock rather
/// than an error — a project that has never installed a plugin simply has none,
/// and that is not a failure worth stopping a command for.
pub fn read(allocator: std.mem.Allocator, io: Io, projectRoot: []const u8) !Lock {
    var lock = Lock{};

    const p = try path(allocator, projectRoot);
    const content = Dir.cwd().readFileAlloc(io, p, allocator, .limited(4 * 1024 * 1024)) catch return lock;
    const trimmed = std.mem.trim(u8, content, " \t\r\n");
    if (trimmed.len == 0) return lock;

    const parsed = std.json.parseFromSliceLeaky(std.json.Value, allocator, trimmed, .{}) catch return lock;
    if (parsed != .object) return lock;

    if (parsed.object.get("kernel")) |k| {
        if (k == .string) lock.kernel = k.string;
    }

    const plugins = parsed.object.get("plugins") orelse return lock;
    if (plugins != .object) return lock;

    var it = plugins.object.iterator();
    while (it.next()) |kv| {
        if (kv.value_ptr.* != .object) continue;
        const o = kv.value_ptr.*.object;
        try lock.entries.append(allocator, .{
            .name = kv.key_ptr.*,
            .remote = str(o, "remote"),
            .version = str(o, "version"),
            .commit = str(o, "commit"),
            .kernel = str(o, "kernel"),
            .source = if (str(o, "source").len > 0) str(o, "source") else "git",
        });
    }

    std.mem.sort(Entry, lock.entries.items, {}, struct {
        fn lessThan(_: void, a: Entry, b: Entry) bool {
            return std.mem.order(u8, a.name, b.name) == .lt;
        }
    }.lessThan);

    return lock;
}

fn str(o: std.json.ObjectMap, key: []const u8) []const u8 {
    const v = o.get(key) orelse return "";
    return if (v == .string) v.string else "";
}

/// Serialise the lock to JSON.
///
/// Hand-written rather than via std.json stringify so the field order and
/// indentation are stable. A lock file is read in code review far more often
/// than by a machine, and a formatter that reorders keys between writes makes
/// every diff unreadable.
pub fn render(allocator: std.mem.Allocator, lock: *const Lock, kernelVersion: []const u8) ![]const u8 {
    var out: std.ArrayList(u8) = .empty;

    try out.appendSlice(allocator, "{\n");
    try out.appendSlice(allocator, "    \"kernel\": ");
    try util.appendJsonString(allocator, &out, kernelVersion);
    try out.appendSlice(allocator, ",\n    \"plugins\": {");

    if (lock.entries.items.len == 0) {
        try out.appendSlice(allocator, "}\n}\n");
        return out.items;
    }
    try out.appendSlice(allocator, "\n");

    for (lock.entries.items, 0..) |e, i| {
        try out.appendSlice(allocator, "        ");
        try util.appendJsonString(allocator, &out, e.name);
        try out.appendSlice(allocator, ": {\n");

        try out.appendSlice(allocator, "            \"version\": ");
        try util.appendJsonString(allocator, &out, e.version);
        try out.appendSlice(allocator, ",\n            \"commit\": ");
        try util.appendJsonString(allocator, &out, e.commit);
        try out.appendSlice(allocator, ",\n            \"remote\": ");
        try util.appendJsonString(allocator, &out, e.remote);
        try out.appendSlice(allocator, ",\n            \"kernel\": ");
        try util.appendJsonString(allocator, &out, e.kernel);
        try out.appendSlice(allocator, ",\n            \"source\": ");
        try util.appendJsonString(allocator, &out, e.source);
        try out.appendSlice(allocator, "\n        }");

        if (i + 1 < lock.entries.items.len) try out.appendSlice(allocator, ",");
        try out.appendSlice(allocator, "\n");
    }

    try out.appendSlice(allocator, "    }\n}\n");
    return out.items;
}

/// Write the lock file to the project root.
pub fn write(allocator: std.mem.Allocator, io: Io, projectRoot: []const u8, lock: *const Lock, kernelVersion: []const u8) !void {
    const body = try render(allocator, lock, kernelVersion);
    const p = try path(allocator, projectRoot);
    try Dir.cwd().writeFile(io, .{ .sub_path = p, .data = body });
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test "put replaces an existing entry rather than duplicating it" {
    const a = std.testing.allocator;
    var lock = Lock{};
    defer lock.entries.deinit(a);

    try lock.put(a, .{ .name = "Auth", .version = "v1.0.0" });
    try lock.put(a, .{ .name = "Auth", .version = "v1.1.0" });

    try std.testing.expectEqual(@as(usize, 1), lock.entries.items.len);
    try std.testing.expectEqualStrings("v1.1.0", lock.find("Auth").?.version);
}

test "entries stay sorted so the file diffs cleanly" {
    const a = std.testing.allocator;
    var lock = Lock{};
    defer lock.entries.deinit(a);

    try lock.put(a, .{ .name = "User" });
    try lock.put(a, .{ .name = "Auth" });
    try lock.put(a, .{ .name = "Tenancy" });

    try std.testing.expectEqualStrings("Auth", lock.entries.items[0].name);
    try std.testing.expectEqualStrings("Tenancy", lock.entries.items[1].name);
    try std.testing.expectEqualStrings("User", lock.entries.items[2].name);
}

test "lookup is case-insensitive" {
    const a = std.testing.allocator;
    var lock = Lock{};
    defer lock.entries.deinit(a);

    try lock.put(a, .{ .name = "SocialAuth", .version = "v1.0.0" });
    try std.testing.expect(lock.find("socialauth") != null);
    try std.testing.expect(lock.find("SOCIALAUTH") != null);
}

test "remove takes the entry out" {
    const a = std.testing.allocator;
    var lock = Lock{};
    defer lock.entries.deinit(a);

    try lock.put(a, .{ .name = "Auth" });
    try std.testing.expect(lock.remove("auth"));
    try std.testing.expectEqual(@as(usize, 0), lock.entries.items.len);
    try std.testing.expect(!lock.remove("auth"));
}

test "an empty lock renders as valid, parseable JSON" {
    const a = std.testing.allocator;
    var arena = std.heap.ArenaAllocator.init(a);
    defer arena.deinit();
    const alloc = arena.allocator();

    var lock = Lock{};
    const body = try render(alloc, &lock, "1.0.0");

    // Must parse — an empty project writing a broken lock would break every
    // later read.
    const parsed = try std.json.parseFromSliceLeaky(std.json.Value, alloc, body, .{});
    try std.testing.expect(parsed == .object);
    try std.testing.expectEqualStrings("1.0.0", parsed.object.get("kernel").?.string);
}

test "a rendered lock round-trips through the parser" {
    const a = std.testing.allocator;
    var arena = std.heap.ArenaAllocator.init(a);
    defer arena.deinit();
    const alloc = arena.allocator();

    var lock = Lock{};
    try lock.put(alloc, .{
        .name = "Auth",
        .remote = "https://github.com/AlfaCode-Team/hkm-plugin-auth.git",
        .version = "v1.0.0",
        .commit = "43d42fa",
        .kernel = "^1.0",
        .source = "git",
    });
    try lock.put(alloc, .{ .name = "User", .version = "v2.0.0" });

    const body = try render(alloc, &lock, "1.2.3");
    const parsed = try std.json.parseFromSliceLeaky(std.json.Value, alloc, body, .{});

    const plugins = parsed.object.get("plugins").?.object;
    try std.testing.expectEqual(@as(usize, 2), plugins.count());
    const auth = plugins.get("Auth").?.object;
    try std.testing.expectEqualStrings("v1.0.0", auth.get("version").?.string);
    try std.testing.expectEqualStrings("43d42fa", auth.get("commit").?.string);
    try std.testing.expectEqualStrings("^1.0", auth.get("kernel").?.string);
}

test "a name needing JSON escaping does not corrupt the file" {
    const a = std.testing.allocator;
    var arena = std.heap.ArenaAllocator.init(a);
    defer arena.deinit();
    const alloc = arena.allocator();

    var lock = Lock{};
    try lock.put(alloc, .{ .name = "We\"ird", .remote = "a\\b" });

    const body = try render(alloc, &lock, "1.0.0");
    const parsed = try std.json.parseFromSliceLeaky(std.json.Value, alloc, body, .{});
    try std.testing.expect(parsed.object.get("plugins").?.object.get("We\"ird") != null);
}
