//! Test aggregator — the single root the `test` step compiles.
//!
//! Zig collects tests only from files it actually analyses, and analysis is
//! lazy: a file imported but whose declarations are never referenced along a
//! compiled path contributes NOTHING, tests included. Pointing the test step at
//! `main.zig` therefore ran whichever tests the command graph happened to drag
//! in and silently skipped the rest — nine of them, spread across
//! plugin_store, plugin_domains and plugin_bootstrap, among them the checks
//! guarding fork/version collisions in the plugin cache and the "a commented-out
//! provider is not enabled" rule.
//!
//! A test that never runs is worse than no test: it reports safety it is not
//! providing. Referencing every file here forces each one to be analysed, so a
//! new `test "..."` block runs the moment it is written.
//!
//! Generated from `find src -name '*.zig'`. Adding a source file means adding a
//! line here BY HAND; nothing enforces it, because the enforcement would need a
//! directory walk and every Zig filesystem API that could do it differs between
//! the pinned toolchain and the one likely to be installed. Until that is
//! settled, the check is:
//!
//!     find src -name '*.zig' | sed 's|^src/||' | grep -vE '^(main|tests)\.zig$' \
//!       | while read f; do grep -q "\"$f\"" src/tests.zig || echo "MISSING: $f"; done

const std = @import("std");

test {
    _ = @import("commands/cli.zig");
    _ = @import("commands/discover.zig");
    _ = @import("commands/doctor.zig");
    _ = @import("commands/list.zig");
    _ = @import("commands/module.zig");
    _ = @import("commands/new.zig");
    _ = @import("commands/plugins.zig");
    _ = @import("commands/run.zig");
    _ = @import("commands/ui.zig");
    _ = @import("commands/update.zig");
    _ = @import("commands/upgrade.zig");
    _ = @import("commands/version.zig");
    _ = @import("config.zig");
    _ = @import("constants.zig");
    _ = @import("lib/banner.zig");
    _ = @import("lib/composer_version.zig");
    _ = @import("lib/install_scope.zig");
    _ = @import("lib/inspector/dashboard.zig");
    _ = @import("lib/inspector/meminspector.zig");
    _ = @import("lib/inspector/tracked.zig");
    _ = @import("lib/kernel.zig");
    _ = @import("lib/memory.zig");
    _ = @import("lib/plugin_assets.zig");
    _ = @import("lib/plugin_bootstrap.zig");
    _ = @import("lib/plugin_env.zig");
    _ = @import("lib/plugin_deps.zig");
    _ = @import("lib/plugin_domains.zig");
    _ = @import("lib/plugin_git.zig");
    _ = @import("lib/plugin_install.zig");
    _ = @import("lib/plugin_lock.zig");
    _ = @import("lib/plugin_registry.zig");
    _ = @import("lib/plugin_sources.zig");
    _ = @import("lib/plugin_store.zig");
    _ = @import("lib/plugin_ui.zig");
    _ = @import("lib/prompt.zig");
    _ = @import("lib/registry.zig");
    _ = @import("lib/semver.zig");
    _ = @import("lib/services.zig");
    _ = @import("lib/userconfig.zig");
    _ = @import("lib/util.zig");
    _ = @import("stamp.zig");
}
