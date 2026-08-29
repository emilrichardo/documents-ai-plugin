#!/usr/bin/env bash
#
# Build the documentation and the downloadable plugin zip, in that order.
#
#   bash tools/build-docs.sh
#
# The version is read from the plugin header and nowhere else, so the zip
# filename, the landing page's download button and the plugin's own
# Documentation screen can never disagree about which build they describe.
# The previous zip is deleted, not kept: docs/downloads/ holds exactly one
# file, and it is always the current version.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
NAME="$(basename "$ROOT")"
PARENT="$(dirname "$ROOT")"

VERSION="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\(.*\)$/\1/p' "$ROOT/ai-documents.php" | head -1 | tr -d '[:space:]')"
if [ -z "$VERSION" ]; then
  echo "Could not read Version from ai-documents.php" >&2
  exit 1
fi

ZIP_DIR="$ROOT/docs/downloads"
ZIP_NAME="ai-documents-$VERSION.zip"
mkdir -p "$ZIP_DIR" "$ROOT/docs/generated" "$ROOT/docs/assets/screenshots"

# 1. Regenerate the pages first, so the fragment that goes into the zip is the
#    current one rather than the previous build's.
php "$ROOT/tools/build-docs.php" > /dev/null

# 2. Replace the download. Built to a temporary file and moved into place, so a
#    failed run never leaves docs/downloads/ empty or half-written.
TMP_ZIP="$(mktemp -t aidocs-zip)".zip
rm -f "$TMP_ZIP"

( cd "$PARENT" && zip -r -q -X "$TMP_ZIP" "$NAME" \
    -x "$NAME/.git/*" \
       "$NAME/.github/*" \
       "$NAME/.claude/*" \
       "$NAME/.gitignore" \
       "*/.DS_Store" \
       "$NAME/docs/downloads/*" \
       "$NAME/docs/index.html" \
       "$NAME/docs/assets/docs.css" \
       "$NAME/docs/assets/docs.js" )

rm -f "$ZIP_DIR"/*.zip
mv "$TMP_ZIP" "$ZIP_DIR/$ZIP_NAME"

# 3. Regenerate once more, now that the zip exists, so the download button
#    carries its real filename and size.
php "$ROOT/tools/build-docs.php"
