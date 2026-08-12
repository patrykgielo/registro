#!/bin/bash
###############################################################################
# Pins: the SEVERE finding from the second infrastructure review --
# maintenance mode was scoped to the DATABASE block only, so (a) `artisan
# up` ran BEFORE the storage volumes were extracted, and (b) a fully
# successful --restore-live run could serve traffic against a database
# that referenced files still mid-`tar -x`. This test asserts the ACTUAL
# CALL ORDER end-to-end -- not just that maintenance mode was entered at
# some point, which the original (buggy) code also did.
#
# Runs the real tenant-restore.sh end-to-end with fake docker/restic on
# PATH -- no extraction, this is exactly the property "does the sequence
# of real commands landing on live infrastructure look right", which only
# a full run can prove.
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "tenant-restore.sh: --restore-live wraps BOTH phases in one maintenance window, in order"
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

fake_exe docker <<'EOS'
case "$*" in
    *"-N -e"*) echo t1; echo t2; exit 0 ;;
    *"exec -T mysql"*) exit 0 ;;
    *"volume inspect"*) exit 0 ;;
    *"find /d -mindepth 1"*) echo "/d/somefile"; exit 0 ;;
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

assert_eq "0" "$RC" "exit code (fully successful live restore)"
assert_not_contains "$OUT" "usage:" "output must not be a usage error"

DOWN_LINE="$(grep -n 'artisan down' "$CALL_LOG" | head -1 | cut -d: -f1)"
DB_LOAD_LINE="$(grep -n -- '-uroot' "$CALL_LOG" | grep -v -- '-N -e' | head -1 | cut -d: -f1)"
FILES_LINE="$(grep -n -- '--user 0:0' "$CALL_LOG" | head -1 | cut -d: -f1)"
UP_LINE="$(grep -n 'artisan up' "$CALL_LOG" | head -1 | cut -d: -f1)"

[ -n "$DOWN_LINE" ] || fail "artisan down was never invoked"
[ -n "$DB_LOAD_LINE" ] || fail "mysql load was never invoked"
[ -n "$FILES_LINE" ] || fail "file extraction (docker run --user 0:0) was never invoked"
[ -n "$UP_LINE" ] || fail "artisan up was never invoked"

if [ -n "$DOWN_LINE" ] && [ -n "$DB_LOAD_LINE" ] && [ "$DOWN_LINE" -ge "$DB_LOAD_LINE" ]; then
    fail "artisan down (line ${DOWN_LINE}) did not happen BEFORE the database load (line ${DB_LOAD_LINE})"
fi
if [ -n "$DB_LOAD_LINE" ] && [ -n "$FILES_LINE" ] && [ "$DB_LOAD_LINE" -ge "$FILES_LINE" ]; then
    fail "database load (line ${DB_LOAD_LINE}) did not happen BEFORE file extraction (line ${FILES_LINE})"
fi
if [ -n "$FILES_LINE" ] && [ -n "$UP_LINE" ] && [ "$FILES_LINE" -ge "$UP_LINE" ]; then
    fail "file extraction (line ${FILES_LINE}) did not happen BEFORE artisan up (line ${UP_LINE}) -- the app could serve traffic against a database referencing files still mid-extraction"
fi

STATUS_LINE="$(cat "$STACKS_ROOT/.state/acme/apply-status" 2>/dev/null || true)"
assert_contains "$STATUS_LINE" "OK vTEST" "apply-status after a fully successful live restore"

test_finish
