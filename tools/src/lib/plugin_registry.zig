//! Where a plugin comes from, and whether it may be installed here.
//!
//! Two jobs, both needed before a single byte is fetched:
//!
//!   1. RESOLVE  plugin name  →  git remote URL
//!   2. GATE     plugin's declared kernel constraint  →  this kernel's version
//!
//! The gate matters more than it looks. Without it, `hkm plugins enable auth`
//! happily installs a plugin built for a kernel two majors ahead; the failure
//! then surfaces at request time, deep inside a pipeline, as a missing method on
//! a contract — an error that points at the plugin and says nothing about the
//! version mismatch that caused it. Refusing at install time turns a confusing
//! runtime bug into a one-line message.

const std = @import("std");
const semver = @import("semver.zig");
const banner = @import("banner.zig");
const util = @import("util.zig");

const Dir = std.Io.Dir;
const Io = std.Io;
const EnvMap = std.process.Environ.Map;

/// GitHub organisation hosting the first-party plugins.
pub const default_org = "AlfaCode-Team";

/// Repo names are `hkm-plugin-<slug>`; the slug is the lower-cased plugin
/// folder name EXCEPT where the repo was created with a different spelling.
/// This table is the whole of that irregularity — keep it here rather than
/// scattering special cases through the commands.
const slug_overrides = [_]struct { folder: []const u8, slug: []const u8 }{
    .{ .folder = "DevTools", .slug = "dev-tools" },
    .{ .folder = "HttpClient", .slug = "http-client" },
    .{ .folder = "RedisCache", .slug = "redis-cache" },
    .{ .folder = "SecurityFilters", .slug = "security-filters" },
    .{ .folder = "SocialAuth", .slug = "social-auth" },
    // Deliberately NOT hyphenated — these repos exist as single words.
    .{ .folder = "SiteSEO", .slug = "siteseo" },
    .{ .folder = "ViteManifest", .slug = "vitemanifest" },
    .{ .folder = "OAuth2", .slug = "oauth2" },
};

/// The repo slug for a plugin folder name.
///
/// Case-insensitive on the override table so `hkm plugins enable sociaLAuth`
/// resolves the same as `SocialAuth`.
pub fn slugFor(allocator: std.mem.Allocator, folder: []const u8) ![]const u8 {
    for (slug_overrides) |o| {
        if (util.eqlIgnoreCase(folder, o.folder)) return allocator.dupe(u8, o.slug);
    }
    return util.lower(allocator, folder);
}

/// The organisation to fetch from. HKM_PLUGIN_ORG lets a fork or a private
/// mirror be used without rebuilding the tool.
pub fn org(env: *EnvMap) []const u8 {
    const v = env.get("HKM_PLUGIN_ORG") orelse return default_org;
    const t = std.mem.trim(u8, v, " \t\r\n");
    return if (t.len == 0) default_org else t;
}

/// The git remote for a plugin.
///
/// HKM_PLUGIN_REMOTE overrides the whole template for air-gapped or mirrored
/// installs; "{s}" in it is replaced by the slug. Example:
///
///   HKM_PLUGIN_REMOTE=git@git.internal:hkm/{s}.git
pub fn remoteFor(allocator: std.mem.Allocator, env: *EnvMap, folder: []const u8) ![]const u8 {
    const slug = try slugFor(allocator, folder);

    if (env.get("HKM_PLUGIN_REMOTE")) |tpl| {
        const t = std.mem.trim(u8, tpl, " \t\r\n");
        if (t.len > 0) {
            if (std.mem.indexOf(u8, t, "{s}")) |i| {
                return std.fmt.allocPrint(allocator, "{s}{s}{s}", .{ t[0..i], slug, t[i + 3 ..] });
            }
            // No placeholder: treat it as a base URL.
            return std.fmt.allocPrint(allocator, "{s}/hkm-plugin-{s}.git", .{ std.mem.trimEnd(u8, t, "/"), slug });
        }
    }

    return std.fmt.allocPrint(allocator, "https://github.com/{s}/hkm-plugin-{s}.git", .{ org(env), slug });
}

/// The running kernel's version, parsed. Null when the build stamped something
/// unparseable (never expected — the default is "0.0.0-dev").
pub fn kernelVersion() ?semver.Version {
    // parseDescribed, not parse: a build stamped from `git describe` carries a
    // "-<commits>-g<sha>" trailer that plain semver reads as a pre-release,
    // making a build made AFTER a release sort below it.
    return semver.parseDescribed(banner.version());
}

/// Outcome of checking a plugin's kernel constraint.
pub const Compat = union(enum) {
    /// No constraint declared, or it is satisfied.
    ok,
    /// Declared and NOT satisfied. Carries both sides for the message.
    incompatible: struct { required: []const u8, kernel: []const u8 },
    /// The constraint string is malformed — refuse rather than guess.
    bad_constraint: []const u8,
    /// The kernel's own version could not be parsed.
    unknown_kernel,
};

/// Check a plugin's declared kernel constraint against this kernel.
///
/// An EMPTY constraint returns `.ok`. That is deliberate: every plugin written
/// before this field existed declares nothing, and failing closed would make
/// them all uninstallable overnight. A MALFORMED constraint does NOT get the
/// same benefit of the doubt — it means someone tried to express a requirement
/// and got it wrong, and silently ignoring it defeats the point of the check.
pub fn checkKernel(constraint: ?[]const u8) Compat {
    const raw = constraint orelse return .ok;
    const trimmed = std.mem.trim(u8, raw, " \t\r\n");
    if (trimmed.len == 0) return .ok;

    const kv = kernelVersion() orelse return .unknown_kernel;

    const okay = semver.satisfies(kv, trimmed) catch return .{ .bad_constraint = trimmed };
    if (okay) return .ok;

    return .{ .incompatible = .{ .required = trimmed, .kernel = banner.version() } };
}

/// A dev build ("0.0.0-dev") satisfies almost nothing, because 0.0.0 is below
/// every real floor. Contributors running the monorepo would be unable to
/// install any plugin, so the check is skipped for it — with the caveat that a
/// dev kernel is exactly where an incompatibility is most likely to be found.
///
/// HKM_PLUGIN_IGNORE_KERNEL=1 forces the same bypass for a real version, for the
/// case where you know better than the constraint and need to move on.
pub fn gateBypassed(env: *EnvMap) bool {
    if (util.envIsTruthy(env, "HKM_PLUGIN_IGNORE_KERNEL")) return true;

    const kv = kernelVersion() orelse return false;
    return kv.major == 0 and kv.minor == 0 and kv.patch == 0 and kv.pre.len > 0;
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test "slug is the lower-cased folder unless the repo spells it differently" {
    const a = std.testing.allocator;

    const auth = try slugFor(a, "Auth");
    defer a.free(auth);
    try std.testing.expectEqualStrings("auth", auth);

    // The irregular ones are the whole reason the table exists.
    const social = try slugFor(a, "SocialAuth");
    defer a.free(social);
    try std.testing.expectEqualStrings("social-auth", social);

    const seo = try slugFor(a, "SiteSEO");
    defer a.free(seo);
    try std.testing.expectEqualStrings("siteseo", seo);

    const vite = try slugFor(a, "ViteManifest");
    defer a.free(vite);
    try std.testing.expectEqualStrings("vitemanifest", vite);

    const oauth = try slugFor(a, "OAuth2");
    defer a.free(oauth);
    try std.testing.expectEqualStrings("oauth2", oauth);
}

test "slug lookup is case-insensitive" {
    const a = std.testing.allocator;
    const s = try slugFor(a, "socialauth");
    defer a.free(s);
    try std.testing.expectEqualStrings("social-auth", s);
}

test "an absent constraint never blocks installation" {
    // Every plugin predating this field declares nothing. Failing closed would
    // make all of them uninstallable at once.
    try std.testing.expect(checkKernel(null) == .ok);
    try std.testing.expect(checkKernel("") == .ok);
    try std.testing.expect(checkKernel("   ") == .ok);
}

test "a malformed constraint is refused rather than ignored" {
    // Someone tried to express a requirement and got it wrong. Treating that as
    // "no requirement" is precisely the silent failure this check exists for.
    const c = checkKernel(">=not-a-version");
    try std.testing.expect(c == .bad_constraint);
}
