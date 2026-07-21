//! `hkm cli [args…]` and `hkm worker [args…]` — run a project's console /
//! worker entry point with the terminal FULLY ATTACHED, so php-io-cli's
//! interactive components (TextInput, Select, Confirm, …) work normally.
//!
//! The project is the current directory by default; pick another with
//! `-p <name|path>` (or `--project=…`). Every other argument is forwarded
//! verbatim to the PHP entry point, so a bare `hkm cli` drops into the project's
//! interactive command picker and `hkm cli make:migration` runs that command.
//!
//!   hkm cli                          # interactive CLI in ./ (terminal stays open)
//!   hkm cli list                     # forward `list` to app/cli/run.php
//!   hkm cli make:migration           # interactive prompt — fully usable
//!   hkm cli -p shop migrate:run      # target a registered project by name
//!   hkm worker --queue=emails        # run app/worker/run.php instead

const std = @import("std");
const run_cmd = @import("run.zig");
const services = @import("../lib/services.zig");
const prompt = @import("../lib/prompt.zig");
const util = @import("../lib/util.zig");

const Io = std.Io;
const EnvMap = std.process.Environ.Map;

/// `is_worker` selects app/worker/run.php instead of app/cli/run.php. Dispatched
/// from main.zig: `hkm cli` → false, `hkm worker` → true.
pub fn run(allocator: std.mem.Allocator, io: Io, env: *EnvMap, args: []const []const u8, is_worker: bool) !u8 {
    var target: []const u8 = ""; // "" → current directory
    var forwarded: std.ArrayList([]const u8) = .empty;
    var pass_through = false;

    // args[0]=exe, args[1]=cli|worker. Only -p/--project select the project; all
    // other tokens are forwarded to PHP. `--` forces the rest to be forwarded.
    var i: usize = 2;
    while (i < args.len) : (i += 1) {
        const a = args[i];
        if (pass_through) {
            try forwarded.append(allocator, a);
            continue;
        }
        if (std.mem.eql(u8, a, "--")) {
            pass_through = true;
        } else if (std.mem.eql(u8, a, "-h") or std.mem.eql(u8, a, "--help")) {
            printHelp(is_worker);
            return 2;
        } else if (std.mem.eql(u8, a, "-p") or std.mem.eql(u8, a, "--project")) {
            if (i + 1 >= args.len) {
                prompt.err("-p/--project needs a value.");
                return 2;
            }
            i += 1;
            target = args[i];
        } else if (std.mem.startsWith(u8, a, "--project=")) {
            target = a["--project=".len..];
        } else {
            // First non-flag token onward is forwarded to the PHP entry point.
            try forwarded.append(allocator, a);
        }
    }

    const root = (try services.resolveRoot(allocator, io, env, target)) orelse {
        prompt.err(try std.fmt.allocPrint(
            allocator,
            "'{s}' is neither a project folder (with proj.json) nor a registered name.",
            .{if (target.len == 0) "." else target},
        ));
        return 1;
    };

    // Export the kernel autoload so the project's PHP can find the framework.
    if (try services.resolveAutoload(allocator, io, env)) |autoload| {
        try env.put("PSP_GLOBAL_AUTOLOAD", autoload);
    }

    // Export the resolved project-registry dir so the kernel + plugins (Edge)
    // read the SAME registry the launcher uses, without re-deriving it.
    if (try services.resolveProjectsDir(allocator, io, env)) |projects_dir| {
        try env.put("PSP_PROJECTS_DIR", projects_dir);
    }

    const rel = if (is_worker) "app/worker/run.php" else "app/cli/run.php";
    const entry = try std.fmt.allocPrint(allocator, "{s}/{s}", .{ root, rel });
    if (!util.fileExists(io, entry)) {
        prompt.err(try std.fmt.allocPrint(allocator, "No entry point at {s}", .{entry}));
        return 1;
    }

    const php = env.get("HKM_PHP_BIN") orelse "php";

    var argv: std.ArrayList([]const u8) = .empty;
    try argv.append(allocator, php);
    try argv.append(allocator, entry);
    for (forwarded.items) |a| try argv.append(allocator, a);

    // Inherit all stdio: the PHP process owns the real TTY for the whole session
    // (interactive prompts, raw-mode menus) and we just block until it exits.
    return run_cmd.spawnWait(io, env, argv.items);
}

fn printHelp(is_worker: bool) void {
    if (is_worker) {
        prompt.intro("hkm worker — run a project's queue worker");
        prompt.section("Usage");
        prompt.item("hkm worker [args…]", "run app/worker/run.php in ./");
        prompt.item("  -p <name|path>", "target a registered project or path");
        prompt.blank();
        prompt.section("Examples");
        prompt.note("hkm worker");
        prompt.note("hkm worker -p shop --queue=emails");
        prompt.outro("Terminal stays attached; Ctrl+C stops the worker");
        return;
    }
    prompt.intro("hkm cli — run a project's console, interactively");
    prompt.section("Usage");
    prompt.item("hkm cli [command] [args…]", "run app/cli/run.php in ./ (terminal attached)");
    prompt.item("  -p <name|path>", "target a registered project or path");
    prompt.item("  --", "forward the rest verbatim to PHP");
    prompt.blank();
    prompt.section("Examples");
    prompt.note("hkm cli                       # interactive command picker");
    prompt.note("hkm cli make:migration        # interactive prompts work");
    prompt.note("hkm cli -p shop migrate:run");
    prompt.outro("The kernel autoload is exported as PSP_GLOBAL_AUTOLOAD for you");
}
