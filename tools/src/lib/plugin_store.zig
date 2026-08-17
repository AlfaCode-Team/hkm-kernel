//! The global plugin cache: one download per (plugin, version, origin),
//! shared by every project on the machine.
//!
//! A project does not own the plugins it uses — it references them. Project A
//! installing Auth v1.2.0 downloads it once; project B wanting the same version
//! links at what is already there and downloads nothing. That is the whole
//! point of a store, and it only holds if the store is GLOBAL: when it followed
//! the install target, third-party plugins landed under the project and every
//! project kept its own copy of identical bytes.
//!
//! ## Layout
//!
//!     <store>/<Name>/<version>-<origin-hash>/
//!
//! The version alone is not a safe key. Two repositories can both publish
//! `v1.0.0` of a plugin called Logger — a fork and its upstream, a private
//! mirror and the public original — and keying on the version alone would give
//! the second one the FIRST one's files, silently, with no error anywhere. The
//! hash is of the remote URL, so those are different directories.
//!
//! It is a hash of the ORIGIN rather than of the content because the lookup has
//! to happen BEFORE anything is downloaded — the question "do I already have
//! this?" is asked when all that is known is the remote and the tag. A content
//! hash could only be computed after the download it is meant to avoid.

const std = @import("std");
const util = @import("util.zig");

const Dir = std.Io.Dir;
const Io = std.Io;
const EnvMap = std.process.Environ.Map;

/// Directory name under the store root, so it stays recognisable in `ls`.
pub const dir_name = "plugin-store";

/// Where the cache lives.
///
/// Resolution order, most explicit first:
///
///   1. HKM_PLUGIN_STORE          — env, or `hkm plugins store --set` (which
///                                  writes it to the same config the launcher
///                                  loads into the environment)
///   2. $XDG_CACHE_HOME/hkm/…     — the conventional per-user cache
///   3. $HOME/.cache/hkm/…        — same, when XDG_CACHE_HOME is unset
///   4. <fallback>/plugin-store   — the caller's kernel root, for a machine
///                                  with no HOME (containers, CI)
///
/// A cache directory is the right home: the contents are re-downloadable,
/// per-user, and safe for a cleaner to delete — losing it costs a re-fetch,
/// never a project.
pub fn root(allocator: std.mem.Allocator, env: *EnvMap, fallback: []const u8) ![]const u8 {
    if (env.get("HKM_PLUGIN_STORE")) |v| {
        const t = std.mem.trim(u8, v, " \t\r\n");
        if (t.len > 0) return allocator.dupe(u8, util.trimSlash(t));
    }

    if (env.get("XDG_CACHE_HOME")) |x| {
        const t = std.mem.trim(u8, x, " \t\r\n");
        if (t.len > 0) return std.fmt.allocPrint(allocator, "{s}/hkm/{s}", .{ util.trimSlash(t), dir_name });
    }

    if (env.get("HOME")) |h| {
        const t = std.mem.trim(u8, h, " \t\r\n");
        if (t.len > 0) return std.fmt.allocPrint(allocator, "{s}/.cache/hkm/{s}", .{ util.trimSlash(t), dir_name });
    }

    return std.fmt.allocPrint(allocator, "{s}/{s}", .{ util.trimSlash(fallback), dir_name });
}

/// Short, stable hash of a remote URL.
///
/// SHA-256 truncated to 8 hex characters. Truncation is fine here: this
/// separates a handful of origins for the same plugin, it is not a security
/// boundary, and a collision would need two remotes whose digests share 32
/// bits AND that publish the same version of the same plugin name.
///
/// The URL is normalised first so `…/plugin.git`, `…/plugin` and `…/plugin/`
/// are one entry rather than three copies of identical bytes.
pub fn originHash(allocator: std.mem.Allocator, remote: []const u8) ![]const u8 {
    var s = std.mem.trim(u8, remote, " \t\r\n");
    s = std.mem.trimEnd(u8, s, "/");
    if (std.mem.endsWith(u8, s, ".git")) s = s[0 .. s.len - 4];

    var digest: [32]u8 = undefined;
    var h = std.crypto.hash.sha2.Sha256.init(.{});
    // Case-insensitively: a host is case-insensitive, and GitHub treats the
    // owner/repo path that way too, so differing only in case is the same repo.
    var buf: [256]u8 = undefined;
    var i: usize = 0;
    while (i < s.len) {
        const n = @min(buf.len, s.len - i);
        for (0..n) |j| {
            const c = s[i + j];
            buf[j] = if (c >= 'A' and c <= 'Z') c - 'A' + 'a' else c;
        }
        h.update(buf[0..n]);
        i += n;
    }
    h.final(&digest);

    return std.fmt.allocPrint(allocator, "{x}", .{digest[0..4]});
}

/// The directory name for one cached version: `<version>-<origin-hash>`.
///
/// An empty remote yields the bare version. That keeps entries written before
/// origin hashing readable, and means a caller with no remote to offer still
/// gets a usable (if less precise) key rather than an error.
pub fn versionKey(allocator: std.mem.Allocator, version: []const u8, remote: []const u8) ![]const u8 {
    if (remote.len == 0) return allocator.dupe(u8, version);
    const h = try originHash(allocator, remote);
    // Freed here rather than left to the caller's arena: this is also called
    // from tests and from long-lived loops, where an intermediate that only
    // ever gets formatted into the result has no reason to outlive it.
    defer allocator.free(h);
    return std.fmt.allocPrint(allocator, "{s}-{s}", .{ version, h });
}

/// Full path to one cached version of one plugin.
pub fn entryDir(
    allocator: std.mem.Allocator,
    env: *EnvMap,
    fallback: []const u8,
    name: []const u8,
    version: []const u8,
    remote: []const u8,
) ![]const u8 {
    const r = try root(allocator, env, fallback);
    const key = try versionKey(allocator, version, remote);
    return std.fs.path.join(allocator, &.{ r, name, key });
}

/// Path to a plugin's directory in the store (all its versions).
pub fn pluginDir(
    allocator: std.mem.Allocator,
    env: *EnvMap,
    fallback: []const u8,
    name: []const u8,
) ![]const u8 {
    const r = try root(allocator, env, fallback);
    return std.fs.path.join(allocator, &.{ r, name });
}

/// The VERSION part of a store entry name, without the origin hash.
///
/// Entry directories are `<version>-<hash>`, and the hash is an implementation
/// detail of the cache — showing it in "updated v2.0.0-42f5f5a5 → v2.0.1"
/// exposes a name the user never typed and cannot look up.
pub fn versionOf(entry: []const u8) []const u8 {
    const dash = std.mem.lastIndexOfScalar(u8, entry, '-') orelse return entry;
    const suffix = entry[dash + 1 ..];
    if (suffix.len != 8) return entry; // not our hash — part of the version
    for (suffix) |c| {
        const hex = (c >= '0' and c <= '9') or (c >= 'a' and c <= 'f');
        if (!hex) return entry;
    }
    return entry[0..dash];
}

/// Does `dir` (a `<version>-<hash>` entry name) hold this version, whatever its
/// origin? Used by prune and by "is any copy of this version present" checks,
/// where the origin is not known or does not matter.
pub fn entryIsVersion(entry: []const u8, version: []const u8) bool {
    if (std.mem.eql(u8, entry, version)) return true; // pre-hash entry
    if (!std.mem.startsWith(u8, entry, version)) return false;
    return entry.len > version.len and entry[version.len] == '-';
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test "the same repo spelled differently is one cache entry" {
    const a = std.testing.allocator;

    const forms = [_][]const u8{
        "https://github.com/AlfaCode-Team/hkm-plugin-logger.git",
        "https://github.com/AlfaCode-Team/hkm-plugin-logger",
        "https://github.com/AlfaCode-Team/hkm-plugin-logger/",
        "https://github.com/alfacode-team/hkm-plugin-logger.git",
    };
    const first = try originHash(a, forms[0]);
    defer a.free(first);
    for (forms[1..]) |f| {
        const h = try originHash(a, f);
        defer a.free(h);
        try std.testing.expectEqualStrings(first, h);
    }
}

test "different origins of the same version never share a directory" {
    const a = std.testing.allocator;

    // The failure this prevents: a fork and its upstream both publishing
    // v1.0.0, the second silently getting the first's files.
    const upstream = try versionKey(a, "v1.0.0", "https://github.com/AlfaCode-Team/hkm-plugin-logger.git");
    defer a.free(upstream);
    const fork = try versionKey(a, "v1.0.0", "https://github.com/someone/hkm-plugin-logger.git");
    defer a.free(fork);

    try std.testing.expect(!std.mem.eql(u8, upstream, fork));
    try std.testing.expect(std.mem.startsWith(u8, upstream, "v1.0.0-"));
    try std.testing.expect(std.mem.startsWith(u8, fork, "v1.0.0-"));
}

test "an entry is recognised as its version, hashed or not" {
    try std.testing.expect(entryIsVersion("v1.0.0-1a2b3c4d", "v1.0.0"));
    try std.testing.expect(entryIsVersion("v1.0.0", "v1.0.0")); // written before hashing
    // v1.0.10 must not be read as v1.0.1 — the separator is what stops it.
    try std.testing.expect(!entryIsVersion("v1.0.10", "v1.0.1"));
    try std.testing.expect(!entryIsVersion("v2.0.0-1a2b3c4d", "v1.0.0"));
}

test "an explicit HKM_PLUGIN_STORE wins over every default" {
    const a = std.testing.allocator;
    var env = EnvMap.init(a);
    defer env.deinit();

    try env.put("HOME", "/home/someone");
    const by_home = try root(a, &env, "/opt/hkm");
    defer a.free(by_home);
    try std.testing.expectEqualStrings("/home/someone/.cache/hkm/plugin-store", by_home);

    try env.put("HKM_PLUGIN_STORE", "/srv/shared/plugins/");
    const explicit = try root(a, &env, "/opt/hkm");
    defer a.free(explicit);
    // Trailing slash trimmed, so joins never produce a doubled separator.
    try std.testing.expectEqualStrings("/srv/shared/plugins", explicit);
}

test "the origin hash is stripped for display" {
    try std.testing.expectEqualStrings("v2.0.0", versionOf("v2.0.0-42f5f5a5"));
    try std.testing.expectEqualStrings("v2.0.0", versionOf("v2.0.0"));
    // A pre-release suffix is part of the version, not a hash.
    try std.testing.expectEqualStrings("v1.0.0-beta", versionOf("v1.0.0-beta"));
    // Eight chars but not hex.
    try std.testing.expectEqualStrings("v1.0.0-zzzzzzzz", versionOf("v1.0.0-zzzzzzzz"));
}
