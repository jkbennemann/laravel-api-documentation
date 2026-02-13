#!/usr/bin/env bash
set -euo pipefail

# Run all integration tests against available local applications.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PASSED=0
FAILED=0
SKIPPED=0

run_test() {
    local script="$1"
    local name="$(basename "$script" .sh)"

    echo ""
    echo "######################################################"
    echo "# Running: $name"
    echo "######################################################"
    echo ""

    if [[ -x "$script" ]]; then
        if "$script" 2>&1; then
            PASSED=$((PASSED + 1))
            echo ""
            echo ">>> PASSED: $name"
        else
            FAILED=$((FAILED + 1))
            echo ""
            echo ">>> FAILED: $name"
        fi
    else
        SKIPPED=$((SKIPPED + 1))
        echo ">>> SKIPPED: $name (not executable or missing dependency)"
    fi
}

# Run each test
for test_script in "$SCRIPT_DIR"/test-api-gateway.sh \
                    "$SCRIPT_DIR"/test-user-service.sh \
                    "$SCRIPT_DIR"/test-product-catalogue.sh \
                    "$SCRIPT_DIR"/test-product-management.sh; do
    if [[ -f "$test_script" ]]; then
        run_test "$test_script"
    fi
done

echo ""
echo "======================================================"
echo "Integration Test Summary"
echo "======================================================"
echo "  Passed:  $PASSED"
echo "  Failed:  $FAILED"
echo "  Skipped: $SKIPPED"
echo "======================================================"

if [[ $FAILED -gt 0 ]]; then
    exit 1
fi
