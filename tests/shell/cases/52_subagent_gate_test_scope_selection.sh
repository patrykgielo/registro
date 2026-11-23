#!/bin/bash
###############################################################################
# Pins: subagent-quality-gate.sh picks WHICH tests to run, and says so.
#
# THE PROBLEM: the gate ran the whole Unit+Feature suite sequentially (~600 s)
# under a 600 s hook timeout -- it exceeded its own limit, so the check that was
# supposed to protect every change could not finish. 9 classes (the product
# form ServiceForm*, PanelWalkthroughTest, CatalogImportFormParityTest) are
# tagged #[Group('slow')] and matter only when the code they exercise changes.
#
# THE RULE: always `--parallel`; default `--exclude-group=slow`; the full run
# when a changed path matches SLOW_TRIGGERS_RE; REGISTRO_TESTS=full|fast forces
# either; any other value is ignored WITH a warning (never a silent fall-through
# that looks like the override worked). Every run prints the scope and why --
# a pass of the fast scope must never read as a pass of the group it left out.
#
# ALSO PINNED (review round): "changed" for scope selection includes what the
# branch committed since origin/develop; a non-ASCII path (git quotes it by
# default) still matches; and a run that exceeds the gate's own time budget ends
# as an unmistakable NOT VERIFIED -- never as silence, never as a pass -- because
# Claude Code reads a hook it had to kill (settings.json: 600 s) as "no decision".
#
# `docker` is faked (records every call, returns canned test output); the gate,
# lib/tree.sh and git are real.
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "subagent-quality-gate.sh: --parallel always; slow group excluded unless a trigger path changed or REGISTRO_TESTS=full"

HOOK="$REPO_ROOT/.claude/hooks/subagent-quality-gate.sh"
[ -f "$HOOK" ] || fail "hook not found at $HOOK"
sandbox_init

R="$SANDBOX/repo"
new_repo "$R" develop

fake_exe docker <<'EOS'
case "$1" in
    inspect) printf '%s' "${FAKE_MOUNT:-}" ;;
    compose)
        case "$*" in
            # `exec sleep`: the sleeper must BE this process, or `timeout` kills the
            # shell and an orphaned sleep keeps the capture pipe open until it ends.
            *pint*) [ -n "${FAKE_PINT_SLEEP:-}" ] && exec sleep "$FAKE_PINT_SLEEP"; exit "${FAKE_PINT_RC:-0}" ;;
            *"artisan test"*)
                [ -n "${FAKE_TEST_SLEEP:-}" ] && { echo "  .....partial progress"; exec sleep "$FAKE_TEST_SLEEP"; }
                printf '%s\n' "${FAKE_TEST_OUT-  Tests:    3 passed (5 assertions)}"; exit "${FAKE_TEST_RC:-0}" ;;
        esac ;;
esac
exit 0
EOS

# run [VAR=value ...] -- the gate against $R, REGISTRO_TESTS cleared unless given.
run() {
    printf '%s' '{"stop_hook_active": false}' | env -u REGISTRO_HOOK_ORIGIN -u REGISTRO_HOOK_ASSUME_WINDOWS -u REGISTRO_TESTS \
        CLAUDE_PROJECT_DIR="$R" FAKE_MOUNT="$R" "$@" bash "$HOOK" >"$SANDBOX/stdout" 2>"$SANDBOX/stderr"
    RC=$?
}
test_call() { grep 'artisan test' "$CALL_LOG" | tail -1; }
err() { cat "$SANDBOX/stderr"; }

# changed FILE... -- the working tree has exactly these (untracked) files.
changed() {
    git -C "$R" clean -fdq
    local f
    for f in "$@"; do
        mkdir -p "$R/$(dirname "$f")"
        echo "<?php // $f" >"$R/$f"
    done
    : >"$CALL_LOG"
}

# --- 1. default: parallel, slow group excluded, and it says why ------------------
changed app/Http/Controllers/HomeController.php
run
assert_eq "0" "$RC" "default scope passes"
assert_contains "$(test_call)" "artisan test --parallel --exclude-group=slow" "default = parallel + exclude slow"
assert_contains "$(err)" "fast" "stderr names the fast scope"
assert_contains "$(err)" "no changed path touches" "...and why"
assert_contains "$(err)" "Tests: OK, fast" "the pass line names the scope that passed"

# --- 2. a trigger path -> the full run (parallel, nothing excluded) --------------
changed app/Filament/Resources/ServiceResource.php
run
assert_eq "0" "$RC" "full scope passes"
assert_contains "$(test_call)" "artisan test --parallel" "full is still parallel"
assert_not_contains "$(test_call)" "exclude-group" "...and excludes nothing"
assert_contains "$(err)" "full" "stderr names the full scope"
assert_contains "$(err)" "app/Filament/Resources/ServiceResource.php" "...and the changed file that caused it"
assert_contains "$(err)" "Tests: OK, full" "the pass line names the scope that passed"

# Every entry of SLOW_TRIGGERS_RE selects the full run.
for f in app/Support/Catalog/CatalogProductValidator.php app/Actions/Catalog/ImportCatalog.php \
    app/Models/Service.php app/Models/ServiceArea.php app/Models/Order.php app/Policies/ServicePolicy.php \
    app/Providers/Filament/AdminPanelProvider.php app/Actions/Inventory/ReserveStock.php \
    app/Actions/Onboarding/CreateTenant.php \
    resources/views/components/ios/service-card.blade.php resources/views/filament/pages/x.blade.php \
    database/migrations/2026_01_01_000000_x.php database/factories/ServiceFactory.php \
    tests/Feature/Filament/ServiceFormTabsTest.php tests/Feature/Catalog/CatalogImportFormParityTest.php; do
    changed "$f"
    run
    assert_not_contains "$(test_call)" "exclude-group" "trigger $f selects the full run"
done

# --- 2b. near misses and non-anchored paths do NOT trigger -----------------------
# (app/Models/ is a whole-directory trigger now, so ServiceArea / Services are
# positives above; the near misses are the look-alike DIRECTORIES.)
for f in app/Modelsx/Foo.php docs/app/Models/Foo.php app/PoliciesOld/Foo.php app/Providers/AppServiceProvider.php \
    app/Actions/Booking/Foo.php app/Actions/Inventoryx/Foo.php resources/views/components/ui/button.blade.php \
    resources/views/components/ios-x/foo.blade.php resources/views/filament-x/foo.blade.php \
    resources/views/home.blade.php database/seeders/Foo.php database/migrationsx/Foo.php \
    docs/app/Filament/Foo.php app/Http/Controllers/Filament.php tests/Feature/Cart/CartTest.php lang/pl/admin.php; do
    changed "$f" app/Foo.php
    run
    assert_contains "$(test_call)" "--exclude-group=slow" "$f is not a trigger"
done

# --- 2c. the trigger is looked up among MANY changed files, not just the first ---
changed app/Foo.php app/Http/A.php app/Zzz/B.php app/Filament/Pages/X.php
run
assert_not_contains "$(test_call)" "exclude-group" "one trigger among several changed files is enough"

# --- 3. REGISTRO_TESTS forces either scope, over the automatic choice ------------
changed app/Foo.php
run REGISTRO_TESTS=full
assert_not_contains "$(test_call)" "exclude-group" "REGISTRO_TESTS=full forces the full run"
assert_contains "$(err)" "REGISTRO_TESTS=full" "...and names the override as the reason"
changed app/Filament/Pages/X.php
run REGISTRO_TESTS=fast
assert_contains "$(test_call)" "artisan test --parallel --exclude-group=slow" "REGISTRO_TESTS=fast forces the fast run even when a trigger changed"
assert_contains "$(err)" "REGISTRO_TESTS=fast" "...and names the override as the reason"
assert_not_contains "$(err)" "WARNING" "a valid override is not a warning"

# --- 4. a bogus value is ignored LOUDLY, the automatic scope still applies -------
changed app/Foo.php
run REGISTRO_TESTS=banana
assert_eq "0" "$RC" "a bogus REGISTRO_TESTS does not block"
assert_contains "$(err)" "WARNING: ignoring REGISTRO_TESTS='banana'" "bogus value warns, naming it"
assert_contains "$(test_call)" "--exclude-group=slow" "...and the default (fast) scope is used"
changed app/Filament/Pages/X.php
run REGISTRO_TESTS=FULL
assert_contains "$(err)" "WARNING: ignoring REGISTRO_TESTS='FULL'" "values are case-sensitive, a near-miss warns"
assert_not_contains "$(test_call)" "exclude-group" "...and the automatic scope (full here) is used"

# empty / unset is "no override", with no warning
changed app/Foo.php
run REGISTRO_TESTS=
assert_not_contains "$(err)" "WARNING" "an empty REGISTRO_TESTS (what WSLENV forwards when unset) is silent"

# --- 5. REAL parallel output (measured with `artisan test --parallel`) -----------
# Progress is dots (no per-test names); failures are `FAILED  Tests\...` blocks;
# the summary is `Tests: ...` followed by `Parallel: N processes`. Colour codes
# and a cursor-up escape surround them.
NL=$'\n'
ESC=$'\033'
PAR_OK="  ${ESC}[90;1m.${ESC}[39;22m${ESC}[90;1m.${ESC}[39;22m${NL}${NL}  ${ESC}[90mTests:${ESC}[39m    ${ESC}[33;1m12 skipped${ESC}[39;22m${ESC}[90m,${ESC}[39m ${ESC}[32;1m2799 passed${ESC}[39;22m${ESC}[90m (10698 assertions)${ESC}[39m${NL}  ${ESC}[90mDuration:${ESC}[39m 302.94s${NL}${NL}${ESC}[1A  ${ESC}[90mParallel:${ESC}[39m 8 processes"
changed app/Foo.php
run FAKE_TEST_OUT="$PAR_OK"
assert_eq "0" "$RC" "coloured paratest output with a Tests: summary is a pass"
assert_contains "$(err)" "Tests: OK" "...and is reported as one"

PAR_FAIL_HEAD="  .⨯${NL}  ────────────────────────────────────────────────────────────────────────────  ${NL}   FAILED  Tests\\Feature"
PAR_SUM="  Tests:    1 failed, 1 passed (2 assertions)${NL}  Duration: 0.42s${NL}${NL}  Parallel: 2 processes"
run FAKE_TEST_RC=1 FAKE_TEST_OUT="${PAR_FAIL_HEAD}\\SomethingNewTest > it works${NL}  Failed asserting that false is true.${NL}${NL}${PAR_SUM}"
assert_eq "2" "$RC" "a new failure in paratest output blocks"
assert_contains "$(err)" "SomethingNewTest" "...and names it"
run FAKE_TEST_RC=1 FAKE_TEST_OUT="${PAR_FAIL_HEAD}\\BookingConfirmationTest > it works${NL}  Failed asserting that false is true.${NL}${NL}${PAR_SUM}"
assert_eq "0" "$RC" "a known pre-existing failure in paratest output (progress line carries a bare ⨯) is excused"
assert_contains "$(err)" "only known pre-existing failures, fast" "...and it says which scope that was"

# --- 6. scope selection sees what the BRANCH committed, not only the working tree --
# An agent that commits before it stops leaves a clean tree; the fast scope would
# then skip the very classes its commit touches. The "is there anything to check /
# any PHP changed" decision stays on the working tree (6c).
git -C "$R" checkout -q -b feature/branch-scope
mkdir -p "$R/app/Filament/Pages"
echo '<?php // committed' >"$R/app/Filament/Pages/Committed.php"
git -C "$R" add -A
git -C "$R" commit -qm "branch work"

# 6a. no origin/develop at all: silent fallback to the working-tree set (fast), no warning
changed app/Http/Foo.php
run
assert_eq "0" "$RC" "no origin/develop: the gate still runs"
assert_contains "$(test_call)" "--exclude-group=slow" "no origin/develop: falls back to the working-tree set (fast)"
assert_not_contains "$(err)" "WARNING" "...silently, not with a warning"

# 6b. origin/develop resolves: the branch's committed trigger file selects the full run
git -C "$R" update-ref refs/remotes/origin/develop "$(git -C "$R" rev-parse develop)"
changed app/Http/Foo.php
run
assert_not_contains "$(test_call)" "exclude-group" "a trigger path COMMITTED on the branch selects the full run"
assert_contains "$(err)" "app/Filament/Pages/Committed.php" "...and the stderr reason names that committed file"

# 6c. nothing in the working tree: the old skip stands (it is not the scope's business)
changed
run
assert_eq "0" "$RC" "clean working tree on a branch with commits: still exits 0"
assert_eq "" "$(test_call)" "...and runs no tests (the 'any PHP changed' skip is untouched)"
assert_contains "$(err)" "No changes" "...and says why"

# 6d. a branch whose commits touch no trigger path stays fast
git -C "$R" checkout -q -B feature/plain develop
mkdir -p "$R/app/Http"
echo '<?php // plain' >"$R/app/Http/Plain.php"
git -C "$R" add -A
git -C "$R" commit -qm "plain work"
changed app/Foo.php
run
assert_contains "$(test_call)" "--exclude-group=slow" "branch commits with no trigger path stay in the fast scope"

# --- 7. non-ASCII paths: git quotes them unless core.quotePath=false ---------------
# A quoted path starts with `"` and misses every ^-anchor; an unquoted-PHP check
# (`\.php$`) misses it too, so the gate used to see "no PHP changed" and skip.
changed "app/Filament/Pages/Zażółć.php"
run
assert_not_contains "$(test_call)" "exclude-group" "a non-ASCII path under a trigger directory selects the full run"
assert_contains "$(err)" "Zażółć.php" "...and is named unquoted in the reason"
changed "app/Http/Łódź.php"
run
assert_contains "$(test_call)" "artisan test" "a changed non-ASCII PHP file is seen as PHP (the gate does not skip)"
assert_contains "$(test_call)" "--exclude-group=slow" "...and outside the triggers stays fast"

# --- 8. the gate ends ITSELF before the hook is killed ------------------------------
# settings.json gives the hook 600 s; a killed hook is "no decision" = a silent
# pass. REGISTRO_GATE_BUDGET_S shrinks the 540 s budget so this can be run for real.
not_a_pass() {
    assert_contains "$(cat "$SANDBOX/stdout")" '"systemMessage"' "$1: the user is told (systemMessage), not left with silence"
    assert_contains "$(cat "$SANDBOX/stdout")" "NOT VERIFIED" "$1: stdout says NOT VERIFIED"
    assert_contains "$(err)" "NOT VERIFIED" "$1: stderr says NOT VERIFIED"
    assert_contains "$(err)" "NOT a pass" "$1: ...and that it is not a pass"
    assert_not_contains "$(err)" "Quality gate passed" "$1: no pass line"
    assert_not_contains "$(err)" "Tests: OK" "$1: no 'Tests: OK' line"
}

changed app/Foo.php
T0=$SECONDS
run REGISTRO_GATE_BUDGET_S=4 FAKE_TEST_SLEEP=60
ELAPSED=$((SECONDS - T0))
assert_eq "0" "$RC" "tests over budget: exit 0 (not a block -- the agent cannot fix a timeout)"
not_a_pass "tests over budget"
assert_contains "$(err)" "tests exceeded" "tests over budget: names what exceeded"
assert_contains "$(err)" "partial progress" "tests over budget: shows the output captured so far"
assert_eq "fast" "$([ "$ELAPSED" -lt 30 ] && echo fast || echo "slow (${ELAPSED}s)")" \
    "the gate stopped at its budget, not after the 60s the fake test run wanted (took ${ELAPSED}s)"

changed app/Foo.php
run REGISTRO_GATE_BUDGET_S=3 FAKE_PINT_SLEEP=60
assert_eq "0" "$RC" "Pint over budget: exit 0"
not_a_pass "Pint over budget"
assert_contains "$(err)" "Pint did not finish" "Pint over budget: names Pint"
assert_eq "" "$(test_call)" "Pint over budget: the tests were not started on top of it"

# A 137 that is NOT the time limit (e.g. an OOM kill) is a failure to investigate, not a timeout.
changed app/Foo.php
run FAKE_TEST_RC=137 FAKE_TEST_OUT="  Killed"
assert_eq "2" "$RC" "exit 137 well within the budget is a BLOCK, not NOT VERIFIED"
assert_not_contains "$(err)" "NOT VERIFIED" "...it is not mislabelled as a timeout"

# A garbage budget is ignored, the default applies, and a normal run still passes.
changed app/Foo.php
run REGISTRO_GATE_BUDGET_S=banana
assert_eq "0" "$RC" "a non-numeric REGISTRO_GATE_BUDGET_S is ignored"
assert_contains "$(err)" "Tests: OK" "...and the run completes normally"

test_finish
