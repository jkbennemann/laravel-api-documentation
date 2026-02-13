#!/usr/bin/env bash
set -euo pipefail

# Test against the user-service application.
# Uses Spatie Data, custom ResourceTransformer, CQRS patterns.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_PATH="/Users/jakob/workspace/laravel/user-service"

if [[ ! -d "$APP_PATH" ]]; then
    echo "ERROR: user-service not found at $APP_PATH"
    exit 1
fi

echo "=== User Service Test ==="
echo "Expected: Spatie Data DTOs, ResourceTransformer, CQRS pattern"
echo ""

"$SCRIPT_DIR/test-against-app.sh" "$APP_PATH" --format=json
