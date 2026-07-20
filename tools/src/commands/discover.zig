//! `hkm discover [root]` — find PhpServicePlatform projects on disk and register
//! them in the kernel registry.
//!
//! Where `hkm update <path>` registers ONE known project, `discover` walks a
//! directory tree, finds every folder holding a `proj.json`, and upserts each
//! into `<kernel>/projects/projects.json` (name, version, ABSOLUTE path,
//! domains — read straight from the project's own proj.json). Use it to adopt
//! projects that were scaffolded with `--no-register`, moved, or cloned from git.
//!
//!   hkm discover                 # scan the current directory tree
//!   hkm discover ~/Documents     # scan a specific root
//!   hkm discover --dry-run       # list what WOULD be registered, change nothing
//!   hkm discover --depth=3       # limit how deep the scan descends (default 4)

const std = @import("std");
const registry = @import("../lib/registry.zig");
const prompt = @import("../lib/prompt.zig");
const util = @import("../lib/util.zig");

const Dir = std.Io.Dir;
const Io = std.Io;
const EnvMap = std.process.Environ.Map;

/// Directories that never hold a project root — skipped so a scan stays fast and
/// never wanders into dependencies or runtime artifacts.
const skip_dirs = [_][]const u8{
    "vendor", "node_modules", "var", ".git", "dist", "zig-out", ".zig-cache",
};

const Options = struct {
    /// Root directory to scan (default: current directory).
    root: []const u8,
    /// Max directory depth to descend from `root` (default 4).
    depth: usize = 4,
    /// --dry-run: report matches without touching the registry.
    dry_run: bool = false,
};

fn parse(args: []const []const u8) Options {
    var root: []const u8 = ".";
    var depth: usize = 4;
    var dry_run = false;

    var i: usize = 2;
    while (i < args.len) : (i += 1) {
        const a = args[i];
        if (std.mem.eql(u8, a, "--dry-run") or std.mem.eql(u8, a, "-n")) {
            dry_run = true;
        } else if (std.mem.startsWith(u8, a, "--depth=")) {
            depth = std.fmt.parseInt(usize, a["--depth=".len..], 10) catch depth;
        } else if (std.mem.startsWith(u8, a, "--")) {
            continue;
        } else {
            root = a;
        }
    }
    return .{ .root = root, .depth = depth, .dry_run = dry_run };
}

/// One discovered project, resolved from its proj.json.
const Found = struct {
    name: []const u8,
    version: []const u8,
    path: []const u8, // absolute
    domains: []const []const u8,
};

pub fn run(allocator: std.mem.Allocator, io: Io, env: *EnvMap, args: []const []const u8) !u8 {
    const opts = parse(args);

    prompt.intro(try std.fmt.allocPrint(allocator, "Discover projects under '{s}'", .{opts.root}));

    const jsonPath = (try registry.resolvePath(allocator, io, env)) orelse {
        prompt.err("Kernel registry not found. Set PSP_PROJECTS_DIR or HKM_KERNEL_HOME.");
        return 1;
    };

    // Walk the tree collecting every folder with a proj.json.
    var found: std.ArrayList(Found) = .empty;
    try scan(allocator, io, env, opts.root, opts.depth, &found);

    if (found.items.len == 0) {
        prompt.muted("No projects found (looked for folders containing proj.json).");
        prompt.outro(try std.fmt.allocPrint(allocator, "registry: {s}", .{jsonPath}));
        return 0;
    }

    var registered: usize = 0;
    for (found.items) |f| {
        // Was this name already known, and pointing at the same path?
        const existing = try registry.find(allocator, io, jsonPath, f.name);
        const status: []const u8 = if (existing) |e|
            (if (std.mem.eql(u8, e.path, f.path)) "up-to-date" else "moved")
        else
            "new";

        prompt.item(f.name, f.path);
        prompt.muted(try std.fmt.allocPrint(allocator, "    {s}  ·  {s}", .{
            status,
            if (f.domains.len > 0) try util.joinList(allocator, f.domains) else "(no domains)",
        }));

        if (!opts.dry_run) {
            try registry.upsert(allocator, io, jsonPath, .{
                .name = f.name,
                .version = f.version,
                .path = f.path,
                .domains = f.domains,
            });
            registered += 1;
        }
    }

    if (opts.dry_run) {
        prompt.note("--dry-run: registry left unchanged.");
        prompt.outro(try std.fmt.allocPrint(allocator, "{d} project(s) found  ·  {s}", .{ found.items.len, jsonPath }));
    } else {
        prompt.ok(try std.fmt.allocPrint(allocator, "Registered {d} project(s)", .{registered}));
        prompt.outro(try std.fmt.allocPrint(allocator, "{s}", .{jsonPath}));
    }
    return 0;
}

/// Recursively look for `proj.json` under `dir`. When a folder IS a project
/// (has proj.json), record it and STOP descending into it — a project root is
/// never nested inside another. `depth` counts remaining levels to descend.
fn scan(allocator: std.mem.Allocator, io: Io, env: *EnvMap, dir: []const u8, depth: usize, out: *std.ArrayList(Found)) !void {
    // Is THIS directory a project root?
    if (try readProject(allocator, io, env, dir)) |f| {
        try out.append(allocator, f);
        return; // do not descend into a project
    }

    if (depth == 0) return;

    var d = Dir.cwd().openDir(io, dir, .{ .iterate = true }) catch return;
    defer d.close(io);
    var it = d.iterate();
    while (try it.next(io)) |entry| {
        if (entry.kind != .directory) continue;
        if (entry.name.len > 0 and entry.name[0] == '.') continue;
        if (util.contains(&skip_dirs, entry.name)) continue;
        const sub = try util.join(allocator, dir, entry.name);
        try scan(allocator, io, env, sub, depth - 1, out);
    }
}

/// Read `<dir>/proj.json` into a Found, or null when absent/invalid/unnamed.
fn readProject(allocator: std.mem.Allocator, io: Io, env: *EnvMap, dir: []const u8) !?Found {
    const projPath = try util.join(allocator, dir, "proj.json");
    const content = Dir.cwd().readFileAlloc(io, projPath, allocator, .limited(4 * 1024 * 1024)) catch return null;

    const parsed = std.json.parseFromSliceLeaky(std.json.Value, allocator, content, .{}) catch return null;
    if (parsed != .object) return null;

    const name = strField(parsed.object, "name") orelse return null;

    var domains: std.ArrayList([]const u8) = .empty;
    if (parsed.object.get("domains")) |d| {
        if (d == .array) {
            for (d.array.items) |item| {
                if (item == .string) try domains.append(allocator, item.string);
            }
        }
    }

    return Found{
        .name = name,
        .version = strField(parsed.object, "version") orelse "1.0.0",
        .path = try util.absPath(allocator, env, dir),
        .domains = try domains.toOwnedSlice(allocator),
    };
}

fn strField(obj: std.json.ObjectMap, key: []const u8) ?[]const u8 {
    const v = obj.get(key) orelse return null;
    return if (v == .string) v.string else null;
}
