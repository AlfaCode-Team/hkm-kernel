//! Installing, updating and removing plugins from their git remotes.
//!
//! The order of operations is the important part, and it is always:
//!
//!   1. RESOLVE  name → remote URL
//!   2. RESOLVE  constraint → concrete tag (never a moving branch)
//!   3. FETCH    into a staging path
//!   4. GATE     the fetched module.json's kernel constraint
//!   5. COMMIT   move into plugins/ and record in plugins.lock.json
//!
//! The gate sits AFTER the fetch because the constraint lives inside the plugin,
//! and the only way to read it for a version you do not yet have is to fetch it.
//! Staging first means a rejected plugin never lands in plugins/ — a
//! half-installed plugin that the bootstrap then tries to wire is a worse
//! outcome than a failed install.

const std = @import("std");
const git = @import("plugin_git.zig");
const lockfile = @import("plugin_lock.zig");
const pregistry = @import("plugin_registry.zig");
const sources = @import("plugin_sources.zig");
const semver = @import("semver.zig");
const banner = @import("banner.zig");
const prompt = @import("prompt.zig");
const util = @import("util.zig");

const Dir = std.Io.Dir;
const Io = std.Io;
const EnvMap = std.process.Environ.Map;

pub const Outcome = union(enum) {
    installed: lockfile.Entry,
    /// Already present at the requested version — nothing to do.
    up_to_date: lockfile.Entry,
    updated: struct { from: []const u8, to: lockfile.Entry },
    /// Refused. `why` is a complete, user-facing sentence.
    refused: []const u8,
};

pub const Options = struct {
    /// Explicit version to install ("v1.2.0"), or empty for "newest allowed".
    version: []const u8 = "",
    /// Report what would happen without touching the filesystem.
    dry_run: bool = false,
    /// Overwrite a working copy that has uncommitted changes.
    force: bool = false,
    /// Clone full history instead of --depth 1.
    full: bool = false,
};

/// Directory a plugin would be installed into for this project.
pub fn targetDir(allocator: std.mem.Allocator, projectRoot: []const u8, name: []const u8) ![]const u8 {
    return std.fs.path.join(allocator, &.{ projectRoot, "plugins", name });
}

/// Read the kernel constraint out of an already-fetched plugin directory.
fn constraintOf(allocator: std.mem.Allocator, io: Io, pluginsDir: []const u8, name: []const u8) ?[]const u8 {
    const meta = sources.readModuleMeta(allocator, io, pluginsDir, name) catch return null;
    const m = meta orelse return null;
    return m.kernel;
}

/// Turn a compatibility verdict into a refusal sentence, or null when fine.
fn gateMessage(allocator: std.mem.Allocator, env: *EnvMap, name: []const u8, constraint: ?[]const u8) !?[]const u8 {
    if (pregistry.gateBypassed(env)) return null;

    return switch (pregistry.checkKernel(constraint)) {
        .ok => null,
        .unknown_kernel => try std.fmt.allocPrint(
            allocator,
            "{s} declares a kernel requirement but this kernel's version ('{s}') could not be parsed.",
            .{ name, banner.version() },
        ),
        .bad_constraint => |c| try std.fmt.allocPrint(
            allocator,
            "{s} declares an unreadable kernel requirement ('{s}'). Fix its module.json \"kernel\" field.",
            .{ name, c },
        ),
        .incompatible => |i| try std.fmt.allocPrint(
            allocator,
            "{s} requires kernel {s} but this kernel is {s}. Upgrade the kernel (hkm upgrade), install an older plugin release (--version=…), or override with HKM_PLUGIN_IGNORE_KERNEL=1.",
            .{ name, i.required, i.kernel },
        ),
    };
}

/// Install a plugin into `<projectRoot>/plugins/<name>` from its git remote.
///
/// Idempotent: an already-present plugin at the requested version reports
/// `.up_to_date` and touches nothing.
pub fn install(
    allocator: std.mem.Allocator,
    io: Io,
    env: *EnvMap,
    projectRoot: []const u8,
    name: []const u8,
    opts: Options,
) !Outcome {
    if (!git.available(allocator, io, env)) {
        return .{ .refused = "git is not installed or not on PATH — it is required to fetch plugins." };
    }

    const remote = try pregistry.remoteFor(allocator, env, name);
    const dest = try targetDir(allocator, projectRoot, name);
    const pluginsDir = try std.fs.path.join(allocator, &.{ projectRoot, "plugins" });

    const already = git.isRepo(io, dest, allocator);

    // Resolve the constraint to a concrete tag BEFORE fetching, so we never
    // install from a moving branch.
    const tag = git.resolveVersion(allocator, io, env, remote, opts.version) catch |e| {
        return .{ .refused = try std.fmt.allocPrint(
            allocator,
            "{s}: {s} ({s})",
            .{ name, git.explain(e), remote },
        ) };
    };

    // Two separate allocPrint calls rather than one with a conditional format
    // string: std.fmt requires the format to be comptime-known, so selecting it
    // with a runtime `if` does not compile.
    const want = tag orelse {
        const msg = if (opts.version.len > 0)
            try std.fmt.allocPrint(
                allocator,
                "{s} has no release matching '{s}' on {s}. Run `hkm plugins versions {s}` to see what exists.",
                .{ name, opts.version, remote, name },
            )
        else
            try std.fmt.allocPrint(
                allocator,
                "{s} has no release tags on {s}. A plugin must be tagged before it can be installed.",
                .{ name, remote },
            );
        return .{ .refused = msg };
    };

    if (already) {
        const current = git.headTag(allocator, io, env, dest) orelse "";
        if (std.mem.eql(u8, current, want.name)) {
            return .{ .up_to_date = .{
                .name = name,
                .remote = remote,
                .version = want.name,
                .commit = git.headCommit(allocator, io, env, dest) orelse "",
                .kernel = constraintOf(allocator, io, pluginsDir, name) orelse "",
            } };
        }

        // Never discard someone's in-progress edits without being told to.
        if (!opts.force and git.isDirty(allocator, io, env, dest)) {
            return .{ .refused = try std.fmt.allocPrint(
                allocator,
                "{s} has uncommitted local changes. Commit or stash them, or pass --force to discard them.",
                .{name},
            ) };
        }

        if (opts.dry_run) {
            return .{ .updated = .{ .from = current, .to = .{
                .name = name,
                .remote = remote,
                .version = want.name,
                .kernel = constraintOf(allocator, io, pluginsDir, name) orelse "",
            } } };
        }

        git.updateTo(allocator, io, env, dest, want.name) catch |e| {
            return .{ .refused = try std.fmt.allocPrint(allocator, "{s}: {s}", .{ name, git.explain(e) }) };
        };

        // Gate AFTER the checkout — the constraint belongs to the new version,
        // not the one that was there before.
        if (try gateMessage(allocator, env, name, constraintOf(allocator, io, pluginsDir, name))) |msg| {
            // Put the previous version back so a refused update leaves a
            // working tree, not a plugin the project cannot boot with.
            if (current.len > 0) git.updateTo(allocator, io, env, dest, current) catch {};
            return .{ .refused = msg };
        }

        return .{ .updated = .{ .from = current, .to = .{
            .name = name,
            .remote = remote,
            .version = want.name,
            .commit = git.headCommit(allocator, io, env, dest) orelse "",
            .kernel = constraintOf(allocator, io, pluginsDir, name) orelse "",
        } } };
    }

    if (opts.dry_run) {
        return .{ .installed = .{ .name = name, .remote = remote, .version = want.name } };
    }

    // Stage into a sibling path so a rejected plugin never lands in plugins/.
    const staging = try std.fmt.allocPrint(allocator, "{s}.hkm-staging", .{dest});
    Dir.cwd().deleteTree(io, staging) catch {};

    git.cloneAt(allocator, io, env, remote, staging, want.name, !opts.full) catch |e| {
        Dir.cwd().deleteTree(io, staging) catch {};
        return .{ .refused = try std.fmt.allocPrint(
            allocator,
            "{s}: {s} ({s})",
            .{ name, git.explain(e), remote },
        ) };
    };

    // Read the constraint out of the STAGED copy.
    const staged_parent = std.fs.path.dirname(staging) orelse pluginsDir;
    const staged_name = std.fs.path.basename(staging);
    const constraint = constraintOf(allocator, io, staged_parent, staged_name);

    if (try gateMessage(allocator, env, name, constraint)) |msg| {
        Dir.cwd().deleteTree(io, staging) catch {};
        return .{ .refused = msg };
    }

    Dir.cwd().createDirPath(io, pluginsDir) catch {};
    Dir.cwd().rename(staging, Dir.cwd(), dest, io) catch {
        Dir.cwd().deleteTree(io, staging) catch {};
        return .{ .refused = try std.fmt.allocPrint(
            allocator,
            "{s}: fetched successfully but could not be moved into {s}.",
            .{ name, dest },
        ) };
    };

    return .{ .installed = .{
        .name = name,
        .remote = remote,
        .version = want.name,
        .commit = git.headCommit(allocator, io, env, dest) orelse "",
        .kernel = constraint orelse "",
    } };
}

/// Record an outcome in the project's lock file.
pub fn recordInLock(
    allocator: std.mem.Allocator,
    io: Io,
    projectRoot: []const u8,
    entry: lockfile.Entry,
) !void {
    var lock = try lockfile.read(allocator, io, projectRoot);
    try lock.put(allocator, entry);
    try lockfile.write(allocator, io, projectRoot, &lock, banner.version());
}

/// Print a one-line summary of an outcome. Returns the exit code it implies.
pub fn report(allocator: std.mem.Allocator, name: []const u8, outcome: Outcome, dry_run: bool) !u8 {
    switch (outcome) {
        .installed => |e| {
            prompt.ok(try std.fmt.allocPrint(allocator, "{s}{s}  {s}", .{
                if (dry_run) "would install  " else "installed  ",
                name,
                e.version,
            }));
            return 0;
        },
        .up_to_date => |e| {
            prompt.muted(try std.fmt.allocPrint(allocator, "up to date  {s}  {s}", .{ name, e.version }));
            return 0;
        },
        .updated => |u| {
            prompt.ok(try std.fmt.allocPrint(allocator, "{s}{s}  {s} → {s}", .{
                if (dry_run) "would update  " else "updated  ",
                name,
                if (u.from.len > 0) u.from else "(untagged)",
                u.to.version,
            }));
            return 0;
        },
        .refused => |why| {
            prompt.err(why);
            return 1;
        },
    }
}
