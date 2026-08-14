#!/bin/bash
###############################################################################
# Pins: the SEVERE finding's other consequence -- maintenance mode lived
# inside `if [ "$SKIP_DB" != true ]`, so `--restore-live --confirm-slug
# <slug> --skip-db` extracted straight into the LIVE storage volumes with
# no maintenance mode at all (an ordinary, documented invocation -- the
# usage guard only refuses when BOTH --skip-db and --skip-files are set).
# `docker run --user 0:0 ... tar -x` wrote into the same volumes the app
# was actively serving/writing to.
#
# Runs the real tenant-restore.sh end-to-end with fake docker/restic on
# PATH, --skip-db set, and asserts `artisan down` still happens BEFORE any
# file extraction.
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "tenant-restore.sh: --restore-live --skip-db still enters maintenance mode before extracting files"
sandbox_init

fake_exe restic <<'EOS'
case "$*" in
    snapshots*) exit 0 ;;
    "ls "*)
        echo "/tmp/acme-backup-files-XyZ123/storage-app-public"
        echo "/tmp/acme-backup-files-XyZ123/storage-app-private"
        exit 0
        ;;
    "dump "*"--archive tar")
        echo "fake-tar-bytes"
        exit 0
        ;;
esac
exit 0
EOS

fake_exe docker <<'EOS'
case "$*" in
    *"volume inspect"*) exit 0 ;;
    *"find /d -mindepth 1"*) echo "/d/somefile"; exit 0 ;;
    # The ONE piped invocation, matched specifically -- NOT the catch-all.
    # See case 16's identical fix for why: a catch-all drain hangs any
    # OTHER, non-piped docker call whose stdin (this test script's own) is
    # a terminal or a held-open FIFO instead of EOF-on-first-read. Case 29
    # pins this.
    *"tar -x -C /dest"*) cat >/dev/null; exit 0 ;;
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
    bash "$SCRIPTS_DIR/tenant-restore.sh" acme latest --restore-live --confirm-slug acme --skip-db 2>&1)"
RC=$?

assert_eq "0" "$RC" "exit code"
assert_not_contains "$OUT" "usage:" "output must not be a usage error"

DOWN_LINE="$(grep -n 'artisan down' "$CALL_LOG" | head -1 | cut -d: -f1)"
FILES_LINE="$(grep -n -- '--user 0:0' "$CALL_LOG" | head -1 | cut -d: -f1)"

[ -n "$DOWN_LINE" ] || fail "artisan down was never invoked, even with --skip-db -- file extraction ran with no maintenance mode at all"
[ -n "$FILES_LINE" ] || fail "file extraction was never invoked"

if [ -n "$DOWN_LINE" ] && [ -n "$FILES_LINE" ] && [ "$DOWN_LINE" -ge "$FILES_LINE" ]; then
    fail "artisan down (line ${DOWN_LINE}) did not happen BEFORE file extraction (line ${FILES_LINE})"
fi

test_finish
