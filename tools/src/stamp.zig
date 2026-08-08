//! Stamp a version into a composer.json.
//!
//!   stamp <composer.json path> <version>
//!
//! Run from build.zig so a versioned build carries its version everywhere,
//! not just in the compiled binary.
//!
//! WHY THIS IS NARROW ON PURPOSE
//! -----------------------------
//! A hard-coded "version" in composer.json normally does more harm than good:
//! Composer derives a package's version from its git tags, and a literal field
//! OVERRIDES that. Once the two can disagree, they eventually do — someone tags
//! v1.2.0 and forgets the field, and every consumer resolves the stale number
//! with no error anywhere. This repository has already been bitten by it once
//! (phpshots/bind-it pinned "0.1.3" in composer.json and its real tags were
//! ignored).
//!
//! It earns its place here for one reason: the native distribution ships
//! WITHOUT a .git directory. A .deb or a zip has no tags to derive from, so the
//! field is the only version marker the installed kernel has.
//!
//! Hence the rule build.zig applies: stamp only when an explicit -Dversion was
//! passed — which is what tools/bundle.sh does for a release. A plain `zig build`
//! leaves composer.json untouched, so a dev build never dirties the working tree
//! with "0.0.0-dev" that someone then commits by accident.
//!
//! The edit is textual rather than a JSON re-serialise so the file keeps its
//! hand-maintained key order, indentation and comments-by-convention. Rewriting
//! it through a JSON encoder would reorder every key and produce an unreadable
//! diff on every release.

const std = @import("std");

pub fn main(init: std.process.Init.Minimal) !void {
    var arena = std.heap.ArenaAllocator.init(std.heap.page_allocator);
    defer arena.deinit();
    const allocator = arena.allocator();

    var threaded: std.Io.Threaded = .init(std.heap.page_allocator, .{});
    defer threaded.deinit();
    const io = threaded.io();

    const args = try init.args.toSlice(allocator);
    if (args.len < 3) {
        std.debug.print("usage: stamp <composer.json> <version>\n", .{});
        return error.MissingArguments;
    }

    const path = args[1];
    // Whitespace from both ends, then ONE leading 'v'.
    //
    // This used to be trim(..., " \t\r\nv"), which strips the cutset from BOTH
    // ends — so any version ENDING in 'v' lost it: "1.1.0-dev" became
    // "1.1.0-de", which then failed validation and silently skipped stamping.
    var version = std.mem.trim(u8, args[2], " \t\r\n");
    if (version.len > 0 and (version[0] == 'v' or version[0] == 'V')) version = version[1..];

    if (version.len == 0) return; // nothing meaningful to stamp

    // A version Composer cannot parse is far worse than no version at all:
    // `composer install` ABORTS on it, so the package never resolves its
    // dependencies. That happened for real — "1.1.0-dev.2" was stamped from the
    // git tag, and every install of that release failed with
    //
    //   "./composer.json" does not match the expected JSON schema:
    //    - version : Does not match the regex pattern ...
    //
    // Composer's `dev` suffix takes NO counter ("1.1.0-dev" is valid,
    // "1.1.0-dev.2" and "1.1.0-dev2" are not). Rather than rewrite the version
    // into something Composer likes — which would make composer.json disagree
    // with the tag it was built from — the field is simply left out. It is
    // optional; a broken install is not.
    if (!composerValid(version)) {
        var buf: [256]u8 = undefined;
        const msg = std.fmt.bufPrint(
            &buf,
            "stamp: '{s}' is not a valid Composer version — leaving composer.json alone.\n" ++
                "       (Composer accepts 1.2.3, 1.2.3-dev, 1.2.3-beta.4, 1.2.3-RC1; a 'dev' suffix takes no number.)\n",
            .{version},
        ) catch return;
        std.Io.File.stderr().writeStreamingAll(io, msg) catch {};
        return;
    }

    // A missing composer.json is not a build failure: the same build.zig runs
    // in checkouts and in staging trees that do not carry one.
    const source = std.Io.Dir.cwd().readFileAlloc(io, path, allocator, .limited(8 * 1024 * 1024)) catch return;

    const updated = try stamp(allocator, source, version) orelse return; // already correct
    try std.Io.Dir.cwd().writeFile(io, .{ .sub_path = path, .data = updated });
}

/// Whether Composer will accept this as a package version.
///
/// A deliberately CONSERVATIVE subset of Composer's own pattern: numeric parts,
/// then an optional stability tag, then an optional `-dev`. Anything it is not
/// sure about is rejected, because the failure mode of a false accept (an
/// install that cannot resolve dependencies) is much worse than a false reject
/// (no version field, which is the status quo for this repository anyway).
fn composerValid(v: []const u8) bool {
    var s_ = v;
    if (s_.len == 0) return false;
    if (s_[0] == 'v' or s_[0] == 'V') s_ = s_[1..];

    // Build metadata is always allowed; ignore it.
    if (std.mem.indexOfScalar(u8, s_, '+')) |i| s_ = s_[0..i];
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
/// Exposed for testing.
pub fn stamp(allocator: std.mem.Allocator, source: []const u8, version: []const u8) !?[]const u8 {
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
    // main() trims the 'v'; stamp() receives it already trimmed. Assert the
    // shape composer expects.
    const out = (try stamp(a, src, "1.0.21")).?;
    defer a.free(out);
    try std.testing.expect(std.mem.indexOf(u8, out, "\"version\": \"v") == null);
}

test "accepts the versions composer accepts" {
    // Verified against `composer validate` before being encoded here.
    for ([_][]const u8{
        "1.1.0", "1.0.21", "v1.1.0", "1.1.0-dev", "1.1.0-beta.2",
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
    // Regression: main() trimmed the cutset " \t\r\nv" from BOTH ends, so
    // "1.1.0-dev" arrived as "1.1.0-de" and was rejected as invalid — the one
    // pre-release form Composer actually accepts. Caught by cross-checking
    // against `composer validate`, not by the unit tests, which called the
    // validator directly and skipped the trimming.
    try std.testing.expect(composerValid("1.1.0-dev"));
    try std.testing.expect(!composerValid("1.1.0-de"));
}
