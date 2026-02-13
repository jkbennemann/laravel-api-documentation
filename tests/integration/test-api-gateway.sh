#!/usr/bin/env bash
set -euo pipefail

# Test against the api-gateway application.
# This is the most critical test: 200+ endpoints, proxy patterns, multi-domain, Spatie Data.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_PATH="/Users/jakob/Workspace/laravel/api-gateway"

if [[ ! -d "$APP_PATH" ]]; then
    echo "ERROR: api-gateway not found at $APP_PATH"
    exit 1
fi

echo "=== API Gateway Test ==="
echo "Expected: 200+ endpoints, multi-domain, proxy patterns, Spatie Data DTOs"
echo ""

"$SCRIPT_DIR/test-against-app.sh" "$APP_PATH" --format=json

echo ""
echo "=== Additional Checks ==="

SPEC_FILE="$APP_PATH/storage/api-docs/api.json"
if [[ -f "$SPEC_FILE" ]] && command -v jq &>/dev/null; then
    # Check for Spatie Data schemas in components
    echo "Checking for Spatie Data schemas..."
    jq '.components.schemas | keys[]' "$SPEC_FILE" 2>/dev/null | head -20 || true

    # Check for security schemes
    echo ""
    echo "Security schemes:"
    jq '.components.securitySchemes // {}' "$SPEC_FILE" 2>/dev/null || true

    # Check for multi-domain specs
    echo ""
    echo "Checking for domain-specific spec files..."
    find "$APP_PATH/storage/api-docs" -name "*.json" -o -name "*.yaml" 2>/dev/null || true
fi
