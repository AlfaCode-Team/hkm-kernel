//! Parsing and editing of a project's `app/bootstrap/app.php`: extracting the
//! providers wired into `withModules([...])` / `withEssentialModules([...])`,
//! and inserting/removing provider entries. This is text manipulation of PHP
//! source — kept isolated here so its (inherently fragile) rules live in ONE
//! place with a clear contract.

const std = @import("std");
const util = @import("util.zig");
const sources = @import("plugin_sources.zig");

/// How a provider was wired into the kernel builder.
pub const Activation = enum { on_demand, essential };

/// One enabled plugin discovered in the bootstrap file.
pub const Enabled = struct {
    /// Plugin folder name (e.g. "Crypto"), or the raw token when unresolved.
    name: []const u8,
    /// The exact `X` written before `::class` (alias or FQN) — used to locate
    /// the entry's line when disabling.
    token: []const u8,
    activation: Activation,
    /// Enriched from the plugin's module.json when found.
    solves: ?[]const u8 = null,
    version: ?[]const u8 = null,
    resolved: bool = false,
    /// Which source the plugin folder was found in (project shadows kernel).
    source: ?sources.Source = null,
};

/// alias (as written in `use ... as Alias`) → plugin folder name.
pub const Alias = struct { alias: []const u8, plugin: []const u8 };

pub const Markers = struct {
    pub const on_demand = "withModules(";
    pub const essential = "withEssentialModules(";
};

// ── reading ─────────────────────────────────────────────────────────────────

/// Parse both builder arrays into `out`. Convenience over two collectFromArray
/// calls — the common case.
pub fn collectEnabled(allocator: std.mem.Allocator, source: []const u8, aliases: []const Alias, out: *std.ArrayList(Enabled)) !void {
    try collectFromArray(allocator, source, Markers.on_demand, .on_demand, aliases, out);
    try collectFromArray(allocator, source, Markers.essential, .essential, aliases, out);
}

/// `c` may appear inside a class identifier (alnum, '_', '\').
fn isIdentChar(c: u8) bool {
    return std.ascii.isAlphanumeric(c) or c == '_' or c == '\\';
}

/// Plugin folder name = the segment immediately after `Plugins\` in a token.
pub fn pluginFromToken(token: []const u8) ?[]const u8 {
    const marker = "Plugins\\";
    const at = std.mem.indexOf(u8, token, marker) orelse return null;
    const rest = token[at + marker.len ..];
    var end: usize = 0;
    while (end < rest.len and rest[end] != '\\') end += 1;
    if (end == 0) return null;
    return rest[0..end];
}

/// Scan every `use Plugins\... ;` statement and record alias → plugin folder.
pub fn collectAliases(allocator: std.mem.Allocator, source: []const u8, out: *std.ArrayList(Alias)) !void {
    var lines = std.mem.splitScalar(u8, source, '\n');
    while (lines.next()) |raw| {
        const line = std.mem.trim(u8, raw, " \t\r");
        if (!std.mem.startsWith(u8, line, "use ")) continue;
        const plugin = pluginFromToken(line) orelse continue;

        const alias = if (std.mem.indexOf(u8, line, " as ")) |idx| blk: {
            var a = std.mem.trim(u8, line[idx + 4 ..], " \t\r");
            if (std.mem.indexOfScalar(u8, a, ';')) |sc| a = a[0..sc];
            break :blk std.mem.trim(u8, a, " \t\r");
        } else blk: {
            var t = line[4..];
            if (std.mem.indexOfScalar(u8, t, ';')) |sc| t = t[0..sc];
            t = std.mem.trim(u8, t, " \t\r");
            const lastSlash = std.mem.lastIndexOfScalar(u8, t, '\\') orelse 0;
            break :blk if (lastSlash == 0) t else t[lastSlash + 1 ..];
        };
        if (alias.len == 0) continue;
        try out.append(allocator, .{ .alias = alias, .plugin = plugin });
    }
}

/// Resolve a `::class` reference token to a plugin folder name.
fn resolvePlugin(token: []const u8, aliases: []const Alias) ?[]const u8 {
    if (pluginFromToken(token)) |p| return p; // fully-qualified reference
    for (aliases) |a| {
        if (std.mem.eql(u8, a.alias, token)) return a.plugin;
    }
    return null;
}

/// Find `marker` (e.g. "withModules(") and collect every `Ident::class` entry up
/// to the array's closing `])`.
pub fn collectFromArray(
    allocator: std.mem.Allocator,
    source: []const u8,
    marker: []const u8,
    activation: Activation,
    aliases: []const Alias,
    out: *std.ArrayList(Enabled),
) !void {
    const start = std.mem.indexOf(u8, source, marker) orelse return;
    const body = source[start + marker.len ..];
    const end = std.mem.indexOf(u8, body, "])") orelse body.len;
    const block = body[0..end];

    const needle = "::class";
    var search: usize = 0;
    while (std.mem.indexOfPos(u8, block, search, needle)) |pos| {
        var b = pos;
        while (b > 0 and isIdentChar(block[b - 1])) b -= 1;
        const token = block[b..pos];
        search = pos + needle.len;
        if (token.len == 0) continue;

        // A commented-out provider is not enabled.
        //
        // Scanning for `::class` with no notion of comments meant the template's
        // documented-as-optional identity stack — User, Feedback, Auth, Tenancy,
        // all four written as `//   \Plugins\User\Provider::class,` — counted as
        // enabled. Every new project downloaded and wired four plugins it had
        // explicitly not asked for, and the comment that said "enable together
        // in an app that needs accounts" was decoration.
        if (isCommentedOut(block, b)) continue;

        const name = resolvePlugin(token, aliases) orelse token;
        if (isEnabled(out.items, name)) continue; // de-dupe
        try out.append(allocator, .{ .name = name, .token = token, .activation = activation });
    }
}

/// Is the token at `at` inside a comment?
///
/// Line-level: a `//` earlier on the same line, or a line whose first non-space
/// character starts a block comment or continues one (` * `). That covers how
/// providers are actually commented out in a bootstrap, and — unlike a full PHP
/// parse — cannot itself go wrong in a way that silently drops a live entry.
///
/// Deliberately NOT fooled by a trailing comment: `Provider::class, // note`
/// has its `//` AFTER the token and stays enabled.
fn isCommentedOut(block: []const u8, at: usize) bool {
    const line_start = if (std.mem.lastIndexOfScalar(u8, block[0..at], '\n')) |nl| nl + 1 else 0;
    const before = block[line_start..at];

    if (std.mem.indexOf(u8, before, "//") != null) return true;

    const trimmed = std.mem.trimStart(u8, before, " \t");
    if (std.mem.startsWith(u8, trimmed, "*")) return true; // inside a docblock
    if (std.mem.startsWith(u8, trimmed, "/*")) return true;

    return false;
}

pub fn isEnabled(items: []const Enabled, name: []const u8) bool {
    for (items) |e| {
        if (std.mem.eql(u8, e.name, name)) return true;
    }
    return false;
}

pub fn findEnabled(items: []const Enabled, folder: []const u8) ?Enabled {
    for (items) |e| {
        if (util.eqlIgnoreCase(e.name, folder)) return e;
    }
    return null;
}

// ── writing ─────────────────────────────────────────────────────────────────

/// Build the text inserted into the builder array: a documentation comment (from
/// module.json) followed by the fully-qualified provider entry. No `use`
/// statement is needed — the FQN resolves in the global-namespace bootstrap.
pub fn buildEntryBlock(allocator: std.mem.Allocator, folder: []const u8, meta: ?sources.ModuleMeta) ![]const u8 {
    var out: std.ArrayList(u8) = .empty;

    try out.appendSlice(allocator, "\n        // ");
    try out.appendSlice(allocator, folder);
    if (meta) |m| {
        if (m.solves) |s| {
            try out.appendSlice(allocator, " — solves: ");
            try out.appendSlice(allocator, s);
        }
        if (m.version) |v| {
            try out.appendSlice(allocator, " · v");
            try out.appendSlice(allocator, v);
        }
    }

    const docText: ?[]const u8 = if (meta) |m| (m.doc orelse m.description) else null;
    if (docText) |txt| try appendWrappedComment(allocator, &out, txt);

    try out.appendSlice(allocator, "\n        Plugins\\");
    try out.appendSlice(allocator, folder);
    try out.appendSlice(allocator, "\\Provider::class,");

    return out.toOwnedSlice(allocator);
}

/// Word-wrap `text` to ~72 columns, each line as `        // ...`.
fn appendWrappedComment(allocator: std.mem.Allocator, out: *std.ArrayList(u8), text: []const u8) !void {
    const limit: usize = 72;
    var it = std.mem.tokenizeAny(u8, text, " \t\r\n");
    var col: usize = 0;
    var line_open = false;
    while (it.next()) |word| {
        if (!line_open or col + 1 + word.len > limit) {
            try out.appendSlice(allocator, "\n        //");
            col = 0;
            line_open = true;
        }
        try out.appendSlice(allocator, " ");
        try out.appendSlice(allocator, word);
        col += 1 + word.len;
    }
}

/// Insert `block` immediately after the array-opening `[` that follows `marker`.
/// Returns null when the marker/bracket isn't found.
pub fn insertIntoArray(allocator: std.mem.Allocator, source: []const u8, marker: []const u8, block: []const u8) !?[]const u8 {
    const mpos = std.mem.indexOf(u8, source, marker) orelse return null;
    const open_rel = std.mem.indexOfScalarPos(u8, source, mpos + marker.len, '[') orelse return null;
    const at = open_rel + 1; // just after '['

    var out: std.ArrayList(u8) = .empty;
    try out.appendSlice(allocator, source[0..at]);
    try out.appendSlice(allocator, block);
    try out.appendSlice(allocator, source[at..]);
    return try out.toOwnedSlice(allocator);
}

// ── Support helpers (plugin Support/helpers.php — function files) ──────────────
//
// A plugin may ship a `Support/helpers.php` full of global functions. Those are
// NOT PSR-4 autoloaded (composer only autoloads classes), so enabling the plugin
// also wires a managed `require_once` into the bootstrap. Each require is tagged
// with `[hkm-support:<Folder>]` so disable can find and remove exactly its line.
// `[psp-support:` is the pre-rename spelling, still recognised: the marker is
// written INTO a project and every project wired before the rename carries it.

pub const support_tag_open = "[hkm-support:";

/// The pre-rename marker. Read, never written.
pub const support_tag_open_legacy = "[psp-support:";

/// The exact managed comment tag for `folder`, e.g. `[hkm-support:Cookie]`.
pub fn supportTag(allocator: std.mem.Allocator, folder: []const u8) ![]const u8 {
    return std.fmt.allocPrint(allocator, "{s}{s}]", .{ support_tag_open, folder });
}

/// Is `folder`'s Support helpers require ACTUALLY present?
///
/// The marker comment alone is not enough. It tracks ownership, not presence,
/// and the two come apart the moment someone deletes the require line while
/// debugging and leaves the comment above it — after which every check keyed on
/// the marker reports the helpers as wired while PHP fatals on the first call
/// to one of them. Requiring the `require_once` itself makes the check say what
/// it claims to say.
pub fn supportRequireWired(allocator: std.mem.Allocator, source: []const u8, folder: []const u8, expr: []const u8) bool {
    const tag = supportTag(allocator, folder) catch return false;
    if (std.mem.indexOf(u8, source, tag) == null) {
        const legacy = std.fmt.allocPrint(allocator, "{s}{s}]", .{ support_tag_open_legacy, folder }) catch return false;
        if (std.mem.indexOf(u8, source, legacy) == null) return false;
    }

    // The expression identifies the file; a require_once naming it is the wiring.
    if (expr.len > 0) {
        if (std.mem.indexOf(u8, source, expr)) |at| {
            const before = source[0..at];
            if (std.mem.lastIndexOf(u8, before, "require_once") != null) return true;
        }
        return false;
    }
    return true;
}

/// Insert a managed `require_once <expr>` for `folder`'s Support/helpers.php after
/// the autoload call in the bootstrap. `expr` is the PHP expression that follows
/// `require_once ` (including its trailing `;`). Idempotent — returns the source
/// unchanged (same slice) when the plugin's require is already present, so callers
/// can compare pointers to detect a no-op.
pub fn insertSupportRequire(allocator: std.mem.Allocator, source: []const u8, folder: []const u8, expr: []const u8) ![]const u8 {
    if (supportRequireWired(allocator, source, folder, expr)) return source;

    const tag = try supportTag(allocator, folder);
    const block = try std.fmt.allocPrint(
        allocator,
        "\n// {s} Support helpers — managed by `hkm plugins`\nrequire_once {s}",
        .{ tag, expr },
    );

    // Anchor after the autoload call; fall back to the strict_types declaration,
    // else the very top of the file.
    // Both spellings. Projects generated after the rename call
    // hkm_require_kernel_autoload(); everything generated before calls the psp_
    // name, and those bootstraps are on disk in every existing project. Looking
    // for one name only would silently miss the anchor in half of them and
    // splice the require at the top of the file instead — before the autoload
    // it depends on.
    const anchor = if (std.mem.indexOf(u8, source, "hkm_require_kernel_autoload();")) |p|
        p
    else if (std.mem.indexOf(u8, source, "psp_require_kernel_autoload();")) |p|
        p
    else if (std.mem.indexOf(u8, source, "declare(strict_types=1);")) |p|
        p
    else
        @as(usize, 0);
    const at = std.mem.indexOfScalarPos(u8, source, anchor, '\n') orelse source.len;

    var out: std.ArrayList(u8) = .empty;
    try out.appendSlice(allocator, source[0..at]);
    try out.appendSlice(allocator, block);
    try out.appendSlice(allocator, source[at..]);
    return out.toOwnedSlice(allocator);
}

/// Remove the managed Support-helpers require for `folder`: the tagged comment
/// line and the `require_once` line immediately below it. A no-op (empty
/// `removed`) when the plugin has no wired require.
pub fn removeSupportRequire(allocator: std.mem.Allocator, source: []const u8, folder: []const u8) !RemoveResult {
    const tag = try supportTag(allocator, folder);

    var lines: std.ArrayList([]const u8) = .empty;
    var it = std.mem.splitScalar(u8, source, '\n');
    while (it.next()) |l| try lines.append(allocator, l);

    var drop = try allocator.alloc(bool, lines.items.len);
    @memset(drop, false);

    for (lines.items, 0..) |l, idx| {
        if (std.mem.indexOf(u8, l, tag) == null) continue;
        drop[idx] = true;
        if (idx + 1 < lines.items.len) {
            const nt = std.mem.trim(u8, lines.items[idx + 1], " \t\r");
            if (std.mem.startsWith(u8, nt, "require_once")) drop[idx + 1] = true;
        }
    }

    var out: std.ArrayList(u8) = .empty;
    var removed: std.ArrayList([]const u8) = .empty;
    var first = true;
    for (lines.items, 0..) |l, idx| {
        if (drop[idx]) {
            try removed.append(allocator, l);
            continue;
        }
        if (!first) try out.appendSlice(allocator, "\n");
        try out.appendSlice(allocator, l);
        first = false;
    }
    return .{ .text = try out.toOwnedSlice(allocator), .removed = try removed.toOwnedSlice(allocator) };
}

pub const RemoveResult = struct { text: []const u8, removed: []const []const u8 };

/// Remove the array entry whose token is `token`, its preceding contiguous
/// `//` comment lines, and (when an alias) its `use ... as Alias;` import.
pub fn removeFromArray(allocator: std.mem.Allocator, source: []const u8, token: []const u8, aliases: []const Alias) !RemoveResult {
    const entry = try std.fmt.allocPrint(allocator, "{s}::class", .{token});

    var alias_import: ?[]const u8 = null;
    if (pluginFromToken(token) == null) {
        for (aliases) |a| {
            if (std.mem.eql(u8, a.alias, token)) {
                alias_import = try std.fmt.allocPrint(allocator, " as {s};", .{token});
                break;
            }
        }
    }

    var lines: std.ArrayList([]const u8) = .empty;
    var it = std.mem.splitScalar(u8, source, '\n');
    while (it.next()) |l| try lines.append(allocator, l);

    var drop = try allocator.alloc(bool, lines.items.len);
    @memset(drop, false);

    for (lines.items, 0..) |l, idx| {
        const t = std.mem.trim(u8, l, " \t\r");
        if (std.mem.startsWith(u8, t, entry)) {
            drop[idx] = true;
            var j = idx;
            while (j > 0) {
                const pt = std.mem.trim(u8, lines.items[j - 1], " \t\r");
                if (std.mem.startsWith(u8, pt, "//")) {
                    drop[j - 1] = true;
                    j -= 1;
                } else break;
            }
        }
        if (alias_import) |imp| {
            if (std.mem.startsWith(u8, t, "use ") and std.mem.endsWith(u8, t, imp)) drop[idx] = true;
        }
    }

    var out: std.ArrayList(u8) = .empty;
    var removed: std.ArrayList([]const u8) = .empty;
    var first = true;
    for (lines.items, 0..) |l, idx| {
        if (drop[idx]) {
            try removed.append(allocator, l);
            continue;
        }
        if (!first) try out.appendSlice(allocator, "\n");
        try out.appendSlice(allocator, l);
        first = false;
    }
    return .{ .text = try out.toOwnedSlice(allocator), .removed = try removed.toOwnedSlice(allocator) };
}

test "a commented-out provider is not enabled" {
    const a = std.testing.allocator;
    const src =
        \\<?php
        \\return Kernel::configure()
        \\    ->withModules([
        \\        \Plugins\Logger\Provider::class,
        \\        // Identity stack (enable together in an app that needs accounts):
        \\        //   \Plugins\User\Provider::class,
        \\        //   \Plugins\Auth\Provider::class,
        \\        \Plugins\View\Provider::class,   // still enabled — the // is AFTER it
        \\    ])
        \\    ->build();
    ;

    var aliases: std.ArrayList(Alias) = .empty;
    defer aliases.deinit(a);
    var out: std.ArrayList(Enabled) = .empty;
    defer out.deinit(a);
    try collectEnabled(a, src, aliases.items, &out);

    try std.testing.expectEqual(@as(usize, 2), out.items.len);
    try std.testing.expect(isEnabled(out.items, "Logger"));
    try std.testing.expect(isEnabled(out.items, "View"));
    try std.testing.expect(!isEnabled(out.items, "User"));
    try std.testing.expect(!isEnabled(out.items, "Auth"));
}
