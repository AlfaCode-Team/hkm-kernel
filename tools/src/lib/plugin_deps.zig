//! Dependency resolution for plugin enable/disable.
//!
//! Every plugin `solves` exactly one domain and `requires` a set of domains
//! (each the `solves` value of another module, or a kernel port). This module:
//!
//!   • builds a CATALOGUE of every discoverable plugin (folder + solves + requires),
//!   • resolves the transitive `requires` closure when ENABLING (so enabling
//!     Tenancy also pulls in Database, Auth, User — and their own deps), and
//!   • finds the enabled DEPENDENTS that would break when DISABLING a plugin.
//!
//! Domains that no catalogued plugin solves are reported as "missing" — they are
//! usually satisfied by a kernel port bound in withPorts() (e.g. database.query),
//! so they are surfaced as a note rather than treated as a hard failure.

const std = @import("std");
const sources = @import("plugin_sources.zig");
const boot = @import("plugin_bootstrap.zig");
const util = @import("util.zig");

const Io = std.Io;
const EnvMap = std.process.Environ.Map;

const Source = sources.Source;
const Located = sources.Located;
const Enabled = boot.Enabled;

/// One plugin known to the project, enriched from its module.json.
pub const Provider = struct {
    located: Located,
    solves: ?[]const u8 = null,
    requires: []const sources.Requirement = &.{},

    pub fn name(self: Provider) []const u8 {
        return self.located.name;
    }
};

/// Build a catalogue of every plugin discoverable across `search` sources,
/// each carrying its solves domain + requires list. Project entries shadow
/// kernel entries of the same folder name (project is listed first).
pub fn catalogue(
    allocator: std.mem.Allocator,
    io: Io,
    srcs: sources.Sources,
    search: []const Source,
    out: *std.ArrayList(Provider),
) !void {
    for (search) |src| {
        const dir = srcs.dirFor(src) orelse continue;
        var names: std.ArrayList([]const u8) = .empty;
        try sources.listPluginDirs(allocator, io, dir, &names);
        for (names.items) |p| {
            // Skip folders that are not enableable modules (no Provider.php).
            const prov = try std.fmt.allocPrint(allocator, "{s}/{s}/Provider.php", .{ dir, p });
            if (!util.fileExists(io, prov)) continue;
            if (findByName(out.items, p) != null) continue; // first source wins (project shadows kernel)

            const meta = try sources.readModuleMeta(allocator, io, dir, p);

            // Module-level and route-level requires are MERGED here.
            //
            // The kernel distinguishes them at runtime — a route-level domain
            // is seeded into that one request's graph — but not at boot: a
            // route naming a domain no registered module solves fails the whole
            // build. So for installing and enabling they are one list. Reading
            // only the module-level one is why a project with Tenancy booted
            // straight into "Route [GET /tenants] requires unknown module
            // domain [http.pageflow]": the walk fetched Tenancy's two declared
            // dependencies and none of the four its routes need.
            var reqs: std.ArrayList(sources.Requirement) = .empty;
            if (meta) |m| {
                for (m.requires) |r| try reqs.append(allocator, r);
                for (m.route_requires) |r| {
                    var seen = false;
                    for (reqs.items) |e| {
                        if (std.mem.eql(u8, e.domain, r.domain)) seen = true;
                    }
                    if (!seen) try reqs.append(allocator, r);
                }
            }

            try out.append(allocator, .{
                .located = .{ .name = p, .source = src, .dir = dir },
                .solves = if (meta) |m| m.solves else null,
                .requires = reqs.items,
            });
        }
    }
}

pub fn findByName(cat: []const Provider, name: []const u8) ?Provider {
    for (cat) |p| {
        if (util.eqlIgnoreCase(p.located.name, name)) return p;
    }
    return null;
}

/// The catalogued plugin whose `solves` domain equals `domain`.
pub fn providerForDomain(cat: []const Provider, domain: []const u8) ?Provider {
    for (cat) |p| {
        if (p.solves) |s| {
            if (std.mem.eql(u8, s, domain)) return p;
        }
    }
    return null;
}

/// The transitive set of plugin FOLDERS that `folder` depends on — every plugin
/// that provides a domain in `folder`'s requires closure, excluding `folder`.
///
///   `needed`  → providers to enable (in dependency order: deps before dependents).
///   `missing` → required domains no catalogued plugin solves (likely a port).
pub fn requiredClosure(
    allocator: std.mem.Allocator,
    cat: []const Provider,
    folder: []const u8,
    needed: *std.ArrayList(Provider),
    missing: *std.ArrayList([]const u8),
) !void {
    const root = findByName(cat, folder) orelse return;
    try visit(allocator, cat, root, needed, missing, folder);
}

fn visit(
    allocator: std.mem.Allocator,
    cat: []const Provider,
    p: Provider,
    needed: *std.ArrayList(Provider),
    missing: *std.ArrayList([]const u8),
    rootFolder: []const u8,
) !void {
    for (p.requires) |req| {
        const domain = req.domain;
        if (providerForDomain(cat, domain)) |dep| {
            // Don't list the plugin being enabled, and de-dupe.
            if (util.eqlIgnoreCase(dep.located.name, rootFolder)) continue;
            if (findByName(needed.items, dep.located.name) != null) continue;
            try visit(allocator, cat, dep, needed, missing, rootFolder); // deps first
            try needed.append(allocator, dep); // then the dependent
        } else {
            if (!containsStr(missing.items, domain)) try missing.append(allocator, domain);
        }
    }
}

/// Among the currently `enabled` plugins, those that (transitively) require any
/// domain provided by `folder` — i.e. the entries that would break if `folder`
/// were disabled. Returned in dependent order (safe to disable top-to-bottom).
pub fn enabledDependentsOf(
    allocator: std.mem.Allocator,
    cat: []const Provider,
    enabled: []const Enabled,
    folder: []const u8,
    out: *std.ArrayList(Provider),
) !void {
    const target = findByName(cat, folder) orelse return;
    const domain = target.solves orelse return; // solves nothing → nothing depends on it

    for (enabled) |e| {
        if (util.eqlIgnoreCase(e.name, folder)) continue;
        const dep = findByName(cat, e.name) orelse continue;
        if (dependsOnDomain(cat, dep, domain, folder)) {
            if (findByName(out.items, dep.located.name) == null) try out.append(allocator, dep);
        }
    }
}

/// Does `p` require `domain` directly or transitively (following providers)?
fn dependsOnDomain(cat: []const Provider, p: Provider, domain: []const u8, skip: []const u8) bool {
    for (p.requires) |req| {
        if (std.mem.eql(u8, req.domain, domain)) return true;
        const next = providerForDomain(cat, req.domain) orelse continue;
        if (util.eqlIgnoreCase(next.located.name, skip)) continue;
        if (dependsOnDomain(cat, next, domain, skip)) return true;
    }
    return false;
}

fn containsStr(items: []const []const u8, s: []const u8) bool {
    for (items) |i| {
        if (std.mem.eql(u8, i, s)) return true;
    }
    return false;
}

fn containsName(items: []const []const u8, name: []const u8) bool {
    for (items) |i| {
        if (util.eqlIgnoreCase(i, name)) return true;
    }
    return false;
}

/// Given the set of plugin folders being removed (`removing`), find the
/// dependencies that become ORPHANED — enabled, pulled in only as a dependency
/// of something in `removing`, and required by NO plugin that stays enabled.
/// Shared dependencies (still needed by another enabled plugin) are kept.
///
/// Iterates to a fixpoint so pruning one orphan can expose its own now-unused
/// dependencies (disable User → orphans View → orphans nothing further).
pub fn orphanedDependencies(
    allocator: std.mem.Allocator,
    cat: []const Provider,
    enabled: []const Enabled,
    removing: []const []const u8,
    out: *std.ArrayList(Provider),
) !void {
    // Candidate pool: every dependency anything in `removing` pulled in.
    var candidates: std.ArrayList(Provider) = .empty;
    for (removing) |name| {
        var needed: std.ArrayList(Provider) = .empty;
        var missing: std.ArrayList([]const u8) = .empty;
        try requiredClosure(allocator, cat, name, &needed, &missing);
        for (needed.items) |p| {
            if (findByName(candidates.items, p.located.name) == null) try candidates.append(allocator, p);
        }
    }

    var changed = true;
    while (changed) {
        changed = false;
        for (candidates.items) |c| {
            const dname = c.located.name;
            if (!isEnabled(enabled, dname)) continue;
            if (containsName(removing, dname)) continue;
            if (findByName(out.items, dname) != null) continue;
            const domain = c.solves orelse continue;
            if (stillNeeded(cat, enabled, removing, out.items, domain, dname)) continue;
            try out.append(allocator, c);
            changed = true;
        }
    }
}

fn isEnabled(enabled: []const Enabled, name: []const u8) bool {
    for (enabled) |e| {
        if (util.eqlIgnoreCase(e.name, name)) return true;
    }
    return false;
}

/// Does any plugin that REMAINS enabled (enabled − removing − pruning) still
/// transitively require `domain`? If so the provider must be kept.
fn stillNeeded(
    cat: []const Provider,
    enabled: []const Enabled,
    removing: []const []const u8,
    pruning: []const Provider,
    domain: []const u8,
    skip: []const u8,
) bool {
    for (enabled) |e| {
        if (util.eqlIgnoreCase(e.name, skip)) continue;
        if (containsName(removing, e.name)) continue;
        if (findByName(pruning, e.name) != null) continue;
        const p = findByName(cat, e.name) orelse continue;
        if (dependsOnDomain(cat, p, domain, skip)) return true;
    }
    return false;
}
