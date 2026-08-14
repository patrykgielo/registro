#!/bin/bash
###############################################################################
# tenant-backup.sh: BACKUP_HEALTHCHECK_URL absent from .env -- the dead-man's-
# switch must be completely inert. No curl call at all, not even one that
# fails harmlessly -- proves this is genuinely opt-in, not "off but still
# tries something", on every tenant that has not configured it.
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "tenant-backup.sh: no BACKUP_HEALTHCHECK_URL means no ping at all"
sandbox_init

STACKS_ROOT="$SANDBOX/stacks"
SLUG="acme"
STACK_DIR="$STACKS_ROOT/$SLUG"
mkdir -p "$STACK_DIR"
cat >"$STACK_DIR/.env" <<'ENV'
DB_DATABASE=registro
REGISTRO_VERSION=vTEST
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
    run) exit 0 ;;
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

assert_eq "0" "$RC" "exit code"
assert_contains "$OUT" "Backup complete" "log"
assert_not_contains "$(cat "$CALL_LOG")" "curl" "curl must never be invoked when BACKUP_HEALTHCHECK_URL is unset"

test_finish
