//! Minimal Laravel-Prompts-style terminal UI: a left gutter bar, diamond step
//! glyphs, colour accents, and styled intro / note / outro / error / prompt
//! helpers. Text/confirm prompts read a line; `select` is an interactive
//! raw-mode arrow-key list. The look matches the modern "prompts" experience.

const std = @import("std");
const Io = std.Io;

// ── ANSI ──────────────────────────────────────────────────────────────────

const reset = "\x1b[0m";
const dim = "\x1b[2m";
const bold = "\x1b[1m";
const green = "\x1b[32m";
const cyan = "\x1b[36m";
const yellow = "\x1b[33m";
const red = "\x1b[31m";
const gray = "\x1b[90m";

// ── glyphs ──────────────────────────────────────────────────────────────────

const bar = dim ++ "│" ++ reset;
const diamond_active = cyan ++ "◆" ++ reset;
const diamond_done = green ++ "◇" ++ reset;
const corner_top = green ++ "┌" ++ reset;
const corner_bot = green ++ "└" ++ reset;

/// Opening banner: `┌  <title>` then a gutter line.
pub fn intro(title: []const u8) void {
    std.debug.print("\n" ++ corner_top ++ "  " ++ bold ++ "{s}" ++ reset ++ "\n" ++ bar ++ "\n", .{title});
}

/// Closing banner: a gutter line then `└  <message>` in green.
pub fn outro(message: []const u8) void {
    std.debug.print(bar ++ "\n" ++ corner_bot ++ "  " ++ green ++ "{s}" ++ reset ++ "\n\n", .{message});
}

/// A plain line under the gutter.
pub fn note(line: []const u8) void {
    std.debug.print(bar ++ "  {s}\n", .{line});
}

/// A success note (green check).
pub fn ok(line: []const u8) void {
    std.debug.print(bar ++ "  " ++ green ++ "✓" ++ reset ++ " {s}\n", .{line});
}

/// An informational/secondary note (dimmed).
pub fn muted(line: []const u8) void {
    std.debug.print(bar ++ "  " ++ gray ++ "{s}" ++ reset ++ "\n", .{line});
}

/// A warning note (yellow).
pub fn warn(line: []const u8) void {
    std.debug.print(bar ++ "  " ++ yellow ++ "▲ {s}" ++ reset ++ "\n", .{line});
}

/// An empty gutter line — vertical spacing inside a help/prompt block.
pub fn blank() void {
    std.debug.print(bar ++ "\n", .{});
}

/// A bold section heading under the gutter (e.g. "Usage", "Options").
pub fn section(title: []const u8) void {
    std.debug.print(bar ++ "  " ++ bold ++ "{s}" ++ reset ++ "\n", .{title});
}

/// A two-column help row: a cyan key padded to 30 cols, then a dimmed
/// description. Use for usage lines, flags, env vars, and examples.
pub fn item(key: []const u8, desc: []const u8) void {
    // A key longer than the column still needs a gap before its description.
    // Without one, every long usage line in `--help` read as one run-on word:
    // "hkm plugins enable <plugin> [proj]wire a plugin into the project".
    if (key.len >= 30) {
        std.debug.print(
            bar ++ "  " ++ cyan ++ "{s}" ++ reset ++ "  " ++ gray ++ "{s}" ++ reset ++ "\n",
            .{ key, desc },
        );
        return;
    }
    std.debug.print(
        bar ++ "  " ++ cyan ++ "{s: <30}" ++ reset ++ gray ++ "{s}" ++ reset ++ "\n",
        .{ key, desc },
    );
}

/// A standalone error block (red), for fatal failures.
pub fn err(message: []const u8) void {
    std.debug.print("\n" ++ red ++ "■  {s}" ++ reset ++ "\n\n", .{message});
}

/// Free-text prompt. Renders `◆  <label> [default]`, reads a line on the gutter,
/// and returns the entry (or `default` when the line is empty). The result is
/// always heap-duped so the caller owns it.
pub fn text(allocator: std.mem.Allocator, io: Io, label: []const u8, default: []const u8) ![]const u8 {
    if (default.len > 0) {
        std.debug.print(diamond_active ++ "  " ++ bold ++ "{s}" ++ reset ++ " " ++ dim ++ "[{s}]" ++ reset ++ "\n", .{ label, default });
    } else {
        std.debug.print(diamond_active ++ "  " ++ bold ++ "{s}" ++ reset ++ "\n", .{label});
    }
    std.debug.print(bar ++ "  " ++ cyan, .{});

    var buf: [4096]u8 = undefined;
    const line = readLine(io, &buf);
    std.debug.print(reset ++ bar ++ "\n", .{});

    const trimmed = std.mem.trim(u8, line, " \t\r\n");
    return allocator.dupe(u8, if (trimmed.len == 0) default else trimmed);
}

/// Yes/No prompt. Renders `◆  <label> [Y/n]` (or `[y/N]`) and parses the answer,
/// falling back to `default_yes` on empty/unrecognised input.
pub fn confirm(io: Io, label: []const u8, default_yes: bool) bool {
    const hint = if (default_yes) "[Y/n]" else "[y/N]";
    std.debug.print(diamond_active ++ "  " ++ bold ++ "{s}" ++ reset ++ " " ++ dim ++ "{s}" ++ reset ++ "\n", .{ label, hint });
    std.debug.print(bar ++ "  " ++ cyan, .{});

    var buf: [64]u8 = undefined;
    const line = readLine(io, &buf);
    std.debug.print(reset ++ bar ++ "\n", .{});

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

    std.debug.print(diamond_active ++ "  " ++ bold ++ "{s}" ++ reset ++ "\n", .{label});
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
            '\r', '\n' => { std.debug.print(bar ++ "\n", .{}); return cur; },
            'q', 0x1b, 3, 4 => return null, // q / Esc / Ctrl+C / Ctrl+D
            else => {},
        }

        if (moved) {
            std.debug.print("\x1b[{d}A", .{items.len}); // cursor up to redraw in place
            drawOptions(items, cur);
        }
    }
}

/// Render the option rows for `select`, highlighting index `cur`. Each line is
/// cleared first (\x1b[2K) so in-place redraws don't leave artifacts.
fn drawOptions(items: []const []const u8, cur: usize) void {
    for (items, 0..) |it, i| {
        if (i == cur) {
            std.debug.print("\x1b[2K" ++ bar ++ "  " ++ cyan ++ "❯ " ++ bold ++ "{s}" ++ reset ++ "\n", .{it});
        } else {
            std.debug.print("\x1b[2K" ++ bar ++ "    " ++ dim ++ "{s}" ++ reset ++ "\n", .{it});
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
    const cols = termCols();
    const overhead = (ncol + 1) + 2 * ncol;
    const avail = if (cols > gutter_cols + overhead) cols - gutter_cols - overhead else 0;
    if (avail > 0) shrinkToFit(widths, avail);

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
    std.debug.print("{s}\n", .{line.items});
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
