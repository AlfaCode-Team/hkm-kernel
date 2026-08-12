const std = @import("std");
const builtin = @import("builtin");
const conf = @import("../constants.zig");
const tracked = @import("inspector/tracked.zig");

const Allocator = std.mem.Allocator;
const DebugConfig = std.heap.DebugAllocatorConfig;

/// Apply the debug-only settings on top of a caller's config.
///
/// These are deliberately gated on `__DEBUG__`: `never_unmap` keeps freed pages
/// mapped so a use-after-free faults instead of silently reading recycled
/// memory, and `retain_metadata` keeps allocation metadata so a double-free is
/// reported against the original allocation. Both trade memory for diagnostics,
/// so neither belongs in a release build.
fn resolveConfig(comptime config: DebugConfig) DebugConfig {
    var c = config;
    if (conf.__DEBUG__) {
        c.retain_metadata = true; // catch double-frees
        c.never_unmap = true; // catch use-after-free
    }
    return c;
}

/// The debug allocator type for a given config.
///
/// Split out from the value constructor below because Zig needs the *type* in
/// return positions and `var`/field declarations, and it must be spelled with
/// the same resolved config or the types will not match.
pub fn DebugAllocType(comptime config: DebugConfig) type {
    return std.heap.DebugAllocator(resolveConfig(config));
}

/// Construct a debug allocator instance.
///
/// Previously this declared its return type as `std.heap.DebugAllocator()` —
/// with no argument — which is a compile error, since DebugAllocator is a
/// generic taking a Config. It went unnoticed because nothing called it and Zig
/// only analyses a function body when it is referenced.
///
/// Callers must keep the returned value alive and call `deinit()` on it; the
/// allocator interface borrows a pointer to it.
pub fn debugAlloc(comptime config: DebugConfig) DebugAllocType(config) {
    return DebugAllocType(config){};
}

/// Outcome of tearing down a Manager, so a caller can decide what to do about
/// leaks (the CLI turns this into a non-zero exit code under HKM_MEM_STRICT).
pub const Teardown = enum { ok, leaked };

/// HKM MEMORY MANAGER
///
/// One object that owns the whole allocation strategy for a process, so command
/// code just asks for `.allocator()` and never has to care which mode it is in.
///
/// Debug builds get:
///   DebugAllocator (leak + double-free + use-after-free detection)
///     └─ MemInspector (per-group stats, live dashboard, leak backtraces)
///
/// Release builds get the page allocator directly. The inspector wrapper
/// compiles to nothing and the debug allocator is never instantiated, so there
/// is no runtime cost and no binary-size cost for shipping this.
///
/// Usage:
///   var mm = memory.Manager.init();
///   defer _ = mm.deinit("hkm");     // prints the dashboard, reports leaks
///   const alloc = mm.allocator();
///   mm.group("discover");           // tag everything after this point
///
/// IMPORTANT: `deinit` only runs if the process does not call
/// `std.process.exit()`, which skips every defer. Return exit codes up to
/// main and exit once, at the very end.
pub const Manager = struct {
    /// Present only in debug builds; `void` (zero bytes) otherwise.
    debug_gpa: if (conf.__DEBUG__) DebugAllocType(.{}) else void,
    tracker: tracked.Tracked,

    /// Set once `report` has run, so an explicit report followed by deinit does
    /// not print the dashboard twice.
    reported: bool = false,

    /// Whether `tracker` has been pointed at the backing allocator yet.
    wired: bool = false,

    /// Create a manager. The allocator is not usable until the first call to
    /// `allocator()`, `group()` or `report()`, which is deliberate.
    ///
    /// WHY THE TWO-PHASE SETUP
    /// -----------------------
    /// `DebugAllocator.allocator()` returns a fat pointer holding `&self`. If
    /// init() wired the tracker to `self.debug_gpa` and then RETURNED the struct
    /// by value, the returned copy would carry a pointer into the dead stack
    /// frame of init() — every allocation would then write through a dangling
    /// pointer. It segfaults on the first real allocation, and the backtrace
    /// blames whatever innocent code happened to allocate first.
    ///
    /// Wiring lazily on first use means the pointer is always taken at the
    /// manager's FINAL address, so the value is safe to return, move or store
    /// in a struct before it is used.
    pub fn init() Manager {
        return .{
            .debug_gpa = if (conf.__DEBUG__) debugAlloc(.{}) else {},
            .tracker = undefined,
        };
    }

    /// Point the tracker at the backing allocator, once, at the final address.
    fn wire(self: *Manager) void {
        if (self.wired) return;
        self.tracker = tracked.Tracked.init(self.backing());
        self.wired = true;
    }

    /// The real allocator underneath the inspector.
    fn backing(self: *Manager) Allocator {
        if (conf.__DEBUG__) return self.debug_gpa.allocator();
        return std.heap.page_allocator;
    }

    /// Hand this to command code.
    pub fn allocator(self: *Manager) Allocator {
        self.wire();
        return self.tracker.allocator();
    }

    /// Tag subsequent allocations with a name, so the dashboard can attribute
    /// memory to the command that spent it. No-op in release.
    pub fn group(self: *Manager, name: []const u8) void {
        self.wire();
        self.tracker.group(name);
    }

    /// Print the inspector dashboard. Safe to call in release (does nothing).
    pub fn report(self: *Manager, runtime: []const u8) void {
        self.wire();
        self.tracker.report(runtime);
        self.reported = true;
    }

    /// Tear everything down, optionally printing the dashboard first.
    ///
    /// Returns `.leaked` when the debug allocator found unfreed memory. The
    /// result is marked `must_use` at the call site via `_ =` so ignoring it is
    /// at least deliberate.
    pub fn deinit(self: *Manager, runtime: ?[]const u8) Teardown {
        // A manager that was never used has no tracker to tear down and
        // nothing to report — wiring one here just to destroy it would be
        // pointless work on every no-op path.
        if (!self.wired) {
            if (conf.__DEBUG__) {
                return switch (self.debug_gpa.deinit()) {
                    .leak => .leaked,
                    .ok => .ok,
                };
            }
            return .ok;
        }

        if (runtime) |rt| {
            if (!self.reported) self.report(rt);
        }

        self.tracker.deinit();

        if (conf.__DEBUG__) {
            // Must run AFTER the inspector releases its own bookkeeping map,
            // or that map is itself reported as a leak.
            return switch (self.debug_gpa.deinit()) {
                .leak => .leaked,
                .ok => .ok,
            };
        }

        return .ok;
    }
};

/// A scratch arena for work that is freed all at once.
///
/// Command code allocates a lot of short-lived strings (paths, formatted
/// messages). Freeing them individually is noise; an arena drops the whole lot
/// in one call. This wraps the Manager's allocator rather than the page
/// allocator so arena-backed memory still shows up in the inspector.
///
/// Usage:
///   var scratch = memory.scratch(mm.allocator());
///   defer scratch.deinit();
///   const path = try std.fs.path.join(scratch.allocator(), &.{ a, b });
pub fn scratch(backing_allocator: Allocator) std.heap.ArenaAllocator {
    return std.heap.ArenaAllocator.init(backing_allocator);
}

test "manager hands out a working allocator and reports no leak when balanced" {
    var mm = Manager.init();

    const a = mm.allocator();
    mm.group("test");

    const buf = try a.alloc(u8, 256);
    a.free(buf);

    try std.testing.expectEqual(Teardown.ok, mm.deinit(null));
}

test "the inspector tracks a live allocation and clears it on free" {
    // This deliberately does NOT leak. An actual leak would be caught by the
    // DebugAllocator, but its reporter writes to stderr and the test runner
    // treats that as a failed run — so a test asserting `.leaked` reddens the
    // build by design. std's leak detection is std's to verify; what is worth
    // testing here is OUR bookkeeping, which is what the dashboard reads.
    var mm = Manager.init();
    const a = mm.allocator();

    const buf = try a.alloc(u8, 32);
    if (tracked.enabled) {
        try std.testing.expectEqual(@as(u32, 1), mm.tracker.inspector.active.count());
        try std.testing.expectEqual(@as(usize, 32), mm.tracker.inspector.current);
    }

    a.free(buf);
    if (tracked.enabled) {
        try std.testing.expectEqual(@as(u32, 0), mm.tracker.inspector.active.count());
        try std.testing.expectEqual(@as(usize, 0), mm.tracker.inspector.current);
        try std.testing.expectEqual(@as(usize, 32), mm.tracker.inspector.peak);
    }

    try std.testing.expectEqual(Teardown.ok, mm.deinit(null));
}

test "groups attribute memory to the command that spent it" {
    var mm = Manager.init();
    const a = mm.allocator();

    mm.group("alpha");
    const one = try a.alloc(u8, 64);
    mm.group("beta");
    const two = try a.alloc(u8, 16);

    if (tracked.enabled) {
        const mi = &mm.tracker.inspector;
        // General + alpha + beta
        try std.testing.expectEqual(@as(usize, 3), mi.group_count);
        try std.testing.expectEqual(@as(usize, 64), mi.groups[1].current);
        try std.testing.expectEqual(@as(usize, 16), mi.groups[2].current);
    }

    a.free(one);
    a.free(two);
    try std.testing.expectEqual(Teardown.ok, mm.deinit(null));
}

test "an unused manager tears down cleanly" {
    // main() creates the manager before it knows whether anything will allocate
    // (--version does not), so deinit must cope with a manager that was never
    // wired rather than dereferencing an undefined tracker.
    var mm = Manager.init();
    try std.testing.expectEqual(Teardown.ok, mm.deinit(null));
}

test "scratch arena frees everything at once" {
    var mm = Manager.init();
    const a = mm.allocator();

    {
        var arena = scratch(a);
        defer arena.deinit();

        const sa = arena.allocator();
        _ = try sa.alloc(u8, 64);
        _ = try sa.alloc(u8, 64);
        // no individual frees — the arena drops it all
    }

    try std.testing.expectEqual(Teardown.ok, mm.deinit(null));
}
