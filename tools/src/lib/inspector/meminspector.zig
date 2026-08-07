const std = @import("std");

const Value = std.atomic.Value;
const Allocator = std.mem.Allocator;

/// A tiny spin-lock. Our critical sections are a few field updates, so spinning
/// is cheaper than a full OS mutex. (std.Thread.Mutex was removed in Zig 0.16.)
const SpinLock = struct {
    held: Value(bool) = Value(bool).init(false),

    pub fn lock(self: *SpinLock) void {
        while (self.held.swap(true, .acquire)) std.atomic.spinLoopHint();
    }
    pub fn unlock(self: *SpinLock) void {
        self.held.store(false, .release);
    }
};

/// HKM MEMORY INSPECTOR
/// A wrapping allocator: put it in front of ANY real allocator and it records
/// every allocation, free, and resize — giving you live stats, per-group usage,
/// and leak detection. It does NOT allocate through itself, so its own
/// bookkeeping never pollutes the numbers.
pub const MemInspector = struct {
    backing: Allocator,

    // Protects ALL the fields below from concurrent access. The backing
    // allocator does its own locking; this only guards our bookkeeping.
    mutex: SpinLock = .{},

    // ---- global stats (in bytes / counts) ----
    total_allocated: usize = 0,
    total_freed: usize = 0,
    current: usize = 0,
    peak: usize = 0,
    alloc_count: usize = 0,
    free_count: usize = 0,
    largest: usize = 0,

    // ---- active allocations: address -> what we know about it ----
    active: std.AutoHashMapUnmanaged(usize, Record) = .{},

    // ---- named memory groups (HTTP Server, Database, ...) ----
    groups: [max_groups]Group = undefined,
    group_count: usize = 0,
    current_group: usize = 0,

    // ---- ring buffer of the most recent events ----
    recent: [recent_cap]Event = undefined,
    recent_len: usize = 0,
    recent_head: usize = 0,

    const max_groups = 16;
    const recent_cap = 8;
    const trace_frames = 6; // how many stack frames we remember per allocation

    // For each live allocation we remember its size, its group, and a short
    // stack trace of WHERE it was allocated (so leaks point at real source lines).
    const Record = struct {
        size: usize,
        group: usize,
        trace: [trace_frames]usize = undefined,
        trace_len: usize = 0,
    };
    // ret_addr is the caller's return address, so a recent event can be
    // resolved back to a source line. It used to be hard-coded to 0 at both
    // call sites, which made the field a lie for anything that read it.
    const Event = struct { freed: bool, size: usize, group: usize, ret_addr: usize };
    const Group = struct { name: []const u8, current: usize = 0, peak: usize = 0, allocs: usize = 0 };

    pub fn init(backing: std.mem.Allocator) MemInspector {
        var self = MemInspector{ .backing = backing };
        self.groups[0] = .{ .name = "General" }; // group 0 is the default bucket
        self.group_count = 1;
        return self;
    }

    pub fn deinit(self: *MemInspector) void {
        self.active.deinit(self.backing);
    }

    /// Hand this to your code. Anything allocated through it gets tracked.
    pub fn allocator(self: *MemInspector) std.mem.Allocator {
        return .{ .ptr = self, .vtable = &vtable };
    }

    /// Switch the current group. Future allocations are tagged with this name
    /// until you call group() again. Creates the group if it's new.
    pub fn group(self: *MemInspector, name: []const u8) void {
        self.mutex.lock();
        defer self.mutex.unlock();
        self.current_group = self.groupId(name);
    }

    fn groupId(self: *MemInspector, name: []const u8) usize {
        var i: usize = 0;
        while (i < self.group_count) : (i += 1) {
            if (std.mem.eql(u8, self.groups[i].name, name)) return i;
        }
        if (self.group_count < max_groups) {
            self.groups[self.group_count] = .{ .name = name };
            self.group_count += 1;
            return self.group_count - 1;
        }
        return 0; // fall back to General if we run out of group slots
    }

    // ---------- internal bookkeeping ----------

    fn recordEvent(self: *MemInspector, freed: bool, size: usize, g: usize, ra: usize) void {
        const e = Event{ .freed = freed, .size = size, .group = g, .ret_addr = ra };
        if (self.recent_len < recent_cap) {
            self.recent[(self.recent_head + self.recent_len) % recent_cap] = e;
            self.recent_len += 1;
        } else {
            self.recent[self.recent_head] = e;
            self.recent_head = (self.recent_head + 1) % recent_cap;
        }
    }

    fn onAlloc(self: *MemInspector, addr: usize, size: usize, trace: [trace_frames]usize, trace_len: usize, ra: usize) void {
        self.mutex.lock();
        defer self.mutex.unlock();
        self.active.put(self.backing, addr, .{
            .size = size,
            .group = self.current_group,
            .trace = trace,
            .trace_len = trace_len,
        }) catch {};
        self.total_allocated += size;
        self.current += size;
        if (self.current > self.peak) self.peak = self.current;
        self.alloc_count += 1;
        if (size > self.largest) self.largest = size;

        var gp = &self.groups[self.current_group];
        gp.current += size;
        gp.allocs += 1;
        if (gp.current > gp.peak) gp.peak = gp.current;

        self.recordEvent(false, size, self.current_group, ra);
    }

    fn onFree(self: *MemInspector, addr: usize, size: usize, ra: usize) void {
        self.mutex.lock();
        defer self.mutex.unlock();
        var g: usize = 0;
        if (self.active.fetchRemove(addr)) |kv| g = kv.value.group;
        self.total_freed += size;
        self.current -|= size; // saturating subtract, never underflows
        self.free_count += 1;
        self.groups[g].current -|= size;
        self.recordEvent(true, size, g, ra);
    }

    fn adjustResize(self: *MemInspector, rec: *Record, new_len: usize) void {
        const old = rec.size;
        rec.size = new_len;
        if (new_len > old) {
            const d = new_len - old;
            self.current += d;
            self.total_allocated += d;
            self.groups[rec.group].current += d;
            if (self.current > self.peak) self.peak = self.current;
        } else {
            const d = old - new_len;
            self.current -|= d;
            self.total_freed += d;
            self.groups[rec.group].current -|= d;
        }
    }

    // ---------- the allocator vtable ----------

    const vtable = Allocator.VTable{
        .alloc = alloc,
        .resize = resize,
        .remap = remap,
        .free = free,
    };

    fn alloc(ctx: *anyopaque, len: usize, alignment: std.mem.Alignment, ra: usize) ?[*]u8 {
        const self: *MemInspector = @ptrCast(@alignCast(ctx));
        const res = self.backing.rawAlloc(len, alignment, ra);
        if (res) |ptr| {
            // Record WHERE this allocation happened. `first_address = ra` skips
            // our own inspector frames so the trace starts at the caller's code.
            var buf: [trace_frames]usize = undefined;
            const st = std.debug.captureCurrentStackTrace(.{ .first_address = ra }, &buf);
            self.onAlloc(@intFromPtr(ptr), len, buf, st.return_addresses.len, ra);
        }
        return res;
    }

    fn resize(ctx: *anyopaque, memory: []u8, alignment: std.mem.Alignment, new_len: usize, ra: usize) bool {
        const self: *MemInspector = @ptrCast(@alignCast(ctx));
        const ok = self.backing.rawResize(memory, alignment, new_len, ra);
        if (ok) {
            self.mutex.lock();
            defer self.mutex.unlock();
            if (self.active.getPtr(@intFromPtr(memory.ptr))) |rec| self.adjustResize(rec, new_len);
        }
        return ok;
    }

    fn remap(ctx: *anyopaque, memory: []u8, alignment: std.mem.Alignment, new_len: usize, ra: usize) ?[*]u8 {
        const self: *MemInspector = @ptrCast(@alignCast(ctx));
        const res = self.backing.rawRemap(memory, alignment, new_len, ra);
        if (res) |ptr| {
            self.mutex.lock();
            defer self.mutex.unlock();
            if (self.active.fetchRemove(@intFromPtr(memory.ptr))) |kv| {
                var rec = kv.value;
                self.adjustResize(&rec, new_len);
                self.active.put(self.backing, @intFromPtr(ptr), rec) catch {};
            }
        }
        return res;
    }

    fn free(ctx: *anyopaque, memory: []u8, alignment: std.mem.Alignment, ra: usize) void {
        const self: *MemInspector = @ptrCast(@alignCast(ctx));
        self.onFree(@intFromPtr(memory.ptr), memory.len, ra);
        self.backing.rawFree(memory, alignment, ra);
    }
};
