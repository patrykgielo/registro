#!/bin/bash
###############################################################################
# tenant-backup.sh: a PARTIAL backup (database backed up, one storage volume
# could not be staged) already die()s with exit 3 before reaching the
# "Backup complete" line -- this proves the dead-man's-switch never fires
# for it, purely because of WHERE the ping is placed (after the FILES_FAILED
# gate), with no separate "is this actually healthy" condition needed around
# the ping itself. If a future edit ever moved the ping above that gate,
# this case would catch it.
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "tenant-backup.sh: a partial backup never pings the dead-man's-switch"
sandbox_init

STACKS_ROOT="$SANDBOX/stacks"
SLUG="acme"
STACK_DIR="$STACKS_ROOT/$SLUG"
mkdir -p "$STACK_DIR"
cat >"$STACK_DIR/.env" <<'ENV'
DB_DATABASE=registro
REGISTRO_VERSION=vTEST
BACKUP_HEALTHCHECK_URL=https://example.test/ping/acme-secret-token
ENV
cat >"$STACK_DIR/.env.secrets" <<'ENV'
DB_ROOT_PASSWORD='secret'
ENV
: >"$STACK_DIR/docker-compose.prod.yml"
: >"$STACK_DIR/docker-compose.tenant-network.override.yml"

BACKUP_ROOT="$SANDBOX/backups"
mkdir -p "${BACKUP_ROOT}/${SLUG}"
: >"${BACKUP_ROOT}/${SLUG}/password"

fake_exe docker <<'EOS'
case "$1" in
    volume) [ "$2" = "inspect" ] && exit 0; exit 1 ;;
    run)
        # storage-app-private specifically fails to stage -- storage-app-public
        # and the database dump both succeed, so this is genuinely a PARTIAL
        # backup, not a total one.
        for a in "$@"; do
            case "$a" in
                *storage-app-private*) exit 1 ;;
            esac
        done
        exit 0
        ;;
    compose)
        for a in "$@"; do
            if [ "$a" = "mysqldump" ]; then
                echo "-- fake dump"
                echo "-- Dump completed on fake"
                exit 0
            fi
        done
        exit 0
        ;;
esac
exit 0
EOS
fake_exe restic <<'EOS'
case "$1" in
    snapshots) exit 0 ;;
    backup) exit 0 ;;
esac
exit 0
EOS
fake_exe curl <<'EOS'
exit 0
EOS

OUT="$(REGISTRO_STACKS_ROOT="$STACKS_ROOT" REGISTRO_BACKUP_ROOT="$BACKUP_ROOT" \
    bash "$SCRIPTS_DIR/tenant-backup.sh" "$SLUG" 2>&1)"
RC=$?

assert_eq "3" "$RC" "exit code -- partial backup must still be reported as a failure"
assert_contains "$OUT" "at least one storage volume could not be included" "log"
assert_not_contains "$OUT" "Backup complete" "the happy-path success line must never be logged for a partial backup"
assert_not_contains "$(cat "$CALL_LOG")" "curl" "the dead-man's-switch must never fire for a partial backup"

test_finish
