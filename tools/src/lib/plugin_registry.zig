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

/// The canonical FOLDER name for a plugin, whatever spelling the user typed.
///
/// The install directory has to match the PSR-4 namespace exactly: `Plugins\`
/// maps to plugins/, so `Plugins\Crypto\Provider` must live in plugins/Crypto.
/// Installing to whatever the user typed meant `hkm plugins install crypto`
/// produced plugins/crypto — the files were there, the autoloader could not see
/// them, and the failure surfaced later as a missing Provider class.
///
/// Resolution order: an override table entry (matched on either spelling), then
/// studly-case. The table is what makes `oauth2` → `OAuth2` and `siteseo` →
/// `SiteSEO` rather than the `Oauth2` / `Siteseo` studly-case would produce.
pub fn canonicalName(allocator: std.mem.Allocator, input: []const u8) ![]const u8 {
    for (slug_overrides) |o| {
        if (util.eqlIgnoreCase(input, o.folder) or util.eqlIgnoreCase(input, o.slug)) {
            return allocator.dupe(u8, o.folder);
        }
    }
    return util.studly(allocator, input);
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

/// Does this look like a git remote rather than a plugin name?
///
/// Plugin names are bare identifiers (`auth`, `SocialAuth`), so anything
/// carrying a scheme, an `scp`-style `host:path`, or a filesystem path is a
/// remote the user wants fetched directly. Checked in that order because
/// `git@github.com:Org/repo.git` has no scheme and would otherwise be missed.
pub fn isRemoteUrl(input: []const u8) bool {
    const s = std.mem.trim(u8, input, " \t\r\n");
    if (s.len == 0) return false;

    for ([_][]const u8{ "https://", "http://", "ssh://", "git://", "file://" }) |scheme| {
        if (std.mem.startsWith(u8, s, scheme)) return true;
    }

    // scp-style: user@host:path — the ':' must come after the '@' and be
    // followed by something, or it is a plain name with a stray colon.
    if (std.mem.indexOfScalar(u8, s, '@')) |at| {
        if (std.mem.indexOfScalarPos(u8, s, at, ':')) |colon| {
            if (colon + 1 < s.len) return true;
        }
    }

    // A local clone, bare or otherwise.
    if (s[0] == '/' or std.mem.startsWith(u8, s, "./") or std.mem.startsWith(u8, s, "../")) return true;

    return false;
}

/// The plugin FOLDER name implied by a remote URL.
///
/// Takes the repository basename, drops a `.git` suffix and the `hkm-plugin-`
/// prefix the first-party repos carry, then canonicalises — so
/// `https://github.com/AlfaCode-Team/hkm-plugin-social-auth.git` yields
/// `SocialAuth`, exactly as `hkm plugins install social-auth` would.
///
/// This is a starting guess, not the final answer: the authority on a plugin's
/// name is the `name` field of its own module.json, which cannot be read until
/// the repository has been fetched. The installer re-checks it there and moves
/// the plugin if the two disagree — a repo whose directory name does not match
/// its namespace would otherwise install to a path PSR-4 never looks in.
pub fn nameFromRemote(allocator: std.mem.Allocator, url: []const u8) ![]const u8 {
    var s = std.mem.trim(u8, url, " \t\r\n");
    s = std.mem.trimEnd(u8, s, "/");

    // Basename, for either separator: scp-style remotes use ':' before the path.
    if (std.mem.lastIndexOfAny(u8, s, "/:")) |i| s = s[i + 1 ..];

    if (std.mem.endsWith(u8, s, ".git")) s = s[0 .. s.len - 4];
    if (std.mem.startsWith(u8, s, "hkm-plugin-")) s = s["hkm-plugin-".len ..];

    if (s.len == 0) return error.UnnamedRemote;
    return canonicalName(allocator, s);
}

/// Is an EXPLICIT remote one of the first-party packages?
///
/// Same question as `isFirstParty`, asked of a URL the user supplied rather
/// than of the environment — it decides whether the plugin lands in the shared
/// kernel or in the project. The answer is yes only for the configured org's
/// `hkm-plugin-*` repositories on github: a fork, a mirror, or anything else is
/// the project's business, and installing it into the kernel would impose one
/// project's choice on every other project on the machine.
pub fn remoteIsFirstParty(env: *EnvMap, url: []const u8) bool {
    const s = std.mem.trim(u8, url, " \t\r\n");
    if (std.mem.indexOf(u8, s, "github.com") == null) return false;

    // The org must be the path segment immediately BEFORE the repo, not merely
    // present somewhere in the URL — otherwise a mirror at
    // git.example.com/AlfaCode-Team/… would pass by containing the name.
    // Matched by hand rather than by building "<org>/hkm-plugin-": this is
    // called from paths that have no allocator to spare and no place to free.
    const at = std.mem.indexOf(u8, s, "/hkm-plugin-") orelse return false;
    const before = s[0..at];
    const o = org(env);
    if (before.len < o.len) return false;
    if (!std.mem.eql(u8, before[before.len - o.len ..], o)) return false;

    // What precedes the org must be a separator, so "not-AlfaCode-Team" fails.
    if (before.len == o.len) return true;
    const sep = before[before.len - o.len - 1];
    return sep == '/' or sep == ':';
}

/// Is this plugin one of the first-party AlfaCode-Team packages?
///
/// Decides WHERE it installs, which in turn decides which composer autoloader
/// resolves it:
///
///   first-party  -> <kernel>/plugins   — the kernel's composer maps Plugins\
///                                        there, so one copy serves every
///                                        project on the machine.
///   third-party  -> <project>/plugins  — the project's own composer maps
///                                        Plugins\ there, so it stays local to
///                                        the project that asked for it.
///
/// Both autoloaders are registered at runtime and each resolves its own
/// directory, so the two never collide.
///
/// "First-party" means the remote resolves to the default org with no override.
/// Pointing HKM_PLUGIN_ORG or HKM_PLUGIN_REMOTE elsewhere makes it third-party
/// by definition: it is no longer a package this kernel vouches for, and
/// installing it into the shared kernel would impose one user's fork on every
/// project on the machine.
pub fn isFirstParty(env: *EnvMap) bool {
    if (env.get("HKM_PLUGIN_REMOTE")) |t| {
        if (std.mem.trim(u8, t, " \t\r\n").len > 0) return false;
    }
    return std.mem.eql(u8, org(env), default_org);
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

test "a remote URL is told apart from a plugin name" {
    // Names are bare identifiers; anything with a scheme, an scp-style
    // host:path, or a filesystem path is a remote.
    try std.testing.expect(isRemoteUrl("https://github.com/AlfaCode-Team/hkm-plugin-logger.git"));
    try std.testing.expect(isRemoteUrl("http://git.internal/hkm/logger.git"));
    try std.testing.expect(isRemoteUrl("ssh://git@host/team/logger.git"));
    try std.testing.expect(isRemoteUrl("git@github.com:AlfaCode-Team/hkm-plugin-logger.git"));
    try std.testing.expect(isRemoteUrl("/srv/git/logger.git"));
    try std.testing.expect(isRemoteUrl("./vendor-fork"));

    try std.testing.expect(!isRemoteUrl("logger"));
    try std.testing.expect(!isRemoteUrl("SocialAuth"));
    try std.testing.expect(!isRemoteUrl(""));
}

test "the plugin name comes out of the repository name" {
    const a = std.testing.allocator;

    // The hkm-plugin- prefix and the .git suffix are both dropped, and the
    // result goes through canonicalName — so a URL install lands in exactly the
    // same directory as installing the same plugin by name.
    const cases = [_]struct { url: []const u8, want: []const u8 }{
        .{ .url = "https://github.com/AlfaCode-Team/hkm-plugin-logger.git", .want = "Logger" },
        .{ .url = "https://github.com/AlfaCode-Team/hkm-plugin-social-auth.git", .want = "SocialAuth" },
        .{ .url = "https://github.com/AlfaCode-Team/hkm-plugin-oauth2", .want = "OAuth2" },
        .{ .url = "git@github.com:AlfaCode-Team/hkm-plugin-siteseo.git", .want = "SiteSEO" },
        // Trailing slash, and a repo that carries no prefix at all.
        .{ .url = "https://example.com/team/billing/", .want = "Billing" },
    };
    for (cases) |c| {
        const got = try nameFromRemote(a, c.url);
        defer a.free(got);
        try std.testing.expectEqualStrings(c.want, got);
    }
}

test "only the configured org's plugin repos count as first-party" {
    var env = EnvMap.init(std.testing.allocator);
    defer env.deinit();

    // First-party decides that the plugin lands in the SHARED kernel, where it
    // affects every project on the machine — so a fork must not qualify merely
    // by being a copy of one.
    try std.testing.expect(remoteIsFirstParty(&env, "https://github.com/AlfaCode-Team/hkm-plugin-logger.git"));
    try std.testing.expect(remoteIsFirstParty(&env, "git@github.com:AlfaCode-Team/hkm-plugin-logger.git"));
    try std.testing.expect(!remoteIsFirstParty(&env, "https://github.com/someone-else/hkm-plugin-logger.git"));
    // Contains the org name, but as a suffix of a different one.
    try std.testing.expect(!remoteIsFirstParty(&env, "https://github.com/not-AlfaCode-Team/hkm-plugin-logger.git"));
    try std.testing.expect(!remoteIsFirstParty(&env, "https://git.internal/AlfaCode-Team/hkm-plugin-logger.git"));
    try std.testing.expect(!remoteIsFirstParty(&env, "/srv/git/hkm-plugin-logger.git"));
}
