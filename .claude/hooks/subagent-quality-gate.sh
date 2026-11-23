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

# --- Time budget --------------------------------------------------------------
# settings.json gives this hook 600 s, and Claude Code treats a hook it had to
# kill as "no decision" -- a PASS, silently. So the gate must stop itself first,
# and say so. ONE knob: the total seconds for Pint + tests together, kept below
# that limit (the rest is the kill grace and the WSL shim). Pint takes what it
# needs out of it; the tests get what is left. REGISTRO_GATE_BUDGET_S overrides
# it (tests of the gate itself use a few seconds); a non-number is ignored.
GATE_BUDGET_S=540
case "${REGISTRO_GATE_BUDGET_S:-}" in
    ""|*[!0-9]*) ;;
    *) GATE_BUDGET_S="$REGISTRO_GATE_BUDGET_S" ;;
esac

# --- Which tests run ---------------------------------------------------------
# The full Unit+Feature suite takes ~300 s even with --parallel (~600 s without),
# and 9 classes tagged #[Group('slow')] (the product form ServiceForm*,
# PanelWalkthroughTest, CatalogImportFormParityTest) are a large part of it.
# They only matter when the code they exercise changes, so the default run
# leaves them out (`--exclude-group=slow`) and the FULL run is chosen when a
# changed path matches this list. Keep it explicit; add a path here when a slow
# class starts covering it. Paths are git-relative (ERE, anchored). "Changed"
# includes what the branch has already committed since origin/develop
# (lib/tree.sh), not only the working tree.
#   app/Filament/                  Filament resources/pages -- the product form lives here
#   app/Models/                    PanelWalkthroughTest touches ~25 models; the form edits Service
#   app/Policies/                  the walkthrough exercises every resource's authorisation
#   app/Providers/Filament/        panel registration
#   app/Support/Catalog/           catalog importer building blocks (CatalogProductValidator, ...)
#   app/Actions/Catalog/           ImportCatalog / RevertCatalogImport
#   app/Actions/Inventory/         inventory actions the form / walkthrough run
#   app/Actions/Onboarding/        onboarding actions the walkthrough sets up through
#   resources/views/components/ios/  service-card, rendered by the form's tile preview
#   resources/views/filament/      Filament view overrides
#   database/migrations/           schema the slow classes build
#   database/factories/            the state the slow classes create
#   tests/Feature/Filament/        the slow classes themselves
#   tests/Feature/Catalog/         CatalogImportFormParityTest
# lang/** is deliberately NOT here: the Localisation parity tests that guard it
# are not slow, so they run in the default scope anyway.
# Override per run: REGISTRO_TESTS=full | fast (anything else is ignored, loudly).
SLOW_TRIGGERS_RE='^(app/Filament/|app/Models/|app/Policies/|app/Providers/Filament/|app/Support/Catalog/|app/Actions/(Catalog|Inventory|Onboarding)/|resources/views/(components/ios|filament)/|database/(migrations|factories)/|tests/Feature/(Filament|Catalog)/)'

skipped() {
    printf '{"systemMessage":"subagent-quality-gate: SKIPPED -- %s. Pint and tests did NOT run for this change."}\n' "$(hook_json_escape "$1")"
    echo "subagent-quality-gate: SKIPPED -- $1" >&2
    exit 0
}

# not_verified MESSAGE -- the check could not reach a verdict. Same exit as
# skipped() (0 + a systemMessage the user sees) but a different word: skipped()
# means "did not run", this means "ran and did not finish". It is deliberately
# not a block: the agent cannot fix a timeout, a second stop passes silently
# anyway (stop_hook_active), and blocking would only buy another full run. What
# matters is that it can never read as a pass.
not_verified() {
    local msg="NOT VERIFIED -- $1. This is NOT a pass: the change was not checked."
    printf '{"systemMessage":"subagent-quality-gate: %s"}\n' "$(hook_json_escape "$msg")"
    echo "subagent-quality-gate: $msg" >&2
    exit 0
}

# run_limited SECONDS CMD... -- CMD in $TREE under `timeout` (TERM at the limit,
# KILL 5 s later), stdin closed. Sets LIMITED_OUT (stdout+stderr), LIMITED_RC and
# LIMITED_TIMED_OUT=1 only when the TIME LIMIT ended it: 124, or 137 after the
# limit had elapsed -- a 137 that arrives early is something else (an OOM kill)
# and stays an ordinary failure. Not a $(...) function: it sets variables.
LIMITED_OUT="" LIMITED_RC=0 LIMITED_TIMED_OUT=0
run_limited() {
    local limit="$1" started="$SECONDS"
    shift
    # `timeout 0` means NO limit -- the exact failure this exists to prevent.
    [ "$limit" -ge 1 ] || limit=1
    LIMITED_RC=0
    LIMITED_TIMED_OUT=0
    LIMITED_OUT="$(cd "$TREE" && timeout -k 5 "$limit" "$@" </dev/null 2>&1)" || LIMITED_RC=$?
    if [ "$LIMITED_RC" -eq 124 ] || { [ "$LIMITED_RC" -eq 137 ] && [ $((SECONDS - started)) -ge "$limit" ]; }; then
        LIMITED_TIMED_OUT=1
    fi
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
command -v timeout >/dev/null 2>&1 || skipped "timeout (coreutils) is not available, so the gate cannot bound the test run"

MOUNT="$(docker inspect "$REGISTRO_APP_CONTAINER" --format '{{range .Mounts}}{{if eq .Destination "/var/www"}}{{.Source}}{{end}}{{end}}' 2>/dev/null || true)"
[ -n "$MOUNT" ] || skipped "container '$REGISTRO_APP_CONTAINER' is not running or has no /var/www mount"

if [ "$(cd "$TREE" && pwd -P)" != "$(cd "$MOUNT" 2>/dev/null && pwd -P)" ]; then
    skipped "the changes are in $TREE but container '$REGISTRO_APP_CONTAINER' mounts $MOUNT, so Pint/tests inside it would check different files"
fi

echo "Quality gate on $TREE" >&2

# Run Pint check
echo "Running Pint style check..." >&2
run_limited "$((GATE_BUDGET_S - SECONDS))" docker compose exec -T app ./vendor/bin/pint --test
if [ "$LIMITED_TIMED_OUT" -eq 1 ]; then
    not_verified "Pint did not finish within the ${GATE_BUDGET_S} s budget and was killed"
fi
if [ "$LIMITED_RC" -ne 0 ]; then
    echo "BLOCKED: Pint style check failed." >&2
    echo "$LIMITED_OUT" | tail -20 >&2
    echo "" >&2
    echo "Fix style issues before completing." >&2
    exit 2
fi

echo "Pint: OK" >&2

# Pick the test scope (see SLOW_TRIGGERS_RE at the top), and say which and why.
TEST_ARGS=(--parallel --exclude-group=slow)
SCOPE="fast (excluding group 'slow')"
SLOW_HIT="$(printf '%s\n' "${REGISTRO_ACTIVE_SCOPE_CHANGES:-$REGISTRO_ACTIVE_CHANGES}" | grep -E "$SLOW_TRIGGERS_RE" | head -3 | paste -sd, - || true)"
if [ -n "$SLOW_HIT" ]; then
    TEST_ARGS=(--parallel)
    SCOPE="full (including group 'slow')"
    SCOPE_WHY="changed: $SLOW_HIT"
else
    SCOPE_WHY="no changed path touches an area the slow classes exercise"
fi
case "${REGISTRO_TESTS:-}" in
    "") ;;
    full)
        TEST_ARGS=(--parallel)
        SCOPE="full (including group 'slow')"
        SCOPE_WHY="REGISTRO_TESTS=full"
        ;;
    fast)
        TEST_ARGS=(--parallel --exclude-group=slow)
        SCOPE="fast (excluding group 'slow')"
        SCOPE_WHY="REGISTRO_TESTS=fast"
        ;;
    *)
        echo "subagent-quality-gate: WARNING: ignoring REGISTRO_TESTS='${REGISTRO_TESTS}' (expected 'full' or 'fast'); using the automatic scope." >&2
        ;;
esac

# Run tests
echo "Running PHPUnit tests: $SCOPE -- $SCOPE_WHY" >&2
TEST_LIMIT=$((GATE_BUDGET_S - SECONDS))
if [ "$TEST_LIMIT" -lt 1 ]; then
    not_verified "Pint used up the whole ${GATE_BUDGET_S} s budget, so the tests were not started"
fi
run_limited "$TEST_LIMIT" docker compose exec -T app php artisan test "${TEST_ARGS[@]}"
TEST_RC="$LIMITED_RC"
TEST_RESULT="$LIMITED_OUT"
if [ "$LIMITED_TIMED_OUT" -eq 1 ]; then
    # Killing the docker client does not stop the process it started inside the
    # container, so the suite may still be running there.
    printf '%s\n' "$TEST_RESULT" | sed 's/\x1b\[[0-9;]*[A-Za-z]//g' | tail -5 >&2
    not_verified "tests exceeded ${TEST_LIMIT} s (scope: ${SCOPE}) and were killed; the result is unknown, and the run may still be going inside the container"
fi

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
    echo "Tests: only known pre-existing failures, $SCOPE ($SUMMARY)" >&2
else
    # Name the scope: a pass of the fast scope is not a pass of the group it left out.
    echo "Tests: OK, $SCOPE ($SUMMARY)" >&2
fi
echo "Quality gate passed." >&2
exit 0
