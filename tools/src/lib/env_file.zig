//! Read a project's `.env` as records rather than lines, so it can be audited
//! and rewritten without losing what a person wrote in it.
//!
//! ## Why a record, not a line
//!
//! A dotenv file is not a key/value store on disk — it is a document. The
//! comment above a key explains it, the blank line below it separates a
//! section, and a key that appears twice is a bug that no parser reports
//! because the loader silently resolves it. Anything that rewrites the file has
//! to preserve the first two while surfacing the third.
//!
//! So a `Record` is an assignment plus the contiguous comment block directly
//! above it, and everything before the first record is a preamble that stays
//! put. Moving a record moves its explanation with it.
//!
//! ## Which duplicate is in effect
//!
//! LoadEnvironment::setVar overwrites `$_ENV[$name]` on every call and the
//! cascade walks a file top to bottom, so within one file the LAST active
//! assignment wins. That is the opposite of what most people assume when they
//! append a key to the bottom of a .env "to try something", and it is why
//! `effective()` exists: an audit that cannot say which line is actually live
//! is not an audit.
//!
//! A commented assignment (`# KEY=`) is parsed as a record too. The seeder
//! writes optional variables that way, so treating them as prose would make
//! every optional plugin knob invisible to the grouping and re-seed it forever.

const std = @import("std");
const util = @import("util.zig");

const Io = std.Io;
const Dir = std.Io.Dir;

/// One `KEY=value` assignment, with the comment block attached above it.
pub const Record = struct {
    key: []const u8,
    /// Everything right of the first `=`, untrimmed of trailing comments.
    value: []const u8,
    /// False when the line is commented out (`# KEY=…`).
    active: bool,
    /// Index into `File.lines` of the assignment itself.
    line: usize,
    /// First line of the attached comment block — equals `line` when none.
    first: usize,
};

pub const File = struct {
    /// Every line of the file, in order, without terminators.
    lines: []const []const u8,
    records: []const Record,
    /// Lines before the first record: the file's banner. Never reordered.
    preamble: usize,
    /// True when the file ended with a newline, so a rewrite can match it.
    trailing_newline: bool,
};

/// A key that appears more than once, with every place it appears.
pub const Duplicate = struct {
    key: []const u8,
    /// Indices into `File.records`, in file order.
    at: []const usize,
};

/// True when `name` is a syntactically valid environment key.
fn isKeyChar(c: u8, first: bool) bool {
    if (c == '_') return true;
    if (c >= 'A' and c <= 'Z') return true;
    if (c >= 'a' and c <= 'z') return true;
    if (!first and c >= '0' and c <= '9') return true;
    return false;
}

/// Split `line` into a key and the text right of `=`, or null when it is not an
/// assignment. Handles the commented form by reporting `active = false`.
pub fn assignment(line: []const u8) ?struct { key: []const u8, value: []const u8, active: bool } {
    var s = std.mem.trim(u8, line, " \t\r");
    if (s.len == 0) return null;

    var active = true;
    if (s[0] == '#') {
        active = false;
        // Step past the marker and any run of them: `##  KEY=` is still a
        // commented assignment, and a person writing one means it.
        while (s.len > 0 and (s[0] == '#' or s[0] == ' ' or s[0] == '\t')) s = s[1..];
        if (s.len == 0) return null;
    }

    // Optional `export ` prefix — valid dotenv, and dropping it silently would
    // make `export DB_HOST=` invisible to a duplicate check that sees `DB_HOST=`.
    if (std.mem.startsWith(u8, s, "export ")) s = std.mem.trimStart(u8, s["export ".len..], " \t");

    const eq = std.mem.indexOfScalar(u8, s, '=') orelse return null;
    const key = std.mem.trim(u8, s[0..eq], " \t");
    if (key.len == 0) return null;

    for (key, 0..) |c, i| {
        if (!isKeyChar(c, i == 0)) return null;
    }

    return .{ .key = key, .value = std.mem.trim(u8, s[eq + 1 ..], " \t"), .active = active };
}

const rule_chars = "-=_\u{2500}\u{2501}\u{2550}#*";

/// True for a comment carrying no words at all: a bare `#`, or a rule of
/// dashes / box characters. Always safe to drop and regenerate.
pub fn isRule(line: []const u8) bool {
    var s = std.mem.trim(u8, line, " \t\r");
    if (s.len == 0 or s[0] != '#') return false;
    s = std.mem.trim(u8, s[1..], " \t");
    if (s.len == 0) return true;
    return std.mem.trim(u8, s, rule_chars).len == 0;
}

/// The label of a `# ─── Name ───` header, or null when the line is not one.
///
/// A LABELLED rule is not decoration — `# --- s3 driver (MinIO) ---` is the
/// only place that fact is written down, and an earlier version of this code
/// deleted three such lines from a real .env because they matched the shape of
/// a banner. So the label is returned rather than judged here, and the caller
/// drops the line only when the label is one it is about to re-emit itself.
pub fn headerLabel(line: []const u8) ?[]const u8 {
    var s = std.mem.trim(u8, line, " \t\r");
    if (s.len == 0 or s[0] != '#') return null;
    s = std.mem.trim(u8, s[1..], " \t");
    if (s.len == 0) return null;

    const head = std.mem.trimStart(u8, s, rule_chars);
    if (head.len == s.len) return null; // no leading rule — ordinary prose
    const label = std.mem.trim(u8, std.mem.trimEnd(u8, head, rule_chars), " \t");
    if (label.len == 0) return null; // pure rule — isRule's business
    return label;
}

/// Comment lines that carry information: not blank, not a bare rule. The unit
/// a rewrite must never lose.
pub fn informationalComments(allocator: std.mem.Allocator, file: File) ![]const []const u8 {
    var out: std.ArrayList([]const u8) = .empty;
    for (file.lines) |line| {
        const t = std.mem.trim(u8, line, " \t\r");
        if (t.len == 0 or t[0] != '#') continue;
        if (isRule(line)) continue;
        if (assignment(line) != null) continue; // a commented-out key is a record
        try out.append(allocator, t);
    }
    return out.items;
}

/// Parse `content` into records. Never fails: a line that is not an assignment
/// is simply not a record, which is what makes this safe to run on any file.
pub fn parse(allocator: std.mem.Allocator, content: []const u8) !File {
    var lines: std.ArrayList([]const u8) = .empty;
    var it = std.mem.splitScalar(u8, content, '\n');
    while (it.next()) |l| try lines.append(allocator, std.mem.trimEnd(u8, l, "\r"));

    // splitScalar yields a trailing empty field for a file ending in a newline.
    const trailing = lines.items.len > 0 and lines.items[lines.items.len - 1].len == 0;
    if (trailing) _ = lines.pop();

    var records: std.ArrayList(Record) = .empty;
    var block: ?usize = null;
    var preamble: usize = 0;

    for (lines.items, 0..) |line, i| {
        const trimmed = std.mem.trim(u8, line, " \t\r");

        if (assignment(line)) |a| {
            try records.append(allocator, .{
                .key = a.key,
                .value = a.value,
                .active = a.active,
                .line = i,
                .first = block orelse i,
            });
            if (records.items.len == 1) preamble = block orelse i;
            block = null;
            continue;
        }

        if (trimmed.len == 0) {
            // A blank line breaks the attachment: a comment separated from a
            // key by whitespace is a section note, not that key's explanation.
            block = null;
            continue;
        }

        if (trimmed[0] == '#') {
            if (block == null) block = i;
            continue;
        }

        block = null;
    }

    if (records.items.len == 0) preamble = lines.items.len;

    return .{
        .lines = lines.items,
        .records = records.items,
        .preamble = preamble,
        .trailing_newline = trailing,
    };
}

/// Keys appearing in more than one record, in first-appearance order.
pub fn duplicates(allocator: std.mem.Allocator, file: File) ![]const Duplicate {
    var out: std.ArrayList(Duplicate) = .empty;
    var seen: std.ArrayList([]const u8) = .empty;

    for (file.records, 0..) |r, i| {
        var already = false;
        for (seen.items) |k| {
            if (std.mem.eql(u8, k, r.key)) {
                already = true;
                break;
            }
        }
        if (already) continue;
        try seen.append(allocator, r.key);

        var at: std.ArrayList(usize) = .empty;
        try at.append(allocator, i);
        for (file.records[i + 1 ..], i + 1..) |other, j| {
            if (std.mem.eql(u8, other.key, r.key)) try at.append(allocator, j);
        }
        if (at.items.len > 1) try out.append(allocator, .{ .key = r.key, .at = at.items });
    }

    return out.items;
}

/// Index into `dup.at` of the record actually in effect: the LAST active one.
/// Null when every occurrence is commented out — then nothing is in effect and
/// the key's value comes from the plugin's own default.
pub fn effective(file: File, dup: Duplicate) ?usize {
    var found: ?usize = null;
    for (dup.at, 0..) |rec, i| {
        if (file.records[rec].active) found = i;
    }
    return found;
}

/// Rebuild the file with the assignment lines at `drop` removed.
///
/// ONLY the assignment lines. The comment block above a dropped key stays, on
/// purpose: it is routinely a section header that belongs to the whole block
/// below it, and deleting a `# ─── Database ───` because the first key under it
/// lost a duplicate vote would be a silent, unrelated edit.
pub fn withoutLines(allocator: std.mem.Allocator, file: File, drop: []const usize) ![]const u8 {
    var out: std.ArrayList(u8) = .empty;
    for (file.lines, 0..) |line, i| {
        var skip = false;
        for (drop) |d| {
            if (d == i) {
                skip = true;
                break;
            }
        }
        if (skip) continue;
        try out.appendSlice(allocator, line);
        try out.append(allocator, '\n');
    }
    if (!file.trailing_newline and out.items.len > 0) _ = out.pop();
    return out.items;
}

// ── grouping ─────────────────────────────────────────────────────────────────

/// A key prefix and the feature it belongs to. Longest match wins, so the table
/// is ordered longest-first and `matchPrefix` does not have to sort it.
///
/// This is the FALLBACK. A key a plugin declares in its module.json `config[]`
/// is grouped under that plugin instead — that mapping is authoritative, this
/// one is a guess about a key nothing claims.
pub const prefix_groups = [_]struct { prefix: []const u8, group: []const u8 }{
    .{ .prefix = "DATABASE_", .group = "Database" },
    .{ .prefix = "SESSION_", .group = "Session" },
    .{ .prefix = "STORAGE_", .group = "Storage" },
    .{ .prefix = "TENANCY_", .group = "Tenancy" },
    .{ .prefix = "TENANT_", .group = "Tenancy" },
    .{ .prefix = "SECURITY_", .group = "Security" },
    .{ .prefix = "COOKIE_", .group = "Cookie" },
    .{ .prefix = "LOGGER_", .group = "Logging" },
    .{ .prefix = "REDIS_", .group = "Redis" },
    .{ .prefix = "QUEUE_", .group = "Queue" },
    .{ .prefix = "CACHE_", .group = "Cache" },
    .{ .prefix = "ROUTE_", .group = "Routing" },
    .{ .prefix = "MAIL_", .group = "Mail" },
    .{ .prefix = "SMTP_", .group = "Mail" },
    .{ .prefix = "VIEW_", .group = "Views" },
    .{ .prefix = "EDGE_", .group = "Edge" },
    .{ .prefix = "AUTH_", .group = "Security" },
    .{ .prefix = "CSRF_", .group = "Security" },
    .{ .prefix = "CORS_", .group = "Security" },
    .{ .prefix = "JWT_", .group = "Security" },
    .{ .prefix = "LOG_", .group = "Logging" },
    .{ .prefix = "JOB_", .group = "Queue" },
    .{ .prefix = "SMS_", .group = "SMS" },
    .{ .prefix = "SEO_", .group = "SEO" },
    .{ .prefix = "AWS_", .group = "Storage" },
    .{ .prefix = "S3_", .group = "Storage" },
    .{ .prefix = "HKM_", .group = "Platform" },
    .{ .prefix = "APP_", .group = "Application" },
    .{ .prefix = "DB_", .group = "Database" },
};

/// The group a key falls into when no plugin declares it.
pub fn prefixGroup(key: []const u8) ?[]const u8 {
    var best: ?[]const u8 = null;
    var best_len: usize = 0;
    for (prefix_groups) |g| {
        if (g.prefix.len <= best_len) continue;
        if (std.mem.startsWith(u8, key, g.prefix)) {
            best = g.group;
            best_len = g.prefix.len;
        }
    }
    return best;
}

/// Name used for everything the plugins and the prefix table both disown.
pub const ungrouped = "Ungrouped";

/// Read `.env` from a project root. Returns "" when there is none, so callers
/// can report an empty analysis rather than an error.
pub fn read(allocator: std.mem.Allocator, io: Io, projectRoot: []const u8) !struct { path: []const u8, content: []const u8 } {
    const path = try std.fs.path.join(allocator, &.{ projectRoot, ".env" });
    const content = Dir.cwd().readFileAlloc(io, path, allocator, .limited(8 * 1024 * 1024)) catch "";
    return .{ .path = path, .content = content };
}

/// Write `content` to the project's `.env`, keeping a `.env.bak` of what was
/// there. The backup is not politeness: this file holds the only copy of every
/// secret the application has, and a rewrite that loses one costs a great deal
/// more than the disk the copy takes.
pub fn write(allocator: std.mem.Allocator, io: Io, path: []const u8, before: []const u8, content: []const u8) !void {
    const backup = try std.fmt.allocPrint(allocator, "{s}.bak", .{path});
    if (before.len > 0) {
        try util.writeFileAtomic(io, backup, before);
        util.chmod600(io, backup);
    }
    try util.writeFileAtomic(io, path, content);
    util.chmod600(io, path);
}

// ── tests ────────────────────────────────────────────────────────────────────

test "assignment parses active, commented and exported forms" {
    try std.testing.expectEqualStrings("A", assignment("A=1").?.key);
    try std.testing.expect(assignment("A=1").?.active);
    try std.testing.expect(!assignment("# A=1").?.active);
    try std.testing.expect(!assignment("##  A=1").?.active);
    try std.testing.expectEqualStrings("A", assignment("export A=1").?.key);
    try std.testing.expectEqualStrings("1", assignment("A = 1").?.value);
}

test "prose and rules are not assignments" {
    try std.testing.expect(assignment("# see APP_KEY for details") == null);
    try std.testing.expect(assignment("") == null);
    try std.testing.expect(assignment("# ─── Auth ───") == null);
    // A key with a dash is not a valid env name, so this is prose.
    try std.testing.expect(assignment("not-a-key=1") == null);
}

test "a comment directly above a key attaches, one across a blank line does not" {
    const a = std.testing.allocator;
    var arena = std.heap.ArenaAllocator.init(a);
    defer arena.deinit();

    const f = try parse(arena.allocator(), "# banner\n\n# explains A\nA=1\n\n# loose\n\nB=2\n");
    try std.testing.expectEqual(@as(usize, 2), f.records.len);
    try std.testing.expectEqual(@as(usize, 2), f.records[0].first); // "# explains A"
    try std.testing.expectEqual(@as(usize, 3), f.records[0].line);
    try std.testing.expectEqual(f.records[1].line, f.records[1].first); // nothing attached
    // Lines 0-1 ("# banner" + the blank) are the banner; line 2 belongs to A.
    try std.testing.expectEqual(@as(usize, 2), f.preamble);
}

test "the LAST active assignment is the one in effect" {
    const a = std.testing.allocator;
    var arena = std.heap.ArenaAllocator.init(a);
    defer arena.deinit();
    const al = arena.allocator();

    const f = try parse(al, "A=1\n# A=2\nA=3\nB=1\n");
    const dups = try duplicates(al, f);
    try std.testing.expectEqual(@as(usize, 1), dups.len);
    try std.testing.expectEqualStrings("A", dups[0].key);
    try std.testing.expectEqual(@as(usize, 3), dups[0].at.len);
    // Index 2 within `at` — the third occurrence, `A=3`.
    try std.testing.expectEqual(@as(usize, 2), effective(f, dups[0]).?);
}

test "a key whose every occurrence is commented has nothing in effect" {
    const a = std.testing.allocator;
    var arena = std.heap.ArenaAllocator.init(a);
    defer arena.deinit();
    const al = arena.allocator();

    const f = try parse(al, "# A=1\n# A=2\n");
    const dups = try duplicates(al, f);
    try std.testing.expect(effective(f, dups[0]) == null);
}

test "APP_KEY_ID and APP_KEY are different keys" {
    const a = std.testing.allocator;
    var arena = std.heap.ArenaAllocator.init(a);
    defer arena.deinit();
    const al = arena.allocator();

    const f = try parse(al, "APP_KEY=1\nAPP_KEY_ID=2\n");
    try std.testing.expectEqual(@as(usize, 0), (try duplicates(al, f)).len);
}

test "withoutLines drops the assignment and keeps the header above it" {
    const a = std.testing.allocator;
    var arena = std.heap.ArenaAllocator.init(a);
    defer arena.deinit();
    const al = arena.allocator();

    const src = "# ─── Database ───\nDB_HOST=old\nDB_PORT=3306\nDB_HOST=new\n";
    const f = try parse(al, src);
    const out = try withoutLines(al, f, &.{1});
    try std.testing.expectEqualStrings("# ─── Database ───\nDB_PORT=3306\nDB_HOST=new\n", out);
}

test "prefixGroup takes the longest match" {
    try std.testing.expectEqualStrings("Database", prefixGroup("DB_HOST").?);
    try std.testing.expectEqualStrings("Database", prefixGroup("DATABASE_URL").?);
    try std.testing.expectEqualStrings("Application", prefixGroup("APP_ENV").?);
    try std.testing.expectEqualStrings("Session", prefixGroup("SESSION_DRIVER").?);
    try std.testing.expect(prefixGroup("STRIPE_SECRET") == null);
}

test "isRule matches only wordless comments" {
    try std.testing.expect(isRule("# ─────────────"));
    try std.testing.expect(isRule("#"));
    try std.testing.expect(isRule("# ---------"));
    try std.testing.expect(!isRule("# ─── Auth ───"));
    try std.testing.expect(!isRule("# set this before booting"));
    try std.testing.expect(!isRule("DB_HOST=1"));
}

test "headerLabel reads a banner's label and leaves prose alone" {
    try std.testing.expectEqualStrings("Auth", headerLabel("# ─── Auth ───").?);
    try std.testing.expectEqualStrings(
        "s3 driver (MinIO)",
        headerLabel("# --- s3 driver (MinIO) ---").?,
    );
    try std.testing.expect(headerLabel("# set this before booting") == null);
    try std.testing.expect(headerLabel("# ─────") == null);
}
