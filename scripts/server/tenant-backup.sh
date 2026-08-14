#!/bin/bash
###############################################################################
# Standalone restic backup for ONE tenant stack -- the same mechanism
# apply.sh's own final "backup" step uses, factored out so cron can call it
# between applies (an apply only backs up at release time; a tenant's data
# still changes every day in between).
#
# Covers the database (mysqldump) AND the two storage volumes
# (storage-app-public, storage-app-private -- client logos, equipment photos,
# portfolio images, CMS block images, GDPR export ZIPs), all in ONE restic
# snapshot: restoring a tenant means one snapshot ID, not hunting for a
# second one that happens to be close in time. Before this, nothing backed up
# those volumes at all -- the only mechanism was a `tar` command a human had
# to remember (see instalacja-tenanta-od-zera.md's old 7.6).
#
# Deliberately its own file rather than a shared "source this" library:
# apply.sh's copy runs inline, mid-reconciliation, with the maintenance-mode
# trap and lock already held, and duplicating those lines here (dump + stage
# + restic backup) is a smaller risk than making apply.sh depend on an
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
# Same deterministic naming apply.sh's own force_clear_flag() already relies
# on for the identical reason: Compose's own ${project}_${volume-key} naming
# scheme (docker-compose.prod.yml's `name: ${TENANT_PREFIX:-registro}`),
# COMPUTED here rather than grepped out of a possibly-stale .env or asked of
# `docker compose` itself -- every Compose subcommand interpolates the WHOLE
# file first (ci-cd-troubleshooting.md's docker-compose-run incident), and
# this script already needs the volumes to exist independently of whether
# that interpolation would succeed right now.
readonly TENANT_PREFIX="tenant-${SLUG}"
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
# The image used to stage the two storage volumes below -- reused rather than
# a generic helper image (alpine/busybox) so nothing here depends on pulling
# a NEW image over the network on every cron run: this is the exact image
# already running this tenant's containers, guaranteed present locally.
REGISTRO_IMAGE_TAG="$(grep -m1 '^REGISTRO_VERSION=' .env 2>/dev/null | cut -d= -f2- || true)"
[ -n "$REGISTRO_IMAGE_TAG" ] || die "REGISTRO_VERSION not set in .env -- has this tenant ever been applied?" 2
readonly REGISTRO_IMAGE="ghcr.io/patrykgielo/registro:${REGISTRO_IMAGE_TAG}"

BACKUP_DIR="${BACKUP_ROOT}/${SLUG}"
mkdir -p "$BACKUP_DIR"
# RESTIC_PASSWORD_FILE overridable same as RESTIC_REPOSITORY -- the default
# keeps the password next to the repo it unlocks, on the same disk, which is
# disk redundancy, not disaster recovery (see instalacja-tenanta-od-zera.md
# Część 6/8.1: if this disk dies, the password dies with it). An operator who
# has moved a copy elsewhere (password manager, second host) points here.
export RESTIC_REPOSITORY="${RESTIC_REPOSITORY:-${BACKUP_DIR}/repo}"
export RESTIC_PASSWORD_FILE="${RESTIC_PASSWORD_FILE:-${BACKUP_DIR}/password}"
[ -f "$RESTIC_PASSWORD_FILE" ] || die "${RESTIC_PASSWORD_FILE} missing -- this stack's backup repository was never initialized by apply.sh" 2

restic snapshots >/dev/null 2>&1 || die "restic repository at ${RESTIC_REPOSITORY} is not initialized -- run apply.sh first" 2

DUMP="$(mktemp "/tmp/${SLUG}-backup-XXXXXX.sql")"
STAGE_DIR="$(mktemp -d "/tmp/${SLUG}-backup-files-XXXXXX")"
trap 'rm -f "$DUMP"; rm -rf "$STAGE_DIR"' EXIT

log "Dumping ${DB_DATABASE}..."
docker compose "${COMPOSE_ARGS[@]}" exec -T mysql mysqldump \
    -uroot -p"${DB_ROOT_PASSWORD}" \
    --single-transaction --routines --triggers --events \
    "$DB_DATABASE" >"$DUMP" 2>>"$LOG_FILE" || die "mysqldump failed" 3

# Content assertion, not exit status -- see apply.sh's dump_database() for
# the full reasoning (a killed/truncated dump can still report rc=0).
tail -5 "$DUMP" | grep -q '^-- Dump completed' || die "dump did not complete (no trailing marker) -- refusing to back up a partial file" 3

# --- storage volumes --------------------------------------------------------
#
# storage-app-public/storage-app-private hold every uploaded file (client
# logos, equipment photos, portfolio images, CMS block images, GDPR export
# ZIPs) -- see this script's own header for why nothing backed them up before
# and why they end up in the SAME restic snapshot as the SQL dump above.
#
# `docker volume inspect` BEFORE `docker run -v <name>:/path` is the guard:
# `docker run` with a volume name that does not exist SILENTLY CREATES an
# empty one instead of erroring -- without this check a missing/renamed
# volume would back up as "successfully" empty, and nobody would notice until
# a restore came back with nothing in it.
#
# `--user 0:0` (root) inside the staging container: files inside the volume
# are owned by the image's fixed `laravel` user (UID 1000, ADR-013), but
# ${dest} is a HOST-created directory (mkdir below, owned by whichever user
# runs this script) with default permissions that do not grant that UID
# write access. Root bypasses both the read side (owner mismatch on /src)
# and the write side (owner mismatch on /dest) without needing the two UIDs
# to ever agree.
#
# The trailing `chown -R` back to THIS process's own UID/GID is not optional
# cleanliness -- found by actually running this against a real volume: GNU
# `cp -a /src/. /dest/` does not only copy files INTO the pre-existing
# ${dest}, it also re-stamps ${dest}'s OWN ownership/mode to match /src's top
# level (root:root, since the volume's own root is root-owned). Without the
# chown, ${dest} itself silently flips from "owned by this script's user" to
# "owned by root" the moment the copy runs, and this script's own `rm -rf`
# in the trap below (run as this same non-root user, no sudo on this box)
# fails with Permission Denied on every subsequent run -- reproduced and
# fixed before shipping, not assumed.
stage_volume() {
    local vol="$1" dest="$2"
    docker volume inspect "$vol" >/dev/null 2>&1 || {
        log "ERROR: volume ${vol} does not exist -- refusing to back it up as an empty directory"
        return 1
    }
    mkdir -p "$dest"
    # --entrypoint sh: this image's own docker/entrypoint.sh refuses to run as
    # anyone but the `laravel` user (EXPECTED_USER check) -- found by actually
    # running this against the real image, not a stand-in. Without the override,
    # --user 0:0 above never reaches `cp`/`chown` at all: the entrypoint's own
    # `whoami` check kills the container first, so `docker run` "succeeds" at
    # starting a container that exits 1 before doing any work, and every backup
    # silently produced an empty snapshot for both storage volumes. Same fix,
    # same reasoning, as apply.sh's own copy of this function.
    docker run --rm --user 0:0 --entrypoint sh \
        -v "${vol}:/src:ro" -v "${dest}:/dest" "$REGISTRO_IMAGE" \
        -c "cp -a /src/. /dest/ && chown -R $(id -u):$(id -g) /dest" \
        >/dev/null 2>>"$LOG_FILE" || {
        log "ERROR: staging volume ${vol} failed"
        return 1
    }
}

RESTIC_TARGETS=("$DUMP")
FILES_FAILED=false
if stage_volume "${TENANT_PREFIX}_storage-app-public" "${STAGE_DIR}/storage-app-public"; then
    RESTIC_TARGETS+=("${STAGE_DIR}/storage-app-public")
else
    FILES_FAILED=true
fi
if stage_volume "${TENANT_PREFIX}_storage-app-private" "${STAGE_DIR}/storage-app-private"; then
    RESTIC_TARGETS+=("${STAGE_DIR}/storage-app-private")
else
    FILES_FAILED=true
fi

log "Backing up to restic (host=tenant-${SLUG})..."
restic backup "${RESTIC_TARGETS[@]}" --host "tenant-${SLUG}" --tag "slug=${SLUG}" --tag "scheduled" \
    >>"$LOG_FILE" 2>&1 || die "restic backup failed" 3

# A files failure does not stop the database (or the OTHER volume) from being
# backed up above -- it is only reported here, after everything that COULD be
# backed up already has been, so one missing/renamed volume never silently
# drops the rest of the snapshot.
[ "$FILES_FAILED" != true ] \
    || die "database backed up, but at least one storage volume could not be included in the snapshot -- see ${LOG_FILE}" 3

log "Backup complete"
exit 0
