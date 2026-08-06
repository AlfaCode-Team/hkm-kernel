//! Semantic-version parsing and constraint matching.
//!
//! Used to decide whether a plugin may be installed against the running kernel.
//! A plugin declares a constraint in its module.json:
//!
//!   "kernel": "^1.2"
//!
//! and the tooling refuses to install it into a kernel that does not satisfy it.
//! Getting this wrong is worse than having no check at all: a plugin that boots
//! against an incompatible kernel fails at request time, deep inside a pipeline,
//! with an error that points at the plugin rather than at the version mismatch.
//!
//! Deliberately small. This implements the subset of the semver grammar the
//! platform actually uses — caret, tilde, comparators, wildcards and exact
//! pins — rather than a complete range algebra nobody will write.

const std = @import("std");

pub const Version = struct {
    major: u32 = 0,
    minor: u32 = 0,
    patch: u32 = 0,
    /// Pre-release tag without the '-' ("dev", "rc.1"). Empty when absent.
    pre: []const u8 = "",

    /// Parse "1.2.3", "v1.2.3", "1.2", "1", "1.0.0-dev", "0.0.0-dev+meta".
    ///
    /// Returns null rather than erroring: version strings arrive from JSON
    /// written by hand, and a malformed one should produce a clear "cannot
    /// determine compatibility" message rather than an unhandled error.
    pub fn parse(raw: []const u8) ?Version {
        var s = std.mem.trim(u8, raw, " \t\r\n");
        if (s.len == 0) return null;

        // Accept a leading 'v'/'V' so git tag names parse directly.
        if (s[0] == 'v' or s[0] == 'V') s = s[1..];
        if (s.len == 0) return null;

        // Build metadata ("+sha") never affects precedence — drop it.
        if (std.mem.indexOfScalar(u8, s, '+')) |i| s = s[0..i];

        var pre: []const u8 = "";
        if (std.mem.indexOfScalar(u8, s, '-')) |i| {
            pre = s[i + 1 ..];
            s = s[0..i];
        }

        var out = Version{ .pre = pre };
        var it = std.mem.splitScalar(u8, s, '.');
        var field: usize = 0;
        while (it.next()) |part| : (field += 1) {
            if (field > 2) break; // ignore anything past patch
            if (part.len == 0) return null;
            const n = std.fmt.parseInt(u32, part, 10) catch return null;
            switch (field) {
                0 => out.major = n,
                1 => out.minor = n,
                else => out.patch = n,
            }
        }
        if (field == 0) return null;

        return out;
    }

    /// Compare precedence. A pre-release sorts BELOW its release
    /// (1.0.0-dev < 1.0.0), per semver §11.
    pub fn order(self: Version, other: Version) std.math.Order {
        if (self.major != other.major) return std.math.order(self.major, other.major);
        if (self.minor != other.minor) return std.math.order(self.minor, other.minor);
        if (self.patch != other.patch) return std.math.order(self.patch, other.patch);

        const a_pre = self.pre.len > 0;
        const b_pre = other.pre.len > 0;
        if (a_pre and !b_pre) return .lt;
        if (!a_pre and b_pre) return .gt;
        if (!a_pre and !b_pre) return .eq;
        return std.mem.order(u8, self.pre, other.pre);
    }

    pub fn eql(self: Version, other: Version) bool {
        return self.order(other) == .eq;
    }

    /// Render back to "MAJOR.MINOR.PATCH[-pre]". Writes into `buf`.
    pub fn format(self: Version, buf: []u8) []const u8 {
        if (self.pre.len > 0) {
            return std.fmt.bufPrint(buf, "{d}.{d}.{d}-{s}", .{ self.major, self.minor, self.patch, self.pre }) catch "?";
        }
        return std.fmt.bufPrint(buf, "{d}.{d}.{d}", .{ self.major, self.minor, self.patch }) catch "?";
    }
};

/// Why a constraint could not be evaluated, so callers can explain themselves.
pub const MatchError = error{
    /// The constraint string could not be understood.
    BadConstraint,
};

/// Does `version` satisfy `constraint`?
///
/// Supported forms, comma- or space-separated (all must hold):
///   *  / any            anything
///   1.2.3               exactly 1.2.3
///   =1.2.3              exactly 1.2.3
///   ^1.2.3              >=1.2.3 and <2.0.0   (>=0.2.3 <0.3.0 when major is 0)
///   ~1.2.3              >=1.2.3 and <1.3.0
///   ~1.2                >=1.2.0 and <1.3.0
///   >=1.2  >1.2  <=2.0  <2.0   comparators
///   1.x / 1.2.x / 1.*   wildcard, equivalent to ^ on the given precision
///
/// A leading 'v' is accepted everywhere.
///
/// NOTE on major 0: `^0.2.3` allows only 0.2.x, not 0.3.0. Pre-1.0 packages
/// break compatibility in the MINOR field by convention, and treating ^0.2 as
/// "anything below 1.0" is how a tool cheerfully installs a plugin against a
/// kernel that renamed half its API.
pub fn satisfies(version: Version, constraint: []const u8) MatchError!bool {
    const trimmed = std.mem.trim(u8, constraint, " \t\r\n");
    if (trimmed.len == 0) return true; // no constraint declared = no opinion
    if (std.mem.eql(u8, trimmed, "*") or std.ascii.eqlIgnoreCase(trimmed, "any")) return true;

    // Every clause must hold. Both ',' and whitespace separate clauses, so
    // ">=1.2 <2.0" and ">=1.2,<2.0" mean the same thing.
    var normalized = std.mem.tokenizeAny(u8, trimmed, ", \t");
    var saw_clause = false;
    while (normalized.next()) |clause| {
        saw_clause = true;
        if (!try satisfiesOne(version, clause)) return false;
    }
    if (!saw_clause) return MatchError.BadConstraint;

    return true;
}

fn satisfiesOne(version: Version, raw_clause: []const u8) MatchError!bool {
    const clause = std.mem.trim(u8, raw_clause, " \t");
    if (clause.len == 0) return MatchError.BadConstraint;

    if (std.mem.startsWith(u8, clause, ">=")) return cmp(version, clause[2..], .gte);
    if (std.mem.startsWith(u8, clause, "<=")) return cmp(version, clause[2..], .lte);
    if (std.mem.startsWith(u8, clause, ">")) return cmp(version, clause[1..], .gt);
    if (std.mem.startsWith(u8, clause, "<")) return cmp(version, clause[1..], .lt);
    if (std.mem.startsWith(u8, clause, "=")) return cmp(version, clause[1..], .eq);
    if (std.mem.startsWith(u8, clause, "^")) return caret(version, clause[1..]);
    if (std.mem.startsWith(u8, clause, "~")) return tilde(version, clause[1..]);

    // Wildcards: 1.x, 1.2.x, 1.*, 1
    if (wildcardPrecision(clause)) |prec| return wildcard(version, clause, prec);

    // Bare version = exact pin.
    return cmp(version, clause, .eq);
}

const Cmp = enum { eq, lt, lte, gt, gte };

fn cmp(version: Version, raw: []const u8, op: Cmp) MatchError!bool {
    const bound = Version.parse(raw) orelse return MatchError.BadConstraint;
    const o = version.order(bound);
    return switch (op) {
        .eq => o == .eq,
        .lt => o == .lt,
        .lte => o != .gt,
        .gt => o == .gt,
        .gte => o != .lt,
    };
}

/// ^1.2.3 → >=1.2.3 <2.0.0 ; ^0.2.3 → >=0.2.3 <0.3.0 ; ^0.0.3 → >=0.0.3 <0.0.4
fn caret(version: Version, raw: []const u8) MatchError!bool {
    const base = Version.parse(raw) orelse return MatchError.BadConstraint;
    if (version.order(base) == .lt) return false;

    if (base.major > 0) return version.major == base.major;
    if (base.minor > 0) return version.major == 0 and version.minor == base.minor;
    return version.major == 0 and version.minor == 0 and version.patch == base.patch;
}

/// ~1.2.3 → >=1.2.3 <1.3.0 ; ~1.2 → >=1.2.0 <1.3.0 ; ~1 → >=1.0.0 <2.0.0
fn tilde(version: Version, raw: []const u8) MatchError!bool {
    const base = Version.parse(raw) orelse return MatchError.BadConstraint;
    if (version.order(base) == .lt) return false;

    // "~1" (no minor given) pins only the major.
    if (std.mem.indexOfScalar(u8, std.mem.trim(u8, raw, "vV "), '.') == null) {
        return version.major == base.major;
    }
    return version.major == base.major and version.minor == base.minor;
}

/// How many numeric fields precede a wildcard, or null when there is none.
fn wildcardPrecision(clause: []const u8) ?usize {
    var s = clause;
    if (s.len > 0 and (s[0] == 'v' or s[0] == 'V')) s = s[1..];

    var fields: usize = 0;
    var it = std.mem.splitScalar(u8, s, '.');
    while (it.next()) |part| {
        if (part.len == 1 and (part[0] == 'x' or part[0] == 'X' or part[0] == '*')) return fields;
        fields += 1;
    }
    // "1" and "1.2" with no explicit wildcard behave as 1.x / 1.2.x.
    if (fields > 0 and fields < 3) return fields;
    return null;
}

fn wildcard(version: Version, clause: []const u8, precision: usize) MatchError!bool {
    if (precision == 0) return true; // "x" / "*"

    // Replace the wildcard with 0 so the prefix parses.
    var buf: [64]u8 = undefined;
    var s = clause;
    if (s.len > 0 and (s[0] == 'v' or s[0] == 'V')) s = s[1..];

    var out: usize = 0;
    var fields: usize = 0;
    var it = std.mem.splitScalar(u8, s, '.');
    while (it.next()) |part| {
        if (fields >= precision) break;
        if (out > 0) {
            if (out >= buf.len) return MatchError.BadConstraint;
            buf[out] = '.';
            out += 1;
        }
        if (out + part.len > buf.len) return MatchError.BadConstraint;
        @memcpy(buf[out..][0..part.len], part);
        out += part.len;
        fields += 1;
    }

    const base = Version.parse(buf[0..out]) orelse return MatchError.BadConstraint;
    return switch (precision) {
        1 => version.major == base.major,
        else => version.major == base.major and version.minor == base.minor,
    };
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test "parses the shapes that actually appear" {
    const v = Version.parse("1.2.3").?;
    try std.testing.expectEqual(@as(u32, 1), v.major);
    try std.testing.expectEqual(@as(u32, 2), v.minor);
    try std.testing.expectEqual(@as(u32, 3), v.patch);

    // git tags carry a leading v
    try std.testing.expect(Version.parse("v2.0.0").?.eql(Version.parse("2.0.0").?));
    // partial versions fill with zeros
    try std.testing.expect(Version.parse("1.2").?.eql(Version.parse("1.2.0").?));
    try std.testing.expect(Version.parse("1").?.eql(Version.parse("1.0.0").?));
    // build metadata is not part of precedence
    try std.testing.expect(Version.parse("1.0.0+abc").?.eql(Version.parse("1.0.0").?));
    // the kernel's own default version string must parse
    try std.testing.expectEqualStrings("dev", Version.parse("0.0.0-dev").?.pre);
}

test "rejects nonsense instead of guessing" {
    try std.testing.expect(Version.parse("") == null);
    try std.testing.expect(Version.parse("abc") == null);
    try std.testing.expect(Version.parse("1..2") == null);
    try std.testing.expect(Version.parse("v") == null);
}

test "a pre-release sorts below its release" {
    const dev = Version.parse("1.0.0-dev").?;
    const rel = Version.parse("1.0.0").?;
    try std.testing.expectEqual(std.math.Order.lt, dev.order(rel));
    try std.testing.expectEqual(std.math.Order.gt, rel.order(dev));
}

test "caret pins the major for 1.x and above" {
    const c = "^1.2.0";
    try std.testing.expect(try satisfies(Version.parse("1.2.0").?, c));
    try std.testing.expect(try satisfies(Version.parse("1.9.9").?, c));
    try std.testing.expect(!try satisfies(Version.parse("1.1.9").?, c)); // below the floor
    try std.testing.expect(!try satisfies(Version.parse("2.0.0").?, c)); // major bump
}

test "caret on a 0.x version pins the minor" {
    // Pre-1.0 packages break in the minor field. Treating ^0.2 as "<1.0" is how
    // a tool installs a plugin against a kernel that renamed half its API.
    const c = "^0.2.3";
    try std.testing.expect(try satisfies(Version.parse("0.2.9").?, c));
    try std.testing.expect(!try satisfies(Version.parse("0.3.0").?, c));
    try std.testing.expect(!try satisfies(Version.parse("1.0.0").?, c));
}

test "tilde pins the minor" {
    try std.testing.expect(try satisfies(Version.parse("1.2.9").?, "~1.2.3"));
    try std.testing.expect(!try satisfies(Version.parse("1.3.0").?, "~1.2.3"));
    // "~1" has no minor, so it pins only the major
    try std.testing.expect(try satisfies(Version.parse("1.9.0").?, "~1"));
    try std.testing.expect(!try satisfies(Version.parse("2.0.0").?, "~1"));
}

test "comparators" {
    try std.testing.expect(try satisfies(Version.parse("1.5.0").?, ">=1.2"));
    try std.testing.expect(!try satisfies(Version.parse("1.1.0").?, ">=1.2"));
    try std.testing.expect(try satisfies(Version.parse("1.0.0").?, "<2.0.0"));
    try std.testing.expect(!try satisfies(Version.parse("2.0.0").?, "<2.0.0"));
    try std.testing.expect(try satisfies(Version.parse("2.0.0").?, "<=2.0.0"));
}

test "every clause in a multi-clause constraint must hold" {
    const c = ">=1.2,<2.0";
    try std.testing.expect(try satisfies(Version.parse("1.5.0").?, c));
    try std.testing.expect(!try satisfies(Version.parse("2.0.1").?, c));
    try std.testing.expect(!try satisfies(Version.parse("1.0.0").?, c));

    // whitespace-separated means the same thing
    try std.testing.expect(try satisfies(Version.parse("1.5.0").?, ">=1.2 <2.0"));
}

test "wildcards" {
    try std.testing.expect(try satisfies(Version.parse("1.9.9").?, "1.x"));
    try std.testing.expect(!try satisfies(Version.parse("2.0.0").?, "1.x"));
    try std.testing.expect(try satisfies(Version.parse("1.2.9").?, "1.2.*"));
    try std.testing.expect(!try satisfies(Version.parse("1.3.0").?, "1.2.*"));
}

test "an absent or open constraint permits anything" {
    // A plugin that declares no kernel constraint must stay installable —
    // failing closed here would break every plugin written before the field
    // existed.
    try std.testing.expect(try satisfies(Version.parse("9.9.9").?, ""));
    try std.testing.expect(try satisfies(Version.parse("9.9.9").?, "*"));
    try std.testing.expect(try satisfies(Version.parse("9.9.9").?, "any"));
}

test "an exact pin matches only itself" {
    try std.testing.expect(try satisfies(Version.parse("1.2.3").?, "1.2.3"));
    try std.testing.expect(!try satisfies(Version.parse("1.2.4").?, "1.2.3"));
    try std.testing.expect(try satisfies(Version.parse("1.2.3").?, "=1.2.3"));
}

test "a malformed constraint is an error, not a silent yes" {
    // Silently treating garbage as "compatible" is the failure mode this whole
    // check exists to prevent.
    try std.testing.expectError(MatchError.BadConstraint, satisfies(Version.parse("1.0.0").?, ">=nope"));
    try std.testing.expectError(MatchError.BadConstraint, satisfies(Version.parse("1.0.0").?, "^abc"));
}
