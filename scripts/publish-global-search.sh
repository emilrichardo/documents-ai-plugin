#!/usr/bin/env bash
set -euo pipefail
# scripts/publish-global-search.sh "commit message"
exec "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/publish-plugin.sh" global-search "${1:-}"
