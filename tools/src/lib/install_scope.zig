//! The two install SCOPES a machine can hold, and how to find each one.
//!
//! HKM ships two installers with two different footprints, and until this file
//! existed nothing in the CLI modelled that they can both be present:
//!
//!   system  .deb        /opt/hkm-kernel        + /usr/bin/{hkm,hkm-config}      root
//!   user    tarball     ~/.local/lib/hkm-kernel + ~/.local/bin/{hkm,hkm-config}  no root
//!
//! Both are legitimate and they COEXIST — a machine with a system install from
//! an earlier deploy, plus a user install for day-to-day work, is the ordinary
//! state, not a broken one. What was broken was that every part of the CLI
//! spoke of "the installed kernel" as if there were one:
//!
//!   • `hkm upgrade` on Linux only ever fetched the .deb and shelled out to
//!     sudo apt-get, so a user install could never update itself. PATH then
//!     resolved the STALE user launcher first and the upgrade looked like it
//!     had done nothing.
//!   • `hkm version` printed the launcher's own compile-time stamp and named no
//!     kernel at all, so with two installs present it answered a question
//!     nobody asked.
//!   • One shared `HKM_KERNEL_HOME` pin in ~/.config/hkm/config.env was read by
//!     BOTH launchers, so whichever installer ran last silently redirected the
//!     other one's kernel. (See lib/kernel.zig for how resolution now stops
//!     that.)
//!
//! Every one of those is the same missing distinction. This file supplies it:
//! given the environment, report what is installed in each scope, at what
//! version, and which one this invocation is actually running.
//!
//! The paths here are not free parameters. `system` mirrors the layout
//! tools/bundle.sh writes into the .deb, and `user` mirrors what
//! tools/install.sh writes into $HKM_PREFIX — including the bin/ + lib/ pairing
//! that lets the launcher self-locate its kernel with no env var at all.
//! Changing one side without the other breaks resolution.

const std = @import("std");
const composer_version = @import("composer_version.zig");
const util = @import("util.zig");

const Io = std.Io;
const EnvMap = std.process.Environ.Map;

pub const Scope = enum {
    system,
    user,

    pub fn label(self: Scope) []const u8 {
        return switch (self) {
            .system => "system",
            .user => "user",
        };
    }

    /// How that scope is installed — used in guidance, so it names the command
    /// the reader should actually run.
    pub fn how(self: Scope) []const u8 {
        if (bundles_apps) return switch (self) {
            .system => "system-wide (/Applications, needs write access)",
            .user => "user-local (~/Applications, no root)",
        };
        return switch (self) {
            .system => "system-wide (.deb, needs root)",
            .user => "user-local (tarball, no root)",
        };
    }
};

/// Does this platform install .app BUNDLES rather than the Debian/tarball pair?
///
/// macOS has the same two scopes — one in your home, one machine-wide — but
/// they are ~/Applications/HKM.app and /Applications/HKM.app. The Linux paths
/// below are not merely unused there: reporting them listed two "not installed"
/// rows on a machine with a perfectly good install, which is how `hkm doctor`
/// came to advise `hkm upgrade --user` to fix an install that already worked.
pub const bundles_apps = @import("builtin").os.tag == .macos;

/// Everything below an .app bundle, shared by both macOS scopes.
pub const mac_app_tail = "Applications/HKM.app/Contents/Resources/opt/hkm-kernel";

/// The kernel root for `scope` on an .app platform.
pub fn macAppRoot(allocator: std.mem.Allocator, env: *EnvMap, scope: Scope) ?[]const u8 {
    return switch (scope) {
        .system => "/" ++ mac_app_tail,
        .user => blk: {
            const home = homeDir(allocator, env) orelse break :blk null;
            break :blk std.fmt.allocPrint(allocator, "{s}/{s}", .{ home, mac_app_tail }) catch null;
        },
    };
}

/// The launcher directory for an .app kernel root: `<bundle>/Contents/MacOS`.
///
/// Not `<prefix>/bin` — that holds the wrapper SCRIPTS install-macos.sh writes,
/// while the real binary this reports on lives inside the bundle.
pub fn macAppBinDir(allocator: std.mem.Allocator, root: ?[]const u8) ?[]const u8 {
    const r = root orelse return null;
    // root is <bundle>/Contents/Resources/opt/hkm-kernel → up 3 to <bundle>/Contents
    const opt = std.fs.path.dirname(r) orelse return null;
    const resources = std.fs.path.dirname(opt) orelse return null;
    const contents = std.fs.path.dirname(resources) orelse return null;
    return std.fmt.allocPrint(allocator, "{s}/MacOS", .{contents}) catch null;
}

/// The system kernel root. Fixed by the .deb's own layout.
pub const system_root = "/opt/hkm-kernel";

/// Where the .deb puts the launcher.
pub const system_bin_dir = "/usr/bin";

/// Directories a launcher living in means "this is the system install".
///
/// Needed because /usr/bin/hkm CANNOT self-locate /opt/hkm-kernel by relative
/// probing — there is no fixed relative path between them — so without this the
/// system launcher fell through to the config-file pin, which is precisely the
/// hijack this module exists to prevent.
pub const system_bin_dirs = [_][]const u8{ "/usr/bin", "/usr/local/bin", "/bin", "/sbin", "/usr/sbin" };

/// Is `dir` one of the system bin directories?
///
/// Always false on macOS. The whole notion is a .deb fact — "a launcher here
/// belongs to the system package, whose kernel is /opt/hkm-kernel" — and no
/// .deb exists on macOS, so the only thing the answer can do there is mislead.
/// `/usr/local/bin` makes that concrete: on an Intel Mac it is Homebrew's own
/// bin directory, so a launcher installed there would have been declared the
/// system install and pointed at a /opt/hkm-kernel that cannot exist.
pub fn isSystemBinDir(dir: []const u8) bool {
    if (@import("builtin").os.tag == .macos) return false;
    const d = util.trimSlash(dir);
    for (system_bin_dirs) |candidate| {
        if (std.mem.eql(u8, d, candidate)) return true;
    }
    return false;
}

/// The invoking user's home directory.
///
/// Honours SUDO_USER, because under `sudo hkm …` HOME is root's (/root on
/// Linux, /var/root on macOS) while
/// every user-scope path the command needs to REPORT belongs to the person who
/// typed the command. Without this, `sudo hkm version` would claim there is no
/// user install on a machine that has one.
pub fn homeDir(allocator: std.mem.Allocator, env: *EnvMap) ?[]const u8 {
    if (env.get("SUDO_USER")) |user| {
        if (util.sudoUserHome(allocator, user)) |h| return h;
    }
    const home = env.get("HOME") orelse return null;
    if (home.len == 0) return null;
    return util.trimSlash(home);
}

/// The user install PREFIX: $HKM_PREFIX, else ~/.local.
///
/// Same variable tools/install.sh reads, so `--prefix /srv/hkm` and
/// `HKM_PREFIX=/srv/hkm hkm upgrade` land in the same place.
pub fn userPrefix(allocator: std.mem.Allocator, env: *EnvMap) ?[]const u8 {
    if (env.get("HKM_PREFIX")) |p| {
        if (p.len > 0) return util.trimSlash(p);
    }
    const home = homeDir(allocator, env) orelse return null;
    return std.fmt.allocPrint(allocator, "{s}/.local", .{home}) catch null;
}

/// The user kernel root: `<prefix>/lib/hkm-kernel`.
///
/// This path is chosen so `<prefix>/bin/hkm` self-locates it by probing
/// "<parent-of-exe-dir>/lib/hkm-kernel" (lib/kernel.zig). That is what makes a
/// user install need NO environment variable and NO config pin — and therefore
/// what stops it from having to write a pin that then hijacks the system
/// install. The earlier `--user` target (~/.local/share/hkm/kernel) sat outside
/// every probe, so it could only be reached through a pin; see legacyUserRoot.
pub fn userRoot(allocator: std.mem.Allocator, env: *EnvMap) ?[]const u8 {
    const prefix = userPrefix(allocator, env) orelse return null;
    return std.fmt.allocPrint(allocator, "{s}/lib/hkm-kernel", .{prefix}) catch null;
}

/// Where a user install puts its launchers: `<prefix>/bin`.
pub fn userBinDir(allocator: std.mem.Allocator, env: *EnvMap) ?[]const u8 {
    const prefix = userPrefix(allocator, env) orelse return null;
    return std.fmt.allocPrint(allocator, "{s}/bin", .{prefix}) catch null;
}

/// Where `hkm upgrade --local --user` used to install: the userdata dir.
///
/// Still probed so a machine that took that path is RECOGNISED rather than
/// reported as having no user install — and so the migration can name it.
/// Nothing writes here any more.
pub fn legacyUserRoot(allocator: std.mem.Allocator, env: *EnvMap) ?[]const u8 {
    if (env.get("XDG_DATA_HOME")) |x| {
        if (x.len > 0) return std.fmt.allocPrint(allocator, "{s}/hkm/kernel", .{util.trimSlash(x)}) catch null;
    }
    const home = homeDir(allocator, env) orelse return null;
    return std.fmt.allocPrint(allocator, "{s}/.local/share/hkm/kernel", .{home}) catch null;
}

/// Is this process running with root privileges?
///
/// This is the switch that makes `sudo hkm upgrade` update the system install
/// and a plain `hkm upgrade` update the user's own. EFFECTIVE uid rather than
/// SUDO_USER, because that is what actually decides whether the write to /opt
/// will succeed — `su -`, a root shell and a container all have no SUDO_USER
/// and are all genuinely root.
///
/// The syscall is reached per-platform rather than through std.posix, which has
/// no geteuid in the pinned toolchain (0.17.0-dev). Linux gets the raw syscall
/// so a statically linked launcher needs no libc; everything else POSIX goes
/// through the libc symbol.
pub fn isRoot(env: *EnvMap) bool {
    _ = env;
    return switch (@import("builtin").os.tag) {
        .windows => false,
        .linux => std.os.linux.geteuid() == 0,
        else => std.c.geteuid() == 0,
    };
}

/// The scope a command should act on when the user named none.
///
/// Root → system, otherwise → user. Deliberately derived from privilege rather
/// than from what happens to be installed: it makes `sudo hkm upgrade` and
/// `hkm upgrade` two predictable, different commands instead of one command
/// whose target depends on machine state.
pub fn defaultScope(env: *EnvMap) Scope {
    return if (isRoot(env)) .system else .user;
}

/// What is installed in one scope.
pub const Install = struct {
    scope: Scope,
    /// Could this scope's paths be resolved at all?
    ///
    /// False only for `.user` with no HOME and no HKM_PREFIX — a cron job or a
    /// stripped service environment. It exists so an unresolvable user scope is
    /// never quietly represented by the SYSTEM paths: an upgrade that fell back
    /// that way would write to /opt on behalf of a command the user ran
    /// specifically to avoid touching /opt.
    resolved: bool,
    /// Kernel root for this scope — always populated, even when absent, so a
    /// diagnostic can say WHERE it looked. Meaningless when `resolved` is false.
    root: []const u8,
    /// Directory the launcher for this scope lives in, when it is resolvable.
    bin_dir: ?[]const u8,
    /// The kernel root holds a composer.json.
    present: bool,
    /// `"version"` from that composer.json — null for a checkout-style install
    /// that was never stamped.
    version: ?[]const u8,
    /// Path to the launcher binary, when one exists there.
    launcher: ?[]const u8,
    /// Dependencies resolved (vendor/autoload.php present).
    vendor: bool,
    /// A LEGACY user install found at the old ~/.local/share/hkm/kernel path.
    /// Only ever set for .user.
    legacy_root: ?[]const u8 = null,
};

/// Inspect one scope. Never fails: an absent install is a result, not an error.
pub fn detect(allocator: std.mem.Allocator, io: Io, env: *EnvMap, scope: Scope) Install {
    const maybe_root: ?[]const u8 = if (bundles_apps)
        macAppRoot(allocator, env, scope)
    else switch (scope) {
        .system => system_root,
        .user => userRoot(allocator, env),
    };
    const bin_dir: ?[]const u8 = if (bundles_apps)
        macAppBinDir(allocator, maybe_root)
    else switch (scope) {
        .system => system_bin_dir,
        .user => userBinDir(allocator, env),
    };

    var out = Install{
        .scope = scope,
        .resolved = maybe_root != null,
        .root = maybe_root orelse "(no HOME — user scope unresolvable)",
        .bin_dir = bin_dir,
        .present = false,
        .version = null,
        .launcher = null,
        .vendor = false,
    };
    const root = maybe_root orelse return out;

    const manifest = std.fs.path.join(allocator, &.{ root, "composer.json" }) catch return out;
    out.present = util.fileExists(io, manifest);
    if (out.present) {
        out.version = composer_version.ofKernel(allocator, io, root);
        if (std.fs.path.join(allocator, &.{ root, "vendor", "autoload.php" })) |autoload| {
            out.vendor = util.fileExists(io, autoload);
        } else |_| {}
    }

    if (bin_dir) |dir| {
        if (std.fs.path.join(allocator, &.{ dir, launcher_name })) |exe| {
            if (util.fileExists(io, exe)) out.launcher = exe;
        } else |_| {}
    }

    // A user install left at the pre-1.4 path is worth surfacing even when the
    // current one is fine — it is a second kernel on disk that a stale pin can
    // still point at.
    // The pre-1.4 user kernel is a Linux-installer artefact; no macOS install
    // ever wrote there, so probing for it would only add a row that cannot exist.
    if (!bundles_apps and scope == .user) {
        if (legacyUserRoot(allocator, env)) |legacy| {
            if (!std.mem.eql(u8, legacy, root)) {
                if (std.fs.path.join(allocator, &.{ legacy, "composer.json" })) |m| {
                    if (util.fileExists(io, m)) out.legacy_root = legacy;
                } else |_| {}
            }
        }
    }

    return out;
}

/// The launcher's filename on this platform.
pub const launcher_name = if (@import("builtin").os.tag == .windows) "hkm.exe" else "hkm";

/// Which scope does a kernel root belong to? Null when it is neither — a dev
/// checkout, or an operator's custom prefix.
pub fn scopeOf(allocator: std.mem.Allocator, env: *EnvMap, root: []const u8) ?Scope {
    const r = util.trimSlash(root);

    // On an .app platform the scopes ARE the two bundle locations. Comparing
    // against the Linux roots here made `hkm version` print "scope: neither — a
    // checkout or a custom prefix" for a stock ~/Applications install, on the
    // very line below a table that had just marked it as the user scope.
    if (bundles_apps) {
        for ([_]Scope{ .system, .user }) |sc| {
            if (macAppRoot(allocator, env, sc)) |m| {
                if (std.mem.eql(u8, r, util.trimSlash(m))) return sc;
            }
        }
        return null;
    }

    if (std.mem.eql(u8, r, system_root)) return .system;
    if (userRoot(allocator, env)) |u| {
        if (std.mem.eql(u8, r, util.trimSlash(u))) return .user;
    }
    if (legacyUserRoot(allocator, env)) |u| {
        if (std.mem.eql(u8, r, util.trimSlash(u))) return .user;
    }
    return null;
}

/// The version to print for a kernel root: its stamped version, or a marker.
///
/// "unstamped" rather than "unknown" is deliberate — for a `--local` install
/// from a checkout it is the CORRECT answer, and it points at the reason
/// (nothing stamped it) instead of implying something is broken.
pub fn versionLabel(v: ?[]const u8) []const u8 {
    return v orelse "unstamped";
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test "the user kernel root is the path the launcher can self-locate" {
    // bin/ + lib/ side by side is what lib/kernel.zig probes as
    // "<parent-of-exe-dir>/lib/hkm-kernel". If this pairing drifts, a user
    // install becomes reachable only through a config pin — and a config pin is
    // read by BOTH launchers, which is the hijack this module exists to stop.
    var arena = std.heap.ArenaAllocator.init(std.testing.allocator);
    defer arena.deinit();
    const a = arena.allocator();

    var env = std.process.Environ.Map.init(a);
    defer env.deinit();
    try env.put("HOME", "/home/tester");

    try std.testing.expectEqualStrings("/home/tester/.local/lib/hkm-kernel", userRoot(a, &env).?);
    try std.testing.expectEqualStrings("/home/tester/.local/bin", userBinDir(a, &env).?);
}

test "HKM_PREFIX relocates both halves together" {
    var arena = std.heap.ArenaAllocator.init(std.testing.allocator);
    defer arena.deinit();
    const a = arena.allocator();

    var env = std.process.Environ.Map.init(a);
    defer env.deinit();
    try env.put("HOME", "/home/tester");
    try env.put("HKM_PREFIX", "/srv/hkm/");

    // The trailing slash must not produce "//lib" — install.sh writes the same
    // two paths and they have to match byte for byte for scopeOf to work.
    try std.testing.expectEqualStrings("/srv/hkm/lib/hkm-kernel", userRoot(a, &env).?);
    try std.testing.expectEqualStrings("/srv/hkm/bin", userBinDir(a, &env).?);
}

test "sudo reports the invoking user's install, not root's" {
    // Under `sudo hkm version` HOME is /root. Resolving the user scope from it
    // would claim the machine has no user install while one sits in the home
    // directory of the person who typed the command.
    var arena = std.heap.ArenaAllocator.init(std.testing.allocator);
    defer arena.deinit();
    const a = arena.allocator();

    var env = std.process.Environ.Map.init(a);
    defer env.deinit();
    try env.put("HOME", "/root");
    try env.put("SUDO_USER", "tester");

    // The base differs per platform — /home on Linux, /Users on macOS — and
    // asserting the Linux spelling everywhere is what let the macOS breakage
    // (an autofs mount point that cannot be written) sit here looking tested.
    const base = if (@import("builtin").os.tag == .macos) "/Users" else "/home";
    const want_home = try std.fmt.allocPrint(a, "{s}/tester", .{base});
    const want_root = try std.fmt.allocPrint(a, "{s}/tester/.local/lib/hkm-kernel", .{base});

    try std.testing.expectEqualStrings(want_home, homeDir(a, &env).?);
    try std.testing.expectEqualStrings(want_root, userRoot(a, &env).?);
}

test "SUDO_USER=root is not treated as a different user" {
    var arena = std.heap.ArenaAllocator.init(std.testing.allocator);
    defer arena.deinit();
    const a = arena.allocator();

    var env = std.process.Environ.Map.init(a);
    defer env.deinit();
    try env.put("HOME", "/root");
    try env.put("SUDO_USER", "root");

    try std.testing.expectEqualStrings("/root", homeDir(a, &env).?);
}

test "scopeOf recognises both current roots and the legacy user one" {
    var arena = std.heap.ArenaAllocator.init(std.testing.allocator);
    defer arena.deinit();
    const a = arena.allocator();

    var env = std.process.Environ.Map.init(a);
    defer env.deinit();
    try env.put("HOME", "/home/tester");

    if (bundles_apps) {
        // The .app pair, and a trailing slash must not change the answer.
        const sys = macAppRoot(a, &env, .system).?;
        const usr = macAppRoot(a, &env, .user).?;
        try std.testing.expectEqual(Scope.system, scopeOf(a, &env, sys).?);
        try std.testing.expectEqual(
            Scope.system,
            scopeOf(a, &env, try std.fmt.allocPrint(a, "{s}/", .{sys})).?,
        );
        try std.testing.expectEqual(Scope.user, scopeOf(a, &env, usr).?);
        // The Linux roots are not scopes here, whatever they happen to contain.
        try std.testing.expect(scopeOf(a, &env, "/opt/hkm-kernel") == null);
    } else {
        try std.testing.expectEqual(Scope.system, scopeOf(a, &env, "/opt/hkm-kernel").?);
        try std.testing.expectEqual(Scope.system, scopeOf(a, &env, "/opt/hkm-kernel/").?);
        try std.testing.expectEqual(Scope.user, scopeOf(a, &env, "/home/tester/.local/lib/hkm-kernel").?);
        // The pre-1.4 --user target still resolves to the user scope, so a
        // machine holding one is diagnosed rather than reported as "neither".
        try std.testing.expectEqual(Scope.user, scopeOf(a, &env, "/home/tester/.local/share/hkm/kernel").?);
    }

    // A dev checkout belongs to no install scope, on any platform.
    try std.testing.expect(scopeOf(a, &env, "/home/tester/Documents/HKMCODE") == null);
}

test "a launcher in a system bin dir is recognised as the system install" {
    // This is what lets /usr/bin/hkm claim /opt/hkm-kernel ahead of a
    // config-file pin. Without it the .deb launcher has no self-location at all
    // and follows whatever the last user-level installer wrote.
    //
    // The claim is Linux-shaped on purpose, so the assertion is too: there is
    // no .deb on macOS, and /usr/local/bin there is Intel Homebrew's own bin.
    const on_macos = @import("builtin").os.tag == .macos;
    try std.testing.expectEqual(!on_macos, isSystemBinDir("/usr/bin"));
    try std.testing.expectEqual(!on_macos, isSystemBinDir("/usr/bin/"));
    try std.testing.expectEqual(!on_macos, isSystemBinDir("/usr/local/bin"));

    // Never a system bin dir on any platform.
    try std.testing.expect(!isSystemBinDir("/home/tester/.local/bin"));
    try std.testing.expect(!isSystemBinDir("/opt/hkm-kernel/bin"));
}

test "an unstamped kernel says so rather than claiming to be unknown" {
    try std.testing.expectEqualStrings("1.3.1", versionLabel("1.3.1"));
    try std.testing.expectEqualStrings("unstamped", versionLabel(null));
}

test "an unresolvable user scope never resolves to the system paths" {
    // With no HOME and no HKM_PREFIX there is no user install to speak of. The
    // dangerous outcome is not "no result" but a SILENT fallback to /opt: `hkm
    // upgrade` would then write system-wide on behalf of a command whose whole
    // purpose is to avoid that.
    var arena = std.heap.ArenaAllocator.init(std.testing.allocator);
    defer arena.deinit();
    const a = arena.allocator();

    var threaded: std.Io.Threaded = .init(std.testing.allocator, .{});
    defer threaded.deinit();

    var env = std.process.Environ.Map.init(a);
    defer env.deinit();

    // The invariant is platform-independent; only the spelling of the system
    // root changes (/opt/hkm-kernel vs /Applications/HKM.app/…).
    const sys_root: []const u8 = if (bundles_apps) "/" ++ mac_app_tail else system_root;

    const user = detect(a, threaded.io(), &env, .user);
    try std.testing.expect(!user.resolved);
    try std.testing.expect(!std.mem.eql(u8, user.root, sys_root));
    try std.testing.expect(!user.present);

    // The system scope is always resolvable — its paths are constants.
    const system = detect(a, threaded.io(), &env, .system);
    try std.testing.expect(system.resolved);
    try std.testing.expectEqualStrings(sys_root, system.root);
}

test "each platform's scopes are the layout its installer actually writes" {
    // The pairing is the point: a home-directory install and a machine-wide one
    // that mirror each other. Naming the Linux pair on macOS is what made
    // `hkm doctor` report two absent installs beside a working bundle.
    var arena = std.heap.ArenaAllocator.init(std.testing.allocator);
    defer arena.deinit();
    const a = arena.allocator();

    var env = std.process.Environ.Map.init(a);
    defer env.deinit();
    try env.put("HOME", "/home/tester");

    if (bundles_apps) {
        try std.testing.expectEqualStrings(
            "/Applications/HKM.app/Contents/Resources/opt/hkm-kernel",
            macAppRoot(a, &env, .system).?,
        );
        try std.testing.expectEqualStrings(
            "/home/tester/Applications/HKM.app/Contents/Resources/opt/hkm-kernel",
            macAppRoot(a, &env, .user).?,
        );
        // The launcher is the binary INSIDE the bundle, not the wrapper on PATH.
        try std.testing.expectEqualStrings(
            "/Applications/HKM.app/Contents/MacOS",
            macAppBinDir(a, macAppRoot(a, &env, .system)).?,
        );
    } else {
        try std.testing.expectEqualStrings("/opt/hkm-kernel", system_root);
        try std.testing.expectEqualStrings("/home/tester/.local/lib/hkm-kernel", userRoot(a, &env).?);
    }
}
