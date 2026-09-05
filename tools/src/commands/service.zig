//! `hkm service` — run a project's queue worker as a supervised system service.
//!
//!   hkm service                      show the unit that WOULD be generated
//!   hkm service write                write it into <project>/var/service/
//!   hkm service install --start      place it in the system, reload, start it
//!   hkm service remove               stop it, disable it, delete the unit
//!
//! `hkm worker --queue=mails` is a foreground process: it dies with the
//! terminal, it does not come back after a crash or a reboot, and nothing
//! collects its output. Every deployment therefore ends up hand-writing the
//! same unit file, and hand-writing it is where the two failure modes live:
//!
//!   * **TimeoutStopSec.** The worker traps SIGTERM and finishes the job in
//!     flight before exiting — that is the whole reason it is safe to redeploy.
//!     systemd's default stop timeout is 90s, but a unit written without one in
//!     mind gets SIGKILLed mid-transaction the first time a job runs long.
//!   * **A version-pinned ExecStart.** `/opt/homebrew/Cellar/hkm/1.13.1/…` and
//!     `<kernel>/vendor/autoload.php` are both real paths today and both gone
//!     after the next upgrade. The unit therefore invokes the LAUNCHER
//!     (`hkm worker -p <root>`), which self-locates the kernel from its own
//!     path — so a kernel upgrade does not silently break the queue.
//!
//! Two supervisors are supported, chosen from the host and overridable with
//! `--platform` so a Mac can generate the Linux unit it will deploy:
//!
//!   systemd  /etc/systemd/system/<name>.service        (--system, the default on Linux)
//!            ~/.config/systemd/user/<name>.service     (--user)
//!   launchd  /Library/LaunchDaemons/<label>.plist      (--system)
//!            ~/Library/LaunchAgents/<label>.plist      (--user, the default on macOS)
//!
//! NOTHING outside the project is touched unless `install` or `remove` is the
//! verb — the default prints the file and the exact commands, because a unit
//! that starts a database-writing process at boot is not something to install
//! as a side effect of asking what it would look like.

const std = @import("std");
const builtin = @import("builtin");
const prompt = @import("../lib/prompt.zig");
const util = @import("../lib/util.zig");
const services = @import("../lib/services.zig");
const envfile = @import("../lib/env_file.zig");
const install_scope = @import("../lib/install_scope.zig");
const run_cmd = @import("run.zig");

const Dir = std.Io.Dir;
const Io = std.Io;
const EnvMap = std.process.Environ.Map;

const Action = enum { preview, write, install, remove };
const Platform = enum { systemd, launchd };
const Scope = enum { system, user };

/// What `ExecStart` invokes.
///
/// `hkm` — the launcher, which self-locates the kernel from its own path. The
/// default, because a kernel upgrade that moves a version-stamped install
/// directory then cannot break the unit.
///
/// `php` — php and the worker entry point directly, the shape a hand-written
/// unit usually has. Correct when the server has no launcher installed at all —
/// a deploy artefact or a container where the kernel arrives through composer —
/// but it MUST pin HKM_GLOBAL_AUTOLOAD, and that pin is a path that an upgrade
/// can invalidate. Use it when there is no launcher, not as a preference.
const ExecMode = enum { hkm, php };

/// Long enough for a slow job to finish after SIGTERM; short enough that a
/// wedged worker still gets killed rather than blocking a reboot forever.
const stop_timeout_secs = 90;
const restart_delay_secs = 5;

const Options = struct {
    action: Action = .preview,
    /// A project PATH (dir holding proj.json) OR a registered NAME. "" = cwd.
    target: []const u8 = "",
    queue: ?[]const u8 = null,
    name: ?[]const u8 = null,
    /// systemd `User=` / `Group=`, as `user` or `user:group`.
    run_as: ?[]const u8 = null,
    memory: ?[]const u8 = null,
    max: ?[]const u8 = null,
    scope: ?Scope = null,
    platform: ?Platform = null,
    hkm_bin: ?[]const u8 = null,
    php_bin: ?[]const u8 = null,
    exec_mode: ExecMode = .hkm,
    /// `write`: the directory to write into instead of <project>/var/service.
    out: ?[]const u8 = null,
    /// `install`: enable + start immediately, not just place the file.
    start: bool = false,
    force: bool = false,
    yes: bool = false,
    /// Report every write and every command, and perform none of them.
    dry_run: bool = false,
    help: bool = false,
};

const known_flags = [_][]const u8{
    "-h",        "--help", "-q",        "--queue", "--name",     "--run-as",
    "--memory",  "--max",  "--system",  "--user",  "--platform", "--hkm-bin",
    "--php-bin", "--exec", "--out",     "--start", "--force",    "-y",
    "--yes",     "-n",     "--dry-run",
};

pub fn run(allocator: std.mem.Allocator, io: Io, env: *EnvMap, args: []const []const u8) !u8 {
    if (util.unknownFlag(args[@min(2, args.len)..], &known_flags)) |bad| {
        prompt.err(try std.fmt.allocPrint(allocator, "Unknown option '{s}'.", .{bad}));
        prompt.hint("hkm service --help", "list the options");
        return 2;
    }

    const opts = (try parse(allocator, args)) orelse {
        printHelp();
        return 2;
    };
    if (opts.help) {
        printHelp();
        return 0;
    }

    const root = (try services.resolveRoot(allocator, io, env, opts.target)) orelse {
        prompt.err(try std.fmt.allocPrint(
            allocator,
            "'{s}' is neither a project folder (with proj.json) nor a registered name.",
            .{if (opts.target.len == 0) "." else opts.target},
        ));
        prompt.hint("hkm service <path|name>", "target a specific project");
        prompt.hint("hkm list", "show what is registered");
        return 1;
    };

    // The worker entry has to exist, or the unit would restart-loop forever on
    // a file that is not there — systemd's least legible failure.
    const entry = try std.fmt.allocPrint(allocator, "{s}/app/worker/run.php", .{root});
    if (!util.fileExists(io, entry)) {
        prompt.err(try std.fmt.allocPrint(allocator, "No worker entry point at {s}", .{entry}));
        prompt.muted("This project has no worker surface to supervise.");
        return 1;
    }

    const platform = opts.platform orelse defaultPlatform() orelse {
        prompt.err("No service manager for this host (systemd and launchd are the supported ones).");
        prompt.hint("--platform=systemd", "generate a Linux unit anyway (to deploy elsewhere)");
        return 1;
    };
    const scope = opts.scope orelse defaultScope(platform);

    // buildSpec rejects bad INPUT as well as failing on I/O, and an input
    // mistake must not reach main() as a bare `error: PhpNotFound`.
    const spec = buildSpec(allocator, io, env, root, opts, platform, scope) catch |e| switch (e) {
        error.PhpNotFound => {
            prompt.err("--exec=php needs an absolute php binary, and php is not on PATH.");
            prompt.hint("--php-bin=/usr/bin/php", "name the one the SERVER will use");
            prompt.muted("systemd refuses a unit whose ExecStart is not an absolute path.");
            return 1;
        },
        error.UnsafeQueueName => {
            prompt.err("That queue name cannot go into a unit file.");
            prompt.muted("Letters, digits, '-', '_', '.' and ':' only — anything else could add");
            prompt.muted("an argument or a directive to the generated unit.");
            return 1;
        },
        error.UnsafeValue => {
            prompt.err("--max / --memory take a plain number.");
            return 1;
        },
        error.UnsafeUnitName => {
            prompt.err("--name has no usable characters in it.");
            prompt.muted("The unit name is lowercased and reduced to letters, digits and dashes.");
            return 1;
        },
        else => return e,
    };

    return switch (opts.action) {
        .preview => previewAction(allocator, spec),
        .write => writeAction(allocator, io, spec, opts),
        .install => installAction(allocator, io, env, spec, opts),
        .remove => removeAction(allocator, io, env, spec, opts),
    };
}

// --------------------------------------------------------------------------
// the resolved service description — everything both renderers need
// --------------------------------------------------------------------------

const Spec = struct {
    platform: Platform,
    scope: Scope,
    /// Absolute project root.
    root: []const u8,
    /// Project name from proj.json, for the human-readable description.
    project: []const u8,
    queue: []const u8,
    /// systemd unit name / launchd file stem, without the extension.
    name: []const u8,
    /// Reverse-DNS launchd label. Equal to `name` on systemd; unused there.
    label: []const u8,
    /// Absolute path to the `hkm` launcher the unit will execute.
    exec: []const u8,
    argv: []const []const u8,
    user: ?[]const u8,
    group: ?[]const u8,
    /// Where the file belongs for this platform + scope.
    dest: []const u8,
    /// Staging copy inside the project, always written by `write`/`install`.
    staged: []const u8,
    /// launchd only — it has no journal, so it needs real files.
    log_out: []const u8,
    log_err: []const u8,
    /// Absolute php binary, pinned into the unit as HKM_PHP_BIN. Null when it
    /// could not be resolved, in which case the unit falls back to PATH and the
    /// caller has been warned.
    php: ?[]const u8,
    /// PATH the service runs with — php's own directory ahead of the usual set.
    path_env: []const u8,
    exec_mode: ExecMode,
    /// --exec=php only: the kernel autoload the unit must pin, because there is
    /// no launcher in the command line to self-locate it.
    autoload: ?[]const u8,
    contents: []const u8,
};

fn buildSpec(
    allocator: std.mem.Allocator,
    io: Io,
    env: *EnvMap,
    root: []const u8,
    opts: Options,
    platform: Platform,
    scope: Scope,
) !Spec {
    const project = try projectName(allocator, io, root);
    const queue = opts.queue orelse (try envQueue(allocator, io, root)) orelse "default";
    if (!isSafeValue(queue)) return error.UnsafeQueueName;

    const name = if (opts.name) |n| try slug(allocator, n) else try defaultName(allocator, project, queue);
    if (name.len == 0) return error.UnsafeUnitName;

    const label = try std.fmt.allocPrint(allocator, "com.alfacode.{s}", .{name});
    const php = resolvePhp(allocator, io, env, opts);

    // --exec=php puts php itself in ExecStart, which systemd requires to be an
    // ABSOLUTE path — a unit naming a bare `php` never starts, and says only
    // "Failed to locate executable". So an unresolved php is fatal here, where
    // the launcher path would merely have been a fallback.
    if (opts.exec_mode == .php and php == null) return error.PhpNotFound;

    const exec = switch (opts.exec_mode) {
        .hkm => try resolveLauncher(allocator, io, env, opts),
        .php => php.?,
    };

    var argv: std.ArrayList([]const u8) = .empty;
    switch (opts.exec_mode) {
        .hkm => {
            try argv.append(allocator, "worker");
            try argv.append(allocator, "-p");
            try argv.append(allocator, root);
        },
        // Absolute, not `app/worker/run.php`. A relative argument only resolves
        // because WorkingDirectory happens to be set, so the two lines silently
        // depend on each other — and dropping WorkingDirectory then fails with
        // "Could not open input file", naming nothing that explains why.
        .php => try argv.append(allocator, try std.fmt.allocPrint(allocator, "{s}/app/worker/run.php", .{root})),
    }
    try argv.append(allocator, try std.fmt.allocPrint(allocator, "--queue={s}", .{queue}));
    if (opts.max) |m| {
        if (!isSafeValue(m)) return error.UnsafeValue;
        try argv.append(allocator, try std.fmt.allocPrint(allocator, "--max-iterations={s}", .{m}));
    }
    if (opts.memory) |m| {
        if (!isSafeValue(m)) return error.UnsafeValue;
        try argv.append(allocator, try std.fmt.allocPrint(allocator, "--memory={s}", .{m}));
    }

    // systemd `User=`/`Group=` are rejected inside a --user unit (it already
    // runs as that user), so identity is a system-scope concern only.
    var user: ?[]const u8 = null;
    var group: ?[]const u8 = null;
    if (platform == .systemd and scope == .system) {
        const spec = opts.run_as orelse invokingUser(env);
        if (spec) |s| {
            if (std.mem.indexOfScalar(u8, s, ':')) |i| {
                user = s[0..i];
                if (i + 1 < s.len) group = s[i + 1 ..];
            } else {
                user = s;
            }
        }
    }

    const ext = if (platform == .systemd) "service" else "plist";
    const stem = if (platform == .systemd) name else label;
    const filename = try std.fmt.allocPrint(allocator, "{s}.{s}", .{ stem, ext });

    var s = Spec{
        .platform = platform,
        .scope = scope,
        .root = root,
        .project = project,
        .queue = queue,
        .name = name,
        .label = label,
        .exec = exec,
        .argv = argv.items,
        .user = user,
        .group = group,
        .dest = try util.join(allocator, try destDir(allocator, env, platform, scope), filename),
        .staged = try std.fmt.allocPrint(allocator, "{s}/var/service/{s}", .{ root, filename }),
        .log_out = try std.fmt.allocPrint(allocator, "{s}/var/log/{s}.out.log", .{ root, name }),
        .log_err = try std.fmt.allocPrint(allocator, "{s}/var/log/{s}.err.log", .{ root, name }),
        .php = php,
        .path_env = try servicePath(allocator, php),
        .exec_mode = opts.exec_mode,
        .autoload = if (opts.exec_mode == .php)
            try services.resolveAutoload(allocator, io, env)
        else
            null,
        .contents = "",
    };

    s.contents = switch (platform) {
        .systemd => try renderSystemd(allocator, io, env, s),
        .launchd => try renderLaunchd(allocator, s),
    };
    return s;
}

// --------------------------------------------------------------------------
// actions
// --------------------------------------------------------------------------

fn previewAction(allocator: std.mem.Allocator, s: Spec) u8 {
    header(allocator, s);
    prompt.blank();
    prompt.raw(s.contents);
    prompt.section("Install it");
    prompt.item("hkm service install", "place it and reload the service manager");
    prompt.item("hkm service install --start", "…and enable + start it now");
    prompt.item("hkm service write", "only write a copy into var/service/ for review");
    prompt.outro("nothing was written");
    return 0;
}

fn writeAction(allocator: std.mem.Allocator, io: Io, s: Spec, opts: Options) !u8 {
    const path = if (opts.out) |d|
        try util.join(allocator, util.trimSlash(d), std.fs.path.basename(s.dest))
    else
        s.staged;

    if (util.fileExists(io, path) and !opts.force) {
        prompt.err(try std.fmt.allocPrint(allocator, "{s} already exists.", .{path}));
        prompt.hint("--force", "overwrite it");
        return 1;
    }

    header(allocator, s);

    if (opts.dry_run) {
        would(allocator, "write  {s}  ({d} bytes)", .{ path, s.contents.len });
        prompt.blank();
        printManualSteps(allocator, s, path);
        return dryOutro();
    }

    try writeTo(io, path, s.contents);
    prompt.ok(try std.fmt.allocPrint(allocator, "wrote  {s}", .{path}));
    prompt.blank();
    printManualSteps(allocator, s, path);
    prompt.outro("written — not installed");
    return 0;
}

fn installAction(allocator: std.mem.Allocator, io: Io, env: *EnvMap, s: Spec, opts: Options) !u8 {
    header(allocator, s);

    if (util.fileExists(io, s.dest) and !opts.force) {
        prompt.err(try std.fmt.allocPrint(allocator, "{s} already exists.", .{s.dest}));
        prompt.hint("--force", "replace the installed unit");
        return 1;
    }

    const dir = std.fs.path.dirname(s.dest) orelse ".";

    // The ONE filesystem touch a dry run makes, and it is deliberate: canWrite
    // probes by creating and deleting a file, and only in a directory this
    // command is about to write to anyway. Without it the dry run cannot say
    // whether the install would need sudo, which is the most useful thing it
    // has to report. On a directory that needs root the probe writes nothing —
    // it simply fails.
    const needs_root = !util.canWrite(io, dir);

    if (opts.dry_run) {
        would(allocator, "write  {s}  ({d} bytes)", .{ s.staged, s.contents.len });
        if (needs_root) {
            would(allocator, "run    sudo mkdir -p {s}", .{dir});
            would(allocator, "run    sudo cp -f {s} {s}", .{ s.staged, s.dest });
            would(allocator, "run    sudo chmod 644 {s}", .{s.dest});
        } else {
            would(allocator, "write  {s}", .{s.dest});
        }
        if (s.platform == .launchd) {
            would(allocator, "create {s}/var/log", .{s.root});
        }
        if (try reloadArgv(allocator, s, needs_root)) |reload| {
            would(allocator, "run    {s}", .{shellLine(allocator, reload)});
        }
        if (opts.start) {
            would(allocator, "run    {s}", .{shellLine(allocator, try startArgv(allocator, s, needs_root))});
        } else {
            prompt.blank();
            printStartSteps(allocator, s);
        }
        return dryOutro();
    }

    // A copy always lands in the project first: it is what makes the installed
    // unit reviewable and diffable afterwards, and it is the file the elevated
    // copy below reads from (a `sudo cp` cannot take its input from memory).
    try writeTo(io, s.staged, s.contents);
    prompt.ok(try std.fmt.allocPrint(allocator, "staged  {s}", .{s.staged}));

    if (needs_root and !opts.yes) {
        prompt.warn(try std.fmt.allocPrint(allocator, "{s} is not writable — this needs sudo.", .{dir}));
        if (!prompt.confirm(io, "Install with sudo?", true)) {
            prompt.muted("cancelled");
            printManualSteps(allocator, s, s.staged);
            return 1;
        }
    }

    if (needs_root) {
        var mk = [_][]const u8{ "sudo", "mkdir", "-p", dir };
        _ = run_cmd.spawnWait(io, env, &mk) catch 1;
        var cp = [_][]const u8{ "sudo", "cp", "-f", s.staged, s.dest };
        if ((run_cmd.spawnWait(io, env, &cp) catch 1) != 0) {
            prompt.err("Could not copy the unit into place.");
            printManualSteps(allocator, s, s.staged);
            return 1;
        }
        var ch = [_][]const u8{ "sudo", "chmod", "644", s.dest };
        _ = run_cmd.spawnWait(io, env, &ch) catch 1;
    } else {
        try writeTo(io, s.dest, s.contents);
    }
    prompt.ok(try std.fmt.allocPrint(allocator, "installed  {s}", .{s.dest}));

    // launchd writes the worker's stdout/stderr to real files and will NOT
    // create their directory — a missing var/log is a silent non-start.
    if (s.platform == .launchd) {
        Dir.cwd().createDirPath(io, try std.fmt.allocPrint(allocator, "{s}/var/log", .{s.root})) catch {};
    }

    if (try reloadArgv(allocator, s, needs_root)) |reload| {
        if ((run_cmd.spawnWait(io, env, reload) catch 1) != 0) {
            prompt.warn("The service manager did not reload cleanly — run the steps below by hand.");
            printManualSteps(allocator, s, s.dest);
            return 1;
        }
        prompt.ok(try std.fmt.allocPrint(allocator, "reloaded  {s}", .{@tagName(s.platform)}));
    }

    if (!opts.start) {
        prompt.blank();
        printStartSteps(allocator, s);
        prompt.outro("installed — not started");
        return 0;
    }

    const start = try startArgv(allocator, s, needs_root);
    if ((run_cmd.spawnWait(io, env, start) catch 1) != 0) {
        prompt.err("Could not start the service.");
        printStartSteps(allocator, s);
        return 1;
    }

    prompt.ok(try std.fmt.allocPrint(allocator, "started  {s}", .{s.name}));
    if (s.platform == .systemd and s.scope == .user) {
        prompt.warn("A --user unit stops when you log out.");
        prompt.hint("loginctl enable-linger $USER", "keep it running between logins");
    }
    prompt.blank();
    printLogSteps(allocator, s);
    prompt.outro("running");
    return 0;
}

fn removeAction(allocator: std.mem.Allocator, io: Io, env: *EnvMap, s: Spec, opts: Options) !u8 {
    header(allocator, s);

    if (!util.fileExists(io, s.dest)) {
        prompt.warn(try std.fmt.allocPrint(allocator, "Nothing installed at {s}", .{s.dest}));
        return 0;
    }

    const dir = std.fs.path.dirname(s.dest) orelse ".";
    const needs_root = !util.canWrite(io, dir);

    if (opts.dry_run) {
        if (loadedEnoughToStop(allocator, io, env, s)) {
            would(allocator, "run    {s}", .{shellLine(allocator, try stopArgv(allocator, s, needs_root))});
        }
        if (needs_root) {
            would(allocator, "run    sudo rm -f {s}", .{s.dest});
        } else {
            would(allocator, "delete {s}", .{s.dest});
        }
        if (try reloadArgv(allocator, s, needs_root)) |reload| {
            would(allocator, "run    {s}", .{shellLine(allocator, reload)});
        }
        prompt.muted(try std.fmt.allocPrint(allocator, "the staged copy at {s} would be kept", .{s.staged}));
        return dryOutro();
    }

    if (!opts.yes and !prompt.confirm(io, "Stop the service and delete the unit?", false)) {
        prompt.muted("cancelled");
        return 1;
    }

    // Stop BEFORE deleting: a unit file removed while the service is running
    // leaves an orphan process the manager can no longer address by name.
    if (loadedEnoughToStop(allocator, io, env, s)) {
        const stop = try stopArgv(allocator, s, needs_root);
        _ = run_cmd.spawnWait(io, env, stop) catch 1;
    }

    if (needs_root) {
        var rm = [_][]const u8{ "sudo", "rm", "-f", s.dest };
        if ((run_cmd.spawnWait(io, env, &rm) catch 1) != 0) {
            prompt.err(try std.fmt.allocPrint(allocator, "Could not delete {s}", .{s.dest}));
            return 1;
        }
    } else {
        Dir.cwd().deleteFile(io, s.dest) catch |e| {
            prompt.err(try std.fmt.allocPrint(allocator, "Could not delete {s} ({t})", .{ s.dest, e }));
            return 1;
        };
    }

    if (try reloadArgv(allocator, s, needs_root)) |reload| {
        _ = run_cmd.spawnWait(io, env, reload) catch 1;
    }

    prompt.ok(try std.fmt.allocPrint(allocator, "removed  {s}", .{s.dest}));
    prompt.muted(try std.fmt.allocPrint(allocator, "the staged copy at {s} was kept", .{s.staged}));
    prompt.outro("removed");
    return 0;
}

// --------------------------------------------------------------------------
// rendering
// --------------------------------------------------------------------------

/// One ExecStart token, quoted when it contains whitespace.
///
/// systemd splits the command line on whitespace, so a project under
/// `/Users/me/My Projects/shop` produces a unit that fails to start with a
/// message about the wrong number of arguments.
fn systemdToken(allocator: std.mem.Allocator, tok: []const u8) ![]const u8 {
    const needs = std.mem.indexOfAny(u8, tok, " \t") != null;
    if (!needs) return tok;
    return std.fmt.allocPrint(allocator, "\"{s}\"", .{tok});
}

fn renderSystemd(allocator: std.mem.Allocator, io: Io, env: *EnvMap, s: Spec) ![]const u8 {
    var o: std.ArrayList(u8) = .empty;
    const a = allocator;

    try o.appendSlice(a, "# Generated by `hkm service`. Regenerate with the same command;\n");
    try o.appendSlice(a, "# hand edits survive nothing but a rewrite, so keep them in proj config.\n\n");

    try o.appendSlice(a, "[Unit]\n");
    try o.appendSlice(a, try std.fmt.allocPrint(a, "Description=HKM queue worker — {s} ({s})\n", .{ s.project, s.queue }));
    try o.appendSlice(a, "After=network-online.target\n");
    try o.appendSlice(a, "Wants=network-online.target\n\n");

    try o.appendSlice(a, "[Service]\n");
    try o.appendSlice(a, "Type=simple\n");
    if (s.user) |u| try o.appendSlice(a, try std.fmt.allocPrint(a, "User={s}\n", .{u}));
    if (s.group) |g| try o.appendSlice(a, try std.fmt.allocPrint(a, "Group={s}\n", .{g}));
    try o.appendSlice(a, try std.fmt.allocPrint(a, "WorkingDirectory={s}\n", .{s.root}));

    try o.appendSlice(a, "ExecStart=");
    try o.appendSlice(a, try systemdToken(a, s.exec));
    for (s.argv) |tok| {
        try o.appendSlice(a, " ");
        try o.appendSlice(a, try systemdToken(a, tok));
    }
    try o.appendSlice(a, "\n");

    try o.appendSlice(a, "Restart=always\n");
    try o.appendSlice(a, try std.fmt.allocPrint(a, "RestartSec={d}\n", .{restart_delay_secs}));
    try o.appendSlice(a,
        \\
        \\# The worker traps SIGTERM and finishes the job in flight before it exits,
        \\# which is what makes a redeploy safe. TimeoutStopSec must therefore be
        \\# longer than the slowest job — when it is not, systemd SIGKILLs a worker
        \\# mid-transaction and the job is lost with no error anywhere.
        \\
    );
    try o.appendSlice(a, "KillSignal=SIGTERM\n");
    try o.appendSlice(a, try std.fmt.allocPrint(a, "TimeoutStopSec={d}\n", .{stop_timeout_secs}));
    try o.appendSlice(a, "StandardOutput=journal\n");
    try o.appendSlice(a, "StandardError=journal\n");
    try o.appendSlice(a, try std.fmt.allocPrint(a, "SyslogIdentifier={s}\n", .{s.name}));

    // A service does not inherit a login shell's PATH, so php is pinned rather
    // than searched for. See resolvePhp() for what the failure looks like when
    // it is not: `error: FileNotFound`, and nothing naming php anywhere.
    try o.appendSlice(a, "\n# A service starts with a minimal PATH, so the php binary is pinned here\n");
    try o.appendSlice(a, "# rather than searched for. Update it if php moves.\n");
    try o.appendSlice(a, try std.fmt.allocPrint(a, "Environment=PATH={s}\n", .{s.path_env}));
    if (s.exec_mode == .hkm) {
        if (s.php) |php| {
            try o.appendSlice(a, try std.fmt.allocPrint(a, "Environment=HKM_PHP_BIN={s}\n", .{php}));
        }

        // The KERNEL path is deliberately NOT pinned in launcher mode: the
        // launcher self-locates it, and a version-stamped install directory
        // invalidates a pinned path on the next upgrade.
        try o.appendSlice(a,
            \\
            \\# The kernel needs no variable — the launcher finds it from its own path.
            \\# Pin these ONLY on a host with several kernel installs, and expect to
            \\# revisit them after an upgrade moves the directory:
            \\
        );
        if (try services.resolveKernelHome(allocator, io, env, null)) |home| {
            try o.appendSlice(a, try std.fmt.allocPrint(a, "# Environment=HKM_KERNEL_HOME={s}\n", .{home}));
        }
        if (try services.resolveProjectsDir(allocator, io, env)) |dir| {
            try o.appendSlice(a, try std.fmt.allocPrint(a, "# Environment=HKM_PROJECTS_DIR={s}\n", .{dir}));
        }
    } else {
        // --exec=php: nothing in this command line can find the kernel on its
        // own, so the autoload has to be stated. It is a real path to a real
        // install, which means an upgrade that moves that directory breaks the
        // unit — the cost of not going through the launcher.
        try o.appendSlice(a,
            \\
            \\# --exec=php: nothing here self-locates the kernel, so its autoload is
            \\# pinned. Re-run `hkm service` after an upgrade moves the kernel.
            \\
        );
        if (s.autoload) |al| {
            try o.appendSlice(a, try std.fmt.allocPrint(a, "Environment=HKM_GLOBAL_AUTOLOAD={s}\n", .{al}));
            try o.appendSlice(a, try std.fmt.allocPrint(a, "Environment=PSP_GLOBAL_AUTOLOAD={s}\n", .{al}));
        } else {
            try o.appendSlice(a, "# Environment=HKM_GLOBAL_AUTOLOAD=/path/to/kernel/vendor/autoload.php\n");
        }
    }

    try o.appendSlice(a, "\n[Install]\n");
    try o.appendSlice(a, if (s.scope == .system) "WantedBy=multi-user.target\n" else "WantedBy=default.target\n");

    return o.items;
}

/// XML text escaping for the plist. Paths legitimately contain `&`, and one
/// unescaped ampersand makes the whole file unparseable to launchd.
fn xmlEscape(allocator: std.mem.Allocator, in: []const u8) ![]const u8 {
    var o: std.ArrayList(u8) = .empty;
    for (in) |c| switch (c) {
        '&' => try o.appendSlice(allocator, "&amp;"),
        '<' => try o.appendSlice(allocator, "&lt;"),
        '>' => try o.appendSlice(allocator, "&gt;"),
        '"' => try o.appendSlice(allocator, "&quot;"),
        '\'' => try o.appendSlice(allocator, "&apos;"),
        else => try o.append(allocator, c),
    };
    return o.items;
}

fn renderLaunchd(allocator: std.mem.Allocator, s: Spec) ![]const u8 {
    var o: std.ArrayList(u8) = .empty;
    const a = allocator;

    try o.appendSlice(a,
        \\<?xml version="1.0" encoding="UTF-8"?>
        \\<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
        \\<plist version="1.0">
        \\<dict>
        \\
    );
    try o.appendSlice(a, try std.fmt.allocPrint(
        a,
        "  <key>Label</key>\n  <string>{s}</string>\n\n",
        .{try xmlEscape(a, s.label)},
    ));

    try o.appendSlice(a, "  <key>ProgramArguments</key>\n  <array>\n");
    try o.appendSlice(a, try std.fmt.allocPrint(a, "    <string>{s}</string>\n", .{try xmlEscape(a, s.exec)}));
    for (s.argv) |tok| {
        try o.appendSlice(a, try std.fmt.allocPrint(a, "    <string>{s}</string>\n", .{try xmlEscape(a, tok)}));
    }
    try o.appendSlice(a, "  </array>\n\n");

    try o.appendSlice(a, try std.fmt.allocPrint(
        a,
        "  <key>WorkingDirectory</key>\n  <string>{s}</string>\n\n",
        .{try xmlEscape(a, s.root)},
    ));

    // launchd hands an agent a PATH of /usr/bin:/bin:/usr/sbin:/sbin, which does
    // not include /opt/homebrew/bin — so php is pinned rather than searched for.
    // See resolvePhp(): without this the worker dies with `error: FileNotFound`
    // and no mention of php anywhere in the log.
    try o.appendSlice(a, "  <key>EnvironmentVariables</key>\n  <dict>\n");
    try o.appendSlice(a, try std.fmt.allocPrint(
        a,
        "    <key>PATH</key>\n    <string>{s}</string>\n",
        .{try xmlEscape(a, s.path_env)},
    ));
    if (s.exec_mode == .hkm) {
        if (s.php) |php| {
            try o.appendSlice(a, try std.fmt.allocPrint(
                a,
                "    <key>HKM_PHP_BIN</key>\n    <string>{s}</string>\n",
                .{try xmlEscape(a, php)},
            ));
        }
    } else if (s.autoload) |al| {
        // --exec=php: nothing in ProgramArguments can self-locate the kernel.
        const esc = try xmlEscape(a, al);
        try o.appendSlice(a, try std.fmt.allocPrint(
            a,
            "    <key>HKM_GLOBAL_AUTOLOAD</key>\n    <string>{s}</string>\n",
            .{esc},
        ));
        try o.appendSlice(a, try std.fmt.allocPrint(
            a,
            "    <key>PSP_GLOBAL_AUTOLOAD</key>\n    <string>{s}</string>\n",
            .{esc},
        ));
    }
    try o.appendSlice(a, "  </dict>\n\n");

    try o.appendSlice(a, "  <key>RunAtLoad</key>\n  <true/>\n");
    try o.appendSlice(a, "  <key>KeepAlive</key>\n  <true/>\n");
    try o.appendSlice(a, try std.fmt.allocPrint(
        a,
        "  <key>ThrottleInterval</key>\n  <integer>{d}</integer>\n",
        .{restart_delay_secs},
    ));

    // launchd's own default is 20 seconds between SIGTERM and SIGKILL, which is
    // shorter than plenty of jobs. Same reasoning as TimeoutStopSec above.
    try o.appendSlice(a, try std.fmt.allocPrint(
        a,
        "  <key>ExitTimeOut</key>\n  <integer>{d}</integer>\n\n",
        .{stop_timeout_secs},
    ));

    // launchd has no journal: without these two the worker's output — including
    // every uncaught exception — goes nowhere at all.
    try o.appendSlice(a, try std.fmt.allocPrint(
        a,
        "  <key>StandardOutPath</key>\n  <string>{s}</string>\n",
        .{try xmlEscape(a, s.log_out)},
    ));
    try o.appendSlice(a, try std.fmt.allocPrint(
        a,
        "  <key>StandardErrorPath</key>\n  <string>{s}</string>\n",
        .{try xmlEscape(a, s.log_err)},
    ));

    try o.appendSlice(a, "</dict>\n</plist>\n");
    return o.items;
}

// --------------------------------------------------------------------------
// service-manager command lines
// --------------------------------------------------------------------------

fn systemctl(allocator: std.mem.Allocator, s: Spec, sudo: bool, verbs: []const []const u8) ![]const []const u8 {
    var v: std.ArrayList([]const u8) = .empty;
    if (sudo and s.scope == .system) try v.append(allocator, "sudo");
    try v.append(allocator, "systemctl");
    if (s.scope == .user) try v.append(allocator, "--user");
    for (verbs) |x| try v.append(allocator, x);
    return v.items;
}

/// launchd's domain target: `system` for a daemon, `gui/<uid>` for an agent.
fn launchDomain(allocator: std.mem.Allocator, s: Spec) ![]const u8 {
    if (s.scope == .system) return "system";
    return std.fmt.allocPrint(allocator, "gui/{d}", .{currentUid()});
}

/// The invoking user's uid, for launchd's `gui/<uid>` domain target.
///
/// Same shape as util.writeFileAtomic's pid lookup: the linux syscall directly,
/// libc everywhere else, and a stand-in on Windows where the whole notion is
/// meaningless (and launchd does not exist).
/// Is there anything for the stop command to act on?
///
/// Only a launchd USER agent is probed, and only to keep `launchctl bootout`
/// from printing "Boot-out failed: 3: No such process" over a perfectly normal
/// removal of a unit that was never started. Everything else answers true: a
/// system-domain query may need privileges we have not asked for yet, and
/// guessing "not running" there would delete the unit out from under a live
/// worker — a far worse outcome than one line of noise.
fn loadedEnoughToStop(allocator: std.mem.Allocator, io: Io, env: *EnvMap, s: Spec) bool {
    if (s.platform != .launchd or s.scope != .user) return true;

    const target = std.fmt.allocPrint(allocator, "{s}/{s}", .{
        launchDomain(allocator, s) catch return true,
        s.label,
    }) catch return true;

    const res = std.process.run(allocator, io, .{
        .argv = &.{ "launchctl", "print", target },
        .environ_map = env,
    }) catch return true;

    return switch (res.term) {
        .exited => |c| c == 0,
        else => true,
    };
}

fn currentUid() u32 {
    return switch (builtin.os.tag) {
        .windows => 0,
        .linux => @intCast(std.os.linux.getuid()),
        else => @intCast(std.c.getuid()),
    };
}

/// The command that makes the manager notice a new or deleted unit, or null
/// when there is nothing to run.
///
/// launchd has no reload verb: bootstrap IS the load step, and it happens in
/// startArgv. Re-reading a plist means bootout + bootstrap, which would stop a
/// running worker — so an install without --start genuinely has nothing to do
/// here, and must not claim otherwise.
fn reloadArgv(allocator: std.mem.Allocator, s: Spec, sudo: bool) !?[]const []const u8 {
    return switch (s.platform) {
        .systemd => try systemctl(allocator, s, sudo, &.{"daemon-reload"}),
        .launchd => null,
    };
}

fn startArgv(allocator: std.mem.Allocator, s: Spec, sudo: bool) ![]const []const u8 {
    switch (s.platform) {
        .systemd => return systemctl(allocator, s, sudo, &.{ "enable", "--now", s.name }),
        .launchd => {
            var v: std.ArrayList([]const u8) = .empty;
            if (s.scope == .system) try v.append(allocator, "sudo");
            try v.appendSlice(allocator, &.{ "launchctl", "bootstrap", try launchDomain(allocator, s), s.dest });
            return v.items;
        },
    }
}

fn stopArgv(allocator: std.mem.Allocator, s: Spec, sudo: bool) ![]const []const u8 {
    switch (s.platform) {
        .systemd => return systemctl(allocator, s, sudo, &.{ "disable", "--now", s.name }),
        .launchd => {
            var v: std.ArrayList([]const u8) = .empty;
            if (s.scope == .system) try v.append(allocator, "sudo");
            try v.appendSlice(allocator, &.{
                "launchctl",                                                                                "bootout",
                try std.fmt.allocPrint(allocator, "{s}/{s}", .{ try launchDomain(allocator, s), s.label }),
            });
            return v.items;
        },
    }
}

// --------------------------------------------------------------------------
// resolution helpers
// --------------------------------------------------------------------------

fn defaultPlatform() ?Platform {
    return switch (builtin.os.tag) {
        .linux => .systemd,
        .macos => .launchd,
        else => null,
    };
}

/// systemd defaults to SYSTEM (a queue worker on a server should survive a
/// reboot with nobody logged in); launchd defaults to a USER agent, because a
/// LaunchDaemon runs as root and a Mac running this is nearly always a
/// developer machine, where root is the wrong answer.
fn defaultScope(platform: Platform) Scope {
    return switch (platform) {
        .systemd => .system,
        .launchd => .user,
    };
}

fn destDir(allocator: std.mem.Allocator, env: *EnvMap, platform: Platform, scope: Scope) ![]const u8 {
    const home = install_scope.homeDir(allocator, env) orelse "~";
    return switch (platform) {
        .systemd => switch (scope) {
            .system => "/etc/systemd/system",
            .user => try std.fmt.allocPrint(allocator, "{s}/.config/systemd/user", .{home}),
        },
        .launchd => switch (scope) {
            .system => "/Library/LaunchDaemons",
            .user => try std.fmt.allocPrint(allocator, "{s}/Library/LaunchAgents", .{home}),
        },
    };
}

/// The launcher the unit will execute.
///
/// PATH is consulted BEFORE this process's own path on purpose: a unit written
/// by `tools/zig-out/bin/hkm` during development must not pin the deployed
/// service to a build artefact in somebody's checkout.
fn resolveLauncher(allocator: std.mem.Allocator, io: Io, env: *EnvMap, opts: Options) ![]const u8 {
    if (opts.hkm_bin) |b| return b;
    if (util.findOnPath(allocator, io, env, "hkm")) |p| return p;
    if (std.process.executableDirPathAlloc(io, allocator)) |dir| {
        const own = try util.join(allocator, util.trimSlash(dir), "hkm");
        if (util.fileExists(io, own)) return own;
    } else |_| {}
    return "hkm";
}

/// The php binary the unit pins as HKM_PHP_BIN.
///
/// A service does NOT inherit your shell's PATH. systemd starts a unit with
/// `/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin` and launchd
/// with less than that, so Homebrew's /opt/homebrew/bin — where php lives on
/// every Apple Silicon Mac — is on neither. The launcher then cannot spawn php
/// and the entire diagnostic the operator gets is:
///
///     error: FileNotFound
///
/// with nothing anywhere naming php. Resolving it here, at generation time,
/// against the PATH of the person who can still see the problem is the only
/// point where that is cheap to get right.
fn resolvePhp(allocator: std.mem.Allocator, io: Io, env: *EnvMap, opts: Options) ?[]const u8 {
    if (opts.php_bin) |p| return p;
    if (env.get("HKM_PHP_BIN")) |p| {
        if (p.len > 0 and std.mem.indexOfScalar(u8, p, '/') != null) return p;
    }
    return util.findOnPath(allocator, io, env, "php");
}

/// PATH for the unit: php's own directory first, then the conventional set.
/// Composer scripts, `git`, and image tooling a job shells out to all live
/// somewhere on it, and the service's inherited PATH is not to be relied on.
fn servicePath(allocator: std.mem.Allocator, php: ?[]const u8) ![]const u8 {
    const base = "/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin";
    const dir = std.fs.path.dirname(php orelse return base) orelse return base;
    if (std.mem.indexOf(u8, ":" ++ base ++ ":", dir) != null) return base;
    return std.fmt.allocPrint(allocator, "{s}:{s}", .{ dir, base });
}

fn invokingUser(env: *EnvMap) ?[]const u8 {
    for ([_][]const u8{ "SUDO_USER", "USER", "LOGNAME" }) |k| {
        if (env.get(k)) |v| {
            if (v.len > 0 and !std.mem.eql(u8, v, "root")) return v;
        }
    }
    return null;
}

fn projectName(allocator: std.mem.Allocator, io: Io, root: []const u8) ![]const u8 {
    const fallback = std.fs.path.basename(util.trimSlash(root));
    const path = try util.join(allocator, root, "proj.json");
    const content = Dir.cwd().readFileAlloc(io, path, allocator, .limited(4 * 1024 * 1024)) catch return fallback;
    const parsed = std.json.parseFromSliceLeaky(std.json.Value, allocator, content, .{}) catch return fallback;
    if (parsed != .object) return fallback;
    const v = parsed.object.get("name") orelse return fallback;
    return if (v == .string and v.string.len > 0) v.string else fallback;
}

/// The project's own WORKER_QUEUE, so `hkm service` defaults to the queue the
/// project is already configured to drain rather than to 'default'.
fn envQueue(allocator: std.mem.Allocator, io: Io, root: []const u8) !?[]const u8 {
    const file = try envfile.read(allocator, io, root);
    if (file.content.len == 0) return null;
    const parsed = try envfile.parse(allocator, file.content);

    // Last active wins — the loader resolves a repeated key the same way.
    var found: ?[]const u8 = null;
    for (parsed.records) |r| {
        if (!r.active) continue;
        if (!std.mem.eql(u8, r.key, "WORKER_QUEUE")) continue;
        found = cleanValue(r.value);
    }
    if (found) |f| {
        if (f.len > 0 and isSafeValue(f)) return f;
    }
    return null;
}

/// Strip an inline `# comment` and surrounding quotes from a dotenv value.
fn cleanValue(raw: []const u8) []const u8 {
    var v = std.mem.trim(u8, raw, " \t\r");
    if (v.len >= 2 and ((v[0] == '"' and v[v.len - 1] == '"') or (v[0] == '\'' and v[v.len - 1] == '\''))) {
        return v[1 .. v.len - 1];
    }
    if (std.mem.indexOfScalar(u8, v, '#')) |i| v = std.mem.trim(u8, v[0..i], " \t");
    return v;
}

/// Values that reach a unit file's command line must not be able to add
/// arguments, break out of a quoted token, or start a new directive.
fn isSafeValue(v: []const u8) bool {
    if (v.len == 0 or v.len > 128) return false;
    for (v) |c| {
        const ok = (c >= 'a' and c <= 'z') or (c >= 'A' and c <= 'Z') or
            (c >= '0' and c <= '9') or c == '-' or c == '_' or c == '.' or c == ':';
        if (!ok) return false;
    }
    return true;
}

/// Lowercase, dash-separated, filesystem- and unit-name safe.
fn slug(allocator: std.mem.Allocator, in: []const u8) ![]const u8 {
    var o: std.ArrayList(u8) = .empty;
    for (in) |c| {
        const l = std.ascii.toLower(c);
        if ((l >= 'a' and l <= 'z') or (l >= '0' and l <= '9')) {
            try o.append(allocator, l);
        } else if (o.items.len > 0 and o.items[o.items.len - 1] != '-') {
            try o.append(allocator, '-');
        }
    }
    while (o.items.len > 0 and o.items[o.items.len - 1] == '-') _ = o.pop();
    return o.items;
}

fn defaultName(allocator: std.mem.Allocator, project: []const u8, queue: []const u8) ![]const u8 {
    const p = try slug(allocator, project);
    const base = if (p.len == 0) "project" else p;
    if (std.mem.eql(u8, queue, "default")) {
        return std.fmt.allocPrint(allocator, "hkm-worker-{s}", .{base});
    }
    return std.fmt.allocPrint(allocator, "hkm-worker-{s}-{s}", .{ base, try slug(allocator, queue) });
}

fn writeTo(io: Io, path: []const u8, data: []const u8) !void {
    if (std.fs.path.dirname(path)) |dir| {
        Dir.cwd().createDirPath(io, dir) catch |e| switch (e) {
            error.PathAlreadyExists => {},
            else => return e,
        };
    }
    try util.writeFileAtomic(io, path, data);
}

// --------------------------------------------------------------------------
// output
// --------------------------------------------------------------------------

fn header(allocator: std.mem.Allocator, s: Spec) void {
    prompt.intro("hkm service");
    prompt.ok(std.fmt.allocPrint(allocator, "project    {s}  ({s})", .{ s.project, s.root }) catch "project");
    prompt.note(std.fmt.allocPrint(allocator, "queue      {s}", .{s.queue}) catch "queue");
    prompt.note(std.fmt.allocPrint(allocator, "manager    {s} ({s} scope)", .{ @tagName(s.platform), @tagName(s.scope) }) catch "manager");
    prompt.note(std.fmt.allocPrint(allocator, "unit       {s}", .{s.dest}) catch "unit");
    prompt.muted(std.fmt.allocPrint(allocator, "exec       {s}", .{s.exec}) catch "exec");
    if (s.php) |php| {
        prompt.muted(std.fmt.allocPrint(allocator, "php        {s}", .{php}) catch "php");
    } else {
        prompt.warn("php was not found on PATH — the unit will have to find it itself.");
        prompt.hint("--php-bin=/usr/bin/php", "pin it, or the service dies with `error: FileNotFound`");
    }
}

/// One line of a dry run: what would have happened, and nothing did.
///
/// prompt.note, not prompt.item — item pads its key to a fixed column, which
/// puts a wide gap between "would" and every verb and makes the list harder to
/// scan than the flat lines it is meant to summarise.
fn would(allocator: std.mem.Allocator, comptime fmt: []const u8, args: anytype) void {
    const body = std.fmt.allocPrint(allocator, "would " ++ fmt, args) catch return;
    prompt.note(body);
}

fn dryOutro() u8 {
    prompt.outro("dry run — nothing was written, nothing was run");
    return 0;
}

/// Render an argv as a copy-pasteable command line.
///
/// Quotes a token containing whitespace, so a project under `~/My Projects`
/// produces a line that can be pasted rather than one that silently splits into
/// two arguments when it is.
fn shellLine(allocator: std.mem.Allocator, argv: []const []const u8) []const u8 {
    var o: std.ArrayList(u8) = .empty;
    for (argv, 0..) |tok, i| {
        if (i > 0) o.append(allocator, ' ') catch return "?";
        const quote = std.mem.indexOfAny(u8, tok, " \t") != null;
        if (quote) o.append(allocator, '\'') catch return "?";
        o.appendSlice(allocator, tok) catch return "?";
        if (quote) o.append(allocator, '\'') catch return "?";
    }
    return o.items;
}

fn printManualSteps(allocator: std.mem.Allocator, s: Spec, from: []const u8) void {
    prompt.section("Install it by hand");
    switch (s.platform) {
        .systemd => {
            const sudo = if (s.scope == .system) "sudo " else "";
            const flag = if (s.scope == .user) " --user" else "";
            prompt.note(std.fmt.allocPrint(allocator, "{s}cp {s} {s}", .{ sudo, from, s.dest }) catch "cp");
            prompt.note(std.fmt.allocPrint(allocator, "{s}systemctl{s} daemon-reload", .{ sudo, flag }) catch "daemon-reload");
            prompt.note(std.fmt.allocPrint(allocator, "{s}systemctl{s} enable --now {s}", .{ sudo, flag, s.name }) catch "enable");
        },
        .launchd => {
            const sudo = if (s.scope == .system) "sudo " else "";
            const domain = launchDomain(allocator, s) catch "system";
            prompt.note(std.fmt.allocPrint(allocator, "{s}cp {s} {s}", .{ sudo, from, s.dest }) catch "cp");
            prompt.note(std.fmt.allocPrint(allocator, "{s}launchctl bootstrap {s} {s}", .{ sudo, domain, s.dest }) catch "bootstrap");
        },
    }
}

fn printStartSteps(allocator: std.mem.Allocator, s: Spec) void {
    prompt.section("Start it");
    switch (s.platform) {
        .systemd => {
            const sudo = if (s.scope == .system) "sudo " else "";
            const flag = if (s.scope == .user) " --user" else "";
            prompt.note(std.fmt.allocPrint(allocator, "{s}systemctl{s} enable --now {s}", .{ sudo, flag, s.name }) catch "enable");
        },
        .launchd => {
            const sudo = if (s.scope == .system) "sudo " else "";
            const domain = launchDomain(allocator, s) catch "system";
            prompt.note(std.fmt.allocPrint(allocator, "{s}launchctl bootstrap {s} {s}", .{ sudo, domain, s.dest }) catch "bootstrap");
        },
    }
    prompt.muted("or re-run: hkm service install --start");
}

fn printLogSteps(allocator: std.mem.Allocator, s: Spec) void {
    prompt.section("Watch it");
    switch (s.platform) {
        .systemd => {
            const flag = if (s.scope == .user) " --user" else "";
            prompt.note(std.fmt.allocPrint(allocator, "journalctl{s} -u {s} -f", .{ flag, s.name }) catch "journalctl");
            prompt.note(std.fmt.allocPrint(allocator, "systemctl{s} status {s}", .{ flag, s.name }) catch "status");
        },
        .launchd => {
            prompt.note(std.fmt.allocPrint(allocator, "tail -f {s}", .{s.log_out}) catch "tail");
            const domain = launchDomain(allocator, s) catch "system";
            prompt.note(std.fmt.allocPrint(allocator, "launchctl print {s}/{s}", .{ domain, s.label }) catch "print");
        },
    }
}

// --------------------------------------------------------------------------
// arguments
// --------------------------------------------------------------------------

fn parse(allocator: std.mem.Allocator, args: []const []const u8) !?Options {
    _ = allocator;
    var o = Options{};
    var i: usize = 2;
    while (i < args.len) : (i += 1) {
        const a = args[i];
        if (std.mem.eql(u8, a, "-h") or std.mem.eql(u8, a, "--help")) {
            o.help = true;
        } else if (std.mem.eql(u8, a, "--system")) {
            o.scope = .system;
        } else if (std.mem.eql(u8, a, "--user")) {
            o.scope = .user;
        } else if (std.mem.eql(u8, a, "--start")) {
            o.start = true;
        } else if (std.mem.eql(u8, a, "--force")) {
            o.force = true;
        } else if (std.mem.eql(u8, a, "-y") or std.mem.eql(u8, a, "--yes")) {
            o.yes = true;
        } else if (std.mem.eql(u8, a, "-n") or std.mem.eql(u8, a, "--dry-run")) {
            o.dry_run = true;
        } else if (value(a, "--queue")) |v| {
            o.queue = v;
        } else if (std.mem.eql(u8, a, "-q")) {
            i += 1;
            o.queue = if (i < args.len) args[i] else return null;
        } else if (value(a, "--name")) |v| {
            o.name = v;
        } else if (value(a, "--run-as")) |v| {
            o.run_as = v;
        } else if (value(a, "--memory")) |v| {
            o.memory = v;
        } else if (value(a, "--max")) |v| {
            o.max = v;
        } else if (value(a, "--hkm-bin")) |v| {
            o.hkm_bin = v;
        } else if (value(a, "--php-bin")) |v| {
            o.php_bin = v;
        } else if (value(a, "--exec")) |v| {
            o.exec_mode = if (std.mem.eql(u8, v, "hkm"))
                .hkm
            else if (std.mem.eql(u8, v, "php"))
                .php
            else
                return null;
        } else if (value(a, "--out")) |v| {
            o.out = v;
        } else if (value(a, "--platform")) |v| {
            o.platform = if (std.mem.eql(u8, v, "systemd"))
                .systemd
            else if (std.mem.eql(u8, v, "launchd"))
                .launchd
            else
                return null;
        } else if (std.mem.startsWith(u8, a, "-")) {
            return null;
        } else if (i == 2 and actionOf(a) != null) {
            o.action = actionOf(a).?;
        } else if (o.target.len == 0) {
            o.target = a;
        } else {
            return null;
        }
    }
    return o;
}

/// `--flag=value`, or null when `a` is a different flag.
fn value(a: []const u8, flag: []const u8) ?[]const u8 {
    if (!std.mem.startsWith(u8, a, flag)) return null;
    if (a.len == flag.len or a[flag.len] != '=') return null;
    const v = a[flag.len + 1 ..];
    return if (v.len == 0) null else v;
}

fn actionOf(a: []const u8) ?Action {
    if (std.mem.eql(u8, a, "show") or std.mem.eql(u8, a, "preview")) return .preview;
    if (std.mem.eql(u8, a, "write") or std.mem.eql(u8, a, "generate")) return .write;
    if (std.mem.eql(u8, a, "install") or std.mem.eql(u8, a, "enable")) return .install;
    if (std.mem.eql(u8, a, "remove") or std.mem.eql(u8, a, "uninstall")) return .remove;
    return null;
}

fn printHelp() void {
    prompt.intro("hkm service — run a project's queue worker as a service");
    prompt.section("Usage");
    prompt.item("hkm service [path|name]", "show the unit that would be generated (writes nothing)");
    prompt.item("hkm service write", "write it to <project>/var/service/");
    prompt.item("hkm service install", "place it in the system and reload the manager");
    prompt.item("hkm service remove", "stop, disable and delete the installed unit");
    prompt.blank();

    prompt.section("Options");
    prompt.item("-q, --queue=NAME", "queue to drain (default: WORKER_QUEUE from .env, else 'default')");
    prompt.item("    --name=UNIT", "unit name (default: hkm-worker-<project>[-<queue>])");
    prompt.item("    --run-as=USER[:GRP]", "systemd User=/Group= (system scope; default: the invoking user)");
    prompt.item("    --max=N", "pass --max-iterations=N to the worker");
    prompt.item("    --memory=MB", "pass --memory=MB to the worker (restart threshold)");
    prompt.item("    --system | --user", "install scope (default: system on Linux, user on macOS)");
    prompt.item("    --platform=<p>", "systemd | launchd — override host detection");
    prompt.item("    --hkm-bin=PATH", "launcher the unit executes (default: hkm on PATH)");
    prompt.item("    --php-bin=PATH", "php the unit pins (default: php on PATH — a service has none)");
    prompt.item("    --exec=hkm|php", "ExecStart runs the launcher (default) or php + the entry directly");
    prompt.item("    --out=DIR", "write: put the file here instead of var/service/");
    prompt.item("    --start", "install: enable and start it immediately");
    prompt.item("    --force", "overwrite an existing unit file");
    prompt.item("-y, --yes", "do not ask before writing to a system location");
    prompt.item("-n, --dry-run", "report every write and command, perform none of them");
    prompt.blank();

    prompt.section("Examples");
    prompt.note("hkm service --queue=mails");
    prompt.note("hkm service install --queue=mails --start");
    prompt.note("hkm service install shop --queue=mails --run-as=deploy:www-data");
    prompt.note("hkm service write --platform=systemd --out=./deploy   # generate a Linux unit on a Mac");
    prompt.blank();

    prompt.section("Several workers");
    prompt.muted("one unit drains one queue — repeat the command per queue, or pass");
    prompt.muted("--name to run two units on the same queue.");
    prompt.outro("The unit runs `hkm worker`, so a kernel upgrade cannot break its paths");
}

// ── tests ──────────────────────────────────────────────────────

test "slug lowercases, collapses separators and trims" {
    const a = std.testing.allocator;
    var arena = std.heap.ArenaAllocator.init(a);
    defer arena.deinit();
    const al = arena.allocator();

    try std.testing.expectEqualStrings("my-shop", try slug(al, "My Shop"));
    try std.testing.expectEqualStrings("hkm-std", try slug(al, "hkm__std"));
    try std.testing.expectEqualStrings("shop", try slug(al, "--shop--"));
    try std.testing.expectEqualStrings("", try slug(al, "///"));
}

test "the default unit name only carries the queue when it is not the default one" {
    const a = std.testing.allocator;
    var arena = std.heap.ArenaAllocator.init(a);
    defer arena.deinit();
    const al = arena.allocator();

    try std.testing.expectEqualStrings("hkm-worker-shop", try defaultName(al, "shop", "default"));
    try std.testing.expectEqualStrings("hkm-worker-shop-mails", try defaultName(al, "Shop", "mails"));
}

test "isSafeValue rejects anything that could add an argument or a directive" {
    try std.testing.expect(isSafeValue("mails"));
    try std.testing.expect(isSafeValue("high-priority.v2"));
    try std.testing.expect(!isSafeValue(""));
    try std.testing.expect(!isSafeValue("mails default")); // extra argument
    try std.testing.expect(!isSafeValue("mails\nExecStart=/bin/sh")); // extra directive
    try std.testing.expect(!isSafeValue("$(id)"));
    try std.testing.expect(!isSafeValue("\"mails\""));
}

test "cleanValue strips quotes and inline comments" {
    try std.testing.expectEqualStrings("mails", cleanValue("  mails  "));
    try std.testing.expectEqualStrings("mails", cleanValue("\"mails\""));
    try std.testing.expectEqualStrings("mails", cleanValue("'mails'"));
    try std.testing.expectEqualStrings("mails", cleanValue("mails   # the mail queue"));
}

test "xmlEscape escapes every character that would break the plist" {
    const a = std.testing.allocator;
    var arena = std.heap.ArenaAllocator.init(a);
    defer arena.deinit();

    try std.testing.expectEqualStrings(
        "/srv/a&amp;b/&lt;x&gt;",
        try xmlEscape(arena.allocator(), "/srv/a&b/<x>"),
    );
}

test "a path with a space is quoted in ExecStart" {
    const a = std.testing.allocator;
    var arena = std.heap.ArenaAllocator.init(a);
    defer arena.deinit();
    const al = arena.allocator();

    try std.testing.expectEqualStrings("/usr/bin/hkm", try systemdToken(al, "/usr/bin/hkm"));
    try std.testing.expectEqualStrings("\"/Users/me/My Projects/shop\"", try systemdToken(al, "/Users/me/My Projects/shop"));
}

test "parse reads the verb, the target and the flags" {
    const a = std.testing.allocator;
    const args = [_][]const u8{ "hkm", "service", "install", "shop", "--queue=mails", "--start", "--run-as=deploy:www-data" };
    const o = (try parse(a, &args)).?;

    try std.testing.expectEqual(Action.install, o.action);
    try std.testing.expectEqualStrings("shop", o.target);
    try std.testing.expectEqualStrings("mails", o.queue.?);
    try std.testing.expectEqualStrings("deploy:www-data", o.run_as.?);
    try std.testing.expect(o.start);
    try std.testing.expect(!o.force);
}

test "parse: a bare invocation previews the current directory" {
    const a = std.testing.allocator;
    const args = [_][]const u8{ "hkm", "service" };
    const o = (try parse(a, &args)).?;

    try std.testing.expectEqual(Action.preview, o.action);
    try std.testing.expectEqualStrings("", o.target);
    try std.testing.expect(o.queue == null);
}

test "parse rejects an unknown platform rather than guessing one" {
    const a = std.testing.allocator;
    const args = [_][]const u8{ "hkm", "service", "--platform=upstart" };
    try std.testing.expect((try parse(a, &args)) == null);
}

test "parse reads --exec and rejects anything but the two modes" {
    const a = std.testing.allocator;

    const hkm = [_][]const u8{ "hkm", "service", "--exec=hkm" };
    try std.testing.expectEqual(ExecMode.hkm, (try parse(a, &hkm)).?.exec_mode);

    const php = [_][]const u8{ "hkm", "service", "--exec=php" };
    try std.testing.expectEqual(ExecMode.php, (try parse(a, &php)).?.exec_mode);

    // The default is the launcher, so an upgrade cannot invalidate the paths.
    const bare = [_][]const u8{ "hkm", "service" };
    try std.testing.expectEqual(ExecMode.hkm, (try parse(a, &bare)).?.exec_mode);

    const bad = [_][]const u8{ "hkm", "service", "--exec=python" };
    try std.testing.expect((try parse(a, &bad)) == null);
}

test "shellLine quotes only the tokens that need it" {
    const a = std.testing.allocator;
    var arena = std.heap.ArenaAllocator.init(a);
    defer arena.deinit();
    const al = arena.allocator();

    try std.testing.expectEqualStrings(
        "systemctl --user enable --now hkm-worker-shop",
        shellLine(al, &.{ "systemctl", "--user", "enable", "--now", "hkm-worker-shop" }),
    );
    try std.testing.expectEqualStrings(
        "cp '/Users/me/My Projects/a.plist' /tmp/a.plist",
        shellLine(al, &.{ "cp", "/Users/me/My Projects/a.plist", "/tmp/a.plist" }),
    );
}

test "parse reads --dry-run and its -n alias" {
    const a = std.testing.allocator;

    const long = [_][]const u8{ "hkm", "service", "install", "--dry-run" };
    try std.testing.expect((try parse(a, &long)).?.dry_run);

    const short = [_][]const u8{ "hkm", "service", "remove", "-n" };
    try std.testing.expect((try parse(a, &short)).?.dry_run);

    const off = [_][]const u8{ "hkm", "service", "install" };
    try std.testing.expect(!(try parse(a, &off)).?.dry_run);
}

test "scope defaults differ per manager, and both are deliberate" {
    try std.testing.expectEqual(Scope.system, defaultScope(.systemd));
    try std.testing.expectEqual(Scope.user, defaultScope(.launchd));
}
