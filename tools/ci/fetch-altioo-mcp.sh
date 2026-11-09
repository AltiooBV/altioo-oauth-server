#!/usr/bin/env bash
#
# Fetches the pinned altioo-mcp release and unpacks it, verified, for install-itop.sh to place in
# extensions/ in place of the stand-in tools/ci/fixtures/altioo-mcp (FIXTURE_RELEASES_DIR there).
#
# The archive runs inside iTop with full rights, so it is taken only when it is the one pinned in
# tools/ci/altioo-mcp-release.env: its SHA-256 must equal the pinned value, not merely the
# .sha256 file published beside it, which comes from the same place as the archive. In CI
# (ALTIOO_MCP_REQUIRE_ATTESTATION=1) the release's GitHub attestation must verify too, against
# AltiooBV/altioo-mcp; elsewhere it is checked when an authenticated gh is at hand and skipped,
# with a line saying so, when not.
#
# Usage: tools/ci/fetch-altioo-mcp.sh <dest dir>
#        -> <dest dir>/altioo-mcp/, the module as an administrator unzips it
#
# @copyright   Copyright (C) 2026 Altioo
# @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later

set -euo pipefail

DEST="${1:?usage: fetch-altioo-mcp.sh <dest dir>}"
PIN="$(dirname "${BASH_SOURCE[0]}")/altioo-mcp-release.env"
REPO=AltiooBV/altioo-mcp

# Read, not sourced: two values, and nothing in the file is run.
VERSION=$(sed -n 's/^ALTIOO_MCP_VERSION=\([0-9][0-9.]*\)$/\1/p' "$PIN")
SHA256=$(sed -n 's/^ALTIOO_MCP_SHA256=\([0-9a-f]\{64\}\)$/\1/p' "$PIN")
[ -n "$VERSION" ] && [ -n "$SHA256" ] || { echo "::error::$PIN does not hold a version and a SHA-256"; exit 1; }

ZIP="altioo-mcp-${VERSION}.zip"
URL="https://github.com/${REPO}/releases/download/v${VERSION}/${ZIP}"

STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT INT TERM

echo "fetching altioo-mcp $VERSION from $URL"
curl -fsSL --retry 3 "$URL" -o "$STAGE/$ZIP"
curl -fsSL --retry 3 "$URL.sha256" -o "$STAGE/$ZIP.sha256"

ACTUAL=$(sha256sum "$STAGE/$ZIP" | cut -d' ' -f1)
[ "$ACTUAL" = "$SHA256" ] \
  || { echo "::error::$ZIP has SHA-256 $ACTUAL, the pin says $SHA256"; exit 1; }
PUBLISHED=$(cut -d' ' -f1 "$STAGE/$ZIP.sha256")
[ "$PUBLISHED" = "$SHA256" ] \
  || { echo "::error::the release publishes SHA-256 $PUBLISHED for $ZIP, the pin says $SHA256"; exit 1; }
echo "   ok  SHA-256 $SHA256, as pinned and as published"

if command -v gh >/dev/null 2>&1 && gh auth status >/dev/null 2>&1; then
  gh attestation verify "$STAGE/$ZIP" --repo "$REPO" >/dev/null \
    || { echo "::error::$ZIP does not carry a valid attestation from $REPO"; exit 1; }
  echo "   ok  attestation from $REPO verified"
elif [ -n "${ALTIOO_MCP_REQUIRE_ATTESTATION:-}" ]; then
  echo "::error::ALTIOO_MCP_REQUIRE_ATTESTATION is set and no authenticated gh is available to verify it"
  exit 1
else
  echo "   --  attestation not verified: no authenticated gh here (CI requires it)"
fi

rm -rf "$DEST/altioo-mcp"
mkdir -p "$DEST"
unzip -q "$STAGE/$ZIP" -d "$STAGE/tree"
# The archive's one top-level directory is the module; anything else in it is not what was pinned.
[ "$(ls -A "$STAGE/tree")" = "altioo-mcp" ] \
  || { echo "::error::$ZIP does not hold exactly one altioo-mcp/ directory: $(ls -A "$STAGE/tree" | tr '\n' ' ')"; exit 1; }
grep -q "'altioo-mcp/${VERSION}'" "$STAGE/tree/altioo-mcp/module.altioo-mcp.php" \
  || { echo "::error::$ZIP does not declare altioo-mcp/$VERSION"; exit 1; }
mv "$STAGE/tree/altioo-mcp" "$DEST/altioo-mcp"
echo "altioo-mcp $VERSION unpacked at $DEST/altioo-mcp"
