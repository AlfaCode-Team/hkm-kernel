const std = @import("std");
const new_cmd = @import("commands/new.zig");
const update_cmd = @import("commands/update.zig");
const run_cmd = @import("commands/run.zig");
const list_cmd = @import("commands/list.zig");
const discover_cmd = @import("commands/discover.zig");
const plugins_cmd = @import("commands/plugins.zig");
const module_cmd = @import("commands/module.zig");
const ui_cmd = @import("commands/ui.zig");
const cli_cmd = @import("commands/cli.zig");
const doctor_cmd = @import("commands/doctor.zig");
const upgrade_cmd = @import("commands/upgrade.zig");
const uninstall_cmd = @import("commands/uninstall.zig");
const version_cmd = @import("commands/version.zig");
const kernel = @import("lib/kernel.zig");
const util = @import("lib/util.zig");
const userconfig = @import("lib/userconfig.zig");
const banner = @import("lib/banner.zig");
const prompt = @import("lib/prompt.zig");
const memory = @import("lib/memory.zig");

fn printHelp(allocator: std.mem.Allocator, io: std.Io, env: *std.process.Environ.Map) void {
    banner.print(allocator, io, env);

    prompt.section("Usage");
    prompt.item("hkm new <path> [opts]", "scaffold a new PhpServicePlatform project");
    prompt.item("hkm run [path|name]", "run a project locally (PHP dev server)");
    prompt.item("hkm cli [command]", "run a project's console interactively");
    prompt.item("hkm worker [args]", "run a project's queue worker");
    prompt.item("hkm list", "list registered projects (alias: ls)");
    prompt.item("hkm discover [root]", "find projects on disk and register them (alias: scan)");
    prompt.item("hkm plugins [path|name]", "analyse a project's enabled plugins/modules");
    prompt.item("hkm module [create|delete]", "scaffold a first-party kernel package (modules/)");
    prompt.item("hkm ui [sync|list|link|clean]", "federate enabled plugins' UIs into the frontend");
    prompt.item("hkm update <path|name>", "refresh a project's kernel registry entry");
    prompt.item("hkm upgrade [--check]", "update YOUR install; sudo hkm upgrade updates the system one");
    prompt.item("hkm upgrade --local", "install THIS checkout over an installed kernel");
    prompt.item("hkm uninstall", "remove every hkm install (keeps projects + registry)");
    prompt.item("hkm doctor", "diagnose the local environment");
    prompt.item("hkm version", "kernel version in each install scope (also --version, -v)");
    prompt.item("hkm help", "show this help");
    prompt.item("hkm <cmd> --dev", "use the development kernel (this monorepo) instead of the installed stable copy");
    prompt.item("hkm <cmd> --mem", "print the memory inspector dashboard when the command finishes (debug builds)");
    prompt.blank();

    prompt.section("Environment");
    prompt.muted("all auto-detected — override only for a non-standard layout");
    prompt.item("HKM_PHP_BIN", "override php binary (default: php)");
    prompt.item("HKM_CLI_PATH", "override target php CLI script");
    prompt.item("HKM_GLOBAL_AUTOLOAD", "override global autoload path");
    prompt.item("HKM_USERDATA_DIR", "persistent registry dir (projects.json + platform.json); survives updates");
    prompt.item("PSP_PROJECTS_DIR", "dir holding the kernel projects.json registry");
    prompt.item("HKM_KERNEL_HOME", "kernel root (registry at <root>/projects/projects.json)");
    prompt.item("HKM_DEV_HOME", "development kernel checkout used by --dev (set once via hkm-config)");
    prompt.item("HKM_MEM_INSPECT", "always print the memory dashboard (same as passing --mem)");
    prompt.item("HKM_MEM_STRICT", "exit 70 when a leak is detected — for CI");

    prompt.outro("Run 'hkm <command> --help' for command details");
}

fn envGet(allocator: std.mem.Allocator, map: *std.process.Environ.Map, key: []const u8) !?[]const u8 {
    const v = map.get(key) orelse return null;
    return try allocator.dupe(u8, v);
}

/// Explain why the PHP passthrough could not start.
///
/// Everything not handled natively in Zig is forwarded to the kernel's PHP CLI,
/// so this one spawn is where a typo and a missing runtime both surface. It has
/// to separate them, because the fixes are unrelated: one is "you meant a
/// different word", the other is "this machine has no PHP".
fn reportPassthroughFailure(
    allocator: std.mem.Allocator,
    io: std.Io,
    env: *std.process.Environ.Map,
    e: anyerror,
    php: []const u8,
    cli: []const u8,
    cmd: []const u8,
) u8 {
    const have_php = util.onPath(allocator, io, env, php);
    const have_cli = util.fileExists(io, cli);

    if (!have_php) {
        prompt.err(std.fmt.allocPrint(
            allocator,
            "PHP is required to run `hkm {s}`, and `{s}` was not found on your PATH.",
            .{ cmd, php },
        ) catch "PHP was not found on your PATH.");
        prompt.item("see what is missing", "hkm doctor");
        prompt.item("Debian/Ubuntu", "sudo apt install php8.4-cli");
        prompt.item("macOS", "brew install php");
        prompt.item("or point at it", "HKM_PHP_BIN=/full/path/to/php");
        return 1;
    }

    if (!have_cli) {
        prompt.err(std.fmt.allocPrint(
            allocator,
            "the kernel's PHP CLI is missing: {s}",
            .{cli},
        ) catch "the kernel's PHP CLI is missing.");
        prompt.item("diagnose it", "hkm doctor");
        prompt.item("show installs", "hkm version");
        prompt.item("reinstall", "hkm upgrade");
        return 1;
    }

    // PHP and the CLI both exist, so the command word itself is the suspect —
    // `hkm` forwards anything it does not handle, and a typo lands here.
    prompt.err(std.fmt.allocPrint(
        allocator,
        "could not run `hkm {s}` ({t}).",
        .{ cmd, e },
    ) catch "could not run that command.");
    prompt.item("list the commands", "hkm help");
    prompt.item("check the environment", "hkm doctor");
    return 1;
}

fn findCliPath(allocator: std.mem.Allocator, io: std.Io, env_map: *std.process.Environ.Map) ![]const u8 {
    return kernel.findCliPath(allocator, io, env_map);
}

fn phpBin(allocator: std.mem.Allocator, env_map: *std.process.Environ.Map) ![]const u8 {
    if (try envGet(allocator, env_map, "HKM_PHP_BIN")) |v| {
        return v;
    }
    return try allocator.dupe(u8, "php");
}

/// Entry point.
///
/// Deliberately thin: it owns the memory manager and is the ONLY place that
/// calls std.process.exit(). Everything else returns an exit code up to here.
///
/// This used to matter less than it looks. Every command arm called
/// std.process.exit(code) directly, and std.process.exit does NOT run deferred
/// code — so `threaded.deinit()` and the arena's `deinit()` never executed on
/// any successful command. The process exiting made that harmless in practice,
/// but it also meant a memory report or leak check could never run, because
/// control never returned from the dispatch. Routing the exit code back here is
/// what makes the inspector possible at all.
pub fn main(init: std.process.Init.Minimal) !void {
    var mm = memory.Manager.init();

    const code = dispatch(init, &mm) catch |err| {
        // Still tear down on the error path, so a failing command reports its
        // leaks too.
        _ = mm.deinit(reportRuntime());
        return err;
    };

    const teardown = mm.deinit(reportRuntime());

    // HKM_MEM_STRICT turns a leak into a failing exit status, so CI can treat
    // leaks as build failures rather than something a human has to notice in
    // scrollback. Never overrides a real command failure.
    if (teardown == .leaked and code == 0 and memory_strict) {
        std.process.exit(70); // EX_SOFTWARE
    }

    std.process.exit(code);
}

/// The runtime label for the dashboard, or null to stay silent.
///
/// Reporting is opt-in: `hkm` is a CLI whose output gets piped and parsed, so
/// an unrequested dashboard on every invocation would be actively harmful.
fn reportRuntime() ?[]const u8 {
    if (!memory_inspect_requested) return null;
    return "hkm";
}

/// Set during argument parsing when `--mem` is passed (or HKM_MEM_INSPECT is
/// truthy). A file-level flag rather than a parameter because the decision is
/// made deep in dispatch() but consumed in main() after it returns.
var memory_inspect_requested: bool = false;

/// Set from HKM_MEM_STRICT. Read in main() after dispatch() has returned, by
/// which point the environment map it was read from is out of scope.
var memory_strict: bool = false;

/// A per-command allocation scope.
///
/// Tags the memory group and then opens a fresh arena, in that order. The order
/// is the whole point: the arena requests memory from the manager in large
/// chunks, so whichever group is active when it takes its FIRST chunk gets
/// charged for everything served out of it. With one process-wide arena opened
/// during startup, every command's memory was attributed to "startup" and the
/// group breakdown showed nothing useful.
///
/// Giving each command its own arena also means its memory is genuinely
/// released when it finishes, rather than accumulating until process exit.
const CmdScope = struct {
    arena: std.heap.ArenaAllocator,

    fn begin(mm: *memory.Manager, name: []const u8) CmdScope {
        mm.group(name);
        return .{ .arena = memory.scratch(mm.allocator()) };
    }

    fn allocator(self: *CmdScope) std.mem.Allocator {
        return self.arena.allocator();
    }

    fn end(self: *CmdScope) void {
        self.arena.deinit();
    }
};

fn dispatch(init: std.process.Init.Minimal, mm: *memory.Manager) !u8 {
    // The arena is kept — command code allocates many short-lived strings and
    // dropping them in one call is the right shape. It is now backed by the
    // memory manager rather than the page allocator directly, so everything the
    // CLI spends shows up in the inspector and the debug allocator can prove the
    // arena itself was released.
    var arena_allocator: std.heap.ArenaAllocator = memory.scratch(mm.allocator());
    defer arena_allocator.deinit();
    const allocator = arena_allocator.allocator();
    mm.group("startup");
    // global_single_threaded uses a `.failing` allocator, which makes
    // std.process.spawn OOM (it allocates argv/env before fork). Use a real
    // allocator-backed Threaded io so spawning child processes works.
    var threaded: std.Io.Threaded = .init(std.heap.page_allocator, .{});
    defer threaded.deinit();
    const io = threaded.io();
    var env_map = try init.environ.createMap(allocator);
    defer env_map.deinit();

    // Bind the output streams BEFORE anything can print. This decides results →
    // stdout / diagnostics → stderr, and whether either gets colour. Until it
    // runs, prompt falls back to stderr, so it must come first.
    prompt.init(io, &env_map);

    // Load persistent config (~/.config/hkm/config.env) so values written by
    // `hkm-config` take effect. Real environment variables always win.
    userconfig.load(allocator, io, &env_map);

    const raw_args = try init.args.toSlice(allocator);

    // `--dev` (anywhere in the args) pins this invocation to the DEVELOPMENT
    // kernel — the monorepo this launcher was built inside — instead of the
    // installed stable copy under /opt. Useful when running a freshly-built
    // tools/zig-out/bin/hkm from within the repo. We resolve the dev root by
    // climbing to the nearest ancestor holding composer.json, export it as
    // HKM_KERNEL_HOME + HKM_CLI_PATH so every command AND the passthrough use
    // it, then strip the flag so downstream arg parsing never sees it.
    var dev_mode = false;
    var args_list: std.ArrayList([]const u8) = .empty;
    defer args_list.deinit(allocator);
    memory_inspect_requested = util.envIsTruthy(&env_map, "HKM_MEM_INSPECT");
    memory_strict = util.envIsTruthy(&env_map, "HKM_MEM_STRICT");
    for (raw_args) |a| {
        if (std.mem.eql(u8, a, "--dev")) {
            dev_mode = true;
            continue;
        }
        // `--mem` prints the memory dashboard when the command finishes. Like
        // --dev it is stripped here so downstream arg parsing never sees it.
        if (std.mem.eql(u8, a, "--mem")) {
            memory_inspect_requested = true;
            continue;
        }
        try args_list.append(allocator, a);
    }
    const args = args_list.items;

    if (dev_mode) {
        // Resolve the dev kernel in two ways, in order:
        //   1. HKM_DEV_HOME — an explicit checkout path from config.env. This is
        //      what lets the INSTALLED hkm (/usr/bin/hkm) target a contributor's
        //      monorepo checkout anywhere on disk.
        //   2. Walk up from the launcher — works when running the repo-built
        //      tools/zig-out/bin/hkm from inside the checkout, no config needed.
        var dev_home: ?[]const u8 = null;
        if (env_map.get("HKM_DEV_HOME")) |h| {
            if (h.len > 0) {
                const t = util.trimSlash(h);
                if (kernel.isKernelDir(io, t)) {
                    dev_home = try allocator.dupe(u8, t);
                } else {
                    prompt.err(try std.fmt.allocPrint(allocator, "HKM_DEV_HOME points to {s} but that is not a kernel checkout (no composer.json).", .{t}));
                    return 1;
                }
            }
        }
        if (dev_home == null) dev_home = try kernel.resolveDevHome(allocator, io);

        if (dev_home) |home| {
            try env_map.put("HKM_KERNEL_HOME", home);
            const cli = try std.fs.path.join(allocator, &.{ home, "bin", "hkm" });
            try env_map.put("HKM_CLI_PATH", cli);
            // Explicit marker so commands can REQUIRE dev mode (e.g. anything that
            // touches the developer's machine, like edge:hosts writing /etc/hosts).
            try env_map.put("HKM_DEV", "1");
            prompt.muted(try std.fmt.allocPrint(allocator, "dev mode: using kernel at {s}", .{home}));
        } else {
            prompt.err("--dev: no development kernel found. Set HKM_DEV_HOME to your checkout, or run the repo-built tools/zig-out/bin/hkm from inside it.");
            return 1;
        }
    }

    if (args.len <= 1) {
        printHelp(allocator, io, &env_map);
        return 0;
    }

    const cmd = args[1];
    if (std.mem.eql(u8, cmd, "help") or std.mem.eql(u8, cmd, "--help") or std.mem.eql(u8, cmd, "-h")) {
        printHelp(allocator, io, &env_map);
        return 0;
    }
    if (std.mem.eql(u8, cmd, "--version") or std.mem.eql(u8, cmd, "-v")) {
        banner.printShort(io);
        return 0;
    }
    if (std.mem.eql(u8, cmd, "version")) {
        var scope = CmdScope.begin(mm, "version");
        defer scope.end();
        return try version_cmd.run(scope.allocator(), io, &env_map, args);
    }
    if (std.mem.eql(u8, cmd, "upgrade") or std.mem.eql(u8, cmd, "self-update")) {
        var scope = CmdScope.begin(mm, "upgrade");
        defer scope.end();
        return try upgrade_cmd.run(scope.allocator(), io, &env_map, args);
    }
    if (std.mem.eql(u8, cmd, "uninstall")) {
        var scope = CmdScope.begin(mm, "uninstall");
        defer scope.end();
        return try uninstall_cmd.run(scope.allocator(), io, &env_map, args);
    }

    // `new` / `update` are handled natively in Zig (no PHP required).
    if (std.mem.eql(u8, cmd, "new")) {
        var scope = CmdScope.begin(mm, "new");
        defer scope.end();
        return try new_cmd.run(scope.allocator(), io, &env_map, args);
    }
    if (std.mem.eql(u8, cmd, "update")) {
        var scope = CmdScope.begin(mm, "update");
        defer scope.end();
        return try update_cmd.run(scope.allocator(), io, &env_map, args);
    }
    if (std.mem.eql(u8, cmd, "run") or std.mem.eql(u8, cmd, "serve")) {
        var scope = CmdScope.begin(mm, "run");
        defer scope.end();
        return try run_cmd.run(scope.allocator(), io, &env_map, args);
    }
    if (std.mem.eql(u8, cmd, "list") or std.mem.eql(u8, cmd, "ls")) {
        var scope = CmdScope.begin(mm, "list");
        defer scope.end();
        return try list_cmd.run(scope.allocator(), io, &env_map, args);
    }
    if (std.mem.eql(u8, cmd, "discover") or std.mem.eql(u8, cmd, "scan")) {
        var scope = CmdScope.begin(mm, "discover");
        defer scope.end();
        return try discover_cmd.run(scope.allocator(), io, &env_map, args);
    }
    if (std.mem.eql(u8, cmd, "module")) {
        var scope = CmdScope.begin(mm, "module");
        defer scope.end();
        return try module_cmd.run(scope.allocator(), io, &env_map, args);
    }
    if (std.mem.eql(u8, cmd, "plugins") or std.mem.eql(u8, cmd, "modules")) {
        var scope = CmdScope.begin(mm, "plugins");
        defer scope.end();
        return try plugins_cmd.run(scope.allocator(), io, &env_map, args);
    }
    if (std.mem.eql(u8, cmd, "ui")) {
        var scope = CmdScope.begin(mm, "ui");
        defer scope.end();
        return try ui_cmd.run(scope.allocator(), io, &env_map, args);
    }
    if (std.mem.eql(u8, cmd, "cli")) {
        var scope = CmdScope.begin(mm, "cli");
        defer scope.end();
        return try cli_cmd.run(scope.allocator(), io, &env_map, args, false);
    }
    if (std.mem.eql(u8, cmd, "worker")) {
        var scope = CmdScope.begin(mm, "worker");
        defer scope.end();
        return try cli_cmd.run(scope.allocator(), io, &env_map, args, true);
    }
    if (std.mem.eql(u8, cmd, "doctor")) {
        var scope = CmdScope.begin(mm, "doctor");
        defer scope.end();
        return try doctor_cmd.run(scope.allocator(), io, &env_map, args);
    }

    var scope = CmdScope.begin(mm, "passthrough");
    defer scope.end();
    const pass = scope.allocator();
    const php = try phpBin(pass, &env_map);
    const cli = try findCliPath(pass, io, &env_map);

    var child_argv: std.ArrayList([]const u8) = .empty;
    defer child_argv.deinit(pass);

    try child_argv.append(pass, php);
    try child_argv.append(pass, cli);

    // pass-through remaining arguments
    var i: usize = 1;
    while (i < args.len) : (i += 1) {
        try child_argv.append(pass, args[i]);
    }

    // A spawn failure here is the ONLY thing standing between a mistyped
    // command and a raw Zig error as the user-facing message. Propagating it
    // printed exactly `error: FileNotFound` on a release build — no filename,
    // no mention of PHP, and no pointer to `hkm doctor`, the command that
    // exists to explain a missing runtime. Both causes are known at this point,
    // so both get said.
    var child = std.process.spawn(io, .{
        .argv = child_argv.items,
        .environ_map = &env_map,
        .stdin = .inherit,
        .stdout = .inherit,
        .stderr = .inherit,
    }) catch |e| {
        return reportPassthroughFailure(pass, io, &env_map, e, php, cli, cmd);
    };

    const term = try child.wait(io);
    return switch (term) {
        .exited => |code| code,
        else => 1,
    };
}

// ---------------------------------------------------------------------------
// Test collection
//
// Zig only runs `test` blocks from files it actually analyses. A top-level
// `@import` is not enough on its own: without referencing the imported file
// from an analysed declaration, its tests are silently skipped and `zig build
// test` reports success having run nothing. Referencing each module here is
// what pulls their tests into the binary.
//
// Verified by mutation — breaking an assertion in lib/memory.zig must turn this
// step red. If it does not, the module below is not being reached.
// ---------------------------------------------------------------------------
test {
    _ = @import("lib/memory.zig");
    _ = @import("lib/util.zig");
    _ = @import("lib/semver.zig");
    _ = @import("lib/plugin_registry.zig");
    _ = @import("lib/plugin_lock.zig");
    _ = @import("lib/plugin_git.zig");
    _ = @import("lib/plugin_install.zig");
    _ = @import("lib/inspector/tracked.zig");
    _ = @import("lib/inspector/meminspector.zig");
}
