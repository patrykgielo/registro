#!/usr/bin/env bash
# SubagentStop hook — quality gate for implementation agents
# Runs Pint + tests after laravel-senior-architect or frontend-ui-architect finishes
# Exit 0 = OK, Exit 2 = block (agent must fix issues)
#
# Runs inside WSL even when Claude Code runs from Windows (lib/wsl-forward.sh):
# `docker` does not exist on the Windows side. It inspects the WSL tree that was
# actually changed (lib/tree.sh), not the Windows mirror clone.
#
# The Docker `app` container bind-mounts ONE tree at /var/www. Pint and the
# tests inside it run against THAT tree's files. A change made in any other
# worktree is invisible to them, so running the gate there would "pass" without
# having looked at the change -- exactly the success this project refuses to
# report. In that case the gate says SKIPPED, loudly, instead.

set -euo pipefail

_HOOKS="${BASH_SOURCE[0]%/*}"
# shellcheck source=lib/wsl-forward.sh
source "$_HOOKS/lib/wsl-forward.sh"
registro_wsl_forward "${BASH_SOURCE[0]}" "$@"
# shellcheck source=lib/tree.sh
source "$_HOOKS/lib/tree.sh"

hook_read_input

# Already continuing because of a stop hook: never block twice in a row.
if [[ "$HOOK_INPUT" =~ \"stop_hook_active\"[[:space:]]*:[[:space:]]*true ]]; then
    exit 0
fi

REGISTRO_APP_CONTAINER="${REGISTRO_APP_CONTAINER:-registro-app}"

skipped() {
    printf '{"systemMessage":"subagent-quality-gate: SKIPPED -- %s. Pint and tests did NOT run for this change."}\n' "$(hook_json_escape "$1")"
    echo "subagent-quality-gate: SKIPPED -- $1" >&2
    exit 0
}

# Check if there are any changed PHP files (no point running Pint/tests if no PHP changes)
if ! registro_active_tree; then
    if [ -z "$(registro_candidate_trees)" ]; then
        skipped "no git work tree found to inspect (REGISTRO_WSL_TREE=${REGISTRO_WSL_TREE:-unset})"
    fi
    echo "No changes in any tree — skipping quality gate." >&2
    exit 0
fi
CHANGED_PHP=$(echo "$REGISTRO_ACTIVE_CHANGES" | grep -c '\.php$' || true)

if [ "$CHANGED_PHP" -eq 0 ]; then
    echo "No PHP files changed in $REGISTRO_ACTIVE_TREE — skipping quality gate." >&2
    exit 0
fi

TREE="$REGISTRO_ACTIVE_TREE"
command -v docker >/dev/null 2>&1 || skipped "docker is not available in this environment"

MOUNT="$(docker inspect "$REGISTRO_APP_CONTAINER" --format '{{range .Mounts}}{{if eq .Destination "/var/www"}}{{.Source}}{{end}}{{end}}' 2>/dev/null || true)"
[ -n "$MOUNT" ] || skipped "container '$REGISTRO_APP_CONTAINER' is not running or has no /var/www mount"

if [ "$(cd "$TREE" && pwd -P)" != "$(cd "$MOUNT" 2>/dev/null && pwd -P)" ]; then
    skipped "the changes are in $TREE but container '$REGISTRO_APP_CONTAINER' mounts $MOUNT, so Pint/tests inside it would check different files"
fi

echo "Quality gate on $TREE" >&2

# Run Pint check
echo "Running Pint style check..." >&2
PINT_RESULT=$(cd "$TREE" && docker compose exec -T app ./vendor/bin/pint --test 2>&1) || {
    echo "BLOCKED: Pint style check failed." >&2
    echo "$PINT_RESULT" | tail -20 >&2
    echo "" >&2
    echo "Fix style issues before completing." >&2
    exit 2
}

echo "Pint: OK" >&2

# Run tests
echo "Running PHPUnit tests..." >&2
TEST_RC=0
TEST_RESULT=$(cd "$TREE" && docker compose exec -T app php artisan test 2>&1) || TEST_RC=$?

# "Tests passed" needs POSITIVE evidence the suite ran to completion: the summary
# line (`Tests:  12 passed ...`). It used to count the word FAILED, so a suite that
# never started -- container error, fatal PHP error, missing autoload -- printed no
# FAILED line at all and went through as "Tests: OK".
CLEAN=$(printf '%s\n' "$TEST_RESULT" | sed 's/\x1b\[[0-9;]*[A-Za-z]//g')
SUMMARY=$(printf '%s\n' "$CLEAN" | grep -E '^[[:space:]]*Tests:[[:space:]]+[0-9]+' | tail -1 || true)

if [ -z "$SUMMARY" ]; then
    echo "BLOCKED: the test suite did not report a result (exit $TEST_RC, no 'Tests: N ...' summary) -- it crashed or never started. NOT counted as a pass." >&2
    printf '%s\n' "$CLEAN" | tail -20 >&2
    exit 2
fi

if [ "$TEST_RC" -ne 0 ]; then
    # Failing tests are the lines that carry the FAILED marker. A known-failure
    # name only excuses a FAILED line -- it used to match ANY line mentioning the
    # name, including PASS lines and the file list, which masked real failures.
    # Pre-existing failures dating from 2026-03 (#31). Not re-verified against
    # develop when this was hardened: if they pass today, delete the list.
    KNOWN_RE='(BookingServiceArea|BookingConfirmation|TenantFeature)'
    FAILING=$(printf '%s\n' "$CLEAN" | grep -E '(^|[[:space:]])(FAILED|⨯)[[:space:]]' || true)
    if [ -z "$FAILING" ]; then
        echo "BLOCKED: the suite exited $TEST_RC but no failing test was identified ('$SUMMARY'). NOT counted as a pass." >&2
        printf '%s\n' "$CLEAN" | tail -20 >&2
        exit 2
    fi
    NEW=$(printf '%s\n' "$FAILING" | grep -Ev "$KNOWN_RE" || true)
    if [ -n "$NEW" ]; then
        echo "BLOCKED: New test failures detected ($SUMMARY)." >&2
        printf '%s\n' "$NEW" >&2
        echo "" >&2
        echo "Fix new test failures before completing." >&2
        exit 2
    fi
    echo "Tests: only known pre-existing failures ($SUMMARY)" >&2
else
    echo "Tests: OK ($SUMMARY)" >&2
fi
echo "Quality gate passed." >&2
exit 0
