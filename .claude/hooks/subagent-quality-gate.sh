#!/usr/bin/env bash
# SubagentStop hook — quality gate for implementation agents
# Runs Pint + tests after laravel-senior-architect or frontend-ui-architect finishes
# Exit 0 = OK, Exit 2 = block (agent must fix issues)

set -euo pipefail

PROJECT_DIR="${CLAUDE_PROJECT_DIR:-.}"

# Check if there are any changed PHP files (no point running Pint/tests if no PHP changes)
CHANGED_PHP=$(cd "$PROJECT_DIR" && git diff --name-only HEAD 2>/dev/null | grep -c '\.php$' || true)

if [ "$CHANGED_PHP" -eq 0 ]; then
    echo "No PHP files changed — skipping quality gate." >&2
    exit 0
fi

# Run Pint check
echo "Running Pint style check..." >&2
PINT_RESULT=$(cd "$PROJECT_DIR" && docker compose exec -T app ./vendor/bin/pint --test 2>&1) || {
    echo "BLOCKED: Pint style check failed." >&2
    echo "$PINT_RESULT" | tail -20 >&2
    echo "" >&2
    echo "Fix style issues before completing." >&2
    exit 2
}

echo "Pint: OK" >&2

# Run tests
echo "Running PHPUnit tests..." >&2
TEST_RESULT=$(cd "$PROJECT_DIR" && docker compose exec -T app php artisan test 2>&1) || {
    # Check if failures are only pre-existing ones
    NEW_FAILURES=$(echo "$TEST_RESULT" | grep -c "FAILED" || true)
    KNOWN_FAILURES=$(echo "$TEST_RESULT" | grep -c "BookingServiceArea\|BookingConfirmation\|TenantFeature" || true)

    if [ "$NEW_FAILURES" -gt "$KNOWN_FAILURES" ]; then
        echo "BLOCKED: New test failures detected." >&2
        echo "$TEST_RESULT" | grep "FAILED" | grep -v "BookingServiceArea\|BookingConfirmation\|TenantFeature" >&2
        echo "" >&2
        echo "Fix new test failures before completing." >&2
        exit 2
    fi
}

echo "Tests: OK (pre-existing failures excluded)" >&2
echo "Quality gate passed." >&2
exit 0
