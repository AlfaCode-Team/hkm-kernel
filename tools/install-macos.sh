#!/usr/bin/env sh
# ---------------------------------------------------------------------------
# install-macos.sh — install the HKM kernel (HKM.app) and wire up `hkm` /
# `hkm-config` on macOS.
#
#   ./install-macos.sh                                # download latest, install
#   ./install-macos.sh hkm-kernel-1.4.1-macos-universal.tar.gz
#   ./install-macos.sh --version v1.4.1               # download a specific tag
#   ./install-macos.sh --uninstall
#
# Installs:
#   /Applications/HKM.app                 the .app bundle (kernel + launcher)
#   ~/.local/bin/hkm, hkm-config          tiny wrapper scripts onto PATH
#
# Your data is NOT inside the install tree and survives upgrades:
#   ~/.config/hkm/config.env              launcher config
#   ~/.local/share/hkm/                   project registry (HKM_USERDATA_DIR)
#
# tools/install.sh (the Linux auto-installer) deliberately refuses to run on
# macOS — its auto-download only covers the Linux tarball — and points here.
# HKM.app is NOT a double-click GUI app: Contents/MacOS/hkm is the exact same
# CLI binary as every other platform, just wrapped for Gatekeeper/Finder.
#
# WHY A WRAPPER SCRIPT, NOT A SYMLINK ONTO PATH
# ----------------------------------------------
# The launcher finds its kernel relative to its OWN executable path
# (tools/src/lib/kernel.zig selfLocateRoot(): "<dir>/../Resources/opt/hkm-kernel").
# macOS's `_NSGetExecutablePath` is not guaranteed to resolve through a symlink
# the way Linux's /proc/self/exe does, so `ln -s .../HKM.app/.../hkm
# ~/.local/bin/hkm` risks the binary computing its own directory as
# ~/.local/bin and never finding Resources/opt/hkm-kernel beside it — silently
# falling back to the wrong kernel (or none). A one-line `exec` wrapper
# sidesteps that: the OS execs the REAL binary at its real path regardless of
# how it was invoked, so self-location always sees where it actually lives.
#
# Still needed from your system administrator, once: PHP >= 8.4 with the
# extensions `hkm doctor` lists. Installing PHP is the one thing this install
# genuinely cannot do for you.
# ---------------------------------------------------------------------------
set -eu

REPO="${HKM_REPO:-AlfaCode-Team/hkm-kernel}"
APPDIR="${HKM_APPDIR:-/Applications}"
APPDST="$APPDIR/HKM.app"
BINDIR="${HKM_BINDIR:-$HOME/.local/bin}"

TARBALL=""
WANT_TAG=""
DO_UNINSTALL=0
SKIP_COMPOSER=0

# ── output helpers ──────────────────────────────────────────────────────────
if [ -t 1 ] && [ -z "${NO_COLOR:-}" ]; then
  C_B='\033[36m'; C_G='\033[32m'; C_Y='\033[33m'; C_R='\033[31m'; C_0='\033[0m'
else
  C_B=''; C_G=''; C_Y=''; C_R=''; C_0=''
fi
say()  { printf "${C_B}▶${C_0} %s\n" "$*"; }
ok()   { printf "${C_G}✓${C_0} %s\n" "$*"; }
warn() { printf "${C_Y}!${C_0} %s\n" "$*" >&2; }
die()  { printf "${C_R}✗${C_0} %s\n" "$*" >&2; exit 1; }

usage() {
  sed -n '3,25p' "$0" | sed 's/^# \{0,1\}//'
  exit 0
}

[ "$(uname -s)" = "Darwin" ] || die "this installer is for macOS only — Linux: tools/install.sh"

# ── arguments ───────────────────────────────────────────────────────────────
while [ $# -gt 0 ]; do
  case "$1" in
    -h|--help)      usage ;;
    --uninstall)    DO_UNINSTALL=1 ;;
    --version)      shift; [ $# -gt 0 ] || die "--version needs a tag (e.g. v1.4.1)"; WANT_TAG="$1" ;;
    --no-composer)  SKIP_COMPOSER=1 ;;
    -*)             die "unknown option: $1  (try --help)" ;;
    *)              TARBALL="$1" ;;
  esac
  shift
done

# ── uninstall ───────────────────────────────────────────────────────────────
if [ "$DO_UNINSTALL" -eq 1 ]; then
  say "Removing $APPDST"
  if [ -w "$APPDIR" ]; then rm -rf "$APPDST"; else sudo rm -rf "$APPDST"; fi
  rm -f "$BINDIR/hkm" "$BINDIR/hkm-config"
  ok "Removed. Your data was left alone:"
  printf '    %s\n    %s\n' "${XDG_CONFIG_HOME:-$HOME/.config}/hkm" \
                            "${XDG_DATA_HOME:-$HOME/.local/share}/hkm"
  exit 0
fi

# ── what is already on this machine ─────────────────────────────────────────
kernel_version() { # $1 = kernel root → prints the stamped version, or nothing
  [ -f "$1/composer.json" ] || return 0
  sed -n 's/.*"version"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$1/composer.json" | head -1
}
OLD_VER="$(kernel_version "$APPDST/Contents/Resources/opt/hkm-kernel")"
[ -d "$APPDST" ] && say "Existing install: $APPDST (${OLD_VER:-unstamped})"

# ── acquire the tarball ─────────────────────────────────────────────────────
TMP="$(mktemp -d)"
cleanup() { rm -rf "$TMP"; }
trap cleanup EXIT INT TERM

fetch() { # $1 = url, $2 = out
  if command -v curl >/dev/null 2>&1; then
    curl -fsSL "$1" -o "$2"
  elif command -v wget >/dev/null 2>&1; then
    wget -qO "$2" "$1"
  else
    die "need curl or wget to download (or pass a .tar.gz path)"
  fi
}

if [ -n "$TARBALL" ]; then
  [ -f "$TARBALL" ] || die "no such file: $TARBALL"
  say "Using $TARBALL"
else
  if [ -n "$WANT_TAG" ]; then
    TAG="$WANT_TAG"
  else
    say "Looking up the latest release of $REPO…"
    API="https://api.github.com/repos/$REPO/releases/latest"
    fetch "$API" "$TMP/rel.json" || die "could not reach GitHub. Download the tarball and pass its path."
    TAG="$(sed -n 's/.*"tag_name"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$TMP/rel.json" | head -1)"
    [ -n "$TAG" ] || die "could not parse the latest tag. Pass --version vX.Y.Z or a tarball path."
  fi

  VER="${TAG#v}"
  NAME="hkm-kernel-${VER}-macos-universal.tar.gz"
  URL="https://github.com/$REPO/releases/download/$TAG/$NAME"
  say "Downloading $NAME"
  fetch "$URL" "$TMP/$NAME" || die "download failed: $URL"
  TARBALL="$TMP/$NAME"
fi

# ── unpack and validate before touching the destination ─────────────────────
say "Unpacking…"
mkdir -p "$TMP/x"
tar -xzf "$TARBALL" -C "$TMP/x" || die "could not extract $TARBALL"
SRC_APP="$TMP/x/HKM.app"
[ -d "$SRC_APP" ] || die "unexpected archive layout (no HKM.app at the top level)"
[ -x "$SRC_APP/Contents/MacOS/hkm" ] || die "launcher missing from the archive (Contents/MacOS/hkm)"

# ── install ─────────────────────────────────────────────────────────────────
# Swap rather than overwrite in place, same reasoning as the Linux installer:
# a half-copied .app is worse than the old one, and this also means a
# currently-running `hkm` (launched from the old .app) is unaffected — it
# keeps its old, now-unlinked inode and finishes normally.
if [ -w "$APPDIR" ]; then SUDO=""; else SUDO="sudo"; warn "$APPDIR is not writable — using sudo"; fi

NEW="$APPDIR/.HKM.app.new.$$"
OLD="$APPDIR/.HKM.app.old.$$"
$SUDO rm -rf "$NEW"
$SUDO cp -R "$SRC_APP" "$NEW"
if [ -d "$APPDST" ]; then $SUDO mv "$APPDST" "$OLD"; fi
$SUDO mv "$NEW" "$APPDST"
$SUDO rm -rf "$OLD"
$SUDO chmod +x "$APPDST/Contents/MacOS/hkm" "$APPDST/Contents/MacOS/hkm-config"

# Clear the quarantine flag Gatekeeper sets on anything downloaded — without
# this an unsigned/unnotarized build refuses to run at all ("cannot be opened
# because the developer cannot be verified"). Best effort: a local --local-style
# copy may carry no quarantine attribute, and that is not an error.
$SUDO xattr -dr com.apple.quarantine "$APPDST" 2>/dev/null || true

ok "Installed to $APPDST"

# ── wrapper scripts onto PATH (see header for why not a symlink) ────────────
mkdir -p "$BINDIR"
for name in hkm hkm-config; do
  staged="$BINDIR/.$name.new.$$"
  printf '#!/bin/sh\nexec "%s/Contents/MacOS/%s" "$@"\n' "$APPDST" "$name" > "$staged"
  chmod +x "$staged"
  mv "$staged" "$BINDIR/$name"
done
ok "wired up $BINDIR/hkm, hkm-config"

# ── PATH ────────────────────────────────────────────────────────────────────
case ":${PATH}:" in
  *":$BINDIR:"*) ok "$BINDIR is on your PATH" ;;
  *)
    warn "$BINDIR is NOT on your PATH."
    printf '  Add it, then open a new terminal:\n'
    case "${SHELL:-}" in
      */fish) printf '    fish_add_path %s\n' "$BINDIR" ;;
      *)    printf '    echo '\''export PATH="%s:$PATH"'\'' >> ~/.zprofile\n' "$BINDIR" ;;
    esac
    ;;
esac

# ── PHP dependencies (best effort — NEVER a precondition) ───────────────────
KERNEL_ROOT="$APPDST/Contents/Resources/opt/hkm-kernel"
if [ "$SKIP_COMPOSER" -eq 1 ]; then
  say "Skipping dependency resolution (--no-composer)."
elif ! command -v php >/dev/null 2>&1; then
  say "No php on PATH — skipping dependency resolution for now."
elif [ -x "$KERNEL_ROOT/install.sh" ]; then
  say "Resolving PHP dependencies (composer install --no-dev)…"
  ( cd "$KERNEL_ROOT" && ./install.sh ) || warn "Dependency resolution did not complete — hkm doctor will show why."
else
  say "Resolving PHP dependencies (composer install --no-dev)…"
  ( cd "$KERNEL_ROOT" && composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist ) \
    || warn "Dependency resolution did not complete — hkm doctor will show why."
fi

# ── verify ──────────────────────────────────────────────────────────────────
printf '\n'
DOCTOR_OK=1
if [ -x "$BINDIR/hkm" ]; then
  say "Checking what the kernel still needs…"
  "$BINDIR/hkm" doctor || DOCTOR_OK=0
fi

printf '\n'
NEW_VER="$(kernel_version "$KERNEL_ROOT")"
if [ -n "$OLD_VER" ] && [ -n "$NEW_VER" ] && [ "$OLD_VER" != "$NEW_VER" ]; then
  printf '  Version: %s -> %s\n' "$OLD_VER" "$NEW_VER"
elif [ -n "$NEW_VER" ]; then
  printf '  Version: %s\n' "$NEW_VER"
fi

if [ "$DOCTOR_OK" -eq 0 ]; then
  printf '  Some requirements are not met yet — see "Must fix" above.\n'
  printf '  Re-check any time with:  hkm doctor\n'
fi
printf '  App:     %s\n' "$APPDST"
printf '  Config:  %s/hkm/config.env\n' "${XDG_CONFIG_HOME:-$HOME/.config}"
printf '  Data:    %s/hkm\n' "${XDG_DATA_HOME:-$HOME/.local/share}"
printf '  Remove:  %s --uninstall\n' "$0"
printf '\n'
