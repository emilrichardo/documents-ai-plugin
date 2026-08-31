#!/usr/bin/env bash
set -euo pipefail
# One-command shortcut: scripts/deploy-plugin.sh ai-documents
exec "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/deploy-plugin.sh" ai-documents
