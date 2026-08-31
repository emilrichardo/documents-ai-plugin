#!/usr/bin/env bash
set -euo pipefail
# scripts/publish-sacscoc.sh "commit message"
exec "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/publish-plugin.sh" sacscoc-institutions "${1:-}"
