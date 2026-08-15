#!/bin/bash
###############################################################################
# Pins: ci-cd-troubleshooting.md "TENANT_PREFIX not in .env" -- the SAME bug
# as case 32, in force_clear_flag()'s copy of the grep instead of the
# `status` action's. Deliberately distinct from case 04
# (04_deploy_force_clear_flag_no_compose.sh), whose .env has a TENANT_PREFIX
# LINE present with an empty value (`TENANT_PREFIX=`) -- grep matches that
# line fine (exit 0), so case 04 never exercised the pipefail/no-match
# interaction this case pins: the key must be ABSENT (no line at all), the
# legacy stack's actual, documented state (deployment.md: "TENANT_PREFIX=
# PUSTE na legacy" describes an empty VALUE, but the real UAT .env that
# surfaced this bug had no line at all).
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "deploy.sh: force_clear_flag() survives .env with no TENANT_PREFIX line at all"
sandbox_init

fake_exe docker <<'EOS'
case "$1" in
    volume) [ "$2" = "inspect" ] && exit 0; exit 1 ;;
    run) exit 0 ;;
esac
exit 0
EOS

mkdir -p "$SANDBOX/legacy"
cat >"$SANDBOX/legacy/.env" <<'ENV'
REDIS_PASSWORD=secret
ENV
APP_DIR="$SANDBOX/legacy"
LOG_FILE="$SANDBOX/log"
log() { echo "$*" >>"$LOG_FILE"; }

FN_SRC="$(extract_between_exact "$SCRIPTS_DIR/deploy.sh" 'force_clear_flag() {' '}')"
[ -n "$FN_SRC" ] || fail "could not extract force_clear_flag() from deploy.sh -- did its shape change?"

# `set -e` matters here: deploy.sh runs under `set -euo pipefail` at the top
# of the file, and that is exactly what turns a failed pipeline substitution
# into a killed script -- the harness itself only runs under `set -uo
# pipefail` (no -e), so calling the extracted function without re-enabling
# -e would silently NOT reproduce the bug this case exists to pin.
#
# The subshell's exit code MUST be captured as a standalone statement, never
# as `(...) || RC=$?`. Proven by two throwaway scripts differing in only
# that one thing: bash's own manual, `set` builtin, -e: "If a compound
# command ... sets -e while executing in a context where -e is ignored,
# that setting will not have any effect until the compound command ...
# completes" -- and the right-hand side of `||` counts as such a context.
# `RC=0; ( set -e; ...; force_clear_flag ) || RC=$?` therefore makes the
# explicit `set -e` INSIDE the subshell a complete no-op: the pipeline
# failure was silently swallowed and the case passed against the unfixed
# source (verified: it did, before this was caught and rewritten). Running
# the subshell as its own statement, with `set -e` never appearing as an
# operand of `||`/`&&`, is what actually lets it kill the subshell on the
# pipeline's pipefail-driven failure.
( set -euo pipefail; eval "$FN_SRC"; force_clear_flag )
RC=$?

CALLS="$(cat "$CALL_LOG")"
assert_eq "0" "$RC" "force_clear_flag() exit code with TENANT_PREFIX absent"
assert_contains "$CALLS" "docker volume inspect registro_storage-framework" "volume name falls back to the 'registro' default when TENANT_PREFIX is absent"

test_finish
