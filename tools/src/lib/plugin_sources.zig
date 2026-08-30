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
        if (entry.name.len > 0 and entry.name[0] == '.') continue;

        // A SYMLINK to a plugin counts. Projects reference a version in the
        // shared store by linking it into their own plugins/, and a plain
        // `kind != .directory` check reports that entry as `.sym_link` and
        // skips it — making every store-linked plugin invisible to discovery,
        // and to everything built on it (locate, the dependency catalogue,
        // asset publishing, `plugins list`).
        switch (entry.kind) {
            .directory => {},
            .sym_link => {
                // Only follow links that actually resolve to a directory, so a
                // dangling or file link is not mistaken for a plugin.
                const target = std.fmt.allocPrint(allocator, "{s}/{s}", .{ util.trimSlash(pluginsDir), entry.name }) catch continue;
                if (!util.dirExists(Dir.cwd(), io, target)) continue;
            },
            else => continue,
        }

        try out.append(allocator, try allocator.dupe(u8, entry.name));
    }
}

// ── module.json ────────────────────────────────────────────────────────────────

/// One entry of a module's `requires[]`.
///
/// A dependency is named by DOMAIN, never by repository — that is the framework
/// being right: a module depends on a capability, not on who ships it. It does
/// leave a plugin outside the platform's own catalogue with no way to say where
/// its dependency comes from, so an entry may also be an object carrying that:
///
///   "requires": [
///     "database.management",
///     { "domain": "telemetry.exotic",
///       "repo":   "https://github.com/acme/hkm-plugin-telemetry.git",
///       "version": "^1.2" }
///   ]
///
/// The string form stays exactly as it was — every existing module.json parses
/// unchanged, and first-party plugins have no reason to write the long form.
pub const Requirement = struct {
    domain: []const u8,
    /// Where to fetch the plugin providing `domain`, for domains this platform
    /// has never heard of. Empty when the entry was a plain string.
    repo: []const u8 = "",
    /// A semver constraint ("^1.2"), an exact tag ("v1.2.0"), or a branch
    /// ("main"). Empty means the newest release tag.
    version: []const u8 = "",
};

pub const ModuleMeta = struct {
    name: ?[]const u8 = null,
    solves: ?[]const u8 = null,
    version: ?[]const u8 = null,
    /// "requires" — what this module depends on. Empty when absent.
    requires: []const Requirement = &.{},
    /// Domains named by INDIVIDUAL ROUTES (`routes[].requires[]`).
    ///
    /// Kept separate because they mean something different to the KERNEL — a
    /// route-level entry is seeded into that one request's graph, not every
    /// request's — but they are just as mandatory: CompileRouteManifestStage
    /// fails the whole boot when a route names a domain no registered module
    /// solves. A plugin whose routes require http.pageflow needs Pageflow
    /// installed and enabled exactly as much as one that requires it up top.
    route_requires: []const Requirement = &.{},
    /// How many HTTP routes this plugin publishes, and how many of those run
    /// NO filter at all — counted across top-level `routes[]` and every nested
    /// group, exactly as the route compiler expands them.
    ///
    /// Enabling a plugin activates every one of its routes at once, and until
    /// now the CLI reported none of them: a single install could publish thirty
    /// endpoints a project never reviewed. You cannot veto what you were never
    /// shown, so `enable` shows it.
    ///
    /// An unfiltered route is not automatically unsafe — a login form, a
    /// robots.txt, or a page shell whose data sits behind a filtered endpoint
    /// are all legitimately unfiltered. It is the set that has to be justified.
    route_count: usize = 0,
    unfiltered_routes: usize = 0,
    /// "documentation" — preferred enable-time doc (string, or array joined).
    doc: ?[]const u8 = null,
    /// "description" — fallback doc text.
    description: ?[]const u8 = null,
    /// "activation" — "essential" when the plugin only works if it is
    /// registered into EVERY request.
    ///
    /// Most plugins are on-demand: the kernel loads them when a route needs
    /// them, and that is strictly better. A few cannot be — a plugin whose
    /// pipeline stage runs on every request needs its bindings present on every
    /// request, and enabling it on-demand produces a project that installs
    /// cleanly, boots cleanly, and throws at the first request instead
    /// ("no TenantIdentifier is bound for this request"). Declaring it here
    /// lets `hkm plugins enable` put it in the right list without the user
    /// having to know.
    activation: ?[]const u8 = null,
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
        .requires = try requiresField(allocator, parsed.object),
        .route_requires = try routeRequiresField(allocator, parsed.object),
        .route_count = routeStats(parsed.object).total,
        .unfiltered_routes = routeStats(parsed.object).unfiltered,
        .doc = try docField(allocator, parsed.object, "documentation"),
        .description = strField(parsed.object, "description"),
        .kernel = strField(parsed.object, "kernel"),
        .activation = strField(parsed.object, "activation"),
    };
}

/// Read "requires", accepting both the string and the object form.
///
/// An object without a usable "domain" is SKIPPED rather than defaulted: a
/// requirement whose domain could not be read cannot be resolved, satisfied or
/// reported, and inventing one would attach its repo to the wrong dependency.
fn requiresField(allocator: std.mem.Allocator, obj: std.json.ObjectMap) ![]const Requirement {
    const v = obj.get("requires") orelse return &.{};
    if (v != .array) return &.{};

    var out: std.ArrayList(Requirement) = .empty;
    for (v.array.items) |item| {
        // "tag" and "branch" are the same field to git — one ref to clone at.
        // Named separately because a manifest saying "branch": "main" reads
        // better than "version": "main".
        const parsed = parseRequirement(item) orelse continue;
        try out.append(allocator, parsed);
    }
    return out.toOwnedSlice(allocator);
}

/// Collect every domain named by a route-level `requires[]`, de-duplicated.
///
/// Routes reach the manifest by three paths, and a dependency declared on ANY of
/// them is equally mandatory — the boot fails when a route names a domain no
/// registered module solves, wherever that route was written:
///
///   "routeRequires": [...]        a module-wide default applied to every route
///   "routes":  [ { "requires" } ] a route declared at the top level
///   "groups":  [ { "requires", "routes": [...], "groups": [...] } ]  nested
///
/// Missing the grouped ones would let `hkm plugins enable` resolve a plugin's
/// dependencies, install them, and still produce a project that fails at boot.
fn routeRequiresField(allocator: std.mem.Allocator, obj: std.json.ObjectMap) ![]const Requirement {
    var out: std.ArrayList(Requirement) = .empty;
    try collectRequires(allocator, obj, &out, 0);
    return out.toOwnedSlice(allocator);
}

/// Matches CompileRouteManifestStage::MAX_GROUP_DEPTH — a self-referencing
/// structure is rejected there, and must not spin here either.
const max_group_depth: u8 = 16;

pub const RouteStats = struct { total: usize = 0, unfiltered: usize = 0 };

/// Count the routes a module.json publishes, and how many run no filter.
///
/// Mirrors the compiler's expansion: a module-wide `routeFilters` and each
/// group's `filters` are inherited by everything inside them, so a route counts
/// as filtered when ANY level put something in front of it.
pub fn routeStats(obj: std.json.ObjectMap) RouteStats {
    var stats: RouteStats = .{};
    countRoutes(obj, false, &stats, 0);
    return stats;
}

fn countRoutes(obj: std.json.ObjectMap, inherited: bool, stats: *RouteStats, depth: u8) void {
    if (depth > max_group_depth) return;

    var guarded = inherited;
    if (nonEmptyArray(obj, "routeFilters")) guarded = true;
    // "filters" is a GROUP key; at the top level the module-wide spelling is
    // "routeFilters", and reading both there would misread an unrelated key.
    if (depth > 0 and nonEmptyArray(obj, "filters")) guarded = true;

    if (obj.get("routes")) |routes| {
        if (routes == .array) {
            for (routes.array.items) |route| {
                if (route != .object) continue;
                stats.total += 1;
                if (!(guarded or nonEmptyArray(route.object, "filters"))) {
                    stats.unfiltered += 1;
                }
            }
        }
    }

    if (obj.get("groups")) |groups| {
        if (groups == .array) {
            for (groups.array.items) |group| {
                if (group != .object) continue;
                countRoutes(group.object, guarded, stats, depth + 1);
            }
        }
    }
}

fn nonEmptyArray(obj: std.json.ObjectMap, key: []const u8) bool {
    const v = obj.get(key) orelse return false;
    return v == .array and v.array.items.len > 0;
}

/// Walk one route-declaration source: its module-wide `routeRequires`, each
/// `routes[].requires[]`, and every nested group, recursively.
fn collectRequires(
    allocator: std.mem.Allocator,
    obj: std.json.ObjectMap,
    out: *std.ArrayList(Requirement),
    depth: u8,
) !void {
    if (depth > max_group_depth) return;

    // Module-wide / group-wide default.
    if (obj.get("routeRequires")) |v| try appendRequirements(allocator, v, out);
    if (obj.get("requires")) |v| {
        // Only meaningful on a GROUP — a module's own top-level requires[] is
        // read separately by requiresField(). Harmless either way: duplicates
        // are dropped, and both lists name domains that must be installed.
        if (depth > 0) try appendRequirements(allocator, v, out);
    }

    if (obj.get("routes")) |routes| {
        if (routes == .array) {
            for (routes.array.items) |route| {
                if (route != .object) continue;
                if (route.object.get("requires")) |reqs| {
                    try appendRequirements(allocator, reqs, out);
                }
            }
        }
    }

    if (obj.get("groups")) |groups| {
        if (groups == .array) {
            for (groups.array.items) |group| {
                if (group != .object) continue;
                try collectRequires(allocator, group.object, out, depth + 1);
            }
        }
    }
}

/// Append every requirement in a `requires[]` value, skipping duplicates.
fn appendRequirements(
    allocator: std.mem.Allocator,
    value: std.json.Value,
    out: *std.ArrayList(Requirement),
) !void {
    if (value != .array) return;

    for (value.array.items) |item| {
        const parsed = parseRequirement(item) orelse continue;
        var seen = false;
        for (out.items) |e| {
            if (std.mem.eql(u8, e.domain, parsed.domain)) seen = true;
        }
        if (!seen) try out.append(allocator, parsed);
    }
}

/// One requires[] entry, in either the string or the object form.
fn parseRequirement(item: std.json.Value) ?Requirement {
    switch (item) {
        .string => {
            if (item.string.len == 0) return null;
            return .{ .domain = item.string };
        },
        .object => {
            const domain = strField(item.object, "domain") orelse return null;
            if (domain.len == 0) return null;
            return .{
                .domain = domain,
                .repo = strField(item.object, "repo") orelse
                    strField(item.object, "remote") orelse
                    strField(item.object, "url") orelse "",
                .version = strField(item.object, "version") orelse
                    strField(item.object, "tag") orelse
                    strField(item.object, "branch") orelse "",
            };
        },
        else => return null,
    }
}

/// Read an array-of-strings field. Returns an empty slice when
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

// ── tests ───────────────────────────────────────────────────────────────────

/// Parse a module.json body and collect its route-level requires.
fn testRouteRequires(allocator: std.mem.Allocator, json: []const u8) ![]const Requirement {
    const parsed = try std.json.parseFromSliceLeaky(std.json.Value, allocator, json, .{});
    return routeRequiresField(allocator, parsed.object);
}

fn hasDomain(list: []const Requirement, domain: []const u8) bool {
    for (list) |r| {
        if (std.mem.eql(u8, r.domain, domain)) return true;
    }
    return false;
}

test "route requires are collected from top-level routes" {
    var arena = std.heap.ArenaAllocator.init(std.testing.allocator);
    defer arena.deinit();

    const got = try testRouteRequires(arena.allocator(),
        \\{ "routes": [ { "requires": ["view.rendering"] } ] }
    );

    try std.testing.expect(hasDomain(got, "view.rendering"));
}

test "route requires are collected from NESTED groups" {
    // A plugin that moves its routes into groups[] declares the same mandatory
    // dependencies — missing them would install cleanly and fail at boot.
    var arena = std.heap.ArenaAllocator.init(std.testing.allocator);
    defer arena.deinit();

    const got = try testRouteRequires(arena.allocator(),
        \\{
        \\  "routeRequires": ["database.management"],
        \\  "routes": [ { "requires": ["http.client"] } ],
        \\  "groups": [
        \\    { "requires": ["audit.trail"],
        \\      "routes": [ { "requires": ["storage.local"] } ],
        \\      "groups": [ { "routes": [ { "requires": ["view.rendering"] } ] } ] }
        \\  ]
        \\}
    );

    try std.testing.expect(hasDomain(got, "database.management")); // module-wide default
    try std.testing.expect(hasDomain(got, "http.client"));         // top-level route
    try std.testing.expect(hasDomain(got, "audit.trail"));         // the group itself
    try std.testing.expect(hasDomain(got, "storage.local"));       // a route inside it
    try std.testing.expect(hasDomain(got, "view.rendering"));      // a nested group
}

test "a module's own top-level requires is not double-counted here" {
    // requiresField() already reads it; collecting it again would be harmless
    // but muddies which list a domain came from.
    var arena = std.heap.ArenaAllocator.init(std.testing.allocator);
    defer arena.deinit();

    const got = try testRouteRequires(arena.allocator(),
        \\{ "requires": ["crypto.services"], "routes": [] }
    );

    try std.testing.expect(!hasDomain(got, "crypto.services"));
}

test "duplicate domains across groups are collected once" {
    var arena = std.heap.ArenaAllocator.init(std.testing.allocator);
    defer arena.deinit();

    const got = try testRouteRequires(arena.allocator(),
        \\{
        \\  "routes": [ { "requires": ["view.rendering"] } ],
        \\  "groups": [ { "routes": [ { "requires": ["view.rendering"] } ] } ]
        \\}
    );

    var count: usize = 0;
    for (got) |r| {
        if (std.mem.eql(u8, r.domain, "view.rendering")) count += 1;
    }
    try std.testing.expectEqual(@as(usize, 1), count);
}

test "a self-referencing group structure terminates" {
    var arena = std.heap.ArenaAllocator.init(std.testing.allocator);
    defer arena.deinit();

    // 40 levels — deeper than max_group_depth, which the compiler also rejects.
    var buf: std.ArrayList(u8) = .empty;
    defer buf.deinit(std.testing.allocator);
    for (0..40) |_| try buf.appendSlice(std.testing.allocator, "{\"groups\":[");
    try buf.appendSlice(std.testing.allocator, "{\"routes\":[{\"requires\":[\"deep.domain\"]}]}");
    for (0..40) |_| try buf.appendSlice(std.testing.allocator, "]}");

    const got = try testRouteRequires(arena.allocator(), buf.items);
    _ = got; // reaching here at all is the assertion: it returned.
}


// ── tests ────────────────────────────────────────────────────────────────────

/// Parse into an arena: parseFromSliceLeaky never frees, which the testing
/// allocator correctly reports as a leak. The arena owns everything and is
/// released when the test returns.
fn statsFor(src: []const u8) !RouteStats {
    var arena = std.heap.ArenaAllocator.init(std.testing.allocator);
    defer arena.deinit();

    const parsed = try std.json.parseFromSliceLeaky(std.json.Value, arena.allocator(), src, .{});
    return routeStats(parsed.object);
}

test "counts top-level routes and spots the unfiltered ones" {
    const s = try statsFor(
        \\{"routes":[
        \\ {"method":"GET","path":"/a"},
        \\ {"method":"GET","path":"/b","filters":["auth"]}
        \\]}
    );
    try std.testing.expectEqual(@as(usize, 2), s.total);
    try std.testing.expectEqual(@as(usize, 1), s.unfiltered);
}

test "a module-wide routeFilters guards every route" {
    const s = try statsFor(
        \\{"routeFilters":["auth"],"routes":[
        \\ {"method":"GET","path":"/a"},
        \\ {"method":"GET","path":"/b"}
        \\]}
    );
    try std.testing.expectEqual(@as(usize, 2), s.total);
    try std.testing.expectEqual(@as(usize, 0), s.unfiltered);
}

test "group filters are inherited by nested groups" {
    const s = try statsFor(
        \\{"groups":[
        \\ {"filters":["auth"],"routes":[{"method":"GET","path":"/x"}],
        \\  "groups":[{"routes":[{"method":"GET","path":"/y"}]}]},
        \\ {"routes":[{"method":"GET","path":"/z"}]}
        \\]}
    );
    try std.testing.expectEqual(@as(usize, 3), s.total);
    // /x and /y inherit auth; only /z is bare.
    try std.testing.expectEqual(@as(usize, 1), s.unfiltered);
}

test "an empty filters array does not count as guarded" {
    const s = try statsFor(
        \\{"routes":[{"method":"GET","path":"/a","filters":[]}]}
    );
    try std.testing.expectEqual(@as(usize, 1), s.unfiltered);
}

test "a manifest with no routes counts nothing" {
    const s = try statsFor("{\"solves\":\"x.y\"}");
    try std.testing.expectEqual(@as(usize, 0), s.total);
    try std.testing.expectEqual(@as(usize, 0), s.unfiltered);
}
