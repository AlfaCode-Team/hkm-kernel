//! Read and write the `"version"` field of a composer.json.
//!
//! Extracted from src/stamp.zig so that BOTH the build-time stamper and
//! `hkm upgrade` / `hkm version` share one implementation. They must: the
//! stamper writes the field, and the CLI reads it back as the only version
//! marker an installed kernel has. Two copies of the parsing rules would
//! eventually disagree about what counts as a version, and the visible symptom
//! would be an upgrade that reports the wrong number.
//!
//! WHY THE FIELD EXISTS AT ALL (IT IS NORMALLY A LIABILITY)
//! -------------------------------------------------------
//! A hard-coded "version" in composer.json usually does more harm than good:
//! Composer derives a package's version from its git tags, and a literal field
//! OVERRIDES that. Once the two can disagree, they eventually do — someone tags
//! v1.2.0 and forgets the field, and every consumer resolves the stale number
//! with no error anywhere. This repository has already been bitten by it once
//! (phpshots/bind-it pinned "0.1.3" and its real tags were ignored).
//!
//! It earns its place here for one reason: the native distribution ships
//! WITHOUT a .git directory. A .deb or a tarball has no tags to derive from, so
//! the field is the only version marker the installed kernel has — which is
//! exactly what `hkm version` reports per install scope.

const std = @import("std");

const Io = std.Io;

// ---------------------------------------------------------------------------
// Reading
// ---------------------------------------------------------------------------

/// The value of the top-level "version" key, or null when the file has none.
///
/// Textual rather than a JSON parse for the same reason `stamp` is: this is
/// called on files written by hand and by the stamper, and the caller only ever
/// wants one scalar. A parse would allocate the whole document to answer it.
pub fn parse(source: []const u8) ?[]const u8 {
    const span = findVersionValue(source) orelse return null;
    const v = source[span.start..span.end];
    return if (v.len == 0) null else v;
}

/// The version of the kernel installed at `root`, read from `<root>/composer.json`.
///
/// Null when the root has no composer.json (not an install), or when it has one
/// with no version — which is the normal state of a GIT CHECKOUT, since
/// build.zig deliberately only stamps a release build.
pub fn ofKernel(allocator: std.mem.Allocator, io: Io, root: []const u8) ?[]const u8 {
    const path = std.fs.path.join(allocator, &.{ root, "composer.json" }) catch return null;
    const body = std.Io.Dir.cwd().readFileAlloc(io, path, allocator, .limited(1024 * 1024)) catch return null;
    const v = parse(body) orelse return null;
    return allocator.dupe(u8, v) catch null;
}

// ---------------------------------------------------------------------------
// Writing
// ---------------------------------------------------------------------------

/// Strip surrounding whitespace and ONE leading `v`, the form Composer wants.
///
/// One 'v', from the FRONT only. A `trim(..., "v")` would strip the cutset from
/// both ends, so any version ENDING in 'v' lost it: "1.1.0-dev" became
/// "1.1.0-de", which then failed validation and silently skipped stamping.
pub fn normalize(raw: []const u8) []const u8 {
    var v = std.mem.trim(u8, raw, " \t\r\n");
    if (v.len > 0 and (v[0] == 'v' or v[0] == 'V')) v = v[1..];
    return v;
}

/// Rewrite a `git describe` version as semver BUILD METADATA.
///
///     1.3.1-2-g34abb2c   →   1.3.1+2.g34abb2c
///
/// Composer rejects the first (its `-` suffix is a stability tag, and "2" is
/// not one) and accepts the second. The two carry identical information and,
/// crucially, identical PRECEDENCE: semver §10 excludes build metadata from
/// ordering, and lib/semver.zig drops everything after `+` — which is exactly
/// what `parseDescribed` already does with the `-2-g34abb2c` form. So this is a
/// change of spelling, not of meaning.
///
/// Null for anything that is not a describe version; the caller must not invent
/// a spelling for a version it does not recognise.
pub fn describeToComposer(allocator: std.mem.Allocator, version: []const u8) ?[]const u8 {
    const v = normalize(version);
    if (!isDescribeVersion(v)) return null;

    // Split at the '-' that begins the "<commits>-g<sha>" trailer: the second
    // '-' from the end, since isDescribeVersion has already established both.
    const g = std.mem.lastIndexOfScalar(u8, v, '-') orelse return null;
    const d = std.mem.lastIndexOfScalar(u8, v[0..g], '-') orelse return null;

    const base = v[0..d]; // "1.3.1"
    const commits = v[d + 1 .. g]; // "2"
    const sha = v[g + 1 ..]; // "g34abb2c"

    const out = std.fmt.allocPrint(allocator, "{s}+{s}.{s}", .{ base, commits, sha }) catch return null;
    return if (composerValid(out)) out else null;
}

/// Write `version` into `<dir>/composer.json`, best effort.
///
/// Returns true only when the file now carries a version. Used after a
/// `--local` install: the checkout's composer.json has NO version field (only a
/// release build is stamped, deliberately), so without this the freshly
/// installed kernel is permanently unable to report what it is — `hkm version`
/// reads "unstamped" forever and `hkm upgrade` has nothing to compare, which is
/// a large part of why a local install looked like it never upgraded.
///
/// A `git describe` version — the shape EVERY build between releases has — is
/// re-spelled as build metadata rather than dropped. That does not contradict
/// the release stamper's rule of "the exact tag or nothing": a release must
/// match the tag it claims to be, whereas a build two commits past v1.3.1
/// corresponds to no tag at all, and recording which commit it is beats
/// recording nothing.
pub fn writeTo(allocator: std.mem.Allocator, io: Io, dir: []const u8, version: []const u8) bool {
    var v = normalize(version);
    if (v.len == 0) return false;
    if (!composerValid(v)) {
        v = describeToComposer(allocator, v) orelse return false;
    }

    const path = std.fs.path.join(allocator, &.{ dir, "composer.json" }) catch return false;
    const source = std.Io.Dir.cwd().readFileAlloc(io, path, allocator, .limited(8 * 1024 * 1024)) catch return false;

    const updated = stamp(allocator, source, v) catch return false;
    if (updated) |out| {
        std.Io.Dir.cwd().writeFile(io, .{ .sub_path = path, .data = out }) catch return false;
        return true;
    }
    // null means "already correct" — which is still the outcome asked for.
    return true;
}

/// Semver build metadata: dot-separated identifiers of [0-9A-Za-z-], each
/// non-empty. Deliberately strict — this string is written verbatim into JSON.
fn validMetadata(meta: []const u8) bool {
    if (meta.len == 0) return false;

    var it = std.mem.splitScalar(u8, meta, '.');
    while (it.next()) |ident| {
        if (ident.len == 0) return false;
        for (ident) |c| {
            if (!std.ascii.isAlphanumeric(c) and c != '-') return false;
        }
    }
    return true;
}

/// Nothing that would break out of a JSON string, whatever validation decided.
/// composerValid() is the gate; this is the seatbelt, because the cost of being
/// wrong is a composer.json no install can parse.
fn jsonSafe(v: []const u8) bool {
    for (v) |c| {
        if (c == '"' or c == '\\' or c < 0x20 or c == 0x7f) return false;
    }
    return true;
}

/// Does this look like `git describe` output — "<version>-<commits>-g<sha>"?
///
/// Matched on the trailing "-<digits>-g<hex>" only, so a real pre-release
/// ("1.1.0-beta.1") is not mistaken for one and still gets a warning.
pub fn isDescribeVersion(v: []const u8) bool {
    const g = std.mem.lastIndexOfScalar(u8, v, '-') orelse return false;
    const sha = v[g + 1 ..];
    if (sha.len < 2 or sha[0] != 'g') return false;
    for (sha[1..]) |c| {
        if (!std.ascii.isHex(c)) return false;
    }

    const head = v[0..g];
    const d = std.mem.lastIndexOfScalar(u8, head, '-') orelse return false;
    const count = head[d + 1 ..];
    if (count.len == 0) return false;
    for (count) |c| {
        if (!std.ascii.isDigit(c)) return false;
    }
    return true;
}

/// Whether Composer will accept this as a package version.
///
/// A deliberately CONSERVATIVE subset of Composer's own pattern: numeric parts,
/// then an optional stability tag, then an optional `-dev`. Anything it is not
/// sure about is rejected, because the failure mode of a false accept (an
/// install that cannot resolve dependencies) is much worse than a false reject
/// (no version field, which is the status quo for a checkout anyway).
pub fn composerValid(v: []const u8) bool {
    var s_ = v;
    if (s_.len == 0) return false;
    if (s_[0] == 'v' or s_[0] == 'V') s_ = s_[1..];

    // Build metadata is allowed, but it still has to BE metadata. Discarding it
    // unchecked let anything through — `composerValid("1.1.0+\"")` returned
    // true, and stamp() writes the version raw between JSON quotes, so that one
    // input produced an unparseable composer.json. Semver defines metadata as
    // dot-separated [0-9A-Za-z-] identifiers; anything else is rejected.
    if (std.mem.indexOfScalar(u8, s_, '+')) |i| {
        if (!validMetadata(s_[i + 1 ..])) return false;
        s_ = s_[0..i];
    }
    if (s_.len == 0) return false;

    // 1-4 numeric components separated by '.' or '-'.
    var i: usize = 0;
    var parts: usize = 0;
    while (i < s_.len and parts < 4) {
        const start = i;
        while (i < s_.len and std.ascii.isDigit(s_[i])) i += 1;
        if (i == start) return false; // expected a number
        parts += 1;
        if (i < s_.len and (s_[i] == '.' or s_[i] == '-')) {
            // Only continue the numeric run when a digit follows.
            if (i + 1 < s_.len and std.ascii.isDigit(s_[i + 1])) {
                i += 1;
                continue;
            }
        }
        break;
    }
    if (parts == 0) return false;
    if (i == s_.len) return true; // plain numeric version

    // Optional separator before the stability tag.
    if (s_[i] == '.' or s_[i] == '-' or s_[i] == '_') i += 1;
    if (i == s_.len) return false; // trailing separator

    const tail = s_[i..];

    // Bare "dev" is the only form Composer accepts — no counter after it.
    if (std.ascii.eqlIgnoreCase(tail, "dev")) return true;
    if (std.ascii.eqlIgnoreCase(tail, "x-dev")) return true;

    // stability tag, optionally followed by (.|-)?digits, repeated.
    const tags = [_][]const u8{ "stable", "beta", "alpha", "patch", "rc", "pl", "b", "a", "p" };
    for (tags) |tag| {
        if (tail.len < tag.len) continue;
        if (!std.ascii.eqlIgnoreCase(tail[0..tag.len], tag)) continue;

        var rest = tail[tag.len..];
        while (rest.len > 0) {
            if (rest[0] == '.' or rest[0] == '-') rest = rest[1..];
            if (rest.len == 0) return false; // trailing separator
            const start = rest.len;
            while (rest.len > 0 and std.ascii.isDigit(rest[0])) rest = rest[1..];
            if (rest.len == start) return false; // expected digits
        }
        return true;
    }

    return false;
}

/// Return the file with `version` applied, or null when it is already correct.
///
/// The edit is textual rather than a JSON re-serialise so the file keeps its
/// hand-maintained key order and indentation. Rewriting it through a JSON
/// encoder would reorder every key and produce an unreadable diff per release.
pub fn stamp(allocator: std.mem.Allocator, source: []const u8, version: []const u8) !?[]const u8 {
    // The version is written raw between JSON quotes below, so refuse outright
    // anything that could terminate the string or embed a control character.
    if (!jsonSafe(version)) return null;

    if (findVersionValue(source)) |span| {
        if (std.mem.eql(u8, source[span.start..span.end], version)) return null; // no-op
        var out: std.ArrayList(u8) = .empty;
        try out.appendSlice(allocator, source[0..span.start]);
        try out.appendSlice(allocator, version);
        try out.appendSlice(allocator, source[span.end..]);
        return try out.toOwnedSlice(allocator);
    }

    // No "version" key: insert one directly after "name", which is where a
    // reader looks for it and where composer's own docs put it.
    const anchor = std.mem.indexOf(u8, source, "\"name\"") orelse return null;
    const line_end = std.mem.indexOfScalarPos(u8, source, anchor, '\n') orelse return null;

    const indent = detectIndent(source, anchor);

    var out: std.ArrayList(u8) = .empty;
    try out.appendSlice(allocator, source[0 .. line_end + 1]);
    try out.appendSlice(allocator, indent);
    try out.appendSlice(allocator, "\"version\": \"");
    try out.appendSlice(allocator, version);
    try out.appendSlice(allocator, "\",\n");
    try out.appendSlice(allocator, source[line_end + 1 ..]);
    return try out.toOwnedSlice(allocator);
}

const Span = struct { start: usize, end: usize };

/// Byte range of the STRING VALUE of a top-level "version" key.
fn findVersionValue(source: []const u8) ?Span {
    var search: usize = 0;
    while (std.mem.indexOfPos(u8, source, search, "\"version\"")) |key_at| {
        search = key_at + 9;

        // Step over whitespace and the colon.
        var i = key_at + 9;
        while (i < source.len and (source[i] == ' ' or source[i] == '\t')) i += 1;
        if (i >= source.len or source[i] != ':') continue;
        i += 1;
        while (i < source.len and (source[i] == ' ' or source[i] == '\t')) i += 1;
        if (i >= source.len or source[i] != '"') continue;

        const start = i + 1;
        const end = std.mem.indexOfScalarPos(u8, source, start, '"') orelse return null;
        return .{ .start = start, .end = end };
    }
    return null;
}

/// The leading whitespace of the line containing `pos`, so an inserted key
/// matches the file's existing indentation rather than imposing a new one.
fn detectIndent(source: []const u8, pos: usize) []const u8 {
    var line_start = pos;
    while (line_start > 0 and source[line_start - 1] != '\n') line_start -= 1;

    var i = line_start;
    while (i < source.len and (source[i] == ' ' or source[i] == '\t')) i += 1;
    return source[line_start..i];
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test "replaces an existing version value" {
    const a = std.testing.allocator;
    const src =
        \\{
        \\    "name": "alfacode-team/php-service-platform",
        \\    "version": "1.0.0",
        \\    "type": "library"
        \\}
    ;
    const out = (try stamp(a, src, "1.0.21")).?;
    defer a.free(out);

    try std.testing.expect(std.mem.indexOf(u8, out, "\"version\": \"1.0.21\"") != null);
    try std.testing.expect(std.mem.indexOf(u8, out, "1.0.0") == null);
    // Everything else must be untouched.
    try std.testing.expect(std.mem.indexOf(u8, out, "\"type\": \"library\"") != null);
}

test "inserts the key after name when absent, matching indentation" {
    const a = std.testing.allocator;
    const src =
        \\{
        \\    "name": "alfacode-team/php-service-platform",
        \\    "type": "library"
        \\}
    ;
    const out = (try stamp(a, src, "1.0.21")).?;
    defer a.free(out);

    try std.testing.expect(std.mem.indexOf(u8, out, "    \"version\": \"1.0.21\",\n") != null);
    // It must still parse.
    var arena = std.heap.ArenaAllocator.init(a);
    defer arena.deinit();
    const parsed = try std.json.parseFromSliceLeaky(std.json.Value, arena.allocator(), out, .{});
    try std.testing.expectEqualStrings("1.0.21", parsed.object.get("version").?.string);
}

test "an already-correct version is a no-op" {
    // Returning null keeps the build from rewriting the file (and dirtying the
    // working tree) on every single invocation.
    const a = std.testing.allocator;
    const src =
        \\{
        \\    "name": "x/y",
        \\    "version": "1.0.21"
        \\}
    ;
    try std.testing.expect((try stamp(a, src, "1.0.21")) == null);
}

test "does not mistake a nested version for the package's own" {
    // "require" blocks are full of version-looking keys; only a top-level
    // "version" KEY should ever be rewritten.
    const a = std.testing.allocator;
    const src =
        \\{
        \\    "name": "x/y",
        \\    "require": { "php": ">=8.4" }
        \\}
    ;
    const out = (try stamp(a, src, "2.0.0")).?;
    defer a.free(out);

    var arena = std.heap.ArenaAllocator.init(a);
    defer arena.deinit();
    const parsed = try std.json.parseFromSliceLeaky(std.json.Value, arena.allocator(), out, .{});
    try std.testing.expectEqualStrings("2.0.0", parsed.object.get("version").?.string);
    try std.testing.expectEqualStrings(">=8.4", parsed.object.get("require").?.object.get("php").?.string);
}

test "a leading v is stripped so composer sees a bare version" {
    const a = std.testing.allocator;
    const src =
        \\{
        \\    "name": "x/y"
        \\}
    ;
    const out = (try stamp(a, src, normalize("v1.0.21"))).?;
    defer a.free(out);
    try std.testing.expect(std.mem.indexOf(u8, out, "\"version\": \"v") == null);
    try std.testing.expect(std.mem.indexOf(u8, out, "\"version\": \"1.0.21\"") != null);
}

test "accepts the versions composer accepts" {
    // Verified against `composer validate` before being encoded here.
    for ([_][]const u8{
        "1.1.0",     "1.0.21",       "v1.1.0", "1.1.0-dev", "1.1.0-beta.2",
        "1.1.0-RC2", "1.1.0-alpha.2", "1.2.3.4", "1.1.0+meta",
    }) |v| {
        try std.testing.expect(composerValid(v));
    }
}

test "rejects the version that broke a real install" {
    // "1.1.0-dev.2" was stamped from a git tag and made `composer install`
    // abort on every machine that took the update. Composer's dev suffix takes
    // no counter.
    try std.testing.expect(!composerValid("1.1.0-dev.2"));
    try std.testing.expect(!composerValid("1.1.0-dev2"));
}

test "rejects anything it cannot vouch for" {
    for ([_][]const u8{
        "", "v", "abc", "1.1.0-", "1.1.0-nonsense", "1.1.0-beta.", "-1.0.0",
    }) |v| {
        try std.testing.expect(!composerValid(v));
    }
}

test "a version ending in 'v' keeps its last character" {
    // Regression: the trim used the cutset " \t\r\nv" on BOTH ends, so
    // "1.1.0-dev" arrived as "1.1.0-de" and was rejected as invalid — the one
    // pre-release form Composer actually accepts.
    try std.testing.expectEqualStrings("1.1.0-dev", normalize("v1.1.0-dev"));
    try std.testing.expect(composerValid("1.1.0-dev"));
    try std.testing.expect(!composerValid("1.1.0-de"));
}

test "build metadata is validated, not waved through" {
    // The bug: metadata was discarded unchecked, so this returned true — and
    // stamp() writes the version raw between JSON quotes, producing a
    // composer.json no install can parse.
    try std.testing.expect(!composerValid("1.1.0+\""));
    try std.testing.expect(!composerValid("1.1.0+a\\b"));
    try std.testing.expect(!composerValid("1.1.0+a\nb"));
    try std.testing.expect(!composerValid("1.1.0+")); // empty metadata
    try std.testing.expect(!composerValid("1.1.0+a..b")); // empty identifier
    try std.testing.expect(!composerValid("1.1.0+a b"));

    // …while real metadata still passes.
    try std.testing.expect(composerValid("1.1.0+build.1"));
    try std.testing.expect(composerValid("1.1.0+20260812"));
    try std.testing.expect(composerValid("1.1.0+g29dccfb"));
    try std.testing.expect(composerValid("1.1.0-beta.1+exp.sha.5114f85"));
}

test "stamp refuses a version that could break out of the JSON string" {
    const a = std.testing.allocator;
    const src =
        \\{
        \\    "name": "acme/pkg",
        \\    "type": "library"
        \\}
    ;
    for ([_][]const u8{ "1.0.0+\"", "1.0.0\\", "1.0.0\n", "1.0.0\x7f" }) |bad| {
        try std.testing.expect((try stamp(a, src, bad)) == null);
    }
}

test "a stamped composer.json is still parseable JSON" {
    const a = std.testing.allocator;
    const src =
        \\{
        \\    "name": "acme/pkg",
        \\    "type": "library"
        \\}
    ;
    const out = (try stamp(a, src, "1.2.0")) orelse return error.ExpectedOutput;
    defer a.free(out);

    const parsed = try std.json.parseFromSlice(std.json.Value, a, out, .{});
    defer parsed.deinit();
    try std.testing.expectEqualStrings("1.2.0", parsed.value.object.get("version").?.string);
}

test "a git describe version is recognised so dev builds stay quiet" {
    try std.testing.expect(isDescribeVersion("1.1.0-dev.2-12-g29dccfb"));
    try std.testing.expect(isDescribeVersion("1.0.21-138-gbdbbf34"));

    // A real pre-release must NOT be mistaken for one: those are release
    // intents, and silently skipping them is how a release ships unstamped.
    try std.testing.expect(!isDescribeVersion("1.1.0-beta.1"));
    try std.testing.expect(!isDescribeVersion("1.1.0-dev.2"));
    try std.testing.expect(!isDescribeVersion("1.1.0"));
    try std.testing.expect(!isDescribeVersion("1.1.0-12-gzz"));
}

test "parse reads back what stamp wrote" {
    // The read and the write are two halves of one contract: `hkm version`
    // reports what a release build stamped. A change to either that breaks the
    // round trip makes every installed kernel report "unknown".
    const a = std.testing.allocator;
    const src =
        \\{
        \\    "name": "acme/pkg"
        \\}
    ;
    const out = (try stamp(a, src, "1.3.1")).?;
    defer a.free(out);
    try std.testing.expectEqualStrings("1.3.1", parse(out).?);
}

test "a git describe version is re-spelled as composer-valid build metadata" {
    // The version every build between releases carries. Composer rejects the
    // "-2-g34abb2c" form, so a --local install used to record nothing at all
    // and could never report what it was.
    var arena = std.heap.ArenaAllocator.init(std.testing.allocator);
    defer arena.deinit();
    const a = arena.allocator();

    const out = describeToComposer(a, "v1.3.1-2-g34abb2c").?;
    try std.testing.expectEqualStrings("1.3.1+2.g34abb2c", out);
    try std.testing.expect(composerValid(out));

    const long = describeToComposer(a, "1.0.21-138-gbdbbf34").?;
    try std.testing.expectEqualStrings("1.0.21+138.gbdbbf34", long);
    try std.testing.expect(composerValid(long));
}

test "the re-spelling preserves precedence exactly" {
    // The whole justification: semver excludes build metadata from ordering,
    // and lib/semver.zig's parseDescribed already collapses the '-' form to the
    // same base version. If these two ever disagreed, `hkm upgrade` would rank a
    // local build differently depending on which spelling happened to be on
    // disk.
    const semver = @import("semver.zig");
    var arena = std.heap.ArenaAllocator.init(std.testing.allocator);
    defer arena.deinit();
    const a = arena.allocator();

    const described = semver.parseDescribed("1.3.1-2-g34abb2c").?;
    const respelled = semver.parseDescribed(describeToComposer(a, "1.3.1-2-g34abb2c").?).?;
    try std.testing.expectEqual(std.math.Order.eq, described.order(respelled));
    try std.testing.expectEqual(std.math.Order.eq, respelled.order(semver.Version.parse("1.3.1").?));
}

test "describeToComposer invents nothing for a version it does not recognise" {
    var arena = std.heap.ArenaAllocator.init(std.testing.allocator);
    defer arena.deinit();
    const a = arena.allocator();

    // A real pre-release is a release intent, not a describe trailer — silently
    // rewriting one would make composer.json disagree with the tag it claims.
    try std.testing.expect(describeToComposer(a, "1.3.1-beta.1") == null);
    try std.testing.expect(describeToComposer(a, "1.3.1") == null);
    try std.testing.expect(describeToComposer(a, "garbage") == null);
}

test "parse returns null for a checkout composer.json with no version" {
    // The normal state of this repo: build.zig only stamps a release build, so
    // a checkout legitimately has no version and must not report a wrong one.
    const src =
        \\{
        \\    "name": "alfacode-team/php-service-platform",
        \\    "require": { "php": ">=8.4" }
        \\}
    ;
    try std.testing.expect(parse(src) == null);
}
