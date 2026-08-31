#!/usr/bin/env bash
set -euo pipefail

# Push one plugin's LOCAL working copy straight to the demo/staging site over
# SFTP — the fast path for "does this look right for real" while developing,
# without waiting on a commit, a push, or a CI run.
#
# Usage:
#   scripts/deploy-plugin.sh <plugin-slug>
#   scripts/deploy-plugin.sh sacscoc-institutions
#
# ── This is not the same thing as the GitHub Actions deploy ─────────────────
#
# .github/workflows/deploy-<slug>.yml is what keeps the demo site in sync with
# `main`: it runs on every push that touches a plugin, and it is the source of
# truth for what stays deployed. This script bypasses git entirely — it
# uploads whatever is on disk right now, committed or not — so anything it
# pushes that is never committed gets overwritten by the next CI deploy. That
# is the intended trade: this script is for iterating quickly, the CI workflow
# is for shipping.
#
# Both use the exact same four credentials and the exact same upload method
# (a password-authenticated SFTP session via sshpass — the demo host has no
# shell access and no key-based auth, so this is the only method that works),
# so a change proven here behaves the same way once it lands on `main`.
#
# ── Credentials ───────────────────────────────────────────────────────────
#
# Read from the environment: SFTP_HOST, SFTP_PORT, SFTP_USERNAME,
# SFTP_PASSWORD — the same names this repo's GitHub Actions secrets already
# use. Nothing here ever hardcodes a value. Put them in a git-ignored `.env`
# at the repo root (copy .env.example) and this script loads it automatically;
# exporting them in your own shell works exactly as well and takes priority.

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SLUG="${1:-}"

if [ -z "$SLUG" ]; then
  echo "Usage: $0 <plugin-slug>" >&2
  echo "  e.g. $0 sacscoc-institutions" >&2
  exit 1
fi

PLUGIN_DIR="$REPO_ROOT/plugins/$SLUG"
if [ ! -d "$PLUGIN_DIR" ]; then
  echo "No such plugin: plugins/$SLUG" >&2
  exit 1
fi

# Load .env if present. `set -a` exports everything sourced from it, but only
# for variables not already set — a value exported in the calling shell still
# wins, so `SFTP_PASSWORD=... scripts/deploy-plugin.sh x` can override it
# per-run without editing the file.
if [ -f "$REPO_ROOT/.env" ]; then
  set -a
  # shellcheck disable=SC1091
  source "$REPO_ROOT/.env"
  set +a
fi

: "${SFTP_HOST:?Set SFTP_HOST — export it, or add it to $REPO_ROOT/.env (copy .env.example)}"
: "${SFTP_PORT:?Set SFTP_PORT}"
: "${SFTP_USERNAME:?Set SFTP_USERNAME}"
: "${SFTP_PASSWORD:?Set SFTP_PASSWORD}"

if ! command -v sshpass >/dev/null 2>&1; then
  echo "sshpass is required — the demo host only accepts password auth over SFTP, the same as the CI deploy." >&2
  echo "Install: brew install hudochenkov/sshpass/sshpass" >&2
  exit 1
fi

REMOTE_PATH="public_html/wp-content/plugins/$SLUG"

echo "Deploying plugins/$SLUG -> ${SFTP_USERNAME}@${SFTP_HOST}:${REMOTE_PATH}"

# A clean staging copy, so dev-only cruft never reaches the site — the same
# thing the CI workflow's own "Strip dev-only files before deploy" step does,
# kept identical on purpose so a local push and a CI push produce the same
# remote state for the same source tree.
STAGE="$(mktemp -d)"
cp -R "$PLUGIN_DIR" "$STAGE/$SLUG"
rm -rf "$STAGE/$SLUG/docs/downloads"
find "$STAGE/$SLUG" -name '.DS_Store' -delete

# sftp has no "sync contents of this directory into that one" command, so the
# batch script lists the staged plugin's own top-level entries and `put -r`s
# each in turn, with the remote cwd already inside the target directory —
# which is what actually merges contents in, rather than nesting a second
# sacscoc-institutions/ folder one level too deep.
ENTRIES="$(cd "$STAGE/$SLUG" && ls -A)"
if [ -z "$ENTRIES" ]; then
  echo "plugins/$SLUG is empty — nothing to deploy." >&2
  exit 1
fi

BATCH="$(mktemp)"
trap 'rm -rf "$STAGE" "$BATCH"' EXIT
{
  # -mkdir with a leading dash carries on if the directory already exists —
  # exactly like the CI's "Ensure the remote directory exists" step, there for
  # the same reason: a plugin's first ever deploy has nowhere to land otherwise.
  echo "-mkdir $REMOTE_PATH"
  echo "lcd \"$STAGE/$SLUG\""
  echo "cd \"$REMOTE_PATH\""
  while IFS= read -r entry; do
    printf 'put -r "%s"\n' "$entry"
  done <<< "$ENTRIES"
  echo "bye"
} > "$BATCH"

# BatchMode=no and PreferredAuthentications=password, both set explicitly:
# BatchMode=yes (ssh's own default once any -o is given) disables password
# auth outright, which is what makes sshpass unable to answer the prompt.
sshpass -p "$SFTP_PASSWORD" sftp \
  -o StrictHostKeyChecking=no \
  -o BatchMode=no \
  -o PreferredAuthentications=password \
  -o PubkeyAuthentication=no \
  -P "$SFTP_PORT" -b "$BATCH" "${SFTP_USERNAME}@${SFTP_HOST}"

echo "Done."
