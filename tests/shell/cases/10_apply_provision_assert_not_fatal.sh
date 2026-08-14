#!/bin/bash
###############################################################################
# Pins: ci-cd-troubleshooting.md, "6 bugów" point 3 -- `registro:tenant-
# provisioned --assert` returns FAILURE (exit 1, printing exactly
# "not-provisioned") for a perfectly healthy, brand-new stack that simply
# has not been provisioned yet. Treating every non-zero exit as "isolation
# is inconsistent, die" would break EVERY first apply for EVERY tenant.
# Point 4 of the same incident ("VAR=$(cmd) on its own line is NOT a
# condition under set -e") is exercised implicitly here too: the extracted
# block runs under `set -e`, same as the real script, so a regression of
# EITHER bug shows up as this case going red.
#
# Two scenarios (both branches of the same `elif` chain): the benign
# "not-provisioned" text must NOT be fatal; any OTHER non-zero result (a
# real isolation inconsistency) MUST still die().
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "apply.sh: a benign 'not-provisioned' --assert result is not fatal, a real inconsistency still is"
sandbox_init

SNIPPET="$(extract_between_exact "$SCRIPTS_DIR/apply.sh" \
    'log "Checking provisioning status..."' 'fi')"
[ -n "$SNIPPET" ] || fail "could not extract the provisioning-status block from apply.sh -- did its shape change?"

run_snippet() {
    local docker_output="$1" docker_rc="$2" out_prefix="$3"
    fake_exe docker <<EOS
echo "$docker_output"
exit $docker_rc
EOS
    (
        set -euo pipefail
        COMPOSE_ARGS=(-f docker-compose.prod.yml)
        OWNER_EMAIL="" OWNER_NAME="" ORG_NAME=""
        SLUG="acme" INDUSTRY="equipment_rental"
        LOG_FILE="$SANDBOX/log"
        KEEP_MAINTENANCE=false
        log() { echo "$*" >>"$LOG_FILE"; }
        die() { echo "DIE: $*" >"${out_prefix}.die"; exit 9; }
        eval "$SNIPPET"
        echo "no-die" >"${out_prefix}.result"
    )
}

rm -f "$SANDBOX/scenario_a.die" "$SANDBOX/scenario_a.result"
run_snippet "not-provisioned" 1 "$SANDBOX/scenario_a"
[ -f "$SANDBOX/scenario_a.die" ] \
    && fail "scenario A (benign 'not-provisioned', exit 1): die() was called -- $(cat "$SANDBOX/scenario_a.die")"
[ -f "$SANDBOX/scenario_a.result" ] \
    || fail "scenario A: block did not complete (crashed under set -e?)"

rm -f "$SANDBOX/scenario_b.die" "$SANDBOX/scenario_b.result"
run_snippet "tenant isolation error: TENANT_SLUG mismatch" 1 "$SANDBOX/scenario_b"
[ -f "$SANDBOX/scenario_b.die" ] \
    || fail "scenario B (real inconsistency, exit 1, NOT 'not-provisioned'): die() was NOT called -- a genuine isolation problem would have been ignored"

test_finish
