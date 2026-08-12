//! `hkm upgrade [--check]` — check for and apply kernel updates.
//!
//!   hkm upgrade --check   # compare the installed version to the latest release
//!   hkm upgrade           # git checkout → pull + composer; packaged → guidance
//!
//! "Latest" is the highest v* tag on the kernel repo, discovered with
//! `git ls-remote` (no API token, works for the public repo). The header is the
//! HKM banner + current version.

const std = @import("std");
const banner = @import("../lib/banner.zig");
const kernel = @import("../lib/kernel.zig");
const run_cmd = @import("run.zig");
const util = @import("../lib/util.zig");
const userconfig = @import("../lib/userconfig.zig");
const semver = @import("../lib/semver.zig");
const prompt = @import("../lib/prompt.zig");

const Dir = std.Io.Dir;
const Io = std.Io;
const EnvMap = std.process.Environ.Map;

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

/// Kernel root (the dir holding composer.json + install.sh) from the resolved
/// CLI path `<root>/bin/hkm`.
fn kernelRoot(allocator: std.mem.Allocator, io: Io, env: *EnvMap) ?[]const u8 {
    const r = kernel.resolve(allocator, io, env) catch return null;
    const bin = std.fs.path.dirname(r.path) orelse return null; // <root>/bin
    return std.fs.path.dirname(bin); // <root>
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
    // --user: install into the user's own data dir instead of the system one,
    // so nothing about the kernel — including installing plugins into its
    // plugins/ — ever needs root.
    var user_install = false;
    // --local builds the checkout before copying it. Without this the tools/
    // binaries in zig-out could be older than the source being installed, so
    // "install my local changes" would ship a launcher that predates them —
    // the one failure mode a local test install must not have.
    var build_first = true;
    for (args[1..]) |a| {
        if (std.mem.eql(u8, a, "--check") or std.mem.eql(u8, a, "-c")) check_only = true;
        if (std.mem.eql(u8, a, "--local") or std.mem.eql(u8, a, "-l")) from_local = true;
        if (std.mem.eql(u8, a, "--dry-run") or std.mem.eql(u8, a, "-n")) dry_run = true;
        if (std.mem.eql(u8, a, "--yes") or std.mem.eql(u8, a, "-y")) assume_yes = true;
        if (std.mem.eql(u8, a, "--pre")) include_pre = true;
        if (std.mem.eql(u8, a, "--user") or std.mem.eql(u8, a, "-u")) user_install = true;
        if (std.mem.eql(u8, a, "--no-build")) build_first = false;
        if (std.mem.eql(u8, a, "--help") or std.mem.eql(u8, a, "-h")) {
            printHelp();
            return 0;
        }
    }

    banner.print(allocator, io, env);

    if (from_local) return localUpgrade(allocator, io, env, dry_run, assume_yes, user_install, build_first);

    const current = parseVer(banner.version());

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

    prompt.item("installed", banner.version());
    prompt.item("latest", latest);

    switch (current.order(latest_ver)) {
        .eq => {
            prompt.ok("you are on the latest version.");
            return 0;
        },
        .gt => {
            prompt.ok("your version is newer than the latest release (dev build).");
            return 0;
        },
        .lt => {
            prompt.warn("an update is available.");
        },
    }

    if (check_only) {
        prompt.item("to update", "run: hkm upgrade");
        return 0;
    }

    // Perform the update.
    const root = kernelRoot(allocator, io, env) orelse {
        prompt.err("could not locate the kernel install (set HKM_KERNEL_HOME).");
        return 1;
    };
    const git_dir = try std.fs.path.join(allocator, &.{ root, ".git" });

    if (util.fileExists(io, git_dir)) {
        // Git checkout install → pull + re-resolve composer deps.
        prompt.section("Updating (git)");
        var pull = [_][]const u8{ "git", "-C", root, "pull", "--ff-only", "--tags" };
        _ = run_cmd.spawnWait(io, env, &pull) catch {};
        const installer = try std.fs.path.join(allocator, &.{ root, "install.sh" });
        if (util.fileExists(io, installer)) {
            var sh = [_][]const u8{ "sh", installer };
            _ = run_cmd.spawnWait(io, env, &sh) catch {};
        }
        prompt.ok("kernel updated. Verify with: hkm doctor");
        return 0;
    }

    // Packaged install: detect OS, download the matching artifact, install it.
    return performPackagedUpgrade(allocator, io, env, latest);
}

/// Download the release artifact for THIS OS and install it. The binary is built
/// per-OS, so builtin.os.tag / cpu.arch are comptime — only this platform's path
/// is compiled in.
fn performPackagedUpgrade(allocator: std.mem.Allocator, io: Io, env: *EnvMap, latest: []const u8) !u8 {
    const os = @import("builtin").os.tag;
    const arch = @import("builtin").cpu.arch;
    const ver = if (latest.len > 0 and (latest[0] == 'v' or latest[0] == 'V')) latest[1..] else latest; // "1.0.1"

    const asset: []const u8 = switch (os) {
        .linux => try std.fmt.allocPrint(allocator, "hkm-kernel_{s}_amd64.deb", .{ver}),
        .macos => try std.fmt.allocPrint(allocator, "hkm-kernel-{s}-macos-universal.tar.gz", .{ver}),
        .windows => try std.fmt.allocPrint(allocator, "hkm-kernel-{s}-windows-x86_64.zip", .{ver}),
        else => return errUnsupported(),
    };
    if (os == .linux and arch != .x86_64) {
        prompt.err("only an amd64 .deb is published; your architecture has no prebuilt package.");
        return 1;
    }

    const url = try std.fmt.allocPrint(
        allocator,
        "https://github.com/{s}/releases/download/{s}/{s}",
        .{ banner.repo(), latest, asset },
    );
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
        .linux => {
            // apt handles the local .deb + its dependencies; needs root.
            var argv = [_][]const u8{ "sudo", "apt-get", "install", "-y", tmp };
            const code = run_cmd.spawnWait(io, env, &argv) catch 1;
            if (code != 0) {
                // Fallback: dpkg then fix deps. Both results are KEPT: with them
                // discarded, an upgrade where apt AND dpkg both failed printed
                // "updated" and left the old kernel installed — the user then
                // debugs a version they believe they are no longer running.
                var dpkg = [_][]const u8{ "sudo", "dpkg", "-i", tmp };
                const dpkg_code = run_cmd.spawnWait(io, env, &dpkg) catch 1;
                var fix = [_][]const u8{ "sudo", "apt-get", "-f", "install", "-y" };
                const fix_code = run_cmd.spawnWait(io, env, &fix) catch 1;
                if (dpkg_code != 0 and fix_code != 0) {
                    prompt.err("installation FAILED — the previous kernel is still in place.");
                    prompt.muted(try std.fmt.allocPrint(allocator, "  the package is downloaded at {s}", .{tmp}));
                    prompt.muted("  try it by hand:  sudo apt-get install -y <path>");
                    return 1;
                }
            }
        },
        .macos => {
            // Replace the kernel resources in place, then re-resolve composer.
            const root = kernelRoot(allocator, io, env) orelse "/Applications/HKM.app/Contents/Resources/opt/hkm-kernel";
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
    prompt.ok("updated. Verify with: hkm doctor");
    return 0;
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
    prompt.item("hkm upgrade", "download and install the latest published release");
    prompt.item("hkm upgrade --check", "report whether an update exists, install nothing");
    prompt.item("hkm upgrade --local", "install THIS checkout over the installed kernel");
    prompt.blank();
    prompt.section("Options");
    prompt.item("--local, -l", "source the update from the local checkout instead of GitHub");
    prompt.item("--dry-run, -n", "show what --local would copy, write nothing");
    prompt.item("--yes, -y", "skip the confirmation prompt");
    prompt.item("--user, -u", "with --local: install into ~/.local/share/hkm/kernel (no sudo, ever)");
    prompt.item("--no-build", "with --local: skip `zig build`, install what is already in tools/zig-out");
    prompt.item("--pre", "consider pre-releases (dev / rc) when checking for updates");
    prompt.item("--check, -c", "check only");
    prompt.item("--help, -h", "show this help");
    prompt.outro("--local needs write access to the installed kernel (usually sudo)");
}

/// `hkm upgrade --local` — install the LOCAL checkout over the INSTALLED kernel.
///
/// The normal upgrade path fetches a published release. This one exists for the
/// case that path cannot serve: you have changed the kernel and want the
/// installed copy — the one every project on this machine actually runs — to be
/// that change, without tagging a release first.
///
/// It copies the same file set a .deb ships (shipped_paths, mirroring
/// bundle.sh), so the result behaves like a real install rather than a
/// half-synced hybrid.
/// `~/.local/share/hkm/kernel` (or $XDG_DATA_HOME), the user-owned kernel root.
///
/// The system install lives under /opt and is root-owned, which means every
/// plugin install — they go into the kernel's plugins/ — needs sudo. A kernel
/// inside the user's own data directory removes that entirely, and sits beside
/// the registry hkm already keeps at ~/.local/share/hkm.
fn userKernelRoot(allocator: std.mem.Allocator, env: *EnvMap) ?[]const u8 {
    if (env.get("XDG_DATA_HOME")) |x| {
        if (x.len > 0) return std.fmt.allocPrint(allocator, "{s}/hkm/kernel", .{util.trimSlash(x)}) catch null;
    }
    const home = env.get("HOME") orelse return null;
    if (home.len == 0) return null;
    return std.fmt.allocPrint(allocator, "{s}/.local/share/hkm/kernel", .{util.trimSlash(home)}) catch null;
}

fn localUpgrade(allocator: std.mem.Allocator, io: Io, env: *EnvMap, dry_run: bool, assume_yes: bool, user_install: bool, build_first: bool) !u8 {
    // SOURCE: the checkout this command is being run from or pointed at.
    const src = (try resolveSource(allocator, io, env)) orelse {
        prompt.err("no local kernel checkout found.");
        prompt.muted("  set one:  hkm-config set HKM_DEV_HOME /path/to/the/checkout");
        prompt.muted("  or run this from inside it.");
        return 1;
    };

    // TARGET: the user's own kernel root with --user, otherwise the installed
    // one every project on this machine resolves to.
    const dest = if (user_install)
        userKernelRoot(allocator, env) orelse {
            prompt.err("could not determine a user kernel root (no HOME / XDG_DATA_HOME).");
            return 1;
        }
    else
        kernelRoot(allocator, io, env) orelse {
            prompt.err("could not locate an installed kernel to update. Is hkm installed (/opt/hkm-kernel)?");
            return 1;
        };

    if (user_install) Dir.cwd().createDirPath(io, dest) catch {};

    // Copying a checkout over itself would delete files mid-walk and leave the
    // only copy of the kernel in an unknown state.
    if (std.mem.eql(u8, src, dest)) {
        prompt.err("the local checkout IS the installed kernel — there is nothing to copy.");
        prompt.muted(src);
        return 1;
    }

    if (!kernel.isKernelDir(io, src)) {
        prompt.err(try std.fmt.allocPrint(allocator, "{s} is not a kernel checkout (no composer.json).", .{src}));
        return 1;
    }

    prompt.section("Local upgrade");
    prompt.item("source", src);
    prompt.item("target", dest);
    prompt.item("version", localVersion(allocator, io, env, src) orelse "unknown");
    prompt.item("installed", installedVersion(allocator, io, dest) orelse banner.version());

    if (dry_run) {
        prompt.blank();
        prompt.section("Would copy");
        for (shipped_paths) |p| prompt.muted(p);
        prompt.outro("Dry run — nothing was written");
        return 0;
    }

    // Overwriting the kernel every project on this machine runs is not
    // something to do on a typo.
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

    // The native launcher is built, not tracked, so it is copied separately —
    // and only when it exists, since a checkout that has never run `zig build`
    // has nothing to install.
    installLauncher(allocator, io, env, src, needs_root, user_install);

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

    // A user kernel that nothing points at is inert: resolution would still
    // find /opt (or nothing). Record it so every later hkm invocation — and
    // therefore every plugin install — uses the root that needs no sudo.
    if (user_install) {
        userconfig.set(allocator, io, env, "HKM_KERNEL_HOME", dest) catch {
            prompt.warn(try std.fmt.allocPrint(
                allocator,
                "installed, but could not record it. Add this to your shell:\n  export HKM_KERNEL_HOME={s}",
                .{dest},
            ));
        };
        prompt.ok(try std.fmt.allocPrint(allocator, "HKM_KERNEL_HOME set to {s}", .{dest}));
        prompt.muted("plugins now install there — no sudo.");
    }

    prompt.outro("Installed kernel updated from the local checkout. Verify with: hkm doctor");
    return 0;
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

/// The TARGET's version, read from the composer.json that ships with it.
///
/// Not banner.version(): that is the version THIS BINARY was stamped with, and
/// the binary being run is usually the local dev build — so the "installed"
/// line would report the source's version on both sides and always look like a
/// no-op. The two differ exactly when this command is worth running.
fn installedVersion(allocator: std.mem.Allocator, io: Io, dest: []const u8) ?[]const u8 {
    const path = std.fs.path.join(allocator, &.{ dest, "composer.json" }) catch return null;
    const body = Dir.cwd().readFileAlloc(io, path, allocator, .limited(1024 * 1024)) catch return null;

    const parsed = std.json.parseFromSliceLeaky(std.json.Value, allocator, body, .{}) catch return null;
    if (parsed != .object) return null;
    const v = parsed.object.get("version") orelse return null;
    if (v != .string or v.string.len == 0) return null;

    return v.string;
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

/// Install the freshly built native launcher next to the one in use.
fn installLauncher(
    allocator: std.mem.Allocator,
    io: Io,
    env: *EnvMap,
    src: []const u8,
    needs_root: bool,
    user_install: bool,
) void {
    const built = std.fs.path.join(allocator, &.{ src, "tools", "zig-out", "bin", "hkm" }) catch return;
    if (!util.fileExists(io, built)) {
        prompt.muted("no built launcher in tools/zig-out — run `zig build` there to update /usr/bin/hkm too.");
        return;
    }

    const targets = [_][]const u8{ "hkm", "hkm-config" };
    var failed: usize = 0;
    var installed_any = false;
    for (targets) |name| {
        const from = std.fs.path.join(allocator, &.{ src, "tools", "zig-out", "bin", name }) catch continue;
        if (!util.fileExists(io, from)) continue;
        // A --user install must not write to /usr/bin: that needs root, which
        // is the whole thing --user exists to avoid. ~/.local/bin is the
        // conventional user-level bin dir and is already on PATH here.
        const to = if (user_install) blk: {
            const home = env.get("HOME") orelse continue;
            const dir = std.fmt.allocPrint(allocator, "{s}/.local/bin", .{util.trimSlash(home)}) catch continue;
            Dir.cwd().createDirPath(io, dir) catch {};
            break :blk std.fmt.allocPrint(allocator, "{s}/{s}", .{ dir, name }) catch continue;
        } else std.fmt.allocPrint(allocator, "/usr/bin/{s}", .{name}) catch continue;

        var copied = true;
        if (needs_root and !user_install) {
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
    if (installed_any) prompt.ok("native launcher updated");
}
