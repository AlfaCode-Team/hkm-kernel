//! Plugin source discovery: where the kernel and project plugin directories
//! live, locating a plugin by name across them, and reading a plugin's
//! `module.json`. The "find the plugins" layer shared by the plugins command.

const std = @import("std");
const registry = @import("registry.zig");
const prompt = @import("prompt.zig");
const util = @import("util.zig");

const Dir = std.Io.Dir;
const Io = std.Io;
const EnvMap = std.process.Environ.Map;

pub const Source = enum { kernel, project };

pub fn sourceLabel(s: Source) []const u8 {
    return switch (s) {
        .kernel => "kernel",
        .project => "project",
    };
}

/// The plugins directories in play for one invocation. The KERNEL dir holds the
/// shared first-party plugins (contributor-protected); the PROJECT dir holds a
/// project's own local plugins. They are distinct only when the project root is
/// not the kernel root itself.
pub const Sources = struct {
    kernel_dir: ?[]const u8 = null, // <kernelRoot>/plugins
    project_dir: ?[]const u8 = null, // <projectRoot>/plugins  (distinct path)
    kernel_root: ?[]const u8 = null,
    /// PWD is inside the kernel monorepo → kernel plugins may be created/deleted.
    in_kernel: bool = false,

    pub fn dirFor(self: Sources, s: Source) ?[]const u8 {
        return switch (s) {
            .kernel => self.kernel_dir,
            .project => self.project_dir,
        };
    }
};

/// A plugin folder found in one source.
pub const Located = struct { name: []const u8, source: Source, dir: []const u8 };

/// Discover the kernel + project plugins dirs. `projectRoot` may be null (e.g.
/// `create`/`delete` run from the kernel monorepo with no active project).
pub fn discoverSources(allocator: std.mem.Allocator, io: Io, env: *EnvMap, projectRoot: ?[]const u8) !Sources {
    var s: Sources = .{};

    const fallback = projectRoot orelse (env.get("PWD") orelse ".");
    if (try kernelPluginsDir(allocator, io, env, fallback)) |kd| {
        s.kernel_dir = kd;
        s.kernel_root = util.parentOf(kd);
    }

    if (projectRoot) |pr| {
        const pd = try std.fmt.allocPrint(allocator, "{s}/plugins", .{util.trimSlash(pr)});
        if (util.dirExists(Dir.cwd(), io, pd)) {
            const same = s.kernel_dir != null and
                std.mem.eql(u8, util.trimSlash(pd), util.trimSlash(s.kernel_dir.?));
            if (!same) s.project_dir = pd;
        }
    }

    if (s.kernel_root) |kr| {
        if (env.get("PWD")) |pwd| {
            if (util.isInside(pwd, kr)) s.in_kernel = true;
        }
        if (projectRoot) |pr| {
            if (std.mem.eql(u8, util.trimSlash(pr), util.trimSlash(kr))) s.in_kernel = true;
        }
    }
    return s;
}

/// Find a plugin by name across the given sources (case-insensitive).
pub fn locate(
    allocator: std.mem.Allocator,
    io: Io,
    sources: Sources,
    arg: []const u8,
    search: []const Source,
    out: *std.ArrayList(Located),
) !void {
    for (search) |src| {
        if (sources.dirFor(src)) |dir| {
            if (try resolvePluginFolder(allocator, io, dir, arg)) |folder| {
                try out.append(allocator, .{ .name = folder, .source = src, .dir = dir });
            }
        }
    }
}

/// Pick one match: the sole entry, or an interactive selection when a plugin of
/// the same name exists in more than one source.
pub fn chooseLocated(allocator: std.mem.Allocator, matches: []const Located) ?Located {
    if (matches.len == 0) return null;
    if (matches.len == 1) return matches[0];

    var labels: std.ArrayList([]const u8) = .empty;
    for (matches) |m| {
        const label = std.fmt.allocPrint(allocator, "{s}  ({s})", .{ sourceLabel(m.source), m.dir }) catch sourceLabel(m.source);
        labels.append(allocator, label) catch {};
    }
    const idx = prompt.select("Found in multiple sources — choose one", labels.items) orelse return null;
    return matches[idx];
}

/// Match `arg` to a real plugin folder under `pluginsDir`, case-insensitively —
/// by folder name first, then by the module.json "name" field.
pub fn resolvePluginFolder(allocator: std.mem.Allocator, io: Io, pluginsDir: []const u8, arg: []const u8) !?[]const u8 {
    var dirs: std.ArrayList([]const u8) = .empty;
    try listPluginDirs(allocator, io, pluginsDir, &dirs);
    for (dirs.items) |d| {
        if (util.eqlIgnoreCase(d, arg)) return d;
    }
    for (dirs.items) |d| {
        if (try readModuleMeta(allocator, io, pluginsDir, d)) |m| {
            if (m.name) |n| {
                if (util.eqlIgnoreCase(n, arg)) return d;
            }
        }
    }
    return null;
}

/// Resolve the kernel's plugins directory.
///   1. HKM_KERNEL_HOME/plugins
///   2. <registry root>/plugins  (parent of projects/projects.json)
///   3. <project root>/plugins   (monorepo / flat layouts)
pub fn kernelPluginsDir(allocator: std.mem.Allocator, io: Io, env: *EnvMap, projectRoot: []const u8) !?[]const u8 {
    if (env.get("HKM_KERNEL_HOME")) |h| {
        if (h.len > 0) {
            const p = try std.fmt.allocPrint(allocator, "{s}/plugins", .{util.trimSlash(h)});
            if (util.dirExists(Dir.cwd(), io, p)) return p;
        }
    }
    if (try registry.resolvePath(allocator, io, env)) |jsonPath| {
        if (util.parentOf(util.parentOf(jsonPath))) |kroot| {
            const p = try std.fmt.allocPrint(allocator, "{s}/plugins", .{kroot});
            if (util.dirExists(Dir.cwd(), io, p)) return p;
        }
    }
    const p = try std.fmt.allocPrint(allocator, "{s}/plugins", .{util.trimSlash(projectRoot)});
    if (util.dirExists(Dir.cwd(), io, p)) return p;
    return null;
}

/// List the immediate (non-dot) subdirectories of a plugins dir.
pub fn listPluginDirs(allocator: std.mem.Allocator, io: Io, pluginsDir: []const u8, out: *std.ArrayList([]const u8)) !void {
    var d = Dir.cwd().openDir(io, pluginsDir, .{ .iterate = true }) catch return;
    defer d.close(io);
    var it = d.iterate();
    while (try it.next(io)) |entry| {
        if (entry.kind != .directory) continue;
        if (entry.name.len > 0 and entry.name[0] == '.') continue;
        try out.append(allocator, try allocator.dupe(u8, entry.name));
    }
}

// ── module.json ────────────────────────────────────────────────────────────────

pub const ModuleMeta = struct {
    name: ?[]const u8 = null,
    solves: ?[]const u8 = null,
    version: ?[]const u8 = null,
    /// "requires" — the domains this module depends on (each a `solves` value of
    /// another module, or a kernel port). Empty when absent.
    requires: []const []const u8 = &.{},
    /// "documentation" — preferred enable-time doc (string, or array joined).
    doc: ?[]const u8 = null,
    /// "description" — fallback doc text.
    description: ?[]const u8 = null,
    /// "kernel" — semver constraint on the kernel this plugin supports
    /// (e.g. "^1.0"). Absent means "no opinion" and never blocks installation;
    /// see plugin_registry.checkKernel.
    kernel: ?[]const u8 = null,
};

/// Read `<pluginsDir>/<name>/module.json`. Returns null when absent/invalid.
pub fn readModuleMeta(allocator: std.mem.Allocator, io: Io, pluginsDir: []const u8, name: []const u8) !?ModuleMeta {
    const path = try std.fmt.allocPrint(allocator, "{s}/{s}/module.json", .{ pluginsDir, name });
    const content = Dir.cwd().readFileAlloc(io, path, allocator, .limited(4 * 1024 * 1024)) catch return null;
    const trimmed = std.mem.trim(u8, content, " \t\r\n");
    if (trimmed.len == 0) return null;

    const parsed = std.json.parseFromSliceLeaky(std.json.Value, allocator, trimmed, .{}) catch return null;
    if (parsed != .object) return null;

    return ModuleMeta{
        .name = strField(parsed.object, "name"),
        .solves = strField(parsed.object, "solves"),
        .version = strField(parsed.object, "version"),
        .requires = try strArrayField(allocator, parsed.object, "requires"),
        .doc = try docField(allocator, parsed.object, "documentation"),
        .description = strField(parsed.object, "description"),
        .kernel = strField(parsed.object, "kernel"),
    };
}

/// Read an array-of-strings field (e.g. "requires"). Returns an empty slice when
/// absent, not an array, or empty. Non-string elements are skipped.
fn strArrayField(allocator: std.mem.Allocator, obj: std.json.ObjectMap, key: []const u8) ![]const []const u8 {
    const v = obj.get(key) orelse return &.{};
    if (v != .array) return &.{};
    var out: std.ArrayList([]const u8) = .empty;
    for (v.array.items) |item| {
        if (item == .string and item.string.len > 0) try out.append(allocator, item.string);
    }
    return out.toOwnedSlice(allocator);
}

fn strField(obj: std.json.ObjectMap, key: []const u8) ?[]const u8 {
    const v = obj.get(key) orelse return null;
    return if (v == .string) v.string else null;
}

/// Read a documentation field that may be a string OR an array of strings
/// (joined with spaces). Returns null when absent or empty.
fn docField(allocator: std.mem.Allocator, obj: std.json.ObjectMap, key: []const u8) !?[]const u8 {
    const v = obj.get(key) orelse return null;
    switch (v) {
        .string => return if (v.string.len > 0) v.string else null,
        .array => {
            var out: std.ArrayList(u8) = .empty;
            for (v.array.items) |item| {
                if (item != .string) continue;
                if (out.items.len > 0) try out.appendSlice(allocator, " ");
                try out.appendSlice(allocator, item.string);
            }
            return if (out.items.len > 0) try out.toOwnedSlice(allocator) else null;
        },
        else => return null,
    }
}
