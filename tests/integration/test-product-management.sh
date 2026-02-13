#!/usr/bin/env bash
set -euo pipefail

# Test against the product-management-service.
# Single-action controllers, CQRS pattern.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_PATH="/Users/jakob/Workspace/laravel/product-management-service"

if [[ ! -d "$APP_PATH" ]]; then
    echo "ERROR: product-management-service not found at $APP_PATH"
    exit 1
fi

echo "=== Product Management Service Test ==="
echo "Expected: Single-action controllers, CQRS"
echo ""

"$SCRIPT_DIR/test-against-app.sh" "$APP_PATH" --format=json
