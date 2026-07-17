//! `hkm run [path|name]` — run a PSP project locally.
//!
//! Mirrors the manual invocation
//!
//!     PSP_GLOBAL_AUTOLOAD=/path/to/kernel/vendor/autoload.php php index.php
//!
//! but resolves the project root + the kernel autoload for you and starts PHP's
//! built-in web server in front of `app/public/index.php`. The project may be
//! given as a PATH (a folder holding proj.json) or a registered project NAME;
//! with no argument the current directory is used.
//!
//!   hkm run                         # serve ./ on 127.0.0.1:8000
//!   hkm run ./my-shop               # serve a path
//!   hkm run shop --port=9000        # serve a registered project by name
//!   hkm run --host=0.0.0.0          # bind all interfaces
//!   hkm run --cli migrate           # run app/cli/run.php migrate
//!   hkm run --worker                # run app/worker/run.php

const std = @import("std");
const registry = @import("../lib/registry.zig");
const prompt = @import("../lib/prompt.zig");
const util = @import("../lib/util.zig");
const services = @import("../lib/services.zig");

const Dir = std.Io.Dir;
const Io = std.Io;
const EnvMap = std.process.Environ.Map;

const Surface = enum { serve, swoole, cli, worker };

const Options = struct {
    /// A project PATH (dir holding proj.json) OR a registered NAME. "" = cwd.
    target: []const u8 = "",
    /// null = use the surface default (serve: 127.0.0.1:8000; swoole: its own env).
    host: ?[]const u8 = null,
    port: ?[]const u8 = null,
    surface: Surface = .serve,
    /// True once a surface flag (--swoole/--cli/--worker) was given explicitly.
    surface_set: bool = false,
    /// Interactively pick the project from the registry before running.
    pick: bool = false,
    /// Pass-through arguments for the cli / worker surfaces.
    extra: []const []const u8 = &.{},
};

pub fn run(allocator: std.mem.Allocator, io: Io, env: *EnvMap, args: []const []const u8) !u8 {
    // Bare `hkm run` (no arguments) prints help rather than serving silently.
    if (args.len <= 2) {
        printHelp();
        return 2;
    }

    var opts = (try parse(allocator, args)) orelse {
        printHelp();
        return 2;
    };

    // `--pick`: choose the project interactively from the registry, then — when
    // no surface flag was given — choose what to run (serve/swoole/cli/worker).
    if (opts.pick) {
        if (opts.target.len == 0) {
            opts.target = (try pickProject(allocator, io, env)) orelse return 1;
        }
        if (!opts.surface_set) {
            opts.surface = pickSurface() orelse {
                prompt.muted("cancelled");
                return 1;
            };
        }
    }

    // Resolve the project root: an explicit/relative path that holds proj.json,
    // otherwise a registered project name looked up in the kernel registry.
    const root = (try services.resolveRoot(allocator, io, env, opts.target)) orelse {
        prompt.err(try std.fmt.allocPrint(
            allocator,
            "'{s}' is neither a project folder (with proj.json) nor a registered name.",
            .{if (opts.target.len == 0) "." else opts.target},
        ));
        return 1;
    };

    // Resolve the kernel autoload and export it for the child PHP process. Dupe it
    // first: it may be a slice into the env map's storage, which env.put() can
    // reallocate — leaving the original slice dangling.
    const autoload: ?[]const u8 = if (try services.resolveAutoload(allocator, io, env)) |a|
        try allocator.dupe(u8, a)
    else
        null;
    if (autoload) |a| {
        try env.put("PSP_GLOBAL_AUTOLOAD", a);
    } else if (env.get("PSP_GLOBAL_AUTOLOAD") == null) {
        prompt.warn("No kernel autoload found — relying on the project's own resolver.");
        prompt.muted("Set HKM_KERNEL_HOME or PSP_GLOBAL_AUTOLOAD if PHP cannot find the kernel.");
    }

    // Export HKM_KERNEL_HOME for the child too, so a runtime getenv('HKM_KERNEL_HOME')
    // resolves — e.g. the plugin Support/helpers.php requires that `hkm plugins`
    // wires for kernel-home plugins reference it. Only set when we can resolve it
    // and the caller has not already provided one.
    if (env.get("HKM_KERNEL_HOME") == null) {
        if (try services.resolveKernelHome(allocator, io, env, autoload)) |home| {
            try env.put("HKM_KERNEL_HOME", home);
        }
    }

    // Export the resolved project-registry dir so the kernel + plugins (Edge)
    // read the SAME registry the launcher uses, without re-deriving it.
    if (try services.resolveProjectsDir(allocator, io, env)) |projects_dir| {
        try env.put("PSP_PROJECTS_DIR", projects_dir);
    }

    const php = env.get("HKM_PHP_BIN") orelse "php";

    var argv: std.ArrayList([]const u8) = .empty;
    defer argv.deinit(allocator);

    switch (opts.surface) {
        .serve => {
            const host = opts.host orelse "127.0.0.1";
            const port = opts.port orelse "8000";
            const docroot = try std.fmt.allocPrint(allocator, "{s}/app/public", .{root});
            const entry = try std.fmt.allocPrint(allocator, "{s}/index.php", .{docroot});
            if (!util.fileExists(io, entry)) {
                prompt.err(try std.fmt.allocPrint(allocator, "No front controller at {s}", .{entry}));
                return 1;
            }
            const listen = try std.fmt.allocPrint(allocator, "{s}:{s}", .{ host, port });

            try argv.append(allocator, php);
            try argv.append(allocator, "-S");
            try argv.append(allocator, listen);
            try argv.append(allocator, "-t");
            try argv.append(allocator, docroot);
            try argv.append(allocator, entry); // router script — front controller

            prompt.intro("hkm run");
            prompt.ok(try std.fmt.allocPrint(allocator, "project  {s}", .{root}));
            prompt.note(try std.fmt.allocPrint(allocator, "serving  http://{s}", .{listen}));
            prompt.outro("PHP development server starting");
        },
        .swoole => {
            const entry = try std.fmt.allocPrint(allocator, "{s}/app/swoole/index.php", .{root});
            if (!util.fileExists(io, entry)) {
                prompt.err(try std.fmt.allocPrint(allocator, "No OpenSwoole entry at {s}", .{entry}));
                return 1;
            }
            // The swoole entry reads SWOOLE_HOST/SWOOLE_PORT from env. Only
            // override them when the user explicitly passed --host/--port,
            // otherwise leave the project's own defaults (env / 9502) intact.
            if (opts.host) |h| try env.put("SWOOLE_HOST", h);
            if (opts.port) |p| try env.put("SWOOLE_PORT", p);

            try argv.append(allocator, php);
            try argv.append(allocator, entry);
            for (opts.extra) |a| try argv.append(allocator, a);

            prompt.intro("hkm run");
            prompt.ok(try std.fmt.allocPrint(allocator, "project  {s}", .{root}));
            prompt.note(try std.fmt.allocPrint(allocator, "starting OpenSwoole  {s}:{s}", .{
                opts.host orelse env.get("SWOOLE_HOST") orelse "127.0.0.1",
                opts.port orelse env.get("SWOOLE_PORT") orelse "9502",
            }));
            prompt.outro("OpenSwoole HTTP server starting");
        },
        .cli, .worker => {
            const rel = if (opts.surface == .cli) "app/cli/run.php" else "app/worker/run.php";
            const entry = try std.fmt.allocPrint(allocator, "{s}/{s}", .{ root, rel });
            if (!util.fileExists(io, entry)) {
                prompt.err(try std.fmt.allocPrint(allocator, "No entry point at {s}", .{entry}));
                return 1;
            }
            try argv.append(allocator, php);
            try argv.append(allocator, entry);
            for (opts.extra) |a| try argv.append(allocator, a);
        },
    }

    // Long-running servers (serve/swoole) get an interactive supervisor: press
    // `r` to restart the process, `q`/Ctrl+C to quit. The one-shot surfaces
    // (cli/worker) just run to completion.
    if (opts.surface == .serve or opts.surface == .swoole) {
        return runWatched(io, env, argv.items);
    }

    return spawnWait(io, env, argv.items);
}

/// Spawn the child, inherit all stdio, and block until it exits.
pub fn spawnWait(io: Io, env: *EnvMap, argv: []const []const u8) !u8 {
    var child = try std.process.spawn(io, .{
        .argv = argv,
        .environ_map = env,
        .stdin = .inherit,
        .stdout = .inherit,
        .stderr = .inherit,
    });
    const term = try child.wait(io);
    return switch (term) {
        .exited => |code| code,
        else => 1,
    };
}

const Control = union(enum) { restart, quit: u8 };

/// Supervise a long-running server: spawn it, then watch the keyboard. `r`
/// restarts the process; `q`, Ctrl+C, or Ctrl+D stops it and returns. Falls
/// back to a plain blocking run when stdin is not an interactive terminal
/// (pipes / CI), where there is no keyboard to listen to.
fn runWatched(io: Io, env: *EnvMap, argv: []const []const u8) !u8 {
    // The interactive supervisor uses POSIX raw-mode termios + poll(), which do
    // not exist on Windows. There, fall back to a plain blocking run (same
    // behaviour as a non-TTY stdin on POSIX).
    if (@import("builtin").os.tag == .windows) {
        return spawnWait(io, env, argv);
    }

    const tty = std.posix.STDIN_FILENO;

    // tcgetattr fails with ENOTTY when stdin is not a terminal — that's our
    // signal to skip the interactive supervisor entirely.
    const orig = std.posix.tcgetattr(tty) catch {
        return spawnWait(io, env, argv);
    };

    var raw = orig;
    raw.lflag.ICANON = false; // deliver each keypress immediately
    raw.lflag.ECHO = false; // don't echo the control keys
    raw.lflag.ISIG = false; // treat Ctrl+C as a byte we handle, not a signal
    raw.lflag.IEXTEN = false;
    std.posix.tcsetattr(tty, .NOW, raw) catch {};
    defer std.posix.tcsetattr(tty, .NOW, orig) catch {};

    prompt.muted("controls:  r restart   q quit");

    while (true) {
        var child = try std.process.spawn(io, .{
            .argv = argv,
            .environ_map = env,
            // stdin is owned by THIS process so it can read the control keys;
            // the dev servers don't read stdin anyway.
            .stdin = .ignore,
            .stdout = .inherit,
            .stderr = .inherit,
        });

        const action = readControl(tty);

        // Stop the current child (kill() also reaps it) before acting.
        child.kill(io);

        switch (action) {
            .restart => {
                prompt.warn("restarting…");
                continue;
            },
            .quit => |code| return code,
        }
    }
}

/// Block on the keyboard until a recognised control key is pressed.
fn readControl(tty: std.posix.fd_t) Control {
    var fds = [_]std.posix.pollfd{.{ .fd = tty, .events = std.posix.POLL.IN, .revents = 0 }};
    while (true) {
        _ = std.posix.poll(&fds, -1) catch return .{ .quit = 0 };
        if (fds[0].revents & std.posix.POLL.IN == 0) continue;

        var buf: [16]u8 = undefined;
        const n = std.posix.read(tty, &buf) catch return .{ .quit = 0 };
        if (n == 0) return .{ .quit = 0 }; // EOF on stdin

        for (buf[0..n]) |c| switch (c) {
            'r', 'R' => return .restart,
            'q', 'Q', 3, 4 => return .{ .quit = 0 }, // q / Ctrl+C / Ctrl+D
            else => {},
        };
    }
}

/// Interactively choose a project from the kernel registry. Prints a numbered
/// list, reads a selection, and returns the chosen project's absolute path
/// (so the caller resolves it in PATH mode). Returns null on any failure.
fn pickProject(allocator: std.mem.Allocator, io: Io, env: *EnvMap) !?[]const u8 {
    const jsonPath = (try registry.resolvePath(allocator, io, env)) orelse {
        prompt.err("Kernel registry not found. Set PSP_PROJECTS_DIR or HKM_KERNEL_HOME.");
        return null;
    };
    const entries = try registry.list(allocator, io, jsonPath);
    if (entries.len == 0) {
        prompt.err("No projects registered. Scaffold one with: hkm new <path>");
        return null;
    }

    const names = try allocator.alloc([]const u8, entries.len);
    for (entries, 0..) |e, i| names[i] = e.name;

    const idx = prompt.select("Select a project", names) orelse {
        prompt.muted("cancelled");
        return null;
    };
    return entries[idx].path;
}

/// Available run surfaces, in menu order, with the enum each maps to.
const surface_choices = [_]struct { label: []const u8, surface: Surface }{
    .{ .label = "serve   (PHP dev server)", .surface = .serve },
    .{ .label = "swoole  (OpenSwoole server)", .surface = .swoole },
    .{ .label = "cli     (app/cli/run.php)", .surface = .cli },
    .{ .label = "worker  (app/worker/run.php)", .surface = .worker },
};

/// Interactively choose what to run. Returns null if cancelled.
fn pickSurface() ?Surface {
    var labels: [surface_choices.len][]const u8 = undefined;
    for (surface_choices, 0..) |c, i| labels[i] = c.label;
    const idx = prompt.select("What do you want to run?", &labels) orelse return null;
    return surface_choices[idx].surface;
}

// --------------------------------------------------------------------------
// argument parsing
// --------------------------------------------------------------------------

fn parse(allocator: std.mem.Allocator, args: []const []const u8) !?Options {
    var opts: Options = .{};
    var extra: std.ArrayList([]const u8) = .empty;
    var pass_through = false;

    // args[0]=exe, args[1]="run"; options start at index 2.
    var i: usize = 2;
    while (i < args.len) : (i += 1) {
        const a = args[i];

        if (pass_through) {
            try extra.append(allocator, a);
            continue;
        }
        if (std.mem.eql(u8, a, "--")) {
            pass_through = true;
            continue;
        }
        if (std.mem.eql(u8, a, "-h") or std.mem.eql(u8, a, "--help")) {
            return null;
        }
        if (std.mem.eql(u8, a, "--pick") or std.mem.eql(u8, a, "-i") or std.mem.eql(u8, a, "--select")) {
            opts.pick = true;
            continue;
        }
        if (std.mem.eql(u8, a, "--swoole")) {
            opts.surface = .swoole;
            opts.surface_set = true;
            continue;
        }
        if (std.mem.eql(u8, a, "--cli")) {
            opts.surface = .cli;
            opts.surface_set = true;
            continue;
        }
        if (std.mem.eql(u8, a, "--worker")) {
            opts.surface = .worker;
            opts.surface_set = true;
            continue;
        }
        if (valueOf(a, "--host")) |v| {
            opts.host = v;
            continue;
        }
        if (valueOf(a, "--port")) |v| {
            opts.port = v;
            continue;
        }
        if (a.len > 0 and a[0] == '-') {
            // Unknown flag for the serve surface is an error; for cli/worker it
            // is passed through to the PHP script.
            if (opts.surface == .serve) return null;
            try extra.append(allocator, a);
            continue;
        }
        // First bare token is the project target; later bare tokens for the
        // cli/worker surfaces are forwarded.
        if (opts.target.len == 0) {
            opts.target = a;
        } else {
            try extra.append(allocator, a);
        }
    }

    opts.extra = try extra.toOwnedSlice(allocator);
    return opts;
}

/// Match `--flag=value`, returning the value slice (or null).
fn valueOf(arg: []const u8, flag: []const u8) ?[]const u8 {
    if (arg.len <= flag.len + 1) return null;
    if (!std.mem.startsWith(u8, arg, flag)) return null;
    if (arg[flag.len] != '=') return null;
    return arg[flag.len + 1 ..];
}

fn printHelp() void {
    prompt.intro("hkm run — run a PSP project locally");
    prompt.section("Usage");
    prompt.item("hkm run [path|name]", "serve a project (defaults to ./)");
    prompt.item("  --pick, -i", "choose the project from the registry interactively");
    prompt.item("  --host=127.0.0.1", "interface to bind (default: 127.0.0.1)");
    prompt.item("  --port=8000", "port to listen on (default: 8000)");
    prompt.item("  --swoole", "run app/swoole/index.php (OpenSwoole server)");
    prompt.item("  --cli [args…]", "run app/cli/run.php instead of serving");
    prompt.item("  --worker", "run app/worker/run.php instead of serving");
    prompt.blank();
    prompt.section("Examples");
    prompt.note("hkm run .");
    prompt.note("hkm run --pick           # pick from the list, then serve");
    prompt.note("hkm run -i --swoole      # pick, then run with OpenSwoole");
    prompt.note("hkm run ./my-shop --port=9000");
    prompt.note("hkm run shop --host=0.0.0.0");
    prompt.note("hkm run shop --swoole --port=9502");
    prompt.note("hkm run --cli migrate --seed");
    prompt.outro("Exports PSP_GLOBAL_AUTOLOAD + HKM_KERNEL_HOME for the child process");
}
