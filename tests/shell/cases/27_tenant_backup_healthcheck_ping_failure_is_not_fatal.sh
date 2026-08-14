#!/bin/bash
###############################################################################
# tenant-backup.sh: the ping endpoint itself is unreachable -- a successful
# backup must NOT be reported as failed because monitoring could not be
# reached. Exit code stays 0, and the durable log shows "Backup complete"
# followed by a WARNING about the ping specifically (never the URL value
# itself -- it can carry a secret token), so a human reading the log later
# can tell these apart from an actual backup failure.
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "tenant-backup.sh: a failed healthcheck ping never fails an otherwise-successful backup"
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
# The endpoint itself is down -- every real failure mode (refused, DNS,
# timeout) collapses to "curl exits non-zero" from this script's own point
# of view, so a bare failing fake is a faithful stand-in.
fake_exe curl <<'EOS'
exit 7
EOS

OUT="$(REGISTRO_STACKS_ROOT="$STACKS_ROOT" REGISTRO_BACKUP_ROOT="$BACKUP_ROOT" \
    bash "$SCRIPTS_DIR/tenant-backup.sh" "$SLUG" 2>&1)"
RC=$?

assert_eq "0" "$RC" "exit code -- a ping failure must never turn a successful backup into a failed one"
assert_contains "$OUT" "Backup complete" "log"
assert_contains "$OUT" "dead-man's-switch ping" "log warns about the ping specifically"
assert_not_contains "$OUT" "acme-secret-token" "the alert URL's own value must never be echoed into the log"

# Ordering: "Backup complete" appears before the ping warning -- the ping is
# a side effect of an already-decided success, not a precondition for it.
COMPLETE_LINE="$(grep -n 'Backup complete' <<<"$OUT" | head -1 | cut -d: -f1)"
WARNING_LINE="$(grep -n "dead-man's-switch ping" <<<"$OUT" | head -1 | cut -d: -f1)"
[ -n "$COMPLETE_LINE" ] && [ -n "$WARNING_LINE" ] || fail "could not locate both log lines to compare ordering"
[ "$COMPLETE_LINE" -lt "$WARNING_LINE" ] || fail "expected 'Backup complete' (line ${COMPLETE_LINE}) before the ping warning (line ${WARNING_LINE})"

test_finish
