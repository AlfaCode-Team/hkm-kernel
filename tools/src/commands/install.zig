//! `hkm install [path|name]` — bring a project that was just `git clone`d /
//! `git pull`ed up to a runnable state.
//!
//! A project's `plugins/`, `var/*` and `userdata/storage/*` are gitignored on
//! purpose (see the scaffolded `.gitignore`): plugin source is fetched from its
//! own git remote by `hkm plugins install`, and `var/`/`userdata/` are runtime
//! state, not source. That means a project pushed to git and pulled somewhere
//! else — a teammate's machine, a fresh server, a CI runner — is missing all
//! three and will not boot. `install` is the one command that restores them:
//!
//!   1. register the project in the kernel's projects.json registry
//!   2. recreate the runtime directories a boot expects to find
//!   3. fix their permissions (writable — a stale root-owned dir from a
//!      previous container run is a common reason a fresh clone can't boot)
//!   4. create .env from .env.example and generate APP_KEY if either is missing
//!   5. `composer install`
//!   6. fetch every plugin the project's own bootstrap wires (mirrors what
//!      `hkm new` does right after scaffolding — see lib/plugin_provision.zig)
//!
//! Every step besides directory creation can be skipped with a --no-* flag, for
//! a CI image that already provisions one of them another way.
//!
//!   hkm install                  # install the project in the current directory
//!   hkm install ./my-shop        # install a specific path
//!   hkm install shop             # install a registered project by name
//!   hkm install --no-install     # skip composer install (e.g. vendor/ is cached)

const std = @import("std");
const registry = @import("../lib/registry.zig");
const prompt = @import("../lib/prompt.zig");
const util = @import("../lib/util.zig");
const services = @import("../lib/services.zig");
const plugin_assets = @import("../lib/plugin_assets.zig");
const plugin_provision = @import("../lib/plugin_provision.zig");
const plugins_cmd = @import("plugins.zig");

const Dir = std.Io.Dir;
const Io = std.Io;
const EnvMap = std.process.Environ.Map;

/// Gitignored runtime directories a boot expects to find. Mirrors what
/// `hkm new` scaffolds and `hkm discover` restores — kept as its own copy here
/// (matching the existing precedent between those two commands) rather than a
/// shared constant, since the two already disagree on the full scaffold list.
const runtime_dirs = [_][]const u8{
    "var/logs",
    "var/cache/manifests",
    "var/tmp",
    "var/locks",
    "var/sessions",
    "var/queue",
    "userdata/storage",
};

const Options = struct {
    /// A project PATH (dir holding proj.json) OR a registered NAME. "" = cwd.
    target: []const u8 = "",
    register: bool = true,
    key: bool = true,
    /// composer install
    install: bool = true,
    /// fetch the plugins the project's bootstrap wires
    plugins: bool = true,
    /// fix var/ and userdata/ mode bits
    chmod: bool = true,
    verify_plugins: bool = false,
    help: bool = false,
    /// --production: var/ and userdata/ get tighter mode bits (dir 0750, file
    /// 0640 — no "other" access) instead of the dev defaults (dir 0775, file
    /// 0664), and a chown failure (see `owner`) is reported per-path instead
    /// of only implied by "no --owner given".
    production: bool = false,
    /// --owner=<user>[:<group>] (also HKM_PROD_OWNER) — chown var/ and
    /// userdata/ to this user[:group], typically the account your web server
    /// / PHP-FPM pool actually runs as. Passed straight to the system `chown`,
    /// so `user`, `user:group` and `:group` (group-only) all work. Requires
    /// root/sudo unless the process already owns the target files. Applies
    /// whenever set, independent of --production and of --no-chmod.
    owner: ?[]const u8 = null,
};

fn parse(args: []const []const u8) ?Options {
    var o = Options{};
    var i: usize = 2;
    while (i < args.len) : (i += 1) {
        const a = args[i];
        if (std.mem.eql(u8, a, "--help") or std.mem.eql(u8, a, "-h")) {
            o.help = true;
        } else if (std.mem.eql(u8, a, "--no-register")) {
            o.register = false;
        } else if (std.mem.eql(u8, a, "--no-key")) {
            o.key = false;
        } else if (std.mem.eql(u8, a, "--no-install")) {
            o.install = false;
        } else if (std.mem.eql(u8, a, "--no-plugins")) {
            o.plugins = false;
        } else if (std.mem.eql(u8, a, "--no-chmod")) {
            o.chmod = false;
        } else if (std.mem.eql(u8, a, "--verify-plugins")) {
            o.verify_plugins = true;
        } else if (std.mem.eql(u8, a, "--production") or std.mem.eql(u8, a, "--prod")) {
            o.production = true;
        } else if (std.mem.startsWith(u8, a, "--owner=")) {
            o.owner = a["--owner=".len..];
        } else if (std.mem.eql(u8, a, "--owner")) {
            if (i + 1 >= args.len) return null;
            i += 1;
            o.owner = args[i];
        } else if (std.mem.startsWith(u8, a, "--")) {
            // unknown flag — ignore so future flags don't hard-fail. Nothing
            // this command does is destructive enough to warrant rejecting one
            // the way `uninstall` does (see util.unknownFlag).
            continue;
        } else if (o.target.len == 0) {
            o.target = a;
        }
    }
    return o;
}

fn printHelp() void {
    prompt.intro("hkm install — bring a cloned/pulled project up to a runnable state");
    prompt.section("Usage");
    prompt.item("hkm install", "install the project in the current directory");
    prompt.item("hkm install <path|name>", "install a specific project or registered name");
    prompt.blank();
    prompt.section("What it does");
    prompt.muted("Registers the project, restores the gitignored var/ and userdata/ runtime");
    prompt.muted("directories with writable permissions, runs composer install, and fetches");
    prompt.muted("every plugin the project's bootstrap wires (plugins/ is never committed).");
    prompt.blank();
    prompt.section("Options");
    prompt.item("--no-register", "skip kernel registry registration");
    prompt.item("--no-key", "skip creating .env / generating APP_KEY");
    prompt.item("--no-install", "skip composer install");
    prompt.item("--no-plugins", "skip fetching the bootstrap's plugins");
    prompt.item("--no-chmod", "skip fixing var/ and userdata/ mode bits");
    prompt.item("--verify-plugins", "run each plugin's own test suite while installing (slow)");
    prompt.item("--production, --prod", "tighter mode bits (dir 0750/file 0640, no world access)");
    prompt.item("--owner=<user>[:<group>]", "chown var/ and userdata/ to this user[:group] (needs root/sudo)");
    prompt.item("--help, -h", "show this help");
    prompt.blank();
    prompt.section("Environment");
    prompt.item("HKM_PROD_OWNER", "default --owner when it is not passed explicitly");
    prompt.item("HKM_CHOWN_BIN", "override the chown binary (default: chown)");
    prompt.blank();
    prompt.section("Examples");
    prompt.note("cd my-shop && hkm install");
    prompt.note("hkm install --production --owner=www-data:www-data");
    prompt.outro("Run this once after `git clone` / `git pull` on a machine new to the project");
}

pub fn run(allocator: std.mem.Allocator, io: Io, env: *EnvMap, args: []const []const u8) !u8 {
    var opts = parse(args) orelse {
        prompt.err("--owner needs a value.");
        printHelp();
        return 2;
    };
    if (opts.help) {
        printHelp();
        return 0;
    }

    // Fall back to HKM_PROD_OWNER so a deploy environment can set it once
    // instead of repeating --owner=user:group on every `hkm install` call.
    if (opts.owner == null) {
        if (env.get("HKM_PROD_OWNER")) |v| {
            if (v.len > 0) opts.owner = v;
        }
    }

    const root = (try services.resolveRoot(allocator, io, env, opts.target)) orelse {
        prompt.err(try std.fmt.allocPrint(
            allocator,
            "'{s}' is neither a project folder (with proj.json) nor a registered name.",
            .{if (opts.target.len == 0) "." else opts.target},
        ));
        return 1;
    };

    const manifest = try readManifest(allocator, io, root);
    prompt.intro(try std.fmt.allocPrint(allocator, "Install project '{s}'", .{manifest.name}));
    prompt.muted(root);

    // 1. register the project in the kernel registry.
    if (opts.register) {
        try registerProject(allocator, io, env, root, manifest);
    }

    // 2. recreate the gitignored runtime directories.
    const created = try ensureRuntimeDirs(allocator, io, root);
    if (created > 0) {
        prompt.ok(try std.fmt.allocPrint(allocator, "Restored {d} runtime director{s}", .{
            created,
            if (created == 1) @as([]const u8, "y") else "ies",
        }));
    } else {
        prompt.muted("Runtime directories already present");
    }

    // 3. make sure they (and anything already inside them) are writable, and
    //    — in --production, or whenever --owner/HKM_PROD_OWNER is set — owned
    //    by the right user[:group] (typically the web server's own account).
    if (opts.chmod) {
        fixPermissions(allocator, io, root, opts.production);
        prompt.ok(if (opts.production)
            "var/ and userdata/ set to production-safe permissions (0750/0640)"
        else
            "var/ and userdata/ set to writable permissions");
    }
    if (opts.owner) |owner| {
        if (owner.len > 0) fixOwnership(allocator, io, env, root, owner);
    } else if (opts.production) {
        prompt.warn("--production: no --owner given (and HKM_PROD_OWNER is unset) — ownership of var/ and userdata/ left unchanged.");
        prompt.muted("pass --owner=<user>[:<group>] — typically your web server's account, e.g. www-data:www-data.");
    }

    // 4. .env — create from .env.example if absent, generate APP_KEY if empty.
    if (opts.key) {
        try ensureEnv(allocator, io, root);
    }

    // 5. composer install — pulls the kernel + vendor deps.
    if (opts.install) {
        try composerInstall(allocator, io, env, root);
    }

    // 6. fetch every plugin the project's own bootstrap wires, then wire their
    //    Support/helpers.php requires and publish their assets — same sequence
    //    `hkm new` runs right after scaffolding.
    var plugins_missing: usize = 0;
    if (opts.plugins) {
        plugins_missing = plugin_provision.installEnabled(allocator, io, env, root, .{
            .verify = opts.verify_plugins,
        }) catch blk: {
            prompt.warn("Could not install the bootstrap's plugins — run 'hkm plugins install <name>' later.");
            break :blk 1;
        };

        _ = plugins_cmd.healSupportRequires(allocator, io, env, root, false) catch 0;

        plugin_assets.publishEnabled(allocator, io, env, root) catch {
            prompt.warn("Could not publish plugin assets — run 'hkm plugins enable <p>' later.");
        };
    }

    prompt.note("");
    prompt.note("Next steps:");
    if (plugins_missing > 0) {
        prompt.muted("  # install the missing plugins listed above first");
    } else {
        prompt.muted("  hkm run                       # or: php -S localhost:8000 -t app/public");
    }

    if (plugins_missing > 0) {
        prompt.outro(try std.fmt.allocPrint(
            allocator,
            "'{s}' installed — but {d} plugin(s) are missing, so it will not boot yet",
            .{ manifest.name, plugins_missing },
        ));
        return 1;
    }

    prompt.outro(try std.fmt.allocPrint(allocator, "'{s}' is ready", .{manifest.name}));
    return 0;
}

// --------------------------------------------------------------------------
// proj.json
// --------------------------------------------------------------------------

const Manifest = struct {
    name: []const u8,
    version: []const u8,
    domains: []const []const u8,
};

/// Read `<root>/proj.json` for the registry fields. `root` is already known to
/// hold a proj.json (services.resolveRoot only returns paths that do), so a
/// parse failure here means malformed JSON, not a missing file — fall back to
/// the directory's own name rather than failing the whole command over it.
fn readManifest(allocator: std.mem.Allocator, io: Io, root: []const u8) !Manifest {
    const fallback = Manifest{ .name = basename(root), .version = "1.0.0", .domains = &.{} };

    const path = try util.join(allocator, root, "proj.json");
    const content = Dir.cwd().readFileAlloc(io, path, allocator, .limited(4 * 1024 * 1024)) catch return fallback;
    const parsed = std.json.parseFromSliceLeaky(std.json.Value, allocator, content, .{}) catch return fallback;
    if (parsed != .object) return fallback;

    var domains: std.ArrayList([]const u8) = .empty;
    if (parsed.object.get("domains")) |d| {
        if (d == .array) {
            for (d.array.items) |item| {
                if (item == .string) try domains.append(allocator, item.string);
            }
        }
    }

    return .{
        .name = strField(parsed.object, "name") orelse fallback.name,
        .version = strField(parsed.object, "version") orelse fallback.version,
        .domains = try domains.toOwnedSlice(allocator),
    };
}

fn strField(obj: std.json.ObjectMap, key: []const u8) ?[]const u8 {
    const v = obj.get(key) orelse return null;
    return if (v == .string) v.string else null;
}

/// Last path component of a (possibly trailing-slash) path.
fn basename(path: []const u8) []const u8 {
    var end = path.len;
    while (end > 0 and (path[end - 1] == '/' or path[end - 1] == '\\')) end -= 1;
    var start = end;
    while (start > 0 and path[start - 1] != '/' and path[start - 1] != '\\') start -= 1;
    return path[start..end];
}

// --------------------------------------------------------------------------
// registry
// --------------------------------------------------------------------------

fn registerProject(allocator: std.mem.Allocator, io: Io, env: *EnvMap, root: []const u8, m: Manifest) !void {
    const jsonPath = (try registry.resolvePath(allocator, io, env)) orelse {
        prompt.warn("Kernel registry not found — skipping registration.");
        prompt.muted("set PSP_PROJECTS_DIR or HKM_KERNEL_HOME, or run `hkm update` later.");
        return;
    };

    try registry.upsert(allocator, io, jsonPath, .{
        .name = m.name,
        .version = m.version,
        .path = root,
        .domains = m.domains,
    });

    prompt.ok(try std.fmt.allocPrint(allocator, "Registered in {s}", .{jsonPath}));
}

// --------------------------------------------------------------------------
// runtime directories + permissions
// --------------------------------------------------------------------------

/// Create every missing runtime directory under `root`. Uses createDirPath so
/// parent segments (e.g. var/cache) are made too. Returns how many were absent.
fn ensureRuntimeDirs(allocator: std.mem.Allocator, io: Io, root: []const u8) !usize {
    const cwd = Dir.cwd();
    var created: usize = 0;
    for (runtime_dirs) |sub| {
        const path = try util.join(allocator, root, sub);
        if (util.dirExists(cwd, io, path)) continue;
        try cwd.createDirPath(io, path);
        created += 1;
    }
    return created;
}

/// Make var/ and userdata/ (and everything already inside them) writable.
/// Best-effort — a mount this process cannot chmod is skipped, not fatal.
/// `production` swaps the dev-friendly 0775/0664 for tighter 0750/0640 (no
/// "other" access) — correct ownership (see fixOwnership) is what actually
/// grants the web server access, so removing world access does not break it.
fn fixPermissions(allocator: std.mem.Allocator, io: Io, root: []const u8, production: bool) void {
    const dir_mode: u32 = if (production) 0o750 else 0o775;
    const file_mode: u32 = if (production) 0o640 else 0o664;
    for ([_][]const u8{ "var", "userdata" }) |sub| {
        const path = std.fmt.allocPrint(allocator, "{s}/{s}", .{ root, sub }) catch continue;
        if (!util.dirExists(Dir.cwd(), io, path)) continue;
        util.chmodTreeWritable(allocator, io, path, dir_mode, file_mode, 8);
    }
}

// --------------------------------------------------------------------------
// ownership
// --------------------------------------------------------------------------

/// chown var/ and userdata/ (recursively) to `owner`, reporting each path's
/// outcome explicitly — unlike chmod, a failed chown in production (wrong
/// privileges, a typo'd user/group) is exactly the kind of thing that should
/// NOT fail silently: the web server would still be unable to write.
fn fixOwnership(allocator: std.mem.Allocator, io: Io, env: *EnvMap, root: []const u8, owner: []const u8) void {
    if (@import("builtin").os.tag == .windows) {
        prompt.warn("--owner is not supported on Windows — skipped.");
        return;
    }

    var ok: usize = 0;
    var total: usize = 0;
    for ([_][]const u8{ "var", "userdata" }) |sub| {
        const path = std.fmt.allocPrint(allocator, "{s}/{s}", .{ root, sub }) catch continue;
        if (!util.dirExists(Dir.cwd(), io, path)) continue;
        total += 1;
        if (chownPath(io, env, path, owner)) {
            ok += 1;
        } else {
            prompt.warn(std.fmt.allocPrint(
                allocator,
                "chown {s} {s} failed — needs root/sudo, or that user/group doesn't exist.",
                .{ owner, sub },
            ) catch "chown failed — needs root/sudo, or that user/group doesn't exist.");
        }
    }

    if (total > 0 and ok == total) {
        prompt.ok(std.fmt.allocPrint(allocator, "var/ and userdata/ owned by {s}", .{owner}) catch "var/ and userdata/ ownership fixed");
    }
}

/// `chown -R <owner> <path>`. Shells out rather than resolving the user/group
/// name to a uid/gid natively — the OS's own NSS already knows how to do that
/// correctly (files, LDAP, whatever `/etc/nsswitch.conf` says), and `chown`
/// already accepts `user`, `user:group` and `:group` (group-only) verbatim, so
/// passing `owner` straight through keeps that flexibility for free. Returns
/// whether the process exited 0.
fn chownPath(io: Io, env: *EnvMap, path: []const u8, owner: []const u8) bool {
    const chown_bin = env.get("HKM_CHOWN_BIN") orelse "chown";
    var child = std.process.spawn(io, .{
        .argv = &.{ chown_bin, "-R", owner, path },
        .environ_map = env,
        .stdin = .ignore,
        .stdout = .ignore,
        .stderr = .inherit,
    }) catch return false;

    const term = child.wait(io) catch return false;
    return switch (term) {
        .exited => |code| code == 0,
        else => false,
    };
}

// --------------------------------------------------------------------------
// .env / APP_KEY
// --------------------------------------------------------------------------

/// Ensure `.env` exists (copied from `.env.example` if absent) and carries a
/// non-empty APP_KEY, generating one only when the line is present and empty —
/// never overwriting a real secret a developer (or an earlier `hkm install`)
/// already set.
fn ensureEnv(allocator: std.mem.Allocator, io: Io, root: []const u8) !void {
    const cwd = Dir.cwd();
    const env_path = try util.join(allocator, root, ".env");

    if (!util.fileExists(io, env_path)) {
        const example_path = try util.join(allocator, root, ".env.example");
        const example = cwd.readFileAlloc(io, example_path, allocator, .limited(1024 * 1024)) catch {
            prompt.warn("No .env and no .env.example — skipped.");
            return;
        };
        try cwd.writeFile(io, .{ .sub_path = env_path, .data = example });
        prompt.ok("Created .env from .env.example");
    }

    const content = cwd.readFileAlloc(io, env_path, allocator, .limited(1024 * 1024)) catch {
        prompt.warn("Could not read .env — APP_KEY not checked.");
        return;
    };

    if (hasNonEmptyAppKey(content)) {
        util.chmod600(io, env_path);
        return;
    }

    var raw: [32]u8 = undefined;
    io.random(&raw);
    const Enc = std.base64.standard.Encoder;
    const key = try allocator.alloc(u8, Enc.calcSize(raw.len));
    _ = Enc.encode(key, &raw);

    var out: std.ArrayList(u8) = .empty;
    var replaced = false;
    var it = std.mem.splitScalar(u8, content, '\n');
    var first = true;
    while (it.next()) |line| {
        if (!first) try out.append(allocator, '\n');
        first = false;
        if (!replaced and std.mem.startsWith(u8, line, "APP_KEY=")) {
            try out.appendSlice(allocator, "APP_KEY=");
            try out.appendSlice(allocator, key);
            replaced = true;
        } else {
            try out.appendSlice(allocator, line);
        }
    }

    if (!replaced) {
        prompt.warn("No APP_KEY= line in .env — left unchanged.");
        util.chmod600(io, env_path);
        return;
    }

    try cwd.writeFile(io, .{ .sub_path = env_path, .data = out.items });
    util.chmod600(io, env_path);
    prompt.ok("Generated APP_KEY in .env (chmod 600)");
}

/// True when .env already has a non-blank `APP_KEY=` value.
fn hasNonEmptyAppKey(content: []const u8) bool {
    var it = std.mem.splitScalar(u8, content, '\n');
    while (it.next()) |raw| {
        const line = std.mem.trim(u8, raw, " \t\r");
        if (std.mem.startsWith(u8, line, "APP_KEY=")) {
            return line.len > "APP_KEY=".len;
        }
    }
    return false;
}

// --------------------------------------------------------------------------
// composer
// --------------------------------------------------------------------------

/// Run `composer install` inside the project. Inherits stdio so the user sees
/// progress; a missing/failing composer is a warning, not a hard error — the
/// rest of `install` (registry, dirs, permissions, plugins) still has value on
/// its own.
fn composerInstall(allocator: std.mem.Allocator, io: Io, env: *EnvMap, root: []const u8) !void {
    const composer = env.get("HKM_COMPOSER_BIN") orelse "composer";
    prompt.note("");
    prompt.ok("Running composer install…");

    var child = std.process.spawn(io, .{
        .argv = &.{ composer, "install", "--no-interaction" },
        .environ_map = env,
        .cwd = .{ .path = root },
        .stdin = .inherit,
        .stdout = .inherit,
        .stderr = .inherit,
    }) catch {
        prompt.warn("composer not found — skipped. Run `composer install` yourself.");
        prompt.muted("(override the binary with HKM_COMPOSER_BIN)");
        return;
    };

    const term = child.wait(io) catch {
        prompt.warn("composer install did not complete cleanly.");
        return;
    };
    switch (term) {
        .exited => |code| {
            if (code == 0) {
                prompt.ok("Dependencies installed");
            } else {
                prompt.warn(try std.fmt.allocPrint(allocator, "composer install exited with code {d}.", .{code}));
            }
        },
        else => prompt.warn("composer install was interrupted."),
    }
}
