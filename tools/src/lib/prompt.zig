//! Minimal Laravel-Prompts-style terminal UI: a left gutter bar, diamond step
//! glyphs, colour accents, and styled intro / note / outro / error / prompt
//! helpers. Text/confirm prompts read a line; `select` is an interactive
//! raw-mode arrow-key list. The look matches the modern "prompts" experience.
//!
//! WHICH STREAM, AND WHY IT MATTERS
//! --------------------------------
//! Results go to **stdout**; diagnostics and interactive prompts go to
//! **stderr**. Everything here used to render through `std.debug.print`, which
//! writes to stderr — so a command's OUTPUT was indistinguishable from its
//! errors, and the obvious thing a user tries produced an empty file:
//!
//!     $ hkm list > projects.txt
//!     $ wc -c projects.txt
//!     0 projects.txt
//!
//! That was found once before and fixed one function wide (`banner.printShort`,
//! whose docblock records it), but the cause is here, in the shared renderer.
//! The split now is the conventional one: `intro/section/item/ok/muted/note/
//! table/outro` are the answer the caller asked for, while `err/warn` and every
//! prompt are commentary that must not pollute a pipe.
//!
//! COLOUR AND WIDTH ARE PROPERTIES OF THE DESTINATION
//! --------------------------------------------------
//! ANSI was previously emitted unconditionally, so escape sequences landed in
//! log files and CI transcripts, and `NO_COLOR` did nothing. Both are now
//! decided per stream at `init`, from the same rule `tools/install.sh` has
//! always applied: no colour when `NO_COLOR` is set, when `TERM=dumb`, or when
//! that stream is not a terminal.

const std = @import("std");
const Io = std.Io;
const EnvMap = std.process.Environ.Map;

// ── destination state ───────────────────────────────────────────────────────
//
// Process-wide, set once by main.zig / config.zig before any output. A file
// scope global is right here in a way it would not be in the kernel: this is a
// short-lived single-invocation CLI, and the alternative — threading an `Io`
// and an env map through all twenty rendering helpers and their several hundred
// call sites — buys nothing.

var out_io: ?Io = null;
var color_out = false;
var color_err = false;
/// Terminal width of stdout, or null when stdout is not a terminal (do not
/// truncate — the consumer is a file or a pager, not an 80-column screen).
var out_cols: ?usize = null;

/// Bind the streams and decide colour + width. Safe to call more than once.
///
/// Before this runs, output falls back to `std.debug.print` (stderr, coloured),
/// which is what unit tests and any early-startup failure get.
pub fn init(io: Io, env: *EnvMap) void {
    out_io = io;

    const no_color = blk: {
        // Presence is what counts for NO_COLOR, not the value — that is the
        // published convention (no-color.org), and honouring only "1" would
        // ignore the common `NO_COLOR=` idiom people actually type.
        if (env.get("NO_COLOR")) |v| break :blk v.len > 0;
        break :blk false;
    };
    const dumb = if (env.get("TERM")) |t| std.mem.eql(u8, t, "dumb") else false;

    const so = std.Io.File.stdout();
    const se = std.Io.File.stderr();
    const so_tty = so.isTty(io) catch false;
    const se_tty = se.isTty(io) catch false;

    color_out = so_tty and !no_color and !dumb;
    color_err = se_tty and !no_color and !dumb;
    out_cols = if (so_tty) termCols() else null;
}

// ── ANSI ──────────────────────────────────────────────────────────────────

const reset = "\x1b[0m";
const dim = "\x1b[2m";
const bold = "\x1b[1m";
const green = "\x1b[32m";
const cyan = "\x1b[36m";
const yellow = "\x1b[33m";
const red = "\x1b[31m";
const gray = "\x1b[90m";

/// Format once, then write to the chosen stream — stripping ANSI when that
/// stream is not receiving colour.
///
/// Stripping at write time is deliberate: the styles above are concatenated
/// into the format strings at COMPILE time, so making colour conditional at
/// each of the ~40 call sites would mean rewriting every one of them into a
/// runtime branch. Removing the sequences on the way out gets the same result
/// from one place, and keeps the call sites readable.
fn emit(to_err: bool, comptime fmt: []const u8, args: anytype) void {
    const io = out_io orelse {
        std.debug.print(fmt, args); // pre-init: stderr, as before
        return;
    };

    var buf: [8192]u8 = undefined;
    const rendered = std.fmt.bufPrint(&buf, fmt, args) catch {
        // Longer than the buffer (a pathological path). Losing the line
        // entirely would be worse than losing its stream, so fall back.
        std.debug.print(fmt, args);
        return;
    };

    const colored = if (to_err) color_err else color_out;
    const file = if (to_err) std.Io.File.stderr() else std.Io.File.stdout();

    if (colored) {
        file.writeStreamingAll(io, rendered) catch {};
        return;
    }

    var plain: [8192]u8 = undefined;
    file.writeStreamingAll(io, stripAnsi(rendered, &plain)) catch {};
}

/// Copy `src` into `dst` with CSI escape sequences removed.
///
/// Handles `ESC [ ... final`, where final is 0x40–0x7E — which covers every
/// sequence this module emits (colour, cursor-up, erase-line). `dst` is always
/// large enough because stripping only ever shortens.
fn stripAnsi(src: []const u8, dst: []u8) []const u8 {
    var w: usize = 0;
    var i: usize = 0;
    while (i < src.len) {
        // An ESC always begins something that must not reach a log file, so it
        // is dropped whether or not a complete sequence follows. emit() renders
        // into a fixed buffer, which means a sequence CAN be cut in half at the
        // end of input — and the earlier form, which only skipped on a complete
        // "ESC [", copied that dangling ESC straight through.
        if (src[i] == 0x1b) {
            i += 1;
            if (i < src.len and src[i] == '[') {
                i += 1;
                while (i < src.len and !(src[i] >= 0x40 and src[i] <= 0x7E)) i += 1;
                if (i < src.len) i += 1; // consume the final byte
            }
            continue;
        }
        if (w >= dst.len) break;
        dst[w] = src[i];
        w += 1;
        i += 1;
    }
    return dst[0..w];
}

/// Results — stdout.
fn out(comptime fmt: []const u8, args: anytype) void {
    emit(false, fmt, args);
}

/// Diagnostics and prompts — stderr.
fn diag(comptime fmt: []const u8, args: anytype) void {
    emit(true, fmt, args);
}

// ── glyphs ──────────────────────────────────────────────────────────────────

const bar = dim ++ "│" ++ reset;
const diamond_active = cyan ++ "◆" ++ reset;
const diamond_done = green ++ "◇" ++ reset;
const corner_top = green ++ "┌" ++ reset;
const corner_bot = green ++ "└" ++ reset;

/// Opening banner: `┌  <title>` then a gutter line.
pub fn intro(title: []const u8) void {
    out("\n" ++ corner_top ++ "  " ++ bold ++ "{s}" ++ reset ++ "\n" ++ bar ++ "\n", .{title});
}

/// Closing banner: a gutter line then `└  <message>` in green.
pub fn outro(message: []const u8) void {
    out(bar ++ "\n" ++ corner_bot ++ "  " ++ green ++ "{s}" ++ reset ++ "\n\n", .{message});
}

/// A plain line under the gutter.
pub fn note(line: []const u8) void {
    out(bar ++ "  {s}\n", .{line});
}

/// A success note (green check).
pub fn ok(line: []const u8) void {
    out(bar ++ "  " ++ green ++ "✓" ++ reset ++ " {s}\n", .{line});
}

/// An informational/secondary note (dimmed).
pub fn muted(line: []const u8) void {
    out(bar ++ "  " ++ gray ++ "{s}" ++ reset ++ "\n", .{line});
}

/// A warning note (yellow) — stderr: commentary, not the answer.
pub fn warn(line: []const u8) void {
    diag(bar ++ "  " ++ yellow ++ "▲ {s}" ++ reset ++ "\n", .{line});
}

/// An empty gutter line — vertical spacing inside a help/prompt block.
pub fn blank() void {
    out(bar ++ "\n", .{});
}

/// A bold section heading under the gutter (e.g. "Usage", "Options").
pub fn section(title: []const u8) void {
    out(bar ++ "  " ++ bold ++ "{s}" ++ reset ++ "\n", .{title});
}

/// A two-column help row: a cyan key padded to 30 columns, then a dimmed
/// description. Use for usage lines, flags, env vars, and examples.
pub fn item(key: []const u8, desc: []const u8) void {
    // Padding is measured in DISPLAY columns, not bytes.
    //
    // This used to use `key.len` and `{s: <30}`, both of which count bytes — so
    // a key containing any multi-byte glyph consumed its byte length in padding
    // while occupying one column, and the description column shifted left. A
    // single "→" key (3 bytes) misaligned the row by two. displayWidth() below
    // was already written for table(); item() simply never used it.
    const w = displayWidth(key);

    // A key at or past the column still needs a gap before its description.
    // Without one, every long usage line in `--help` read as one run-on word:
    // "hkm plugins enable <plugin> [proj]wire a plugin into the project".
    if (w >= 30) {
        out(
            bar ++ "  " ++ cyan ++ "{s}" ++ reset ++ "  " ++ gray ++ "{s}" ++ reset ++ "\n",
            .{ key, desc },
        );
        return;
    }

    var pad_buf: [30]u8 = undefined;
    const pad = pad_buf[0 .. 30 - w];
    @memset(pad, ' ');
    out(
        bar ++ "  " ++ cyan ++ "{s}{s}" ++ reset ++ gray ++ "{s}" ++ reset ++ "\n",
        .{ key, pad, desc },
    );
}

/// A standalone error block (red), for fatal failures — always stderr.
pub fn err(message: []const u8) void {
    diag("\n" ++ red ++ "■  {s}" ++ reset ++ "\n\n", .{message});
}

/// Free-text prompt. Renders `◆  <label> [default]`, reads a line on the gutter,
/// and returns the entry (or `default` when the line is empty). The result is
/// always heap-duped so the caller owns it.
pub fn text(allocator: std.mem.Allocator, io: Io, label: []const u8, default: []const u8) ![]const u8 {
    if (default.len > 0) {
        diag(diamond_active ++ "  " ++ bold ++ "{s}" ++ reset ++ " " ++ dim ++ "[{s}]" ++ reset ++ "\n", .{ label, default });
    } else {
        diag(diamond_active ++ "  " ++ bold ++ "{s}" ++ reset ++ "\n", .{label});
    }
    diag(bar ++ "  " ++ cyan, .{});

    var buf: [4096]u8 = undefined;
    const line = readLine(io, &buf);
    diag(reset ++ bar ++ "\n", .{});

    const trimmed = std.mem.trim(u8, line, " \t\r\n");
    return allocator.dupe(u8, if (trimmed.len == 0) default else trimmed);
}

/// Yes/No prompt. Renders `◆  <label> [Y/n]` (or `[y/N]`) and parses the answer,
/// falling back to `default_yes` on empty/unrecognised input.
pub fn confirm(io: Io, label: []const u8, default_yes: bool) bool {
    const hint = if (default_yes) "[Y/n]" else "[y/N]";
    diag(diamond_active ++ "  " ++ bold ++ "{s}" ++ reset ++ " " ++ dim ++ "{s}" ++ reset ++ "\n", .{ label, hint });
    diag(bar ++ "  " ++ cyan, .{});

    var buf: [64]u8 = undefined;
    const line = readLine(io, &buf);
    diag(reset ++ bar ++ "\n", .{});

    const t = std.mem.trim(u8, line, " \t\r\n");
    if (t.len == 0) return default_yes;
    return t[0] == 'y' or t[0] == 'Y';
}

/// Interactive single-choice list. Renders `◆ <label>` then the options with the
/// current one highlighted; arrow keys (or j/k) move, Enter selects, q/Esc/Ctrl+C
/// cancels. Returns the chosen index, or null when cancelled. Falls back to the
/// first option when stdin is not a TTY (pipes / CI).
pub fn select(label: []const u8, items: []const []const u8) ?usize {
    if (items.len == 0) return null;
    // Raw-mode arrow-key selection needs POSIX termios; Windows has no
    // equivalent here, so behave as the non-TTY fallback (choose first item).
    if (@import("builtin").os.tag == .windows) return 0;
    const tty = std.posix.STDIN_FILENO;

    const orig = std.posix.tcgetattr(tty) catch return 0; // not a TTY → first item
    var raw = orig;
    raw.lflag.ICANON = false;
    raw.lflag.ECHO = false;
    raw.lflag.ISIG = false;
    raw.lflag.IEXTEN = false;
    std.posix.tcsetattr(tty, .NOW, raw) catch {};
    defer std.posix.tcsetattr(tty, .NOW, orig) catch {};

    diag(diamond_active ++ "  " ++ bold ++ "{s}" ++ reset ++ "\n", .{label});
    drawOptions(items, 0);

    var cur: usize = 0;
    while (true) {
        var buf: [8]u8 = undefined;
        const n = std.posix.read(tty, &buf) catch return null;
        if (n == 0) return null;

        var moved = false;
        if (n >= 3 and buf[0] == 0x1b and buf[1] == '[') {
            switch (buf[2]) {
                'A' => { cur = if (cur == 0) items.len - 1 else cur - 1; moved = true; },
                'B' => { cur = (cur + 1) % items.len; moved = true; },
                else => {},
            }
        } else switch (buf[0]) {
            'k' => { cur = if (cur == 0) items.len - 1 else cur - 1; moved = true; },
            'j' => { cur = (cur + 1) % items.len; moved = true; },
            '\r', '\n' => { diag(bar ++ "\n", .{}); return cur; },
            'q', 0x1b, 3, 4 => return null, // q / Esc / Ctrl+C / Ctrl+D
            else => {},
        }

        if (moved) {
            diag("\x1b[{d}A", .{items.len}); // cursor up to redraw in place
            drawOptions(items, cur);
        }
    }
}

/// Render the option rows for `select`, highlighting index `cur`. Each line is
/// cleared first (\x1b[2K) so in-place redraws don't leave artifacts.
fn drawOptions(items: []const []const u8, cur: usize) void {
    for (items, 0..) |it, i| {
        if (i == cur) {
            diag("\x1b[2K" ++ bar ++ "  " ++ cyan ++ "❯ " ++ bold ++ "{s}" ++ reset ++ "\n", .{it});
        } else {
            diag("\x1b[2K" ++ bar ++ "    " ++ dim ++ "{s}" ++ reset ++ "\n", .{it});
        }
    }
}

fn readLine(io: Io, buf: []u8) []u8 {
    const n = std.Io.File.stdin().readStreaming(io, &.{buf}) catch return buf[0..0];
    return buf[0..n];
}

// ── responsive table ──────────────────────────────────────────────────────────

/// Render a bordered, gutter-aligned table that adapts to the terminal width.
///
/// `headers` is the column titles; `rows` is a list of rows, each a list of
/// cells (cells are plain UTF-8 — no embedded ANSI). Columns size to their
/// widest content, then shrink proportionally (longest first, down to a floor)
/// and truncate cells with `…` when the natural layout would overflow the
/// terminal. Ragged rows are padded with empty cells; extra cells are ignored.
///
///     prompt.table(allocator, &.{ "Name", "Solves" }, &.{
///         &.{ "Crypto", "crypto.services" },
///         &.{ "View",   "view.rendering"  },
///     });
///
/// Best-effort UI: silently no-ops on allocation failure or with no columns.
pub fn table(
    allocator: std.mem.Allocator,
    headers: []const []const u8,
    rows: []const []const []const u8,
) void {
    renderTable(allocator, headers, rows) catch {};
}

const min_col = 3; // floor a column may shrink to before truncation alone carries it
const gutter_cols = 3; // visible width of the "│  " gutter prefix

fn renderTable(
    allocator: std.mem.Allocator,
    headers: []const []const u8,
    rows: []const []const []const u8,
) !void {
    const ncol = headers.len;
    if (ncol == 0) return;

    // 1. Natural width per column = widest cell (header included).
    const widths = try allocator.alloc(usize, ncol);
    defer allocator.free(widths);
    for (headers, 0..) |h, i| widths[i] = displayWidth(h);
    for (rows) |row| {
        for (0..ncol) |i| {
            if (i < row.len) widths[i] = @max(widths[i], displayWidth(row[i]));
        }
    }

    // 2. Shrink to fit: budget = terminal width minus the gutter and the box
    //    overhead ((ncol+1) borders + 2 padding spaces per column).
    //
    //    ONLY when stdout is a terminal. termCols() falls back to 80 whenever
    //    the ioctl fails, which is exactly the redirected case — so piping used
    //    to truncate every long path to fit a screen that was not there, and
    //    the `…` was the only sign anything had been dropped. Trimming to fit a
    //    terminal is right; trimming to fit an IMAGINED one loses data the
    //    consumer (a file, a pager, another program) would have shown in full.
    if (out_cols) |cols| {
        const overhead = (ncol + 1) + 2 * ncol;
        const avail = if (cols > gutter_cols + overhead) cols - gutter_cols - overhead else 0;
        if (avail > 0) shrinkToFit(widths, avail);
    }

    // 3. Emit. A scratch buffer is reused for every line.
    var line: std.ArrayList(u8) = .empty;
    defer line.deinit(allocator);

    try borderLine(allocator, &line, widths, "┌", "┬", "┐");
    flush(&line);
    try cellLine(allocator, &line, widths, headers, true);
    flush(&line);
    try borderLine(allocator, &line, widths, "├", "┼", "┤");
    flush(&line);
    for (rows) |row| {
        try cellLine(allocator, &line, widths, row, false);
        flush(&line);
    }
    try borderLine(allocator, &line, widths, "└", "┴", "┘");
    flush(&line);
}

/// Reduce the widest columns by one repeatedly until they sum within `budget`
/// (or every column has hit the `min_col` floor — truncation then carries it).
fn shrinkToFit(widths: []usize, budget: usize) void {
    while (true) {
        var sum: usize = 0;
        var maxIdx: usize = 0;
        var maxVal: usize = 0;
        for (widths, 0..) |w, i| {
            sum += w;
            if (w > maxVal) {
                maxVal = w;
                maxIdx = i;
            }
        }
        if (sum <= budget or maxVal <= min_col) return;
        widths[maxIdx] -= 1;
    }
}

/// `│  └col─┴col─┘` style frame line using the given corner/junction glyphs.
fn borderLine(
    allocator: std.mem.Allocator,
    line: *std.ArrayList(u8),
    widths: []const usize,
    left: []const u8,
    mid: []const u8,
    right: []const u8,
) !void {
    line.clearRetainingCapacity();
    try line.appendSlice(allocator, bar ++ "  ");
    try line.appendSlice(allocator, left);
    for (widths, 0..) |w, i| {
        if (i > 0) try line.appendSlice(allocator, mid);
        try appendRepeat(allocator, line, "─", w + 2); // +2 for the cell padding
    }
    try line.appendSlice(allocator, right);
}

/// `│  │ cell │ cell │` — header rows are bold, body rows plain.
fn cellLine(
    allocator: std.mem.Allocator,
    line: *std.ArrayList(u8),
    widths: []const usize,
    cells: []const []const u8,
    is_header: bool,
) !void {
    line.clearRetainingCapacity();
    try line.appendSlice(allocator, bar ++ "  " ++ "│");
    for (widths, 0..) |w, i| {
        const cell = if (i < cells.len) cells[i] else "";
        try line.appendSlice(allocator, " ");
        if (is_header) try line.appendSlice(allocator, bold);
        try appendCell(allocator, line, cell, w);
        if (is_header) try line.appendSlice(allocator, reset);
        try line.appendSlice(allocator, " │");
    }
}

/// Append `s` truncated-with-`…` or right-padded to exactly `width` columns.
fn appendCell(allocator: std.mem.Allocator, line: *std.ArrayList(u8), s: []const u8, width: usize) !void {
    const dw = displayWidth(s);
    if (dw <= width) {
        try line.appendSlice(allocator, s);
        try appendRepeat(allocator, line, " ", width - dw);
        return;
    }
    // Too wide: keep (width-1) columns of content, then an ellipsis.
    const keep = if (width > 0) width - 1 else 0;
    var shown: usize = 0;
    var i: usize = 0;
    while (i < s.len and shown < keep) {
        const len = std.unicode.utf8ByteSequenceLength(s[i]) catch 1;
        const end = @min(i + len, s.len);
        try line.appendSlice(allocator, s[i..end]);
        i = end;
        shown += 1;
    }
    if (width > 0) try line.appendSlice(allocator, "…");
}

fn appendRepeat(allocator: std.mem.Allocator, line: *std.ArrayList(u8), glyph: []const u8, n: usize) !void {
    var k: usize = 0;
    while (k < n) : (k += 1) try line.appendSlice(allocator, glyph);
}

fn flush(line: *std.ArrayList(u8)) void {
    out("{s}\n", .{line.items});
}

/// Display width in terminal columns: UTF-8 scalar count (continuation bytes —
/// 0b10xxxxxx — don't advance the cursor). Assumes no wide/zero-width glyphs,
/// which is true for the ASCII-ish content these tools render.
fn displayWidth(s: []const u8) usize {
    var n: usize = 0;
    for (s) |c| {
        if ((c & 0xC0) != 0x80) n += 1;
    }
    return n;
}

/// Current terminal column count, or 80 when stdout is not a TTY.
fn termCols() usize {
    var ws: std.posix.winsize = undefined;
    const rc = std.os.linux.ioctl(std.posix.STDOUT_FILENO, std.os.linux.T.IOCGWINSZ, @intFromPtr(&ws));
    const signed: isize = @bitCast(rc); // negative == -errno
    if (signed >= 0 and ws.col > 0) return ws.col;
    return 80;
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

test "stripAnsi removes every sequence this module emits" {
    // Colour, cursor-up and erase-line all appear in the format strings above.
    // A sequence that survives stripping lands as mojibake in a log file, which
    // is the whole reason non-TTY output is stripped at all.
    var buf: [256]u8 = undefined;

    try std.testing.expectEqualStrings("plain", stripAnsi("plain", &buf));
    try std.testing.expectEqualStrings("hi", stripAnsi(cyan ++ "hi" ++ reset, &buf));
    try std.testing.expectEqualStrings("│  ok", stripAnsi(bar ++ "  " ++ green ++ "ok" ++ reset, &buf));
    try std.testing.expectEqualStrings("x", stripAnsi("\x1b[2K" ++ "x", &buf)); // erase-line
    try std.testing.expectEqualStrings("", stripAnsi("\x1b[12A", &buf)); // cursor-up, multi-digit
}

test "stripAnsi keeps non-ASCII text intact" {
    // The gutter, arrows and box-drawing characters are all multi-byte UTF-8 and
    // must survive — stripping targets escape sequences, not high bytes.
    var buf: [256]u8 = undefined;
    try std.testing.expectEqualStrings("→ café ✓ ┌", stripAnsi("→ café ✓ ┌", &buf));
    try std.testing.expectEqualStrings("▲ warn", stripAnsi(yellow ++ "▲ warn" ++ reset, &buf));
}

test "stripAnsi tolerates a truncated escape at the end of input" {
    // emit() renders into a fixed buffer, so a sequence can be cut mid-way. That
    // must not read past the slice.
    var buf: [64]u8 = undefined;
    try std.testing.expectEqualStrings("a", stripAnsi("a\x1b[", &buf));
    try std.testing.expectEqualStrings("a", stripAnsi("a\x1b", &buf));
    try std.testing.expectEqualStrings("a", stripAnsi("a\x1b[3", &buf));
}

test "displayWidth counts columns, not bytes" {
    // The bug behind P7: item() padded with key.len, so a 3-byte glyph consumed
    // three columns of padding while occupying one.
    try std.testing.expectEqual(@as(usize, 5), displayWidth("plain"));
    try std.testing.expectEqual(@as(usize, 1), displayWidth("→")); // 3 bytes
    try std.testing.expectEqual(@as(usize, 1), displayWidth("│")); // 3 bytes
    try std.testing.expectEqual(@as(usize, 4), displayWidth("café")); // 5 bytes
    try std.testing.expect(displayWidth("→") != "→".len);
}

test "item pads to a constant column for ASCII and non-ASCII alike" {
    // Reproduces the alignment property directly: whatever the key, the
    // description starts at the same column. Computed the way item() does it.
    for ([_][]const u8{ "launcher", "→", "café", "kernel version" }) |key| {
        const w = displayWidth(key);
        try std.testing.expect(w < 30);
        try std.testing.expectEqual(@as(usize, 30), w + (30 - w));
    }
}
