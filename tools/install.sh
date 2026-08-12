#!/usr/bin/env sh
# ---------------------------------------------------------------------------
# install.sh — install the HKM kernel + launcher for the CURRENT USER.
#
# No root. Nothing is written outside your home directory.
#
#   ./install.sh                          # download the latest release, install
#   ./install.sh hkm-kernel-1.2.3-linux-x86_64.tar.gz
#   ./install.sh --version v1.2.3         # download a specific tag
#   HKM_PREFIX=/srv/hkm ./install.sh      # install somewhere else
#   ./install.sh --uninstall
#
# Installs:
#   $HKM_PREFIX/bin/hkm, hkm-config       (default: ~/.local/bin)
#   $HKM_PREFIX/lib/hkm-kernel/           kernel source + vendor/
#
# That relative layout is not arbitrary: the launcher resolves its kernel by
# probing "<parent of its own dir>/lib/hkm-kernel" (tools/src/lib/kernel.zig),
# so bin/ and lib/ side by side means self-location works with no environment
# variable and no config file.
#
# Your data is NOT inside the install tree and survives upgrades:
#   ~/.config/hkm/config.env              launcher config
#   ~/.local/share/hkm/                   project registry (HKM_USERDATA_DIR)
#
# Still needed from your system administrator, once: PHP >= 8.4 with the
# extensions `hkm doctor` lists. Installing PHP is the one thing a user-local
# install genuinely cannot do for you.
# ---------------------------------------------------------------------------
set -eu

REPO="${HKM_REPO:-AlfaCode-Team/hkm-kernel}"
PREFIX="${HKM_PREFIX:-$HOME/.local}"
KERNEL_DIRNAME="hkm-kernel"
DEST="$PREFIX/lib/$KERNEL_DIRNAME"
BINDIR="$PREFIX/bin"

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
  sed -n '3,30p' "$0" | sed 's/^# \{0,1\}//'
  exit 0
}

# ── arguments ───────────────────────────────────────────────────────────────
while [ $# -gt 0 ]; do
  case "$1" in
    -h|--help)      usage ;;
    --uninstall)    DO_UNINSTALL=1 ;;
    --version)      shift; [ $# -gt 0 ] || die "--version needs a tag (e.g. v1.2.3)"; WANT_TAG="$1" ;;
    --prefix)       shift; [ $# -gt 0 ] || die "--prefix needs a path"; PREFIX="$1"
                    DEST="$PREFIX/lib/$KERNEL_DIRNAME"; BINDIR="$PREFIX/bin" ;;
    --no-composer)  SKIP_COMPOSER=1 ;;
    -*)             die "unknown option: $1  (try --help)" ;;
    *)              TARBALL="$1" ;;
  esac
  shift
done

# ── uninstall ───────────────────────────────────────────────────────────────
if [ "$DO_UNINSTALL" -eq 1 ]; then
  say "Removing $DEST"
  rm -rf "$DEST"
  rm -f "$BINDIR/hkm" "$BINDIR/hkm-config"
  ok "Removed. Your data was left alone:"
  printf '    %s\n    %s\n' "${XDG_CONFIG_HOME:-$HOME/.config}/hkm" \
                            "${XDG_DATA_HOME:-$HOME/.local/share}/hkm"
  printf '  Delete those too if you want a clean slate.\n'
  exit 0
fi

# ── a system install would shadow this one ──────────────────────────────────
# /usr/bin usually precedes ~/.local/bin on PATH, so a leftover .deb install
# silently wins and the user debugs the wrong copy.
if [ -x /usr/bin/hkm ] && [ "$PREFIX" = "$HOME/.local" ]; then
  warn "A system-wide hkm exists at /usr/bin/hkm (installed from the .deb)."
  warn "It will take priority on PATH over this user install."
  warn "Remove it first with:  sudo apt remove hkm-kernel"
fi

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

arch_slug() {
  case "$(uname -m)" in
    x86_64|amd64) echo "x86_64" ;;
    aarch64|arm64) echo "aarch64" ;;
    *) die "unsupported architecture: $(uname -m)" ;;
  esac
}

if [ -n "$TARBALL" ]; then
  [ -f "$TARBALL" ] || die "no such file: $TARBALL"
  say "Using $TARBALL"
else
  OS="$(uname -s)"
  [ "$OS" = "Linux" ] || die "auto-download supports Linux; on $OS use the .app/.zip bundle, or pass a tarball"
  ARCH="$(arch_slug)"

  if [ -n "$WANT_TAG" ]; then
    TAG="$WANT_TAG"
  else
    say "Looking up the latest release of $REPO…"
    API="https://api.github.com/repos/$REPO/releases/latest"
    fetch "$API" "$TMP/rel.json" || die "could not reach GitHub. Download the tarball and pass its path."
    # Deliberately not jq — this script must run on a bare machine.
    TAG="$(sed -n 's/.*"tag_name"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$TMP/rel.json" | head -1)"
    [ -n "$TAG" ] || die "could not parse the latest tag. Pass --version vX.Y.Z or a tarball path."
  fi

  VER="${TAG#v}"
  NAME="hkm-kernel-${VER}-linux-${ARCH}.tar.gz"
  URL="https://github.com/$REPO/releases/download/$TAG/$NAME"
  say "Downloading $NAME"
  fetch "$URL" "$TMP/$NAME" || die "download failed: $URL"
  TARBALL="$TMP/$NAME"
fi

# ── unpack and validate before touching the destination ─────────────────────
say "Unpacking…"
mkdir -p "$TMP/x"
tar -xzf "$TARBALL" -C "$TMP/x" || die "could not extract $TARBALL"

# The archive has a single top-level directory.
SRC="$(find "$TMP/x" -mindepth 1 -maxdepth 1 -type d | head -1)"
[ -n "$SRC" ] || die "unexpected archive layout (no top-level directory)"
[ -f "$SRC/lib/$KERNEL_DIRNAME/composer.json" ] \
  || die "this does not look like an hkm tarball (no lib/$KERNEL_DIRNAME/composer.json)"
[ -x "$SRC/bin/hkm" ] || die "launcher missing from the archive (bin/hkm)"

# ── preserve the registry across an upgrade ─────────────────────────────────
# projects.json / platform.json are USER DATA that happen to live in the kernel
# tree. `hkm-config check` (run at the end) migrates them OUT to the userdata
# dir, which is the permanent fix — but a user who has never run it still has
# their only copy in here, and the swap below would delete it. So carry them
# across first. The .deb marks the same two files as dpkg conffiles.
PRESERVE="projects/projects.json projects/platform.json"
if [ -d "$DEST" ]; then
  for rel in $PRESERVE; do
    if [ -f "$DEST/$rel" ]; then
      mkdir -p "$SRC/lib/$KERNEL_DIRNAME/$(dirname "$rel")"
      cp "$DEST/$rel" "$SRC/lib/$KERNEL_DIRNAME/$rel"
      say "Kept your $rel"
    fi
  done
fi

# ── install ─────────────────────────────────────────────────────────────────
mkdir -p "$BINDIR" "$PREFIX/lib"

# Swap rather than overwrite in place: a half-copied kernel is worse than an old
# one, and rm -rf on the live tree would break a concurrent `hkm` invocation.
NEW="$PREFIX/lib/.$KERNEL_DIRNAME.new.$$"
OLD="$PREFIX/lib/.$KERNEL_DIRNAME.old.$$"
rm -rf "$NEW"
cp -R "$SRC/lib/$KERNEL_DIRNAME" "$NEW"

if [ -d "$DEST" ]; then mv "$DEST" "$OLD"; fi
mv "$NEW" "$DEST"
rm -rf "$OLD"

cp "$SRC/bin/hkm"        "$BINDIR/hkm"
cp "$SRC/bin/hkm-config" "$BINDIR/hkm-config"
chmod +x "$BINDIR/hkm" "$BINDIR/hkm-config"
ok "Installed to $DEST"

# ── repoint a stale kernel pin ──────────────────────────────────────────────
# This is not cosmetic. The launcher loads ~/.config/hkm/config.env into its
# environment BEFORE resolving (main.zig), and resolveHome() checks
# HKM_KERNEL_HOME FIRST — ahead of self-location. So a pin left over from an
# earlier install at a different path silently wins, and `hkm` keeps running the
# OLD kernel while this one sits unused. Repoint it, or self-location never gets
# a look in.
CFG="${XDG_CONFIG_HOME:-$HOME/.config}/hkm/config.env"
if [ -f "$CFG" ]; then
  PINNED="$(sed -n 's/^[[:space:]]*HKM_KERNEL_HOME[[:space:]]*=[[:space:]]*//p' "$CFG" | tail -1)"
  if [ -n "$PINNED" ] && [ "$PINNED" != "$DEST" ]; then
    warn "config.env pins HKM_KERNEL_HOME=$PINNED"
    warn "That would override this install. Repointing it to $DEST"
    "$BINDIR/hkm-config" set-kernel-home "$DEST" >/dev/null 2>&1 \
      || die "could not repoint HKM_KERNEL_HOME — edit $CFG by hand, then re-run"
    ok "Repointed HKM_KERNEL_HOME"
  fi
fi

# ── PHP dependencies (best effort — NEVER a precondition) ───────────────────
# Installing is unconditional on purpose. Copying files into your own home
# cannot fail for want of PHP, and refusing to do it until an administrator has
# installed php8.4-mbstring helps nobody: you end up unable to even read `hkm
# doctor`, which is the thing that would have told you what to ask for.
#
# So: try to build vendor/, and if anything is missing just say so and finish.
# `hkm doctor` is the single authority on whether the environment can actually
# run the kernel, and it is installed and usable either way.
if [ "$SKIP_COMPOSER" -eq 1 ]; then
  say "Skipping dependency resolution (--no-composer)."
elif ! command -v php >/dev/null 2>&1; then
  say "No php on PATH — skipping dependency resolution for now."
elif [ -x "$DEST/install.sh" ]; then
  say "Resolving PHP dependencies (composer install --no-dev)…"
  # The kernel ships its own composer/modules helper (it also handles
  # modules.lock and falls back to downloading composer.phar).
  ( cd "$DEST" && ./install.sh ) || warn "Dependency resolution did not complete — hkm doctor will show why."
else
  say "Resolving PHP dependencies (composer install --no-dev)…"
  ( cd "$DEST" && composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist ) \
    || warn "Dependency resolution did not complete — hkm doctor will show why."
fi

# ── PATH ────────────────────────────────────────────────────────────────────
case ":${PATH}:" in
  *":$BINDIR:"*) ok "$BINDIR is on your PATH" ;;
  *)
    warn "$BINDIR is NOT on your PATH."
    printf '  Add it, then open a new terminal:\n'
    case "${SHELL##*/}" in
      zsh)  printf '    echo '\''export PATH="%s:$PATH"'\'' >> ~/.zshrc\n' "$BINDIR" ;;
      fish) printf '    fish_add_path %s\n' "$BINDIR" ;;
      *)    printf '    echo '\''export PATH="%s:$PATH"'\'' >> ~/.bashrc\n' "$BINDIR" ;;
    esac
    ;;
esac

# ── pin config + move the registry out of the kernel tree ───────────────────
# `hkm-config check` is the canonical step: it pins HKM_KERNEL_HOME, then
# creates ~/.local/share/hkm and MIGRATES projects.json + platform.json out of
# the kernel tree into it (ensureUserdata in config.zig). After this, an upgrade
# cannot touch the registry at all — it no longer lives in the replaced tree.
if [ -x "$BINDIR/hkm-config" ]; then
  say "Pinning configuration…"
  # It exits non-zero when vendor/ is absent, which is the expected state after
  # --no-composer — so only surface that as a problem when composer did run.
  if ! "$BINDIR/hkm-config" check >/dev/null 2>&1; then
    [ "$SKIP_COMPOSER" -eq 1 ] \
      || warn "hkm-config check reported problems — run '$BINDIR/hkm-config check' to see them."
  fi
fi

# ── verify ──────────────────────────────────────────────────────────────────
# The install itself has already succeeded. doctor's exit code reports the
# ENVIRONMENT, not the install, so it must not become this script's exit code.
printf '\n'
DOCTOR_OK=1
if [ -x "$BINDIR/hkm" ]; then
  say "Checking what the kernel still needs…"
  "$BINDIR/hkm" doctor || DOCTOR_OK=0
fi

printf '\n'
ok "Installed for $(id -un) only; nothing was written outside your home."
if [ "$DOCTOR_OK" -eq 0 ]; then
  printf '  Some requirements are not met yet — see "Must fix" above.\n'
  printf '  Installing PHP and its extensions needs an administrator; everything\n'
  printf '  else you can do yourself. Re-check any time with:  hkm doctor\n'
fi
printf '  Kernel:  %s\n' "$DEST"
printf '  Config:  %s/hkm/config.env\n' "${XDG_CONFIG_HOME:-$HOME/.config}"
printf '  Data:    %s/hkm\n' "${XDG_DATA_HOME:-$HOME/.local/share}"
printf '  Remove:  %s --uninstall\n' "$0"
