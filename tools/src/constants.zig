const builtin = @import("builtin");

/// Build-wide debug switch.
///
/// Derived from the build mode rather than hard-coded. It used to be a literal
/// `true`, which meant a RELEASE binary still got the debug allocator's
/// `never_unmap` and `retain_metadata` settings — the first stops freed pages
/// from ever going back to the OS and the second keeps every allocation's
/// metadata alive for the life of the process. Both are exactly what you want
/// while hunting a use-after-free, and both are memory leaks by design in a
/// shipped binary. `hkm` is a short-lived CLI so it would have survived it, but
/// the setting would have been wrong the moment anything long-running used it.
///
/// Because this is comptime-known, every `if (__DEBUG__)` branch is resolved at
/// compile time and the dead side is never codegen'd.
pub const __DEBUG__ = (builtin.mode == .Debug);

/// Whether the memory inspector is compiled in at all.
///
/// Kept separate from `__DEBUG__` so a Debug build can opt out of the tracking
/// overhead (it wraps every allocation and captures a stack trace) while still
/// keeping debug assertions and safety checks.
pub const __INSPECT__ = __DEBUG__;
