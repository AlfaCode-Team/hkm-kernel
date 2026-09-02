//! Seed a plugin's declared env vars into the project's `.env`.
//!
//! Every plugin lists the environment it reads in `module.json` `config[]`, and
//! the kernel FAILS THE BOOT when a required one is absent (ValidateConfigStage).
//! Before this, enabling a plugin left the operator to discover that list from a
//! stack trace, one variable per boot attempt. Enabling now writes the whole set
//! into `.env` at once, so the knobs are visible where you configure things.
//!
//! Three shapes, and the difference between them is load-bearing:
//!
//!   default present        KEY=value        written ACTIVE — the documented default
//!   required, no default   KEY=             written ACTIVE but EMPTY
//!   optional, no default   # KEY=           written COMMENTED
//!
//! An empty value is not the same as an absent one. ValidateConfigStage treats
//! `''` as missing (`$value === null || $value === ''`), so a required var
//! written empty still fails the boot loudly until someone supplies a real
//! secret — which is what should happen. An OPTIONAL var written empty would
//! instead be read as the string `''` and silently beat the plugin's own
//! internal default, so those stay commented: present and documented, but not
//! overriding anything.
//!
//! Nothing already in the file is ever touched. Re-enabling a plugin, or
//! enabling a second one that shares a variable, adds only what is missing.

const std = @import("std");
const util = @import("util.zig");
const envfile = @import("env_file.zig");

const Io = std.Io;
const Dir = std.Io.Dir;

/// One declared variable from a plugin's `module.json` `config[]`.
pub const Var = struct {
    key: []const u8,
    /// "string" | "int" | "float" | "bool" — informational, written as a comment.
    type_name: ?[]const u8 = null,
    required: bool = true,
    /// Rendered default. Null when the plugin declared none.
    default: ?[]const u8 = null,
};

pub const Seeded = struct {
    /// Variables written into the file.
    added: []const Var,
    /// Variables already present (in any form) and therefore left alone.
    skipped: usize,
    /// The file that was (or would be) written.
    path: []const u8,
    /// True when the .env did not exist and was created.
    created: bool,
};

/// Read `config[]` out of a plugin's module.json.
///
/// Accepts both declared shapes: a bare string (`"APP_KEY"`, required, untyped)
/// and the object form (`{ "key": …, "type": …, "required": …, "default": … }`).
pub fn readVars(
    allocator: std.mem.Allocator,
    io: Io,
    pluginsDir: []const u8,
    name: []const u8,
) ![]const Var {
    const path = try std.fmt.allocPrint(allocator, "{s}/{s}/module.json", .{ pluginsDir, name });
    const content = Dir.cwd().readFileAlloc(io, path, allocator, .limited(4 * 1024 * 1024)) catch return &.{};

    const trimmed = std.mem.trim(u8, content, " \t\r\n");
    if (trimmed.len == 0) return &.{};

    const parsed = std.json.parseFromSliceLeaky(std.json.Value, allocator, trimmed, .{}) catch return &.{};
    if (parsed != .object) return &.{};

    const config = parsed.object.get("config") orelse return &.{};
    if (config != .array) return &.{};

    var out: std.ArrayList(Var) = .empty;
    for (config.array.items) |entry| {
        switch (entry) {
            .string => |s| {
                if (s.len == 0) continue;
                try out.append(allocator, .{ .key = s });
            },
            .object => |o| {
                const key = switch (o.get("key") orelse continue) {
                    .string => |s| s,
                    else => continue,
                };
                if (key.len == 0) continue;

                try out.append(allocator, .{
                    .key = key,
                    .type_name = switch (o.get("type") orelse std.json.Value{ .null = {} }) {
                        .string => |s| s,
                        else => null,
                    },
                    // Absent means required — same default the kernel applies.
                    .required = switch (o.get("required") orelse std.json.Value{ .bool = true }) {
                        .bool => |b| b,
                        else => true,
                    },
                    .default = try renderDefault(allocator, o.get("default")),
                });
            },
            else => {},
        }
    }

    return out.items;
}

/// Render a JSON default as it should appear on the right of `KEY=`.
///
/// An explicit JSON `null` is NOT a default — it means "no value", which is
/// exactly the state an absent key already expresses.
fn renderDefault(allocator: std.mem.Allocator, value: ?std.json.Value) !?[]const u8 {
    const v = value orelse return null;
    return switch (v) {
        .string => |s| s,
        .integer => |i| try std.fmt.allocPrint(allocator, "{d}", .{i}),
        .float => |f| try std.fmt.allocPrint(allocator, "{d}", .{f}),
        .bool => |b| if (b) "true" else "false",
        .null => null,
        // An array or object cannot be expressed in a dotenv value.
        else => null,
    };
}

/// True when `key` already appears in the file, whether set or commented out.
///
/// A commented entry counts as present on purpose: it means a previous seed (or
/// a person) already put that knob in front of the operator, and writing it a
/// second time would grow the file every time a plugin is re-enabled.
pub fn hasKey(content: []const u8, key: []const u8) bool {
    var lines = std.mem.splitScalar(u8, content, '\n');
    while (lines.next()) |raw| {
        var line = std.mem.trim(u8, raw, " \t\r");
        if (line.len == 0) continue;

        // Look past a comment marker so `# KEY=` is recognised too.
        while (line.len > 0 and (line[0] == '#' or line[0] == ' ' or line[0] == '\t')) {
            line = line[1..];
            line = std.mem.trimStart(u8, line, " \t");
        }
        if (line.len <= key.len) continue;
        if (!std.mem.startsWith(u8, line, key)) continue;

        // Must be followed by '=' — otherwise APP_KEY would match APP_KEY_ID.
        const rest = std.mem.trimStart(u8, line[key.len..], " \t");
        if (rest.len > 0 and rest[0] == '=') return true;
    }
    return false;
}

/// Byte offset just past the last non-blank line of this plugin's existing
/// block, or null when the file has no block for it yet.
///
/// Without this, a plugin that gains a variable in a later version seeds a
/// SECOND `# ─── Auth ───` block on the next enable, and a third after that.
/// The file still works — every key is present exactly once — but the grouping
/// it was written to provide quietly stops being true, which is the whole point
/// of the block.
fn insertionPoint(content: []const u8, pluginName: []const u8) ?usize {
    var found = false;
    var end: ?usize = null;
    var pos: usize = 0;

    while (pos <= content.len) {
        const nl = std.mem.indexOfScalarPos(u8, content, pos, '\n') orelse content.len;
        const line = content[pos..nl];

        if (envfile.headerLabel(line)) |label| {
            if (found) break; // the next block starts here
            if (std.mem.eql(u8, label, pluginName)) found = true;
        } else if (found and std.mem.trim(u8, line, " \t\r").len > 0) {
            end = nl;
        }

        if (nl == content.len) break;
        pos = nl + 1;
    }

    return if (found) (end orelse null) else null;
}

/// Append every variable of `vars` that the project's `.env` does not already
/// mention, under a labelled block. Creates the file when absent.
///
/// Two rules, both load-bearing. A key already in the file — set, or commented
/// out — is never rewritten, so a real secret is never clobbered by a re-enable
/// or by a second plugin that happens to declare the same variable. And the new
/// keys go into this plugin's OWN block, merged into it when one already
/// exists.
pub fn seed(
    allocator: std.mem.Allocator,
    io: Io,
    projectRoot: []const u8,
    pluginName: []const u8,
    vars: []const Var,
    dry_run: bool,
) !Seeded {
    const path = try std.fs.path.join(allocator, &.{ projectRoot, ".env" });

    const existing = Dir.cwd().readFileAlloc(io, path, allocator, .limited(8 * 1024 * 1024)) catch "";
    const created = existing.len == 0 and !util.fileExists(io, path);

    var missing: std.ArrayList(Var) = .empty;
    var skipped: usize = 0;
    for (vars) |v| {
        if (hasKey(existing, v.key)) {
            skipped += 1;
        } else {
            try missing.append(allocator, v);
        }
    }

    if (missing.items.len == 0 or dry_run) {
        return .{ .added = missing.items, .skipped = skipped, .path = path, .created = created };
    }

    // The variable lines themselves, built once — they go either into this
    // plugin's existing block or into a fresh one.
    var body: std.ArrayList(u8) = .empty;
    for (missing.items) |v| {
        if (v.default) |d| {
            try body.appendSlice(allocator, try std.fmt.allocPrint(allocator, "{s}={s}\n", .{ v.key, d }));
            continue;
        }

        if (v.required) {
            // Active but empty. The kernel counts '' as missing, so the boot
            // still stops here until a real value is supplied — which is the
            // correct outcome for something like an API key.
            try body.appendSlice(allocator, try std.fmt.allocPrint(
                allocator,
                "{s}=          # REQUIRED{s} — set this before booting\n",
                .{ v.key, typeSuffix(allocator, v.type_name) },
            ));
            continue;
        }

        // Optional with no default: COMMENTED. Writing it empty would be read as
        // the string '' and would quietly beat the plugin's own default.
        try body.appendSlice(allocator, try std.fmt.allocPrint(
            allocator,
            "# {s}=         # optional{s}\n",
            .{ v.key, typeSuffix(allocator, v.type_name) },
        ));
    }

    var out: std.ArrayList(u8) = .empty;

    if (insertionPoint(existing, pluginName)) |at| {
        // Merge into the block this plugin already owns.
        const rest = existing[at..];
        const tail = std.mem.trimStart(u8, rest, "\n");
        // How the block was separated from whatever follows it. The inserted
        // lines go INSIDE the block, so that separation has to be put back —
        // otherwise every re-seed pulls the next block up by one line.
        const newlines = rest.len - tail.len;

        try out.appendSlice(allocator, existing[0..at]);
        try out.append(allocator, '\n');
        try out.appendSlice(allocator, body.items);
        var n: usize = 1;
        while (n < newlines) : (n += 1) try out.append(allocator, '\n');
        try out.appendSlice(allocator, tail);
    } else {
        try out.appendSlice(allocator, existing);

        // Exactly one blank line before the block, whatever the file ended with.
        if (out.items.len > 0) {
            while (out.items.len > 0 and (out.items[out.items.len - 1] == '\n' or out.items[out.items.len - 1] == '\r')) {
                _ = out.pop();
            }
            try out.appendSlice(allocator, "\n\n");
        }

        try out.appendSlice(allocator, try std.fmt.allocPrint(
            allocator,
            "# ─── {s} ─────────────────────────────────────────────────\n" ++
                "# Declared in the plugin's module.json config[]. Added by `hkm plugins enable`.\n",
            .{pluginName},
        ));
        try out.appendSlice(allocator, body.items);
    }

    Dir.cwd().writeFile(io, .{ .sub_path = path, .data = out.items }) catch |e| return e;

    // A .env holds secrets; a freshly created one should not be world-readable.
    if (created) util.chmod600(io, path);

    return .{ .added = missing.items, .skipped = skipped, .path = path, .created = created };
}

fn typeSuffix(allocator: std.mem.Allocator, type_name: ?[]const u8) []const u8 {
    const t = type_name orelse return "";
    if (t.len == 0) return "";
    return std.fmt.allocPrint(allocator, " ({s})", .{t}) catch "";
}

// ── tests ────────────────────────────────────────────────────────────────────

test "hasKey matches a set value" {
    try std.testing.expect(hasKey("FOO=1\nBAR=2\n", "FOO"));
}

test "hasKey matches a commented entry so re-enabling does not duplicate it" {
    try std.testing.expect(hasKey("# FOO=\n", "FOO"));
    try std.testing.expect(hasKey("#FOO=\n", "FOO"));
}

test "hasKey does not match a longer key with the same prefix" {
    try std.testing.expect(!hasKey("APP_KEY_ID=x\n", "APP_KEY"));
    try std.testing.expect(hasKey("APP_KEY_ID=x\nAPP_KEY=y\n", "APP_KEY"));
}

test "hasKey ignores a key mentioned only in prose" {
    try std.testing.expect(!hasKey("# see APP_KEY for details\n", "APP_KEY"));
}

test "insertionPoint finds a plugin's own block and ignores the next one" {
    const src =
        "APP_KEY=x\n\n" ++
        "# ─── Auth ───\n" ++
        "AUTH_TTL=60\n\n" ++
        "# ─── Mail ───\n" ++
        "MAIL_HOST=smtp\n";

    // Just past `AUTH_TTL=60` — inside Auth, not swallowing the Mail block.
    const at = insertionPoint(src, "Auth").?;
    try std.testing.expectEqualStrings("APP_KEY=x\n\n# ─── Auth ───\nAUTH_TTL=60", src[0..at]);

    try std.testing.expect(insertionPoint(src, "Storage") == null);
}

test "a key already in the file is never rewritten, set or commented" {
    // Both forms count as present: rewriting a commented one would grow the
    // file on every enable, and rewriting a set one would clobber a real secret.
    try std.testing.expect(hasKey("DEMO_SECRET=live-value\n", "DEMO_SECRET"));
    try std.testing.expect(hasKey("# DEMO_MODE=\n", "DEMO_MODE"));
    try std.testing.expect(!hasKey("DEMO_MODE_X=1\n", "DEMO_MODE"));
}
