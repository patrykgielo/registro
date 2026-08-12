#!/bin/bash
###############################################################################
# Pins: "nothing gates the file phase on the DB phase failing" -- a failed
# database load logged "app left in maintenance mode... fix manually" and
# then fell straight through into extracting files into the LIVE volumes
# anyway, against a database that was NOT successfully restored. Also
# pins the STATE_DIR/apply-status write on failure (tenant-check.sh's own
# ground truth) and that artisan up is never reached.
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "tenant-restore.sh: a failed database load aborts before touching files, leaves maintenance mode, writes FAILED status"
sandbox_init

fake_exe restic <<'EOS'
case "$*" in
    snapshots*) exit 0 ;;
    "ls "*)
        echo "/tmp/acme-backup-AbCdEf.sql"
        echo "/tmp/acme-backup-files-XyZ123/storage-app-public"
        echo "/tmp/acme-backup-files-XyZ123/storage-app-private"
        exit 0
        ;;
    "dump "*"--archive tar")
        echo "fake-tar-bytes"
        exit 0
        ;;
    "dump "*)
        echo "-- fake dump"
        echo "-- Dump completed on 2026-01-01"
        exit 0
        ;;
esac
exit 0
EOS

# The mysql LOAD (a non-interactive `mysql -uroot ... registro <dump`) fails;
# everything else (artisan down/up, volume ops) would succeed if reached --
# so a passing test here proves the abort, not a coincidentally-failing
# fake.
fake_exe docker <<'EOS'
case "$*" in
    *"-N -e"*) echo t1; exit 0 ;;
    *"exec -T mysql"*) exit 1 ;;
    *) exit 0 ;;
esac
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
REGISTRO_VERSION=vTEST
EOS
mkdir -p "$SANDBOX/backups/acme"
: >"$SANDBOX/backups/acme/password"

OUT="$(REGISTRO_STACKS_ROOT="$STACKS_ROOT" \
    REGISTRO_BACKUP_ROOT="$SANDBOX/backups" \
    bash "$SCRIPTS_DIR/tenant-restore.sh" acme latest --restore-live --confirm-slug acme 2>&1)"
RC=$?

assert_eq "3" "$RC" "exit code (restore failed)"
assert_contains "$OUT" "loading dump into live" "output must explain the DB load failure"
assert_contains "$(cat "$CALL_LOG")" "artisan down" "artisan down must still have run (maintenance mode entered)"
assert_not_contains "$(cat "$CALL_LOG")" "--user 0:0" "file extraction must NEVER run after the database load failed"
assert_not_contains "$(cat "$CALL_LOG")" "artisan up" "artisan up must never run -- the app must stay in maintenance mode"

STATUS_LINE="$(cat "$STACKS_ROOT/.state/acme/apply-status" 2>/dev/null || true)"
assert_contains "$STATUS_LINE" "FAILED vTEST" "apply-status must read FAILED, not a stale OK from a previous apply"

test_finish
