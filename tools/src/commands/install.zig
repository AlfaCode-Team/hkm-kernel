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
//!   7. with --production / --owner: chown and chmod the WHOLE project for the
//!      web server's account, plus the plugin-store versions its plugins/
//!      symlinks point at — last, because steps 5 and 6 create vendor/ and
//!      plugins/ as whoever ran the command
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
const installer = @import("../lib/plugin_install.zig");
const pstore = @import("../lib/plugin_store.zig");

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
    /// --production: run the hardening pass over the WHOLE project with no
    /// "other" access at all — code 0750/0640, var+userdata 2770/0660, .env
    /// 0640. Without it the pass still runs whenever `owner` is set, using the
    /// group-and-world-readable dev modes (0775/0664).
    production: bool = false,
    /// --owner=<user>[:<group>] (also HKM_PROD_OWNER) — chown the whole
    /// project, AND the plugin-store versions its plugins/ symlinks resolve to,
    /// to this user[:group], typically `deploy:www-data`: the deploy
    /// account keeps the code, the web server / PHP-FPM pool reaches it through
    /// the group. Passed straight to the system `chown`, so `user`,
    /// `user:group` and `:group` (group-only) all work. Requires root/sudo
    /// unless the process already owns the target files. Setting it triggers
    /// the hardening pass on its own, independent of --production.
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
    prompt.item("--production, --prod", "harden the WHOLE tree: code 0750/0640, var+userdata 2770/0660");
    prompt.item("--owner=<user>[:<group>]", "chown the project AND its linked plugin store entries to this user[:group] (needs root/sudo)");
    prompt.item("--help, -h", "show this help");
    prompt.blank();
    prompt.section("Environment");
    prompt.item("HKM_PROD_OWNER", "default --owner when it is not passed explicitly");
    prompt.item("HKM_CHOWN_BIN", "override the chown binary (default: chown)");
    prompt.blank();
    prompt.section("Examples");
    prompt.note("cd my-shop && hkm install");
    prompt.note("sudo hkm install --production --owner=deploy:www-data");
    prompt.muted("code readable+traversable by the pool's group, var/ and userdata/ writable, .env 0640.");
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

    // 3. make sure they (and anything already inside them) are writable by the
    //    account running this command — composer and the plugin fetch below
    //    both write into the project, so this cannot wait for the hardening
    //    pass in step 7.
    if (opts.chmod) {
        fixPermissions(allocator, io, root);
        prompt.muted("var/ and userdata/ set to writable permissions");
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

    // 7. LAST — ownership and mode bits for the WHOLE project tree.
    //
    //    Deliberately after composer and the plugin fetch: both create files
    //    (vendor/, plugins/) owned by whoever ran this command, so a pass any
    //    earlier would leave exactly the directories a request has to read
    //    owned by the wrong account — the reason a server boot fails under
    //    PHP-FPM while the same tree runs fine from the shell.
    if (opts.production or opts.owner != null) {
        hardenProject(allocator, io, env, root, opts.production, opts.owner);
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
/// Step 3: make `var/` and `userdata/` writable by the account running this
/// command, so composer and the plugin fetch can write into them. Deliberately
/// NOT the production mode bits — those are applied by the hardening pass at
/// the end, once every file that pass has to cover actually exists.
fn fixPermissions(allocator: std.mem.Allocator, io: Io, root: []const u8) void {
    for (writable_subdirs) |sub| {
        const path = std.fmt.allocPrint(allocator, "{s}/{s}", .{ root, sub }) catch continue;
        if (!util.dirExists(Dir.cwd(), io, path)) continue;
        util.chmodTreeWritable(allocator, io, path, 0o775, 0o664, 8);
    }
}

// --------------------------------------------------------------------------
// hardening — the whole project tree, for a web server account
// --------------------------------------------------------------------------

/// The subtrees the application WRITES to at runtime. Everything else in a
/// project is code the request only ever reads.
const writable_subdirs = [_][]const u8{ "var", "userdata" };

/// Never touched by the hardening pass. `.git` holds the whole history — every
/// secret ever committed and later removed included — and nothing in a request
/// path reads it, so widening it to the web server's group buys nothing and
/// gives away everything. It also stays owned by whoever cloned the repo, so a
/// later `git pull` as the deploy user still works after a `sudo hkm install`.
const harden_skip = [_][]const u8{".git"};

/// Mode bits for a hardening pass, in the split-ownership model this command
/// is built around: the code stays owned by the DEPLOY user and the web server
/// account reaches it through the GROUP.
///
/// That is why every mode here grants the group read and directory-execute but
/// never write on code — an FPM pool that can rewrite the PHP it is executing
/// turns any file-write bug into remote code execution. Only `var/` and
/// `userdata/`, which the application genuinely writes, are group-writable, and
/// their directories carry setgid (`2` prefix) so a log file the pool creates
/// at 3am inherits the group instead of becoming unreadable to the deploy user.
const Modes = struct {
    /// directories holding code
    dir: u32,
    /// regular files holding code
    file: u32,
    /// directories under var/ and userdata/ — setgid + group-writable
    writable_dir: u32,
    /// regular files under var/ and userdata/
    writable_file: u32,
    /// .env and friends — group-READABLE, because PHP-FPM has to read APP_KEY,
    /// and never world-readable whichever profile is in force. A 0600 .env is
    /// the single most common reason a tree that runs from the shell fails to
    /// boot under a pool running as another account.
    secret: u32 = 0o640,

    fn of(production: bool) Modes {
        return if (production) .{
            // setgid on code directories too, not just the writable ones: the
            // group is the only thing granting the pool access, and a deploy
            // that lands new files (a git pull, a plugin fetch) creates them
            // with the DEPLOYING account's primary group unless the parent
            // directory says otherwise. Without it every deploy silently
            // un-shares whatever it touched, and the site 500s on a file that
            // was readable an hour ago.
            .dir = 0o2750,
            .file = 0o640,
            .writable_dir = 0o2770,
            .writable_file = 0o660,
        } else .{
            // World-readable, which is the point of the non-production profile
            // — but NOT group-writable on code. The group here is the web
            // server's, and 0664 source would let the pool rewrite the PHP it
            // is executing. Only var/ and userdata/ below are group-writable.
            .dir = 0o2755,
            .file = 0o644,
            .writable_dir = 0o2775,
            .writable_file = 0o664,
        };
    }
};

/// Apply ownership and then mode bits to the entire project.
///
/// Order matters: chown FIRST, chmod second. POSIX lets chown clear the setuid
/// and setgid bits, so a chown running after the chmod would strip the setgid
/// this pass puts on `var/` — and the symptom (files the pool creates being
/// unreadable to the deploy user) shows up days later, nowhere near the cause.
fn hardenProject(
    allocator: std.mem.Allocator,
    io: Io,
    env: *EnvMap,
    root: []const u8,
    production: bool,
    owner: ?[]const u8,
) void {
    if (@import("builtin").os.tag == .windows) {
        prompt.warn("--production/--owner adjust POSIX mode bits and ownership — skipped on Windows.");
        return;
    }

    prompt.note("");
    if (owner) |o| {
        if (o.len > 0) {
            fixOwnership(allocator, io, env, root, o);
            fixPluginStoreOwnership(allocator, io, env, root, o);
        }
    } else {
        prompt.warn("--production: no --owner given (and HKM_PROD_OWNER is unset) — ownership left unchanged.");
        prompt.muted("pass --owner=<user>[:<group>] — typically your web server's account, e.g. deploy:www-data.");
    }

    const m = Modes.of(production);
    hardenTree(allocator, io, root, m);
    prompt.ok(std.fmt.allocPrint(
        allocator,
        "Permissions applied — code {o}/{o}, var+userdata {o}/{o}, .env {o}",
        .{ m.dir, m.file, m.writable_dir, m.writable_file, m.secret },
    ) catch "Permissions applied");

    verifyModes(allocator, io, root, m);
    reportTraversal(allocator, io, env, root);
}

/// Re-stat the paths that decide whether the application boots, and say so when
/// a mode did not actually take.
///
/// Every chmod in this pass is best-effort by contract (`util.chmodPath`
/// swallows the error), which is right for one file deep in `vendor/` and wrong
/// for `var/` — a setgid bit refused because the process is not in the target
/// group leaves a tree that looks hardened and is not. The kernel will not tell
/// you either: it fails later, at the first write, as a permission error on a
/// path whose ownership was just reported as correct.
fn verifyModes(allocator: std.mem.Allocator, io: Io, root: []const u8, m: Modes) void {
    var bad: std.ArrayList([]const u8) = .empty;

    const Want = struct { path: []const u8, mode: u32 };
    var wants: std.ArrayList(Want) = .empty;
    wants.append(allocator, .{ .path = root, .mode = m.dir }) catch return;
    for (writable_subdirs) |sub| {
        const path = std.fmt.allocPrint(allocator, "{s}/{s}", .{ root, sub }) catch continue;
        if (util.dirExists(Dir.cwd(), io, path)) wants.append(allocator, .{ .path = path, .mode = m.writable_dir }) catch {};
    }
    const env_path = std.fmt.allocPrint(allocator, "{s}/.env", .{root}) catch null;
    if (env_path) |ep| {
        if (util.fileExists(io, ep)) wants.append(allocator, .{ .path = ep, .mode = m.secret }) catch {};
    }

    for (wants.items) |w| {
        const actual = util.statMode(io, w.path) orelse continue;
        if (actual == w.mode) continue;
        bad.append(allocator, std.fmt.allocPrint(
            allocator,
            "  {s} — wanted {o}, is {o}",
            .{ w.path, w.mode, actual },
        ) catch continue) catch {};
    }

    if (bad.items.len == 0) return;

    prompt.warn("Some mode bits did not take — the pass reports what it asked for, not what the filesystem accepted:");
    for (bad.items) |line| prompt.muted(line);
    prompt.muted("Usually: not running as root/sudo, a filesystem that refuses setgid, or an ACL overriding the mode.");
}

/// Walk the project root once, dispatching each top-level entry to the mode
/// pair that belongs to it: `var/` and `userdata/` get the writable set,
/// everything else the read-only code set, and `.env*` the secret mode.
fn hardenTree(allocator: std.mem.Allocator, io: Io, root: []const u8, m: Modes) void {
    util.chmodPath(io, root, m.dir);

    var dir = Dir.cwd().openDir(io, root, .{ .iterate = true }) catch return;
    defer dir.close(io);
    var it = dir.iterate();
    while (it.next(io) catch null) |entry| {
        if (skipped(entry.name)) continue;
        const child = std.fmt.allocPrint(allocator, "{s}/{s}", .{ root, entry.name }) catch continue;
        switch (entry.kind) {
            // A symlink's mode bits are not consulted by anything; chmod would
            // follow it and rewrite a target that may sit outside the project.
            .sym_link => continue,
            .directory => {
                if (contains(&writable_subdirs, entry.name)) {
                    applyTree(allocator, io, child, m.writable_dir, m.writable_file, 32);
                } else {
                    applyTree(allocator, io, child, m.dir, m.file, 32);
                }
            },
            .file => chmodFile(io, child, if (std.mem.startsWith(u8, entry.name, ".env")) m.secret else m.file),
            else => {},
        }
    }
}

/// Recursively chmod one subtree. Depth-limited and best-effort, matching
/// `util.chmodTreeWritable`: a directory that cannot be opened is skipped
/// rather than failing the command. 32 is chosen to clear a real `vendor/`,
/// which routinely nests past the 8 the writable-dirs pass uses.
fn applyTree(allocator: std.mem.Allocator, io: Io, path: []const u8, dir_mode: u32, file_mode: u32, depth: usize) void {
    util.chmodPath(io, path, dir_mode);
    if (depth == 0) return;

    var dir = Dir.cwd().openDir(io, path, .{ .iterate = true }) catch return;
    defer dir.close(io);
    var it = dir.iterate();
    while (it.next(io) catch null) |entry| {
        if (skipped(entry.name)) continue;
        const child = std.fmt.allocPrint(allocator, "{s}/{s}", .{ path, entry.name }) catch continue;
        switch (entry.kind) {
            .sym_link => continue,
            .directory => applyTree(allocator, io, child, dir_mode, file_mode, depth - 1),
            .file => chmodFile(io, child, file_mode),
            else => {},
        }
    }
}

/// chmod one regular file to `mode`, KEEPING it executable if it already was.
///
/// A flat `chmod 0640` over the tree is the obvious implementation and it
/// breaks the install: `bin/psp`, `vendor/bin/*` and every shipped shell script
/// lose their exec bit, and the failure surfaces as "command not found" long
/// after this command reported success. The exec bit is re-granted exactly
/// where `mode` grants read, so it never widens access beyond the profile.
fn chmodFile(io: Io, path: []const u8, mode: u32) void {
    const current = util.statMode(io, path) orelse {
        util.chmodPath(io, path, mode);
        return;
    };
    util.chmodPath(io, path, withExecBit(mode, (current & 0o111) != 0));
}

/// `mode`, plus an execute bit wherever `mode` already grants READ — and only
/// when the file was executable to begin with. Deriving x from r rather than
/// hardcoding 0o111 is what keeps the profile intact: under --production a
/// script comes out 0750, not 0751, so "no access for other" still holds for
/// the one class of file where a stray x bit is worth the most to an attacker.
fn withExecBit(mode: u32, executable: bool) u32 {
    if (!executable) return mode;
    return mode | ((mode & 0o444) >> 2);
}

fn skipped(name: []const u8) bool {
    return contains(&harden_skip, name);
}

fn contains(haystack: []const []const u8, needle: []const u8) bool {
    for (haystack) |h| {
        if (std.mem.eql(u8, h, needle)) return true;
    }
    return false;
}

// --------------------------------------------------------------------------
// ownership
// --------------------------------------------------------------------------

/// chown the whole project to `owner`, reporting the outcome explicitly —
/// unlike chmod, a failed chown (wrong privileges, a typo'd user/group) is
/// exactly the kind of thing that should NOT fail silently: the web server
/// would still be unable to read the code or write its logs.
///
/// `.git` is chowned separately — it is not, so that the deploy user keeps
/// being able to `git pull` after a `sudo hkm install`. That is why the root
/// itself is chowned non-recursively and each top-level entry individually,
/// rather than one `chown -R` over the project.
fn fixOwnership(allocator: std.mem.Allocator, io: Io, env: *EnvMap, root: []const u8, owner: []const u8) void {
    var failed: usize = 0;

    if (!chownPath(io, env, root, owner, false)) failed += 1;

    var dir = Dir.cwd().openDir(io, root, .{ .iterate = true }) catch {
        prompt.warn("Could not read the project root — ownership left unchanged.");
        return;
    };
    defer dir.close(io);

    var it = dir.iterate();
    while (it.next(io) catch null) |entry| {
        if (skipped(entry.name)) continue;
        const child = std.fmt.allocPrint(allocator, "{s}/{s}", .{ root, entry.name }) catch continue;
        if (!chownPath(io, env, child, owner, true)) failed += 1;
    }

    if (failed == 0) {
        prompt.ok(std.fmt.allocPrint(allocator, "Project owned by {s} (.git left as-is)", .{owner}) catch "Ownership fixed");
    } else {
        prompt.warn(std.fmt.allocPrint(
            allocator,
            "chown {s} failed on {d} path(s) — needs root/sudo, or that user/group doesn't exist.",
            .{ owner, failed },
        ) catch "chown failed — needs root/sudo, or that user/group doesn't exist.");
    }
}

/// chown the plugin-store entries this project's `plugins/*` symlinks point at.
///
/// A project's plugins are not IN the project. `hkm plugins install` keeps one
/// copy per (plugin, version, origin) in the global store and links the project
/// at it (lib/plugin_store.zig), so `plugins/Logger` is a symlink out of the
/// tree. Both halves of the hardening pass stop at that boundary by design:
/// `hardenTree` skips symlinks because a chmod would follow one and rewrite a
/// target outside the project, and `fixOwnership` only walks the project root.
///
/// The result, before this pass, was a project that verified clean and could
/// not serve: every file the pool had to READ FIRST — every Provider, every
/// controller a route resolves to — was still owned by whoever ran the command,
/// and the report said "Project owned by deploy:www-data".
///
/// Only the versions THIS project links to are touched. The store is shared by
/// every project on the machine, and taking ownership of all of it on behalf of
/// one project's web account is not this command's call.
fn fixPluginStoreOwnership(
    allocator: std.mem.Allocator,
    io: Io,
    env: *EnvMap,
    root: []const u8,
    owner: []const u8,
) void {
    const plugins_dir = std.fmt.allocPrint(allocator, "{s}/plugins", .{root}) catch return;
    var dir = Dir.cwd().openDir(io, plugins_dir, .{ .iterate = true }) catch return;
    defer dir.close(io);

    // The resolved store, used ONLY to bound how far up a target we may walk.
    // A link pointing somewhere else entirely — a working copy someone is
    // editing — gets its own tree chown'd and nothing above it.
    const store: ?[]const u8 = blk: {
        const fallback = fb: {
            const p = installer.pluginsRoot(allocator, io, env, root) catch break :fb root;
            break :fb util.parentOf(p) orelse root;
        };
        break :blk pstore.root(allocator, env, fallback) catch null;
    };

    var done: std.ArrayList([]const u8) = .empty;
    var linked: usize = 0;
    var failed: usize = 0;

    var it = dir.iterate();
    while (it.next(io) catch null) |entry| {
        const link = std.fmt.allocPrint(allocator, "{s}/{s}", .{ plugins_dir, entry.name }) catch continue;
        // A real directory is inside the project — fixOwnership already had it.
        if (!util.isSymlink(io, link)) continue;
        const target = util.linkTarget(allocator, io, link) orelse continue;
        // Relative targets stay inside the project, absolute ones are the store.
        if (target.len == 0 or target[0] != '/') continue;
        // A dangling link has nothing to chown; `hkm plugins verify` reports it.
        if (!util.dirExists(Dir.cwd(), io, target)) continue;
        linked += 1;

        if (!chownOnce(allocator, io, env, &done, target, owner, true)) failed += 1;

        // Everything between the store root and the version directory has to be
        // traversable by the new owner too, or the tree just chown'd cannot be
        // reached. Walk up only INSIDE the store, never above it: the store's
        // own parents are a user's cache or home, and chowning those to a web
        // account on behalf of one project would be a machine-wide surprise.
        const s_root = store orelse continue;
        if (!util.isInside(target, s_root)) continue;
        var cursor: ?[]const u8 = util.parentOf(target);
        while (cursor) |dir_path| : (cursor = util.parentOf(dir_path)) {
            if (!util.isInside(dir_path, s_root)) break;
            if (!chownOnce(allocator, io, env, &done, dir_path, owner, false)) failed += 1;
            if (std.mem.eql(u8, util.trimSlash(dir_path), util.trimSlash(s_root))) break;
        }
    }

    if (linked == 0) return;

    if (failed == 0) {
        prompt.ok(std.fmt.allocPrint(
            allocator,
            "{d} linked plugin store entr{s} owned by {s}",
            .{ linked, if (linked == 1) @as([]const u8, "y") else "ies", owner },
        ) catch "Plugin store ownership fixed");
    } else {
        prompt.warn(std.fmt.allocPrint(
            allocator,
            "chown {s} failed on {d} plugin store path(s) — the pool cannot read those plugins.",
            .{ owner, failed },
        ) catch "chown failed on the plugin store — the pool cannot read those plugins.");
    }
}

/// chown `path`, remembering it so a path reached through several links — the
/// store root, a plugin directory holding two pinned versions — is chown'd once
/// rather than once per link.
fn chownOnce(
    allocator: std.mem.Allocator,
    io: Io,
    env: *EnvMap,
    done: *std.ArrayList([]const u8),
    path: []const u8,
    owner: []const u8,
    recursive: bool,
) bool {
    for (done.items) |p| {
        if (std.mem.eql(u8, p, path)) return true;
    }
    done.append(allocator, path) catch {};
    return chownPath(io, env, path, owner, recursive);
}

/// `chown [-R] <owner> <path>`. Shells out rather than resolving the user/group
/// name to a uid/gid natively — the OS's own NSS already knows how to do that
/// correctly (files, LDAP, whatever `/etc/nsswitch.conf` says), and `chown`
/// already accepts `user`, `user:group` and `:group` (group-only) verbatim, so
/// passing `owner` straight through keeps that flexibility for free. Returns
/// whether the process exited 0.
fn chownPath(io: Io, env: *EnvMap, path: []const u8, owner: []const u8, recursive: bool) bool {
    const chown_bin = env.get("HKM_CHOWN_BIN") orelse "chown";
    const argv: []const []const u8 = if (recursive)
        &.{ chown_bin, "-R", owner, path }
    else
        &.{ chown_bin, owner, path };

    var child = std.process.spawn(io, .{
        .argv = argv,
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
// traversal
// --------------------------------------------------------------------------

/// Report ancestor directories the web server account cannot pass through.
///
/// Getting the project's OWN permissions right is not sufficient: to open
/// `/home/deploy/shop/app/public_html/index.php` the pool needs execute on
/// EVERY directory down the path, and a home directory is 0700 on a stock
/// Debian install. Nothing inside the project can fix that, and the resulting
/// failure reads as a permission error on a file whose mode bits are visibly
/// correct — so name the actual directory instead of leaving it to be guessed.
///
/// Reported, never changed: widening a directory that is not part of the
/// project is the operator's call, not this command's.
fn reportTraversal(allocator: std.mem.Allocator, io: Io, env: *EnvMap, root: []const u8) void {
    var blocked: std.ArrayList([]const u8) = .empty;
    collectBlocked(allocator, io, util.parentOf(root), &blocked);

    if (blocked.items.len > 0) {
        prompt.warn("The web server may not be able to REACH the project — these parent directories deny traversal to others:");
        for (blocked.items) |item| prompt.muted(std.fmt.allocPrint(allocator, "  {s}", .{item}) catch item);
        prompt.muted("Each one needs execute for the pool's account: `chmod o+x <dir>`, add the account to its group, or");
        prompt.muted("move the project somewhere the web server already reaches (/var/www, /srv).");
    }

    reportStoreTraversal(allocator, io, env, root);
}

/// The same check for the PLUGIN STORE, which the project reaches by symlink.
///
/// Worth its own pass and its own advice: the store defaults to `$HOME/.cache`
/// (lib/plugin_store.zig), and a deploy run under sudo resolves that to
/// `/root/.cache` — a directory that is 0700 on every mainstream distro. The
/// chown above then succeeds on every entry and the site still cannot read one
/// of them, because the denial is a level above anything this command owns.
///
/// The remedy differs too. Widening a home directory to reach a cache is the
/// wrong trade; the store is relocatable precisely so it does not have to be.
fn reportStoreTraversal(allocator: std.mem.Allocator, io: Io, env: *EnvMap, root: []const u8) void {
    const fallback = fb: {
        const p = installer.pluginsRoot(allocator, io, env, root) catch break :fb root;
        break :fb util.parentOf(p) orelse root;
    };
    const store = pstore.root(allocator, env, fallback) catch return;
    // Nothing installed from the store — no reason to talk about it.
    if (!util.dirExists(Dir.cwd(), io, store)) return;
    // A store INSIDE the project is covered by the project's own walk above.
    if (util.isInside(store, root)) return;
    // Say nothing about a store this project does not actually reach into —
    // the warning below asserts that its plugins/ links point there.
    if (!linksIntoStore(allocator, io, root, store)) return;

    var blocked: std.ArrayList([]const u8) = .empty;
    collectBlocked(allocator, io, store, &blocked);
    if (blocked.items.len == 0) return;

    prompt.warn("The web server cannot REACH the plugin store — the project's plugins/ symlinks point into it:");
    for (blocked.items) |item| prompt.muted(std.fmt.allocPrint(allocator, "  {s}", .{item}) catch item);
    prompt.muted(std.fmt.allocPrint(
        allocator,
        "  store: {s}",
        .{store},
    ) catch "");
    prompt.muted("Move it somewhere the pool already reaches rather than widening a home directory:");
    prompt.muted("  hkm plugins store --set=/var/lib/hkm/plugin-store   (or: export HKM_PLUGIN_STORE=…)");
    prompt.muted("then re-point this project's links with: hkm plugins lock");
}

/// True when at least one `plugins/*` entry is a symlink resolving into
/// `store`. Cheap enough to run unconditionally: a project has a handful of
/// plugins, and this reads only the link targets, never the trees behind them.
fn linksIntoStore(allocator: std.mem.Allocator, io: Io, root: []const u8, store: []const u8) bool {
    const plugins_dir = std.fmt.allocPrint(allocator, "{s}/plugins", .{root}) catch return false;
    var dir = Dir.cwd().openDir(io, plugins_dir, .{ .iterate = true }) catch return false;
    defer dir.close(io);

    var it = dir.iterate();
    while (it.next(io) catch null) |entry| {
        const link = std.fmt.allocPrint(allocator, "{s}/{s}", .{ plugins_dir, entry.name }) catch continue;
        if (!util.isSymlink(io, link)) continue;
        const target = util.linkTarget(allocator, io, link) orelse continue;
        if (util.isInside(target, store)) return true;
    }
    return false;
}

/// Walk from `start` up to `/`, collecting every directory that denies
/// traversal to "other" — reachable only by its owner or a member of its
/// group, which a web server account rarely is.
fn collectBlocked(
    allocator: std.mem.Allocator,
    io: Io,
    start: ?[]const u8,
    blocked: *std.ArrayList([]const u8),
) void {
    var cursor: ?[]const u8 = start;
    while (cursor) |path| : (cursor = util.parentOf(path)) {
        if (util.statMode(io, path)) |mode| {
            if ((mode & 0o001) == 0) {
                blocked.append(allocator, std.fmt.allocPrint(allocator, "{s} ({o})", .{ path, mode }) catch path) catch {};
            }
        }
        if (std.mem.eql(u8, path, "/")) break;
    }
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

// --------------------------------------------------------------------------
// tests
// --------------------------------------------------------------------------

test "withExecBit leaves a non-executable file's mode alone" {
    try std.testing.expectEqual(@as(u32, 0o640), withExecBit(0o640, false));
    try std.testing.expectEqual(@as(u32, 0o664), withExecBit(0o664, false));
    try std.testing.expectEqual(@as(u32, 0o660), withExecBit(0o660, false));
}

test "withExecBit re-grants x exactly where the mode grants r" {
    // The regression this guards: a flat chmod 0640 over the tree strips the
    // exec bit from bin/psp and vendor/bin/*, and the install only looks like
    // it worked until the first invocation.
    try std.testing.expectEqual(@as(u32, 0o750), withExecBit(0o640, true));
    try std.testing.expectEqual(@as(u32, 0o775), withExecBit(0o664, true));
    try std.testing.expectEqual(@as(u32, 0o770), withExecBit(0o660, true));
}

test "withExecBit never widens beyond the profile" {
    // --production grants nothing to "other", so neither may the exec bit.
    for ([_]u32{ 0o640, 0o660, 0o600 }) |mode| {
        try std.testing.expectEqual(@as(u32, 0), withExecBit(mode, true) & 0o007);
    }
}

test "production modes deny other, dev modes keep the previous defaults" {
    const prod = Modes.of(true);
    try std.testing.expectEqual(@as(u32, 0o2750), prod.dir);
    try std.testing.expectEqual(@as(u32, 0o640), prod.file);
    try std.testing.expectEqual(@as(u32, 0o2770), prod.writable_dir);
    try std.testing.expectEqual(@as(u32, 0o660), prod.writable_file);
    for ([_]u32{ prod.dir, prod.file, prod.writable_dir, prod.writable_file, prod.secret }) |m| {
        try std.testing.expectEqual(@as(u32, 0), m & 0o007);
    }

    // Dev keeps the historic 0775/0664 for the runtime tree (what the pre-
    // hardening `fixPermissions` applied), but code is only world-READABLE.
    const dev = Modes.of(false);
    try std.testing.expectEqual(@as(u32, 0o2755), dev.dir);
    try std.testing.expectEqual(@as(u32, 0o644), dev.file);
    try std.testing.expectEqual(@as(u32, 0o2775), dev.writable_dir);
    try std.testing.expectEqual(@as(u32, 0o664), dev.writable_file);
}

test "both profiles make var/ group-writable and setgid, and .env group-readable" {
    for ([_]Modes{ Modes.of(true), Modes.of(false) }) |m| {
        // group write on the runtime tree — the pool has to write logs
        try std.testing.expect(m.writable_dir & 0o020 != 0);
        try std.testing.expect(m.writable_file & 0o020 != 0);
        // setgid on BOTH trees: the writable one so a file the pool creates
        // keeps the deploy user's group, the code one so a deploy that pulls
        // new files does not un-share them from the pool.
        try std.testing.expect(m.writable_dir & 0o2000 != 0);
        try std.testing.expect(m.dir & 0o2000 != 0);
        // group READ but never group WRITE on .env — FPM reads APP_KEY, and a
        // 0600 .env is the most common reason an FPM boot fails on a tree that
        // runs fine from the shell.
        try std.testing.expect(m.secret & 0o040 != 0);
        try std.testing.expectEqual(@as(u32, 0), m.secret & 0o020);
        try std.testing.expectEqual(@as(u32, 0), m.secret & 0o007);
        // code is never group-writable — an FPM pool that can rewrite the PHP
        // it executes turns any file-write bug into code execution.
        try std.testing.expectEqual(@as(u32, 0), m.file & 0o020);
        try std.testing.expectEqual(@as(u32, 0), m.dir & 0o020);
    }
}

test "skipped protects .git and nothing else" {
    try std.testing.expect(skipped(".git"));
    try std.testing.expect(!skipped("var"));
    try std.testing.expect(!skipped(".env"));
    try std.testing.expect(!skipped("vendor"));
}
