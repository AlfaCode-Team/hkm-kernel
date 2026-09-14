//! `hkm ppkg` — the package manager, hosted.
//!
//! The command line itself lives in modules/hkm-ppkg (`src/cli.zig`), which
//! also ships on its own as the standalone `ppkg` binary. Keeping ONE front-end
//! there is the point: two copies of forty commands' argument handling would
//! drift the first time either was fixed.
//!
//! What stays here is only what hkm knows and the package cannot:
//!   * how the tool is spelled here (`hkm ppkg`), which `report` applies to
//!     every message the package prints;
//!   * which releases `self-update` follows — the kernel's, since that is what
//!     delivers this binary;
//!   * where the launcher lives, for `@composer` in a project's scripts;
//!   * `test-env`, which builds a plugin's vendor/ out of this kernel's own and
//!     so has no business in a general package manager.

const std = @import("std");
const ppkg = @import("ppkg");
// The version stamped in by `zig build -Dversion=…`. `self-update` compares it
// against the newest published release.
const build_info = @import("build_info");
const testenv = @import("../lib/ppkg/testenv.zig");

const Io = std.Io;
const EnvMap = std.process.Environ.Map;

/// `hkm ppkg …` — `args` is the launcher's whole argv: `hkm`, `ppkg`, then the
/// package manager's own arguments.
pub fn run(allocator: std.mem.Allocator, io: Io, env: *EnvMap, args: []const []const u8) !u8 {
    const cli_path = env.get("HKM_CLI_PATH") orelse "";
    return ppkg.cli.run(allocator, io, env, if (args.len > 2) args[2..] else &.{}, .{
        .program = "hkm ppkg",
        .version = build_info.version,
        .release_repo = build_info.repo,
        .executable = cli_path,
        .self_command = if (cli_path.len > 0) try std.fmt.allocPrint(allocator, "{s} ppkg", .{cli_path}) else "",
        .extension = .{
            .dispatch = extra,
            .usage = &.{
                .{ "ppkg test-env <dir>", "build a plugin's test vendor/ from what is already on disk" },
            },
            .words = "test-env",
        },
    });
}

fn extra(
    allocator: std.mem.Allocator,
    io: Io,
    env: *EnvMap,
    sub: []const u8,
    rest: []const []const u8,
) anyerror!?u8 {
    if (std.mem.eql(u8, sub, "test-env") or std.mem.eql(u8, sub, "testenv")) {
        return try testenv.command(allocator, io, env, rest);
    }
    return null;
}
