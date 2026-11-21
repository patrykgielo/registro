#!/bin/bash
###############################################################################
# Pins: subagent-quality-gate.sh runs Pint + tests only when the container
# actually mounts the tree that changed -- and says SKIPPED, loudly, otherwise.
#
# THE BUG: the gate ran `docker compose exec app pint/tests` from its own cwd.
# From the Windows app there is no docker at all, so it would never have run.
# Fixed by forwarding it into WSL -- which exposes a worse trap: the `app`
# container bind-mounts ONE tree at /var/www, and this repo is worked in several
# git worktrees. Pint and tests inside the container check the MOUNTED tree's
# files. A change made in another worktree is invisible to them, so a "passing"
# gate there would be a success that describes nothing. It must say SKIPPED.
#
# `docker` is faked (records every call); everything else is real.
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "subagent-quality-gate.sh: gate runs only against the mounted tree, SKIPPED is loud, failures block"

HOOK="$REPO_ROOT/.claude/hooks/subagent-quality-gate.sh"
[ -f "$HOOK" ] || fail "hook not found at $HOOK"
sandbox_init

R="$SANDBOX/main"
W="$SANDBOX/wt"
new_repo "$R" develop
git -C "$R" worktree add -q -b feature/w "$W" >/dev/null 2>&1

fake_exe docker <<'EOS'
case "$1" in
    inspect) printf '%s' "${FAKE_MOUNT:-}" ;;
    compose)
        case "$*" in
            *pint*) exit "${FAKE_PINT_RC:-0}" ;;
            *"artisan test"*) printf '%s\n' "${FAKE_TEST_OUT-  Tests:    3 passed (5 assertions)}"; exit "${FAKE_TEST_RC:-0}" ;;
        esac ;;
esac
exit 0
EOS

run() { # TREE(env + native) [extra env assignments...]
    local tree="$1"
    shift
    printf '%s' '{"stop_hook_active": false}' | env -u REGISTRO_HOOK_ORIGIN -u REGISTRO_HOOK_ASSUME_WINDOWS \
        CLAUDE_PROJECT_DIR="$tree" "$@" bash "$HOOK" >"$SANDBOX/stdout" 2>"$SANDBOX/stderr"
    RC=$?
}
compose_calls() { grep -c '^docker compose ' "$CALL_LOG" || true; }

echo "<?php // new" >"$R/a.php"

# --- 1. mount matches the changed tree: pint, then tests, both run --------------
: >"$CALL_LOG"
run "$R" FAKE_MOUNT="$R"
assert_eq "0" "$RC" "mounted tree, pint+tests pass: exit 0"
assert_eq "2" "$(compose_calls)" "pint AND tests ran"
assert_contains "$(grep '^docker compose ' "$CALL_LOG" | head -1)" "pint" "pint runs first"
assert_contains "$(cat "$SANDBOX/stderr")" "Quality gate passed" "success message only after both ran"

# --- 2. pint failing blocks (exit 2) and tests do not run -----------------------
: >"$CALL_LOG"
run "$R" FAKE_MOUNT="$R" FAKE_PINT_RC=1
assert_eq "2" "$RC" "pint failure blocks with exit 2"
assert_eq "1" "$(compose_calls)" "tests are not run after a pint failure"

# --- 3. new test failures block; known pre-existing ones do not -----------------
NL=$'\n'
SUM1="  Tests:    1 failed, 9 passed (20 assertions)"
run "$R" FAKE_MOUNT="$R" FAKE_TEST_RC=1 FAKE_TEST_OUT="  FAILED  Tests\\Feature\\SomethingNew${NL}${SUM1}"
assert_eq "2" "$RC" "a new failing test blocks"
run "$R" FAKE_MOUNT="$R" FAKE_TEST_RC=1 FAKE_TEST_OUT="  FAILED  Tests\\Feature\\BookingConfirmationTest${NL}${SUM1}"
assert_eq "0" "$RC" "a known pre-existing failure does not"
assert_contains "$(cat "$SANDBOX/stderr")" "only known pre-existing failures" "...and says that is what it let through"

# --- 3b. "Tests: OK" needs POSITIVE evidence the suite ran (code review W10) -----
# A suite that never started prints no FAILED line, so counting FAILED lines let a
# crashed `artisan test` through as OK.
run "$R" FAKE_MOUNT="$R" FAKE_TEST_RC=1 FAKE_TEST_OUT="Error response from daemon: container registro-app is not running"
assert_eq "2" "$RC" "container error (rc 1, no FAILED line, no summary) blocks"
assert_not_contains "$(cat "$SANDBOX/stderr")" "Quality gate passed" "...and never claims the gate passed"
run "$R" FAKE_MOUNT="$R" FAKE_TEST_RC=255 FAKE_TEST_OUT="PHP Fatal error:  Uncaught Error: Class not found in /var/www/artisan:12"
assert_eq "2" "$RC" "a fatal PHP error blocks"
run "$R" FAKE_MOUNT="$R" FAKE_TEST_RC=0 FAKE_TEST_OUT=""
assert_eq "2" "$RC" "rc 0 with no summary line is not evidence of a pass"
run "$R" FAKE_MOUNT="$R" FAKE_TEST_RC=1 FAKE_TEST_OUT="${SUM1}"
assert_eq "2" "$RC" "rc 1 with a summary but no identifiable failing test blocks"

# A known-failure NAME must only excuse a FAILED line -- not a PASS line, and not a
# real failure sitting beside it. The old check counted any line mentioning the name.
run "$R" FAKE_MOUNT="$R" FAKE_TEST_RC=1 \
    FAKE_TEST_OUT="  PASS  Tests\\Feature\\BookingConfirmationSecurityTest${NL}  PASS  Tests\\Feature\\TenantFeatureTest${NL}  FAILED  Tests\\Feature\\RealRegression${NL}${SUM1}"
assert_eq "2" "$RC" "PASS lines naming a known test must not mask a real failure"
run "$R" FAKE_MOUNT="$R" FAKE_TEST_RC=1 \
    FAKE_TEST_OUT="  FAILED  Tests\\Feature\\TenantFeatureTest${NL}  FAILED  Tests\\Feature\\RealRegression${NL}${SUM1}"
assert_eq "2" "$RC" "a known failure beside a real one still blocks"
assert_contains "$(cat "$SANDBOX/stderr")" "RealRegression" "...and names the real one"
assert_not_contains "$(cat "$SANDBOX/stderr")" "TenantFeatureTest" "...and not the excused one"

# --- 4. THE TRAP: changes live in a worktree the container does not mount -------
rm -f "$R/a.php"
echo "<?php // wt" >"$W/b.php"
: >"$CALL_LOG"
printf '%s' '{}' | env -u REGISTRO_HOOK_ASSUME_WINDOWS REGISTRO_HOOK_ORIGIN=windows REGISTRO_WSL_TREE="$R" \
    CLAUDE_PROJECT_DIR="$R" FAKE_MOUNT="$R" bash "$HOOK" >"$SANDBOX/stdout" 2>"$SANDBOX/stderr"
RC=$?
assert_eq "0" "$RC" "unmounted worktree: do not block on a check that cannot see the change"
assert_eq "0" "$(compose_calls)" "...and do NOT run Pint/tests against the wrong files"
assert_contains "$(cat "$SANDBOX/stdout")" "SKIPPED" "the user is told (systemMessage), not left to assume it passed"
assert_contains "$(cat "$SANDBOX/stdout")" "did NOT run" "...in so many words"
assert_not_contains "$(cat "$SANDBOX/stderr")" "Quality gate passed" "no success message for a gate that did not run"

# --- 5. container not running: SKIPPED, not passed ---------------------------------
: >"$CALL_LOG"
printf '%s' '{}' | env -u REGISTRO_HOOK_ASSUME_WINDOWS REGISTRO_HOOK_ORIGIN=windows REGISTRO_WSL_TREE="$R" \
    CLAUDE_PROJECT_DIR="$R" FAKE_MOUNT="" bash "$HOOK" >"$SANDBOX/stdout" 2>"$SANDBOX/stderr"
assert_contains "$(cat "$SANDBOX/stdout")" "SKIPPED" "no mount (container down) is SKIPPED"
assert_eq "0" "$(compose_calls)" "nothing was run"

# --- 6. no php change: nothing to gate; stop_hook_active never blocks --------------
rm -f "$W/b.php"
: >"$CALL_LOG"
run "$R" FAKE_MOUNT="$R"
assert_eq "0" "$RC" "no php changes: exit 0"
assert_eq "0" "$(compose_calls)" "no docker compose call"
echo "<?php // x" >"$R/a.php"
printf '%s' '{"stop_hook_active": true}' | env -u REGISTRO_HOOK_ORIGIN -u REGISTRO_HOOK_ASSUME_WINDOWS \
    CLAUDE_PROJECT_DIR="$R" FAKE_MOUNT="$R" FAKE_PINT_RC=1 bash "$HOOK" >/dev/null 2>&1
assert_eq "0" "$?" "stop_hook_active=true never blocks, even with a failing pint"

test_finish
