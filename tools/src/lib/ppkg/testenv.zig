//! Zero-network test environments for plugins.
//!
//! ## Why this exists
//!
//! Verifying a freshly fetched plugin used to mean `composer install` inside it.
//! That is a dependency RESOLUTION, and a plugin's composer.json declares ~34
//! `vcs` repositories with `dev-master` / `dev-main` constraints and ships no
//! composer.lock — so Composer had to read a composer.json off every branch and
//! tag of all 34 GitHub repositories to build a package pool, per plugin, with
//! the vendor/ deleted in between so nothing was reused. Without a GitHub token
//! the API returns 403 after 60 requests an hour and Composer silently falls
//! back to full `git clone`s.
//!
//! All of that to reach a phpunit and a handful of sibling plugin classes that
//! were already sitting on the disk.
//!
//! ## What it does instead
//!
//! Writes a `vendor/autoload.php` that delegates to the KERNEL's autoloader —
//! which already has phpunit, the kernel classes and all 107 Packagist packages
//! registered — and layers the plugin's own PSR-4 rules on top of it. A
//! dependency is found by SEARCHING the checkouts already on disk for one whose
//! composer.json declares that package name, never by fetching.
//!
//! ## The honest boundary
//!
//! A dev dependency with no checkout on disk cannot be conjured. It is reported
//! as missing and the caller decides — the one thing this must never do is
//! quietly produce an environment where a test fails for want of a class and
//! reads as the PLUGIN being broken.

const std = @import("std");
const manifest = @import("ppkg").manifest;
const kernel = @import("../kernel.zig");
const sources = @import("../plugin_sources.zig");
const prompt = @import("../prompt.zig");
const util = @import("../util.zig");

const Io = std.Io;
const Dir = std.Io.Dir;
const EnvMap = std.process.Environ.Map;
const Manifest = manifest.Manifest;

/// A resolved test environment.
pub const Env = struct {
    /// Absolute path of the generated bootstrap, for `phpunit --bootstrap`.
    autoload_path: []const u8,
    /// Absolute path to a runnable phpunit, or null when none was found.
    phpunit: ?[]const u8,
    /// Package names required for tests that no checkout on disk provides.
    missing: []const []const u8,
    /// Packages resolved from disk, as `name => directory`, for reporting.
    resolved: []const Resolved,

    pub const Resolved = struct { name: []const u8, dir: []const u8 };
};

pub const Error = error{
    NoKernel,
    NoManifest,
};

/// Build the environment for the plugin checked out at `plugin_dir`.
///
/// Writes `<plugin_dir>/vendor/autoload.php` and nothing else — no vendor
/// packages are copied, because every one of them is loaded in place from where
/// it already lives.
pub fn build(
    allocator: std.mem.Allocator,
    io: Io,
    env: *EnvMap,
    plugin_dir: []const u8,
) !Env {
    const kernel_home = (try kernel.resolveHome(allocator, io, env)) orelse return Error.NoKernel;
    const kernel_autoload = try std.fs.path.join(allocator, &.{ kernel_home, "vendor", "autoload.php" });
    if (!util.fileExists(io, kernel_autoload)) return Error.NoKernel;

    const own = (try manifest.read(allocator, io, plugin_dir)) orelse return Error.NoManifest;

    // Where a dependency checkout might already be.
    const roots = try searchRoots(allocator, io, env, plugin_dir);

    var registrations: std.ArrayList(Registration) = .empty;
    var missing: std.ArrayList([]const u8) = .empty;
    var resolved: std.ArrayList(Env.Resolved) = .empty;

    // The plugin under test comes first, so its own rules are registered even
    // if a stale copy of it is also installed in the kernel's plugins/.
    try register(allocator, own, plugin_dir, &registrations);

    // Tests need require AND require-dev; the runtime requires are already
    // satisfied by the kernel autoloader when they are kernel packages, and by
    // a disk checkout when they are sibling plugins.
    for (own.require_dev) |dep| {
        if (dep.isPlatform()) continue;
        if (providedByKernel(dep.name)) continue;

        if (try findCheckout(allocator, io, roots, dep.name)) |dir| {
            const dep_manifest = (try manifest.read(allocator, io, dir)) orelse continue;
            try register(allocator, dep_manifest, dir, &registrations);
            try resolved.append(allocator, .{ .name = dep.name, .dir = dir });
        } else {
            try missing.append(allocator, dep.name);
        }
    }

    const body = try render(allocator, kernel_autoload, registrations.items);

    const vendor_dir = try std.fs.path.join(allocator, &.{ plugin_dir, "vendor" });
    Dir.cwd().createDirPath(io, vendor_dir) catch {};
    const autoload_path = try std.fs.path.join(allocator, &.{ vendor_dir, "autoload.php" });
    try util.writeFileAtomic(io, autoload_path, body);

    return .{
        .autoload_path = autoload_path,
        .phpunit = try findPhpunit(allocator, io, kernel_home),
        .missing = try missing.toOwnedSlice(allocator),
        .resolved = try resolved.toOwnedSlice(allocator),
    };
}

/// One PSR-4/PSR-0 rule or eagerly-loaded file to emit into the bootstrap.
const Registration = union(enum) {
    psr4: struct { prefix: []const u8, dir: []const u8 },
    psr0: struct { prefix: []const u8, dir: []const u8 },
    file: []const u8,
};

/// Turn a package's autoload block into registrations rooted at its directory.
///
/// `autoload-dev` is included for every package, not just the root: a plugin's
/// own tests live under its `autoload-dev`, and a test-support package exists
/// precisely to be used by another package's tests.
fn register(
    allocator: std.mem.Allocator,
    m: Manifest,
    dir: []const u8,
    out: *std.ArrayList(Registration),
) !void {
    for ([_]manifest.Autoload{ m.autoload, m.autoload_dev }) |block| {
        for (block.psr4) |rule| for (rule.paths) |p| {
            try out.append(allocator, .{ .psr4 = .{
                .prefix = rule.prefix,
                .dir = try absolutise(allocator, dir, p),
            } });
        };
        for (block.psr0) |rule| for (rule.paths) |p| {
            try out.append(allocator, .{ .psr0 = .{
                .prefix = rule.prefix,
                .dir = try absolutise(allocator, dir, p),
            } });
        };
        for (block.files) |f| {
            try out.append(allocator, .{ .file = try absolutise(allocator, dir, f) });
        }
        // `classmap` is deliberately not honoured here. Composer would scan and
        // bake it; a test run does not need it, because every class a classmap
        // entry would have found is reachable through the PSR-4 rules of the
        // same package in practice, and scanning per plugin would reintroduce
        // exactly the per-plugin cost this module exists to remove.
    }
}

fn absolutise(allocator: std.mem.Allocator, dir: []const u8, sub: []const u8) ![]const u8 {
    const s = std.mem.trim(u8, sub, "/");
    if (s.len == 0) return allocator.dupe(u8, dir);
    return std.fs.path.join(allocator, &.{ dir, s });
}

/// Packages the kernel's own autoloader already provides.
///
/// The kernel IS `alfacode-team/php-service-platform`, and its vendor/ holds
/// every Packagist dependency including phpunit — so a plugin requiring any of
/// them needs no checkout of its own.
fn providedByKernel(name: []const u8) bool {
    if (std.mem.eql(u8, name, "alfacode-team/php-service-platform")) return true;
    // Anything that is not a first-party plugin came from Packagist and is in
    // the kernel's vendor/ already (phpunit, mockery, faker, …).
    return !isFirstPartyPlugin(name);
}

fn isFirstPartyPlugin(name: []const u8) bool {
    return std.mem.startsWith(u8, name, "alfacode-team/hkm-");
}

/// Directories that may contain a checkout of a required package.
///
/// Ordered most-specific first. The plugin's own PARENT is included because the
/// common development layout keeps every plugin as siblings in one folder, and
/// that copy is the one a developer is actually editing.
fn searchRoots(
    allocator: std.mem.Allocator,
    io: Io,
    env: *EnvMap,
    plugin_dir: []const u8,
) ![]const []const u8 {
    var out: std.ArrayList([]const u8) = .empty;

    if (std.fs.path.dirname(plugin_dir)) |parent| try out.append(allocator, parent);

    const srcs = sources.discoverSources(allocator, io, env, null) catch null;
    if (srcs) |s| {
        if (s.kernel_dir) |k| try out.append(allocator, k);
        if (s.project_dir) |p| try out.append(allocator, p);
    }

    return out.toOwnedSlice(allocator);
}

/// Find a directory under one of `roots` whose composer.json declares `name`.
///
/// The composer.json is read rather than the directory name matched, because
/// the folder a plugin lives in is its PSR-4 name (`I18n`) and has no reliable
/// relationship to its package name (`alfacode-team/hkm-plugin-i18n`).
fn findCheckout(
    allocator: std.mem.Allocator,
    io: Io,
    roots: []const []const u8,
    name: []const u8,
) !?[]const u8 {
    for (roots) |root| {
        var d = Dir.cwd().openDir(io, root, .{ .iterate = true }) catch continue;
        defer d.close(io);

        var it = d.iterate();
        while (try it.next(io)) |entry| {
            if (entry.kind != .directory and entry.kind != .sym_link) continue;
            if (entry.name.len > 0 and entry.name[0] == '.') continue;

            const candidate = try std.fs.path.join(allocator, &.{ root, entry.name });
            const m = manifest.read(allocator, io, candidate) catch continue;
            if (m) |found| {
                if (std.mem.eql(u8, found.name, name)) return candidate;
            }
        }
    }
    return null;
}

fn findPhpunit(allocator: std.mem.Allocator, io: Io, kernel_home: []const u8) !?[]const u8 {
    const p = try std.fs.path.join(allocator, &.{ kernel_home, "vendor", "bin", "phpunit" });
    return if (util.fileExists(io, p)) p else null;
}

/// Render the bootstrap PHP.
fn render(
    allocator: std.mem.Allocator,
    kernel_autoload: []const u8,
    registrations: []const Registration,
) ![]const u8 {
    var out: std.ArrayList(u8) = .empty;

    try out.appendSlice(allocator,
        \\<?php
        \\
        \\// @generated by `hkm ppkg test-env` — regenerate rather than edit.
        \\//
        \\// Delegates to the kernel's autoloader (phpunit, the kernel itself and
        \\// every Packagist package already live there) and layers this plugin's
        \\// own rules on top. A longer PSR-4 prefix always wins in Composer's
        \\// ClassLoader, so `Plugins\Foo\` registered here takes precedence over
        \\// the kernel's generic `Plugins\` rule without needing to prepend.
        \\
        \\
    );

    try out.appendSlice(allocator, "$loader = require ");
    try appendPhpString(allocator, &out, kernel_autoload);
    try out.appendSlice(allocator, ";\n\n");

    for (registrations) |r| switch (r) {
        .psr4 => |v| {
            try out.appendSlice(allocator, "$loader->addPsr4(");
            try appendPhpString(allocator, &out, v.prefix);
            try out.appendSlice(allocator, ", ");
            try appendPhpString(allocator, &out, v.dir);
            try out.appendSlice(allocator, ");\n");
        },
        .psr0 => |v| {
            try out.appendSlice(allocator, "$loader->add(");
            try appendPhpString(allocator, &out, v.prefix);
            try out.appendSlice(allocator, ", ");
            try appendPhpString(allocator, &out, v.dir);
            try out.appendSlice(allocator, ");\n");
        },
        .file => {},
    };

    // Files load AFTER every rule is registered: a bootstrap file routinely
    // references a class from its own package, which must already be loadable.
    var wrote_any_file = false;
    for (registrations) |r| switch (r) {
        .file => |path| {
            if (!wrote_any_file) {
                try out.appendSlice(allocator, "\n");
                wrote_any_file = true;
            }
            // require_once, and guarded: a helpers file that is missing from a
            // checkout should not fatal the whole suite before it starts.
            try out.appendSlice(allocator, "if (is_file(");
            try appendPhpString(allocator, &out, path);
            try out.appendSlice(allocator, ")) { require_once ");
            try appendPhpString(allocator, &out, path);
            try out.appendSlice(allocator, "; }\n");
        },
        else => {},
    };

    try out.appendSlice(allocator, "\nreturn $loader;\n");
    return out.toOwnedSlice(allocator);
}

/// A PHP single-quoted string literal.
fn appendPhpString(allocator: std.mem.Allocator, out: *std.ArrayList(u8), s: []const u8) !void {
    try out.append(allocator, '\'');
    for (s) |c| {
        if (c == '\\' or c == '\'') try out.append(allocator, '\\');
        try out.append(allocator, c);
    }
    try out.append(allocator, '\'');
}

// ── command ───────────────────────────────────────────────────────────────────

pub fn command(allocator: std.mem.Allocator, io: Io, env: *EnvMap, args: []const []const u8) !u8 {
    if (args.len == 0) {
        prompt.err("Usage: hkm ppkg test-env <plugin-dir>");
        return 2;
    }

    const dir = try util.absPath(allocator, env, args[0]);

    prompt.intro("hkm ppkg test-env");

    const result = build(allocator, io, env, dir) catch |e| {
        prompt.err(switch (e) {
            Error.NoKernel => "No kernel with a built vendor/ was found. `hkm ppkg test-env` borrows the kernel's autoloader and phpunit; run `composer install` in the kernel once.",
            Error.NoManifest => "That directory has no composer.json.",
            else => "Could not build the test environment.",
        });
        return 1;
    };

    prompt.item("bootstrap", result.autoload_path);
    prompt.item("phpunit", result.phpunit orelse "not found in the kernel's vendor/bin");

    for (result.resolved) |r| {
        prompt.muted(try std.fmt.allocPrint(allocator, "    {s}  ← {s}", .{ r.name, r.dir }));
    }

    if (result.missing.len > 0) {
        prompt.blank();
        prompt.warn("Test dependencies with no checkout on disk:");
        for (result.missing) |m| {
            prompt.muted(try std.fmt.allocPrint(allocator, "    {s}", .{m}));
        }
        prompt.note("Install them as plugins, or clone them beside this one, and re-run.");
    }

    prompt.blank();
    prompt.outro("no network used");
    return 0;
}

// ── tests ─────────────────────────────────────────────────────────────────────

const testing = std.testing;

test "kernel packages need no checkout; first-party plugins do" {
    try testing.expect(providedByKernel("phpunit/phpunit"));
    try testing.expect(providedByKernel("alfacode-team/php-service-platform"));
    try testing.expect(!providedByKernel("alfacode-team/hkm-plugin-i18n"));
    try testing.expect(!providedByKernel("alfacode-team/hkm-test-support"));
}

test "an empty psr-4 path means the package directory itself" {
    var arena = std.heap.ArenaAllocator.init(testing.allocator);
    defer arena.deinit();
    const a = arena.allocator();

    try testing.expectEqualStrings("/p/I18n", try absolutise(a, "/p/I18n", ""));
    try testing.expectEqualStrings("/p/I18n/tests", try absolutise(a, "/p/I18n", "tests"));
}

test "bootstrap registers rules before requiring files" {
    var arena = std.heap.ArenaAllocator.init(testing.allocator);
    defer arena.deinit();
    const a = arena.allocator();

    const regs = [_]Registration{
        .{ .file = "/p/I18n/Support/helpers.php" },
        .{ .psr4 = .{ .prefix = "Plugins\\I18n\\", .dir = "/p/I18n" } },
    };
    const php = try render(a, "/k/vendor/autoload.php", &regs);

    const psr4_at = std.mem.indexOf(u8, php, "addPsr4").?;
    const file_at = std.mem.indexOf(u8, php, "require_once").?;
    try testing.expect(psr4_at < file_at);

    // Backslashes in a PHP single-quoted literal must be escaped, or
    // 'Plugins\I18n\' would terminate at the closing quote it escapes.
    try testing.expect(std.mem.indexOf(u8, php, "'Plugins\\\\I18n\\\\'") != null);
    try testing.expect(std.mem.indexOf(u8, php, "$loader = require '/k/vendor/autoload.php';") != null);
}

test "a missing bootstrap file does not fatal the suite" {
    var arena = std.heap.ArenaAllocator.init(testing.allocator);
    defer arena.deinit();

    const regs = [_]Registration{.{ .file = "/p/gone.php" }};
    const php = try render(arena.allocator(), "/k/vendor/autoload.php", &regs);
    try testing.expect(std.mem.indexOf(u8, php, "if (is_file('/p/gone.php'))") != null);
}
