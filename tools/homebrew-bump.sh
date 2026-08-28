#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# homebrew-bump.sh — point HomebrewFormula/hkm.rb at a release.
#
#   ./tools/homebrew-bump.sh 1.5.0                  # sha256 from the published asset
#   ./tools/homebrew-bump.sh 1.5.0 dist/hkm-....tar.gz   # sha256 from a local file
#
# Rewrites `url` and `sha256` in place. Everything else in the formula is
# hand-maintained and left untouched — the version is not stated separately,
# Homebrew reads it back out of the URL.
#
# WHY THIS EXISTS
# ---------------
# A tap formula pins an exact tarball by digest, so it goes stale the instant a
# release ships — and a stale formula does not fail loudly, it installs the
# PREVIOUS version and looks like it worked. The release workflow runs this so
# the pin is a build product, not something a human has to remember.
#
# The local-file form is what makes the formula testable before a release
# exists: build with `tools/bundle.sh macos`, bump against dist/, then
# `brew install --build-from-source ./HomebrewFormula/hkm.rb`.
# ---------------------------------------------------------------------------
set -euo pipefail

REPO="${HKM_REPO:-AlfaCode-Team/hkm-kernel}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
FORMULA="${HKM_FORMULA:-$HERE/../HomebrewFormula/hkm.rb}"

die() { printf 'homebrew-bump: %s\n' "$*" >&2; exit 1; }

VERSION="${1:-}"
[ -n "$VERSION" ] || die "usage: homebrew-bump.sh <version> [local-tarball]"
VERSION="${VERSION#v}"
LOCAL="${2:-}"

NAME="hkm-kernel-${VERSION}-macos-universal.tar.gz"
URL="https://github.com/$REPO/releases/download/v${VERSION}/${NAME}"

# sha256 of the exact bytes the formula will download.
if [ -n "$LOCAL" ]; then
  [ -f "$LOCAL" ] || die "no such file: $LOCAL"
  SHA="$(shasum -a 256 "$LOCAL" | cut -d' ' -f1)"
  # A local build is only a stand-in for the real asset — say so, so a
  # hand-run test bump is never mistaken for a release pin.
  printf 'note: sha256 taken from %s, NOT from the published asset\n' "$LOCAL" >&2
  URL="${HKM_URL_OVERRIDE:-$URL}"
else
  TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
  curl -fsSL "$URL" -o "$TMP/$NAME" || die "could not download $URL"
  SHA="$(shasum -a 256 "$TMP/$NAME" | cut -d' ' -f1)"
fi

[ "${#SHA}" -eq 64 ] || die "bad sha256: $SHA"
[ -f "$FORMULA" ] || die "no formula at $FORMULA"

# Anchored to the leading two spaces of the DSL call so the identical strings
# inside the header comment and the caveats are never touched.
#
# The PLACEHOLDER-DIGEST notice is dropped at the same time. It warns that the
# committed digest cannot be installed yet, which stops being true the moment
# this script writes a real one — leaving it in place would turn an accurate
# warning into a lie about a working formula.
TMPF="$(mktemp)"
URL="$URL" SHA="$SHA" awk '
  /^  url "/    { print "  url \"" ENVIRON["URL"] "\"";   next }
  /^  sha256 "/ { print "  sha256 \"" ENVIRON["SHA"] "\""; next }
  /PLACEHOLDER-DIGEST/ { drop = 1; next }
  drop && /^  # / { next }
  { drop = 0; print }
' "$FORMULA" > "$TMPF"

# Every one of the three lines must have been found — a silent no-op here is
# exactly the stale pin this script exists to prevent.
for field in url sha256; do
  grep -q "^  $field \"" "$TMPF" || die "formula has no '$field' line to rewrite"
done
grep -q "^  sha256 \"$SHA\"" "$TMPF" || die "sha256 rewrite did not take"

mv "$TMPF" "$FORMULA"
printf 'homebrew-bump: %s -> %s\n  %s\n  sha256 %s\n' "$(basename "$FORMULA")" "$VERSION" "$URL" "$SHA"
