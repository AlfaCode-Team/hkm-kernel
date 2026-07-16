//! `hkm upgrade [--check]` — check for and apply kernel updates.
//!
//!   hkm upgrade --check   # compare the installed version to the latest release
//!   hkm upgrade           # git checkout → pull + composer; packaged → guidance
//!
//! "Latest" is the highest v* tag on the kernel repo, discovered with
//! `git ls-remote` (no API token, works for the public repo). The header is the
//! Sentinel banner + current version.

const std = @import("std");
const banner = @import("../lib/banner.zig");
const kernel = @import("../lib/kernel.zig");
const run_cmd = @import("run.zig");
const util = @import("../lib/util.zig");
const prompt = @import("../lib/prompt.zig");

const Io = std.Io;
const EnvMap = std.process.Environ.Map;

const Ver = struct {
    major: u32 = 0,
    minor: u32 = 0,
    patch: u32 = 0,

    fn parse(s: []const u8) Ver {
        var t = s;
        if (t.len > 0 and (t[0] == 'v' or t[0] == 'V')) t = t[1..];
        // Ignore any pre-release / build suffix (-dev, -rc1, +meta).
        if (std.mem.indexOfAny(u8, t, "-+")) |i| t = t[0..i];
        var v: Ver = .{};
        var it = std.mem.splitScalar(u8, t, '.');
        v.major = std.fmt.parseInt(u32, it.next() orelse "0", 10) catch 0;
        v.minor = std.fmt.parseInt(u32, it.next() orelse "0", 10) catch 0;
        v.patch = std.fmt.parseInt(u32, it.next() orelse "0", 10) catch 0;
        return v;
    }

    fn order(a: Ver, b: Ver) std.math.Order {
        if (a.major != b.major) return std.math.order(a.major, b.major);
        if (a.minor != b.minor) return std.math.order(a.minor, b.minor);
        return std.math.order(a.patch, b.patch);
    }
};

const Latest = union(enum) {
    tag: []const u8, // highest v* tag found
    none, // repo reachable but has no release tags yet
    unreachable_, // git failed / offline
};

/// Query the remote repo for the highest v* tag.
fn latestTag(allocator: std.mem.Allocator, io: Io, env: *EnvMap) Latest {
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
        const v = Ver.parse(tag);
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

pub fn run(allocator: std.mem.Allocator, io: Io, env: *EnvMap, args: []const []const u8) !u8 {
    var check_only = false;
    for (args[1..]) |a| {
        if (std.mem.eql(u8, a, "--check") or std.mem.eql(u8, a, "-c")) check_only = true;
    }

    banner.print();

    const current = Ver.parse(banner.version());

    prompt.muted("checking for updates…");
    const latest = switch (latestTag(allocator, io, env)) {
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
    const latest_ver = Ver.parse(latest);

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
