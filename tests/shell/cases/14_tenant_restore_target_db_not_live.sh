#!/bin/bash
###############################################################################
# Pins: tenant-restore.sh's second safety property -- the default,
# non-live restore mode refuses a --target-db equal to the tenant's live
# DB_DATABASE. Without this guard, an operator who mistypes --target-db
# would silently get exactly what --restore-live is for, minus the
# --confirm-slug safety net that mode requires.
#
# Runs the real script end-to-end with fake docker/restic/mysql -- `restic
# snapshots` (the repository precondition check) must succeed for this
# guard to even be reached, so it is faked to exit 0. The guard must fire
# BEFORE `restic ls`/`restic dump` -- asserted on the call log, not just
# the exit code, so a regression that moved the check past those calls is
# still caught even if the exit code happened to stay right.
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "tenant-restore.sh: --target-db refuses to equal the live database"
sandbox_init

fake_exe docker <<'EOS'
exit 0
EOS
fake_exe restic <<'EOS'
case "$1" in
    snapshots) exit 0 ;;
esac
exit 0
EOS

STACKS_ROOT="$SANDBOX/stacks"
STACK_DIR="$STACKS_ROOT/acme"
mkdir -p "$STACK_DIR"
cat >"$STACK_DIR/.env.secrets" <<'EOS'
APP_KEY='base64:test'
DB_ROOT_PASSWORD='rootpw'
DB_PASSWORD='pw'
REDIS_PASSWORD='redispw'
EOS
cat >"$STACK_DIR/.env" <<'EOS'
DB_DATABASE=registro
EOS
mkdir -p "$SANDBOX/backups/acme"
: >"$SANDBOX/backups/acme/password"

OUT="$(REGISTRO_STACKS_ROOT="$STACKS_ROOT" \
    REGISTRO_BACKUP_ROOT="$SANDBOX/backups" \
    bash "$SCRIPTS_DIR/tenant-restore.sh" acme --target-db registro 2>&1)"
RC=$?

assert_eq "2" "$RC" "exit code"
assert_contains "$OUT" "must not be the tenant's live database" "error message"
assert_contains "$(cat "$CALL_LOG")" "restic snapshots" "restic snapshots (the precondition check) must have run"
assert_not_contains "$(cat "$CALL_LOG")" "restic ls" "restic ls must never run -- the guard must fire first"
assert_not_contains "$(cat "$CALL_LOG")" "restic dump" "restic dump must never run -- the guard must fire first"

# A --target-db that genuinely differs from the live database must NOT be
# rejected by this guard.
OUT_OK="$(REGISTRO_STACKS_ROOT="$STACKS_ROOT" \
    REGISTRO_BACKUP_ROOT="$SANDBOX/backups" \
    bash "$SCRIPTS_DIR/tenant-restore.sh" acme --target-db registro_scratch 2>&1)"
assert_not_contains "$OUT_OK" "must not be the tenant's live database" "error message (distinct --target-db must not trip this guard)"

test_finish
