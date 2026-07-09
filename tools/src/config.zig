//! `hkm-config` — inspect and repair the launcher's persistent configuration
//! (`~/.config/hkm/config.env`, loaded by `hkm` at startup).
//!
//!   hkm-config                     # check config; auto-configure if incomplete
//!   hkm-config check               # same as no-args
//!   hkm-config print               # show the config file path + contents
//!   hkm-config set-kernel-home <p> # pin HKM_KERNEL_HOME
//!   hkm-config set-autoload <p>    # pin HKM_GLOBAL_AUTOLOAD (vendor/autoload.php)
//!   hkm-config set-dev-home <p>    # pin HKM_DEV_HOME (dev checkout used by --dev)
//!
//! "check" resolves the kernel (env → relative to this binary → /opt/hkm-kernel)
//! and, if the config file is missing or stale, writes HKM_KERNEL_HOME for you.

const std = @import("std");
const kernel = @import("lib/kernel.zig");
const userconfig = @import("lib/userconfig.zig");
const prompt = @import("lib/prompt.zig");
const util = @import("lib/util.zig");

const Io = std.Io;
const EnvMap = std.process.Environ.Map;

pub fn main(init: std.process.Init.Minimal) !void {
    var arena: std.heap.ArenaAllocator = .init(std.heap.page_allocator);
    defer arena.deinit();
    const allocator = arena.allocator();
    var threaded: std.Io.Threaded = .init(std.heap.page_allocator, .{});
    defer threaded.deinit();
    const io = threaded.io();
    var env = try init.environ.createMap(allocator);
    defer env.deinit();

    // Make already-saved config visible to resolution below.
    userconfig.load(allocator, io, &env);

    const args = try init.args.toSlice(allocator);
    const action = if (args.len >= 2) args[1] else "check";

    if (std.mem.eql(u8, action, "print")) {
        const p = (try userconfig.path(allocator, &env)) orelse {
            prompt.err("cannot resolve config path (no HOME).");
            std.process.exit(1);
        };
        prompt.section("Config file");
        prompt.item("path", p);
        if (std.Io.Dir.cwd().readFileAlloc(io, p, allocator, .limited(64 * 1024))) |c| {
            prompt.blank();
            std.debug.print("{s}\n", .{c});
        } else |_| prompt.muted("(file does not exist yet — run `hkm-config` to create it)");
        return;
    }

    if (std.mem.eql(u8, action, "set-kernel-home")) {
        if (args.len < 3) return usage();
        try userconfig.set(allocator, io, &env, "HKM_KERNEL_HOME", args[2]);
        prompt.ok("HKM_KERNEL_HOME saved.");
        return;
    }
    if (std.mem.eql(u8, action, "set-autoload")) {
        if (args.len < 3) return usage();
        try userconfig.set(allocator, io, &env, "HKM_GLOBAL_AUTOLOAD", args[2]);
        prompt.ok("HKM_GLOBAL_AUTOLOAD saved.");
        return;
    }
    if (std.mem.eql(u8, action, "set-dev-home")) {
        if (args.len < 3) return usage();
        // The DEV kernel: a contributor's monorepo checkout, used only when a
        // command is invoked with --dev. Validate it looks like a kernel root
        // so a typo fails here rather than at first --dev use.
        const p = util.trimSlash(args[2]);
        if (!kernel.isKernelDir(io, p)) {
            prompt.err("that path is not a kernel checkout (no composer.json).");
            std.process.exit(1);
        }
        try userconfig.set(allocator, io, &env, "HKM_DEV_HOME", p);
        prompt.ok("HKM_DEV_HOME saved. Use `hkm <command> --dev` to target it.");
        return;
    }
    if (std.mem.eql(u8, action, "check") or std.mem.eql(u8, action, "configure")) {
        std.process.exit(try runCheck(allocator, io, &env));
    }

    usage();
}

fn usage() void {
    prompt.section("hkm-config");
    prompt.item("hkm-config", "check config; auto-configure if incomplete");
    prompt.item("hkm-config print", "show the config file path + contents");
    prompt.item("hkm-config set-kernel-home <p>", "pin the kernel root");
    prompt.item("hkm-config set-autoload <p>", "pin vendor/autoload.php");
    prompt.item("hkm-config set-dev-home <p>", "pin the development kernel checkout used by --dev");
}

fn runCheck(allocator: std.mem.Allocator, io: Io, env: *EnvMap) !u8 {
    prompt.intro("hkm-config");

    const cfg = (try userconfig.path(allocator, env)) orelse {
        prompt.err("no HOME — cannot locate ~/.config/hkm/config.env");
        return 1;
    };
    prompt.section("Configuration");
    prompt.item("config file", cfg);
    prompt.item("exists", if (util.fileExists(io, cfg)) "yes" else "no (will create)");

    // 1. Locate the kernel.
    const home = (try kernel.resolveHome(allocator, io, env)) orelse {
        prompt.blank();
        prompt.err("no kernel found.");
        prompt.item("fix", "install the hkm-kernel package, or: hkm-config set-kernel-home <path>");
        return 1;
    };
    prompt.item("kernel home", home);

    // 2. Check kernel pieces.
    const autoload = try std.fs.path.join(allocator, &.{ home, "vendor", "autoload.php" });
    const projects = try std.fs.path.join(allocator, &.{ home, "projects", "projects.json" });
    const have_vendor = util.fileExists(io, autoload);
    const have_registry = util.fileExists(io, projects);
    prompt.item("vendor/autoload.php", if (have_vendor) "present" else "MISSING");
    prompt.item("projects registry", if (have_registry) "present" else "absent (no projects registered yet)");

    // 3. Ensure HKM_KERNEL_HOME is persisted and current.
    const saved = try userconfig.get(allocator, io, env, "HKM_KERNEL_HOME");
    var wrote = false;
    if (saved == null or !std.mem.eql(u8, saved.?, home)) {
        try userconfig.set(allocator, io, env, "HKM_KERNEL_HOME", home);
        wrote = true;
    }

    // 4. Ensure a PERSISTENT userdata dir holds the registry (projects.json +
    //    platform.json) so kernel updates never overwrite it. Seed from the
    //    kernel defaults, then pin HKM_USERDATA_DIR.
    const userdata = try ensureUserdata(allocator, io, env, home);
    if (userdata) |ud| {
        prompt.item("userdata dir", ud);
        const cur = try userconfig.get(allocator, io, env, "HKM_USERDATA_DIR");
        if (cur == null or !std.mem.eql(u8, cur.?, ud)) {
            try userconfig.set(allocator, io, env, "HKM_USERDATA_DIR", ud);
            wrote = true;
        }
    }

    prompt.blank();
    if (!have_vendor) {
        prompt.warn("kernel dependencies are not installed.");
        const installer = try std.fs.path.join(allocator, &.{ home, "install.sh" });
        if (util.fileExists(io, installer)) {
            prompt.item("run", try std.fmt.allocPrint(allocator, "sh {s}", .{installer}));
        } else {
            prompt.item("run", try std.fmt.allocPrint(allocator, "cd {s} && composer install --no-dev", .{home}));
        }
        return 1;
    }

    if (wrote) {
        prompt.ok("configuration written — HKM_KERNEL_HOME + HKM_USERDATA_DIR pinned.");
    } else {
        prompt.ok("configuration is complete.");
    }
    prompt.muted("verify the runtime with: hkm doctor");
    return 0;
}

/// Resolve (and create + seed) the persistent userdata directory that holds the
/// registry files. Order: existing HKM_USERDATA_DIR → XDG_DATA_HOME/hkm →
/// HOME/.local/share/hkm. Seeds projects.json ({}) and platform.json (copied
/// from the kernel default) when absent. Returns the dir, or null if unresolvable.
fn ensureUserdata(allocator: std.mem.Allocator, io: Io, env: *EnvMap, home: []const u8) !?[]const u8 {
    const dir: []const u8 = blk: {
        if (env.get("HKM_USERDATA_DIR")) |d| {
            if (d.len > 0) break :blk try allocator.dupe(u8, d);
        }
        if (env.get("XDG_DATA_HOME")) |x| {
            if (x.len > 0) break :blk try std.fs.path.join(allocator, &.{ x, "hkm" });
        }
        if (env.get("HOME")) |h| {
            if (h.len > 0) break :blk try std.fs.path.join(allocator, &.{ h, ".local", "share", "hkm" });
        }
        return null;
    };

    const cwd = std.Io.Dir.cwd();
    cwd.createDirPath(io, dir) catch {};

    // Seed projects.json: migrate an existing kernel registry if present (so
    // relocating doesn't drop registrations), else start with an empty one.
    const proj = try std.fs.path.join(allocator, &.{ dir, "projects.json" });
    if (!util.fileExists(io, proj)) {
        const src = try std.fs.path.join(allocator, &.{ home, "projects", "projects.json" });
        const data = cwd.readFileAlloc(io, src, allocator, .limited(8 * 1024 * 1024)) catch "{}\n";
        cwd.writeFile(io, .{ .sub_path = proj, .data = data }) catch {};
    }

    // Seed platform.json from the kernel's shipped default, else a minimal map.
    const plat = try std.fs.path.join(allocator, &.{ dir, "platform.json" });
    if (!util.fileExists(io, plat)) {
        const src = try std.fs.path.join(allocator, &.{ home, "projects", "platform.json" });
        const data = cwd.readFileAlloc(io, src, allocator, .limited(1024 * 1024)) catch
            "{\n    \"subdomains\": { \"admin\": [\"app\"], \"api\": [\"api\"] }\n}\n";
        cwd.writeFile(io, .{ .sub_path = plat, .data = data }) catch {};
    }

    return dir;
}
