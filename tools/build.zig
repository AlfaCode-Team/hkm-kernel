const std = @import("std");

pub fn build(b: *std.Build) void {
    const target = b.standardTargetOptions(.{});
    // A plain `zig build` is Debug (~13MB of debug info — fine for hacking).
    // For distribution build a release: `zig build --release=small` → ~230KB,
    // `--release=safe` → ~4MB (keeps runtime safety checks), `--release=fast`.
    const optimize = b.standardOptimizeOption(.{});

    // Version stamped into the binary. `zig build -Dversion=1.0.0` (bundle.sh
    // passes the release VERSION); defaults to a dev marker for local builds.
    const version = b.option([]const u8, "version", "Version string stamped into the binary") orelse "0.0.0-dev";
    const build_info = b.addOptions();
    build_info.addOption([]const u8, "version", version);
    build_info.addOption([]const u8, "repo", "AlfaCode-Team/hkm-kernel");

    const launcher = b.addExecutable(.{
        .name = "hkm",
        .root_module = b.createModule(.{
            .root_source_file = b.path("src/main.zig"),
            .target = target,
            .optimize = optimize,
        }),
    });
    launcher.root_module.addOptions("build_info", build_info);
    b.installArtifact(launcher);

    const config_tool = b.addExecutable(.{
        .name = "hkm-config",
        .root_module = b.createModule(.{
            .root_source_file = b.path("src/config.zig"),
            .target = target,
            .optimize = optimize,
        }),
    });
    config_tool.root_module.addOptions("build_info", build_info);
    b.installArtifact(config_tool);

    // Also drop the native launcher + config tool into the repo-level bin/ (next
    // to bin/hkm-cli) so `zig build` makes `bin/hkm` and `bin/hkm-config` runnable
    // locally. build.zig lives in tools/, so "../bin" is the repo bin/.
    //
    // Guarded to NATIVE builds only: the cross-compiled bundle builds
    // (`-Dtarget=…` in tools/bundle.sh) must never overwrite the local bin/ with
    // a foreign-arch (Windows/macOS) binary. A dedicated `zig build bin` step is
    // also exposed for running it on demand.
    const to_bin = b.addUpdateSourceFiles();
    to_bin.addCopyFileToSource(launcher.getEmittedBin(), "../bin/hkm");
    to_bin.addCopyFileToSource(config_tool.getEmittedBin(), "../bin/hkm-config");
    const bin_step = b.step("bin", "Copy hkm + hkm-config into the repo bin/");
    bin_step.dependOn(&to_bin.step);
    if (target.query.isNative()) {
        b.getInstallStep().dependOn(&to_bin.step);
    }

    // `zig build test` — unit tests for the library modules.
    //
    // Rooted at src/main.zig rather than at individual files so that relative
    // imports like `@import("../constants.zig")` resolve inside the module.
    // Running `zig test src/lib/memory.zig` directly makes src/lib the module
    // root and that import fails, which is misleading rather than useful.
    // The stamper's logic lives in lib/composer_version.zig — it is shared with
    // `hkm version` / `hkm upgrade`, which READ the field the stamper writes.
    // Testing the library rather than the executable wrapper is what keeps the
    // read and write halves verified against each other.
    const stamp_tests = b.addTest(.{
        .root_module = b.createModule(.{
            .root_source_file = b.path("src/lib/composer_version.zig"),
            .target = target,
            .optimize = optimize,
        }),
    });

    const unit_tests = b.addTest(.{
        .root_module = b.createModule(.{
            .root_source_file = b.path("src/tests.zig"),
            .target = target,
            .optimize = optimize,
        }),
    });
    unit_tests.root_module.addOptions("build_info", build_info);

    const run_unit_tests = b.addRunArtifact(unit_tests);
    const test_step = b.step("test", "Run the tools unit tests");
    test_step.dependOn(&run_unit_tests.step);
    test_step.dependOn(&b.addRunArtifact(stamp_tests).step);

    // ── Stamp the version into composer.json ────────────────────────────────
    //
    // Only when -Dversion was passed explicitly (which tools/bundle.sh does for
    // a release). A plain `zig build` is 0.0.0-dev, and writing that into
    // composer.json would dirty the working tree on every build and eventually
    // be committed by accident.
    //
    // The field is needed because the native distribution ships WITHOUT a .git
    // directory: a .deb or zip has no tags for composer to derive a version
    // from, so this is the installed kernel's only version marker. It is also
    // why the field is a liability in a git checkout — a literal version
    // OVERRIDES the tags, and the two silently drift apart.
    const stamper = b.addExecutable(.{
        .name = "hkm-stamp",
        .root_module = b.createModule(.{
            .root_source_file = b.path("src/stamp.zig"),
            .target = b.graph.host,
            .optimize = .Debug,
        }),
    });

    // Installed so it can be run and tested directly. Without this the only
    // copies live in .zig-cache under content-hashed directories, and verifying
    // its behaviour against real composer means guessing which one is current.
    b.installArtifact(stamper);

    if (!std.mem.eql(u8, version, "0.0.0-dev")) {
        const stamp_run = b.addRunArtifact(stamper);
        stamp_run.addFileArg(b.path("../composer.json"));
        stamp_run.addArg(version);
        b.getInstallStep().dependOn(&stamp_run.step);
    }

    // `zig build stamp -Dversion=X` runs it on demand regardless.
    const stamp_step = b.step("stamp", "Write -Dversion into the kernel composer.json");
    const stamp_manual = b.addRunArtifact(stamper);
    stamp_manual.addFileArg(b.path("../composer.json"));
    stamp_manual.addArg(version);
    stamp_step.dependOn(&stamp_manual.step);

    const run_launcher = b.addRunArtifact(launcher);

    const run_step = b.step("run", "Run hkm launcher");
    run_step.dependOn(&run_launcher.step);

    // `zig build docs` — generate the .tex from the Markdown single source, then
    // typeset it into a PDF. Requires python3 + a LaTeX toolchain (pdflatex).
    // Run pdflatex twice so the TOC resolves, then prune the LaTeX aux files.
    // Opt-in: a plain `zig build` does not run it.
    const docs_dir = b.path("docs");

    // 1. Markdown → LaTeX (single source of truth is hkm-cli-usage.md).
    const gen_tex = b.addSystemCommand(&.{ "python3", "build-docs.py" });
    gen_tex.setCwd(docs_dir);

    const pdf_pass1 = b.addSystemCommand(&.{ "pdflatex", "-interaction=nonstopmode", "-halt-on-error", "hkm-cli-usage.tex" });
    pdf_pass1.setCwd(docs_dir);
    pdf_pass1.step.dependOn(&gen_tex.step);

    const pdf_pass2 = b.addSystemCommand(&.{ "pdflatex", "-interaction=nonstopmode", "-halt-on-error", "hkm-cli-usage.tex" });
    pdf_pass2.setCwd(docs_dir);
    pdf_pass2.step.dependOn(&pdf_pass1.step);

    const docs_clean = b.addSystemCommand(&.{
        "rm", "-f",
        "hkm-cli-usage.aux", "hkm-cli-usage.log", "hkm-cli-usage.out", "hkm-cli-usage.toc",
    });
    docs_clean.setCwd(docs_dir);
    docs_clean.step.dependOn(&pdf_pass2.step);

    const docs_step = b.step("docs", "Build the CLI usage PDF (needs pdflatex)");
    docs_step.dependOn(&docs_clean.step);
}
