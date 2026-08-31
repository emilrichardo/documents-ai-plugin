#!/usr/bin/env bash
set -euo pipefail

# Commit one plugin's local changes and push to `main` — which is all it takes
# to deploy: .github/workflows/deploy-<slug>.yml is already watching for
# exactly this and does the actual SFTP upload itself, with credentials
# already stored as this repo's GitHub Actions secrets. Nothing here ever
# touches those credentials, and nothing here needs to.
#
# Usage:
#   scripts/publish-plugin.sh <plugin-slug> "commit message"
#   scripts/publish-plugin.sh sacscoc-institutions "Add the Layout control to the search block"
#
# Only stages plugins/<slug>/ — never -A — so this can never sweep in an
# unrelated change sitting elsewhere in the working tree. If there is nothing
# to commit under that path, it says so and stops.
#
# This pushes straight to `main` with no confirmation prompt of its own: it is
# meant to be run when you are ready to ship, the same as typing the
# add/commit/push commands out by hand. If you want to look before pushing,
# run `git diff --stat plugins/<slug>` yourself first.

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="${1:-}"
MESSAGE="${2:-}"

if [ -z "$SLUG" ] || [ -z "$MESSAGE" ]; then
  echo "Usage: $0 <plugin-slug> \"commit message\"" >&2
  echo "  e.g. $0 sacscoc-institutions \"Add the Layout control to the search block\"" >&2
  exit 1
fi

PLUGIN_PATH="plugins/$SLUG"
if [ ! -d "$REPO_ROOT/$PLUGIN_PATH" ]; then
  echo "No such plugin: $PLUGIN_PATH" >&2
  exit 1
fi

cd "$REPO_ROOT"

git add -- "$PLUGIN_PATH"

if git diff --cached --quiet -- "$PLUGIN_PATH"; then
  echo "Nothing changed under $PLUGIN_PATH — nothing to publish."
  exit 0
fi

echo "Staged:"
git diff --cached --stat -- "$PLUGIN_PATH"
echo

git commit -m "$MESSAGE"
git push origin main

echo
echo "Pushed. .github/workflows/deploy-$SLUG.yml should pick this up now —"
if command -v gh >/dev/null 2>&1; then
  echo "watch it with:"
  echo "  gh run watch \$(gh run list --workflow=deploy-$SLUG.yml --limit 1 --json databaseId --jq '.[0].databaseId')"
else
  echo "check the Actions tab on GitHub."
fi
