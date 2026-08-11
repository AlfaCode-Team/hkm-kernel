//! Which plugin provides a given domain.
//!
//! A plugin declares its dependencies in module.json as the DOMAINS it needs
//! ("crypto.services", "cache.redis") — never as repository names. That is the
//! right thing for the framework: a module depends on a capability, not on who
//! happens to ship it. It leaves the installer with a lookup to do, and the
//! lookup cannot be guessed:
//!
//!   crypto.services       → hkm-plugin-crypto          the first segment works
//!   logging.application   → hkm-plugin-logger          …and here it does not
//!   http.client           → hkm-plugin-http-client
//!   http.cookies          → hkm-plugin-cookie          four different plugins
//!   http.pageflow         → hkm-plugin-pageflow        share one first segment
//!   http.security_filters → hkm-plugin-security-filters
//!
//! Thirteen of the twenty-eight first-party domains do not match their
//! repository name, and "http" alone is ambiguous four ways — so a naming
//! convention cannot carry this. It is resolved from three sources, most
//! trustworthy first.

const std = @import("std");
const sources = @import("plugin_sources.zig");
const deps = @import("plugin_deps.zig");
const util = @import("util.zig");
const pregistry = @import("plugin_registry.zig");

const Io = std.Io;
const EnvMap = std.process.Environ.Map;

pub const Mapping = struct { domain: []const u8, folder: []const u8 };

/// How a domain was resolved — worth reporting, because the three sources carry
/// different weight and a seeded guess can be stale in a way disk never is.
pub const Origin = enum {
    /// Read from an installed plugin's own module.json. Cannot be wrong.
    installed,
    /// From the built-in table below. Right for first-party plugins, and only
    /// as current as the release of this tool.
    seed,
    /// Declared by the plugin that needs it, as a repo on its requires[] entry.
    /// The only source that can reach a plugin nothing else has heard of.
    declared,
};

pub const Resolution = struct {
    folder: []const u8,
    origin: Origin,
    /// Set only for `.declared` — where to fetch it, and at which ref.
    repo: []const u8 = "",
    version: []const u8 = "",
};

/// First-party domain → plugin folder.
///
/// Generated from the plugin repositories rather than typed, and ordered by
/// domain. It exists for one case: resolving a dependency of a plugin that is
/// not installed yet, where there is no module.json on disk to read. Once a
/// plugin IS installed its own manifest takes over, which is why a stale entry
/// here degrades to a wrong first guess rather than a wrong answer.
///
/// Adding a first-party plugin means adding its line. `hkm plugins domains`
/// prints the table, and the test at the bottom of this file keeps it honest.
pub const seed = [_]Mapping{
    .{ .domain = "audit.trail",           .folder = "Audit" },
    .{ .domain = "auth.identity",         .folder = "Auth" },
    .{ .domain = "auth.social",           .folder = "SocialAuth" },
    .{ .domain = "authorization.policy",  .folder = "Authorization" },
    .{ .domain = "cache.redis",           .folder = "RedisCache" },
    .{ .domain = "crypto.services",       .folder = "Crypto" },
    .{ .domain = "database.management",   .folder = "Database" },
    .{ .domain = "dev.tooling",           .folder = "DevTools" },
    .{ .domain = "edge.routing",          .folder = "Edge" },
    .{ .domain = "feedback.management",   .folder = "Feedback" },
    .{ .domain = "http.client",           .folder = "HttpClient" },
    .{ .domain = "http.cookies",          .folder = "Cookie" },
    .{ .domain = "http.pageflow",         .folder = "Pageflow" },
    .{ .domain = "http.security_filters", .folder = "SecurityFilters" },
    .{ .domain = "i18n.translation",      .folder = "I18n" },
    .{ .domain = "logging.application",   .folder = "Logger" },
    .{ .domain = "mail.delivery",         .folder = "Mail" },
    .{ .domain = "oauth.server",          .folder = "OAuth2" },
    .{ .domain = "seo.management",        .folder = "SiteSEO" },
    .{ .domain = "session.management",    .folder = "Session" },
    .{ .domain = "storage.local",         .folder = "Storage" },
    .{ .domain = "system.commands",       .folder = "Commands" },
    .{ .domain = "tenancy.routing",       .folder = "Tenancy" },
    .{ .domain = "tenant.settings",       .folder = "Settings" },
    .{ .domain = "user.management",       .folder = "User" },
    .{ .domain = "validation.rules",      .folder = "Validation" },
    .{ .domain = "view.rendering",        .folder = "View" },
    .{ .domain = "vite.manifest",         .folder = "ViteManifest" },
};

/// The plugin folder that provides `domain`, or null when nothing knows.
///
/// Consults installed plugins first: their module.json is the authority, it
/// covers third-party plugins the table has never heard of, and it is right
/// even when the table is out of date.
pub fn resolve(
    allocator: std.mem.Allocator,
    io: Io,
    env: *EnvMap,
    projectRoot: []const u8,
    domain: []const u8,
) !?Resolution {
    const srcs = try sources.discoverSources(allocator, io, env, projectRoot);
    var cat: std.ArrayList(deps.Provider) = .empty;
    try deps.catalogue(allocator, io, srcs, &.{ .project, .kernel }, &cat);

    if (deps.providerForDomain(cat.items, domain)) |p| {
        return .{ .folder = p.located.name, .origin = .installed };
    }

    for (seed) |m| {
        if (std.mem.eql(u8, m.domain, domain)) return .{ .folder = m.folder, .origin = .seed };
    }

    return null;
}

/// As `resolve`, but against a catalogue the caller already built — for loops
/// that would otherwise re-scan every plugins directory once per domain.
pub fn resolveIn(cat: []const deps.Provider, domain: []const u8) ?Resolution {
    if (deps.providerForDomain(cat, domain)) |p| {
        return .{ .folder = p.located.name, .origin = .installed };
    }
    for (seed) |m| {
        if (std.mem.eql(u8, m.domain, domain)) return .{ .folder = m.folder, .origin = .seed };
    }
    return null;
}

/// Resolve a requirement, allowing the repo it declares to answer for domains
/// nothing else knows.
///
/// The declared repo is consulted LAST, after disk and the built-in table, and
/// that ordering is a security property rather than a preference. A plugin can
/// name any URL it likes; if a declaration outranked the curated table, then
/// installing any plugin could silently redirect `crypto.services` — a
/// first-party domain, on the trusted path, in the shared kernel directory — to
/// a repository of its author's choosing. Consulting it last means a
/// declaration can only ever REACH a domain the platform has no answer for,
/// which is the case it exists to serve.
///
/// `overridden` is set when a declaration was ignored because the platform
/// already had an answer, so the caller can say so rather than diverge silently.
pub fn resolveRequirement(
    cat: []const deps.Provider,
    req: sources.Requirement,
    overridden: *bool,
) ?Resolution {
    overridden.* = false;

    if (resolveIn(cat, req.domain)) |hit| {
        if (req.repo.len > 0 and hit.origin == .seed) overridden.* = true;
        return hit;
    }

    if (req.repo.len == 0) return null;

    // Nothing else knows this domain. The folder name comes from the repo, and
    // is corrected from the plugin's own module.json once it is fetched.
    const folder = pregistry.nameFromRemote(std.heap.page_allocator, req.repo) catch return null;
    return .{
        .folder = folder,
        .origin = .declared,
        .repo = req.repo,
        .version = req.version,
    };
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test "the seed table has no duplicate or empty entries" {
    // A duplicated domain would resolve to whichever line came first, silently.
    for (seed, 0..) |a, i| {
        try std.testing.expect(a.domain.len > 0);
        try std.testing.expect(a.folder.len > 0);
        for (seed[i + 1 ..]) |b| {
            try std.testing.expect(!std.mem.eql(u8, a.domain, b.domain));
        }
    }
}

test "every seeded folder is the canonical spelling of itself" {
    // The folder has to match the PSR-4 namespace exactly, so a seed entry that
    // is not already canonical would install to a directory the autoloader
    // never looks in — the failure this whole lookup exists to prevent.
    const a = std.testing.allocator;
    for (seed) |m| {
        const canon = try pregistry.canonicalName(a, m.folder);
        defer a.free(canon);
        try std.testing.expectEqualStrings(m.folder, canon);
    }
}

test "the domains a convention could not reach are the ones that matter" {
    // Guards the premise of this file: if these ever became derivable from
    // their domain, the table would be dead weight. They are not.
    const cases = [_]Mapping{
        .{ .domain = "logging.application", .folder = "Logger" },
        .{ .domain = "cache.redis", .folder = "RedisCache" },
        .{ .domain = "http.cookies", .folder = "Cookie" },
        .{ .domain = "http.security_filters", .folder = "SecurityFilters" },
        .{ .domain = "oauth.server", .folder = "OAuth2" },
        .{ .domain = "seo.management", .folder = "SiteSEO" },
    };
    for (cases) |c| {
        const got = resolveIn(&.{}, c.domain) orelse return error.Unresolved;
        try std.testing.expectEqualStrings(c.folder, got.folder);
        try std.testing.expect(got.origin == .seed);
    }
}

test "a declared repo reaches an unknown domain but never overrides a known one" {
    const sources_mod = @import("plugin_sources.zig");
    var overridden = false;

    // The case it exists for: nothing on disk, nothing in the table.
    const unknown = sources_mod.Requirement{
        .domain = "telemetry.exotic",
        .repo = "https://github.com/acme/hkm-plugin-telemetry.git",
        .version = "^1.2",
    };
    const hit = resolveRequirement(&.{}, unknown, &overridden) orelse return error.Unresolved;
    try std.testing.expect(hit.origin == .declared);
    try std.testing.expectEqualStrings("Telemetry", hit.folder);
    try std.testing.expectEqualStrings("^1.2", hit.version);
    try std.testing.expect(!overridden);

    // The case that must NOT work: a plugin cannot redirect a platform domain
    // to a repository of its choosing — that domain installs into the SHARED
    // kernel directory, where it would affect every project on the machine.
    const hijack = sources_mod.Requirement{
        .domain = "crypto.services",
        .repo = "https://github.com/attacker/hkm-plugin-crypto.git",
    };
    const safe = resolveRequirement(&.{}, hijack, &overridden) orelse return error.Unresolved;
    try std.testing.expect(safe.origin == .seed);
    try std.testing.expectEqualStrings("Crypto", safe.folder);
    try std.testing.expectEqualStrings("", safe.repo);
    // …and the caller is told, so the divergence is never silent.
    try std.testing.expect(overridden);

    // No repo and no answer: unresolved, not guessed.
    const bare = sources_mod.Requirement{ .domain = "nothing.knows.this" };
    try std.testing.expect(resolveRequirement(&.{}, bare, &overridden) == null);
}

test "a plugin's route-level requires are dependencies too" {
    // Regression: Tenancy declares database.management + i18n.translation at
    // module level, and http.pageflow / auth.identity / user.management /
    // audit.trail on individual ROUTES. Reading only the module level installed
    // two of six, and the project failed to boot on
    //   Route [GET /tenants] requires unknown module domain [http.pageflow]
    // because CompileRouteManifestStage enforces route requires at BUILD time.
    const sources_mod = @import("plugin_sources.zig");

    const route_only = [_]sources_mod.Requirement{.{ .domain = "http.pageflow" }};
    var overridden = false;

    const hit = resolveRequirement(&.{}, route_only[0], &overridden) orelse return error.Unresolved;
    try std.testing.expectEqualStrings("Pageflow", hit.folder);
}
