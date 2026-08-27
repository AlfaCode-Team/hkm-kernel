//! Shared path / string / filesystem helpers used across the hkm commands.
//! Keep these dependency-light and command-agnostic.

const std = @import("std");

const Dir = std.Io.Dir;
const Io = std.Io;
const EnvMap = std.process.Environ.Map;

/// Can this process write inside `dir`?
///
/// Probes by actually creating and removing a file rather than inspecting
/// permission bits: the bits do not account for the effective uid, mount
/// options (a read-only /opt), SELinux, or ACLs. A probe answers the question
/// that is actually being asked — "will my write succeed?" — and the caller
/// uses it to decide whether to shell out through sudo.
pub fn canWrite(io: Io, dir: []const u8) bool {
    // Sized for a real path. A 64-byte buffer overflowed on any ordinary
    // directory, and because the overflow was swallowed as "cannot write" the
    // caller silently escalated to sudo for targets it could have written
    // directly — every copy then failed with no usable explanation.
    var buf: [std.fs.max_path_bytes]u8 = undefined;
    const probe = std.fmt.bufPrint(&buf, "{s}/.hkm-write-probe", .{dir}) catch return false;

    Dir.cwd().writeFile(io, .{ .sub_path = probe, .data = "" }) catch return false;
    Dir.cwd().deleteFile(io, probe) catch {};
    return true;
}

/// Whether an environment variable is set to something meaning "yes".
///
/// Accepts 1 / true / yes / on, case-insensitively. Anything else — including
/// an unset variable, an empty value, or "0" — is false. Centralised so every
/// HKM_* toggle agrees on what truthy means; a variable that reads as enabled
/// to one command and disabled to another is a confusing bug to chase.
pub fn envIsTruthy(env: *EnvMap, key: []const u8) bool {
    const raw = env.get(key) orelse return false;
    const v = std.mem.trim(u8, raw, " \t\r\n");
    for ([_][]const u8{ "1", "true", "yes", "on" }) |t| {
        if (eqlIgnoreCase(v, t)) return true;
    }
    return false;
}

/// Restrict a file to owner-only (chmod 0600). No-op on Windows. Best-effort —
/// used for secret-bearing files like a project's .env.
pub fn chmod600(io: Io, path: []const u8) void {
    if (@import("builtin").os.tag == .windows) return;
    const f = Dir.cwd().openFile(io, path, .{}) catch return;
    defer f.close(io);
    f.setPermissions(io, @enumFromInt(0o600)) catch {};
}

/// Locate an executable by walking PATH, returning the FIRST match — the one
/// that would actually run. Null when it is nowhere on PATH.
///
/// A name containing a '/' is treated as a path, not a PATH lookup, matching
/// what execve itself does — so `HKM_PHP_BIN=/opt/php/bin/php` resolves to that
/// file rather than being searched for as a filename.
///
/// Lives here because `doctor` and `version` had grown a private copy each, and
/// a third was about to appear in main.zig for the passthrough diagnostic.
pub fn findOnPath(allocator: std.mem.Allocator, io: Io, env: *EnvMap, name: []const u8) ?[]const u8 {
    if (name.len == 0) return null;

    if (std.mem.indexOfScalar(u8, name, '/') != null) {
        return if (fileExists(io, name)) name else null;
    }

    const path = env.get("PATH") orelse return null;
    var it = std.mem.splitScalar(u8, path, ':');
    while (it.next()) |dir| {
        if (dir.len == 0) continue;
        const cand = std.fs.path.join(allocator, &.{ dir, name }) catch continue;
        if (fileExists(io, cand)) return cand;
    }
    return null;
}

/// Whether an executable is runnable — `findOnPath` as a predicate.
pub fn onPath(allocator: std.mem.Allocator, io: Io, env: *EnvMap, name: []const u8) bool {
    return findOnPath(allocator, io, env, name) != null;
}

/// Every match for `name` on PATH, counted — used to detect one install
/// shadowing another.
pub fn countOnPath(allocator: std.mem.Allocator, io: Io, env: *EnvMap, name: []const u8) usize {
    const path = env.get("PATH") orelse return 0;
    var n: usize = 0;
    var it = std.mem.splitScalar(u8, path, ':');
    while (it.next()) |dir| {
        if (dir.len == 0) continue;
        const cand = std.fs.path.join(allocator, &.{ dir, name }) catch continue;
        if (fileExists(io, cand)) n += 1;
    }
    return n;
}

/// The first argument that looks like a flag but is not in `known`, or null.
///
/// Every command in this tree parses flags by testing for the ones it knows and
/// ignoring the rest, with a comment saying that keeps future flags from hard
/// failing. For a read-only command that is a fair trade. For a DESTRUCTIVE one
/// it is not, because the ignored token is usually a typo of the flag that was
/// meant to make it safe:
///
///     hkm uninstall --dryrun --yes     # --dry-run misspelled
///
/// which parsed as "no dry run, and don't ask" and deleted the install. Commands
/// that can destroy or escalate should reject what they do not recognise.
///
/// `--flag=value` is compared on the name before `=`. A bare `-` or `--` is not
/// treated as a flag, and everything after a `--` separator is left alone so a
/// passthrough command can forward its own arguments.
pub fn unknownFlag(args: []const []const u8, known: []const []const u8) ?[]const u8 {
    for (args) |raw| {
        if (std.mem.eql(u8, raw, "--")) return null; // end of options
        if (raw.len < 2 or raw[0] != '-') continue; // positional
        if (std.mem.eql(u8, raw, "-")) continue; // stdin convention

        const name = if (std.mem.indexOfScalar(u8, raw, '=')) |i| raw[0..i] else raw;
        if (!contains(known, name)) return raw;
    }
    return null;
}

/// Write `data` to `path` atomically: fill a sibling temp file, then rename it
/// over the target.
///
/// `writeFile` truncates first and then writes, so a process killed part way
/// through — or a full disk — leaves a truncated file rather than the old one.
/// That is tolerable for a cache and not for USER DATA: `projects.json` is the
/// registry of every project on the machine, the one file `hkm uninstall`
/// deliberately rescues before deleting anything, and it was being replaced by
/// the destructive method.
///
/// rename(2) within a directory is atomic, so a reader sees either the previous
/// file or the new one and never a half-written one. The pattern is already used
/// twice in this tree — `install.sh` stages to `.new` before `mv`, and the
/// launcher install writes `.hkm-new` then renames — it had simply never reached
/// the registry.
pub fn writeFileAtomic(io: Io, path: []const u8, data: []const u8) !void {
    var buf: [std.fs.max_path_bytes]u8 = undefined;
    const tmp = std.fmt.bufPrint(&buf, "{s}.hkm-tmp", .{path}) catch {
        // No room for the suffix — a direct write still beats not writing.
        return Dir.cwd().writeFile(io, .{ .sub_path = path, .data = data });
    };

    try Dir.cwd().writeFile(io, .{ .sub_path = tmp, .data = data });
    Dir.cwd().rename(tmp, Dir.cwd(), path, io) catch |e| {
        Dir.cwd().deleteFile(io, tmp) catch {};
        return e;
    };
}

/// Is `path` a symbolic link? readLink succeeds only on one.
pub fn isSymlink(io: Io, path: []const u8) bool {
    var buf: [std.fs.max_path_bytes]u8 = undefined;
    _ = Dir.cwd().readLink(io, path, &buf) catch return false;
    return true;
}

/// snake_case, from any spelling: "AddWidgets"/"addWidgets"/"add widgets" all
/// become "add_widgets". An underscore already present is preserved — which is
/// the whole point, since studly()+lower() destroys it.
pub fn snake(allocator: std.mem.Allocator, input: []const u8) ![]const u8 {
    var out: std.ArrayList(u8) = .empty;
    var prev_lower = false;
    for (input) |c| {
        if (c == ' ' or c == '-' or c == '.' or c == '/') {
            if (out.items.len > 0 and out.items[out.items.len - 1] != '_') try out.append(allocator, '_');
            prev_lower = false;
            continue;
        }
        if (c >= 'A' and c <= 'Z') {
            // Boundary only after a lower-case run, so "HTTPClient" does not
            // become "h_t_t_p_client".
            if (prev_lower and out.items.len > 0) try out.append(allocator, '_');
            try out.append(allocator, c - 'A' + 'a');
            prev_lower = false;
            continue;
        }
        try out.append(allocator, c);
        prev_lower = (c >= 'a' and c <= 'z') or (c >= '0' and c <= '9');
    }
    return out.toOwnedSlice(allocator);
}

/// Drop `suffix` from the end of `name`, case-insensitively. Used so
/// `make:seeder WidgetSeeder` produces WidgetSeeder.php, not
/// WidgetSeederSeeder.php.
pub fn stripSuffix(name: []const u8, suffix: []const u8) []const u8 {
    if (name.len <= suffix.len) return name;
    const tail = name[name.len - suffix.len ..];
    var i: usize = 0;
    while (i < suffix.len) : (i += 1) {
        const a = if (tail[i] >= 'A' and tail[i] <= 'Z') tail[i] - 'A' + 'a' else tail[i];
        const b = if (suffix[i] >= 'A' and suffix[i] <= 'Z') suffix[i] - 'A' + 'a' else suffix[i];
        if (a != b) return name;
    }
    return name[0 .. name.len - suffix.len];
}

/// Where a symlink points, duped into `allocator`; null when `path` is not one.
pub fn linkTarget(allocator: std.mem.Allocator, io: Io, path: []const u8) ?[]const u8 {
    var buf: [std.fs.max_path_bytes]u8 = undefined;
    // readLink returns the byte count written into the buffer, not a slice.
    const n = Dir.cwd().readLink(io, path, &buf) catch return null;
    return allocator.dupe(u8, buf[0..n]) catch null;
}

/// Make a file executable (0755). No-op on Windows.
///
/// A plain read-then-write copy does NOT carry the mode across, so a launcher
/// copied that way lands as 0644 and cannot be run — the install looks like it
/// worked right up until the first invocation.
pub fn chmodExec(io: Io, path: []const u8) void {
    if (@import("builtin").os.tag == .windows) return;
    const f = Dir.cwd().openFile(io, path, .{}) catch return;
    defer f.close(io);
    f.setPermissions(io, @enumFromInt(0o755)) catch {};
}

/// Set a path's own mode bits (chmod) via `Dir.cwd()`, without having to open
/// it first — works on both files and directories. No-op on Windows.
/// Best-effort — swallows the error rather than propagating it, same contract
/// as chmod600/chmodExec: a path that could not be rechmod'd (wrong owner, a
/// read-only mount) should not fail the whole command.
pub fn chmodPath(io: Io, path: []const u8, mode: u32) void {
    if (@import("builtin").os.tag == .windows) return;
    Dir.cwd().setFilePermissions(io, path, @enumFromInt(mode), .{}) catch {};
}

/// Recursively make `path` writable: `dirMode` (e.g. 0o775) on every directory
/// including `path` itself, `fileMode` (e.g. 0o664) on every regular file
/// beneath it. Fixes a runtime tree (var/, userdata/) left behind by a
/// previous run under a different owner (root inside a container, a different
/// dev's clone). Depth-limited and best-effort: a subtree that cannot be
/// opened or rechmod'd is skipped rather than failing the caller.
pub fn chmodTreeWritable(allocator: std.mem.Allocator, io: Io, path: []const u8, dirMode: u32, fileMode: u32, depth: usize) void {
    chmodPath(io, path, dirMode);
    if (depth == 0) return;

    var dir = Dir.cwd().openDir(io, path, .{ .iterate = true }) catch return;
    defer dir.close(io);
    var it = dir.iterate();
    while (it.next(io) catch null) |entry| {
        const child = std.fmt.allocPrint(allocator, "{s}/{s}", .{ path, entry.name }) catch continue;
        switch (entry.kind) {
            .directory => chmodTreeWritable(allocator, io, child, dirMode, fileMode, depth - 1),
            else => chmodPath(io, child, fileMode),
        }
    }
}

// ── path strings ────────────────────────────────────────────────────────────

/// Trim trailing path separators (keeps a lone "/").
pub fn trimSlash(path: []const u8) []const u8 {
    var p = path;
    while (p.len > 1 and (p[p.len - 1] == '/' or p[p.len - 1] == '\\')) p = p[0 .. p.len - 1];
    return p;
}

/// Drop leading "./" (or ".\\") segments so joined absolute paths stay clean.
pub fn stripDotSlash(path: []const u8) []const u8 {
    var p = path;
    while (p.len >= 2 and p[0] == '.' and (p[1] == '/' or p[1] == '\\')) p = p[2..];
    return p;
}

/// Parent directory of a path (null when there is no separator).
pub fn parentOf(path: ?[]const u8) ?[]const u8 {
    const p = path orelse return null;
    const t = trimSlash(p);
    const idx = std.mem.lastIndexOfScalar(u8, t, '/') orelse return null;
    if (idx == 0) return "/";
    return t[0..idx];
}

/// Join `base` and `sub` with a single separator.
pub fn join(allocator: std.mem.Allocator, base: []const u8, sub: []const u8) ![]const u8 {
    return std.fmt.allocPrint(allocator, "{s}/{s}", .{ base, sub });
}

/// Resolve a possibly-relative path to an absolute one via PWD. An already
/// absolute path is returned as-is; "." and "" resolve to PWD itself.
pub fn absPath(allocator: std.mem.Allocator, env: *EnvMap, raw: []const u8) ![]const u8 {
    const path = stripDotSlash(raw);
    if (path.len > 0 and (path[0] == '/' or path[0] == '\\')) return path;
    const pwd = env.get("PWD") orelse return path;
    if (pwd.len == 0) return path;
    if (path.len == 0 or std.mem.eql(u8, path, ".")) return pwd;
    return std.fmt.allocPrint(allocator, "{s}/{s}", .{ pwd, path });
}

// ── filesystem probes ─────────────────────────────────────────────────────────

/// True if `path` is accessible (relative to the process cwd).
pub fn fileExists(io: Io, path: []const u8) bool {
    Dir.cwd().access(io, path, .{}) catch return false;
    return true;
}

/// True if `path` is an openable directory under `dir`.
pub fn dirExists(dir: Dir, io: Io, path: []const u8) bool {
    var d = dir.openDir(io, path, .{}) catch return false;
    d.close(io);
    return true;
}

/// True if `path` is missing or contains no entries.
pub fn dirIsEmpty(dir: Dir, io: Io, path: []const u8) bool {
    var d = dir.openDir(io, path, .{ .iterate = true }) catch return true;
    defer d.close(io);
    var it = d.iterate();
    const first = it.next(io) catch return true;
    return first == null;
}

// ── string lists ──────────────────────────────────────────────────────────────

/// Join a string list with ", " for display.
pub fn joinList(allocator: std.mem.Allocator, items: []const []const u8) ![]const u8 {
    var out: std.ArrayList(u8) = .empty;
    for (items, 0..) |it, i| {
        if (i > 0) try out.appendSlice(allocator, ", ");
        try out.appendSlice(allocator, it);
    }
    return out.toOwnedSlice(allocator);
}

/// Shallow copy of a string list into a growable list.
pub fn dupeList(allocator: std.mem.Allocator, items: []const []const u8) !std.ArrayList([]const u8) {
    var out: std.ArrayList([]const u8) = .empty;
    try out.appendSlice(allocator, items);
    return out;
}

/// True if `needle` is present in `haystack` (string equality).
pub fn contains(haystack: []const []const u8, needle: []const u8) bool {
    for (haystack) |h| {
        if (std.mem.eql(u8, h, needle)) return true;
    }
    return false;
}

// ── case / naming helpers ─────────────────────────────────────────────────────

/// PascalCase a name: `billing-engine` / `billing_engine` → `BillingEngine`.
pub fn studly(allocator: std.mem.Allocator, name: []const u8) ![]const u8 {
    var out: std.ArrayList(u8) = .empty;
    var upper_next = true;
    for (name) |c| {
        if (c == '-' or c == '_' or c == ' ') {
            upper_next = true;
            continue;
        }
        if (upper_next) {
            try out.append(allocator, std.ascii.toUpper(c));
            upper_next = false;
        } else {
            try out.append(allocator, c);
        }
    }
    return out.toOwnedSlice(allocator);
}

/// Lowercase a string into freshly allocated memory.
pub fn lower(allocator: std.mem.Allocator, s: []const u8) ![]const u8 {
    const out = try allocator.alloc(u8, s.len);
    for (s, 0..) |c, idx| out[idx] = std.ascii.toLower(c);
    return out;
}

/// Case-insensitive ASCII string equality.
pub fn eqlIgnoreCase(a: []const u8, b: []const u8) bool {
    if (a.len != b.len) return false;
    for (a, b) |x, y| {
        if (std.ascii.toLower(x) != std.ascii.toLower(y)) return false;
    }
    return true;
}

/// True when `path` is `root` or lives beneath it (path-segment aware).
pub fn isInside(path: []const u8, root: []const u8) bool {
    const p = trimSlash(path);
    const r = trimSlash(root);
    if (std.mem.eql(u8, p, r)) return true;
    return p.len > r.len and std.mem.startsWith(u8, p, r) and p[r.len] == '/';
}

// ── time ──────────────────────────────────────────────────────────────────────

/// UTC timestamp prefix `YYYY_MM_DD_HHMMSS` for ordered filenames (migrations).
pub fn timestampPrefix(allocator: std.mem.Allocator) ![]const u8 {
    var ts: std.os.linux.timespec = undefined;
    _ = std.os.linux.clock_gettime(.REALTIME, &ts);
    const secs: u64 = @intCast(@max(ts.sec, 0));
    const es = std.time.epoch.EpochSeconds{ .secs = secs };
    const day = es.getEpochDay();
    const yd = day.calculateYearDay();
    const md = yd.calculateMonthDay();
    const ds = es.getDaySeconds();
    return std.fmt.allocPrint(allocator, "{d:0>4}_{d:0>2}_{d:0>2}_{d:0>2}{d:0>2}{d:0>2}", .{
        yd.year,
        md.month.numeric(),
        @as(u32, md.day_index) + 1,
        ds.getHoursIntoDay(),
        ds.getMinutesIntoHour(),
        ds.getSecondsIntoMinute(),
    });
}

// ── JSON ──────────────────────────────────────────────────────────────────────

/// Append a JSON string BODY (no surrounding quotes), escaping backslash and
/// double-quote — the only chars that appear in the names/paths/domains these
/// tools serialise.
pub fn appendJsonEscaped(allocator: std.mem.Allocator, out: *std.ArrayList(u8), s: []const u8) !void {
    for (s) |c| switch (c) {
        '\\' => try out.appendSlice(allocator, "\\\\"),
        '"' => try out.appendSlice(allocator, "\\\""),
        else => try out.append(allocator, c),
    };
}

/// Append a JSON-quoted, escaped string (`"…"`) to `out`.
pub fn appendJsonString(allocator: std.mem.Allocator, out: *std.ArrayList(u8), s: []const u8) !void {
    try out.append(allocator, '"');
    try appendJsonEscaped(allocator, out, s);
    try out.append(allocator, '"');
}

test "snake_case keeps underscores the caller already wrote" {
    const a = std.testing.allocator;
    const cases = [_]struct { in: []const u8, want: []const u8 }{
        .{ .in = "add_widgets", .want = "add_widgets" },
        .{ .in = "AddWidgets", .want = "add_widgets" },
        .{ .in = "addWidgets", .want = "add_widgets" },
        .{ .in = "add widgets", .want = "add_widgets" },
        .{ .in = "add_widgets_to_orders", .want = "add_widgets_to_orders" },
        .{ .in = "widgets", .want = "widgets" },
    };
    for (cases) |c| {
        const got = try snake(a, c.in);
        defer a.free(got);
        try std.testing.expectEqualStrings(c.want, got);
    }
}

test "an existing suffix is not doubled" {
    try std.testing.expectEqualStrings("Widget", stripSuffix("WidgetSeeder", "Seeder"));
    try std.testing.expectEqualStrings("Widget", stripSuffix("Widgetseeder", "Seeder"));
    try std.testing.expectEqualStrings("Widget", stripSuffix("Widget", "Seeder"));
    // Not a suffix, merely a substring.
    try std.testing.expectEqualStrings("SeederThing", stripSuffix("SeederThing", "Seeder"));
}

test "unknownFlag catches the typo that made a destructive command run for real" {
    // The exact input that deleted an install: `--dry-run` misspelled, so the
    // guard flag was ignored and `--yes` suppressed the confirmation.
    const known = [_][]const u8{ "--dry-run", "-n", "--yes", "-y", "--help", "-h" };
    const args = [_][]const u8{ "--dryrun", "--yes" };
    try std.testing.expectEqualStrings("--dryrun", unknownFlag(&args, &known).?);
}

test "unknownFlag accepts every flag a command declares" {
    const known = [_][]const u8{ "--dry-run", "-n", "--yes", "-y" };
    try std.testing.expect(unknownFlag(&[_][]const u8{ "--dry-run", "-y" }, &known) == null);
    try std.testing.expect(unknownFlag(&[_][]const u8{}, &known) == null);
}

test "unknownFlag ignores positionals and the stdin dash" {
    const known = [_][]const u8{"--yes"};
    try std.testing.expect(unknownFlag(&[_][]const u8{ "shop", "--yes" }, &known) == null);
    try std.testing.expect(unknownFlag(&[_][]const u8{"-"}, &known) == null);
    // A negative number is a positional, not a flag people expect to be rejected.
    try std.testing.expect(unknownFlag(&[_][]const u8{"/some/path"}, &known) == null);
}

test "unknownFlag compares --flag=value on the name" {
    const known = [_][]const u8{"--prefix"};
    try std.testing.expect(unknownFlag(&[_][]const u8{"--prefix=/srv/hkm"}, &known) == null);
    try std.testing.expectEqualStrings("--prefx=/srv", unknownFlag(&[_][]const u8{"--prefx=/srv"}, &known).?);
}

test "unknownFlag stops at a -- separator so passthrough args are left alone" {
    const known = [_][]const u8{"--yes"};
    const args = [_][]const u8{ "--yes", "--", "--anything-goes", "-x" };
    try std.testing.expect(unknownFlag(&args, &known) == null);
}
