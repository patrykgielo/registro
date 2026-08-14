#!/bin/bash
###############################################################################
# tenant-backup.sh: BACKUP_HEALTHCHECK_URL set and the backup fully
# succeeds -- the ping actually fires, with the configured URL, after
# "Backup complete" is logged.
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "tenant-backup.sh: a fully successful backup pings BACKUP_HEALTHCHECK_URL"
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
CURL_CALL="$(grep '^curl' "$CALL_LOG" || true)"
[ -n "$CURL_CALL" ] || fail "dead-man's-switch ping was never sent on a successful backup"
assert_contains "$CURL_CALL" "https://example.test/ping/acme-secret-token" "curl invocation carries the configured URL"

test_finish
