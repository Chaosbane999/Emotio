#!/usr/bin/env bash
#
# Build a distributable, WordPress-installable ZIP of the plugin.
#
# Produces dist/ai-visibility-monitor-<version>.zip containing a single
# top-level ai-visibility-monitor/ folder with runtime files only (dev and
# test tooling is excluded). Run from anywhere:
#
#   bash ai-visibility-monitor/bin/build-zip.sh
#
set -euo pipefail

# Resolve paths relative to this script (plugin dir is one level up from bin/).
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(dirname "$SCRIPT_DIR")"
REPO_ROOT="$(dirname "$PLUGIN_DIR")"
SLUG="ai-visibility-monitor"

VERSION="$(grep -oP 'Version:\s*\K[0-9.]+' "$PLUGIN_DIR/$SLUG.php" | head -1)"
OUT_DIR="$REPO_ROOT/dist"
OUT_ZIP="$OUT_DIR/$SLUG-$VERSION.zip"

# Files/dirs to exclude from the distributed package.
EXCLUDES=(
	"tests"
	"bin"
	"composer.json"
	"composer.lock"
	"phpcs.xml.dist"
	"phpstan.neon.dist"
	"phpunit.xml.dist"
)

STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

cp -r "$PLUGIN_DIR" "$STAGE/$SLUG"
for item in "${EXCLUDES[@]}"; do
	rm -rf "$STAGE/$SLUG/$item"
done
find "$STAGE" -name '.DS_Store' -delete

mkdir -p "$OUT_DIR"
rm -f "$OUT_ZIP"
( cd "$STAGE" && zip -rq "$OUT_ZIP" "$SLUG" )

echo "Built: $OUT_ZIP"
unzip -l "$OUT_ZIP" | tail -1
