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
  # Deliberately NARROW: this removes only what this script installed, at this
  # prefix. It does not touch a system (.deb) install, the pre-1.4 user kernel,
  # the config file or the plugin cache — `hkm uninstall` does all of that, and
  # rescues the project registry out of any kernel tree before deleting it.
  say "Removing $DEST"
  rm -rf "$DEST"
  rm -f "$BINDIR/hkm" "$BINDIR/hkm-config"
  ok "Removed. Your data was left alone:"
  printf '    %s\n    %s\n' "${XDG_CONFIG_HOME:-$HOME/.config}/hkm" \
                            "${XDG_DATA_HOME:-$HOME/.local/share}/hkm"
  printf '\n  For a FULL removal — every install on this machine, the config and\n'
  printf '  the plugin cache, while keeping your projects and projects.json:\n'
  printf '      hkm uninstall --dry-run    # see the plan first\n'
  printf '      hkm uninstall              # then do it (sudo for the .deb too)\n'
  exit 0
fi

# ── what is already on this machine ─────────────────────────────────────────
# Installing over an existing install is the ordinary case, not an error — but
# the two are independent, upgrade separately, and PATH silently decides which
# launcher a bare `hkm` runs. Reporting both up front is what turns "I installed
# it and the version did not change" into something the reader can see coming.
kernel_version() { # $1 = kernel root → prints the stamped version, or nothing
  [ -f "$1/composer.json" ] || return 0
  sed -n 's/.*"version"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "$1/composer.json" | head -1
}

SYS_ROOT=/opt/hkm-kernel
SYS_VER="$(kernel_version "$SYS_ROOT")"
OLD_VER="$(kernel_version "$DEST")"

if [ -d "$SYS_ROOT" ] || [ -d "$DEST" ]; then
  say "Existing installs on this machine:"
  [ -d "$SYS_ROOT" ] && printf '    system  %-28s %s\n' "$SYS_ROOT" "${SYS_VER:-unstamped}"
  [ -d "$DEST" ]     && printf '    user    %-28s %s\n' "$DEST"     "${OLD_VER:-unstamped}"
fi

# /usr/bin usually precedes ~/.local/bin on PATH, so a leftover .deb install
# silently wins and the user debugs the wrong copy.
if [ -x /usr/bin/hkm ] && [ "$BINDIR" = "$HOME/.local/bin" ]; then
  warn "A system-wide hkm exists at /usr/bin/hkm (installed from the .deb)."
  warn "It comes FIRST on PATH, so a bare 'hkm' will still run that one."
  warn "Either remove it (sudo apt remove hkm-kernel), or put $BINDIR ahead of"
  warn "/usr/bin in your PATH. 'hkm version' shows both installs at any time."
fi

# The pre-1.4 'hkm upgrade --user' target. It is NOT self-locatable, so it could
# only ever be reached through a config pin — and that pin is read by every
# launcher on the machine, which is how a user install came to redirect the
# system launcher's kernel. Nothing writes there now; say it is being left.
LEGACY_USER="${XDG_DATA_HOME:-$HOME/.local/share}/hkm/kernel"
if [ -f "$LEGACY_USER/composer.json" ] && [ "$LEGACY_USER" != "$DEST" ]; then
  warn "An older user kernel remains at $LEGACY_USER"
  warn "It is superseded by this install and is no longer updated."
  warn "Delete it once 'hkm version' shows the new one active."
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
  if [ "$OS" = "Darwin" ]; then
    die "macOS uses a separate installer — tools/install-macos.sh (curl -fsSL https://github.com/$REPO/releases/latest/download/install-macos.sh | sh)"
  fi
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

# Stage beside the destination, then rename over it. A plain `cp` truncates
# and writes INTO the existing file, which fails with "Text file busy" the
# moment that exact binary is the one currently running this script — i.e.
# every `hkm upgrade` that reaches this installer. rename() swaps the
# directory entry instead: the running process keeps its old (now-unlinked)
# inode and finishes normally, and the next invocation picks up the new build.
cp "$SRC/bin/hkm"        "$BINDIR/.hkm.new.$$"
cp "$SRC/bin/hkm-config" "$BINDIR/.hkm-config.new.$$"
chmod +x "$BINDIR/.hkm.new.$$" "$BINDIR/.hkm-config.new.$$"
mv "$BINDIR/.hkm.new.$$"        "$BINDIR/hkm"
mv "$BINDIR/.hkm-config.new.$$" "$BINDIR/hkm-config"
ok "Installed to $DEST"

# ── drop a kernel pin this install makes redundant ──────────────────────────
# The launcher loads ~/.config/hkm/config.env into its environment before
# resolving. That file is shared by EVERY hkm on the machine, so a pin written
# for one install redirected the other one too — a .deb launcher reporting 1.3.1
# while running a kernel out of the user's home.
#
# The bin/ + lib/ layout above is self-locating (the launcher probes
# "<parent-of-its-own-dir>/lib/hkm-kernel"), so this install needs no pin at
# all. Removing one is therefore strictly better than repointing it: a repointed
# pin still applies machine-wide, while no pin lets each launcher find its own
# kernel. A pin aimed somewhere ELSE is an operator's deliberate choice about a
# custom layout and is only reported.
CFG="${XDG_CONFIG_HOME:-$HOME/.config}/hkm/config.env"
if [ -f "$CFG" ]; then
  PINNED="$(sed -n 's/^[[:space:]]*HKM_KERNEL_HOME[[:space:]]*=[[:space:]]*//p' "$CFG" | tail -1)"
  if [ -n "$PINNED" ]; then
    if [ "$PINNED" = "$DEST" ]; then
      "$BINDIR/hkm-config" unset HKM_KERNEL_HOME >/dev/null 2>&1 \
        && ok "Removed the redundant HKM_KERNEL_HOME pin (the launcher self-locates)"
    elif [ "$PINNED" = "$LEGACY_USER" ]; then
      # The pre-1.4 '--user' target. Not a custom layout an operator chose — a
      # location this very install supersedes, and the one a machine that hit
      # the cross-scope hijack is pinned to. Leaving it would keep a superseded
      # kernel as the fallback for every launcher here, so remove it: the user
      # kernel it named has just been replaced by the one at $DEST.
      "$BINDIR/hkm-config" unset HKM_KERNEL_HOME >/dev/null 2>&1 \
        && ok "Removed the HKM_KERNEL_HOME pin to the superseded $PINNED"
    else
      warn "config.env pins HKM_KERNEL_HOME=$PINNED"
      warn "This install does not need it, and it is shared with every other hkm"
      warn "on this machine. Clear it with:  hkm-config unset HKM_KERNEL_HOME"
    fi
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
    case "${SHELL:-}" in
      */zsh)  printf '    echo '\''export PATH="%s:$PATH"'\'' >> ~/.zshrc\n' "$BINDIR" ;;
      */fish) printf '    fish_add_path %s\n' "$BINDIR" ;;
      *)      printf '    echo '\''export PATH="%s:$PATH"'\'' >> ~/.bashrc\n' "$BINDIR" ;;
    esac
    ;;
esac

# ── move the registry out of the kernel tree ────────────────────────────────
# `hkm-config check` creates ~/.local/share/hkm and MIGRATES projects.json +
# platform.json out of the kernel tree into it (ensureUserdata in config.zig).
# After this, an upgrade cannot touch the registry at all — it no longer lives
# in the replaced tree.
#
# It no longer pins HKM_KERNEL_HOME for a self-locating layout like this one;
# see the pin section above for why a machine-wide pin was the wrong default.
if [ -x "$BINDIR/hkm-config" ]; then
  say "Checking configuration…"
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

# State the version transition explicitly. "Installed" with no number is what
# leaves someone unsure whether anything changed — especially when another
# install on the machine is what their PATH actually resolves.
NEW_VER="$(kernel_version "$DEST")"
if [ -n "$OLD_VER" ] && [ -n "$NEW_VER" ] && [ "$OLD_VER" != "$NEW_VER" ]; then
  printf '  Version: %s -> %s\n' "$OLD_VER" "$NEW_VER"
elif [ -n "$NEW_VER" ]; then
  printf '  Version: %s\n' "$NEW_VER"
fi

if [ "$DOCTOR_OK" -eq 0 ]; then
  printf '  Some requirements are not met yet — see "Must fix" above.\n'
  printf '  Installing PHP and its extensions needs an administrator; everything\n'
  printf '  else you can do yourself. Re-check any time with:  hkm doctor\n'
fi
printf '  Kernel:  %s\n' "$DEST"
printf '  Config:  %s/hkm/config.env\n' "${XDG_CONFIG_HOME:-$HOME/.config}"
printf '  Data:    %s/hkm\n' "${XDG_DATA_HOME:-$HOME/.local/share}"
printf '  Remove:  %s --uninstall\n' "$0"
printf '\n'
printf '  Every install on this machine, and which one your PATH runs:  hkm version\n'
printf '  Update this one later (no root):                              hkm upgrade\n'
