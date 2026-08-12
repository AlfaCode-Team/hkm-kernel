//! `hkm update <path>` — refresh a project's entry in the kernel registry.
//!
//! Reads the project's proj.json (name, version, domains) and writes/updates the
//! matching entry in `<kernel>/projects/projects.json` with the project's
//! ABSOLUTE path. Use it after moving a project, changing its domains, or to
//! register an existing project that was scaffolded with --no-register.

const std = @import("std");
const registry = @import("../lib/registry.zig");
const prompt = @import("../lib/prompt.zig");
const util = @import("../lib/util.zig");

const Dir = std.Io.Dir;
const Io = std.Io;
const EnvMap = std.process.Environ.Map;

const Options = struct {
    /// A project PATH (a dir holding proj.json) OR a registered project NAME.
    target: []const u8,
    /// --domains: REPLACE the whole list. null = no overwrite.
    set: ?[]const []const u8 = null,
    /// --add-domains: ADD to the existing list (deduped). null = none.
    add: ?[]const []const u8 = null,
    /// --remove-domains: REMOVE from the existing list. null = none.
    remove: ?[]const []const u8 = null,
};

/// Match a `--flag=value` or `--flag value` option, advancing `i` for the latter.
fn matchValue(args: []const []const u8, i: *usize, long: []const u8) !?[]const u8 {
    const a = args[i.*];
    var buf: [64]u8 = undefined;
    const eq = std.fmt.bufPrint(&buf, "{s}=", .{long}) catch return null;
    if (std.mem.startsWith(u8, a, eq)) return a[eq.len..];
    if (std.mem.eql(u8, a, long)) {
        if (i.* + 1 >= args.len) return error.MissingValue;
        i.* += 1;
        return args[i.*];
    }
    return null;
}

fn parse(allocator: std.mem.Allocator, args: []const []const u8) !?Options {
    var target: ?[]const u8 = null;
    var set_csv: ?[]const u8 = null;
    var add_csv: ?[]const u8 = null;
    var remove_csv: ?[]const u8 = null;

    var i: usize = 2;
    while (i < args.len) : (i += 1) {
        const a = args[i];
        if (try matchValue(args, &i, "--domains")) |v| {
            set_csv = v;
        } else if (try matchValue(args, &i, "--add-domains")) |v| {
            add_csv = v;
        } else if (try matchValue(args, &i, "--add-domain")) |v| {
            add_csv = v;
        } else if (try matchValue(args, &i, "--remove-domains")) |v| {
            remove_csv = v;
        } else if (try matchValue(args, &i, "--remove-domain")) |v| {
            remove_csv = v;
        } else if (std.mem.startsWith(u8, a, "--")) {
            continue;
        } else if (target == null) {
            target = a;
        }
    }

    if (target == null) return null;
    return Options{
        .target = target.?,
        .set = if (set_csv) |csv| try splitDomains(allocator, csv) else null,
        .add = if (add_csv) |csv| try splitDomains(allocator, csv) else null,
        .remove = if (remove_csv) |csv| try splitDomains(allocator, csv) else null,
    };
}

/// The registry fields we resolve before upserting — from a proj.json (path
/// mode) or from the existing registry entry (name mode).
const Resolved = struct {
    name: []const u8,
    version: []const u8,
    path: []const u8,
    domains: []const []const u8,
};

fn printHelp() void {
    prompt.intro("hkm update — refresh a project's kernel registry entry");
    prompt.section("Usage");
    prompt.item("hkm update <path|name>", "by PATH (folder with proj.json) or registered NAME");
    prompt.blank();
    prompt.section("Domain options");
    prompt.muted("combine --add/--remove; --domains overrides both");
    prompt.item("  --domains=a.com,b.com", "REPLACE the whole domain list");
    prompt.item("  --add-domains=a.com,b.com", "ADD to the existing domains");
    prompt.item("  --remove-domains=a.com", "REMOVE from the existing domains");
    prompt.muted("no domain flag re-syncs domains from the project's proj.json");
    prompt.blank();
    prompt.section("Examples");
    prompt.note("hkm update ./my-shop");
    prompt.note("hkm update shop --add-domains=www.shop.com");
    prompt.note("hkm update shop --remove-domains=shop.local");
    prompt.note("hkm update shop --domains=shop.com,www.shop.com");
    prompt.outro("Pass a project path or name to begin");
    prompt.item("  --help, -h", "show this help");
}

pub fn run(allocator: std.mem.Allocator, io: Io, env: *EnvMap, args: []const []const u8) !u8 {
    // An explicit --help succeeds (exit 0); falling into the null branch below
    // means the arguments were wrong, which is a usage error (exit 2).
    for (args[@min(2, args.len)..]) |a| {
        if (std.mem.eql(u8, a, "--help") or std.mem.eql(u8, a, "-h")) {
            printHelp();
            return 0;
        }
    }

    const opts = (try parse(allocator, args)) orelse {
        printHelp();
        return 2;
    };

    prompt.intro(try std.fmt.allocPrint(allocator, "Update project '{s}'", .{opts.target}));

    const jsonPath = (try registry.resolvePath(allocator, io, env)) orelse {
        prompt.err("Kernel registry not found. Set PSP_PROJECTS_DIR or HKM_KERNEL_HOME.");
        return 1;
    };

    // PATH MODE: the target is a directory holding proj.json.
    var resolved: ?Resolved = try fromProjectDir(allocator, io, env, opts.target);

    // NAME MODE: not a project dir — look the name up in the registry, then try
    // to refresh from proj.json at its registered (absolute) path.
    if (resolved == null) {
        if (try registry.find(allocator, io, jsonPath, opts.target)) |entry| {
            resolved = (try fromProjectDir(allocator, io, env, entry.path)) orelse Resolved{
                .name = entry.name,
                .version = entry.version,
                .path = entry.path,
                .domains = entry.domains,
            };
        }
    }

    const r = resolved orelse {
        prompt.err(try std.fmt.allocPrint(
            allocator,
            "'{s}' is neither a project folder (with proj.json) nor a registered name.",
            .{opts.target},
        ));
        return 1;
    };

    // Compute the final domain list:
    //   --domains            → REPLACE wholesale
    //   --add/--remove       → mutate the CURRENT registry list (what's there)
    //   none                 → re-sync from proj.json (r.domains)
    const domains = blk: {
        if (opts.set) |s| break :blk s;

        if (opts.add != null or opts.remove != null) {
            // Base on what is ALREADY in the registry so add/remove are additive
            // edits, not a reset; fall back to proj.json domains if not yet there.
            const current = if (try registry.find(allocator, io, jsonPath, r.name)) |e| e.domains else r.domains;
            var list = try util.dupeList(allocator, current);
            if (opts.remove) |rm| list = try removeDomains(allocator, list, rm);
            if (opts.add) |ad| list = try addDomains(allocator, list, ad);
            break :blk list.items;
        }

        break :blk r.domains;
    };

    try registry.upsert(allocator, io, jsonPath, .{
        .name = r.name,
        .version = r.version,
        .path = r.path,
        .domains = domains,
    });

    prompt.ok(try std.fmt.allocPrint(allocator, "{s}  →  {s}", .{ r.name, r.path }));
    prompt.muted(try std.fmt.allocPrint(allocator, "domains: {s}", .{try util.joinList(allocator, domains)}));
    prompt.outro(try std.fmt.allocPrint(allocator, "Updated '{s}' in the registry", .{r.name}));
    return 0;
}

/// Read `<dir>/proj.json` and extract the registry fields. Returns null if the
/// directory has no readable/valid proj.json (so the caller can fall back to a
/// name lookup).
fn fromProjectDir(allocator: std.mem.Allocator, io: Io, env: *EnvMap, dir: []const u8) !?Resolved {
    const projPath = try util.join(allocator, dir, "proj.json");
    const content = Dir.cwd().readFileAlloc(io, projPath, allocator, .limited(4 * 1024 * 1024)) catch return null;

    const parsed = std.json.parseFromSliceLeaky(std.json.Value, allocator, content, .{}) catch return null;
    if (parsed != .object) return null;

    const name = strField(parsed.object, "name") orelse return null;
    return Resolved{
        .name = name,
        .version = strField(parsed.object, "version") orelse "1.0.0",
        .path = try util.absPath(allocator, env, dir),
        .domains = try domainsFromJson(allocator, parsed.object),
    };
}

/// Drop every domain present in `remove` from `list`.
fn removeDomains(allocator: std.mem.Allocator, list: std.ArrayList([]const u8), remove: []const []const u8) !std.ArrayList([]const u8) {
    var out: std.ArrayList([]const u8) = .empty;
    for (list.items) |d| {
        if (!util.contains(remove, d)) try out.append(allocator, d);
    }
    return out;
}

/// Append each domain in `add` that is not already present (dedup, order kept).
fn addDomains(allocator: std.mem.Allocator, list: std.ArrayList([]const u8), add: []const []const u8) !std.ArrayList([]const u8) {
    var out = list;
    for (add) |d| {
        if (!util.contains(out.items, d)) try out.append(allocator, d);
    }
    return out;
}

fn domainsFromJson(allocator: std.mem.Allocator, obj: std.json.ObjectMap) ![]const []const u8 {
    var list: std.ArrayList([]const u8) = .empty;
    if (obj.get("domains")) |d| {
        if (d == .array) {
            for (d.array.items) |item| {
                if (item == .string) try list.append(allocator, item.string);
            }
        }
    }
    return list.toOwnedSlice(allocator);
}

fn strField(obj: std.json.ObjectMap, key: []const u8) ?[]const u8 {
    const v = obj.get(key) orelse return null;
    return if (v == .string) v.string else null;
}

fn splitDomains(allocator: std.mem.Allocator, csv: []const u8) !?[]const []const u8 {
    var list: std.ArrayList([]const u8) = .empty;
    var it = std.mem.splitScalar(u8, csv, ',');
    while (it.next()) |raw| {
        const d = std.mem.trim(u8, raw, " \t\r\n");
        if (d.len > 0) try list.append(allocator, try allocator.dupe(u8, d));
    }
    if (list.items.len == 0) return null;
    const slice = try list.toOwnedSlice(allocator);
    return slice;
}

