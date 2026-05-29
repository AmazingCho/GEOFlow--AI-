#!/usr/bin/env bash
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PROJECT_NAME="$(basename "$PROJECT_DIR")"
DESKTOP_DIR="${HOME}/Desktop"
STAMP="$(date +%Y%m%d_%H%M%S)"
BACKUP_PATH="${DESKTOP_DIR}/GEOFlow_backup_${STAMP}.zip"

if [[ ! -d "$DESKTOP_DIR" ]]; then
  echo "Desktop directory not found: $DESKTOP_DIR" >&2
  exit 1
fi

cd "$(dirname "$PROJECT_DIR")"

if command -v ditto >/dev/null 2>&1; then
  ditto -c -k --sequesterRsrc --keepParent "$PROJECT_NAME" "$BACKUP_PATH"
else
  zip -qry "$BACKUP_PATH" "$PROJECT_NAME"
fi

echo "$BACKUP_PATH"
