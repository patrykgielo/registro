#!/bin/bash
###############################################################################
# Standalone restic restore for ONE tenant stack -- the read side of
# tenant-backup.sh/apply.sh's final backup step. Until this file existed,
# nothing in this repo had ever read a restic snapshot back: the only
# restore procedure was prose in instalacja-tenanta-od-zera.md Część 8 for a
# human to retype by hand. This is that procedure, made runnable.
#
# Same reasoning as tenant-backup.sh's own header for why this is a
# standalone file rather than a sourced library -- not repeated here.
#
# SAFE BY DEFAULT: this script never touches the tenant's live database or
# live storage volumes unless BOTH --restore-live AND --confirm-slug <slug>
# are given. Without them it restores into a SCRATCH database (created
# fresh inside the tenant's own running mysql container, never the live
# DB_DATABASE) and a plain host directory for files -- enough to prove a
# snapshot is readable and its contents are correct without any risk to
# data currently in use. See "Restore modes" below.
#
# Restore modes:
#   default (no --restore-live)
#     DB    -> loaded into --target-db NAME inside the tenant's own mysql
#              container (default name: "<DB_DATABASE>_restore_verify").
#              DROPPED and recreated if it already exists. Refuses if NAME
#              equals the tenant's live DB_DATABASE.
#     files -> extracted to --files-dir DIR on the HOST (default: a fresh
#              mktemp directory, printed at the end). Never written into
#              the live Docker volumes.
#   --restore-live --confirm-slug <slug>   (DESTRUCTIVE, loud opt-in)
#     ONE maintenance-mode window wraps BOTH phases, whichever of them run
#     (even with --skip-db or --skip-files) -- app taken down (artisan
#     down), horizon/scheduler stopped, THEN the database is loaded into
#     the LIVE DB_DATABASE (if not skipped) AND the storage volumes are
#     extracted into the LIVE storage-app-public/storage-app-private
#     Docker volumes (if not skipped, root-privileged helper container,
#     chown -R 1000:1000 -- ADR-013's laravel UID), and ONLY once
#     everything that was going to run has succeeded does horizon/scheduler
#     restart and the app come back up (artisan up). This mirrors
#     instalacja-tenanta-od-zera.md Część 8.4+8.6 as ONE operation, not two
#     independent ones -- an app serving a freshly restored database that
#     references equipment photos/logos/CMS images still mid-extraction is
#     exactly the inconsistent state a single restic snapshot exists to
#     prevent (see the whole reasoning in tenant-backup.sh's own header).
#
#     If the database phase fails, the files phase NEVER RUNS -- restoring
#     files against a database that was NOT restored is its own kind of
#     inconsistency. Any failure in EITHER phase leaves the app in
#     maintenance mode; nothing auto-clears it (see "Maintenance mode
#     never auto-heals" below) -- re-run this command once the cause is
#     fixed, or clear it manually once you've confirmed by hand that the
#     database and files are consistent with each other.
#
#     Also refuses if the target volume does not exist (never silently
#     creates one to restore into, same guard as tenant-backup.sh's
#     stage_volume()).
#   --confirm-slug must equal the <slug> argument, typed a second time --
#   guards against a pasted/scripted command whose first argument silently
#   changed (e.g. a loop variable) from doing the destructive thing to the
#   wrong tenant.
#
# Maintenance mode never auto-heals. Once artisan down succeeds, NOTHING
# in this script -- not a failure, not a signal, not an untrapped crash --
# automatically calls artisan up except the script's own deliberate,
# sequential success path (both phases done, right at the end). Unlike
# apply.sh's clear_maintenance() (which DOES auto-clear on an interrupted
# migration, because a migration is a single, independent step), a live
# restore here has TWO dependent phases -- auto-clearing on an interrupt
# that landed between them would risk exactly the "app serving traffic
# against inconsistent data" state this whole design exists to prevent. A
# human confirming both are consistent before typing `artisan up` is the
# deliberately safer default.
#
# STATE_DIR/apply-status: --restore-live writes the SAME status file
# apply.sh writes and tenant-check.sh already reads as ground truth
# (RUNNING when it starts touching live state, FAILED with a reason on any
# failure/signal, OK on a fully successful restore) -- so a broken or
# killed live restore is never read as a healthy tenant by tenant-check.sh
# just because the last APPLY happened to succeed. Never written for the
# default (scratch) mode, and never written for an early refusal (bad
# --confirm-slug, precondition failures) that never touched the tenant --
# see RESTORE_LIVE_STARTED below.
#
# Usage (as the deploy user):
#   tenant-restore.sh <slug> [snapshot] [options]
#
#   <slug>                 tenant slug (same as tenant-backup.sh's argument)
#   [snapshot]              restic snapshot ID, or "latest" (default)
#
# Options:
#   --target-db NAME        scratch-mode DB restore target (default mode
#                            only; default NAME: "<DB_DATABASE>_restore_verify")
#   --files-dir DIR          scratch-mode files restore target (default
#                            mode only; default: fresh mktemp directory)
#   --restore-live           DESTRUCTIVE: restore into the live DB + volumes
#   --confirm-slug SLUG      required with --restore-live; must equal <slug>
#   --skip-db                do not restore the database
#   --skip-files              do not restore storage-app-public/private
#
# Examples:
#   tenant-restore.sh acme                                  # scratch-verify latest
#   tenant-restore.sh acme a1b2c3d4                          # scratch-verify one snapshot
#   tenant-restore.sh acme latest --restore-live --confirm-slug acme
#
# Exit codes: 0 success, 1 usage, 2 preconditions/safety refusal,
# 3 restore failed.
###############################################################################

set -euo pipefail

readonly STACKS_ROOT="${REGISTRO_STACKS_ROOT:-/opt/stacks}"
readonly COMPOSE_FILE="docker-compose.prod.yml"
readonly TENANT_NETWORK_OVERRIDE="docker-compose.tenant-network.override.yml"
readonly BACKUP_ROOT="${REGISTRO_BACKUP_ROOT:-/opt/registro/tenant-backups}"
# Stock, un-customized image for the live-mode tar extraction below -- it
# has no entrypoint.sh of its own to fight with (unlike our own
# ghcr.io/patrykgielo/registro image, which refuses to run as non-`laravel`
# users and would need --entrypoint sh, same as tenant-backup.sh's
# stage_volume()). Same image instalacja-tenanta-od-zera.md Część 8.6
# already documents and has verified end-to-end.
readonly EXTRACT_IMAGE="debian:bookworm-slim"

usage() {
    cat >&2 <<'EOS'
usage: tenant-restore.sh <slug> [snapshot] [options]

  <slug>                  tenant slug
  [snapshot]               restic snapshot ID, or "latest" (default)

options:
  --target-db NAME         scratch-mode DB restore target (default mode only)
  --files-dir DIR           scratch-mode files restore target (default mode only)
  --restore-live            DESTRUCTIVE: restore into the live DB + volumes
  --confirm-slug SLUG       required with --restore-live; must equal <slug>
  --skip-db                 do not restore the database
  --skip-files               do not restore storage volumes
EOS
    exit 1
}

[ $# -ge 1 ] || usage
SLUG="$1"; shift
SNAPSHOT="latest"
if [ $# -gt 0 ] && [[ "$1" != --* ]]; then
    SNAPSHOT="$1"; shift
fi

TARGET_DB=""
FILES_DIR=""
RESTORE_LIVE=false
CONFIRM_SLUG=""
SKIP_DB=false
SKIP_FILES=false

while [ $# -gt 0 ]; do
    case "$1" in
        --target-db) [ $# -ge 2 ] || usage; TARGET_DB="$2"; shift 2 ;;
        --files-dir) [ $# -ge 2 ] || usage; FILES_DIR="$2"; shift 2 ;;
        --restore-live) RESTORE_LIVE=true; shift ;;
        --confirm-slug) [ $# -ge 2 ] || usage; CONFIRM_SLUG="$2"; shift 2 ;;
        --skip-db) SKIP_DB=true; shift ;;
        --skip-files) SKIP_FILES=true; shift ;;
        *) usage ;;
    esac
done

[ "$SKIP_DB" = true ] && [ "$SKIP_FILES" = true ] && { echo "--skip-db and --skip-files together leave nothing to restore" >&2; usage; }

STACK_DIR="${STACKS_ROOT}/${SLUG}"
readonly TENANT_PREFIX="tenant-${SLUG}"
STATE_DIR="${STACKS_ROOT}/.state/${SLUG}"
LOG_FILE="${STATE_DIR}/apply.log"
# Same file apply.sh writes and tenant-check.sh already reads as ground
# truth -- see this script's own header ("STATE_DIR/apply-status").
STATUS_FILE="${STATE_DIR}/apply-status"
COMPOSE_ARGS=(-f "$COMPOSE_FILE" -f "$TENANT_NETWORK_OVERRIDE")
mkdir -p "$STATE_DIR" 2>/dev/null || true

# --- global state consulted by log()/die()/on_exit()/on_signal() below --
# initialized unconditionally, BEFORE any of those can be called, so a
# very early die() (a bad --confirm-slug, before STACK_DIR is even known
# to exist) never trips an unbound-variable error under `set -u`.
DUMP=""
MAINTENANCE_ON=false
STATUS_FINALIZED=false
# Flips true only once a --restore-live run has actually started touching
# live state (right before `artisan down`) -- guards die()/on_exit()/
# on_signal() from writing a FAILED status for a tenant an early refusal
# (bad --confirm-slug, a precondition check, an empty snapshot) never
# touched in the first place.
RESTORE_LIVE_STARTED=false

log() {
    local line="[$(date '+%Y-%m-%d %H:%M:%S')] restore: $*"
    echo "$line" >>"${LOG_FILE:-/dev/null}" 2>/dev/null || true
    echo "$line" 2>/dev/null || true
}

write_restore_status() {
    mkdir -p "$(dirname "$STATUS_FILE")" 2>/dev/null || true
    local tag
    tag="$(grep -m1 '^REGISTRO_VERSION=' "${STACK_DIR}/.env" 2>/dev/null | cut -d= -f2- || true)"
    printf '%s %s %s %s\n' "$1" "${tag:-unknown}" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "${2:-}" >"$STATUS_FILE" 2>/dev/null || true
}

# Deliberately does NOT attempt `artisan up` on its own -- see this file's
# own header ("Maintenance mode never auto-heals"). Only logs, so an
# operator reading the log sees exactly why the app is still down and what
# to check before clearing it by hand.
clear_maintenance() {
    [ "$MAINTENANCE_ON" = true ] || return 0
    log "Leaving ${SLUG} in maintenance mode -- the restore did not complete (or was interrupted). Confirm the database and files are consistent with each other before clearing it: docker compose exec -T app php artisan up. See ${LOG_FILE}."
}

die() {
    log "ERROR: $1"
    if [ "$RESTORE_LIVE" = true ] && [ "$RESTORE_LIVE_STARTED" = true ] && [ "$STATUS_FINALIZED" != true ]; then
        STATUS_FINALIZED=true
        write_restore_status "FAILED" "$1"
    fi
    exit "${2:-1}"
}

# Mirrors apply.sh's on_exit/on_signal pair exactly (same reasoning: bash
# reads $? as 0 when the process dies from an untrapped signal landing
# between commands, so a stale status can otherwise survive a kill). Kept
# deliberately as close to apply.sh's shape as this script's own
# differences require, rather than inventing a second pattern -- see
# ci-cd-troubleshooting.md's "drugi przegląd infrastrukturalny" incident
# for the original reasoning this is copied from.
on_exit() {
    local rc=$?
    [ -n "$DUMP" ] && rm -f "$DUMP"
    if [ "$RESTORE_LIVE" = true ] && [ "$RESTORE_LIVE_STARTED" = true ] \
            && [ "$rc" -ne 0 ] && [ "$STATUS_FINALIZED" != true ]; then
        clear_maintenance
        write_restore_status "FAILED" "exit ${rc} -- see ${LOG_FILE}"
    fi
    return "$rc"
}

on_signal() {
    trap '' HUP INT TERM PIPE EXIT
    log "Received SIG${1} -- cleaning up"
    [ -n "$DUMP" ] && rm -f "$DUMP"
    if [ "$RESTORE_LIVE" = true ] && [ "$RESTORE_LIVE_STARTED" = true ]; then
        clear_maintenance
        write_restore_status "FAILED" "killed by SIG${1}"
    fi
    exit 3
}
trap on_exit EXIT
trap '' PIPE
for sig in HUP INT TERM; do
    # shellcheck disable=SC2064
    trap "on_signal ${sig}" "$sig"
done

if [ "$RESTORE_LIVE" = true ]; then
    [ "$CONFIRM_SLUG" = "$SLUG" ] \
        || die "refusing: --restore-live requires --confirm-slug ${SLUG} (typed exactly, to catch a stale/wrong slug in a pasted command)" 2
    [ -n "$TARGET_DB" ] && die "--target-db is a scratch-mode option and does not apply with --restore-live (live restores always target the tenant's own DB_DATABASE)" 1
    [ -n "$FILES_DIR" ] && die "--files-dir is a scratch-mode option and does not apply with --restore-live (live restores always target the tenant's own storage volumes)" 1
fi

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
export RESTIC_REPOSITORY="${RESTIC_REPOSITORY:-${BACKUP_DIR}/repo}"
# Same override as tenant-backup.sh/apply.sh -- see those files' own
# comments on why the default (password next to the repo it unlocks) is
# disk redundancy, not disaster recovery.
export RESTIC_PASSWORD_FILE="${RESTIC_PASSWORD_FILE:-${BACKUP_DIR}/password}"
[ -f "$RESTIC_PASSWORD_FILE" ] || die "${RESTIC_PASSWORD_FILE} missing -- this stack's backup repository was never initialized (run apply.sh/tenant-backup.sh first)" 2

restic snapshots >/dev/null 2>&1 || die "restic repository at ${RESTIC_REPOSITORY} is not initialized or unreadable -- run apply.sh/tenant-backup.sh first" 2

if [ "$SKIP_DB" != true ] && [ "$RESTORE_LIVE" != true ]; then
    TARGET_DB="${TARGET_DB:-${DB_DATABASE}_restore_verify}"
    [ "$TARGET_DB" != "$DB_DATABASE" ] \
        || die "--target-db must not be the tenant's live database (${DB_DATABASE}) -- that would silently do what --restore-live is for, without the safety confirmation. Use --restore-live --confirm-slug ${SLUG} instead." 2
fi
if [ "$SKIP_FILES" != true ] && [ "$RESTORE_LIVE" != true ] && [ -z "$FILES_DIR" ]; then
    FILES_DIR="$(mktemp -d "/tmp/${SLUG}-restore-files-XXXXXX")"
fi

log "Snapshots for tenant-${SLUG}:"
restic snapshots --host "tenant-${SLUG}" 2>&1 | tee -a "$LOG_FILE" >&2 || true

# --host only when resolving "latest" -- an explicit snapshot ID is already
# unambiguous, and filtering an exact ID by --host can reject it outright if
# the snapshot predates a tagging change.
LS_HOST_FILTER=()
[ "$SNAPSHOT" = "latest" ] && LS_HOST_FILTER=(--host "tenant-${SLUG}")
SNAPSHOT_LS="$(restic ls "$SNAPSHOT" "${LS_HOST_FILTER[@]}" 2>>"$LOG_FILE")" \
    || die "restic ls ${SNAPSHOT} failed -- see ${LOG_FILE}" 3

DB_PATH="$(printf '%s\n' "$SNAPSHOT_LS" | grep -E '\.sql$' | head -1)" || true
PUBLIC_PATH="$(printf '%s\n' "$SNAPSHOT_LS" | grep -E '/storage-app-public$' | head -1)" || true
PRIVATE_PATH="$(printf '%s\n' "$SNAPSHOT_LS" | grep -E '/storage-app-private$' | head -1)" || true

[ "$SKIP_DB" = true ] || [ -n "$DB_PATH" ] \
    || die "snapshot ${SNAPSHOT} has no .sql dump -- nothing to restore for the database (pass --skip-db to restore only files)" 3
[ "$SKIP_FILES" = true ] || [ -n "$PUBLIC_PATH" ] \
    || die "snapshot ${SNAPSHOT} has no storage-app-public entry -- pass --skip-files to restore only the database" 3
[ "$SKIP_FILES" = true ] || [ -n "$PRIVATE_PATH" ] \
    || die "snapshot ${SNAPSHOT} has no storage-app-private entry -- pass --skip-files to restore only the database" 3

# Number of path segments up to and including the directory itself --
# instalacja-tenanta-od-zera.md Część 8.6 documents this as a fixed
# "--strip-components=3" for today's mktemp naming; computed here instead
# of hardcoded so a future change to the staging path depth in
# tenant-backup.sh/apply.sh does not silently misalign this script.
strip_components_for() {
    local path="${1#/}"
    printf '%s' "$path" | awk -F/ '{print NF}'
}
if [ "$SKIP_FILES" != true ]; then
    PUBLIC_STRIP="$(strip_components_for "$PUBLIC_PATH")"
    PRIVATE_STRIP="$(strip_components_for "$PRIVATE_PATH")"
fi

restore_files_preview() {
    local snap_path="$1" strip="$2" dest="$3"
    mkdir -p "$dest"
    restic dump "$SNAPSHOT" "$snap_path" --archive tar 2>>"$LOG_FILE" \
        | tar -x -C "$dest" --strip-components="$strip" || {
        log "ERROR: extracting ${snap_path} to ${dest} failed"
        return 1
    }
    # Content assertion, not exit status -- same reasoning as the database
    # side's own SHOW TABLES > 0 check below: a `tar` that read zero
    # entries (e.g. a snapshot path that resolved to an empty directory)
    # still exits 0.
    [ -n "$(find "$dest" -mindepth 1 -print -quit)" ] || {
        log "ERROR: ${dest} is empty after extraction -- restore did not actually populate it"
        return 1
    }
}

restore_files_live() {
    local snap_path="$1" strip="$2" volume="$3"
    docker volume inspect "$volume" >/dev/null 2>&1 || {
        log "ERROR: volume ${volume} does not exist -- refusing to restore into a volume that was never created (bring the stack up with docker compose/apply.sh first)"
        return 1
    }
    # --user 0:0 (root) to write into the volume regardless of who owns it
    # already, then chown -R 1000:1000 (ADR-013's fixed `laravel` UID) as a
    # second command in the SAME privileged container -- this is the
    # counterpart the backup side has (tenant-backup.sh's stage_volume())
    # and the restore side previously lacked, per
    # instalacja-tenanta-od-zera.md Część 8.6/8.7's documented gap.
    restic dump "$SNAPSHOT" "$snap_path" --archive tar 2>>"$LOG_FILE" \
        | docker run --rm -i --user 0:0 -v "${volume}:/dest" "$EXTRACT_IMAGE" \
            sh -c "tar -x -C /dest --strip-components=${strip} && chown -R 1000:1000 /dest" \
            >/dev/null 2>>"$LOG_FILE" || {
        log "ERROR: restoring into volume ${volume} failed"
        return 1
    }
    # Same content assertion as restore_files_preview() above, run as a
    # SEPARATE step after extraction -- mirrors the database side's own
    # separate SHOW TABLES check, rather than folding it into the same
    # `sh -c` (a distinct step gives a distinct, unambiguous error message
    # instead of one generic "extraction failed" covering two different
    # failure shapes).
    if [ -z "$(docker run --rm -v "${volume}:/d" "$EXTRACT_IMAGE" sh -c 'find /d -mindepth 1 -print -quit' 2>>"$LOG_FILE")" ]; then
        log "ERROR: volume ${volume} is empty after extraction -- restore did not actually populate it"
        return 1
    fi
}

# --- read & verify the database dump content, if requested -- shared by
# both modes, and deliberately done BEFORE maintenance mode is entered
# (below) for --restore-live: this only reads from restic into a local
# temp file, it is not a live mutation, so there is no reason to take the
# app down before knowing the dump is even valid. ---------------------
if [ "$SKIP_DB" != true ]; then
    DUMP="$(mktemp "/tmp/${SLUG}-restore-XXXXXX.sql")"
    log "Reading database dump (${DB_PATH}) from snapshot ${SNAPSHOT}..."
    restic dump "$SNAPSHOT" "$DB_PATH" >"$DUMP" 2>>"$LOG_FILE" \
        || die "restic dump of ${DB_PATH} failed -- see ${LOG_FILE}" 3
    # Same content assertion as tenant-backup.sh's own dump/backup pair --
    # a truncated restic dump is not the same failure as a truncated
    # mysqldump, but the marker proves the ORIGINAL dump was complete, and
    # restic dump either returns the exact bytes or fails outright, so this
    # is still the right check here.
    tail -5 "$DUMP" | grep -q '^-- Dump completed' \
        || die "restored dump has no trailing '-- Dump completed' marker -- refusing to load a file that may be truncated" 3
fi

RESTORE_FAILED=false

if [ "$RESTORE_LIVE" = true ]; then
    ###########################################################################
    # LIVE restore -- ONE maintenance-mode window wraps BOTH phases (see this
    # file's own header). RESTORE_LIVE_STARTED flips true right here, the
    # first point any of this actually touches the tenant's live state --
    # every die()/on_exit()/on_signal() status write above this line was
    # gated on it being false, i.e. never fired.
    ###########################################################################
    RESTORE_LIVE_STARTED=true
    write_restore_status "RUNNING" "restore in progress (snapshot ${SNAPSHOT})"

    log "LIVE restore: taking ${SLUG} into maintenance mode..."
    docker compose "${COMPOSE_ARGS[@]}" exec -T app php artisan down --retry=15 2>>"$LOG_FILE" \
        || die "artisan down failed -- refusing to overwrite the live database/files while still possibly serving traffic" 3
    MAINTENANCE_ON=true
    docker compose "${COMPOSE_ARGS[@]}" stop horizon scheduler >>"$LOG_FILE" 2>&1 \
        || log "WARNING: stopping horizon/scheduler failed -- continuing, but they may write to the database/files mid-restore"

    if [ "$SKIP_DB" != true ]; then
        if ! docker compose "${COMPOSE_ARGS[@]}" exec -T mysql mysql -uroot -p"${DB_ROOT_PASSWORD}" \
                "${DB_DATABASE}" <"$DUMP" 2>>"$LOG_FILE"; then
            log "ERROR: loading dump into live ${DB_DATABASE} failed"
            RESTORE_FAILED=true
        else
            TABLE_COUNT="$(docker compose "${COMPOSE_ARGS[@]}" exec -T mysql mysql -uroot -p"${DB_ROOT_PASSWORD}" \
                -N -e 'SHOW TABLES' "${DB_DATABASE}" 2>>"$LOG_FILE" | wc -l | tr -d ' ')" || TABLE_COUNT=0
            if [ "$TABLE_COUNT" -gt 0 ]; then
                log "Live database restored: ${TABLE_COUNT} table(s) present"
            else
                log "ERROR: live database has zero tables after load -- restore did not actually populate data"
                RESTORE_FAILED=true
            fi
        fi
    fi

    # Files NEVER run if the database phase already failed -- restoring
    # files against a database that was NOT restored is its own kind of
    # inconsistency (see this file's own header).
    if [ "$RESTORE_FAILED" != true ] && [ "$SKIP_FILES" != true ]; then
        log "LIVE restore: extracting storage-app-public into the live volume..."
        if restore_files_live "$PUBLIC_PATH" "$PUBLIC_STRIP" "${TENANT_PREFIX}_storage-app-public"; then
            log "storage-app-public restored into the live volume"
        else
            RESTORE_FAILED=true
        fi
        if [ "$RESTORE_FAILED" != true ]; then
            log "LIVE restore: extracting storage-app-private into the live volume..."
            if restore_files_live "$PRIVATE_PATH" "$PRIVATE_STRIP" "${TENANT_PREFIX}_storage-app-private"; then
                log "storage-app-private restored into the live volume"
            else
                RESTORE_FAILED=true
            fi
        fi
    fi

    if [ "$RESTORE_FAILED" = true ]; then
        die "restore for ${SLUG} failed -- app left in maintenance mode, horizon/scheduler left stopped. Fix the cause, then either re-run this command or bring the stack back up manually once you've confirmed the database and files are consistent with each other (docker compose up -d horizon scheduler && docker compose exec -T app php artisan up). See ${LOG_FILE}." 3
    fi

    # Both phases (whichever were requested) succeeded -- only NOW is it
    # safe to let the app serve traffic again.
    docker compose "${COMPOSE_ARGS[@]}" up -d horizon scheduler >>"$LOG_FILE" 2>&1 \
        || log "WARNING: could not restart horizon/scheduler -- do it manually"
    docker compose "${COMPOSE_ARGS[@]}" exec -T app php artisan up 2>>"$LOG_FILE" \
        || die "artisan up failed -- app still in maintenance mode after a fully successful restore (the database and files ARE consistent, only the traffic toggle failed). Fix manually: docker compose exec -T app php artisan up" 3
    MAINTENANCE_ON=false
    STATUS_FINALIZED=true
    write_restore_status "OK" "restored from snapshot ${SNAPSHOT}"
    log "Live restore complete for ${SLUG} (snapshot ${SNAPSHOT}) -- app taken out of maintenance mode"
else
    ###########################################################################
    # Scratch (default) restore -- never touches live state, no maintenance
    # mode, no apply-status write.
    ###########################################################################
    if [ "$SKIP_DB" != true ]; then
        log "Restoring database dump into scratch database '${TARGET_DB}' (tenant's own mysql container, not the live ${DB_DATABASE})..."
        if ! docker compose "${COMPOSE_ARGS[@]}" exec -T mysql mysql -uroot -p"${DB_ROOT_PASSWORD}" \
                -e "DROP DATABASE IF EXISTS \`${TARGET_DB}\`; CREATE DATABASE \`${TARGET_DB}\`;" 2>>"$LOG_FILE"; then
            log "ERROR: could not (re)create scratch database ${TARGET_DB}"
            RESTORE_FAILED=true
        elif ! docker compose "${COMPOSE_ARGS[@]}" exec -T mysql mysql -uroot -p"${DB_ROOT_PASSWORD}" \
                "${TARGET_DB}" <"$DUMP" 2>>"$LOG_FILE"; then
            log "ERROR: loading dump into ${TARGET_DB} failed"
            RESTORE_FAILED=true
        else
            TABLE_COUNT="$(docker compose "${COMPOSE_ARGS[@]}" exec -T mysql mysql -uroot -p"${DB_ROOT_PASSWORD}" \
                -N -e 'SHOW TABLES' "${TARGET_DB}" 2>>"$LOG_FILE" | wc -l | tr -d ' ')" || TABLE_COUNT=0
            if [ "$TABLE_COUNT" -gt 0 ]; then
                log "Restored ${TABLE_COUNT} table(s) into scratch database ${TARGET_DB} -- inspect it directly, it is NOT used by the running app. Drop it when done: DROP DATABASE \`${TARGET_DB}\`;"
            else
                log "ERROR: ${TARGET_DB} has zero tables after load -- restore did not actually populate data"
                RESTORE_FAILED=true
            fi
        fi
    fi

    if [ "$SKIP_FILES" != true ]; then
        log "Restoring storage-app-public to ${FILES_DIR}/storage-app-public (host directory, NOT the live volume)..."
        if restore_files_preview "$PUBLIC_PATH" "$PUBLIC_STRIP" "${FILES_DIR}/storage-app-public"; then
            log "storage-app-public extracted to ${FILES_DIR}/storage-app-public"
        else
            RESTORE_FAILED=true
        fi
        log "Restoring storage-app-private to ${FILES_DIR}/storage-app-private (host directory, NOT the live volume)..."
        if restore_files_preview "$PRIVATE_PATH" "$PRIVATE_STRIP" "${FILES_DIR}/storage-app-private"; then
            log "storage-app-private extracted to ${FILES_DIR}/storage-app-private"
        else
            RESTORE_FAILED=true
        fi
    fi

    if [ "$RESTORE_FAILED" = true ]; then
        die "restore for ${SLUG} finished with at least one failed step -- see ${LOG_FILE}" 3
    fi
fi

log "Restore complete for ${SLUG} (snapshot ${SNAPSHOT})"
[ "$RESTORE_LIVE" = true ] || {
    [ "$SKIP_DB" = true ] || log "  scratch database: ${TARGET_DB}"
    [ "$SKIP_FILES" = true ] || log "  files: ${FILES_DIR}"
}
exit 0
