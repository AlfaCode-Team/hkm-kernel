//! Cross-command resolution services: where the project root, the kernel
//! autoload, and the scaffolding templates live, plus the template-token
//! `replace`. These were previously private to the `run` / `new` commands;
//! hoisting them here lets every command depend on `lib/` rather than on each
//! other (no command-to-command imports).

const std = @import("std");
const registry = @import("registry.zig");
const kernel = @import("kernel.zig");
const util = @import("util.zig");

const Dir = std.Io.Dir;
const Io = std.Io;
const EnvMap = std.process.Environ.Map;

/// Resolve the project root directory from a path or registered name.
/// Returns an absolute path to a folder that contains a proj.json.
pub fn resolveRoot(allocator: std.mem.Allocator, io: Io, env: *EnvMap, target: []const u8) !?[]const u8 {
    // No target → current working directory.
    const candidate = if (target.len == 0) (env.get("PWD") orelse ".") else target;

    // PATH MODE: an existing directory holding proj.json.
    const abs = try util.absPath(allocator, env, candidate);
    if (util.fileExists(io, try std.fmt.allocPrint(allocator, "{s}/proj.json", .{abs}))) {
        return abs;
    }

    // NAME MODE: look the name up in the kernel registry.
    if (target.len > 0) {
        if (try registry.resolvePath(allocator, io, env)) |jsonPath| {
            if (try registry.find(allocator, io, jsonPath, target)) |entry| {
                if (util.fileExists(io, try std.fmt.allocPrint(allocator, "{s}/proj.json", .{entry.path}))) {
                    return entry.path;
                }
            }
        }
    }
    return null;
}

/// Resolve the kernel's vendor/autoload.php to export as PSP_GLOBAL_AUTOLOAD.
///
/// Order: PSP_GLOBAL_AUTOLOAD (kept) → HKM_GLOBAL_AUTOLOAD → HKM_KERNEL_HOME →
/// the kernel root inferred from the registry path (<kernel>/projects/...).
pub fn resolveAutoload(allocator: std.mem.Allocator, io: Io, env: *EnvMap) !?[]const u8 {
    if (env.get("PSP_GLOBAL_AUTOLOAD")) |v| {
        if (v.len > 0) return v;
    }
    if (env.get("HKM_GLOBAL_AUTOLOAD")) |v| {
        if (v.len > 0) return v;
    }
    // Self-locate the kernel (HKM_KERNEL_HOME, then relative to this executable,
    // then /opt/hkm-kernel) and use its vendor/autoload.php. This makes an
    // installed launcher use the INSTALLED kernel — not a dev tree found via PWD.
    if (try kernel.resolveHome(allocator, io, env)) |home| {
        const p = try std.fmt.allocPrint(allocator, "{s}/vendor/autoload.php", .{home});
        if (util.fileExists(io, p)) return p;
    }
    if (try registry.resolvePath(allocator, io, env)) |jsonPath| {
        if (util.parentOf(util.parentOf(jsonPath))) |kernel_root| {
            const p = try std.fmt.allocPrint(allocator, "{s}/vendor/autoload.php", .{kernel_root});
            if (util.fileExists(io, p)) return p;
        }
    }
    return null;
}

/// Resolve the kernel HOME directory (the kernel root, parent of its `vendor/`).
///
/// Order: HKM_KERNEL_HOME (kept) → `<autoload>/../..` when the resolved autoload
/// looks like `<home>/vendor/autoload.php` → the kernel root inferred from the
/// registry path (`<kernel>/projects/projects.json`). Returns null when none
/// apply. Used to export HKM_KERNEL_HOME to child processes so a served app's
/// runtime `getenv('HKM_KERNEL_HOME')` resolves.
pub fn resolveKernelHome(allocator: std.mem.Allocator, io: Io, env: *EnvMap, autoload: ?[]const u8) !?[]const u8 {
    // <home>/vendor/autoload.php → <home> (keeps home consistent with the autoload
    // we already resolved).
    if (autoload) |a| {
        if (std.mem.endsWith(u8, a, "/vendor/autoload.php")) {
            if (util.parentOf(util.parentOf(a))) |home| return home;
        }
    }
    if (try kernel.resolveHome(allocator, io, env)) |home| return home;
    if (try registry.resolvePath(allocator, io, env)) |jsonPath| {
        if (util.parentOf(util.parentOf(jsonPath))) |kernel_root| {
            if (util.dirExists(Dir.cwd(), io, kernel_root)) return kernel_root;
        }
    }
    return null;
}

/// Resolve the DIRECTORY that holds the project registry (projects.json), to
/// export as PSP_PROJECTS_DIR — so a child (the kernel, plugins like Edge) reads
/// the SAME registry the launcher does, without having to re-derive it. Honours
/// an explicit PSP_PROJECTS_DIR, else takes the parent of the resolved
/// registry file (which itself prefers HKM_USERDATA_DIR, then HKM_KERNEL_HOME).
pub fn resolveProjectsDir(allocator: std.mem.Allocator, io: Io, env: *EnvMap) !?[]const u8 {
    if (env.get("PSP_PROJECTS_DIR")) |d| {
        if (d.len > 0) return try allocator.dupe(u8, util.trimSlash(d));
    }
    const jsonPath = (try registry.resolvePath(allocator, io, env)) orelse return null;
    if (std.fs.path.dirname(jsonPath)) |dir| return try allocator.dupe(u8, dir);
    return null;
}

/// Resolve a directory holding the scaffolding templates, or null.
/// Probes each candidate for a `proj.json`; HKM_TEMPLATES_DIR is trusted
/// without the probe so a partial override directory still works.
pub fn resolveTemplatesDir(allocator: std.mem.Allocator, io: Io, env: *EnvMap) !?[]const u8 {
    if (env.get("HKM_TEMPLATES_DIR")) |d| {
        if (d.len > 0) return util.trimSlash(d);
    }
    // The templates ship INSIDE the kernel payload at <kernel>/templates (they
    // used to live under tools/, which is not shipped). Self-locate the kernel.
    if (try kernel.resolveHome(allocator, io, env)) |home| {
        const c = try std.fmt.allocPrint(allocator, "{s}/templates", .{home});
        if (templatesDirOk(io, c)) return c;
    }
    // Locations relative to the installed executable (packaged distributions).
    if (std.process.executableDirPathAlloc(io, allocator)) |exe_dir| {
        const beside = try std.fmt.allocPrint(allocator, "{s}/templates", .{util.trimSlash(exe_dir)});
        if (templatesDirOk(io, beside)) return beside;
        const fhs = try std.fmt.allocPrint(allocator, "{s}/../share/hkm/templates", .{util.trimSlash(exe_dir)});
        if (templatesDirOk(io, fhs)) return fhs;
    } else |_| {}
    // Fallback: infer the kernel root from the registry path.
    if (try registry.resolvePath(allocator, io, env)) |jsonPath| {
        if (util.parentOf(util.parentOf(jsonPath))) |kernel_root| {
            const c = try std.fmt.allocPrint(allocator, "{s}/templates", .{kernel_root});
            if (templatesDirOk(io, c)) return c;
        }
    }
    return null;
}

/// A templates dir is usable if it contains a `proj.json` template.
fn templatesDirOk(io: Io, dir: []const u8) bool {
    var buf: [4096]u8 = undefined;
    const probe = std.fmt.bufPrint(&buf, "{s}/proj.json", .{dir}) catch return false;
    Dir.cwd().access(io, probe, .{}) catch return false;
    return true;
}

/// Replace every occurrence of `needle` with `value` in `input`.
pub fn replace(allocator: std.mem.Allocator, input: []const u8, needle: []const u8, value: []const u8) ![]const u8 {
    var out: std.ArrayList(u8) = .empty;
    errdefer out.deinit(allocator);

    var rest = input;
    while (std.mem.indexOf(u8, rest, needle)) |idx| {
        try out.appendSlice(allocator, rest[0..idx]);
        try out.appendSlice(allocator, value);
        rest = rest[idx + needle.len ..];
    }
    try out.appendSlice(allocator, rest);
    return out.toOwnedSlice(allocator);
}
