//! `hkm install --check` — report, change nothing: can the web server's account
//! actually use the files a deploy does NOT ship?
//!
//! A project deployed by pushing its git tree somewhere (git-ftp, rsync, a CI
//! job) only ever uploads what git tracks. What git ignores is created ON the
//! server, by whoever happened to create it: `.env` and its per-domain siblings
//! (`.env.ekkula.ateiug`) written over SSH as root, `var/` and `userdata/` made
//! by the first request or by a CLI run under sudo. Those are exactly the files
//! whose ownership drifts, and the failure is quiet: `LoadEnvironment` skips an
//! unreadable `.env.*` without a word, so a key "set" in it simply is not.
//!
//! This walks those paths and evaluates them the way the kernel's permission
//! check does for the named account (uid, then group membership, then other),
//! instead of pattern-matching mode bits — `0640 root:root` and `0640
//! deploy:www-data` look identical in a mode table and only one of them works.
//!
//! Read-only by construction: it stats and lists, and never chmods, chowns or
//! creates. The fixer is `hkm install --production`, and the summary names it.
//!
//! Not modelled: POSIX ACLs and SELinux labels. Both can deny what the mode
//! bits allow; a clean report means the MODE BITS are right.

const std = @import("std");
const builtin = @import("builtin");
const prompt = @import("prompt.zig");
const util = @import("util.zig");

const Dir = std.Io.Dir;
const Io = std.Io;
const EnvMap = std.process.Environ.Map;

// ── stat with owner ──────────────────────────────────────────────────────────

pub const Info = struct {
    uid: u32,
    gid: u32,
    /// Full st_mode: type bits and permission bits.
    mode: u32,

    pub fn perm(self: Info) u32 {
        return self.mode & 0o7777;
    }
    pub fn isDir(self: Info) bool {
        return self.mode & 0o170000 == 0o040000;
    }
    pub fn isFile(self: Info) bool {
        return self.mode & 0o170000 == 0o100000;
    }
    pub fn isLink(self: Info) bool {
        return self.mode & 0o170000 == 0o120000;
    }
};

/// lstat() with the owner and group, which `Dir.statFile` does not expose.
/// Reached per platform, like `install_scope.isRoot`: the raw statx syscall on
/// Linux (no libc needed for a static launcher), fstatat through libc elsewhere.
pub fn lstat(allocator: std.mem.Allocator, path: []const u8) ?Info {
    const z = allocator.dupeZ(u8, path) catch return null;
    switch (builtin.os.tag) {
        .windows => return null,
        .linux => {
            const linux = std.os.linux;
            var buf: linux.Statx = undefined;
            const rc = linux.statx(linux.AT.FDCWD, z, linux.AT.SYMLINK_NOFOLLOW, .{ .TYPE = true, .MODE = true, .UID = true, .GID = true }, &buf);
            if (linux.errno(rc) != .SUCCESS) return null;
            return .{ .uid = buf.uid, .gid = buf.gid, .mode = buf.mode };
        },
        else => {
            var st: std.c.Stat = undefined;
            if (std.c.fstatat(std.c.AT.FDCWD, z, &st, std.c.AT.SYMLINK_NOFOLLOW) != 0) return null;
            return .{ .uid = @intCast(st.uid), .gid = @intCast(st.gid), .mode = @intCast(st.mode) };
        },
    }
}

// ── accounts ─────────────────────────────────────────────────────────────────

/// The account whose access is being judged — the PHP-FPM pool's user.
pub const Account = struct {
    name: []const u8,
    uid: u32,
    gids: []const u32,
};

pub const R: u32 = 0o4;
pub const W: u32 = 0o2;
pub const X: u32 = 0o1;

/// Whether `acct` has every bit in `want` on a path with this owner and mode.
///
/// The kernel's own order, and it is an ORDER, not a union: the owner class
/// applies to the owner even when the group or other class would grant more —
/// a `0070` file its owner cannot read is legal and means exactly that. root
/// bypasses the bits entirely (for the purposes of this report).
pub fn allows(info: Info, acct: Account, want: u32) bool {
    if (acct.uid == 0) return true;
    const p = info.mode & 0o777;
    const bits = if (info.uid == acct.uid)
        (p >> 6) & 7
    else if (inGroups(info.gid, acct.gids))
        (p >> 3) & 7
    else
        p & 7;
    return bits & want == want;
}

fn inGroups(gid: u32, gids: []const u32) bool {
    for (gids) |g| if (g == gid) return true;
    return false;
}

/// Run a short query (`id`, `getent`) and return its trimmed stdout, or null.
fn query(allocator: std.mem.Allocator, io: Io, env: *EnvMap, argv: []const []const u8) ?[]const u8 {
    const res = std.process.run(allocator, io, .{ .argv = argv, .environ_map = env }) catch return null;
    switch (res.term) {
        .exited => |c| if (c != 0) return null,
        else => return null,
    }
    const out = std.mem.trim(u8, res.stdout, " \t\r\n");
    return if (out.len == 0) null else out;
}

/// uid and every group of `name`, through the system's own `id` — which already
/// honours /etc/nsswitch.conf (files, LDAP, sssd), as `chown` does for the fixer.
pub fn resolveAccount(allocator: std.mem.Allocator, io: Io, env: *EnvMap, name: []const u8) ?Account {
    const uid_s = query(allocator, io, env, &.{ "id", "-u", name }) orelse return null;
    const uid = std.fmt.parseInt(u32, uid_s, 10) catch return null;
    const groups_s = query(allocator, io, env, &.{ "id", "-G", name }) orelse return null;

    var gids: std.ArrayList(u32) = .empty;
    var it = std.mem.tokenizeAny(u8, groups_s, " \t");
    while (it.next()) |tok| {
        const g = std.fmt.parseInt(u32, tok, 10) catch continue;
        gids.append(allocator, g) catch return null;
    }
    return .{ .name = name, .uid = uid, .gids = gids.items };
}

/// gid of a group NAME. `getent` exists on every Linux server this targets;
/// null elsewhere (macOS), and the group half of an ownership check is skipped
/// with a note rather than guessed.
fn groupId(allocator: std.mem.Allocator, io: Io, env: *EnvMap, name: []const u8) ?u32 {
    if (std.fmt.parseInt(u32, name, 10)) |n| return n else |_| {}
    const line = query(allocator, io, env, &.{ "getent", "group", name }) orelse return null;
    var it = std.mem.splitScalar(u8, line, ':');
    _ = it.next(); // name
    _ = it.next(); // password
    const gid_s = it.next() orelse return null;
    return std.fmt.parseInt(u32, gid_s, 10) catch null;
}

/// Display names for uids/gids, looked up once each.
const Names = struct {
    allocator: std.mem.Allocator,
    io: Io,
    env: *EnvMap,
    users: std.AutoHashMapUnmanaged(u32, []const u8) = .empty,
    groups: std.AutoHashMapUnmanaged(u32, []const u8) = .empty,

    fn user(self: *Names, uid: u32) []const u8 {
        if (self.users.get(uid)) |n| return n;
        const num = std.fmt.allocPrint(self.allocator, "{d}", .{uid}) catch return "?";
        const name = query(self.allocator, self.io, self.env, &.{ "id", "-nu", num }) orelse num;
        self.users.put(self.allocator, uid, name) catch {};
        return name;
    }

    fn group(self: *Names, gid: u32) []const u8 {
        if (self.groups.get(gid)) |n| return n;
        const num = std.fmt.allocPrint(self.allocator, "{d}", .{gid}) catch return "?";
        // `getent group <gid>` → "www-data:x:33:"; the name is the first field.
        const out = if (query(self.allocator, self.io, self.env, &.{ "getent", "group", num })) |line|
            line[0 .. std.mem.indexOfScalar(u8, line, ':') orelse line.len]
        else
            num;
        self.groups.put(self.allocator, gid, out) catch {};
        return out;
    }

    fn owner(self: *Names, info: Info) []const u8 {
        return std.fmt.allocPrint(self.allocator, "{s}:{s}", .{ self.user(info.uid), self.group(info.gid) }) catch "?";
    }
};

// ── expected ownership ───────────────────────────────────────────────────────

/// `--owner` as the check understands it: `user`, `user:group` or `:group`,
/// resolved to ids. A half that cannot be resolved is null and not checked.
pub const Owner = struct {
    label: []const u8,
    uid: ?u32 = null,
    gid: ?u32 = null,
    unresolved: ?[]const u8 = null,
};

fn resolveOwner(allocator: std.mem.Allocator, io: Io, env: *EnvMap, spec: []const u8) Owner {
    var o = Owner{ .label = spec };
    const colon = std.mem.indexOfScalar(u8, spec, ':');
    const user = if (colon) |c| spec[0..c] else spec;
    const group = if (colon) |c| spec[c + 1 ..] else "";

    if (user.len > 0) {
        if (query(allocator, io, env, &.{ "id", "-u", user })) |s| {
            o.uid = std.fmt.parseInt(u32, s, 10) catch null;
        }
        if (o.uid == null) o.unresolved = user;
    }
    if (group.len > 0) {
        o.gid = groupId(allocator, io, env, group);
        if (o.gid == null) o.unresolved = group;
    }
    return o;
}

// ── the check ────────────────────────────────────────────────────────────────

pub const Options = struct {
    /// The PHP-FPM pool account whose access is judged.
    as: []const u8,
    /// Expected owner (`--owner` / HKM_PROD_OWNER), or null to skip ownership.
    owner: ?[]const u8 = null,
    /// Runtime directories a boot expects (install.zig's runtime_dirs).
    runtime_dirs: []const []const u8,
    /// Subtrees the application writes (install.zig's writable_subdirs).
    writable_subdirs: []const []const u8,
    /// The mode `hkm install --production` gives `.env*`, for the report.
    secret_mode: u32 = 0o640,
};

/// How many paths of one kind of problem to list before summarising.
const shown_per_kind = 10;

const Finding = struct {
    list: std.ArrayList([]const u8) = .empty,
    count: usize = 0,

    fn add(self: *Finding, allocator: std.mem.Allocator, line: []const u8) void {
        self.count += 1;
        if (self.list.items.len < shown_per_kind) self.list.append(allocator, line) catch {};
    }

    fn print(self: Finding, allocator: std.mem.Allocator, title: []const u8) void {
        if (self.count == 0) return;
        prompt.warn(std.fmt.allocPrint(allocator, "{s} ({d})", .{ title, self.count }) catch title);
        for (self.list.items) |l| prompt.muted(l);
        if (self.count > self.list.items.len) {
            prompt.muted(std.fmt.allocPrint(allocator, "  … and {d} more", .{self.count - self.list.items.len}) catch "  …");
        }
    }
};

const Walk = struct {
    allocator: std.mem.Allocator,
    io: Io,
    acct: Account,
    owner: ?Owner,
    names: *Names,
    root: []const u8,
    entries: usize = 0,
    not_writable: Finding = .{},
    no_setgid: Finding = .{},
    wrong_owner: Finding = .{},
    uninspected: Finding = .{},

    fn rel(self: *Walk, path: []const u8) []const u8 {
        if (std.mem.startsWith(u8, path, self.root) and path.len > self.root.len + 1) return path[self.root.len + 1 ..];
        return path;
    }

    fn line(self: *Walk, path: []const u8, info: Info, why: []const u8) []const u8 {
        return std.fmt.allocPrint(self.allocator, "  {s}  {s}  {o:0>4}  {s}", .{
            self.rel(path), self.names.owner(info), info.perm(), why,
        }) catch path;
    }

    fn checkOwner(self: *Walk, path: []const u8, info: Info) void {
        const o = self.owner orelse return;
        const uid_bad = if (o.uid) |u| info.uid != u else false;
        const gid_bad = if (o.gid) |g| info.gid != g else false;
        if (uid_bad or gid_bad) {
            self.wrong_owner.add(self.allocator, self.line(path, info, std.fmt.allocPrint(self.allocator, "expected {s}", .{o.label}) catch ""));
        }
    }

    /// One writable subtree: every directory rwx and every file rw for the
    /// account; directories setgid so files the pool creates keep the group.
    fn tree(self: *Walk, path: []const u8, depth: usize) void {
        const info = lstat(self.allocator, path) orelse return;
        if (info.isLink()) return; // the kernel follows it; its target is someone else's tree
        self.entries += 1;
        self.checkOwner(path, info);

        if (info.isFile()) {
            if (!allows(info, self.acct, R | W)) {
                self.not_writable.add(self.allocator, self.line(path, info, std.fmt.allocPrint(self.allocator, "{s} cannot read+write it", .{self.acct.name}) catch ""));
            }
            return;
        }
        if (!info.isDir()) return;

        if (!allows(info, self.acct, R | W | X)) {
            self.not_writable.add(self.allocator, self.line(path, info, std.fmt.allocPrint(self.allocator, "{s} cannot create files in it", .{self.acct.name}) catch ""));
        }
        if (info.perm() & 0o2000 == 0) {
            self.no_setgid.add(self.allocator, self.line(path, info, "no setgid — files created here take the creator's group"));
        }
        if (depth == 0) return;

        var dir = Dir.cwd().openDir(self.io, path, .{ .iterate = true }) catch {
            // Denied to whoever is RUNNING the check, which is not the pool.
            self.uninspected.add(self.allocator, std.fmt.allocPrint(self.allocator, "  {s}", .{self.rel(path)}) catch path);
            return;
        };
        defer dir.close(self.io);
        var it = dir.iterate();
        while (it.next(self.io) catch null) |entry| {
            const child = std.fmt.allocPrint(self.allocator, "{s}/{s}", .{ path, entry.name }) catch continue;
            self.tree(child, depth - 1);
        }
    }
};

/// A real env file, not the committed template.
fn isEnvFile(name: []const u8) bool {
    if (!std.mem.startsWith(u8, name, ".env")) return false;
    if (name.len > 4 and name[4] != '.') return false; // `.envrc`, `.environment`
    return !std.mem.eql(u8, name, ".env.example") and !std.mem.endsWith(u8, name, ".example") and !std.mem.endsWith(u8, name, ".dist");
}

/// Run the check. Returns the exit code: 0 clean, 1 problems, 2 cannot check.
pub fn check(allocator: std.mem.Allocator, io: Io, env: *EnvMap, root: []const u8, opts: Options) u8 {
    if (builtin.os.tag == .windows) {
        prompt.warn("--check reads POSIX owners and mode bits — not available on Windows.");
        return 2;
    }

    const acct = resolveAccount(allocator, io, env, opts.as) orelse {
        prompt.err(std.fmt.allocPrint(allocator, "No account '{s}' on this machine.", .{opts.as}) catch "Unknown account.");
        prompt.muted("Pass the account PHP-FPM runs as: --as=<user> (see `user =` in the pool config), or set HKM_POOL_USER.");
        return 2;
    };

    var names = Names{ .allocator = allocator, .io = io, .env = env };
    const owner: ?Owner = if (opts.owner) |spec| resolveOwner(allocator, io, env, spec) else null;

    prompt.intro("Check permissions — gitignored runtime paths (read-only, nothing is changed)");
    prompt.muted(root);
    prompt.muted(std.fmt.allocPrint(allocator, "judged as {s} (uid {d}){s}", .{
        acct.name, acct.uid,
        if (acct.uid == 0) @as([]const u8, " — root passes every check; pass --as=<pool user>") else "",
    }) catch "");
    if (owner) |o| {
        prompt.muted(std.fmt.allocPrint(allocator, "expected owner {s}", .{o.label}) catch "");
        if (o.unresolved) |u| prompt.warn(std.fmt.allocPrint(allocator, "'{s}' is not a user/group here (or getent is missing) — that half of the ownership check is skipped.", .{u}) catch "");
    }
    prompt.blank();

    var problems: usize = 0;

    // ── 1. Can the pool reach the project at all? ─────────────────────────
    {
        var blocked: std.ArrayList([]const u8) = .empty;
        var cursor: ?[]const u8 = root;
        while (cursor) |p| : (cursor = util.parentOf(p)) {
            if (lstat(allocator, p)) |info| {
                if (!allows(info, acct, X)) {
                    blocked.append(allocator, std.fmt.allocPrint(allocator, "  {s}  {s}  {o:0>4}", .{ p, names.owner(info), info.perm() }) catch p) catch {};
                }
            }
            if (std.mem.eql(u8, p, "/")) break;
        }
        if (blocked.items.len > 0) {
            problems += blocked.items.len;
            prompt.warn(std.fmt.allocPrint(allocator, "{s} cannot pass through these directories, so it cannot reach the project:", .{acct.name}) catch "");
            for (blocked.items) |b| prompt.muted(b);
        } else {
            prompt.ok(std.fmt.allocPrint(allocator, "{s} can reach the project", .{acct.name}) catch "reachable");
        }
    }

    // ── 2. .env and every per-domain / per-environment sibling ───────────
    prompt.section("Environment files");
    {
        var found: usize = 0;
        var dir = Dir.cwd().openDir(io, root, .{ .iterate = true }) catch {
            prompt.err("Cannot list the project root — run the check with sudo.");
            return 2;
        };
        defer dir.close(io);
        var it = dir.iterate();
        while (it.next(io) catch null) |entry| {
            if (!isEnvFile(entry.name)) continue;
            const path = std.fmt.allocPrint(allocator, "{s}/{s}", .{ root, entry.name }) catch continue;
            const info = lstat(allocator, path) orelse continue;
            found += 1;

            var why: std.ArrayList([]const u8) = .empty;
            // A symlinked .env is followed by PHP; judge the link only for existence.
            if (!info.isLink()) {
                if (!allows(info, acct, R)) why.append(allocator, std.fmt.allocPrint(allocator, "{s} CANNOT READ IT — its values are silently ignored", .{acct.name}) catch "") catch {};
                if (info.perm() & 0o007 != 0) why.append(allocator, "readable by every account on the server — holds APP_KEY and secrets") catch {};
                if (info.perm() & 0o020 != 0) why.append(allocator, "group-writable — the pool could rewrite its own config") catch {};
                if (owner) |o| {
                    if ((o.uid != null and info.uid != o.uid.?) or (o.gid != null and info.gid != o.gid.?)) {
                        why.append(allocator, std.fmt.allocPrint(allocator, "expected owner {s}", .{o.label}) catch "") catch {};
                    }
                }
            }

            const head = std.fmt.allocPrint(allocator, "{s:<28} {s:<22} {o:0>4}", .{ entry.name, names.owner(info), info.perm() }) catch entry.name;
            if (why.items.len == 0) {
                prompt.ok(head);
            } else {
                problems += why.items.len;
                prompt.warn(head);
                for (why.items) |w| prompt.muted(std.fmt.allocPrint(allocator, "    ✗ {s}", .{w}) catch w);
            }
        }
        if (found == 0) {
            problems += 1;
            prompt.warn(".env is missing — the application cannot boot without APP_KEY.");
        } else {
            prompt.muted(std.fmt.allocPrint(allocator, "`hkm install --production` gives these {o:0>4}: owner read-write, group read, nothing for others.", .{opts.secret_mode}) catch "");
        }
    }

    // ── 3. Runtime directories present ────────────────────────────────────
    prompt.section("Runtime directories");
    {
        var missing: usize = 0;
        for (opts.runtime_dirs) |sub| {
            const path = std.fmt.allocPrint(allocator, "{s}/{s}", .{ root, sub }) catch continue;
            if (lstat(allocator, path) == null) {
                missing += 1;
                prompt.warn(std.fmt.allocPrint(allocator, "{s} is missing", .{sub}) catch sub);
            }
        }
        problems += missing;
        if (missing == 0) prompt.ok("All present");
    }

    // ── 4. var/ and userdata/ — writable by the pool, all the way down ────
    prompt.section("Writable trees");
    var walk = Walk{ .allocator = allocator, .io = io, .acct = acct, .owner = owner, .names = &names, .root = root };
    for (opts.writable_subdirs) |sub| {
        const path = std.fmt.allocPrint(allocator, "{s}/{s}", .{ root, sub }) catch continue;
        if (lstat(allocator, path) == null) continue; // reported above when it is a runtime dir
        walk.tree(path, 32);
    }
    walk.not_writable.print(allocator, std.fmt.allocPrint(allocator, "{s} cannot write", .{acct.name}) catch "not writable");
    walk.wrong_owner.print(allocator, "Wrong owner");
    walk.no_setgid.print(allocator, "Directories without setgid (a warning — works now, drifts later)");
    walk.uninspected.print(allocator, "Could not be listed by the account running this check — re-run with sudo to inspect them");
    problems += walk.not_writable.count + walk.wrong_owner.count;
    if (walk.not_writable.count == 0 and walk.wrong_owner.count == 0) {
        prompt.ok(std.fmt.allocPrint(allocator, "{d} entries under {s} — all writable by {s}", .{
            walk.entries, std.mem.join(allocator, ", ", opts.writable_subdirs) catch "var, userdata", acct.name,
        }) catch "writable");
    }

    // ── Summary ───────────────────────────────────────────────────────────
    prompt.blank();
    if (problems == 0) {
        prompt.outro(if (walk.uninspected.count > 0)
            "No problems in what could be inspected — re-run with sudo to cover the rest"
        else
            "Ownership and permissions are correct");
        return 0;
    }

    prompt.note("To fix (changes the whole project tree, keeps the deploy account as owner):");
    prompt.muted(std.fmt.allocPrint(allocator, "  sudo hkm install --production --owner={s} --no-register --no-key --no-install --no-plugins", .{
        if (opts.owner) |o| o else "':<pool group>'",
    }) catch "");
    prompt.outro(std.fmt.allocPrint(allocator, "{d} problem(s) found — nothing was changed", .{problems}) catch "problems found");
    return 1;
}

// ── tests ────────────────────────────────────────────────────────────────────

const www = Account{ .name = "www-data", .uid = 33, .gids = &.{33} };

test "owner class applies to the owner even when group would grant more" {
    // 0070 owned by www-data: the group may read it, its owner may not.
    try std.testing.expect(!allows(.{ .uid = 33, .gid = 99, .mode = 0o100070 }, www, R));
}

test "the pool reads a .env through its group" {
    // deploy:www-data 0640 — the split-ownership shape --production produces.
    try std.testing.expect(allows(.{ .uid = 1000, .gid = 33, .mode = 0o100640 }, www, R));
    try std.testing.expect(!allows(.{ .uid = 1000, .gid = 33, .mode = 0o100640 }, www, W));
}

test "a root-owned 0600 .env is unreadable to the pool" {
    // The failure this command exists to find: LoadEnvironment skips it silently.
    try std.testing.expect(!allows(.{ .uid = 0, .gid = 0, .mode = 0o100600 }, www, R));
}

test "a 2770 var/ directory is writable through the group" {
    try std.testing.expect(allows(.{ .uid = 1000, .gid = 33, .mode = 0o042770 }, www, R | W | X));
    try std.testing.expect(!allows(.{ .uid = 1000, .gid = 50, .mode = 0o042770 }, www, W));
}

test "root passes every check" {
    const root_acct = Account{ .name = "root", .uid = 0, .gids = &.{0} };
    try std.testing.expect(allows(.{ .uid = 1000, .gid = 1000, .mode = 0o100000 }, root_acct, R | W));
}

test "env file names" {
    try std.testing.expect(isEnvFile(".env"));
    try std.testing.expect(isEnvFile(".env.local"));
    try std.testing.expect(isEnvFile(".env.ekkula.ateiug"));
    try std.testing.expect(!isEnvFile(".env.example"));
    try std.testing.expect(!isEnvFile(".env.production.example"));
    try std.testing.expect(!isEnvFile(".envrc"));
    try std.testing.expect(!isEnvFile(".gitignore"));
}

test "mode type helpers" {
    const d = Info{ .uid = 0, .gid = 0, .mode = 0o042770 };
    try std.testing.expect(d.isDir() and !d.isFile() and !d.isLink());
    try std.testing.expectEqual(@as(u32, 0o2770), d.perm());
}
