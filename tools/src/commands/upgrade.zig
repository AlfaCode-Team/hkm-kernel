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
    for (args[1..]) |a| {
        if (std.mem.eql(u8, a, "--check") or std.mem.eql(u8, a, "-c")) check_only = true;
        if (std.mem.eql(u8, a, "--local") or std.mem.eql(u8, a, "-l")) from_local = true;
        if (std.mem.eql(u8, a, "--dry-run") or std.mem.eql(u8, a, "-n")) dry_run = true;
        if (std.mem.eql(u8, a, "--yes") or std.mem.eql(u8, a, "-y")) assume_yes = true;
        if (std.mem.eql(u8, a, "--pre")) include_pre = true;
        if (std.mem.eql(u8, a, "--help") or std.mem.eql(u8, a, "-h")) {
            printHelp();
            return 0;
        }
    }

    banner.print();

    if (from_local) return localUpgrade(allocator, io, env, dry_run, assume_yes);

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
                // Fallback: dpkg then fix deps.
                var dpkg = [_][]const u8{ "sudo", "dpkg", "-i", tmp };
                _ = run_cmd.spawnWait(io, env, &dpkg) catch {};
                var fix = [_][]const u8{ "sudo", "apt-get", "-f", "install", "-y" };
                _ = run_cmd.spawnWait(io, env, &fix) catch {};
            }
        },
        .macos => {
            // Replace the kernel resources in place, then re-resolve composer.
            const root = kernelRoot(allocator, io, env) orelse "/Applications/HKM.app/Contents/Resources/opt/hkm-kernel";
            const app_root = std.fs.path.dirname(std.fs.path.dirname(std.fs.path.dirname(root) orelse root) orelse root) orelse root;
            var untar = [_][]const u8{ "tar", "-xzf", tmp, "-C", app_root, "--strip-components=0" };
            _ = run_cmd.spawnWait(io, env, &untar) catch {};
            const installer = try std.fs.path.join(allocator, &.{ root, "install.sh" });
            if (util.fileExists(io, installer)) {
                var sh = [_][]const u8{ "sh", installer };
                _ = run_cmd.spawnWait(io, env, &sh) catch {};
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
fn localUpgrade(allocator: std.mem.Allocator, io: Io, env: *EnvMap, dry_run: bool, assume_yes: bool) !u8 {
    // SOURCE: the checkout this command is being run from or pointed at.
    const src = (try kernel.resolveDevHome(allocator, io)) orelse {
        prompt.err("no local kernel checkout found. Run this from inside the monorepo, or set HKM_DEV_HOME to it.");
        return 1;
    };

    // TARGET: the installed kernel every project on this machine resolves to.
    const dest = kernelRoot(allocator, io, env) orelse {
        prompt.err("could not locate an installed kernel to update. Is hkm installed (/opt/hkm-kernel)?");
        return 1;
    };

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
            failed += 1;
            // Report the FIRST failure with its cause. Counting 645 silent
            // failures tells the user something went wrong and nothing about
            // what, which is barely better than failing silently.
            if (failed == 1) {
                prompt.err(try std.fmt.allocPrint(allocator, "{s}: {t}", .{ rel_dest, e }));
            }
            continue;
        };
        copied += 1;
    }

    prompt.ok(try std.fmt.allocPrint(allocator, "copied {d} file(s)", .{copied}));
    if (failed > 0) {
        prompt.err(try std.fmt.allocPrint(allocator, "{d} file(s) could not be written — the install may be inconsistent.", .{failed}));
        return 1;
    }

    // The native launcher is built, not tracked, so it is copied separately —
    // and only when it exists, since a checkout that has never run `zig build`
    // has nothing to install.
    installLauncher(allocator, io, env, src, needs_root);

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
        prompt.muted("no install.sh in the target — skipping composer step.");
    }

    prompt.outro("Installed kernel updated from the local checkout. Verify with: hkm doctor");
    return 0;
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

/// Install the freshly built native launcher next to the one in use.
fn installLauncher(allocator: std.mem.Allocator, io: Io, env: *EnvMap, src: []const u8, needs_root: bool) void {
    const built = std.fs.path.join(allocator, &.{ src, "tools", "zig-out", "bin", "hkm" }) catch return;
    if (!util.fileExists(io, built)) {
        prompt.muted("no built launcher in tools/zig-out — run `zig build` there to update /usr/bin/hkm too.");
        return;
    }

    const targets = [_][]const u8{ "hkm", "hkm-config" };
    for (targets) |name| {
        const from = std.fs.path.join(allocator, &.{ src, "tools", "zig-out", "bin", name }) catch continue;
        if (!util.fileExists(io, from)) continue;
        const to = std.fmt.allocPrint(allocator, "/usr/bin/{s}", .{name}) catch continue;

        if (needs_root) {
            var cp = [_][]const u8{ "sudo", "cp", "-f", from, to };
            _ = run_cmd.spawnWait(io, env, &cp) catch continue;
        } else {
            copyOne(allocator, io, env, from, to, false) catch continue;
        }
    }
    prompt.ok("native launcher updated");
}
