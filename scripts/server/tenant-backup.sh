#!/bin/bash
###############################################################################
# Standalone restic backup for ONE tenant stack -- the same mechanism
# apply.sh's own final "backup" step uses, factored out so cron can call it
# between applies (an apply only backs up at release time; a tenant's data
# still changes every day in between).
#
# Deliberately its own file rather than a shared "source this" library:
# apply.sh's copy runs inline, mid-reconciliation, with the maintenance-mode
# trap and lock already held, and duplicating those ~25 lines here (dump +
# assert + restic backup) is a smaller risk than making apply.sh depend on an
# external file that could be edited independently and drift from what it
# actually needs.
#
# Usage (as the deploy user):
#   /opt/registro/tenant-backup.sh <slug>
#
# Cron (per tenant, staggered so they don't all mysqldump at once). No output
# redirect needed -- this script logs to /opt/stacks/<slug>/.apply.log itself:
#   17 3 * * * deploy /opt/registro/tenant-backup.sh acme
#
# Exit codes: 0 success, 1 usage, 2 preconditions (stack/restic missing),
# 3 backup failed.
###############################################################################

set -euo pipefail

readonly STACKS_ROOT="${REGISTRO_STACKS_ROOT:-/opt/stacks}"
readonly COMPOSE_FILE="docker-compose.prod.yml"
readonly TENANT_NETWORK_OVERRIDE="docker-compose.tenant-network.override.yml"
readonly BACKUP_ROOT="${REGISTRO_BACKUP_ROOT:-/opt/registro/tenant-backups}"

[ $# -eq 1 ] || { echo "usage: tenant-backup.sh <slug>" >&2; exit 1; }
SLUG="$1"
STACK_DIR="${STACKS_ROOT}/${SLUG}"
# Same file apply.sh itself logs to -- STACKS_ROOT/.state/<slug>/apply.log,
# never inside STACK_DIR, which is a live git working tree (see apply.sh's
# own STATE_DIR comment: anything written into it outside a clone can make a
# later `git clone`/checkout see a non-empty directory). Cron-triggered
# backups land in the same file as apply runs for that tenant.
LOG_FILE="${STACKS_ROOT}/.state/${SLUG}/apply.log"
COMPOSE_ARGS=(-f "$COMPOSE_FILE" -f "$TENANT_NETWORK_OVERRIDE")
mkdir -p "${STACKS_ROOT}/.state/${SLUG}" 2>/dev/null || true

log() {
    local line="[$(date '+%Y-%m-%d %H:%M:%S')] backup: $*"
    echo "$line" >>"$LOG_FILE" 2>/dev/null || true
    echo "$line" 2>/dev/null || true
}
die() { log "ERROR: $1"; exit "${2:-1}"; }

[ -d "$STACK_DIR" ] || die "${STACK_DIR} does not exist -- was this tenant ever applied?" 2
[ -f "${STACK_DIR}/.env.secrets" ] || die "${STACK_DIR}/.env.secrets missing" 2
command -v restic >/dev/null 2>&1 || die "restic not installed -- see app/docs/deployment/tenant-apply.md" 2

cd "$STACK_DIR"
set -a
# shellcheck disable=SC1091
source .env.secrets
set +a
: "${DB_ROOT_PASSWORD:?DB_ROOT_PASSWORD missing from .env.secrets}"

DB_DATABASE="$(grep -m1 '^DB_DATABASE=' .env 2>/dev/null | cut -d= -f2- || echo registro)"

BACKUP_DIR="${BACKUP_ROOT}/${SLUG}"
mkdir -p "$BACKUP_DIR"
[ -f "${BACKUP_DIR}/password" ] || die "${BACKUP_DIR}/password missing -- this stack's backup repository was never initialized by apply.sh" 2
export RESTIC_REPOSITORY="${RESTIC_REPOSITORY:-${BACKUP_DIR}/repo}"
export RESTIC_PASSWORD_FILE="${BACKUP_DIR}/password"

restic snapshots >/dev/null 2>&1 || die "restic repository at ${RESTIC_REPOSITORY} is not initialized -- run apply.sh first" 2

DUMP="$(mktemp "/tmp/${SLUG}-backup-XXXXXX.sql")"
trap 'rm -f "$DUMP"' EXIT

log "Dumping ${DB_DATABASE}..."
docker compose "${COMPOSE_ARGS[@]}" exec -T mysql mysqldump \
    -uroot -p"${DB_ROOT_PASSWORD}" \
    --single-transaction --routines --triggers --events \
    "$DB_DATABASE" >"$DUMP" 2>>"$LOG_FILE" || die "mysqldump failed" 3

# Content assertion, not exit status -- see apply.sh's dump_database() for
# the full reasoning (a killed/truncated dump can still report rc=0).
tail -5 "$DUMP" | grep -q '^-- Dump completed' || die "dump did not complete (no trailing marker) -- refusing to back up a partial file" 3

log "Backing up to restic (host=tenant-${SLUG})..."
restic backup "$DUMP" --host "tenant-${SLUG}" --tag "slug=${SLUG}" --tag "scheduled" \
    >>"$LOG_FILE" 2>&1 || die "restic backup failed" 3

log "Backup complete"
exit 0
