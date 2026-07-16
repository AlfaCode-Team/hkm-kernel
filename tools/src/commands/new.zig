//! `hkm new <path>` — native project scaffolder.
//!
//! Creates a FLAT standalone PSP project (the hybrid global-kernel model: the
//! kernel is installed globally, the project owns its own plugins + src/ via a
//! local composer.json + vendor/). The generated layout matches the working
//! demo at psp-shop: a scaffolded directory IS the project root.
//!
//! This is implemented natively in Zig so a packaged install can scaffold a
//! project with NO PHP / Composer present — only `composer install` is needed
//! afterwards to pull the kernel + plugins.
//!
//! The generated files are kept as real templates under `templates/`
//! and read from a templates DIRECTORY at runtime — they are NOT embedded in the
//! binary, so they can be edited without recompiling. Resolution order for the
//! directory (first hit wins):
//!
//!   1. HKM_TEMPLATES_DIR                         (explicit override)
//!   2. HKM_KERNEL_HOME/templates       (dev / installed kernel)
//!   3. <exe_dir>/templates                       (packaged alongside binary)
//!   4. <exe_dir>/../share/hkm/templates          (packaged FHS layout)
//!   5. <kernel_root>/templates         (inferred from the registry)
//!
//! If none can be found, `new` fails with a clear message — there is no
//! compiled-in fallback. Templates use three tokens substituted per project:
//! `{{PROJECT_NAME}}`, `{{STUDLY}}` and `{{DOMAINS_JSON}}`.

const std = @import("std");
const registry = @import("../lib/registry.zig");
const prompt = @import("../lib/prompt.zig");
const util = @import("../lib/util.zig");
const services = @import("../lib/services.zig");
const plugin_assets = @import("../lib/plugin_assets.zig");

const Dir = std.Io.Dir;
const Io = std.Io;
const EnvMap = std.process.Environ.Map;

/// One generated file:
///   dest — path written inside the new project
///   src  — relative path under the templates dir (null = always-empty file)
const Template = struct {
    dest: []const u8,
    src: ?[]const u8 = null,
};

/// Every file the scaffolder writes. `src` is read from the resolved templates
/// dir at runtime (relative to the templates dir).
const templates = [_]Template{
    .{ .dest = "proj.json", .src = "proj.json" },
    .{ .dest = "composer.json", .src = "composer.json" },
    .{ .dest = ".env.example", .src = "env.example" },
    .{ .dest = ".env", .src = "env.example" },
    .{ .dest = ".gitignore", .src = "gitignore" },
    .{ .dest = "README.md", .src = "README.md" },
    .{ .dest = "app/bootstrap/kernel-autoload.php", .src = "app/bootstrap/kernel-autoload.php" },
    .{ .dest = "app/bootstrap/app.php", .src = "app/bootstrap/app.php" },
    .{ .dest = "app/public/index.php", .src = "app/public/index.php" },
    .{ .dest = "app/public/.htaccess", .src = "app/public/.htaccess" },
    .{ .dest = "app/nginx.conf.example", .src = "app/nginx.conf.example" },
    .{ .dest = "app/apache.conf.example", .src = "app/apache.conf.example" },
    .{ .dest = "app/swoole/index.php", .src = "app/swoole/index.php" },
    .{ .dest = "app/cli/run.php", .src = "app/cli/run.php" },
    .{ .dest = "app/worker/run.php", .src = "app/worker/run.php" },
    .{ .dest = "config/storage.php", .src = "config/storage.php" },
    .{ .dest = "config/let-migrate.php", .src = "config/let-migrate.php" },
    .{ .dest = "config/environments/local.php", .src = "config/environments/local.php" },
    .{ .dest = "config/environments/production.php", .src = "config/environments/production.php" },
    .{ .dest = "config/environments/staging.php", .src = "config/environments/staging.php" },
    .{ .dest = "config/environments/testing.php", .src = "config/environments/testing.php" },
    .{ .dest = "src/README.md", .src = "src/README.md" },
    .{ .dest = "src/Domain/Greeting.php", .src = "src/Domain/Greeting.php" },
    .{ .dest = "src/Application/GreetingService.php", .src = "src/Application/GreetingService.php" },
    .{ .dest = "src/Infrastructure/Http/HomeController.php", .src = "src/Infrastructure/Http/HomeController.php" },
    .{ .dest = "src/Application/Ports/.gitkeep" },
    .{ .dest = "src/Infrastructure/Persistence/.gitkeep" },
    .{ .dest = "resources/welcome.php", .src = "resources/welcome.php" },
    .{ .dest = "var/.gitkeep" },
    .{ .dest = "userdata/.gitkeep" },
};

/// Directory tree created before any file is written.
const dirs = [_][]const u8{
    "app/bootstrap",
    "app/public",
    "app/swoole",
    "app/cli",
    "app/worker",
    "src/Domain",
    "src/Application/Ports",
    "src/Infrastructure/Http",
    "src/Infrastructure/Persistence",
    "config",
    "config/environments",
    "database/migrations",
    "database/seeders",
    "database/factories",
    "resources/layouts",
    "var/logs",
    "var/cache/manifests",
    "var/tmp",
    "var/locks",
    "var/sessions",
    "var/queue",
    "userdata/storage",
};

/// Options parsed from argv for the `new` command.
const Options = struct {
    /// Target directory the project is created in.
    path: []const u8,
    /// Lower-case project name (proj.json "name"); defaults to basename(path).
    name: []const u8,
    /// StudlyCase form of `name` — the project src/ PSR-4 root namespace.
    studly: []const u8,
    /// Domains for proj.json + the kernel registry. Null until resolved (flag or
    /// interactive prompt); see resolveDomains().
    domains: ?[]const []const u8 = null,
    /// --no-register skips writing to the kernel projects.json registry.
    register: bool = true,
    /// --no-install skips running `composer install` after scaffolding.
    install: bool = true,
    /// --no-key skips generating + writing APP_KEY into .env.
    key: bool = true,
};

/// Parse `hkm new <path> [--project=<name>] [--domains=a,b]
///                       [--no-register] [--no-install] [--no-key]`.
/// argv[0] = exe, argv[1] = "new", argv[2] = path, then flags.
fn parse(allocator: std.mem.Allocator, args: []const []const u8) !?Options {
    var path: ?[]const u8 = null;
    var name: ?[]const u8 = null;
    var domains_csv: ?[]const u8 = null;
    var register = true;
    var install = true;
    var key = true;

    var i: usize = 2;
    while (i < args.len) : (i += 1) {
        const a = args[i];
        if (std.mem.startsWith(u8, a, "--project=")) {
            name = a["--project=".len..];
        } else if (std.mem.eql(u8, a, "--project")) {
            if (i + 1 >= args.len) return error.MissingProjectValue;
            i += 1;
            name = args[i];
        } else if (std.mem.startsWith(u8, a, "--domains=")) {
            domains_csv = a["--domains=".len..];
        } else if (std.mem.eql(u8, a, "--domains")) {
            if (i + 1 >= args.len) return error.MissingDomainsValue;
            i += 1;
            domains_csv = args[i];
        } else if (std.mem.eql(u8, a, "--no-register")) {
            register = false;
        } else if (std.mem.eql(u8, a, "--no-install")) {
            install = false;
        } else if (std.mem.eql(u8, a, "--no-key")) {
            key = false;
        } else if (std.mem.startsWith(u8, a, "--")) {
            // unknown flag — ignore so future flags don't hard-fail
            continue;
        } else if (path == null) {
            path = a;
        }
    }

    if (path == null) return null;

    const resolved_name = name orelse basename(path.?);
    return Options{
        .path = path.?,
        .name = try allocator.dupe(u8, resolved_name),
        .studly = try studly(allocator, resolved_name),
        .domains = if (domains_csv) |csv| try splitDomains(allocator, csv) else null,
        .register = register,
        .install = install,
        .key = key,
    };
}

/// Split a comma-separated domain list, trimming blanks. Returns null if the
/// list has no usable entries (caller falls back to a prompt/default).
fn splitDomains(allocator: std.mem.Allocator, csv: []const u8) !?[]const []const u8 {
    var list: std.ArrayList([]const u8) = .empty;
    var it = std.mem.splitScalar(u8, csv, ',');
    while (it.next()) |raw| {
        const d = std.mem.trim(u8, raw, " \t\r\n");
        if (d.len > 0) try list.append(allocator, try allocator.dupe(u8, d));
    }
    if (list.items.len == 0) return null;
    return try list.toOwnedSlice(allocator);
}

/// Last path component of a (possibly trailing-slash) path.
fn basename(path: []const u8) []const u8 {
    var end = path.len;
    while (end > 0 and (path[end - 1] == '/' or path[end - 1] == '\\')) end -= 1;
    var start = end;
    while (start > 0 and path[start - 1] != '/' and path[start - 1] != '\\') start -= 1;
    return path[start..end];
}

/// Convert "psp-shop" / "psp_shop" / "psp shop" → "PspShop" (empty → "App").
fn studly(allocator: std.mem.Allocator, name: []const u8) ![]const u8 {
    const s = try util.studly(allocator, name);
    return if (s.len == 0) try allocator.dupe(u8, "App") else s;
}

/// Scaffold the project. Returns the process exit code.
pub fn run(allocator: std.mem.Allocator, io: Io, env: *EnvMap, args: []const []const u8) !u8 {
    var opts = (try parse(allocator, args)) orelse {
        prompt.intro("hkm new — create a new PhpServicePlatform project");
        prompt.section("Usage");
        prompt.item("hkm new <path>", "scaffold into <path>");
        prompt.item("  --project=<name>", "project name (default: derived from path)");
        prompt.item("  --domains=a.com,b.com", "comma-separated domains to register");
        prompt.item("  --no-register", "skip kernel registry registration");
        prompt.blank();
        prompt.section("Example");
        prompt.note("hkm new ./my-shop --project=shop --domains=shop.localhost,shop.local");
        prompt.outro("Pass a target path to begin");
        return 2;
    };

    const cwd = Dir.cwd();

    prompt.intro(try std.fmt.allocPrint(allocator, "Create project '{s}'", .{opts.name}));

    // Refuse to scaffold into an existing non-empty target (don't clobber).
    if (util.dirExists(cwd, io, opts.path) and !util.dirIsEmpty(cwd, io, opts.path)) {
        prompt.err(try std.fmt.allocPrint(allocator, "Target '{s}' already exists and is not empty.", .{opts.path}));
        return 1;
    }

    // Resolve domains: --domains flag wins; otherwise ask interactively, falling
    // back to "<name>.localhost". These feed BOTH proj.json and the registry.
    opts.domains = try resolveDomains(allocator, io, opts);

    // 1. directory tree
    for (dirs) |sub| {
        const p = try util.join(allocator, opts.path, sub);
        try cwd.createDirPath(io, p);
    }

    // 2. files — read each template from the resolved templates dir, then
    //    substitute tokens. Templates are NOT embedded: a dir is required.
    const tpl_dir = (try services.resolveTemplatesDir(allocator, io, env)) orelse {
        prompt.err("Could not locate the scaffolding templates directory.");
        prompt.muted("Set HKM_TEMPLATES_DIR or HKM_KERNEL_HOME, or run from inside the kernel repo.");
        return 1;
    };
    prompt.muted(try std.fmt.allocPrint(allocator, "templates: {s}", .{tpl_dir}));
    for (templates) |t| {
        const raw = (try templateBody(allocator, io, tpl_dir, t)) orelse {
            prompt.err(try std.fmt.allocPrint(
                allocator,
                "Missing template '{s}' in {s}",
                .{ t.src.?, tpl_dir },
            ));
            return 1;
        };
        const data = try render(allocator, raw, opts);
        const p = try util.join(allocator, opts.path, t.dest);
        try cwd.writeFile(io, .{ .sub_path = p, .data = data });
    }
    prompt.ok(try std.fmt.allocPrint(allocator, "Scaffolded {d} files", .{templates.len}));

    // 3. register the project in the kernel's projects.json registry.
    if (opts.register) {
        try registerProject(allocator, io, env, opts);
    }

    // 4. generate APP_KEY and write it into .env (done natively — no PHP needed).
    if (opts.key) {
        try generateAppKey(allocator, io, opts);
    }

    // 5. composer install (pull the global kernel + plugins).
    if (opts.install) {
        try composerInstall(allocator, io, env, opts);
    }

    // 6. publish the assets (config/migrations/seeders/factories/resources) of
    //    every plugin the project bootstrap enables (copy only — no migrate).
    plugin_assets.publishEnabled(allocator, io, env, opts.path) catch {
        prompt.warn("Could not publish plugin assets — run 'hkm plugins enable <p>' later.");
    };

    // What's left for the user to do by hand.
    prompt.note("");
    prompt.note("Next steps:");
    prompt.muted(try std.fmt.allocPrint(allocator, "  cd {s}", .{opts.path}));
    if (!opts.install) prompt.muted("  composer install");
    prompt.muted("  hkm run                       # or: php -S localhost:8000 -t app/public");

    prompt.outro(try std.fmt.allocPrint(allocator, "Project '{s}' is ready", .{opts.name}));
    return 0;
}

/// Generate a 32-byte APP_KEY (base64), and write it into the project's .env by
/// replacing the empty `APP_KEY=` line. Done in pure Zig so it works even when
/// PHP is not installed.
fn generateAppKey(allocator: std.mem.Allocator, io: Io, opts: Options) !void {
    var raw: [32]u8 = undefined;
    io.random(&raw);

    const Enc = std.base64.standard.Encoder;
    const key = try allocator.alloc(u8, Enc.calcSize(raw.len));
    _ = Enc.encode(key, &raw);

    const env_path = try util.join(allocator, opts.path, ".env");
    const content = Dir.cwd().readFileAlloc(io, env_path, allocator, .limited(1024 * 1024)) catch {
        prompt.warn("Could not read .env — APP_KEY not set.");
        return;
    };

    // Replace the first line beginning with "APP_KEY=" (the empty placeholder).
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
        return;
    }

    try Dir.cwd().writeFile(io, .{ .sub_path = env_path, .data = out.items });

    // The .env now holds the freshly generated APP_KEY (and will hold DB creds,
    // JWT secrets, …). Lock it down to owner-only so it is never world-readable.
    util.chmod600(io, env_path);
    prompt.ok("Generated APP_KEY in .env (chmod 600)");
}

/// Run `composer install` inside the new project. Inherits stdio so the user
/// sees progress; a missing/failing composer is a warning, not a hard error.
fn composerInstall(allocator: std.mem.Allocator, io: Io, env: *EnvMap, opts: Options) !void {
    const composer = env.get("HKM_COMPOSER_BIN") orelse "composer";
    prompt.note("");
    prompt.ok("Running composer install…");

    var child = std.process.spawn(io, .{
        .argv = &.{ composer, "install", "--no-interaction" },
        .environ_map = env,
        .cwd = .{ .path = opts.path },
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

/// Decide the project's domains: the --domains flag if given, else an interactive
/// prompt, else the "<name>.localhost" default.
fn resolveDomains(allocator: std.mem.Allocator, io: Io, opts: Options) ![]const []const u8 {
    if (opts.domains) |d| {
        prompt.ok(try std.fmt.allocPrint(allocator, "Domains: {s}", .{try util.joinList(allocator, d)}));
        return d;
    }

    const default = try std.fmt.allocPrint(allocator, "{s}.localhost", .{opts.name});
    const answer = try prompt.text(allocator, io, "Domains (comma-separated)", default);

    return (try splitDomains(allocator, answer)) orelse blk: {
        var one: std.ArrayList([]const u8) = .empty;
        try one.append(allocator, default);
        break :blk try one.toOwnedSlice(allocator);
    };
}

/// Write/refresh this project's entry in the kernel registry (projects.json).
fn registerProject(allocator: std.mem.Allocator, io: Io, env: *EnvMap, opts: Options) !void {
    const jsonPath = (try registry.resolvePath(allocator, io, env)) orelse {
        prompt.warn("Kernel registry not found — skipping registration.");
        prompt.muted("set PSP_PROJECTS_DIR or HKM_KERNEL_HOME, or run `hkm update` later.");
        return;
    };

    try registry.upsert(allocator, io, jsonPath, .{
        .name = opts.name,
        .version = "1.0.0",
        .path = try util.absPath(allocator, env, opts.path),
        .domains = opts.domains orelse &.{},
    });

    prompt.ok(try std.fmt.allocPrint(allocator, "Registered in {s}", .{jsonPath}));
}

// --------------------------------------------------------------------------
// template directory resolution
// --------------------------------------------------------------------------

/// The body for one template: read `<dir>/<src>` from disk. Templates with no
/// `src` (.gitkeep) are always empty. Returns null when a required source file
/// is missing on disk (the caller turns this into a clear error).
fn templateBody(allocator: std.mem.Allocator, io: Io, dir: []const u8, t: Template) !?[]const u8 {
    const src = t.src orelse return "";
    const path = try std.fmt.allocPrint(allocator, "{s}/{s}", .{ dir, src });
    return Dir.cwd().readFileAlloc(io, path, allocator, .limited(8 * 1024 * 1024)) catch null;
}

// --------------------------------------------------------------------------
// template rendering
// --------------------------------------------------------------------------

/// Replace `{{PROJECT_NAME}}`, `{{STUDLY}}` and `{{DOMAINS_JSON}}` in a body.
fn render(allocator: std.mem.Allocator, body: []const u8, opts: Options) ![]const u8 {
    const a = try services.replace(allocator, body, "{{PROJECT_NAME}}", opts.name);
    const b = try services.replace(allocator, a, "{{STUDLY}}", opts.studly);
    return services.replace(allocator, b, "{{DOMAINS_JSON}}", try domainsJson(allocator, opts.domains orelse &.{}));
}

/// Render the domains as a JSON array literal for proj.json, e.g.
///   ["shop.localhost", "shop.local"]  →  multi-line with 8-space item indent.
fn domainsJson(allocator: std.mem.Allocator, domains: []const []const u8) ![]const u8 {
    if (domains.len == 0) return allocator.dupe(u8, "[]");

    var out: std.ArrayList(u8) = .empty;
    try out.appendSlice(allocator, "[\n");
    for (domains, 0..) |d, i| {
        try out.appendSlice(allocator, "        \"");
        for (d) |c| switch (c) {
            '\\' => try out.appendSlice(allocator, "\\\\"),
            '"' => try out.appendSlice(allocator, "\\\""),
            else => try out.append(allocator, c),
        };
        try out.appendSlice(allocator, "\"");
        if (i + 1 < domains.len) try out.appendSlice(allocator, ",");
        try out.appendSlice(allocator, "\n");
    }
    try out.appendSlice(allocator, "    ]");
    return out.toOwnedSlice(allocator);
}
