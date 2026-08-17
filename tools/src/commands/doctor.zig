//! `hkm doctor` — the single authority on "can this machine run the kernel?".
//!
//! Installing is deliberately unconditional: `tools/install.sh` only copies
//! files into your home and never demands PHP, composer or an administrator.
//! That trade means SOMETHING has to tell you what is still missing, in one
//! place, with the command to fix each item. This is that something.
//!
//! It walks every requirement the kernel actually has, in the order they matter:
//!
//!   Launcher       this binary, whether its dir is on PATH, and whether another
//!                  `hkm` earlier on PATH would shadow it
//!   Kernel         the kernel root, its PHP CLI, the first-party modules/
//!                  path-repositories, and vendor/autoload.php
//!   Configuration  ~/.config/hkm/config.env, a STALE HKM_KERNEL_HOME pin, the
//!                  userdata dir and the project registry
//!   Tooling        php, composer, git, node/npm — each with what needs it
//!   PHP runtime    version, required extensions, a PDO driver (asked of PHP
//!                  itself via a `php -r` preflight, so it reflects the exact
//!                  runtime a project will use rather than a guess)
//!
//! Exit code is 0 only when every HARD requirement passes, so it works as a CI
//! gate. Soft findings (no git, no node, PATH not set up) warn and do not fail.

const std = @import("std");
const builtin = @import("builtin");
const run_cmd = @import("run.zig");
const install_scope = @import("../lib/install_scope.zig");
const kernel = @import("../lib/kernel.zig");
const prompt = @import("../lib/prompt.zig");
const userconfig = @import("../lib/userconfig.zig");
const util = @import("../lib/util.zig");

const Io = std.Io;
const Dir = std.Io.Dir;
const EnvMap = std.process.Environ.Map;

/// Accumulates findings so the run never stops at the first problem — someone
/// with three things missing should learn all three from one command.
const Report = struct {
    hard: std.ArrayList([]const u8) = .empty,
    soft: std.ArrayList([]const u8) = .empty,
    allocator: std.mem.Allocator,

    fn fail(self: *Report, fix: []const u8) void {
        self.hard.append(self.allocator, fix) catch {};
    }
    fn hint(self: *Report, fix: []const u8) void {
        self.soft.append(self.allocator, fix) catch {};
    }
};

const OK = "OK";
const MISSING = "MISSING";

fn mark(present: bool) []const u8 {
    return if (present) OK else MISSING;
}

/// Locate an executable by walking PATH. Returns the FIRST match, which is the
/// one that would actually run.
fn findOnPath(allocator: std.mem.Allocator, io: Io, env: *EnvMap, name: []const u8) ?[]const u8 {
    const path = env.get("PATH") orelse return null;
    var it = std.mem.splitScalar(u8, path, ':');
    while (it.next()) |dir| {
        if (dir.len == 0) continue;
        const cand = std.fs.path.join(allocator, &.{ dir, name }) catch continue;
        if (util.fileExists(io, cand)) return cand;
    }
    return null;
}

/// Every match on PATH, in order — used to detect one install shadowing another.
fn countOnPath(allocator: std.mem.Allocator, io: Io, env: *EnvMap, name: []const u8) usize {
    const path = env.get("PATH") orelse return 0;
    var n: usize = 0;
    var it = std.mem.splitScalar(u8, path, ':');
    while (it.next()) |dir| {
        if (dir.len == 0) continue;
        const cand = std.fs.path.join(allocator, &.{ dir, name }) catch continue;
        if (util.fileExists(io, cand)) n += 1;
    }
    return n;
}

fn dirOnPath(env: *EnvMap, dir: []const u8) bool {
    const path = env.get("PATH") orelse return false;
    var it = std.mem.splitScalar(u8, path, ':');
    while (it.next()) |entry| {
        if (std.mem.eql(u8, util.trimSlash(entry), util.trimSlash(dir))) return true;
    }
    return false;
}

/// The PHP preflight. A single `-r` program so `hkm doctor` needs no files on
/// disk. Prints a table and exits non-zero on a hard failure.
const preflight =
    \\$reqPhp = '8.4.1';
    \\$okPhp  = version_compare(PHP_VERSION, $reqPhp, '>=');
    \\printf("  php %-13s runtime %s (need >= %s) [%s]\n", '', PHP_VERSION, $reqPhp, $okPhp ? 'OK' : 'FAIL');
    \\$required = ['json','mbstring','ctype','tokenizer','filter','pdo','openssl','curl','fileinfo'];
    \\$missing = [];
    \\foreach ($required as $e) {
    \\    $has = extension_loaded($e);
    \\    if (!$has) $missing[] = $e;
    \\    printf("  ext-%-11s %s\n", $e, $has ? 'OK' : 'MISSING <-- required');
    \\}
    \\$drivers = class_exists('PDO') ? PDO::getAvailableDrivers() : [];
    \\$hasDriver = (bool) array_intersect(['mysql','pgsql','sqlite','sqlsrv'], $drivers);
    \\printf("  pdo-driver     %s (%s)\n", $hasDriver ? 'OK' : 'MISSING <-- need one', $drivers ? implode(',', $drivers) : 'none');
    \\$ml = ini_get('memory_limit');
    \\printf("  memory_limit   %s\n", $ml === false ? 'unknown' : $ml);
    \\$optional = [
    \\  'redis'      => 'RedisCache plugin (cache + queue)',
    \\  'swoole'     => 'OpenSwoole HTTP server (api face)',
    \\  'openswoole' => 'OpenSwoole HTTP server (api face)',
    \\  'gd'         => 'image processing',
    \\  'intl'       => 'i18n / locale formatting',
    \\  'zip'        => 'archive support',
    \\  'sodium'     => 'modern crypto (recommended)',
    \\  'opcache'    => 'production performance',
    \\];
    \\echo "\n  optional:\n";
    \\foreach ($optional as $e => $why) {
    \\    printf("  ext-%-11s %-9s %s\n", $e, extension_loaded($e) ? 'present' : 'absent', $why);
    \\}
    \\exit((!$okPhp || $missing || !$hasDriver) ? 1 : 0);
;

fn phpBin(allocator: std.mem.Allocator, env: *EnvMap) ![]const u8 {
    if (env.get("HKM_PHP_BIN")) |v| return allocator.dupe(u8, v);
    return allocator.dupe(u8, "php");
}

pub fn run(allocator: std.mem.Allocator, io: Io, env: *EnvMap, args: []const []const u8) !u8 {
    _ = args;
    prompt.intro("hkm doctor");

    var rep = Report{ .allocator = allocator };
    const cwd = Dir.cwd();

    // ── Platform ────────────────────────────────────────────────────────────
    prompt.section("Platform");
    prompt.item("os", @tagName(builtin.os.tag));
    prompt.item("arch", @tagName(builtin.cpu.arch));

    // ── Launcher ────────────────────────────────────────────────────────────
    prompt.section("Launcher");
    var own_dir: ?[]const u8 = null;
    if (std.process.executableDirPathAlloc(io, allocator)) |d| {
        own_dir = d;
        prompt.item("this binary", d);
        if (dirOnPath(env, d)) {
            prompt.item("on PATH", "yes");
        } else {
            prompt.item("on PATH", "NO — `hkm` will not be found in a new shell");
            rep.hint(try std.fmt.allocPrint(allocator, "add to PATH: export PATH=\"{s}:$PATH\"", .{d}));
        }
    } else |_| {
        prompt.item("this binary", "unknown");
    }

    // Two installs (a .deb in /usr/bin and a user install in ~/.local/bin) is a
    // normal state, and the one that wins is decided by PATH order — silently.
    const n_hkm = countOnPath(allocator, io, env, "hkm");
    if (n_hkm > 1) {
        const first = findOnPath(allocator, io, env, "hkm") orelse "?";
        prompt.item("copies on PATH", try std.fmt.allocPrint(allocator, "{d} — first is {s}", .{ n_hkm, first }));
        if (own_dir) |d| {
            const own_exe = try std.fs.path.join(allocator, &.{ d, "hkm" });
            if (!std.mem.eql(u8, own_exe, first)) {
                prompt.warn("another hkm earlier on PATH shadows this one.");
                rep.hint("remove the system copy (sudo apt remove hkm-kernel) or reorder PATH");
            }
        }
    }

    // ── Installs ────────────────────────────────────────────────────────────
    //
    // Listed before the kernel section because the two scopes are the context
    // everything below it is read in. A machine can hold both, they upgrade
    // separately, and PATH silently decides which launcher a bare `hkm` runs —
    // so "which kernel am I even looking at" has to be answered first.
    const home_early = try kernel.resolveHomeDetailed(allocator, io, env);
    prompt.section("Installs");
    var install_rows: std.ArrayList([]const []const u8) = .empty;
    var any_install = false;
    for ([_]install_scope.Scope{ .system, .user }) |sc| {
        const inst = install_scope.detect(allocator, io, env, sc);
        if (inst.present) any_install = true;

        const active: []const u8 = blk: {
            const root = home_early.root orelse break :blk " ";
            break :blk if (std.mem.eql(u8, util.trimSlash(root), util.trimSlash(inst.root))) "→" else " ";
        };

        try install_rows.append(allocator, try allocator.dupe([]const u8, &.{
            active,
            sc.label(),
            inst.root,
            if (!inst.present) "not installed" else install_scope.versionLabel(inst.version),
            if (!inst.present) "-" else if (inst.vendor) OK else "no vendor/",
        }));

        if (inst.legacy_root) |legacy| {
            rep.hint(try std.fmt.allocPrint(
                allocator,
                "a stale user kernel remains at {s} — migrate with `hkm upgrade --user`, then delete it",
                .{legacy},
            ));
        }
    }
    prompt.table(allocator, &.{ "", "scope", "kernel root", "version", "deps" }, install_rows.items);
    if (!any_install) {
        rep.hint("no kernel installed in either scope — `hkm upgrade --user` installs one without root");
    }

    // ── Kernel ──────────────────────────────────────────────────────────────
    prompt.section("Kernel");
    const k = try kernel.resolve(allocator, io, env);
    prompt.item("cli path", k.path);
    prompt.item("resolved via", kernel.sourceLabel(k.source));
    prompt.item("cli present", if (k.exists) OK else "MISSING — reinstall or set HKM_KERNEL_HOME");
    if (!k.exists) rep.fail("kernel CLI missing — reinstall, or: hkm-config set-kernel-home <path>");

    const home_opt = home_early.root;
    var vendor_ok = false;
    if (home_opt) |home| {
        prompt.item("kernel root", home);

        const composer_json = try std.fs.path.join(allocator, &.{ home, "composer.json" });
        const has_manifest = util.fileExists(io, composer_json);
        prompt.item("composer.json", mark(has_manifest));
        if (!has_manifest) rep.fail("kernel root has no composer.json — the install is incomplete");

        const src_dir = try std.fs.path.join(allocator, &.{ home, "src" });
        prompt.item("src/", mark(util.dirExists(cwd, io, src_dir)));

        // The first-party packages are composer PATH repositories. When they are
        // absent, `composer install` fails outright rather than degrading — so
        // this is worth naming individually.
        const mods = [_][]const u8{ "bind-it", "php-io-cli", "let-migrate", "http" };
        var missing_mods: usize = 0;
        for (mods) |m| {
            const p = try std.fs.path.join(allocator, &.{ home, "modules", m, "composer.json" });
            if (!util.fileExists(io, p)) missing_mods += 1;
        }
        prompt.item("modules/ (4 first-party)", if (missing_mods == 0)
            OK
        else
            try std.fmt.allocPrint(allocator, "{d} MISSING — composer install will fail", .{missing_mods}));
        if (missing_mods > 0) {
            rep.fail("first-party modules missing — reinstall the bundle, or: git submodule update --init");
        }

        const autoload = try std.fs.path.join(allocator, &.{ home, "vendor", "autoload.php" });
        vendor_ok = util.fileExists(io, autoload);
        prompt.item("vendor/autoload.php", if (vendor_ok) OK else "MISSING — dependencies not installed");
        if (!vendor_ok) {
            rep.fail(try std.fmt.allocPrint(allocator, "install dependencies: cd {s} && ./install.sh", .{home}));
        }

        prompt.item("root writable", if (util.canWrite(io, home)) "yes" else "no — composer install will fail here");
        if (!util.canWrite(io, home)) {
            rep.hint("kernel root is not writable by you (a root-owned /opt install?) — prefer a user install");
        }
    } else {
        prompt.item("kernel root", "NOT FOUND");
        rep.fail("no kernel found — install it, or: hkm-config set-kernel-home <path>");
    }

    // ── Configuration ───────────────────────────────────────────────────────
    prompt.section("Configuration");
    if (try userconfig.path(allocator, env)) |cfg| {
        prompt.item("config file", cfg);
        prompt.item("exists", if (util.fileExists(io, cfg)) "yes" else "no (defaults in use)");
    }

    // A config-file pin is shared by EVERY launcher on the machine, so one left
    // behind by a user install used to redirect the system launcher's kernel
    // too. Self-location now outranks it (lib/kernel.zig), which makes a
    // leftover pin harmless but still worth removing: it is consulted whenever
    // a launcher cannot self-locate, and that is a hard failure to read.
    if (try userconfig.get(allocator, io, env, "HKM_KERNEL_HOME")) |pin| {
        prompt.item("HKM_KERNEL_HOME", pin);
        const in_use = if (home_opt) |h| std.mem.eql(u8, util.trimSlash(pin), util.trimSlash(h)) else false;
        if (home_early.source == .kernel_home_config) {
            prompt.warn("this kernel comes from the config pin — the launcher could not self-locate one.");
        } else if (!in_use) {
            prompt.warn("the pin points at a different kernel than the one in use (self-location wins).");
            rep.hint("remove the stale pin: hkm-config unset HKM_KERNEL_HOME");
        } else {
            rep.hint("the pin is redundant (self-location finds the same kernel): hkm-config unset HKM_KERNEL_HOME");
        }
    } else {
        prompt.item("HKM_KERNEL_HOME", "not pinned (self-locating)");
    }

    const userdata = try userconfig.get(allocator, io, env, "HKM_USERDATA_DIR");
    if (userdata) |ud| {
        prompt.item("userdata dir", ud);
        prompt.item("writable", if (util.canWrite(io, ud)) "yes" else "NO — the registry cannot be updated");
        if (!util.canWrite(io, ud)) rep.fail("userdata dir is not writable — check its ownership");

        const proj = try std.fs.path.join(allocator, &.{ ud, "projects.json" });
        prompt.item("projects.json", if (util.fileExists(io, proj)) OK else "absent (no projects registered yet)");
    } else {
        prompt.item("userdata dir", "not pinned — run: hkm-config check");
        rep.hint("pin a persistent registry dir so upgrades cannot touch it: hkm-config check");
    }

    // ── Tooling ─────────────────────────────────────────────────────────────
    prompt.section("Tooling");
    const php = try phpBin(allocator, env);
    const php_path = findOnPath(allocator, io, env, php);
    prompt.item("php", php_path orelse "MISSING — required to run anything");
    if (php_path == null) {
        rep.fail("install PHP >= 8.4  (Debian: sudo apt install php8.4-cli)");
    }

    const composer_path = findOnPath(allocator, io, env, "composer");
    prompt.item("composer", composer_path orelse "absent — needed only to build vendor/");
    if (composer_path == null and !vendor_ok) {
        // The kernel's install.sh downloads composer.phar when composer is not
        // installed, so this is a hint rather than a hard failure.
        rep.hint("no composer — the kernel's install.sh will fetch composer.phar instead");
    }

    prompt.item("git", findOnPath(allocator, io, env, "git") orelse "absent — needed by `hkm plugins` git sources");
    prompt.item("node", findOnPath(allocator, io, env, "node") orelse "absent — needed by `hkm ui` (frontend only)");
    prompt.item("npm", findOnPath(allocator, io, env, "npm") orelse "absent — needed by `hkm ui` (frontend only)");

    // ── PHP runtime & extensions ────────────────────────────────────────────
    prompt.section("PHP runtime & extensions");
    if (php_path == null) {
        prompt.warn("skipped — no php binary to ask.");
        prompt.item("Debian/Ubuntu", "sudo apt install php8.4-cli php8.4-{mbstring,curl,xml,zip,mysql}");
        prompt.item("macOS (brew)", "brew install php");
        prompt.item("Windows", "winget install PHP.PHP");
        prompt.item("override", "set HKM_PHP_BIN=/full/path/to/php");
    } else {
        var argv = [_][]const u8{ php, "-d", "display_errors=stderr", "-r", preflight };
        const code = run_cmd.spawnWait(io, env, &argv) catch |e| blk: {
            prompt.err("could not execute the PHP binary.");
            prompt.item("tried", php);
            prompt.item("detail", @errorName(e));
            break :blk @as(u8, 1);
        };
        if (code != 0) {
            rep.fail("PHP runtime does not meet requirements — see the table above");
        }
    }

    // ── Verdict ─────────────────────────────────────────────────────────────
    prompt.blank();
    if (rep.hard.items.len == 0 and rep.soft.items.len == 0) {
        prompt.ok("everything the kernel needs is present.");
        return 0;
    }

    if (rep.hard.items.len > 0) {
        prompt.section("Must fix");
        for (rep.hard.items) |f| prompt.item("→", f);
    }
    if (rep.soft.items.len > 0) {
        prompt.section("Worth fixing");
        for (rep.soft.items) |f| prompt.item("→", f);
    }

    prompt.blank();
    if (rep.hard.items.len > 0) {
        prompt.warn("environment is INCOMPLETE — the items under \"Must fix\" block the kernel.");
        return 1;
    }
    prompt.ok("environment is usable; the notes above are optional improvements.");
    return 0;
}
