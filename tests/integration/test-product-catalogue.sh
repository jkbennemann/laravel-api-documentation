#!/usr/bin/env bash
set -euo pipefail

# Test against the product-catalogue application.
# Laravel 12 with PHP 8.2, Action/Workflow pattern.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_PATH="/Users/jakob/Workspace/laravel/product-catalogue"

if [[ ! -d "$APP_PATH" ]]; then
    echo "ERROR: product-catalogue not found at $APP_PATH"
    exit 1
fi

echo "=== Product Catalogue Test ==="
echo "Expected: Laravel 12, PHP 8.2, Action/Workflow pattern"
echo ""

"$SCRIPT_DIR/test-against-app.sh" "$APP_PATH" --format=json
