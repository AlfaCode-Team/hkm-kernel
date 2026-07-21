const std = @import("std");
const new_cmd = @import("commands/new.zig");
const update_cmd = @import("commands/update.zig");
const run_cmd = @import("commands/run.zig");
const list_cmd = @import("commands/list.zig");
const discover_cmd = @import("commands/discover.zig");
const plugins_cmd = @import("commands/plugins.zig");
const ui_cmd = @import("commands/ui.zig");
const cli_cmd = @import("commands/cli.zig");
const doctor_cmd = @import("commands/doctor.zig");
const upgrade_cmd = @import("commands/upgrade.zig");
const kernel = @import("lib/kernel.zig");
const util = @import("lib/util.zig");
const userconfig = @import("lib/userconfig.zig");
const banner = @import("lib/banner.zig");
const prompt = @import("lib/prompt.zig");

fn printHelp() void {
    banner.print();

    prompt.section("Usage");
    prompt.item("hkm new <path> [opts]", "scaffold a new PhpServicePlatform project");
    prompt.item("hkm run [path|name]", "run a project locally (PHP dev server)");
    prompt.item("hkm cli [command]", "run a project's console interactively");
    prompt.item("hkm worker [args]", "run a project's queue worker");
    prompt.item("hkm list", "list registered projects (alias: ls)");
    prompt.item("hkm discover [root]", "find projects on disk and register them (alias: scan)");
    prompt.item("hkm plugins [path|name]", "analyse a project's enabled plugins/modules");
    prompt.item("hkm ui [sync|list|link|clean]", "federate enabled plugins' UIs into the frontend");
    prompt.item("hkm update <path|name>", "refresh a project's kernel registry entry");
    prompt.item("hkm upgrade [--check]", "check for / apply a kernel update");
    prompt.item("hkm doctor", "diagnose the local environment");
    prompt.item("hkm version", "show the Sentinel banner + version (also --version, -v)");
    prompt.item("hkm help", "show this help");
    prompt.item("hkm <cmd> --dev", "use the development kernel (this monorepo) instead of the installed stable copy");
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

    prompt.outro("Run 'hkm <command> --help' for command details");
}

fn envGet(allocator: std.mem.Allocator, map: *std.process.Environ.Map, key: []const u8) !?[]const u8 {
    const v = map.get(key) orelse return null;
    return try allocator.dupe(u8, v);
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

pub fn main(init: std.process.Init.Minimal) !void {
    var arena_allocator: std.heap.ArenaAllocator = .init(std.heap.page_allocator);
    defer arena_allocator.deinit();
    const allocator = arena_allocator.allocator();
    // global_single_threaded uses a `.failing` allocator, which makes
    // std.process.spawn OOM (it allocates argv/env before fork). Use a real
    // allocator-backed Threaded io so spawning child processes works.
    var threaded: std.Io.Threaded = .init(std.heap.page_allocator, .{});
    defer threaded.deinit();
    const io = threaded.io();
    var env_map = try init.environ.createMap(allocator);
    defer env_map.deinit();

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
    for (raw_args) |a| {
        if (std.mem.eql(u8, a, "--dev")) {
            dev_mode = true;
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
                    std.process.exit(1);
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
            std.process.exit(1);
        }
    }

    if (args.len <= 1) {
        printHelp();
        return;
    }

    const cmd = args[1];
    if (std.mem.eql(u8, cmd, "help") or std.mem.eql(u8, cmd, "--help") or std.mem.eql(u8, cmd, "-h")) {
        printHelp();
        return;
    }
    if (std.mem.eql(u8, cmd, "--version") or std.mem.eql(u8, cmd, "-v")) {
        banner.printShort();
        return;
    }
    if (std.mem.eql(u8, cmd, "version")) {
        banner.print();
        return;
    }
    if (std.mem.eql(u8, cmd, "upgrade") or std.mem.eql(u8, cmd, "self-update")) {
        const code = try upgrade_cmd.run(allocator, io, &env_map, args);
        std.process.exit(code);
    }

    // `new` / `update` are handled natively in Zig (no PHP required).
    if (std.mem.eql(u8, cmd, "new")) {
        const code = try new_cmd.run(allocator, io, &env_map, args);
        std.process.exit(code);
    }
    if (std.mem.eql(u8, cmd, "update")) {
        const code = try update_cmd.run(allocator, io, &env_map, args);
        std.process.exit(code);
    }
    if (std.mem.eql(u8, cmd, "run") or std.mem.eql(u8, cmd, "serve")) {
        const code = try run_cmd.run(allocator, io, &env_map, args);
        std.process.exit(code);
    }
    if (std.mem.eql(u8, cmd, "list") or std.mem.eql(u8, cmd, "ls")) {
        const code = try list_cmd.run(allocator, io, &env_map, args);
        std.process.exit(code);
    }
    if (std.mem.eql(u8, cmd, "discover") or std.mem.eql(u8, cmd, "scan")) {
        const code = try discover_cmd.run(allocator, io, &env_map, args);
        std.process.exit(code);
    }
    if (std.mem.eql(u8, cmd, "plugins") or std.mem.eql(u8, cmd, "modules")) {
        const code = try plugins_cmd.run(allocator, io, &env_map, args);
        std.process.exit(code);
    }
    if (std.mem.eql(u8, cmd, "ui")) {
        const code = try ui_cmd.run(allocator, io, &env_map, args);
        std.process.exit(code);
    }
    if (std.mem.eql(u8, cmd, "cli")) {
        const code = try cli_cmd.run(allocator, io, &env_map, args, false);
        std.process.exit(code);
    }
    if (std.mem.eql(u8, cmd, "worker")) {
        const code = try cli_cmd.run(allocator, io, &env_map, args, true);
        std.process.exit(code);
    }
    if (std.mem.eql(u8, cmd, "doctor")) {
        const code = try doctor_cmd.run(allocator, io, &env_map, args);
        std.process.exit(code);
    }

    const php = try phpBin(allocator, &env_map);
    const cli = try findCliPath(allocator, io, &env_map);

    var child_argv: std.ArrayList([]const u8) = .empty;
    defer child_argv.deinit(allocator);

    try child_argv.append(allocator, php);
    try child_argv.append(allocator, cli);

    // pass-through remaining arguments
    var i: usize = 1;
    while (i < args.len) : (i += 1) {
        try child_argv.append(allocator, args[i]);
    }

    var child = try std.process.spawn(io, .{
        .argv = child_argv.items,
        .environ_map = &env_map,
        .stdin = .inherit,
        .stdout = .inherit,
        .stderr = .inherit,
    });

    const term = try child.wait(io);
    switch (term) {
        .exited => |code| std.process.exit(code),
        else => std.process.exit(1),
    }
}
