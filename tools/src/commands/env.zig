//! `hkm env` — audit and tidy a project's `.env`.
//!
//!   hkm env [path|name]            what is in it: duplicates, groups, orphans
//!   hkm env dedupe [path|name]     resolve duplicate keys, one prompt each
//!   hkm env group [path|name]      reorder it into blocks, by plugin then feature
//!
//! ## Why this exists
//!
//! A `.env` accumulates. A plugin seeds its block on enable, someone appends a
//! key at the bottom to try something, a second plugin declares a variable the
//! first one already did — and none of it is an error anywhere. The loader
//! resolves a repeated key silently, the boot succeeds, and the value in effect
//! is whichever line happens to be last. That is the failure this command is
//! for: not a file that is broken, a file that works and does not say what it
//! is doing.
//!
//! Which is also why `dedupe` asks instead of picking. The right survivor is
//! not derivable — `DB_HOST=localhost` on line 12 and `DB_HOST=10.0.0.4` on
//! line 88 are both plausible, and the one currently in effect is as likely to
//! be the accident as the intent. The command's job is to show which is live
//! and let the person who knows decide.
//!
//! Nothing is written without a `.env.bak` beside it.

const std = @import("std");
const prompt = @import("../lib/prompt.zig");
const util = @import("../lib/util.zig");
const services = @import("../lib/services.zig");
const envfile = @import("../lib/env_file.zig");
const plugin_env = @import("../lib/plugin_env.zig");

const Dir = std.Io.Dir;
const Io = std.Io;
const EnvMap = std.process.Environ.Map;

const Action = enum { audit, dedupe, group };

const Options = struct {
    action: Action = .audit,
    target: []const u8 = "",
    dry_run: bool = false,
    /// Non-interactive resolution: keep the first or the last occurrence.
    keep: ?Keep = null,
    help: bool = false,
};

const Keep = enum { first, last, effective };

fn parse(args: []const []const u8) Options {
    var o = Options{};
    var i: usize = 2;
    while (i < args.len) : (i += 1) {
        const a = args[i];
        if (std.mem.eql(u8, a, "--help") or std.mem.eql(u8, a, "-h")) {
            o.help = true;
        } else if (std.mem.eql(u8, a, "--dry-run") or std.mem.eql(u8, a, "-n")) {
            o.dry_run = true;
        } else if (std.mem.eql(u8, a, "--keep=first")) {
            o.keep = .first;
        } else if (std.mem.eql(u8, a, "--keep=last")) {
            o.keep = .last;
        } else if (std.mem.eql(u8, a, "--keep=effective")) {
            o.keep = .effective;
        } else if (std.mem.startsWith(u8, a, "--")) {
            continue;
        } else if (i == 2 and isAction(a)) {
            o.action = actionOf(a);
        } else if (o.target.len == 0) {
            o.target = a;
        }
    }
    return o;
}

fn isAction(a: []const u8) bool {
    return std.mem.eql(u8, a, "audit") or std.mem.eql(u8, a, "analyse") or
        std.mem.eql(u8, a, "analyze") or std.mem.eql(u8, a, "dedupe") or
        std.mem.eql(u8, a, "dedup") or std.mem.eql(u8, a, "group");
}

fn actionOf(a: []const u8) Action {
    if (std.mem.eql(u8, a, "dedupe") or std.mem.eql(u8, a, "dedup")) return .dedupe;
    if (std.mem.eql(u8, a, "group")) return .group;
    return .audit;
}

fn printHelp() void {
    prompt.intro("hkm env — audit and tidy a project's .env");
    prompt.section("Usage");
    prompt.item("hkm env [path|name]", "what is in it: duplicates, groups, keys no plugin declares");
    prompt.item("hkm env dedupe [path|name]", "resolve duplicate keys — one prompt per key");
    prompt.item("hkm env group [path|name]", "reorder into blocks, by declaring plugin then by feature");
    prompt.blank();
    prompt.section("Options");
    prompt.item("--dry-run, -n", "show the result without writing");
    prompt.item("--keep=effective", "dedupe without prompting: keep the line the loader actually uses");
    prompt.item("--keep=first", "dedupe without prompting: keep the topmost occurrence");
    prompt.item("--keep=last", "dedupe without prompting: keep the bottom occurrence");
    prompt.item("--help, -h", "show this help");
    prompt.blank();
    prompt.section("Notes");
    prompt.muted("The LAST active assignment wins at load time, not the first — so a key");
    prompt.muted("appended at the bottom silently overrides the one in its proper block.");
    prompt.muted("Every write leaves the previous file beside it as .env.bak.");
}

pub fn run(allocator: std.mem.Allocator, io: Io, env: *EnvMap, args: []const []const u8) !u8 {
    const opts = parse(args);
    if (opts.help) {
        printHelp();
        return 0;
    }

    const root = (try services.resolveRoot(allocator, io, env, opts.target)) orelse {
        prompt.err(try std.fmt.allocPrint(
            allocator,
            "'{s}' is neither a project folder (with proj.json) nor a registered name.",
            .{if (opts.target.len == 0) "." else opts.target},
        ));
        return 1;
    };

    const f = try envfile.read(allocator, io, root);
    if (f.content.len == 0) {
        prompt.intro("hkm env");
        prompt.err(try std.fmt.allocPrint(allocator, "no .env at {s}", .{f.path}));
        prompt.muted("create one with: hkm install");
        return 1;
    }

    const file = try envfile.parse(allocator, f.content);
    const claims = try readClaims(allocator, io, root);

    return switch (opts.action) {
        .audit => try audit(allocator, io, f.path, file, claims),
        .dedupe => try dedupe(allocator, io, f.path, f.content, file, opts),
        .group => try group(allocator, io, f.path, f.content, file, claims, opts),
    };
}

// ── which plugin declares which key ──────────────────────────────────────────

const Claim = struct { key: []const u8, plugin: []const u8 };

/// Map every key declared in an installed plugin's `module.json` `config[]` to
/// that plugin. This is the authoritative half of the grouping: a key a plugin
/// declares belongs to that plugin, whatever its prefix happens to spell.
fn readClaims(allocator: std.mem.Allocator, io: Io, root: []const u8) ![]const Claim {
    var out: std.ArrayList(Claim) = .empty;

    const plugins_dir = try std.fmt.allocPrint(allocator, "{s}/plugins", .{root});
    var dir = Dir.cwd().openDir(io, plugins_dir, .{ .iterate = true }) catch return out.items;
    defer dir.close(io);

    var it = dir.iterate();
    while (it.next(io) catch null) |entry| {
        // A plugin is a directory or a symlink into the store — both resolve.
        if (entry.name.len == 0 or entry.name[0] == '.') continue;
        // `entry.name` points into the iterator's own buffer and is overwritten
        // by the next next() call — it has to be duped before it outlives this
        // iteration, or the stored group name is whatever the next entry is.
        const name = try allocator.dupe(u8, entry.name);
        const vars = plugin_env.readVars(allocator, io, plugins_dir, name) catch continue;
        for (vars) |v| try out.append(allocator, .{ .key = v.key, .plugin = name });
    }

    return out.items;
}

fn claimOf(claims: []const Claim, key: []const u8) ?[]const u8 {
    for (claims) |c| {
        if (std.mem.eql(u8, c.key, key)) return c.plugin;
    }
    return null;
}

/// The block a key belongs in: its declaring plugin, else its prefix's feature,
/// else Ungrouped.
fn groupOf(claims: []const Claim, key: []const u8) []const u8 {
    if (claimOf(claims, key)) |p| return p;
    return envfile.prefixGroup(key) orelse envfile.ungrouped;
}

// ── audit ────────────────────────────────────────────────────────────────────

fn audit(
    allocator: std.mem.Allocator,
    io: Io,
    path: []const u8,
    file: envfile.File,
    claims: []const Claim,
) !u8 {
    _ = io;
    prompt.intro("hkm env");
    prompt.muted(path);

    var active: usize = 0;
    for (file.records) |r| {
        if (r.active) active += 1;
    }
    prompt.blank();
    prompt.item("keys", try std.fmt.allocPrint(
        allocator,
        "{d} ({d} set, {d} commented)",
        .{ file.records.len, active, file.records.len - active },
    ));

    const dups = try envfile.duplicates(allocator, file);

    // ── duplicates ──
    prompt.blank();
    prompt.section("Duplicates");
    if (dups.len == 0) {
        prompt.ok("no key appears twice");
    } else {
        for (dups) |d| {
            const live = envfile.effective(file, d);
            prompt.warn(try std.fmt.allocPrint(allocator, "{s} — {d} occurrences", .{ d.key, d.at.len }));
            for (d.at, 0..) |rec, i| {
                const r = file.records[rec];
                prompt.muted(try std.fmt.allocPrint(
                    allocator,
                    "  line {d:>4}  {s}{s}={s}{s}",
                    .{
                        r.line + 1,
                        if (r.active) "" else "# ",
                        r.key,
                        elide(r.value),
                        if (live != null and live.? == i) "   ← in effect" else "",
                    },
                ));
            }
        }
        prompt.blank();
        prompt.muted("resolve them with: hkm env dedupe");
    }

    // ── groups ──
    prompt.blank();
    prompt.section("Groups");
    const names = try groupNames(allocator, file, claims);
    for (names) |g| {
        var n: usize = 0;
        for (file.records) |r| {
            if (std.mem.eql(u8, groupOf(claims, r.key), g)) n += 1;
        }
        prompt.item(g, try std.fmt.allocPrint(allocator, "{d} key(s)", .{n}));
    }
    prompt.blank();
    prompt.muted("reorder the file into these blocks with: hkm env group");

    prompt.outro(if (dups.len == 0) "no duplicates" else "duplicates found");
    return if (dups.len == 0) 0 else 1;
}

/// Shorten a value for display. A .env is full of secrets; an audit that prints
/// a 400-character key into a terminal — and a scrollback, and a screen share —
/// has widened the blast radius of the thing it was asked to tidy.
fn elide(value: []const u8) []const u8 {
    if (value.len <= 24) return value;
    return value[0..24];
}

/// Every group present, plugins first (alphabetically), then features, with
/// Ungrouped last so the keys nothing claims are where you look for them.
fn groupNames(allocator: std.mem.Allocator, file: envfile.File, claims: []const Claim) ![]const []const u8 {
    var out: std.ArrayList([]const u8) = .empty;
    for (file.records) |r| {
        const g = groupOf(claims, r.key);
        var seen = false;
        for (out.items) |o| {
            if (std.mem.eql(u8, o, g)) {
                seen = true;
                break;
            }
        }
        if (!seen) try out.append(allocator, g);
    }

    // Plugin-declared groups sort before heuristic ones; Ungrouped goes last.
    const rank = struct {
        fn of(claims_: []const Claim, name: []const u8) u8 {
            if (std.mem.eql(u8, name, envfile.ungrouped)) return 2;
            for (claims_) |c| {
                if (std.mem.eql(u8, c.plugin, name)) return 0;
            }
            return 1;
        }
    };

    const Ctx = struct { claims: []const Claim };
    std.mem.sort([]const u8, out.items, Ctx{ .claims = claims }, struct {
        fn lt(ctx: Ctx, a: []const u8, b: []const u8) bool {
            const ra = rank.of(ctx.claims, a);
            const rb = rank.of(ctx.claims, b);
            if (ra != rb) return ra < rb;
            return std.mem.order(u8, a, b) == .lt;
        }
    }.lt);

    return out.items;
}

// ── dedupe ───────────────────────────────────────────────────────────────────

fn dedupe(
    allocator: std.mem.Allocator,
    io: Io,
    path: []const u8,
    before: []const u8,
    file: envfile.File,
    opts: Options,
) !u8 {
    prompt.intro("hkm env dedupe");
    prompt.muted(path);

    const dups = try envfile.duplicates(allocator, file);
    if (dups.len == 0) {
        prompt.ok("no duplicate keys — nothing to do");
        return 0;
    }

    var drop: std.ArrayList(usize) = .empty;
    var resolved: usize = 0;

    for (dups) |d| {
        const live = envfile.effective(file, d);

        const choice = if (opts.keep) |k| autoChoice(file, d, live, k) else blk: {
            prompt.blank();
            var items: std.ArrayList([]const u8) = .empty;
            for (d.at, 0..) |rec, i| {
                const r = file.records[rec];
                try items.append(allocator, try std.fmt.allocPrint(
                    allocator,
                    "line {d:>4}  {s}{s}={s}{s}",
                    .{
                        r.line + 1,
                        if (r.active) "" else "# ",
                        r.key,
                        elide(r.value),
                        if (live != null and live.? == i) "   (in effect now)" else "",
                    },
                ));
            }
            try items.append(allocator, "leave this key alone");

            const label = try std.fmt.allocPrint(
                allocator,
                "{s} appears {d} times — which line should remain?",
                .{ d.key, d.at.len },
            );
            break :blk prompt.select(label, items.items) orelse items.items.len - 1;
        };

        // The extra trailing option, or a cancelled prompt: change nothing.
        if (choice >= d.at.len) continue;

        resolved += 1;
        for (d.at, 0..) |rec, i| {
            if (i == choice) continue;
            try drop.append(allocator, file.records[rec].line);
        }
    }

    if (drop.items.len == 0) {
        prompt.blank();
        prompt.muted("nothing selected — file unchanged");
        return 0;
    }

    const after = try envfile.withoutLines(allocator, file, drop.items);

    prompt.blank();
    prompt.ok(try std.fmt.allocPrint(
        allocator,
        "{d} key(s) resolved, {d} line(s) removed",
        .{ resolved, drop.items.len },
    ));

    if (opts.dry_run) {
        prompt.muted("dry run — nothing written");
        return 0;
    }

    try envfile.write(allocator, io, path, before, after);
    prompt.ok(try std.fmt.allocPrint(allocator, "written — previous file kept at {s}.bak", .{path}));
    return 0;
}

fn autoChoice(file: envfile.File, d: envfile.Duplicate, live: ?usize, keep: Keep) usize {
    return switch (keep) {
        .first => 0,
        .last => d.at.len - 1,
        // Preserving the value the application is running on right now is the
        // only automatic answer that cannot change behaviour. With nothing
        // active there is nothing in effect to preserve, so keep the last.
        .effective => live orelse blk: {
            _ = file;
            break :blk d.at.len - 1;
        },
    };
}

// ── group ────────────────────────────────────────────────────────────────────

fn group(
    allocator: std.mem.Allocator,
    io: Io,
    path: []const u8,
    before: []const u8,
    file: envfile.File,
    claims: []const Claim,
    opts: Options,
) !u8 {
    prompt.intro("hkm env group");
    prompt.muted(path);

    const dups = try envfile.duplicates(allocator, file);
    if (dups.len > 0) {
        // Reordering a file with duplicates would move the losing copies next
        // to the winner, where they look deliberate. Worse, "last wins" is
        // positional, so the reorder can change WHICH ONE the loader picks —
        // a rewrite that silently alters the running configuration.
        prompt.err(try std.fmt.allocPrint(
            allocator,
            "{d} duplicate key(s) — resolve them before grouping.",
            .{dups.len},
        ));
        prompt.muted("grouping moves lines, and the last assignment is the one that wins,");
        prompt.muted("so reordering a duplicated key can change which value is in effect.");
        prompt.muted("run: hkm env dedupe");
        return 1;
    }

    const names = try groupNames(allocator, file, claims);

    var out: std.ArrayList(u8) = .empty;

    // Every line this rewrite has placed somewhere. What is left over at the
    // end is what would otherwise be silently dropped — see the rescue pass.
    const used = try allocator.alloc(bool, file.lines.len);
    @memset(used, false);

    // Preamble — whatever a person put at the top of the file, kept verbatim.
    for (file.lines[0..file.preamble], 0..) |line, i| {
        try out.appendSlice(allocator, line);
        try out.append(allocator, '\n');
        used[i] = true;
    }
    trimTrailingBlanks(&out);

    for (names) |g| {
        if (out.items.len > 0) try out.appendSlice(allocator, "\n\n");
        try out.appendSlice(allocator, try std.fmt.allocPrint(
            allocator,
            "# ─── {s} ───────────────────────────────────────────────\n",
            .{g},
        ));

        for (file.records) |r| {
            if (!std.mem.eql(u8, groupOf(claims, r.key), g)) continue;

            // Carry the record's attached explanation with it. Only two kinds
            // of line are dropped: a wordless rule, and a banner whose label is
            // a group this pass is re-emitting anyway. A labelled rule that
            // says something — `# --- s3 driver (MinIO) ---` — is information,
            // and survives.
            var i = r.first;
            while (i < r.line) : (i += 1) {
                used[i] = true;
                if (envfile.isRule(file.lines[i])) continue;
                if (envfile.headerLabel(file.lines[i])) |label| {
                    if (isGroupName(names, label)) continue;
                }
                try out.appendSlice(allocator, file.lines[i]);
                try out.append(allocator, '\n');
            }
            try out.appendSlice(allocator, file.lines[r.line]);
            try out.append(allocator, '\n');
            used[r.line] = true;
        }
    }

    // Rescue pass. A comment block separated from every key by a blank line —
    // or trailing after the last one — belongs to no record and would simply
    // cease to exist. These are routinely the most important lines in the file
    // ("NEVER commit actual values for these"), so they are kept verbatim, in
    // order, under a heading that says why they are no longer where they were.
    var orphans: std.ArrayList([]const u8) = .empty;
    for (file.lines, 0..) |line, i| {
        if (used[i]) continue;
        const t = std.mem.trim(u8, line, " \t\r");
        if (t.len == 0 or envfile.isRule(line)) continue;
        // This pass's OWN heading from a previous run. Without this the Notes
        // block orphans itself and grows by two lines every time the command
        // is run — which is the difference between a tidy-up you can run twice
        // and one you can run once.
        if (envfile.headerLabel(line)) |label| {
            if (std.mem.eql(u8, label, notes_label)) continue;
        }
        if (std.mem.eql(u8, t, notes_note)) continue;
        try orphans.append(allocator, line);
    }

    if (orphans.items.len > 0) {
        try out.appendSlice(allocator, "\n\n");
        try out.appendSlice(allocator, "# ─── " ++ notes_label ++ " ───────────────────────────────────────────────\n");
        try out.appendSlice(allocator, notes_note ++ "\n");
        for (orphans.items) |line| {
            try out.appendSlice(allocator, line);
            try out.append(allocator, '\n');
        }
    }

    trimTrailingBlanks(&out);
    try out.append(allocator, '\n');

    prompt.blank();
    for (names) |g| {
        var n: usize = 0;
        for (file.records) |r| {
            if (std.mem.eql(u8, groupOf(claims, r.key), g)) n += 1;
        }
        prompt.item(g, try std.fmt.allocPrint(allocator, "{d} key(s)", .{n}));
    }

    // Everything that went in must come out. A reorder that drops a key takes a
    // secret with it, and one that drops a comment takes the only explanation
    // of a setting — so both are counted rather than trusted. This check is
    // what caught the rescue pass being necessary in the first place.
    const check = try envfile.parse(allocator, out.items);
    if (check.records.len != file.records.len) {
        prompt.err(try std.fmt.allocPrint(
            allocator,
            "refusing to write: {d} keys in, {d} out.",
            .{ file.records.len, check.records.len },
        ));
        return 1;
    }

    const before_notes = try envfile.informationalComments(allocator, file);
    const after_notes = try envfile.informationalComments(allocator, check);
    if (try lostComments(allocator, before_notes, after_notes, names)) |lost| {
        prompt.err("refusing to write: the rewrite would drop comment lines.");
        prompt.muted(lost);
        return 1;
    }

    prompt.blank();
    if (opts.dry_run) {
        prompt.muted("dry run — nothing written");
        return 0;
    }

    try envfile.write(allocator, io, path, before, out.items);
    prompt.ok(try std.fmt.allocPrint(
        allocator,
        "{d} keys regrouped into {d} block(s) — previous file kept at {s}.bak",
        .{ file.records.len, names.len, path },
    ));
    return 0;
}

/// Heading this pass writes over the comments that belong to no single key.
const notes_label = "Notes";
const notes_note = "# Comments that were not attached to any single key.";

fn isGroupName(names: []const []const u8, label: []const u8) bool {
    for (names) |n| {
        if (std.mem.eql(u8, n, label)) return true;
    }
    return false;
}

/// The first informational comment present before the rewrite and absent after,
/// or null when none was lost. A banner this pass re-emits is not a loss.
fn lostComments(
    allocator: std.mem.Allocator,
    before: []const []const u8,
    after: []const []const u8,
    names: []const []const u8,
) !?[]const u8 {
    for (before) |b| {
        if (envfile.headerLabel(b)) |label| {
            if (isGroupName(names, label)) continue;
        }
        var found = false;
        for (after) |a| {
            if (std.mem.eql(u8, a, b)) {
                found = true;
                break;
            }
        }
        if (!found) return try std.fmt.allocPrint(allocator, "  first missing: {s}", .{b});
    }
    return null;
}

fn trimTrailingBlanks(out: *std.ArrayList(u8)) void {
    while (out.items.len > 0 and (out.items[out.items.len - 1] == '\n' or out.items[out.items.len - 1] == '\r')) {
        _ = out.pop();
    }
}

// ── tests ────────────────────────────────────────────────────────────────────

test "the action word is optional and the target survives it" {
    try std.testing.expectEqual(Action.audit, parse(&.{ "hkm", "env" }).action);
    try std.testing.expectEqual(Action.dedupe, parse(&.{ "hkm", "env", "dedupe" }).action);
    try std.testing.expectEqual(Action.group, parse(&.{ "hkm", "env", "group", "shop" }).action);
    try std.testing.expectEqualStrings("shop", parse(&.{ "hkm", "env", "group", "shop" }).target);
    // No action word — the bare argument is the project, not a typo'd verb.
    try std.testing.expectEqualStrings("shop", parse(&.{ "hkm", "env", "shop" }).target);
    try std.testing.expectEqual(Action.audit, parse(&.{ "hkm", "env", "shop" }).action);
}

test "keep flags parse" {
    try std.testing.expectEqual(Keep.effective, parse(&.{ "hkm", "env", "dedupe", "--keep=effective" }).keep.?);
    try std.testing.expectEqual(Keep.first, parse(&.{ "hkm", "env", "dedupe", "--keep=first" }).keep.?);
    try std.testing.expect(parse(&.{ "hkm", "env", "dedupe" }).keep == null);
    try std.testing.expect(parse(&.{ "hkm", "env", "-n" }).dry_run);
}

test "a plugin's claim beats the prefix table" {
    const claims = [_]Claim{.{ .key = "DB_HOST", .plugin = "Tenancy" }};
    // The prefix table would say Database; the plugin that declares it wins.
    try std.testing.expectEqualStrings("Tenancy", groupOf(&claims, "DB_HOST"));
    try std.testing.expectEqualStrings("Database", groupOf(&claims, "DB_PORT"));
    try std.testing.expectEqualStrings(envfile.ungrouped, groupOf(&claims, "STRIPE_KEY"));
}

test "autoChoice keeps what is running when asked for the effective line" {
    const a = std.testing.allocator;
    var arena = std.heap.ArenaAllocator.init(a);
    defer arena.deinit();
    const al = arena.allocator();

    const f = try envfile.parse(al, "A=1\nA=2\n# A=3\n");
    const d = (try envfile.duplicates(al, f))[0];
    const live = envfile.effective(f, d);

    try std.testing.expectEqual(@as(usize, 1), autoChoice(f, d, live, .effective)); // A=2
    try std.testing.expectEqual(@as(usize, 0), autoChoice(f, d, live, .first));
    try std.testing.expectEqual(@as(usize, 2), autoChoice(f, d, live, .last));
}
