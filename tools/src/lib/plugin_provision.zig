//! Install every plugin a project's own `app/bootstrap/app.php` wires.
//!
//! Reads the bootstrap rather than taking an explicit list, so the set
//! installed can never drift from what the template/project actually enables.
//! Shared by `hkm new` (a freshly scaffolded project) and `hkm install` (an
//! existing project whose gitignored `plugins/` did not travel with a git
//! clone) — both need the identical fetch-and-lock behaviour.

const std = @import("std");
const prompt = @import("prompt.zig");
const util = @import("util.zig");
const installer = @import("plugin_install.zig");
const lockfile = @import("plugin_lock.zig");
const plugin_boot = @import("plugin_bootstrap.zig");

const Dir = std.Io.Dir;
const Io = std.Io;
const EnvMap = std.process.Environ.Map;

pub const Options = struct {
    /// Run each plugin's own test suite while installing. Off by default — a
    /// project routinely wires a dozen-plus already-released plugins, and
    /// testing each costs a composer install plus a phpunit run.
    verify: bool = false,
};

/// Record an installed plugin in plugins.lock.json, naming it if that fails.
///
/// The install itself succeeded, so this is not fatal — but a silent failure
/// leaves the lock disagreeing with what is on disk, and the user with no idea
/// which plugin to re-add.
fn recordOrWarn(
    allocator: std.mem.Allocator,
    io: Io,
    projectRoot: []const u8,
    name: []const u8,
    entry: lockfile.Entry,
) void {
    installer.recordInLock(allocator, io, projectRoot, entry) catch {
        const msg = std.fmt.allocPrint(
            allocator,
            "{s}: installed, but could not be recorded in plugins.lock.json — run `hkm plugins add {s}` to repair the lock.",
            .{ name, name },
        ) catch return;
        prompt.warn(msg);
    };
}

/// Install every plugin `<projectRoot>/app/bootstrap/app.php` wires. Returns
/// the number that could NOT be installed (0 when the bootstrap enables none,
/// or when every enabled plugin installed cleanly).
pub fn installEnabled(allocator: std.mem.Allocator, io: Io, env: *EnvMap, projectRoot: []const u8, opts: Options) !usize {
    const bootstrap = try util.join(allocator, projectRoot, "app/bootstrap/app.php");
    const source = Dir.cwd().readFileAlloc(io, bootstrap, allocator, .limited(4 * 1024 * 1024)) catch return 0;

    var aliases: std.ArrayList(plugin_boot.Alias) = .empty;
    try plugin_boot.collectAliases(allocator, source, &aliases);

    var enabled: std.ArrayList(plugin_boot.Enabled) = .empty;
    try plugin_boot.collectEnabled(allocator, source, aliases.items, &enabled);

    if (enabled.items.len == 0) return 0;

    prompt.section("Installing plugins");

    var ok: usize = 0;
    // Names, not just a count: the message that follows is the only place the
    // user learns WHICH plugins are missing, and "3 of 19 failed" leaves them
    // diffing the bootstrap against plugins/ to find out.
    var failed: std.ArrayList([]const u8) = .empty;
    for (enabled.items) |e| {
        const outcome = installer.install(allocator, io, env, projectRoot, e.name, .{
            .interactive = false,
            .verify = opts.verify,
        }) catch {
            try failed.append(allocator, e.name);
            continue;
        };
        switch (outcome) {
            .refused => |why| {
                try failed.append(allocator, e.name);
                prompt.warn(why);
            },
            .installed, .up_to_date, .linked, .updated => {
                ok += 1;
                _ = installer.report(allocator, e.name, outcome, false) catch {};
                // A lockfile write that fails is NOT a successful install:
                // swallowing it left the command reporting success while
                // plugins.lock.json did not record the plugin, so the next
                // `hkm plugins` run cannot tell it is already there.
                switch (outcome) {
                    .installed, .up_to_date, .linked => |entry| recordOrWarn(allocator, io, projectRoot, e.name, entry),
                    .updated => |u| recordOrWarn(allocator, io, projectRoot, e.name, u.to),
                    .refused => {},
                }
            },
        }
    }

    if (failed.items.len > 0) {
        prompt.warn(try std.fmt.allocPrint(
            allocator,
            "{d} of {d} plugin(s) could not be installed — the project will not boot until they are:",
            .{ failed.items.len, enabled.items.len },
        ));
        // One command per plugin, and no separate list of bare names above it:
        // the commands already name every one, and printing both meant reading
        // the same names twice. `install` takes a SINGLE plugin — its second
        // positional is the project path, so space-joining the names would
        // install the first and treat the rest as a directory.
        for (failed.items) |name| {
            prompt.muted(try std.fmt.allocPrint(allocator, "  hkm plugins install {s}", .{name}));
        }
    } else {
        prompt.ok(try std.fmt.allocPrint(allocator, "{d} plugin(s) installed", .{ok}));
    }

    return failed.items.len;
}
