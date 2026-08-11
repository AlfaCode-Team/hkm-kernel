//! HKM ASCII banner + version header, shared by `hkm --version` and the
//! update commands. Kept dependency-free (just std.debug.print + ANSI).

const std = @import("std");
const build_info = @import("build_info");

const cyan = "\x1b[36m";
const bold = "\x1b[1m";
const dim = "\x1b[2m";
const reset = "\x1b[0m";

/// The product/brand is "HKM" (HKM Kernel).
const art =
    \\  ██╗  ██╗ ██╗  ██╗ ███╗   ███╗
    \\  ██║  ██║ ██║ ██╔╝ ████╗ ████║
    \\  ███████║ █████╔╝  ██╔████╔██║
    \\  ██╔══██║ ██╔═██╗  ██║╚██╔╝██║
    \\  ██║  ██║ ██║  ██╗ ██║ ╚═╝ ██║
    \\  ╚═╝  ╚═╝ ╚═╝  ╚═╝ ╚═╝     ╚═╝
;

pub fn version() []const u8 {
    return build_info.version;
}

pub fn repo() []const u8 {
    return build_info.repo;
}

/// Full banner: ASCII art + version + tagline. Used as the header of the
/// version/update commands.
///
/// Takes io/env so the PHP line can report the runtime ACTUALLY on this machine
/// rather than a compiled-in constant — which is the only version of the two
/// that can differ from what the user expects, and the one that explains most
/// "it works on my machine" reports.
pub fn print(allocator: std.mem.Allocator, io: std.Io, env: *std.process.Environ.Map) void {
    std.debug.print("\n{s}{s}{s}\n", .{ cyan, art, reset });
    std.debug.print("  {s}HKM Kernel{s} {s}· Gated Demand Architecture{s}\n", .{ bold, reset, dim, reset });
    std.debug.print("  {s}version {s}{s}{s}\n", .{ dim, reset, build_info.version, reset });
    std.debug.print("  {s}PHP     {s}{s}{s}\n\n", .{ dim, reset, phpVersion(allocator, io, env) orelse "not found", reset });
}

/// The PHP runtime's version, or null when php is absent or unreadable.
///
/// Asked of PHP itself (`-r 'echo PHP_VERSION;'`) rather than parsed out of
/// `php -v`, whose first line carries build metadata and varies between
/// distributions. Honours HKM_PHP_BIN, so the banner reports the interpreter
/// this tool would actually run — not whichever `php` happens to be first on
/// PATH.
///
/// Never fails the banner: a missing PHP is a real state worth SHOWING (the
/// kernel cannot run without it), not a reason to refuse to print a version.
fn phpVersion(allocator: std.mem.Allocator, io: std.Io, env: *std.process.Environ.Map) ?[]const u8 {
    const bin = env.get("HKM_PHP_BIN") orelse "php";

    const res = std.process.run(allocator, io, .{
        .argv = &.{ bin, "-r", "echo PHP_VERSION;" },
        .environ_map = env,
    }) catch return null;

    switch (res.term) {
        .exited => |c| if (c != 0) return null,
        else => return null,
    }

    const v = std.mem.trim(u8, res.stdout, " \t\r\n");
    return if (v.len == 0) null else v;
}

/// One-line version, for `hkm --version` piped/scripted use.
///
/// Goes to STDOUT. It used to use std.debug.print, which writes to stderr — so
/// the one function whose entire purpose is to be captured
/// (VERSION=$(hkm --version)) returned an empty string, while the version went
/// somewhere the caller was not looking. The docblock claimed scripted use the
/// whole time.
pub fn printShort(io: std.Io) void {
    var buf: [128]u8 = undefined;
    const line = std.fmt.bufPrint(&buf, "hkm (HKM Kernel) {s}\n", .{build_info.version}) catch return;
    std.Io.File.stdout().writeStreamingAll(io, line) catch {};
}
