# ---------------------------------------------------------------------------
# Homebrew formula for the HKM kernel.
#
#   brew tap alfacode-team/hkm https://github.com/AlfaCode-Team/hkm-kernel
#   brew trust alfacode-team/hkm
#   brew install hkm
#
# The `brew trust` step is required: Homebrew 6 refuses to load a formula from a
# third-party tap until it is trusted, and it refuses at `brew install` — so
# `brew tap` reports success and the failure surfaces one command later.
#
# This repository doubles as its own tap: Homebrew reads formulae from a
# `HomebrewFormula/` directory in any tapped repo, so there is no second
# `homebrew-hkm` repo to keep in sync with releases.
#
# `url` and `sha256` are rewritten on every release by tools/homebrew-bump.sh,
# driven from the `homebrew` job in .github/workflows/release.yml. Do not hand-
# edit them — bump the release and let the job commit the result.
#
# A release's digest cannot exist before the release does, so that job runs
# AFTER publishing and lands the result on main: directly when the token is
# allowed to, otherwise as an automatic pull request. Nothing here is ever a
# number a human is expected to paste in.
#
# WHY A PREBUILT TARBALL AND NOT A SOURCE BUILD
# ---------------------------------------------
# The launcher is Zig, pinned to a *dev* toolchain (tools/.zig-version) for the
# new `std.Io` API. Homebrew's `zig` formula tracks stable releases, which
# cannot compile this tree at all — so the formula consumes the same universal
# Mach-O every other macOS install path gets, and does the one thing that
# genuinely must happen on the target machine: resolve vendor/ against the PHP
# Homebrew just installed.
# ---------------------------------------------------------------------------
class Hkm < Formula
  desc "CLI for the HKM kernel, a modular PHP service platform"
  homepage "https://github.com/AlfaCode-Team/hkm-kernel"
  url "https://github.com/AlfaCode-Team/hkm-kernel/releases/download/v1.5.0/hkm-kernel-1.5.0-macos-universal.tar.gz"
  sha256 "20e624db28e998cf088b19ae20453bc53c421aa0432f35a625b83515b2a3d1de"
  license "MIT"

  livecheck do
    url :stable
    strategy :github_latest
  end

  # composer is needed twice: here, to build vendor/, and afterwards by
  # `hkm new`, which resolves a scaffolded project's plugins. php is the
  # runtime the kernel dispatches every command to. :macos because the release
  # asset is a universal Mach-O (arm64 + x86_64) wrapped in an .app bundle —
  # there is nothing in it for Linux.
  #
  # Ordering is alphabetical, including the requirement, per brew style.
  depends_on "composer"
  depends_on :macos
  depends_on "php"

  def install
    # Reshape the .app into the bin/ + lib/ pairing the launcher already knows.
    # tools/src/lib/kernel.zig selfLocateRoot() probes "<parent-of-exe-dir>/lib/
    # hkm-kernel", so a binary at libexec/bin/hkm finds libexec/lib/hkm-kernel
    # with no env var and no config file — the same relative layout
    # tools/install.sh produces.
    kernel = libexec/"lib/hkm-kernel"
    kernel.install Dir["HKM.app/Contents/Resources/opt/hkm-kernel/*"]
    (libexec/"bin").install "HKM.app/Contents/MacOS/hkm",
                            "HKM.app/Contents/MacOS/hkm-config"

    # vendor/ is deliberately not shipped in the tarball — it is resolved here
    # so the autoloader matches the exact PHP this formula just depended on.
    # Reuse the kernel's own install.sh rather than restating composer flags:
    # it is the same script the .deb postinst and install-macos.sh run.
    ENV["COMPOSER_HOME"] = buildpath/"composer-home"
    system kernel/"install.sh"

    # Wrapper scripts, not symlinks. Two independent reasons:
    #
    # 1. The launcher self-locates from its OWN executable path, and macOS's
    #    `_NSGetExecutablePath` is not guaranteed to resolve through a symlink
    #    the way Linux's /proc/self/exe does. Brew's bin/ is symlinked into
    #    HOMEBREW_PREFIX, so a symlinked launcher risks computing its directory
    #    as the prefix bin and never finding lib/hkm-kernel beside it.
    #
    # 2. HKM_USERDATA_DIR. Left unset, the project registry defaults to
    #    <kernel>/projects/projects.json (tools/src/lib/registry.zig) — inside
    #    the Cellar, which `brew upgrade` deletes wholesale. Defaulting it here
    #    puts the registry under XDG data instead, so upgrades stop destroying
    #    the list of registered projects. A real environment variable still
    #    wins, as does an HKM_USERDATA_DIR pinned in ~/.config/hkm/config.env.
    %w[hkm hkm-config].each do |exe|
      # The mkdir is not belt-and-braces: on a machine that has never run hkm
      # the directory does not exist yet, and an absent userdata dir is a HARD
      # `hkm doctor` failure ("the registry cannot be updated"). Creating it
      # here is what makes `brew install hkm && hkm doctor` green on a first
      # run. Failure to create it is swallowed so a read-only HOME degrades to
      # that same diagnosable state instead of breaking every command.
      (bin/exe).write <<~SH
        #!/bin/sh
        : "${HKM_USERDATA_DIR:=${XDG_DATA_HOME:-$HOME/.local/share}/hkm}"
        export HKM_USERDATA_DIR
        [ -d "$HKM_USERDATA_DIR" ] || mkdir -p "$HKM_USERDATA_DIR" 2>/dev/null || true
        exec "#{libexec}/bin/#{exe}" "$@"
      SH
      (bin/exe).chmod 0755
    end
  end

  def caveats
    <<~EOS
      The project registry is kept outside the Cellar so `brew upgrade` cannot
      destroy it:
        #{ENV.fetch("XDG_DATA_HOME", "~/.local/share")}/hkm

      Confirm the runtime — PHP version, required extensions, vendor/ — with:
        hkm doctor
    EOS
  end

  test do
    assert_match version.to_s, shell_output("#{bin}/hkm --version")

    # Proves the reshaped layout self-locates: `hkm-config check` resolves the
    # kernel root relative to the launcher, and reports the vendor/ built above.
    output = shell_output("#{bin}/hkm-config check")
    assert_match "vendor/autoload.php", output
    assert_match (libexec/"lib/hkm-kernel").to_s, output
  end
end
