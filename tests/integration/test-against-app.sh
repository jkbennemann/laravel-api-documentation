#!/usr/bin/env bash
set -euo pipefail

# Generic test script for validating API documentation generation against a Laravel app.
# Usage: ./test-against-app.sh /path/to/laravel/app [--format=json|yaml] [--domain=example.com]

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PACKAGE_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"

APP_PATH="${1:-}"
FORMAT="${2:---format=json}"
DOMAIN="${3:-}"

if [[ -z "$APP_PATH" ]]; then
    echo "Usage: $0 /path/to/laravel/app [--format=json|yaml] [--domain=example.com]"
    exit 1
fi

if [[ ! -d "$APP_PATH" ]]; then
    echo "ERROR: App directory not found: $APP_PATH"
    exit 1
fi

APP_NAME="$(basename "$APP_PATH")"
echo "============================================="
echo "Testing: $APP_NAME"
echo "Path:    $APP_PATH"
echo "Format:  $FORMAT"
echo "============================================="
echo ""

# Step 1: Ensure package is symlinked
echo "[1/6] Checking package installation..."
if [[ ! -d "$APP_PATH/vendor/jkbennemann/laravel-api-documentation" ]]; then
    echo "  Package not installed. Adding repository and requiring..."
    cd "$APP_PATH"
    composer config repositories.local-api-doc path "$PACKAGE_DIR" 2>/dev/null || true
    composer require jkbennemann/laravel-api-documentation:@dev --dev 2>&1 | tail -5
else
    echo "  Package already installed."
fi

# Step 2: Generate documentation
echo ""
echo "[2/6] Generating documentation..."
cd "$APP_PATH"
XDEBUG_MODE=off php artisan api:generate $FORMAT $DOMAIN --dev 2>&1 | tee /tmp/api-doc-output-${APP_NAME}.txt
GENERATE_EXIT=$?

if [[ $GENERATE_EXIT -ne 0 ]]; then
    echo ""
    echo "ERROR: Documentation generation failed with exit code $GENERATE_EXIT"
    exit 1
fi

echo "  Generation completed successfully."

# Step 3: Find generated spec file
echo ""
echo "[3/6] Locating generated spec..."
SPEC_FILE=""
for f in "$APP_PATH/storage/api-docs/api.json" "$APP_PATH/storage/api-docs/api.yaml" "$APP_PATH/storage/api-documentation/api.json"; do
    if [[ -f "$f" ]]; then
        SPEC_FILE="$f"
        break
    fi
done

if [[ -z "$SPEC_FILE" ]]; then
    echo "  WARNING: Could not find generated spec file. Checking storage..."
    find "$APP_PATH/storage" -name "*.json" -o -name "*.yaml" 2>/dev/null | head -10
    exit 1
fi

echo "  Found: $SPEC_FILE"
SPEC_SIZE=$(wc -c < "$SPEC_FILE" | tr -d ' ')
echo "  Size: ${SPEC_SIZE} bytes"

# Step 4: Validate spec structure
echo ""
echo "[4/6] Validating spec structure..."

if command -v jq &>/dev/null && [[ "$SPEC_FILE" == *.json ]]; then
    OPENAPI_VERSION=$(jq -r '.openapi // "unknown"' "$SPEC_FILE")
    PATHS_COUNT=$(jq '.paths | length' "$SPEC_FILE")
    SCHEMAS_COUNT=$(jq '.components.schemas // {} | length' "$SPEC_FILE")
    SECURITY_COUNT=$(jq '.components.securitySchemes // {} | length' "$SPEC_FILE")
    TAGS_COUNT=$(jq '.tags // [] | length' "$SPEC_FILE")

    echo "  OpenAPI Version:    $OPENAPI_VERSION"
    echo "  Paths (endpoints):  $PATHS_COUNT"
    echo "  Component schemas:  $SCHEMAS_COUNT"
    echo "  Security schemes:   $SECURITY_COUNT"
    echo "  Tags:               $TAGS_COUNT"

    # Check for operations with request bodies
    POST_PUT_PATCH=$(jq '[.paths | to_entries[] | .value | to_entries[] | select(.key == "post" or .key == "put" or .key == "patch")] | length' "$SPEC_FILE")
    WITH_REQ_BODY=$(jq '[.paths | to_entries[] | .value | to_entries[] | select(.key == "post" or .key == "put" or .key == "patch") | select(.value.requestBody != null)] | length' "$SPEC_FILE")
    echo ""
    echo "  POST/PUT/PATCH operations: $POST_PUT_PATCH"
    echo "  With request body:         $WITH_REQ_BODY"

    # Check for response schemas
    TOTAL_OPS=$(jq '[.paths | to_entries[] | .value | to_entries[] | select(.key | test("get|post|put|patch|delete"))] | length' "$SPEC_FILE")
    WITH_RESPONSES=$(jq '[.paths | to_entries[] | .value | to_entries[] | select(.key | test("get|post|put|patch|delete")) | select(.value.responses | length > 0)] | length' "$SPEC_FILE")
    echo "  Total operations:          $TOTAL_OPS"
    echo "  With responses defined:    $WITH_RESPONSES"

    # Check for $ref usage
    REF_COUNT=$(jq '[.. | objects | select(has("$ref"))] | length' "$SPEC_FILE" 2>/dev/null || echo "N/A")
    echo "  \$ref references:           $REF_COUNT"
else
    echo "  jq not available or YAML format — skipping detailed analysis"
    echo "  Checking basic JSON validity..."
    python3 -c "import json; json.load(open('$SPEC_FILE'))" 2>&1 && echo "  Valid JSON." || echo "  NOT valid JSON!"
fi

# Step 5: Lint with spectral (if available)
echo ""
echo "[5/6] Linting with spectral..."
if command -v npx &>/dev/null; then
    npx --yes @stoplight/spectral-cli@latest lint "$SPEC_FILE" --fail-severity=error 2>&1 | tail -20 || true
else
    echo "  npx not available — skipping spectral lint"
fi

# Step 6: Compare route coverage
echo ""
echo "[6/6] Route coverage comparison..."
cd "$APP_PATH"
ROUTE_COUNT=$(XDEBUG_MODE=off php artisan route:list --json 2>/dev/null | jq 'length' 2>/dev/null || echo "N/A")
echo "  Total Laravel routes: $ROUTE_COUNT"
echo "  Documented paths:     $PATHS_COUNT"

if [[ "$ROUTE_COUNT" != "N/A" && "$PATHS_COUNT" != "" ]]; then
    if [[ $ROUTE_COUNT -gt 0 ]]; then
        COVERAGE=$(( PATHS_COUNT * 100 / ROUTE_COUNT ))
        echo "  Coverage:             ${COVERAGE}%"
    fi
fi

echo ""
echo "============================================="
echo "Test complete for: $APP_NAME"
echo "============================================="
