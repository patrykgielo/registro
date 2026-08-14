#!/bin/bash
###############################################################################
# Pins: tenant-restore.sh's core safety property -- --restore-live must
# refuse to run at all unless --confirm-slug repeats the exact slug typed
# as the first argument. Guards against a pasted/looped command whose first
# argument silently changed doing the destructive thing to the wrong
# tenant.
#
# Runs the real tenant-restore.sh end-to-end (short guard, fires before any
# docker/restic/mysql call) rather than extracting a fragment. Fakes
# docker/restic anyway so a regression that moved the guard PAST the point
# docker/restic get touched is caught by the empty call log, not just by
# the exit code.
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "tenant-restore.sh: --restore-live refuses without a matching --confirm-slug"
sandbox_init

fake_exe docker <<'EOS'
exit 0
EOS
fake_exe restic <<'EOS'
exit 0
EOS

STACKS_ROOT="$SANDBOX/stacks"
mkdir -p "$STACKS_ROOT/acme"

run_restore() {
    REGISTRO_STACKS_ROOT="$STACKS_ROOT" \
        REGISTRO_BACKUP_ROOT="$SANDBOX/backups" \
        bash "$SCRIPTS_DIR/tenant-restore.sh" "$@" 2>&1
}

OUT_NO_CONFIRM="$(run_restore acme --restore-live)"
RC_NO_CONFIRM=$?
assert_eq "2" "$RC_NO_CONFIRM" "exit code with --restore-live and no --confirm-slug"
assert_contains "$OUT_NO_CONFIRM" "requires --confirm-slug acme" "error message (no confirm)"

OUT_WRONG_CONFIRM="$(run_restore acme --restore-live --confirm-slug wrong-slug)"
RC_WRONG_CONFIRM=$?
assert_eq "2" "$RC_WRONG_CONFIRM" "exit code with a --confirm-slug that does not match"
assert_contains "$OUT_WRONG_CONFIRM" "requires --confirm-slug acme" "error message (wrong confirm)"

# Neither refusal may have touched docker or restic -- the guard is the
# very first thing that runs for --restore-live, before STACK_DIR is even
# checked for existence.
assert_eq "" "$(cat "$CALL_LOG")" "docker/restic invocations (must be none)"

OUT_RIGHT_CONFIRM="$(run_restore acme --restore-live --confirm-slug acme)"
# A correct --confirm-slug must NOT be rejected by this guard -- it should
# fail later, on a DIFFERENT precondition (.env.secrets missing), proving
# the confirm-slug check itself passed.
assert_not_contains "$OUT_RIGHT_CONFIRM" "requires --confirm-slug" "error message (matching confirm must not trip this guard)"

test_finish
