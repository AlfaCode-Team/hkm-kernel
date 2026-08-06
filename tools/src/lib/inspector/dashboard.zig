const std = @import("std");
const MemInspector = @import("meminspector.zig").MemInspector;

// ---- a small, tasteful color palette (ANSI escape codes) ----
const dim = "\x1b[38;5;245m"; // grey
const cyan = "\x1b[38;5;80m";
const green = "\x1b[38;5;114m";
const yellow = "\x1b[38;5;179m";
const red = "\x1b[38;5;203m";
const blue = "\x1b[38;5;110m";
const bold = "\x1b[1m";
const reset = "\x1b[0m";

const WIDTH = 46; // inner content width

fn p(comptime fmt: []const u8, args: anytype) void {
    std.debug.print(fmt, args);
}

/// Turn a byte count into a friendly string like "24.6 MB". Writes into `buf`.
fn human(buf: []u8, bytes: usize) []const u8 {
    const b: f64 = @floatFromInt(bytes);
    if (bytes < 1024)
        return std.fmt.bufPrint(buf, "{d} B", .{bytes}) catch "?";
    if (bytes < 1024 * 1024)
        return std.fmt.bufPrint(buf, "{d:.1} KB", .{b / 1024.0}) catch "?";
    if (bytes < 1024 * 1024 * 1024)
        return std.fmt.bufPrint(buf, "{d:.1} MB", .{b / (1024.0 * 1024.0)}) catch "?";
    return std.fmt.bufPrint(buf, "{d:.1} GB", .{b / (1024.0 * 1024.0 * 1024.0)}) catch "?";
}

/// Print a filled/empty bar of the given width for value/max.
fn bar(color: []const u8, value: usize, max: usize, width: usize) void {
    const ratio: f64 = if (max == 0) 0 else @as(f64, @floatFromInt(value)) / @as(f64, @floatFromInt(max));
    const filled: usize = @intFromFloat(ratio * @as(f64, @floatFromInt(width)));
    p("{s}", .{color});
    var i: usize = 0;
    while (i < width) : (i += 1) p("{s}", .{if (i < filled) "\u{2588}" else dim ++ "\u{2591}"});
    p("{s}", .{reset});
}

fn header(comptime title: []const u8) void {
    p("\n{s}{s}▸ {s}{s}\n", .{ bold, cyan, title, reset });
}

/// Print `text` centered inside WIDTH columns (used for the title box).
fn centerLine(color: []const u8, text: []const u8) void {
    const pad = if (text.len < WIDTH) (WIDTH - text.len) / 2 else 0;
    p("{s}│{s}", .{ blue, color });
    var i: usize = 0;
    while (i < pad) : (i += 1) p(" ", .{});
    p("{s}", .{text});
    var j: usize = pad + text.len;
    while (j < WIDTH) : (j += 1) p(" ", .{});
    p("{s}│{s}\n", .{ blue, reset });
}

/// Render the whole dashboard for the given inspector.
pub fn render(mi: *MemInspector, runtime: []const u8) void {
    // Take a consistent snapshot: block allocations while we read the state.
    mi.mutex.lock();
    defer mi.mutex.unlock();

    var b1: [32]u8 = undefined;
    var b2: [32]u8 = undefined;

    // ---- title ----
    var rt: [48]u8 = undefined;
    const rtline = std.fmt.bufPrint(&rt, "runtime · {s}", .{runtime}) catch "runtime";
    p("\n{s}{s}╭{s}╮{s}\n", .{ bold, blue, "─" ** WIDTH, reset });
    centerLine(bold, "HKM  MEMORY  INSPECTOR");
    centerLine(dim, rtline);
    p("{s}╰{s}╯{s}\n", .{ blue, "─" ** WIDTH, reset });

    // ---- overview ----
    header("MEMORY OVERVIEW");
    p("  {s}current{s}  ", .{ dim, reset });
    bar(green, mi.current, mi.peak, 22);
    p("  {s}{s}{s}\n", .{ bold, human(&b1, mi.current), reset });
    p("  {s}peak   {s}  ", .{ dim, reset });
    bar(yellow, mi.peak, mi.peak, 22);
    p("  {s}{s}{s}\n", .{ bold, human(&b2, mi.peak), reset });
    p("  {s}allocated {s}{s}   {s}freed {s}{s}\n", .{
        dim, human(&b1, mi.total_allocated), reset,
        dim, human(&b2, mi.total_freed),     reset,
    });

    // ---- stats ----
    header("ALLOCATION STATS");
    const active = mi.active.count();
    var avg: usize = 0;
    if (mi.alloc_count > 0) avg = mi.total_allocated / mi.alloc_count;
    p("  active {s}{d}{s}   allocs {s}{d}{s}   frees {s}{d}{s}\n", .{
        bold, active, reset, bold, mi.alloc_count, reset, bold, mi.free_count, reset,
    });
    p("  avg alloc {s}{s}{s}   largest {s}{s}{s}\n", .{
        bold, human(&b1, avg), reset, bold, human(&b2, mi.largest), reset,
    });

    // ---- groups ----
    //
    // Scaled by PEAK, not current. This dashboard is normally printed at exit,
    // by which point a well-behaved run has freed everything — so plotting
    // `current` drew every bar at zero and the panel said nothing. Peak answers
    // the question actually being asked: how much did this command cost?
    // `current` is still shown per row, because a non-zero value at exit is
    // precisely where a leak lives.
    header("MEMORY GROUPS");
    var gmax: usize = 1;
    var gi: usize = 0;
    while (gi < mi.group_count) : (gi += 1) {
        if (mi.groups[gi].peak > gmax) gmax = mi.groups[gi].peak;
    }
    gi = 0;
    while (gi < mi.group_count) : (gi += 1) {
        const g = mi.groups[gi];
        if (g.allocs == 0) continue; // skip empty buckets
        p("  {s: <12}", .{g.name});
        bar(blue, g.peak, gmax, 18);
        p("  {s: >9}  {s}{d} alloc{s}{s}", .{
            human(&b1, g.peak),
            dim, g.allocs, if (g.allocs == 1) "" else "s", reset,
        });
        // Anything still held at report time is either a leak or memory that
        // outlives the report — call it out rather than hiding it.
        if (g.current > 0) {
            p("  {s}({s} still held){s}", .{ yellow, human(&b2, g.current), reset });
        }
        p("\n", .{});
    }

    // ---- leaks ----
    header("LEAK DETECTOR");
    if (active == 0) {
        p("  {s}✔ no leaks — everything was freed{s}\n", .{ green, reset });
    } else {
        p("  {s}⚠ {d} allocation(s) were never freed:{s}\n", .{ red, active, reset });
        var it = mi.active.iterator();
        var n: usize = 1;
        while (it.next()) |entry| : (n += 1) {
            var rec = entry.value_ptr.*;
            // one clear headline per leak...
            p("\n  {s}#{d}{s}  {s}{s} leaked{s}  {s}(group: {s}){s}\n", .{
                bold, n, reset,
                yellow ++ bold, human(&b1, rec.size), reset,
                dim, mi.groups[rec.group].name, reset,
            });
            // ...then the exact source location(s) where it was allocated.
            if (rec.trace_len > 0) {
                // show only the top 2 frames (alloc site + its caller); deeper
                // frames are usually runtime startup noise.
                const show = @min(rec.trace_len, 2);
                var st = std.debug.StackTrace{ .return_addresses = rec.trace[0..show], .skipped = .none };
                std.debug.dumpStackTrace(&st);
            } else {
                p("      {s}(location not captured — build in Debug mode){s}\n", .{ dim, reset });
            }
            if (n >= 5) break; // don't flood
        }
    }

    // ---- recent activity ----
    header("RECENT ACTIVITY");
    var k: usize = 0;
    while (k < mi.recent_len) : (k += 1) {
        const e = mi.recent[(mi.recent_head + k) % mi.recent.len];
        if (e.freed) {
            p("  {s}- {s: >9}{s}  {s}{s}{s}\n", .{ red, human(&b1, e.size), reset, dim, mi.groups[e.group].name, reset });
        } else {
            p("  {s}+ {s: >9}{s}  {s}{s}{s}\n", .{ green, human(&b1, e.size), reset, dim, mi.groups[e.group].name, reset });
        }
    }
    p("\n", .{});
}
