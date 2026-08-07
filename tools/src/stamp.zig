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
    const version = std.mem.trim(u8, args[2], " \t\r\nv");

    if (version.len == 0) return; // nothing meaningful to stamp

    // A missing composer.json is not a build failure: the same build.zig runs
    // in checkouts and in staging trees that do not carry one.
    const source = std.Io.Dir.cwd().readFileAlloc(io, path, allocator, .limited(8 * 1024 * 1024)) catch return;

    const updated = try stamp(allocator, source, version) orelse return; // already correct
    try std.Io.Dir.cwd().writeFile(io, .{ .sub_path = path, .data = updated });
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
