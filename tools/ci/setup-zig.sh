#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# setup-zig.sh — install the PINNED Zig toolchain in CI, resiliently.
#
# The version in tools/.zig-version is a Zig *dev* build, which upstream purges
# from ziglang.org/builds after a few months. To keep CI reproducible we fetch
# it from a SELF-HOSTED GitHub release first (ZIG_DIST_URL), then fall back to
# the community mirrors, which keep dev builds indefinitely.
#
# Publish the self-hosted copy once with tools/ci/publish-zig-toolchain.sh and
# set the repo variable ZIG_DIST_URL to that release's download base, e.g.
#   https://github.com/AlfaCode-Team/hkm-kernel/releases/download/zig-toolchain
#
# NOTE: ZIG_DIST_URL is a repo variable, so it is EMPTY for pull requests from a
# fork. The public-mirror fallback is what keeps fork PRs green — it must work
# on its own, without any repo configuration.
#
# TARBALL NAMING: Zig flipped the tarball name from zig-<os>-<arch>-<ver> to
# zig-<arch>-<os>-<ver> as of 0.14.1. Both spellings are tried so this script
# keeps working whichever side of that change the pinned version sits on.
#
# Adds the extracted toolchain dir to GITHUB_PATH (or prints it locally).
# ---------------------------------------------------------------------------
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
VER="$(cat "$HERE/../.zig-version")"

case "$(uname -s)" in
  Linux)  ZOS=linux ;;
  Darwin) ZOS=macos ;;
  *)      ZOS=linux ;;
esac
case "$(uname -m)" in
  x86_64|amd64)  ZARCH=x86_64 ;;
  arm64|aarch64) ZARCH=aarch64 ;;
  *)             ZARCH=x86_64 ;;
esac

# Upstream tarball names — current (arch first) and legacy (os first) spellings.
NAME_CURRENT="zig-${ZARCH}-${ZOS}-${VER}.tar.xz"
NAME_LEGACY="zig-${ZOS}-${ZARCH}-${VER}.tar.xz"
SELFHOSTED="zig-${ZOS}-${ZARCH}.tar.xz"          # release-asset naming (no '+')

# Public sources, in order. ziglang.org only serves the CURRENT master build, so
# it hits solely while the pin is fresh; the community mirrors below are the ones
# that still carry an aged dev build. Most community mirrors keep TAGGED releases
# only — the three here were verified against the current pin (machengine
# redirects to hexops; it stays as a second entry point to the same store).
# Full official list, worth re-checking when the pin changes:
#   https://ziglang.org/download/community-mirrors.txt
bases=(
  "https://ziglang.org/builds"
  "https://pkg.hexops.org/zig"
  "https://pkg.machengine.org/zig"
  "https://zig.squirl.dev"
)

DEST="${RUNNER_TEMP:-/tmp}/zig-toolchain"
mkdir -p "$DEST"
TARBALL="$DEST/zig.tar.xz"

# Ordered candidate URLs: self-hosted first, then every public base × both names.
urls=()
[ -n "${ZIG_DIST_URL:-}" ] && urls+=("${ZIG_DIST_URL%/}/${SELFHOSTED}")
for b in "${bases[@]}"; do
  urls+=("${b%/}/${NAME_CURRENT}" "${b%/}/${NAME_LEGACY}")
done

fetched=""
for u in "${urls[@]}"; do
  echo "▶ trying $u"
  # --connect-timeout keeps a dead mirror from stalling the job; --retry only
  # fires on transient errors, so a 404 falls straight through to the next URL.
  if curl -fSL --connect-timeout 15 --max-time 900 \
          --retry 3 --retry-delay 4 -o "$TARBALL" "$u"; then
    fetched="$u"; break
  fi
done
if [ -z "$fetched" ]; then
  echo "ERROR: could not fetch Zig $VER from any source."
  echo "  Tried ${#urls[@]} URLs (self-hosted + community mirrors, both namings)."
  if [ -z "${ZIG_DIST_URL:-}" ]; then
    echo "  ZIG_DIST_URL is empty — expected for a fork PR (repo variables are not"
    echo "  exposed to them), so this run depended entirely on the public mirrors."
  fi
  echo "  If the mirrors have purged this dev build, host it yourself:"
  echo "    ZIG_HOME=/opt/zig ./tools/ci/publish-zig-toolchain.sh"
  echo "    gh variable set ZIG_DIST_URL -b '<release-download-base>'"
  echo "  Fork PRs cannot use that — repin tools/.zig-version to a TAGGED release"
  echo "  (mirrored permanently) if fork contributions must build the launcher."
  exit 1
fi
echo "✓ fetched from $fetched"

tar -xf "$TARBALL" -C "$DEST"
# Locate the zig binary regardless of the extracted top-level dir name.
ZIG_BIN="$(find "$DEST" -maxdepth 2 -type f -name zig | head -1)"
[ -n "$ZIG_BIN" ] || { echo "ERROR: zig binary not found after extract"; exit 1; }
ZIG_DIR="$(dirname "$ZIG_BIN")"

# Assert we got the pinned build — a mirror serving a different version must fail
# here, not silently compile the launcher with the wrong toolchain.
got="$("$ZIG_BIN" version)"
if [ "$got" != "$VER" ]; then
  echo "ERROR: fetched Zig $got but tools/.zig-version pins $VER (source: $fetched)"
  exit 1
fi

if [ -n "${GITHUB_PATH:-}" ]; then
  echo "$ZIG_DIR" >> "$GITHUB_PATH"
fi
echo "Zig installed at $ZIG_DIR"
echo "$got"
