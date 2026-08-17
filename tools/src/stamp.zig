//! Stamp a version into a composer.json.
//!
//!   stamp <composer.json path> <version>
//!
//! Run from build.zig so a versioned build carries its version everywhere, not
//! just in the compiled binary.
//!
//! The parsing, validation and rewriting all live in lib/composer_version.zig,
//! because `hkm version` and `hkm upgrade` READ the field this writes. When the
//! two halves lived in separate copies, a reader and a writer that disagreed
//! about what counts as a version would surface as an installed kernel
//! reporting the wrong number, with nothing pointing at the cause.
//!
//! WHY STAMPING IS NARROW ON PURPOSE
//! ---------------------------------
//! build.zig stamps only when an explicit -Dversion was passed — which is what
//! tools/bundle.sh does for a release. A plain `zig build` leaves composer.json
//! untouched, so a dev build never dirties the working tree with "0.0.0-dev"
//! that someone then commits by accident. See lib/composer_version.zig for why
//! the field is a liability in a git checkout and necessary in a bundle.

const std = @import("std");
const composer_version = @import("lib/composer_version.zig");

pub fn main(init: std.process.Init.Minimal) !void {
    var arena = std.heap.ArenaAllocator.init(std.heap.page_allocator);
    defer arena.deinit();
    const allocator = arena.allocator();

    var threaded: std.Io.Threaded = .init(std.heap.page_allocator, .{});
    defer threaded.deinit();
    const io = threaded.io();

    const args = try init.args.toSlice(allocator);
    if (args.len < 3) {
        std.debug.print("usage: stamp <composer.json> <version>\n", .{});
        return error.MissingArguments;
    }

    const path = args[1];
    const version = composer_version.normalize(args[2]);
    if (version.len == 0) return; // nothing meaningful to stamp

    // A version Composer cannot parse is far worse than no version at all:
    // `composer install` ABORTS on it, so the package never resolves its
    // dependencies. That happened for real — "1.1.0-dev.2" was stamped from the
    // git tag, and every install of that release failed with
    //
    //   "./composer.json" does not match the expected JSON schema:
    //    - version : Does not match the regex pattern ...
    //
    // Composer's `dev` suffix takes NO counter ("1.1.0-dev" is valid,
    // "1.1.0-dev.2" and "1.1.0-dev2" are not). Rather than rewrite the version
    // into something Composer likes — which would make composer.json disagree
    // with the tag it was built from — the field is simply left out. It is
    // optional; a broken install is not.
    if (!composer_version.composerValid(version)) {
        // A `git describe` version ("1.1.0-dev.2-12-g29dccfb") is what every
        // build from a checkout between releases looks like. It is EXPECTED to
        // be unstampable, so saying so on every single dev build trains people
        // to ignore the message — and then they ignore it on the release build
        // where it matters. Skip quietly for that shape; warn for anything else.
        if (composer_version.isDescribeVersion(version)) return;

        // A version longer than the buffer would make bufPrint fail, and
        // returning there skipped the marker with NO diagnostic at all — the
        // silent failure this warning exists to prevent. Fall back to a fixed
        // message so every rejected version is reported.
        var buf: [256]u8 = undefined;
        const msg = std.fmt.bufPrint(
            &buf,
            "stamp: '{s}' is not a valid Composer version — leaving composer.json alone.\n" ++
                "       (Composer accepts 1.2.3, 1.2.3-dev, 1.2.3-beta.4, 1.2.3-RC1; a 'dev' suffix takes no number.)\n",
            .{version},
        ) catch
            "stamp: the requested version is not valid for Composer — leaving composer.json alone.\n";
        std.Io.File.stderr().writeStreamingAll(io, msg) catch {};
        return;
    }

    // A missing composer.json is not a build failure: the same build.zig runs
    // in checkouts and in staging trees that do not carry one.
    const source = std.Io.Dir.cwd().readFileAlloc(io, path, allocator, .limited(8 * 1024 * 1024)) catch return;

    const updated = try composer_version.stamp(allocator, source, version) orelse return; // already correct
    try std.Io.Dir.cwd().writeFile(io, .{ .sub_path = path, .data = updated });
}
