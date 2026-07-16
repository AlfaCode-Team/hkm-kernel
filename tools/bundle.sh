#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# bundle.sh — build the `hkm` launcher for every OS and assemble installable
# bundles under dist/. Run from the repo root or from tools/.
#
#   ./tools/bundle.sh            # build all: linux .deb tree, macos, windows zip
#   ./tools/bundle.sh linux      # only one target
#
# What a bundle contains:
#   • the native `hkm` + `hkm-config` launcher (Zig, statically linked)
#   • the kernel PHP source (src/) + vendor/ (composer --no-dev)
#   • the PHP CLI (bin/psp) installed AS bin/hkm so the launcher's default
#     passthrough path (<kernel>/bin/hkm) resolves.
#
# End users still need PHP 8.4 on PATH — `hkm doctor` verifies it.
# ---------------------------------------------------------------------------
set -euo pipefail

ZIG_VER="0.17.0-dev.657+2faf8debf"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TOOLS="$ROOT/tools"
DIST="$ROOT/dist"
VERSION="${VERSION:-$(git -C "$ROOT" describe --tags --always 2>/dev/null || echo 0.0.0)}"
VERSION="${VERSION#v}"
KERNEL_DIRNAME="hkm-kernel"   # install prefix basename → /opt/hkm-kernel

want="${1:-all}"
say() { printf '\033[36m▶ %s\033[0m\n' "$*"; }

# --- 0. prerequisites the BUILD host needs (not the end user) ---------------
# NOTE: composer is NOT needed to BUILD the bundle anymore — vendor/ is not
# shipped; `composer install` runs on the TARGET at install time instead.
command -v zig >/dev/null || { echo "zig not found (need $ZIG_VER)"; exit 1; }
git -C "$ROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1 \
  || { echo "bundle.sh must run inside the git checkout (needs submodule tracking)"; exit 1; }

# How the composer PATH-repository modules (git submodules) get into a bundle:
#   MODULES=bundle (default) — their tracked files are STAGED into modules/. The
#                              bundle is self-contained (no git needed on target).
#   MODULES=git              — modules/ is NOT staged; instead a modules.lock of
#                              (path url pinned-SHA) is shipped and install.sh
#                              git-clones each at its exact commit on the target.
#                              Smaller bundle, but target needs git + network.
MODULES="${MODULES:-bundle}"

# vendor/ is ALWAYS excluded — composer resolves it on the target. Everything
# staged is git-tracked ⇒ no gitignored junk (.claude, node_modules, var/cache,
# submodule vendors) can leak.
SRC_PATHS="src plugins projects templates composer.json composer.lock bin/psp README.md LICENSE"
[ "$MODULES" = bundle ] && SRC_PATHS="$SRC_PATHS modules"

# Emit modules.lock: "<path> <url> <pinned-sha>" per submodule, from the SHA
# recorded in the superproject (git ls-tree) + the .gitmodules URL.
write_modules_lock() { # $1 = kernel root
  local k="$1"
  ( cd "$ROOT"
    git config -f .gitmodules --get-regexp 'submodule\..*\.path' | while read -r key path; do
      name="${key#submodule.}"; name="${name%.path}"
      url="$(git config -f .gitmodules --get "submodule.${name}.url")"
      sha="$(git ls-tree HEAD "$path" | awk '{print $3}')"
      [ -n "$sha" ] && printf '%s %s %s\n' "$path" "$url" "$sha"
    done
  ) > "$k/modules.lock"
}

stage_kernel() { # $1 = destination kernel root
  local k="$1"
  mkdir -p "$k/bin"

  # Copy ONLY git-tracked files (across submodules) into the kernel root.
  ( cd "$ROOT" && git ls-files -z --recurse-submodules -- $SRC_PATHS ) \
  | while IFS= read -r -d '' f; do
      mkdir -p "$k/$(dirname "$f")"
      cp "$ROOT/$f" "$k/$f"
    done

  # In git mode, ship the lockfile instead of the module sources.
  if [ "$MODULES" = git ]; then write_modules_lock "$k"; fi

  # PHP CLI installed under the name the launcher expects (bin/hkm).
  if [ -f "$k/bin/psp" ]; then mv "$k/bin/psp" "$k/bin/hkm"; chmod +x "$k/bin/hkm"; fi

  # Runtime install ships NO documentation or build tooling: strip every `docs`/
  # `doc` and `tools` directory + leftover test caches. The templates/ subtree is
  # EXEMPT — a scaffolded project legitimately ships its own docs/tests/tooling.
  find "$k" -depth -type d \( -name docs -o -name doc -o -name tools -o -name tests \
       -o -name .git -o -name .github \) -not -path "$k/templates/*" -exec rm -rf {} + 2>/dev/null || true
  find "$k" -type f \( -name '.phpunit.result.cache' -o -name '.gitignore' \
       -o -name '.gitattributes' \) -not -path "$k/templates/*" -delete 2>/dev/null || true

  # Drop the composer-install helper used on non-.deb targets (macOS/Windows).
  cp "$TOOLS/templates/install-kernel.sh" "$k/install.sh" 2>/dev/null || true
  chmod +x "$k/install.sh" 2>/dev/null || true
}

build_zig() { # $1 = zig target triple, $2 = out dir
  say "zig build $1"
  ( cd "$TOOLS" && zig build --release=small -Dversion="$VERSION" -Dtarget="$1" -p "$2" )
}

rm -rf "$DIST"; mkdir -p "$DIST"

# ─── Linux: .deb (amd64) ────────────────────────────────────────────────────
if [[ "$want" == all || "$want" == linux ]]; then
  build_zig x86_64-linux-gnu "$DIST/_zig/linux"
  PKG="hkm-kernel_${VERSION}_amd64"; P="$DIST/$PKG"
  mkdir -p "$P/DEBIAN" "$P/usr/bin"
  stage_kernel "$P/opt/$KERNEL_DIRNAME"
  cp "$DIST/_zig/linux/bin/hkm"        "$P/usr/bin/hkm"
  cp "$DIST/_zig/linux/bin/hkm-config" "$P/usr/bin/hkm-config"
  chmod +x "$P/usr/bin/hkm" "$P/usr/bin/hkm-config"
  # composer is a hard dependency now: the package ships SOURCE, not vendor/, and
  # resolves dependencies on the target in postinst. Network access is required
  # at install time. In MODULES=git mode, git is also required to fetch modules.
  DEPS="php8.4-cli, php8.4-mbstring, php8.4-curl, php8.4-xml, php8.4-zip, composer, ca-certificates"
  [ "$MODULES" = git ] && DEPS="$DEPS, git"
  cat > "$P/DEBIAN/control" <<EOF
Package: hkm-kernel
Version: ${VERSION}
Architecture: amd64
Maintainer: AlfacodeTeam <dev@hkm.local>
Depends: ${DEPS}
Recommends: php8.4-mysql | php8.4-pgsql | php8.4-sqlite3, php8.4-redis, php8.4-intl
Description: PhpServicePlatform (HKM) kernel and native launcher
 Installs the kernel PHP source (src, plugins, projects, modules) under
 /opt/hkm-kernel and a native hkm launcher in /usr/bin. PHP dependencies are
 resolved with composer at install time (vendor/ is not bundled), so the
 runtime matches this machine's PHP. Needs network access during install.
 Run 'hkm doctor' afterwards to verify PHP and required extensions.
EOF
  # conffiles: the project registry + platform map are USER DATA. Marking them as
  # dpkg conffiles makes upgrades PRESERVE the user's versions instead of
  # overwriting them with the packaged defaults. (Relocate entirely with
  # HKM_USERDATA_DIR if you'd rather keep them outside /opt.)
  cat > "$P/DEBIAN/conffiles" <<EOF
/opt/${KERNEL_DIRNAME}/projects/projects.json
/opt/${KERNEL_DIRNAME}/projects/platform.json
EOF
  # postinst: build vendor/ on the target via the shipped install.sh helper.
  cat > "$P/DEBIAN/postinst" <<EOF
#!/bin/sh
set -e
KERNEL=/opt/${KERNEL_DIRNAME}
echo "hkm-kernel: resolving PHP dependencies with composer…"
if [ -x "\$KERNEL/install.sh" ]; then
  ( cd "\$KERNEL" && ./install.sh ) || {
    echo "WARNING: composer install failed. Fix connectivity/PHP, then run:";
    echo "  sudo sh -c 'cd \$KERNEL && ./install.sh'";
  }
fi
echo "hkm-kernel installed. Verify your environment with:  hkm doctor"
exit 0
EOF
  chmod +x "$P/DEBIAN/postinst"
  # prerm: remove the composer-generated vendor/ so the package uninstalls clean.
  cat > "$P/DEBIAN/prerm" <<EOF
#!/bin/sh
set -e
if [ "\$1" = "remove" ] || [ "\$1" = "purge" ]; then
  rm -rf /opt/${KERNEL_DIRNAME}/vendor /opt/${KERNEL_DIRNAME}/composer.phar
fi
exit 0
EOF
  chmod +x "$P/DEBIAN/prerm"
  # --root-owner-group: force root:root ownership so the .deb installs files as
  # root (avoids the "unusual owner 1000:1000" warning when building as a user).
  dpkg-deb --root-owner-group --build "$P" >/dev/null
  say "wrote $DIST/${PKG}.deb"
fi

# ─── macOS: universal .app tarball (arm64 + x86_64) ─────────────────────────
if [[ "$want" == all || "$want" == macos ]]; then
  build_zig aarch64-macos "$DIST/_zig/mac-arm"
  build_zig x86_64-macos  "$DIST/_zig/mac-x86"
  APP="$DIST/HKM.app"
  mkdir -p "$APP/Contents/MacOS"
  stage_kernel "$APP/Contents/Resources/opt/$KERNEL_DIRNAME"
  # Build a true universal Mach-O. Prefer macOS `lipo`, else LLVM's cross-platform
  # `llvm-lipo` (available on Linux — this is what lets the whole macOS bundle be
  # produced on an ubuntu runner). Only if neither exists do we ship arm64-only.
  LIPO=""
  if command -v lipo >/dev/null; then LIPO="lipo"
  elif command -v llvm-lipo >/dev/null; then LIPO="llvm-lipo"
  else LIPO="$(ls /usr/bin/llvm-lipo-* 2>/dev/null | sort -V | tail -1 || true)"; fi
  if [ -n "$LIPO" ]; then
    "$LIPO" -create "$DIST/_zig/mac-arm/bin/hkm"        "$DIST/_zig/mac-x86/bin/hkm"        -output "$APP/Contents/MacOS/hkm"
    "$LIPO" -create "$DIST/_zig/mac-arm/bin/hkm-config" "$DIST/_zig/mac-x86/bin/hkm-config" -output "$APP/Contents/MacOS/hkm-config"
  else
    cp "$DIST/_zig/mac-arm/bin/hkm"        "$APP/Contents/MacOS/hkm"
    cp "$DIST/_zig/mac-arm/bin/hkm-config" "$APP/Contents/MacOS/hkm-config"
    echo "note: no lipo/llvm-lipo — macOS bundle is arm64-only (install llvm for universal)"
  fi
  chmod +x "$APP/Contents/MacOS/hkm" "$APP/Contents/MacOS/hkm-config"
  cat > "$APP/Contents/Info.plist" <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0"><dict>
  <key>CFBundleName</key><string>HKM</string>
  <key>CFBundleExecutable</key><string>hkm</string>
  <key>CFBundleIdentifier</key><string>team.alfacode.hkm-kernel</string>
  <key>CFBundleVersion</key><string>${VERSION}</string>
  <key>CFBundleShortVersionString</key><string>${VERSION}</string>
  <key>CFBundlePackageType</key><string>APPL</string>
</dict></plist>
EOF
  ( cd "$DIST" && tar -czf "hkm-kernel-${VERSION}-macos-universal.tar.gz" HKM.app )
  say "wrote $DIST/hkm-kernel-${VERSION}-macos-universal.tar.gz"
fi

# ─── Windows: .zip (x86_64) ─────────────────────────────────────────────────
if [[ "$want" == all || "$want" == windows ]]; then
  build_zig x86_64-windows-gnu "$DIST/_zig/win"
  B="$DIST/hkm-kernel-win"
  stage_kernel "$B/$KERNEL_DIRNAME"
  cp "$DIST/_zig/win/bin/hkm.exe"        "$B/hkm.exe"
  cp "$DIST/_zig/win/bin/hkm-config.exe" "$B/hkm-config.exe"
  # Windows composer helper (vendor/ is not bundled — resolved on the target).
  cat > "$B/hkm-kernel/install.bat" <<'EOF'
@echo off
REM Resolve PHP dependencies for the HKM kernel (vendor/ is not shipped).
cd /d "%~dp0"
where composer >nul 2>nul
if %errorlevel%==0 (
  set COMPOSER_ALLOW_SUPERUSER=1
  composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist
) else (
  php -r "copy('https://getcomposer.org/installer','composer-setup.php');"
  php composer-setup.php --quiet
  del composer-setup.php
  php composer.phar install --no-dev --optimize-autoloader --no-interaction --prefer-dist
)
echo Done. Verify with:  hkm doctor
EOF
  cat > "$B/INSTALL.txt" <<'EOF'
HKM Kernel — Windows
====================
1. Extract this folder to C:\hkm  (or any path).
2. Install PHP 8.4 (winget install PHP.PHP) and Composer, open a new terminal.
3. Resolve dependencies (vendor/ is NOT bundled):
       cd C:\hkm\hkm-kernel
       install.bat
4. setx HKM_KERNEL_HOME C:\hkm\hkm-kernel
5. Add the folder containing hkm.exe to your PATH.
6. Verify:  hkm doctor
EOF
  ( cd "$DIST" && zip -qr "hkm-kernel-${VERSION}-windows-x86_64.zip" hkm-kernel-win )
  say "wrote $DIST/hkm-kernel-${VERSION}-windows-x86_64.zip"
fi

rm -rf "$DIST/_zig"
say "done — artifacts in $DIST/"
ls -la "$DIST"
