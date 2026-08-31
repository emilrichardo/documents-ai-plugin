#!/usr/bin/env bash
set -euo pipefail
# scripts/publish-ai-documents.sh "commit message"
exec "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/publish-plugin.sh" ai-documents "${1:-}"
