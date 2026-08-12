//! Git operations for fetching and updating plugins.
//!
//! Everything shells out to `git`. That is deliberate: it inherits the user's
//! credential helpers, SSH agent, proxy settings and `insteadOf` rewrites for
//! free. Reimplementing the protocol would mean reimplementing all of that
//! badly, and the first private or mirrored plugin repo would break.
//!
//! Fetching is done at a TAG, never a moving branch. A plugin pinned to `main`
//! silently changes under a project between two deploys, which is the failure a
//! lock file exists to prevent.

const std = @import("std");
const semver = @import("semver.zig");
const prompt = @import("prompt.zig");

const Dir = std.Io.Dir;
const Io = std.Io;
const EnvMap = std.process.Environ.Map;

pub const GitError = error{
    /// `git` is not installed or not on PATH.
    GitMissing,
    /// The remote could not be reached or does not exist.
    RemoteUnreachable,
    /// The requested ref does not exist on the remote.
    RefNotFound,
    /// A git invocation failed for another reason.
    CommandFailed,
};

/// Is git available at all? Checked once before a batch of operations so the
/// user gets one clear message instead of N confusing ones.
pub fn available(allocator: std.mem.Allocator, io: Io, env: *EnvMap) bool {
    const res = std.process.run(allocator, io, .{
        .argv = &.{ "git", "--version" },
        .environ_map = env,
    }) catch return false;
    return switch (res.term) {
        .exited => |c| c == 0,
        else => false,
    };
}

/// Run git capturing stdout. Returns null when the command failed.
fn capture(allocator: std.mem.Allocator, io: Io, env: *EnvMap, argv: []const []const u8) ?[]const u8 {
    const res = std.process.run(allocator, io, .{ .argv = argv, .environ_map = env }) catch return null;
    switch (res.term) {
        .exited => |c| if (c != 0) return null,
        else => return null,
    }
    return res.stdout;
}

/// Run git with stdio inherited, so clone/fetch progress reaches the terminal.
fn passthrough(io: Io, env: *EnvMap, argv: []const []const u8) !u8 {
    var child = try std.process.spawn(io, .{
        .argv = argv,
        .environ_map = env,
        .stdin = .inherit,
        .stdout = .inherit,
        .stderr = .inherit,
    });
    const term = try child.wait(io);
    return switch (term) {
        .exited => |c| c,
        else => 1,
    };
}

/// A release tag on a remote.
pub const Tag = struct {
    name: []const u8, // as written on the remote, e.g. "v1.2.0"
    version: semver.Version,
};

/// Every `v*`-shaped tag on the remote, newest first.
///
/// Uses `ls-remote`, so it costs one network round trip and no clone — the
/// difference between listing versions for 29 plugins in a second and cloning
/// 29 repositories to read their tags.
pub fn listTags(allocator: std.mem.Allocator, io: Io, env: *EnvMap, url: []const u8) GitError![]Tag {
    // Retried, because a single transient DNS or TLS hiccup otherwise surfaces
    // as "the repo does not exist" — a message that sends people looking for a
    // permissions problem they do not have. Three quick attempts turn the most
    // common flake into a non-event; a genuinely missing repo still fails, just
    // a second later.
    var out: ?[]const u8 = null;
    var attempt: usize = 0;
    while (attempt < 3) : (attempt += 1) {
        out = capture(allocator, io, env, &.{ "git", "ls-remote", "--tags", "--refs", url });
        if (out != null) break;
        if (attempt + 1 < 3) io.sleep(.{ .nanoseconds = 400 * std.time.ns_per_ms }, .awake) catch {};
    }
    const listing = out orelse return GitError.RemoteUnreachable;

    var tags: std.ArrayList(Tag) = .empty;
    var lines = std.mem.splitScalar(u8, listing, '\n');
    while (lines.next()) |line| {
        const marker = "refs/tags/";
        const idx = std.mem.indexOf(u8, line, marker) orelse continue;
        const name = std.mem.trim(u8, line[idx + marker.len ..], " \t\r");
        if (name.len == 0) continue;
        const v = semver.Version.parse(name) orelse continue; // skip non-semver tags
        const owned = allocator.dupe(u8, name) catch continue;
        tags.append(allocator, .{ .name = owned, .version = v }) catch continue;
    }

    // Newest first.
    std.mem.sort(Tag, tags.items, {}, struct {
        fn lessThan(_: void, a: Tag, b: Tag) bool {
            return a.version.order(b.version) == .gt;
        }
    }.lessThan);

    return tags.items;
}

/// The highest tag satisfying `constraint` (empty = highest overall).
///
/// Returns null when the remote has no matching release. Callers must treat
/// that as an error rather than falling back to a branch: installing from a
/// moving ref is what makes a build unreproducible.
pub fn resolveVersion(
    allocator: std.mem.Allocator,
    io: Io,
    env: *EnvMap,
    url: []const u8,
    constraint: []const u8,
) GitError!?Tag {
    const tags = try listTags(allocator, io, env, url);
    if (tags.len == 0) return null;

    const want = std.mem.trim(u8, constraint, " \t\r\n");
    if (want.len == 0) return tags[0]; // newest

    for (tags) |t| {
        // A bad constraint is the caller's problem to report; skipping here
        // would silently install the newest version instead.
        const ok = semver.satisfies(t.version, want) catch return GitError.RefNotFound;
        if (ok) return t;
    }
    return null;
}

/// Clone `url` into `dest` at `ref`.
///
/// Shallow (`--depth 1`) and single-branch: a plugin is consumed, not developed,
/// from a project, so its history is dead weight — for the larger plugins that
/// is the difference between a few hundred KB and several MB per install.
/// Use `--full` at the call site when a contributor genuinely needs history.
pub fn cloneAt(
    allocator: std.mem.Allocator,
    io: Io,
    env: *EnvMap,
    url: []const u8,
    dest: []const u8,
    ref: []const u8,
    shallow: bool,
) GitError!void {
    _ = allocator;

    var argv: [14][]const u8 = undefined;
    var n: usize = 0;
    argv[n] = "git";
    n += 1;
    // Installing at a tag necessarily detaches HEAD; git's advice about that is
    // correct for a human developing the repo and pure noise for a package
    // manager that always works this way.
    argv[n] = "-c";
    n += 1;
    argv[n] = "advice.detachedHead=false";
    n += 1;
    argv[n] = "clone";
    n += 1;
    argv[n] = "--quiet";
    n += 1;
    if (shallow) {
        argv[n] = "--depth";
        n += 1;
        argv[n] = "1";
        n += 1;
    }
    if (ref.len > 0) {
        argv[n] = "--branch";
        n += 1;
        argv[n] = ref;
        n += 1;
    }
    argv[n] = url;
    n += 1;
    argv[n] = dest;
    n += 1;

    const code = passthrough(io, env, argv[0..n]) catch return GitError.CommandFailed;
    if (code != 0) return GitError.RemoteUnreachable;
}

/// Is `dir` a git working copy?
pub fn isRepo(io: Io, dir: []const u8, allocator: std.mem.Allocator) bool {
    const git_dir = std.fs.path.join(allocator, &.{ dir, ".git" }) catch return false;
    Dir.cwd().access(io, git_dir, .{}) catch return false;
    return true;
}

/// Fetch tags and hard-checkout `ref` in an existing working copy.
///
/// `--force` on the fetch so a re-tagged release (the tag moved on the remote)
/// still converges instead of failing forever with "would clobber existing tag".
pub fn updateTo(
    allocator: std.mem.Allocator,
    io: Io,
    env: *EnvMap,
    dir: []const u8,
    ref: []const u8,
) GitError!void {
    _ = allocator;

    const fetch = [_][]const u8{ "git", "-C", dir, "fetch", "--tags", "--force", "--quiet", "--depth", "1", "origin" };
    const fcode = passthrough(io, env, &fetch) catch return GitError.CommandFailed;
    if (fcode != 0) return GitError.RemoteUnreachable;

    const co = [_][]const u8{ "git", "-c", "advice.detachedHead=false", "-C", dir, "checkout", "--quiet", "--force", ref };
    const ccode = passthrough(io, env, &co) catch return GitError.CommandFailed;
    if (ccode != 0) return GitError.RefNotFound;
}

/// The commit currently checked out, or null.
pub fn headCommit(allocator: std.mem.Allocator, io: Io, env: *EnvMap, dir: []const u8) ?[]const u8 {
    const out = capture(allocator, io, env, &.{ "git", "-C", dir, "rev-parse", "HEAD" }) orelse return null;
    const t = std.mem.trim(u8, out, " \t\r\n");
    if (t.len == 0) return null;
    return t;
}

/// The tag currently checked out, or null when HEAD is not exactly on a tag.
pub fn headTag(allocator: std.mem.Allocator, io: Io, env: *EnvMap, dir: []const u8) ?[]const u8 {
    const out = capture(allocator, io, env, &.{ "git", "-C", dir, "describe", "--tags", "--exact-match" }) orelse return null;
    const t = std.mem.trim(u8, out, " \t\r\n");
    if (t.len == 0) return null;
    return t;
}

/// Does the working copy have uncommitted changes?
///
/// Checked before any destructive update. A contributor editing a plugin in
/// place must not have that work silently discarded by a checkout --force, so
/// callers refuse to update a dirty tree unless explicitly told to.
pub fn isDirty(allocator: std.mem.Allocator, io: Io, env: *EnvMap, dir: []const u8) bool {
    const out = capture(allocator, io, env, &.{ "git", "-C", dir, "status", "--porcelain" }) orelse return false;
    return std.mem.trim(u8, out, " \t\r\n").len > 0;
}

/// Human-readable reason for a GitError, for a single-line error message.
pub fn explain(e: GitError) []const u8 {
    return switch (e) {
        GitError.GitMissing => "git is not installed or not on PATH",
        GitError.RemoteUnreachable => "could not reach the remote (offline, private, or the repo does not exist)",
        GitError.RefNotFound => "the requested version does not exist on the remote",
        GitError.CommandFailed => "the git command failed",
    };
}
