//! `hkm upgrade` — check for and apply kernel updates, per INSTALL SCOPE.
//!
//!   hkm upgrade            # update the install this user owns (~/.local) — no root
//!   sudo hkm upgrade       # update the system install (/opt + /usr/bin)
//!   hkm upgrade --check    # compare each scope's kernel to the latest release
//!   hkm upgrade --local    # install THIS checkout over an installed kernel
//!
//! WHY THE SCOPE SPLIT EXISTS
//! --------------------------
//! Linux publishes TWO artifacts and the tarball is the primary one (see
//! tools/bundle.sh): a user-local tarball that needs no root, and a .deb for
//! multi-user machines. `hkm upgrade` only ever fetched the .deb and shelled
//! out to `sudo apt-get`, so:
//!
//!   • a user install could not update itself at all — the command "succeeded",
//!     updated /opt, and left ~/.local/bin/hkm exactly as it was;
//!   • PATH usually resolves ~/.local/bin BEFORE /usr/bin, so the very next
//!     command ran the old launcher and the version had not moved;
//!   • and a non-root user was prompted for a password to update a copy of the
//!     kernel they were not running.
//!
//! So the target is now chosen by privilege, which makes the two forms two
//! predictable commands rather than one command with a machine-dependent
//! target: root → system, otherwise → user. `--system` / `--user` override it.
//!
//! "Latest" is the highest v* tag on the kernel repo, discovered with
//! `git ls-remote` (no API token, works for the public repo).
//!
//! VERSIONS ARE READ FROM THE KERNEL, NOT FROM THIS BINARY. `banner.version()`
//! is stamped into the launcher at compile time, so comparing it to the latest
//! tag answered "is this BINARY current" while the command went on to replace a
//! KERNEL somewhere else entirely. With two scopes present those two are
//! routinely different numbers.

const std = @import("std");
const banner = @import("../lib/banner.zig");
const composer_version = @import("../lib/composer_version.zig");
const install_scope = @import("../lib/install_scope.zig");
const kernel = @import("../lib/kernel.zig");
const run_cmd = @import("run.zig");
const util = @import("../lib/util.zig");
const userconfig = @import("../lib/userconfig.zig");
const semver = @import("../lib/semver.zig");
const prompt = @import("../lib/prompt.zig");

const Dir = std.Io.Dir;
const Io = std.Io;
const EnvMap = std.process.Environ.Map;
const Scope = install_scope.Scope;

/// Version handling comes from lib/semver.zig rather than a local copy.
///
/// The local `Ver` this replaces STRIPPED any pre-release suffix before
/// comparing, so "v1.1.0-dev.1" was indistinguishable from a stable "v1.1.0"
/// and sorted above "v1.0.21" — meaning publishing a single dev tag would have
/// offered, and installed, that dev build to every stable user running
/// `hkm upgrade`. semver.Version sorts a pre-release BELOW its release, which
/// is what makes a dev tag safe to publish at all.
const Ver = semver.Version;

/// Parse a tag or stamped version, tolerating the `git describe` trailer that
/// tools/bundle.sh produces for builds between releases.
fn parseVer(s_: []const u8) Ver {
    return semver.parseDescribed(s_) orelse .{};
}

const Latest = union(enum) {
    tag: []const u8, // highest matching tag found
    none, // repo reachable but has no release tags yet
    unreachable_, // git failed / offline
};

/// Query the remote repo for the highest v* tag.
///
/// PRE-RELEASE TAGS ARE SKIPPED unless `include_pre` is set. A dev or rc tag is
/// published precisely so people can opt IN to it; treating it as the latest
/// release would push unfinished work to everyone who runs `hkm upgrade`, which
/// is the opposite of what publishing a pre-release is for.
fn latestTag(allocator: std.mem.Allocator, io: Io, env: *EnvMap, include_pre: bool) Latest {
    const url = std.fmt.allocPrint(allocator, "https://github.com/{s}.git", .{banner.repo()}) catch return .unreachable_;
    const res = std.process.run(allocator, io, .{
        .argv = &.{ "git", "ls-remote", "--tags", "--refs", url },
        .environ_map = env,
    }) catch return .unreachable_;
    switch (res.term) {
        .exited => |c| if (c != 0) return .unreachable_,
        else => return .unreachable_,
    }

    var best: ?[]const u8 = null;
    var best_ver: Ver = .{};
    var lines = std.mem.splitScalar(u8, res.stdout, '\n');
    while (lines.next()) |line| {
        const marker = "refs/tags/";
        const idx = std.mem.indexOf(u8, line, marker) orelse continue;
        const tag = std.mem.trim(u8, line[idx + marker.len ..], " \t\r");
        if (tag.len == 0) continue;
        const v = semver.Version.parse(tag) orelse continue; // skip non-semver tags
        if (v.pre.len > 0 and !include_pre) continue;
        if (best == null or v.order(best_ver) == .gt) {
            best = allocator.dupe(u8, tag) catch continue;
            best_ver = v;
        }
    }
    return if (best) |b| .{ .tag = b } else .none;
}

/// The file set a release ships, mirroring SRC_PATHS in tools/bundle.sh.
///
/// Kept in step with that script deliberately: a local install that copied a
/// DIFFERENT set than a packaged release would produce an installed kernel that
/// behaves unlike anything a user could get from a .deb, which is the opposite
/// of what testing a local build is for.
const shipped_paths = [_][]const u8{
    "src",           "plugins",       "projects", "templates",
    "composer.json", "composer.lock", "bin/psp",  "README.md",
    "LICENSE",       "modules",
};

pub fn run(allocator: std.mem.Allocator, io: Io, env: *EnvMap, args: []const []const u8) !u8 {
    var check_only = false;
    var from_local = false;
    var dry_run = false;
    var assume_yes = false;
    // Opt IN to dev / rc releases. Off by default: a pre-release must never
    // reach someone who did not ask for one.
    var include_pre = false;
    // --local builds the checkout before copying it. Without this the tools/
    // binaries in zig-out could be older than the source being installed, so
    // "install my local changes" would ship a launcher that predates them —
    // the one failure mode a local test install must not have.
    var build_first = true;
    // Which install to act on. Null = decide from privilege (root → system,
    // otherwise → user), which is what makes `sudo hkm upgrade` and plain
    // `hkm upgrade` two different, predictable commands.
    var scope: ?Scope = null;

    // Same reasoning as `uninstall`: an ignored typo here silently changes WHICH
    // install is replaced. `--systm` would fall through to the privilege default
    // and upgrade the user's install while the operator believed they had named
    // the system one.
    const known = [_][]const u8{
        "--check",  "-c", "--local",  "-l", "--dry-run", "-n",
        "--yes",    "-y", "--pre",    "--user", "-u", "--system",
        "-s",       "--no-build", "--help", "-h",
    };
    if (util.unknownFlag(args[1..], &known)) |bad| {
        prompt.err(std.fmt.allocPrint(allocator, "unknown option: {s}", .{bad}) catch "unknown option");
        prompt.muted("  nothing was changed. Run `hkm upgrade --help` for the accepted flags.");
        return 1;
    }

    for (args[1..]) |a| {
        if (std.mem.eql(u8, a, "--check") or std.mem.eql(u8, a, "-c")) check_only = true;
        if (std.mem.eql(u8, a, "--local") or std.mem.eql(u8, a, "-l")) from_local = true;
        if (std.mem.eql(u8, a, "--dry-run") or std.mem.eql(u8, a, "-n")) dry_run = true;
        if (std.mem.eql(u8, a, "--yes") or std.mem.eql(u8, a, "-y")) assume_yes = true;
        if (std.mem.eql(u8, a, "--pre")) include_pre = true;
        if (std.mem.eql(u8, a, "--user") or std.mem.eql(u8, a, "-u")) scope = .user;
        if (std.mem.eql(u8, a, "--system") or std.mem.eql(u8, a, "-s")) scope = .system;
        if (std.mem.eql(u8, a, "--no-build")) build_first = false;
        if (std.mem.eql(u8, a, "--help") or std.mem.eql(u8, a, "-h")) {
            printHelp();
            return 0;
        }
    }

    const target = scope orelse install_scope.defaultScope(env);

    banner.print(allocator, io, env);

    // A system upgrade that is not root cannot write /opt, and every step after
    // this point would fail one at a time with a permission error. Say it once,
    // at the top, with the command that works.
    if (target == .system and !install_scope.isRoot(env)) {
        prompt.warn("a system upgrade needs root — re-run it as:  sudo hkm upgrade --system");
        prompt.muted("  (or drop --system to update your own user install, which needs no root)");
        return 1;
    }

    if (from_local) return localUpgrade(allocator, io, env, target, dry_run, assume_yes, build_first);

    const inst = install_scope.detect(allocator, io, env, target);
    if (!inst.resolved) {
        prompt.err("cannot locate a user install directory (no HOME and no HKM_PREFIX).");
        prompt.muted("  set one:  HKM_PREFIX=/srv/hkm hkm upgrade --user");
        return 1;
    }

    prompt.section("Target");
    prompt.item("scope", target.how());
    prompt.item("kernel", inst.root);
    prompt.item("installed", if (inst.present) install_scope.versionLabel(inst.version) else "not installed");

    prompt.muted("checking for updates…");
    const latest = switch (latestTag(allocator, io, env, include_pre)) {
        .tag => |t| t,
        .none => {
            prompt.item("repo", banner.repo());
            prompt.ok("no releases published yet — nothing to update to.");
            return 0;
        },
        .unreachable_ => {
            prompt.warn("could not reach the update server (offline or git missing).");
            prompt.item("repo", banner.repo());
            return 1;
        },
    };
    const latest_ver = parseVer(latest);
    prompt.item("latest", latest);

    // The version of the KERNEL BEING REPLACED, not of this binary. Those are
    // different numbers whenever the launcher on PATH belongs to the other
    // scope — which is the state that made upgrades look like no-ops.
    const current = if (inst.present and inst.version != null)
        parseVer(inst.version.?)
    else
        Ver{}; // absent or unstamped → treat as older than anything, so it installs

    if (!inst.present) {
        prompt.warn("nothing installed in this scope yet — this will be a fresh install.");
    } else if (inst.version == null) {
        // A `--local` install carries the checkout's composer.json, which is
        // deliberately unstamped. There is nothing to compare, so proceed
        // rather than refuse.
        prompt.warn("the installed kernel carries no version — installing the latest release over it.");
    } else switch (current.order(latest_ver)) {
        .eq => {
            prompt.ok("this scope is on the latest version.");
            try reportOtherScope(allocator, io, env, target, latest_ver);
            return 0;
        },
        .gt => {
            prompt.ok("this scope is newer than the latest release (dev build).");
            try reportOtherScope(allocator, io, env, target, latest_ver);
            return 0;
        },
        .lt => prompt.warn("an update is available."),
    }

    if (check_only) {
        prompt.item("to update", if (target == .system) "run: sudo hkm upgrade --system" else "run: hkm upgrade");
        try reportOtherScope(allocator, io, env, target, latest_ver);
        return 0;
    }

    // A git checkout is updated with git, not by unpacking a release over it.
    const git_dir = try std.fs.path.join(allocator, &.{ inst.root, ".git" });
    if (inst.present and util.fileExists(io, git_dir)) {
        prompt.section("Updating (git)");
        var pull = [_][]const u8{ "git", "-C", inst.root, "pull", "--ff-only", "--tags" };
        _ = run_cmd.spawnWait(io, env, &pull) catch {};
        const installer = try std.fs.path.join(allocator, &.{ inst.root, "install.sh" });
        if (util.fileExists(io, installer)) {
            var sh = [_][]const u8{ "sh", installer };
            _ = run_cmd.spawnWait(io, env, &sh) catch {};
        }
        prompt.ok("kernel updated. Verify with: hkm doctor");
        try reportOtherScope(allocator, io, env, target, latest_ver);
        return 0;
    }

    const code = try performPackagedUpgrade(allocator, io, env, target, latest);

    // Say what was NOT updated, right after saying what was. This is the exact
    // moment the old behaviour misled: the command reported success, and the
    // very next `hkm` ran the other scope's launcher at the old version with
    // nothing on screen connecting the two.
    if (code == 0) try reportOtherScope(allocator, io, env, target, latest_ver);
    return code;
}

/// Mention the OTHER scope when it is also installed and also behind.
///
/// Without this, "you are on the latest version" is true of the scope that was
/// checked and false of the one the user's PATH actually runs — which is the
/// precise shape of "I upgraded and the version did not change".
fn reportOtherScope(allocator: std.mem.Allocator, io: Io, env: *EnvMap, target: Scope, latest: Ver) !void {
    const other: Scope = if (target == .system) .user else .system;
    const inst = install_scope.detect(allocator, io, env, other);
    if (!inst.resolved or !inst.present) return;

    const v = inst.version orelse {
        prompt.blank();
        prompt.muted(try std.fmt.allocPrint(
            allocator,
            "note: a {s} install also exists at {s} (unstamped version).",
            .{ other.label(), inst.root },
        ));
        return;
    };

    if (parseVer(v).order(latest) != .lt) return;

    prompt.blank();
    prompt.warn(try std.fmt.allocPrint(
        allocator,
        "the {s} install is still on {s} and was NOT touched.",
        .{ other.label(), v },
    ));
    prompt.item("kernel", inst.root);
    prompt.item("update it", if (other == .system) "sudo hkm upgrade --system" else "hkm upgrade --user");
}

/// Download the release artifact for THIS OS + scope and install it. The binary
/// is built per-OS, so builtin.os.tag / cpu.arch are comptime — only this
/// platform's path is compiled in.
fn performPackagedUpgrade(
    allocator: std.mem.Allocator,
    io: Io,
    env: *EnvMap,
    target: Scope,
    latest: []const u8,
) !u8 {
    const os = @import("builtin").os.tag;
    const ver = composer_version.normalize(latest); // "1.0.1"

    if (os == .linux) return linuxUpgrade(allocator, io, env, target, latest, ver);

    const asset: []const u8 = switch (os) {
        .macos => try std.fmt.allocPrint(allocator, "hkm-kernel-{s}-macos-universal.tar.gz", .{ver}),
        .windows => try std.fmt.allocPrint(allocator, "hkm-kernel-{s}-windows-x86_64.zip", .{ver}),
        else => return errUnsupported(),
    };

    const url = try assetUrl(allocator, latest, asset);
    const tmp = try std.fs.path.join(allocator, &.{ "/tmp", asset });

    prompt.section("Downloading update");
    prompt.item("asset", asset);
    prompt.item("from", url);
    if (!download(io, env, url, tmp)) {
        prompt.err("download failed — check your connection and try again.");
        return 1;
    }

    prompt.section("Installing");
    switch (os) {
        .macos => {
            // Replace the kernel resources in place, then re-resolve composer.
            const root = (try kernel.resolveHome(allocator, io, env)) orelse
                "/Applications/HKM.app/Contents/Resources/opt/hkm-kernel";
            const app_root = std.fs.path.dirname(std.fs.path.dirname(std.fs.path.dirname(root) orelse root) orelse root) orelse root;
            var untar = [_][]const u8{ "tar", "-xzf", tmp, "-C", app_root, "--strip-components=0" };
            if ((run_cmd.spawnWait(io, env, &untar) catch 1) != 0) {
                prompt.err("could not unpack the release — the previous kernel is still in place.");
                prompt.muted(try std.fmt.allocPrint(allocator, "  the archive is at {s}", .{tmp}));
                return 1;
            }
            const installer = try std.fs.path.join(allocator, &.{ root, "install.sh" });
            if (util.fileExists(io, installer)) {
                var sh = [_][]const u8{ "sh", installer };
                if ((run_cmd.spawnWait(io, env, &sh) catch 1) != 0) {
                    prompt.err("unpacked, but install.sh failed — the install may be half-updated.");
                    prompt.muted("  re-run it by hand, then check: hkm doctor");
                    return 1;
                }
            }
        },
        .windows => {
            prompt.warn("downloaded — extract the zip and run install.bat to finish (Windows self-replace is unsafe while running).");
            prompt.item("saved to", tmp);
            return 0;
        },
        else => return errUnsupported(),
    }

    prompt.blank();
    prompt.ok("updated. Verify with: hkm version");
    return 0;
}

/// Linux publishes one artifact per scope; pick the one that matches.
fn linuxUpgrade(
    allocator: std.mem.Allocator,
    io: Io,
    env: *EnvMap,
    target: Scope,
    tag: []const u8,
    ver: []const u8,
) !u8 {
    const arch = switch (@import("builtin").cpu.arch) {
        .x86_64 => "x86_64",
        .aarch64 => "aarch64",
        else => "",
    };

    return switch (target) {
        // ── user: the portable tarball + its own installer. No root anywhere. ──
        .user => blk: {
            if (arch.len == 0) {
                prompt.err("no user-local tarball is published for this architecture.");
                break :blk 1;
            }
            const asset = try std.fmt.allocPrint(allocator, "hkm-kernel-{s}-linux-{s}.tar.gz", .{ ver, arch });
            const url = try assetUrl(allocator, tag, asset);
            const tmp = try std.fs.path.join(allocator, &.{ "/tmp", asset });

            prompt.section("Downloading update");
            prompt.item("asset", asset);
            prompt.item("from", url);
            if (!download(io, env, url, tmp)) {
                prompt.err("download failed — check your connection and try again.");
                break :blk 1;
            }

            // The tarball carries the user installer at its top level. Running
            // it — rather than reimplementing the copy here — is what keeps the
            // upgrade identical to a first install: it preserves the project
            // registry, swaps the tree atomically, resolves composer against
            // THIS machine's PHP, and repairs a stale config pin.
            // Unpack into a directory cleared first. A leftover tree from an
            // interrupted run could otherwise supply an install.sh from a
            // different build than the archive just downloaded.
            const work = try std.fmt.allocPrint(allocator, "/tmp/hkm-upgrade-{d}", .{std.Thread.getCurrentId()});
            var rm = [_][]const u8{ "rm", "-rf", work };
            _ = run_cmd.spawnWait(io, env, &rm) catch {};
            Dir.cwd().createDirPath(io, work) catch {};
            var untar = [_][]const u8{ "tar", "-xzf", tmp, "-C", work };
            if ((run_cmd.spawnWait(io, env, &untar) catch 1) != 0) {
                prompt.err("could not unpack the release — the previous kernel is still in place.");
                prompt.muted(try std.fmt.allocPrint(allocator, "  the archive is at {s}", .{tmp}));
                break :blk 1;
            }

            const installer = try findInstaller(allocator, io, work, ver, arch);
            if (installer == null) {
                prompt.err("the archive has no install.sh at its top level — cannot continue.");
                prompt.muted(try std.fmt.allocPrint(allocator, "  unpacked at {s}", .{work}));
                break :blk 1;
            }

            prompt.section("Installing (user-local, no root)");
            var sh = [_][]const u8{ "sh", installer.?, tmp };
            const code = run_cmd.spawnWait(io, env, &sh) catch 1;
            if (code != 0) {
                prompt.err("the installer reported a failure — check the output above.");
                break :blk 1;
            }

            prompt.blank();
            prompt.ok("user install updated. Verify with: hkm version");
            break :blk 0;
        },

        // ── system: the .deb, via apt so its dependencies resolve. ────────────
        .system => blk: {
            if (@import("builtin").cpu.arch != .x86_64) {
                prompt.err("only an amd64 .deb is published; your architecture has no prebuilt package.");
                prompt.muted("  the user-local tarball has no such limit:  hkm upgrade --user");
                break :blk 1;
            }
            const asset = try std.fmt.allocPrint(allocator, "hkm-kernel_{s}_amd64.deb", .{ver});
            const url = try assetUrl(allocator, tag, asset);
            const tmp = try std.fs.path.join(allocator, &.{ "/tmp", asset });

            prompt.section("Downloading update");
            prompt.item("asset", asset);
            prompt.item("from", url);
            if (!download(io, env, url, tmp)) {
                prompt.err("download failed — check your connection and try again.");
                break :blk 1;
            }

            prompt.section("Installing (system-wide)");
            // Already root by the time we get here (run() refuses otherwise),
            // so call apt directly. Prefixing `sudo` unconditionally broke on
            // the machines where a system install is most useful — containers
            // and CI images run as root and frequently ship no sudo at all.
            var argv = [_][]const u8{ "apt-get", "install", "-y", tmp };
            const code = run_cmd.spawnWait(io, env, &argv) catch 1;
            if (code != 0) {
                // Fallback: dpkg, then repair dependencies, then dpkg AGAIN —
                // and the verdict is that SECOND dpkg, never the repair.
                //
                // `apt-get -f install -y` exits 0 when it finds nothing to
                // repair. So when `dpkg -i` failed for a reason that is not a
                // missing dependency — a truncated download, a corrupt .deb —
                // the repair returned 0 and the old condition
                // (`dpkg_code != 0 and fix_code != 0`) was false. The command
                // then printed "updated" with the previous kernel still
                // installed: the exact outcome this block exists to prevent,
                // and the same "upgrade did nothing" the rest of this release
                // is about. Only a dpkg that succeeds proves the package landed.
                var dpkg = [_][]const u8{ "dpkg", "-i", tmp };
                var verdict = run_cmd.spawnWait(io, env, &dpkg) catch 1;
                if (verdict != 0) {
                    var fix = [_][]const u8{ "apt-get", "-f", "install", "-y" };
                    _ = run_cmd.spawnWait(io, env, &fix) catch {};
                    verdict = run_cmd.spawnWait(io, env, &dpkg) catch 1;
                }
                if (verdict != 0) {
                    prompt.err("installation FAILED — the previous kernel is still in place.");
                    prompt.muted(try std.fmt.allocPrint(allocator, "  the package is downloaded at {s}", .{tmp}));
                    prompt.muted("  try it by hand:  sudo apt-get install -y <path>");
                    break :blk 1;
                }
            }

            prompt.blank();
            prompt.ok("system install updated. Verify with: hkm version");
            break :blk 0;
        },
    };
}

/// `<work>/hkm-kernel-<ver>-linux-<arch>/install.sh`, verified to exist.
///
/// The archive's top-level directory name is fixed by bundle.sh, so it is
/// derived rather than discovered — a directory listing would need a readdir
/// whose API differs across the toolchains this has to build on.
fn findInstaller(allocator: std.mem.Allocator, io: Io, work: []const u8, ver: []const u8, arch: []const u8) !?[]const u8 {
    const top = try std.fmt.allocPrint(allocator, "hkm-kernel-{s}-linux-{s}", .{ ver, arch });
    const path = try std.fs.path.join(allocator, &.{ work, top, "install.sh" });
    return if (util.fileExists(io, path)) path else null;
}

fn assetUrl(allocator: std.mem.Allocator, tag: []const u8, asset: []const u8) ![]const u8 {
    return std.fmt.allocPrint(
        allocator,
        "https://github.com/{s}/releases/download/{s}/{s}",
        .{ banner.repo(), tag, asset },
    );
}

fn errUnsupported() u8 {
    prompt.err("automatic upgrade is not supported on this platform — download from the releases page.");
    return 1;
}

/// Download url → dest with curl (fallback wget), stdio inherited for a progress bar.
fn download(io: Io, env: *EnvMap, url: []const u8, dest: []const u8) bool {
    var curl = [_][]const u8{ "curl", "-fSL", "--progress-bar", "-o", dest, url };
    if ((run_cmd.spawnWait(io, env, &curl) catch 1) == 0) return true;
    var wget = [_][]const u8{ "wget", "-O", dest, url };
    return (run_cmd.spawnWait(io, env, &wget) catch 1) == 0;
}

fn printHelp() void {
    prompt.intro("hkm upgrade");
    prompt.section("Usage");
    prompt.item("hkm upgrade", "update YOUR install (~/.local) from the latest release — no root");
    prompt.item("sudo hkm upgrade", "update the SYSTEM install (/opt + /usr/bin)");
    prompt.item("hkm upgrade --check", "report what each scope is on, install nothing");
    prompt.item("hkm upgrade --local", "install THIS checkout over an installed kernel");
    prompt.blank();
    prompt.section("Scope");
    prompt.muted("chosen from privilege unless you say otherwise: root → system, else → user");
    prompt.item("--user, -u", "act on ~/.local/lib/hkm-kernel + ~/.local/bin (never needs root)");
    prompt.item("--system, -s", "act on /opt/hkm-kernel + /usr/bin (needs root)");
    prompt.blank();
    prompt.section("Options");
    prompt.item("--local, -l", "source the update from the local checkout instead of GitHub");
    prompt.item("--dry-run, -n", "show what --local would copy, write nothing");
    prompt.item("--yes, -y", "skip the confirmation prompt");
    prompt.item("--no-build", "with --local: skip `zig build`, install what is in tools/zig-out");
    prompt.item("--pre", "consider pre-releases (dev / rc) when checking for updates");
    prompt.item("--check, -c", "check only");
    prompt.item("--help, -h", "show this help");
    prompt.outro("`hkm version` shows both scopes and which one your PATH actually runs");
}

/// `hkm upgrade --local` — install the LOCAL checkout over an INSTALLED kernel.
///
/// The normal upgrade path fetches a published release. This one exists for the
/// case that path cannot serve: you have changed the kernel and want an
/// installed copy — the one projects on this machine actually run — to be that
/// change, without tagging a release first.
///
/// It copies the same file set a release ships (shipped_paths, mirroring
/// bundle.sh), so the result behaves like a real install rather than a
/// half-synced hybrid.
fn localUpgrade(
    allocator: std.mem.Allocator,
    io: Io,
    env: *EnvMap,
    target: Scope,
    dry_run: bool,
    assume_yes: bool,
    build_first: bool,
) !u8 {
    // SOURCE: the checkout this command is being run from or pointed at.
    const src = (try resolveSource(allocator, io, env)) orelse {
        prompt.err("no local kernel checkout found.");
        prompt.muted("  set one:  hkm-config set HKM_DEV_HOME /path/to/the/checkout");
        prompt.muted("  or run this from inside it.");
        return 1;
    };

    const inst = install_scope.detect(allocator, io, env, target);
    if (!inst.resolved) {
        prompt.err("cannot locate a user install directory (no HOME and no HKM_PREFIX).");
        prompt.muted("  set one:  HKM_PREFIX=/srv/hkm hkm upgrade --local --user");
        return 1;
    }
    const dest = inst.root;

    // The user scope may not exist yet — creating it is the correct outcome of
    // "install my checkout for me", and refusing would leave a non-root user
    // with no way to get a kernel at all.
    if (target == .user) Dir.cwd().createDirPath(io, dest) catch {};

    // Copying a checkout over itself would delete files mid-walk and leave the
    // only copy of the kernel in an unknown state.
    if (std.mem.eql(u8, util.trimSlash(src), util.trimSlash(dest))) {
        prompt.err("the local checkout IS the installed kernel — there is nothing to copy.");
        prompt.muted(src);
        return 1;
    }

    if (!kernel.isKernelDir(io, src)) {
        prompt.err(try std.fmt.allocPrint(allocator, "{s} is not a kernel checkout (no composer.json).", .{src}));
        return 1;
    }

    const version = localVersion(allocator, io, env, src) orelse "unknown";

    prompt.section("Local upgrade");
    prompt.item("source", src);
    prompt.item("target", dest);
    prompt.item("scope", target.how());
    prompt.item("version", version);
    prompt.item("installed", if (inst.present) install_scope.versionLabel(inst.version) else "not installed");

    if (dry_run) {
        prompt.blank();
        prompt.section("Would copy");
        for (shipped_paths) |p| prompt.muted(p);
        prompt.outro("Dry run — nothing was written");
        return 0;
    }

    // Overwriting a kernel that projects on this machine run is not something
    // to do on a typo.
    if (!assume_yes and !prompt.confirm(io, "Overwrite the installed kernel with this checkout?", false)) {
        prompt.muted("cancelled");
        return 1;
    }

    // Build the checkout first, so the launcher that gets installed is the one
    // built from the source being installed.
    if (build_first) buildCheckout(allocator, io, env, src);

    // Untracked files under the installed paths are NOT copied — see below —
    // so say which ones, before the install silently omits them.
    warnUntracked(allocator, io, env, src);

    // git ls-files gives exactly the TRACKED files, so build artifacts, vendor/
    // and local scratch never leak into the install — the same guarantee
    // bundle.sh relies on.
    const listing = std.process.run(allocator, io, .{
        .argv = &.{ "git", "-C", src, "ls-files", "--recurse-submodules", "--", "src", "plugins", "projects", "templates", "composer.json", "composer.lock", "bin/psp", "README.md", "LICENSE", "modules" },
        .environ_map = env,
    }) catch {
        prompt.err("could not list the checkout's tracked files (is git installed?).");
        return 1;
    };
    switch (listing.term) {
        .exited => |c| if (c != 0) {
            prompt.err("git ls-files failed in the local checkout.");
            return 1;
        },
        else => return 1,
    }

    const needs_root = !util.canWrite(io, dest);
    if (needs_root) prompt.muted("target is not writable — using sudo");

    var copied: usize = 0;
    var skipped: usize = 0; // tracked by git, absent from the working tree
    var failed: usize = 0;
    var lines = std.mem.splitScalar(u8, listing.stdout, '\n');
    while (lines.next()) |raw| {
        const rel = std.mem.trim(u8, raw, " \t\r");
        if (rel.len == 0) continue;

        const from = try std.fs.path.join(allocator, &.{ src, rel });
        // bin/psp is installed under the name the launcher's passthrough
        // expects; bundle.sh does the same rename.
        const rel_dest = if (std.mem.eql(u8, rel, "bin/psp")) "bin/hkm" else rel;
        const to = try std.fs.path.join(allocator, &.{ dest, rel_dest });

        copyOne(allocator, io, env, from, to, needs_root) catch |e| {
            // A file git tracks but the working tree no longer has is NOT a
            // write failure — nothing was lost, because there was nothing to
            // copy. Treating it as one aborted the install before the launcher
            // was replaced, so a checkout with one uncommitted deletion could
            // never update its own `hkm` binary: every upgrade errored, the
            // stale launcher stayed, and the cause looked unrelated.
            if (e == error.FileNotFound) {
                skipped += 1;
                if (skipped <= 10) {
                    prompt.muted(try std.fmt.allocPrint(
                        allocator,
                        "  skipped {s} — tracked by git, missing from the working tree",
                        .{rel_dest},
                    ));
                }
                continue;
            }
            failed += 1;
            // Every failure, with its cause — capped so a systemic problem does
            // not bury the summary. Reporting only the first meant "7 file(s)
            // could not be written" alongside ONE filename, leaving the reader
            // to guess whether the other six shared that cause.
            if (failed <= 10) {
                prompt.err(try std.fmt.allocPrint(allocator, "{s}: {t}", .{ rel_dest, e }));
            } else if (failed == 11) {
                prompt.muted("  (further failures not listed)");
            }
            continue;
        };
        copied += 1;
        // bin/hkm is the PHP CLI the launcher hands off to — it must stay
        // executable for the same reason.
        if (std.mem.eql(u8, rel_dest, "bin/hkm")) util.chmodExec(io, to);
    }

    prompt.ok(try std.fmt.allocPrint(allocator, "copied {d} file(s)", .{copied}));

    if (skipped > 0) {
        // Worth saying, not worth failing over: the installed kernel matches
        // the working tree, which is what --local promises.
        prompt.warn(try std.fmt.allocPrint(
            allocator,
            "{d} file(s) are tracked by git but deleted locally — not installed.",
            .{skipped},
        ));
        prompt.muted("    git status --short | grep '^ D'      # see them");
        prompt.muted("    git checkout -- <path>               # restore, or commit the deletion");
    }

    if (failed > 0) {
        prompt.err(try std.fmt.allocPrint(allocator, "{d} file(s) could not be written — the install may be inconsistent.", .{failed}));
        return 1;
    }

    // Record what was installed, so the result can report its own version.
    //
    // The checkout's composer.json has NO version field — build.zig only stamps
    // a release build, deliberately. Copying it verbatim therefore produced an
    // installed kernel that could never say what it was: `hkm version` read
    // "unstamped" forever and `hkm upgrade` had nothing to compare, which is a
    // large part of why a local install looked like it "did not upgrade".
    if (composer_version.writeTo(allocator, io, dest, version)) {
        // Report what actually landed in the file. A `git describe` version is
        // re-spelled as build metadata to satisfy Composer, so echoing the
        // input would name a string the installed kernel does not carry — and
        // the next `hkm version` would appear to contradict this line.
        prompt.item("stamped", composer_version.ofKernel(allocator, io, dest) orelse version);
    } else {
        prompt.muted("  could not record the version in the installed composer.json");
    }

    // The native launcher is built, not tracked, so it is copied separately —
    // and only when it exists, since a checkout that has never run `zig build`
    // has nothing to install.
    installLauncher(allocator, io, env, src, target, needs_root);

    // vendor/ is deliberately not shipped, so dependencies are resolved against
    // the TARGET's PHP rather than whatever the checkout happened to resolve.
    prompt.section("Resolving PHP dependencies");
    const installer = try std.fs.path.join(allocator, &.{ dest, "install.sh" });
    if (util.fileExists(io, installer)) {
        var sh = [_][]const u8{ "sh", installer };
        var sudo_sh = [_][]const u8{ "sudo", "sh", installer };
        const code = run_cmd.spawnWait(io, env, if (needs_root) &sudo_sh else &sh) catch 1;
        if (code != 0) prompt.warn("install.sh reported an error — run it manually in the target to finish.");
    } else {
        // A --local copy carries only tracked files, and install.sh is written
        // by bundle.sh at package time — so it is absent here. Run composer
        // directly, or the target has no vendor/ and cannot boot.
        // --no-scripts: the target is an INSTALLED kernel, never a git
        // checkout, and the only scripts this package defines set up developer
        // git hooks. Running them there printed "fatal: not in a git directory"
        // on every install; guarding the script itself silenced the error but
        // left composer echoing a long command line instead. Skipping scripts
        // for a destination that cannot use them removes both, and leaves the
        // script simple for the checkout where it does apply.
        var composer = [_][]const u8{ "composer", "install", "--no-dev", "--optimize-autoloader", "--no-interaction", "--no-scripts", "--working-dir", dest };
        var sudo_composer = [_][]const u8{ "sudo", "composer", "install", "--no-dev", "--optimize-autoloader", "--no-interaction", "--no-scripts", "--working-dir", dest };
        const ccode = run_cmd.spawnWait(io, env, if (needs_root) &sudo_composer else &composer) catch 1;
        if (ccode != 0) {
            prompt.warn("composer install failed — the kernel has no vendor/ and cannot boot.");
            // Name the usual cause. A bare "composer install failed" sends
            // people to their network or their PHP version, when in practice it
            // is almost always a cache left root-owned by an earlier
            // `sudo composer` — the error surfaces as "Permission denied" on a
            // .zip deep inside ~/.cache/composer.
            prompt.muted("  if it said 'Permission denied' under ~/.cache/composer, the cache is root-owned:");
            prompt.muted("    sudo chown -R \"$USER\" ~/.cache/composer");
            prompt.muted(try std.fmt.allocPrint(
                allocator,
                "  then re-run:  composer install --no-dev --working-dir {s}",
                .{dest},
            ));
        }
    }

    try clearStalePin(allocator, io, env, dest);

    prompt.outro("Installed kernel updated from the local checkout. Verify with: hkm version");
    return 0;
}

/// Remove a config.env HKM_KERNEL_HOME pin that this install makes redundant.
///
/// A user install at ~/.local/lib/hkm-kernel is self-located by
/// ~/.local/bin/hkm, so no pin is needed to reach it. Writing one anyway is what
/// created the original fault: config.env is read by BOTH launchers, so the pin
/// a user-level install left behind also redirected /usr/bin/hkm to the user's
/// kernel. Deleting it hands resolution back to self-location, where each
/// launcher finds its own install.
///
/// A pin pointing somewhere ELSE is left alone — that is an operator's
/// deliberate choice about a custom layout, and silently discarding it would be
/// its own surprise. It is reported instead.
fn clearStalePin(allocator: std.mem.Allocator, io: Io, env: *EnvMap, dest: []const u8) !void {
    const pinned = (userconfig.get(allocator, io, env, "HKM_KERNEL_HOME") catch null) orelse return;
    const p = util.trimSlash(std.mem.trim(u8, pinned, " \t\r\n"));
    if (p.len == 0) return;

    if (std.mem.eql(u8, p, util.trimSlash(dest))) {
        if (userconfig.unset(allocator, io, env, "HKM_KERNEL_HOME") catch false) {
            prompt.muted("  removed the now-redundant HKM_KERNEL_HOME pin (the launcher self-locates)");
        }
        return;
    }

    prompt.warn(try std.fmt.allocPrint(
        allocator,
        "config.env still pins HKM_KERNEL_HOME={s}",
        .{p},
    ));
    prompt.muted("  that is only a fallback now, but it will be used if a launcher cannot self-locate.");
    prompt.muted("  clear it with:  hkm-config unset HKM_KERNEL_HOME");
}

/// The checkout to install FROM.
///
/// Order: HKM_DEV_HOME, then the working directory, then the launcher's own
/// location.
///
/// The last of those used to be the only one, via kernel.resolveDevHome — which
/// climbs from the EXECUTABLE's directory. Once the launcher is installed to
/// ~/.local/bin that climb can never reach a checkout, so `hkm upgrade --local`
/// failed for the very user who had just installed it, while HKM_DEV_HOME sat
/// in config.env pointing straight at the answer.
fn resolveSource(allocator: std.mem.Allocator, io: Io, env: *EnvMap) !?[]const u8 {
    if (env.get("HKM_DEV_HOME")) |h| {
        const t = util.trimSlash(std.mem.trim(u8, h, " \t\r\n"));
        if (t.len > 0 and kernel.isKernelDir(io, t)) return try allocator.dupe(u8, t);
    }

    // Walk up from the working directory: running it from anywhere inside the
    // checkout should just work.
    if (env.get("PWD")) |pwd| {
        var cur = util.trimSlash(pwd);
        var depth: usize = 0;
        while (depth < 32 and cur.len > 0) : (depth += 1) {
            if (kernel.isKernelDir(io, cur)) return try allocator.dupe(u8, cur);
            const parent = std.fs.path.dirname(cur) orelse break;
            if (std.mem.eql(u8, parent, cur)) break;
            cur = parent;
        }
    }

    return kernel.resolveDevHome(allocator, io);
}

/// Run `zig build` in the checkout's tools/ before installing it.
///
/// The version passed is `git describe`, so the installed binary reports the
/// exact commit it came from — which is the whole point of a local test
/// install. That string is deliberately NOT composer-valid for a dev checkout
/// ("1.1.0-dev.2-12-g29dccfb"), so the stamper skips composer.json and the
/// working tree stays clean; only the binary carries it.
fn buildCheckout(allocator: std.mem.Allocator, io: Io, env: *EnvMap, src: []const u8) void {
    const tools = std.fs.path.join(allocator, &.{ src, "tools" }) catch return;
    const build_zig = std.fs.path.join(allocator, &.{ tools, "build.zig" }) catch return;
    if (!util.fileExists(io, build_zig)) return; // not a checkout with tools/

    prompt.section("Building");

    const version = localVersion(allocator, io, env, src) orelse "0.0.0-dev";
    const dversion = std.fmt.allocPrint(allocator, "-Dversion={s}", .{version}) catch return;

    var argv = [_][]const u8{ "zig", "build", dversion, "--build-file", build_zig };
    const code = run_cmd.spawnWait(io, env, &argv) catch {
        prompt.warn("zig not found — installing whatever is already in tools/zig-out.");
        return;
    };
    if (code != 0) {
        prompt.warn("build failed — installing whatever is already in tools/zig-out.");
        return;
    }
    prompt.ok(std.fmt.allocPrint(allocator, "built {s}", .{version}) catch "built");
}

/// `git describe` in the checkout, so the source's real version is reported
/// rather than the version this BINARY was stamped with — they differ exactly
/// when a local upgrade is worth doing.
fn localVersion(allocator: std.mem.Allocator, io: Io, env: *EnvMap, src: []const u8) ?[]const u8 {
    const res = std.process.run(allocator, io, .{
        .argv = &.{ "git", "-C", src, "describe", "--tags", "--always" },
        .environ_map = env,
    }) catch return null;
    switch (res.term) {
        .exited => |c| if (c != 0) return null,
        else => return null,
    }
    const t = std.mem.trim(u8, res.stdout, " \t\r\n");
    return if (t.len == 0) null else t;
}

/// Copy one tracked file, creating its parent directory.
fn copyOne(allocator: std.mem.Allocator, io: Io, env: *EnvMap, from: []const u8, to: []const u8, needs_root: bool) !void {
    const parent = std.fs.path.dirname(to) orelse return error.NoParentDir;

    if (needs_root) {
        var mk = [_][]const u8{ "sudo", "mkdir", "-p", parent };
        _ = try run_cmd.spawnWait(io, env, &mk);
        var cp = [_][]const u8{ "sudo", "cp", "-f", from, to };
        if ((try run_cmd.spawnWait(io, env, &cp)) != 0) return error.SudoCopyFailed;
        return;
    }

    Dir.cwd().createDirPath(io, parent) catch |e| switch (e) {
        error.PathAlreadyExists => {},
        else => return e,
    };
    const data = try Dir.cwd().readFileAlloc(io, from, allocator, .limited(64 * 1024 * 1024));
    try Dir.cwd().writeFile(io, .{ .sub_path = to, .data = data });
}

/// Name the untracked files that this install will skip.
///
/// `--local` installs `git ls-files` output, which is the right rule: it is what
/// keeps vendor/, build output and scratch files out of the installed kernel.
/// The cost is that a NEW file — a template variant, a new source file — is
/// invisible to it, and the install silently produces a kernel without it. That
/// failure is near-impossible to read from the outside: the command reports
/// success and the feature simply is not there.
fn warnUntracked(allocator: std.mem.Allocator, io: Io, env: *EnvMap, src: []const u8) void {
    const res = std.process.run(allocator, io, .{
        .argv = &.{
            "git",                 "-C",  src, "ls-files", "--others", "--exclude-standard",
            "--",                  "src", "plugins",       "projects", "templates",
            "composer.json",       "bin", "modules",
        },
        .environ_map = env,
    }) catch return;

    var shown: usize = 0;
    var lines = std.mem.splitScalar(u8, res.stdout, '\n');
    while (lines.next()) |raw| {
        const line = std.mem.trim(u8, raw, " \t\r");
        if (line.len == 0) continue;
        if (shown == 0) {
            prompt.warn("untracked files will NOT be installed — `git add` them first:");
        }
        if (shown < 10) {
            prompt.muted(std.fmt.allocPrint(allocator, "  {s}", .{line}) catch continue);
        }
        shown += 1;
    }
    if (shown > 10) {
        prompt.muted(std.fmt.allocPrint(allocator, "  … and {d} more", .{shown - 10}) catch return);
    }
}

/// Install the freshly built native launcher into the target scope's bin dir.
///
/// The bin dir follows the SCOPE, not the kernel path: a user install's
/// launcher belongs beside its kernel in ~/.local/bin, and writing it to
/// /usr/bin would both need root and overwrite the other install's binary — the
/// two-installs-one-file collision this whole change is about.
fn installLauncher(
    allocator: std.mem.Allocator,
    io: Io,
    env: *EnvMap,
    src: []const u8,
    target: Scope,
    needs_root: bool,
) void {
    const built = std.fs.path.join(allocator, &.{ src, "tools", "zig-out", "bin", "hkm" }) catch return;
    if (!util.fileExists(io, built)) {
        prompt.muted("no built launcher in tools/zig-out — run `zig build` there to update the hkm binary too.");
        return;
    }

    const bin_dir = switch (target) {
        .system => install_scope.system_bin_dir,
        .user => install_scope.userBinDir(allocator, env) orelse {
            prompt.warn("could not determine a user bin directory (no HOME) — launcher not installed.");
            return;
        },
    };
    if (target == .user) Dir.cwd().createDirPath(io, bin_dir) catch {};

    const targets = [_][]const u8{ "hkm", "hkm-config" };
    var failed: usize = 0;
    var installed_any = false;
    for (targets) |name| {
        const from = std.fs.path.join(allocator, &.{ src, "tools", "zig-out", "bin", name }) catch continue;
        if (!util.fileExists(io, from)) continue;
        const to = std.fs.path.join(allocator, &.{ bin_dir, name }) catch continue;

        var copied = true;
        if (needs_root and target == .system) {
            var cp = [_][]const u8{ "sudo", "cp", "-f", from, to };
            const code = run_cmd.spawnWait(io, env, &cp) catch blk: {
                break :blk @as(u8, 1);
            };
            copied = code == 0;
        } else {
            // Write beside it, then rename over.
            //
            // A running executable cannot be written to (ETXTBSY), and the most
            // ordinary reason for one to be running is a dev server started
            // with this very launcher. Overwriting in place made `hkm upgrade`
            // fail for the entire time `hkm run` was up. rename() replaces the
            // directory entry instead of the file: the running process keeps
            // its old inode and finishes normally, and the next invocation
            // picks up the new build.
            const staged = std.fmt.allocPrint(allocator, "{s}.hkm-new", .{to}) catch continue;
            copyOne(allocator, io, env, from, staged, false) catch {
                copied = false;
            };
            if (copied) {
                util.chmodExec(io, staged);
                Dir.cwd().rename(staged, Dir.cwd(), to, io) catch {
                    Dir.cwd().deleteFile(io, staged) catch {};
                    copied = false;
                };
            }
        }

        if (!copied) {
            // Reported, never swallowed. This used to `catch continue` and then
            // print "native launcher updated" regardless, so an upgrade that
            // installed NOTHING looked identical to one that worked — and the
            // next command silently ran the old binary. The usual cause is the
            // launcher being executed right now (ETXTBSY): a background `hkm`
            // still running holds it busy and every write to it fails.
            failed += 1;
            prompt.warn(std.fmt.allocPrint(
                allocator,
                "could not replace {s} — it is still the OLD build.",
                .{to},
            ) catch "could not replace the launcher — it is still the OLD build.");
            prompt.muted("  check what still holds it:  pgrep -af hkm");
            continue;
        }

        // The copy above writes bytes only, so the executable bit is lost. A
        // launcher installed without it fails at the first invocation with
        // "permission denied", long after the install reported success.
        util.chmodExec(io, to);
        installed_any = true;
    }

    if (failed > 0) return;
    if (installed_any) {
        prompt.ok(std.fmt.allocPrint(allocator, "native launcher updated in {s}", .{bin_dir}) catch "native launcher updated");
    }
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test "a release artifact URL is built for the requested tag" {
    var arena = std.heap.ArenaAllocator.init(std.testing.allocator);
    defer arena.deinit();
    const a = arena.allocator();

    const url = try assetUrl(a, "v1.3.1", "hkm-kernel-1.3.1-linux-x86_64.tar.gz");
    try std.testing.expect(std.mem.endsWith(u8, url, "/releases/download/v1.3.1/hkm-kernel-1.3.1-linux-x86_64.tar.gz"));
}

test "asset names drop the tag's leading v but the URL path keeps it" {
    // The tag is "v1.3.1" and every artifact is named "1.3.1" — mixing the two
    // up yields a 404 that reads as "download failed, check your connection".
    var arena = std.heap.ArenaAllocator.init(std.testing.allocator);
    defer arena.deinit();
    const a = arena.allocator();

    const ver = composer_version.normalize("v1.3.1");
    try std.testing.expectEqualStrings("1.3.1", ver);

    const asset = try std.fmt.allocPrint(a, "hkm-kernel-{s}-linux-x86_64.tar.gz", .{ver});
    const url = try assetUrl(a, "v1.3.1", asset);
    try std.testing.expect(std.mem.indexOf(u8, url, "/v1.3.1/") != null);
    try std.testing.expect(std.mem.indexOf(u8, url, "hkm-kernel-1.3.1-linux") != null);
}

test "an unstamped install compares as older than any release" {
    // A --local install has no version in composer.json. Treating that as
    // "equal" would make `hkm upgrade` refuse to replace it forever, which is
    // the state a user reads as "upgrading does not change the version".
    const latest = parseVer("v1.3.1");
    const unstamped = Ver{};
    try std.testing.expectEqual(std.math.Order.lt, unstamped.order(latest));
}

test "a pre-release sorts below its release so a dev tag is opt-in" {
    try std.testing.expectEqual(std.math.Order.lt, parseVer("1.4.0-dev").order(parseVer("1.4.0")));
    try std.testing.expectEqual(std.math.Order.gt, parseVer("1.4.0").order(parseVer("1.3.1")));
}
