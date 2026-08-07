const std = @import("std");
const builtin = @import("builtin");
const MemInspector = @import("meminspector.zig").MemInspector;
const dashboard = @import("dashboard.zig");

// THE SWITCH: on in Debug builds (development), off in optimized (production).
// Because this is comptime-known, the compiler removes the whole inspector
// from release binaries — the disabled branches are never even compiled.
pub const enabled = (builtin.mode == .Debug);
const Allocator = std.mem.Allocator;

/// A drop-in allocator wrapper. In development it tracks everything; in a
/// production build it becomes a thin pass-through to the real allocator.
pub const Tracked = struct {
    backing: Allocator,
    // In release this field is `void` — it takes up zero bytes.
    inspector: if (enabled) MemInspector else void,

    pub fn init(backing: Allocator) Tracked {
        return .{
            .backing = backing,
            .inspector = if (enabled) MemInspector.init(backing) else {},
        };
    }

    pub fn deinit(self: *Tracked) void {
        if (enabled) self.inspector.deinit();
    }

    /// Hand this to your code. Dev: the tracked allocator. Prod: the real one.
    pub fn allocator(self: *Tracked) Allocator {
        if (enabled) return self.inspector.allocator();
        return self.backing;
    }

    pub fn group(self: *Tracked, name: []const u8) void {
        if (enabled) self.inspector.group(name);
        // in production this is a no-op that compiles to nothing
    }

    pub fn report(self: *Tracked, runtime: []const u8) void {
        if (enabled) dashboard.render(&self.inspector, runtime);
        // in production: nothing happens, nothing is compiled
    }
};
