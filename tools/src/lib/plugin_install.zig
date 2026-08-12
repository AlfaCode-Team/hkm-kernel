//! Installing, updating and removing plugins from their git remotes.
//!
//! The order of operations is the important part, and it is always:
//!
//!   1. RESOLVE  name → remote URL
//!   2. RESOLVE  constraint → concrete tag (never a moving branch)
//!   3. FETCH    into a staging path
//!   4. GATE     the fetched module.json's kernel constraint
//!   5. COMMIT   move into plugins/ and record in plugins.lock.json
//!
//! The gate sits AFTER the fetch because the constraint lives inside the plugin,
//! and the only way to read it for a version you do not yet have is to fetch it.
//! Staging first means a rejected plugin never lands in plugins/ — a
//! half-installed plugin that the bootstrap then tries to wire is a worse
//! outcome than a failed install.

const std = @import("std");
const git = @import("plugin_git.zig");
const lockfile = @import("plugin_lock.zig");
const pregistry = @import("plugin_registry.zig");
const sources = @import("plugin_sources.zig");
const semver = @import("semver.zig");
const banner = @import("banner.zig");
const prompt = @import("prompt.zig");
const util = @import("util.zig");
const store = @import("plugin_store.zig");
const run_cmd = @import("../commands/run.zig");
const kernel = @import("kernel.zig");

const Dir = std.Io.Dir;
const Io = std.Io;
const EnvMap = std.process.Environ.Map;

pub const Outcome = union(enum) {
    installed: lockfile.Entry,
    /// Already present at the requested version — nothing to do.
    up_to_date: lockfile.Entry,
    /// The version was already in the shared store, so nothing was downloaded,
    /// but this PROJECT gained it. Distinct from `up_to_date`, which reads as
    /// "nothing happened" — and something did.
    linked: lockfile.Entry,
    updated: struct { from: []const u8, to: lockfile.Entry },
    /// Refused. `why` is a complete, user-facing sentence.
    refused: []const u8,
};

pub const Options = struct {
    /// Explicit version to install ("v1.2.0"), or empty for "newest allowed".
    version: []const u8 = "",
    /// Report what would happen without touching the filesystem.
    dry_run: bool = false,
    /// Overwrite a working copy that has uncommitted changes.
    force: bool = false,
    /// Clone full history instead of --depth 1.
    full: bool = false,
    /// Run the plugin's own test suite before installing it.
    verify: bool = true,
    /// Whether a failing suite may be escalated to the user. FALSE for
    /// unattended callers (`hkm new`, CI): there is nobody to answer, and
    /// installing a plugin whose tests fail because nothing could ask is how a
    /// broken plugin reaches a project silently.
    interactive: bool = true,
    /// Explicit git remote, bypassing name→URL resolution. Set when the user
    /// gave a URL instead of a plugin name, and when restoring a lock entry
    /// that records where its plugin actually came from.
    remote: []const u8 = "",
    /// Skip the composer autoload refresh. For callers installing SEVERAL
    /// plugins: the dump is a full classmap rebuild of the whole tree, so doing
    /// it per plugin costs N rebuilds to reach the state one at the end gives.
    /// A caller that sets this MUST call refreshAutoload itself when done, or
    /// the plugins are on disk and invisible to PHP.
    defer_autoload: bool = false,
};

/// The version-keyed store — always `<kernel>/plugin-store/<Name>/<version>`.
///
/// One physical copy per (plugin, version), reused by every project that pins
/// that version — so two projects on the same release share the download, and a
/// project on an older release keeps its own copy rather than being dragged
/// forward. A project references its pin with a symlink from its own plugins/,
/// which is what lets the PROJECT's composer resolve it and what makes the
/// pinned version per-project rather than machine-wide.
///
/// Deliberately NOT under `<kernel>/plugins/`: that path is PSR-4 mapped by the
/// kernel's composer, and a `.store` directory inside it would be scanned as if
/// every version were a plugin.
pub fn storeDir(
    allocator: std.mem.Allocator,
    io: Io,
    env: *EnvMap,
    projectRoot: []const u8,
    name: []const u8,
    version: []const u8,
) !?[]const u8 {
    return storeDirFor(allocator, io, env, projectRoot, name, version, "");
}

/// As `storeDir`, with the first-party decision already made — see `pluginsRootFor`.
pub fn storeDirFor(
    allocator: std.mem.Allocator,
    io: Io,
    env: *EnvMap,
    projectRoot: []const u8,
    name: []const u8,
    version: []const u8,
    remote: []const u8,
) !?[]const u8 {
    // ALWAYS the global cache, first-party or not.
    //
    // It used to follow the install target, so a third-party plugin was stored
    // under the PROJECT — and every other project on the machine wanting the
    // same plugin at the same version downloaded and kept its own copy. That
    // defeats the point: one copy per (plugin, version, origin), shared.
    //
    // Storing centrally does NOT change which composer owns the plugin. What
    // composer sees is the LINK in <project>/plugins/<Name>, and that is still
    // per project — the store is only where the bytes live.
    const fallback = try kernelFallbackRoot(allocator, io, env, projectRoot);
    const path = try store.entryDir(allocator, env, fallback, name, version, remote);
    return path;
}

/// The kernel root, used only as the store location of last resort — a machine
/// with no HOME and no configured cache directory.
fn kernelFallbackRoot(allocator: std.mem.Allocator, io: Io, env: *EnvMap, projectRoot: []const u8) ![]const u8 {
    const plugins = try pluginsRootFor(allocator, io, env, projectRoot, true);
    return util.parentOf(plugins) orelse projectRoot;
}

/// Where first-party plugins are installed: the KERNEL's plugins directory.
///
/// Not the project's. Plugins under the AlfaCode-Team org are shared
/// infrastructure — one copy serves every project on the machine, which is what
/// plugin_sources already calls the "kernel" source and treats as the
/// contributor-protected one. Installing per project would give each its own
/// copy of the same nineteen packages and no single place to update them.
///
/// Falls back to the project's own plugins/ when no kernel root can be resolved
/// (a bare checkout, or a project outside a kernel install), so the command
/// still works rather than failing on a machine that has no /opt install.
pub fn targetDir(
    allocator: std.mem.Allocator,
    io: Io,
    env: *EnvMap,
    projectRoot: []const u8,
    name: []const u8,
) ![]const u8 {
    const dir = try pluginsRoot(allocator, io, env, projectRoot);
    return std.fs.path.join(allocator, &.{ dir, name });
}

/// The directory plugins are installed into, created if absent.
pub fn pluginsRoot(
    allocator: std.mem.Allocator,
    io: Io,
    env: *EnvMap,
    projectRoot: []const u8,
) ![]const u8 {
    return pluginsRootFor(allocator, io, env, projectRoot, pregistry.isFirstParty(env));
}

/// As `pluginsRoot`, but with the first-party decision already made.
///
/// Separate because an explicit remote answers that question by itself, and the
/// environment-based answer would be wrong for it: `hkm plugins install
/// https://github.com/AlfaCode-Team/hkm-plugin-logger.git` must reach the same
/// shared kernel directory as `hkm plugins install logger`, and a URL pointing
/// anywhere else must not.
pub fn pluginsRootFor(
    allocator: std.mem.Allocator,
    io: Io,
    env: *EnvMap,
    projectRoot: []const u8,
    first_party: bool,
) ![]const u8 {
    // Third-party plugins belong to the project that asked for them, and are
    // resolved by the PROJECT's composer. Only first-party packages go into the
    // shared kernel.
    if (!first_party) {
        return std.fs.path.join(allocator, &.{ projectRoot, "plugins" });
    }

    if (try sources.kernelPluginsDir(allocator, io, env, projectRoot)) |kd| return kd;

    // kernelPluginsDir only returns a path that already EXISTS. A fresh kernel
    // install has no plugins/ yet, so derive it from the kernel root and let the
    // caller create it.
    if (env.get("HKM_KERNEL_HOME")) |h| {
        if (h.len > 0) return std.fmt.allocPrint(allocator, "{s}/plugins", .{util.trimSlash(h)});
    }
    if (try kernelRootFromCli(allocator, io, env)) |root| {
        return std.fmt.allocPrint(allocator, "{s}/plugins", .{root});
    }

    return std.fs.path.join(allocator, &.{ projectRoot, "plugins" });
}

/// Kernel root derived from the resolved CLI path (`<root>/bin/hkm`).
fn kernelRootFromCli(allocator: std.mem.Allocator, io: Io, env: *EnvMap) !?[]const u8 {
    const r = kernel.resolve(allocator, io, env) catch return null;
    const bin = std.fs.path.dirname(r.path) orelse return null;
    return std.fs.path.dirname(bin);
}

/// Read the kernel constraint out of an already-fetched plugin directory.
fn constraintOf(allocator: std.mem.Allocator, io: Io, pluginsDir: []const u8, name: []const u8) ?[]const u8 {
    const meta = sources.readModuleMeta(allocator, io, pluginsDir, name) catch return null;
    const m = meta orelse return null;
    return m.kernel;
}

/// Turn a compatibility verdict into a refusal sentence, or null when fine.
fn gateMessage(allocator: std.mem.Allocator, env: *EnvMap, name: []const u8, constraint: ?[]const u8) !?[]const u8 {
    if (pregistry.gateBypassed(env)) return null;

    return switch (pregistry.checkKernel(constraint)) {
        .ok => null,
        .unknown_kernel => try std.fmt.allocPrint(
            allocator,
            "{s} declares a kernel requirement but this kernel's version ('{s}') could not be parsed.",
            .{ name, banner.version() },
        ),
        .bad_constraint => |c| try std.fmt.allocPrint(
            allocator,
            "{s} declares an unreadable kernel requirement ('{s}'). Fix its module.json \"kernel\" field.",
            .{ name, c },
        ),
        .incompatible => |i| try std.fmt.allocPrint(
            allocator,
            "{s} requires kernel {s} but this kernel is {s}. Upgrade the kernel (hkm upgrade), install an older plugin release (--version=…), or override with HKM_PLUGIN_IGNORE_KERNEL=1.",
            .{ name, i.required, i.kernel },
        ),
    };
}


/// Outcome of running a freshly fetched plugin's own test suite.
const Verdict = enum {
    passed,
    failed,
    /// No test suite, or no runner after a successful dependency install.
    unavailable,
    /// Dependencies could not be resolved, so the suite could not be reached.
    /// Distinct from `unavailable` because the causes and the fix differ: this
    /// one is almost always the environment (an unwritable composer cache, no
    /// network, missing auth), not the plugin.
    blocked,
};

/// Install the plugin's dev dependencies and run its tests, in `dir`.
///
/// A packaged kernel ships no phpunit — install.sh runs `composer install
/// --no-dev` — so the runner has to come from the plugin itself. That costs a
/// composer install per plugin, which is why `verify` is a switch rather than
/// unconditional.
fn runPluginTests(allocator: std.mem.Allocator, io: Io, env: *EnvMap, dir: []const u8, name: []const u8) Verdict {
    const tests_dir = std.fs.path.join(allocator, &.{ dir, "tests" }) catch return .unavailable;
    if (!util.dirExists(Dir.cwd(), io, tests_dir)) return .unavailable; // nothing to run

    prompt.muted(std.fmt.allocPrint(allocator, "{s}: resolving test dependencies…", .{name}) catch name);

    var composer = [_][]const u8{ "composer", "install", "--no-interaction", "--no-progress", "--working-dir", dir };
    const cinstall = run_cmd.spawnWait(io, env, &composer) catch return .unavailable;
    if (cinstall != 0) return .blocked;

    const phpunit = std.fs.path.join(allocator, &.{ dir, "vendor", "bin", "phpunit" }) catch return .unavailable;
    if (!util.fileExists(io, phpunit)) return .unavailable;

    prompt.muted(std.fmt.allocPrint(allocator, "{s}: running tests…", .{name}) catch name);

    // Paths are passed EXPLICITLY rather than relying on the working directory:
    // hkm runs from wherever the user invoked it, so phpunit found neither a
    // phpunit.xml nor a test path and simply printed its own usage — which the
    // exit code then reported as a failure.
    var run = [_][]const u8{ phpunit, "--no-coverage", "--do-not-cache-result", "--bootstrap", "", tests_dir };
    const autoload = std.fs.path.join(allocator, &.{ dir, "vendor", "autoload.php" }) catch return .unavailable;
    run[4] = autoload;
    const code = run_cmd.spawnWait(io, env, &run) catch return .unavailable;
    return if (code == 0) .passed else .failed;
}

/// Strip everything a consumer does not need from an installed plugin.
///
/// tests/ and vendor/ are development artefacts: vendor/ here holds the plugin's
/// DEV dependencies (phpunit and friends) pulled purely to run the suite, and
/// leaving it would shadow the kernel's own autoloader with a second copy of
/// shared packages. Removed after verification, never before — deleting the
/// tests first would make the verification impossible.
fn stripDevArtefacts(io: Io, allocator: std.mem.Allocator, dir: []const u8) void {
    for ([_][]const u8{ "tests", "vendor", "composer.lock", "phpunit.xml", "phpunit.xml.dist" }) |entry| {
        const path = std.fs.path.join(allocator, &.{ dir, entry }) catch continue;
        Dir.cwd().deleteTree(io, path) catch {};
    }
}

/// Make the kernel's autoloader aware of a newly installed plugin.
///
/// The kernel maps `Plugins\` to its plugins/ directory, so a new folder is only
/// discoverable once the classmap is regenerated.
pub fn refreshAutoload(allocator: std.mem.Allocator, io: Io, env: *EnvMap, pluginsDir: []const u8) void {
    const kernel_root = util.parentOf(pluginsDir) orelse return;
    const composer_json = std.fs.path.join(allocator, &.{ kernel_root, "composer.json" }) catch return;
    if (!util.fileExists(io, composer_json)) return;

    var argv = [_][]const u8{ "composer", "dump-autoload", "--no-interaction", "--working-dir", kernel_root };
    _ = run_cmd.spawnWait(io, env, &argv) catch {};
}

/// Refresh every composer that could own a plugin directory for this project.
///
/// The counterpart to `Options.defer_autoload`: call once after a batch.
pub fn refreshAllAutoload(allocator: std.mem.Allocator, io: Io, env: *EnvMap, projectRoot: []const u8) void {
    const project_plugins = std.fs.path.join(allocator, &.{ projectRoot, "plugins" }) catch return;
    refreshAutoload(allocator, io, env, project_plugins);

    const kernel_plugins = pluginsRootFor(allocator, io, env, projectRoot, true) catch return;
    if (!std.mem.eql(u8, kernel_plugins, project_plugins)) {
        refreshAutoload(allocator, io, env, kernel_plugins);
    }
}

/// Install a plugin into `<projectRoot>/plugins/<name>` from its git remote.
///
/// Idempotent: an already-present plugin at the requested version reports
/// `.up_to_date` and touches nothing.
pub fn install(
    allocator: std.mem.Allocator,
    io: Io,
    env: *EnvMap,
    projectRoot: []const u8,
    name: []const u8,
    opts: Options,
) !Outcome {
    if (!git.available(allocator, io, env)) {
        return .{ .refused = "git is not installed or not on PATH — it is required to fetch plugins." };
    }

    // An explicit remote wins over name→URL resolution: it is how a fork, a
    // private mirror or a plugin that was never in the registry gets installed,
    // and how a lock entry is restored from wherever its plugin came from.
    const remote = if (opts.remote.len > 0)
        opts.remote
    else
        try pregistry.remoteFor(allocator, env, name);

    // Where it lands follows the REMOTE, not the environment — see pluginsRootFor.
    const first_party = if (opts.remote.len > 0)
        pregistry.remoteIsFirstParty(env, opts.remote)
    else
        pregistry.isFirstParty(env);

    const pluginsDir = try pluginsRootFor(allocator, io, env, projectRoot, first_party);

    // The DIRECTORY must match the PSR-4 namespace, not whatever the user
    // typed: `install crypto` has to produce plugins/Crypto or the autoloader
    // will never find Plugins\Crypto\Provider.
    var folder = try pregistry.canonicalName(allocator, name);
    var dest = try std.fs.path.join(allocator, &.{ pluginsDir, folder });

    // A REAL working copy at dest — not a link into the shared store.
    //
    // The distinction decides whether the in-place update path below may run,
    // and getting it wrong is destructive: `git checkout` through a store
    // symlink rewrites the shared (plugin, version) directory that every other
    // project pinning that version is linked to. A managed link is re-pointed,
    // never checked out.
    const already = !util.isSymlink(io, dest) and git.isRepo(io, dest, allocator);

    // Resolve the constraint to a concrete tag BEFORE fetching, so we never
    // install from a moving branch.
    const tag = git.resolveRef(allocator, io, env, remote, opts.version) catch |e| {
        return .{ .refused = try std.fmt.allocPrint(
            allocator,
            "{s}: {s} ({s})",
            .{ name, git.explain(e), remote },
        ) };
    };

    // Two separate allocPrint calls rather than one with a conditional format
    // string: std.fmt requires the format to be comptime-known, so selecting it
    // with a runtime `if` does not compile.
    const want = tag orelse {
        const msg = if (opts.version.len > 0)
            try std.fmt.allocPrint(
                allocator,
                "{s} has no tag, version or branch matching '{s}' on {s}. Run `hkm plugins versions {s}` to see what exists.",
                .{ name, opts.version, remote, name },
            )
        else
            try std.fmt.allocPrint(
                allocator,
                "{s} has no release tags on {s}. A plugin must be tagged before it can be installed.",
                .{ name, remote },
            );
        return .{ .refused = msg };
    };

    // ── Already in the store at this version? ───────────────────────────────
    //
    // Two projects pinning the same release must not download it twice. The
    // store is keyed by (plugin, version), so a second project just links at
    // what is already there.
    // A branch is a moving target with no version, and the store's whole
    // premise is that a (plugin, version) directory never changes. Two projects
    // tracking "main" at different commits would collide on one path, and the
    // second would silently get the first's checkout. Branch installs therefore
    // use the flat per-project layout instead.
    const storable = !want.isBranch();

    // The hashed entry, or a pre-hash one left by an older layout.
    //
    // Migrated caches keep their bare `<version>` directory names, and projects
    // are symlinked straight at those paths. Looking only for the hashed name
    // would miss every one of them — re-downloading the entire cache once, and
    // leaving the old copies orphaned but still linked. Accepting the legacy
    // name (without renaming it, which would break those links) means the two
    // layouts coexist and new installs converge on the hashed one.
    const store_hit: ?[]const u8 = if (!storable) null else blk: {
        if (try storeDirFor(allocator, io, env, projectRoot, folder, want.name, remote)) |hashed| {
            if (util.dirExists(Dir.cwd(), io, hashed)) break :blk hashed;
        }
        if (try storeDirFor(allocator, io, env, projectRoot, folder, want.name, "")) |legacy| {
            if (util.dirExists(Dir.cwd(), io, legacy)) break :blk legacy;
        }
        break :blk null;
    };

    if (store_hit) |store_path| {
        {
            const link = try std.fs.path.join(allocator, &.{ projectRoot, "plugins", folder });
            const had_it = util.dirExists(Dir.cwd(), io, link);

            // WHERE the existing link points, not merely that one exists.
            //
            // Testing only for existence reported "up to date" for a link that
            // was about to be repointed at a different version — or, after an
            // install from a fork's URL, at a different plugin entirely. The
            // relink happened either way; only the message was wrong, which is
            // the worst of both.
            const current = if (had_it) util.linkTarget(allocator, io, link) else null;
            const unchanged = if (current) |c| std.mem.eql(u8, c, store_path) else false;

            if (!opts.dry_run and !unchanged) try linkIntoProject(allocator, io, projectRoot, folder, store_path);

            const entry = lockfile.Entry{
                .name = folder,
                .remote = remote,
                .version = want.name,
                .commit = git.headCommit(allocator, io, env, store_path) orelse "",
                .kernel = constraintOf(allocator, io, util.parentOf(store_path) orelse store_path, std.fs.path.basename(store_path)) orelse "",
            };

            if (unchanged) return .{ .up_to_date = entry };
            if (!had_it) return .{ .linked = entry };

            // Repointed. The version it came FROM is the store_path directory the old
            // link named; a real directory (a pre-store_path install) has no version
            // in its path, so say so rather than inventing one.
            const from = if (current) |c| store.versionOf(std.fs.path.basename(c)) else "an unmanaged copy";
            return .{ .updated = .{ .from = from, .to = entry } };
        }
    }

    if (already) {
        const current = git.headTag(allocator, io, env, dest) orelse "";
        if (std.mem.eql(u8, current, want.name)) {
            return .{ .up_to_date = .{
                .name = folder,
                .remote = remote,
                .version = want.name,
                .commit = git.headCommit(allocator, io, env, dest) orelse "",
                .kernel = constraintOf(allocator, io, pluginsDir, folder) orelse "",
            } };
        }

        // Never discard someone's in-progress edits without being told to.
        if (!opts.force and git.isDirty(allocator, io, env, dest)) {
            return .{ .refused = try std.fmt.allocPrint(
                allocator,
                "{s} has uncommitted local changes. Commit or stash them, or pass --force to discard them.",
                .{name},
            ) };
        }

        if (opts.dry_run) {
            return .{ .updated = .{ .from = current, .to = .{
                .name = folder,
                .remote = remote,
                .version = want.name,
                .kernel = constraintOf(allocator, io, pluginsDir, folder) orelse "",
            } } };
        }

        git.updateTo(allocator, io, env, dest, want.name) catch |e| {
            return .{ .refused = try std.fmt.allocPrint(allocator, "{s}: {s}", .{ name, git.explain(e) }) };
        };

        // Gate AFTER the checkout — the constraint belongs to the new version,
        // not the one that was there before.
        if (try gateMessage(allocator, env, name, constraintOf(allocator, io, pluginsDir, name))) |msg| {
            // Put the previous version back so a refused update leaves a
            // working tree, not a plugin the project cannot boot with.
            if (current.len > 0) git.updateTo(allocator, io, env, dest, current) catch {};
            return .{ .refused = msg };
        }

        return .{ .updated = .{ .from = current, .to = .{
            .name = name,
            .remote = remote,
            .version = want.name,
            .commit = git.headCommit(allocator, io, env, dest) orelse "",
            .kernel = constraintOf(allocator, io, pluginsDir, folder) orelse "",
        } } };
    }

    if (opts.dry_run) {
        return .{ .installed = .{ .name = name, .remote = remote, .version = want.name } };
    }

    // Stage into a sibling path so a rejected plugin never lands in plugins/.
    const staging = try std.fmt.allocPrint(allocator, "{s}.hkm-staging", .{dest});
    Dir.cwd().deleteTree(io, staging) catch {};

    git.cloneAt(allocator, io, env, remote, staging, want.name, !opts.full) catch |e| {
        Dir.cwd().deleteTree(io, staging) catch {};
        return .{ .refused = try std.fmt.allocPrint(
            allocator,
            "{s}: {s} ({s})",
            .{ name, git.explain(e), remote },
        ) };
    };

    // Read the constraint out of the STAGED copy.
    const staged_parent = std.fs.path.dirname(staging) orelse pluginsDir;
    const staged_name = std.fs.path.basename(staging);
    const constraint = constraintOf(allocator, io, staged_parent, staged_name);

    // The plugin's own module.json outranks the repository name.
    //
    // Installing by name, the two always agree. Installing by URL they need
    // not: a fork called `our-logger`, or a repo that simply spells its name
    // differently, would land in plugins/OurLogger while its classes live in
    // Plugins\Logger — present on disk, invisible to PSR-4, and surfacing much
    // later as "Class does not exist". Correct it here, before anything moves.
    if (try sources.readModuleMeta(allocator, io, staged_parent, staged_name)) |meta| {
        if (meta.name) |declared_raw| if (declared_raw.len > 0) {
            const declared = try pregistry.canonicalName(allocator, declared_raw);
            if (!std.mem.eql(u8, declared, folder)) {
                prompt.muted(try std.fmt.allocPrint(
                    allocator,
                    "{s}: the repository declares itself as '{s}' — installing under that name.",
                    .{ folder, declared },
                ));
                folder = declared;
                dest = try std.fs.path.join(allocator, &.{ pluginsDir, folder });
            }
        };
    }

    if (try gateMessage(allocator, env, name, constraint)) |msg| {
        Dir.cwd().deleteTree(io, staging) catch {};
        return .{ .refused = msg };
    }

    // ── Verify BEFORE the plugin lands in plugins/ ───────────────────────────
    //
    // Run in the staging copy so a plugin whose tests fail never reaches the
    // directory the bootstrap wires from. Verifying after the move would mean
    // deciding what to do with a broken plugin that is already installed.
    if (opts.verify) {
        switch (runPluginTests(allocator, io, env, staging, name)) {
            .passed => prompt.ok(try std.fmt.allocPrint(allocator, "{s}: tests passed", .{name})),
            .unavailable => prompt.muted(try std.fmt.allocPrint(
                allocator,
                "{s}: no test suite to run",
                .{name},
            )),
            // Not the plugin's fault, and not something to fail the install
            // over — but say WHY, because "could not verify" with no cause
            // sends people looking at the plugin.
            .blocked => {
                prompt.warn(try std.fmt.allocPrint(
                    allocator,
                    "{s}: could not resolve test dependencies — installed WITHOUT verification.",
                    .{name},
                ));
                prompt.muted("  usually: an unwritable composer cache, no network, or GitHub auth.");
                prompt.muted("  if the cache is root-owned: sudo chown -R \"$USER\" ~/.cache/composer");
            },
            .failed => {
                const accepted = opts.interactive and prompt.confirm(
                    io,
                    try std.fmt.allocPrint(
                        allocator,
                        "{s}: its tests FAILED. Install it anyway?",
                        .{name},
                    ),
                    false,
                );
                if (!accepted) {
                    Dir.cwd().deleteTree(io, staging) catch {};
                    return .{ .refused = try std.fmt.allocPrint(
                        allocator,
                        "{s}: test suite failed — not installed.{s}",
                        .{
                            name,
                            if (opts.interactive)
                                ""
                            else
                                " Nothing could ask, so it was skipped rather than installed unverified; re-run interactively to override.",
                        },
                    ) };
                }
                prompt.warn(try std.fmt.allocPrint(
                    allocator,
                    "{s}: installing despite failing tests, at your request.",
                    .{name},
                ));
            },
        }
    }

    // Only now that it is trusted: drop the development artefacts.
    stripDevArtefacts(io, allocator, staging);

    // Land it in the version-keyed store when one is available, so the copy is
    // shared; fall back to the flat plugins/ layout when it is not.
    const final_dest = if (storable) blk_outer: {
        const store_path = (try storeDirFor(allocator, io, env, projectRoot, folder, want.name, remote)) orelse break :blk_outer dest;
        if (util.parentOf(store_path)) |parent| Dir.cwd().createDirPath(io, parent) catch {};
        break :blk_outer store_path;
    } else dest;

    Dir.cwd().createDirPath(io, pluginsDir) catch {};

    // Landing flat, over a path that is currently a link into the store: drop
    // the link first. Renaming onto it would either fail or, worse, follow it
    // and write through into the shared copy.
    if (std.mem.eql(u8, final_dest, dest) and util.isSymlink(io, dest)) {
        Dir.cwd().deleteFile(io, dest) catch {};
    }

    Dir.cwd().rename(staging, Dir.cwd(), final_dest, io) catch {
        Dir.cwd().deleteTree(io, staging) catch {};
        return .{ .refused = try std.fmt.allocPrint(
            allocator,
            "{s}: fetched successfully but could not be moved into {s}.",
            .{ name, final_dest },
        ) };
    };

    // Point the project at the version it just pinned. Without this the plugin
    // sits in the store and the project cannot see it.
    if (!std.mem.eql(u8, final_dest, dest)) {
        try linkIntoProject(allocator, io, projectRoot, folder, final_dest);
    }

    // The composer that owns the directory needs its classmap regenerated
    // before the new plugin is discoverable.
    //
    // Two directories can be involved — the shared kernel's and the project's —
    // but for a third-party plugin they are the SAME path, and dumping it twice
    // rebuilt the entire classmap for no gain. Deferred entirely when the caller
    // is installing a batch and will dump once at the end.
    if (!opts.defer_autoload) {
        const project_plugins = try std.fs.path.join(allocator, &.{ projectRoot, "plugins" });
        refreshAutoload(allocator, io, env, pluginsDir);
        if (!std.mem.eql(u8, pluginsDir, project_plugins)) {
            refreshAutoload(allocator, io, env, project_plugins);
        }
    }

    return .{ .installed = .{
        .name = folder,
        .remote = remote,
        .version = want.name,
        .commit = git.headCommit(allocator, io, env, final_dest) orelse "",
        .kernel = constraint orelse "",
    } };
}

/// Link `<project>/plugins/<Name>` at the store copy the project pinned.
///
/// A symlink rather than a copy: the point of the store is that one version
/// exists once on disk. The project's own composer maps Plugins\\ to plugins/,
/// so it resolves THROUGH the link — which is what makes the pinned version a
/// per-project fact rather than a machine-wide one.
fn linkIntoProject(
    allocator: std.mem.Allocator,
    io: Io,
    projectRoot: []const u8,
    folder: []const u8,
    target: []const u8,
) !void {
    const plugins = try std.fs.path.join(allocator, &.{ projectRoot, "plugins" });
    Dir.cwd().createDirPath(io, plugins) catch {};

    const link = try std.fs.path.join(allocator, &.{ plugins, folder });

    // Create the new link under a temporary name and RENAME it over the old
    // one. Deleting first and linking second leaves a window — and, if the
    // symlink call fails, a permanent state — where the project has no plugin
    // at all, having had a working one a moment earlier. rename(2) replaces
    // atomically.
    const tmp = try std.fmt.allocPrint(allocator, "{s}.hkm-new", .{link});
    Dir.cwd().deleteFile(io, tmp) catch {};
    Dir.cwd().deleteTree(io, tmp) catch {};

    Dir.cwd().symLink(io, target, tmp, .{ .is_directory = true }) catch |e| {
        prompt.warn(std.fmt.allocPrint(
            allocator,
            "{s}: could not link into the project ({t}) — the plugin is in the store but this project cannot see it.",
            .{ folder, e },
        ) catch folder);
        return e;
    };

    // A previous FLAT install leaves a real directory; rename cannot replace a
    // non-empty directory, so that one case still needs an explicit removal.
    if (!util.isSymlink(io, link) and util.dirExists(Dir.cwd(), io, link)) {
        Dir.cwd().deleteTree(io, link) catch {};
    }

    Dir.cwd().rename(tmp, Dir.cwd(), link, io) catch |e| {
        Dir.cwd().deleteFile(io, tmp) catch {};
        prompt.warn(std.fmt.allocPrint(
            allocator,
            "{s}: could not link into the project ({t}) — the plugin is in the store but this project cannot see it.",
            .{ folder, e },
        ) catch folder);
        return e;
    };
}

/// Record an outcome in the project's lock file.
pub fn recordInLock(
    allocator: std.mem.Allocator,
    io: Io,
    projectRoot: []const u8,
    entry: lockfile.Entry,
) !void {
    var lock = try lockfile.read(allocator, io, projectRoot);
    try lock.put(allocator, entry);
    try lockfile.write(allocator, io, projectRoot, &lock, banner.version());
}

/// Print a one-line summary of an outcome. Returns the exit code it implies.
pub fn report(allocator: std.mem.Allocator, name: []const u8, outcome: Outcome, dry_run: bool) !u8 {
    switch (outcome) {
        .installed => |e| {
            prompt.ok(try std.fmt.allocPrint(allocator, "{s}{s}  {s}", .{
                if (dry_run) "would install  " else "installed  ",
                name,
                e.version,
            }));
            return 0;
        },
        .up_to_date => |e| {
            prompt.muted(try std.fmt.allocPrint(allocator, "up to date  {s}  {s}", .{ name, e.version }));
            return 0;
        },
        .linked => |e| {
            // Say what happened: the project gained the plugin, it just did not
            // need downloading because another project already had that version.
            prompt.ok(try std.fmt.allocPrint(allocator, "linked  {s}  {s}  (already in the store)", .{ name, e.version }));
            return 0;
        },
        .updated => |u| {
            prompt.ok(try std.fmt.allocPrint(allocator, "{s}{s}  {s} → {s}", .{
                if (dry_run) "would update  " else "updated  ",
                name,
                if (u.from.len > 0) u.from else "(untagged)",
                u.to.version,
            }));
            return 0;
        },
        .refused => |why| {
            prompt.err(why);
            return 1;
        },
    }
}
