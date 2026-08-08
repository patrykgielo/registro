#!/bin/bash
###############################################################################
# Tenant-stack reconciler audit -- `check`.
#
# Same convention as scripts/cc-doctor.sh: a deterministic pass/fail list,
# silent when clean, loud and specific when not. Nothing here is a judgement
# call -- every assertion below is something that was, at some point while
# designing tasks 2/4/5/6 of the stack-per-tenant epic, caught drifting
# silently in a code review. This script exists so the NEXT drift is caught
# by a cron job instead of the next review.
#
# Usage:
#   scripts/server/tenant-check.sh            # all tenants under /opt/stacks
#   scripts/server/tenant-check.sh <slug>      # one tenant only
#
# Cron (root, since docker network/port inspection needs no privilege escalation
# here but running alongside the certificate cron is the natural place for it):
#   */30 * * * * root /opt/registro/tenant-check.sh >/dev/null 2>&1
#
# stdout is redirected to /dev/null in that cron line, same as sync-
# certificate.sh's own cron entry -- relying on cron's mail-on-output
# convention would mean findings only surface if this host's local MTA
# actually works, which this project has repeatedly found NOT to be a safe
# assumption (see the VPS-deploy memory notes on SMTP). LOG_FILE below is
# the durable answer instead: written to ONLY when there is a finding, never
# on a clean run, so tailing it stays as quiet as the stdout convention
# itself promises.
#
# Exit: 0 clean, 1 findings.
###############################################################################

set -uo pipefail

readonly STACKS_ROOT="${REGISTRO_STACKS_ROOT:-/opt/stacks}"
readonly LOG_FILE="${REGISTRO_TENANT_CHECK_LOG:-/var/log/registro-tenant-check.log}"
# apply.sh writes RUNNING unconditionally before doing any real work, and
# only its own explicit checkpoints (success, die(), a caught signal)
# overwrite it -- see that script's own comment. SIGKILL is the one death
# none of those checkpoints can catch, which leaves RUNNING stuck forever
# with the timestamp of whatever run died. A real, in-progress apply is
# never running this long -- the full happy path (git clone through backup)
# was timed at well under 3 minutes end to end -- so anything older than
# this is a crashed run, not a live one.
readonly STUCK_RUNNING_THRESHOLD_SECONDS=1800

FINDINGS=()
finding() { FINDINGS+=("$1"); }

###############################################################################
# Discover tenants
###############################################################################

if [ $# -eq 1 ]; then
    SLUGS=("$1")
else
    SLUGS=()
    if [ -d "$STACKS_ROOT" ]; then
        for d in "$STACKS_ROOT"/*/; do
            [ -d "$d" ] || continue
            SLUGS+=("$(basename "$d")")
        done
    fi
fi

###############################################################################
# 1/2/3/4 -- per-tenant checks
###############################################################################

for SLUG in "${SLUGS[@]}"; do
    STACK_DIR="${STACKS_ROOT}/${SLUG}"
    # apply.sh's own bookkeeping (lock/log/status/pre-apply dumps) -- kept out
    # of STACK_DIR itself, which is a live git working tree. See apply.sh's
    # own STATE_DIR comment for why.
    STATE_DIR="${STACKS_ROOT}/.state/${SLUG}"
    ENV_FILE="${STACK_DIR}/.env"
    NGINX_CONTAINER="tenant-${SLUG}-nginx"
    NETWORK="tenant-${SLUG}-edge"

    if [ ! -f "$ENV_FILE" ]; then
        finding "${SLUG}: ${ENV_FILE} missing -- stack directory present but never applied, or corrupted"
        continue
    fi

    CIDR="$(grep -m1 '^TRUSTED_PROXIES_CIDR=' "$ENV_FILE" 2>/dev/null | cut -d= -f2- || true)"

    ###########################################################################
    # TRUSTED_PROXIES_CIDR must never be a wildcard
    #
    # config/trustedproxy.php ships a safe empty default (trust nobody's
    # X-Forwarded-*) -- a unit test can pin THAT. It cannot see what an
    # operator actually wrote into a live .env, which is exactly the value
    # TrustProxies reads at request time. '*' trusts whoever connects
    # directly (this tenant's own nginx, today never anything else) and
    # forwards whatever headers a client sent unfiltered -- see tenant-
    # stack-provisioning.md's own TrustProxies section.
    ###########################################################################
    if [ "$CIDR" = "*" ] || [ "$CIDR" = "**" ]; then
        finding "${SLUG}: TRUSTED_PROXIES_CIDR is '${CIDR}' in ${ENV_FILE} -- trusts every X-Forwarded-* header from whoever connects directly"
    fi

    ###########################################################################
    # nginx must be bound to loopback, never 0.0.0.0/[::] -- asserted against
    # Docker's OWN view (`docker port`), not the .env text. A HTTP_PORT_V4
    # line that READS "127.0.0.1:..." is not proof of anything if the
    # container was started under a different compose file, an old override,
    # or a manual `docker run -p`, none of which this check can see by
    # reading .env alone.
    ###########################################################################
    if docker inspect "$NGINX_CONTAINER" >/dev/null 2>&1; then
        PORT_MAP="$(docker port "$NGINX_CONTAINER" 80/tcp 2>/dev/null || true)"
        if [ -z "$PORT_MAP" ]; then
            finding "${SLUG}: 'docker port ${NGINX_CONTAINER} 80/tcp' returned nothing -- container is not publishing 80 at all"
        elif printf '%s\n' "$PORT_MAP" | grep -qE '^(0\.0\.0\.0|\[::\]):'; then
            finding "${SLUG}: ${NGINX_CONTAINER} is publicly bound (${PORT_MAP//$'\n'/, }) -- must be loopback-only, the edge is the only thing allowed to publish"
        fi
    else
        finding "${SLUG}: container ${NGINX_CONTAINER} does not exist -- stack directory present but never brought up, or removed"
    fi

    ###########################################################################
    # TRUSTED_PROXIES_CIDR must match the tenant-<slug>-edge network's ACTUAL
    # subnet, not whatever was hand-typed or copy-pasted when it was set.
    # These are matched by hand today (edge-stack.md's own runbook) and
    # nothing but this check catches the two drifting.
    ###########################################################################
    if docker network inspect "$NETWORK" >/dev/null 2>&1; then
        ACTUAL_SUBNET="$(docker network inspect "$NETWORK" --format '{{range .IPAM.Config}}{{.Subnet}}{{end}}' 2>/dev/null || true)"
        if [ -z "$CIDR" ]; then
            finding "${SLUG}: network ${NETWORK} exists (${ACTUAL_SUBNET}) but TRUSTED_PROXIES_CIDR is unset in ${ENV_FILE}"
        elif [ "$CIDR" != "$ACTUAL_SUBNET" ]; then
            finding "${SLUG}: TRUSTED_PROXIES_CIDR=${CIDR} in ${ENV_FILE} does not match ${NETWORK}'s actual subnet ${ACTUAL_SUBNET}"
        fi
    elif [ -n "$CIDR" ] && [ "$CIDR" != "*" ] && [ "$CIDR" != "**" ]; then
        finding "${SLUG}: TRUSTED_PROXIES_CIDR=${CIDR} set in ${ENV_FILE} but network ${NETWORK} does not exist"
    fi

    ###########################################################################
    # Exactly one organization row -- raw SQL against mysql directly, NOT
    # Eloquent through the app container. This is deliberately not `php
    # artisan tinker`: organizations.singleton (task 2) already enforces this
    # at the database level going forward, but this check exists to catch
    # anything that bypassed it (a restore, a bug, a future script) with no
    # dependency on the app container being healthy -- only mysql.
    ###########################################################################
    if [ -f "${STACK_DIR}/.env.secrets" ] && docker inspect "tenant-${SLUG}-mysql" >/dev/null 2>&1; then
        DB_ROOT_PASSWORD="$(grep -m1 "^DB_ROOT_PASSWORD=" "${STACK_DIR}/.env.secrets" 2>/dev/null | cut -d= -f2- | tr -d "'\"" || true)"
        DB_DATABASE="$(grep -m1 '^DB_DATABASE=' "$ENV_FILE" 2>/dev/null | cut -d= -f2- || echo registro)"
        if [ -n "$DB_ROOT_PASSWORD" ]; then
            COUNT="$(docker exec "tenant-${SLUG}-mysql" \
                mysql -uroot -p"$DB_ROOT_PASSWORD" -N -e "SELECT COUNT(*) FROM \`${DB_DATABASE}\`.organizations" 2>/dev/null || true)"
            if [ -z "$COUNT" ]; then
                finding "${SLUG}: could not query organizations row count (mysql unreachable or table missing)"
            elif [ "$COUNT" != "1" ]; then
                finding "${SLUG}: organizations table has ${COUNT} row(s), expected exactly 1"
            fi
        fi
    fi

    ###########################################################################
    # Last apply status -- the durable marker apply.sh itself writes. Not a
    # duplicate of the per-tenant checks above: a FAILED status can coexist
    # with an otherwise-clean stack (e.g. the asset gate failed and the
    # operator has not yet re-run apply on the same tag), and this is the one
    # place that surfaces it without reading a log file.
    #
    # RUNNING is only a problem once it's stale -- a live apply legitimately
    # holds that status for its own real duration. Age is read from the
    # status line's own timestamp (field 3), not this check's own clock
    # against the file's mtime, so a status file copied or touched by
    # something else does not produce a false reading.
    #
    # DEGRADED (see apply.sh's backup step) means the release itself is live
    # and healthy but its post-release backup failed -- deliberately a
    # separate finding shape from FAILED, so an operator reads "the site is
    # fine, the backup is not" rather than assuming the deploy itself broke.
    ###########################################################################
    if [ -f "${STATE_DIR}/apply-status" ]; then
        STATUS_LINE="$(cat "${STATE_DIR}/apply-status")"
        case "$STATUS_LINE" in
            FAILED\ *) finding "${SLUG}: last apply FAILED (${STATUS_LINE})" ;;
            DEGRADED\ *) finding "${SLUG}: release is live but DEGRADED (${STATUS_LINE})" ;;
            RUNNING\ *)
                TS="$(awk '{print $3}' <<<"$STATUS_LINE")"
                TS_EPOCH="$(date -u -d "$TS" +%s 2>/dev/null || echo 0)"
                NOW_EPOCH="$(date -u +%s)"
                AGE=$(( NOW_EPOCH - TS_EPOCH ))
                if [ "$TS_EPOCH" -eq 0 ] || [ "$AGE" -gt "$STUCK_RUNNING_THRESHOLD_SECONDS" ]; then
                    finding "${SLUG}: apply-status has read RUNNING since ${TS:-an unparseable timestamp} (${AGE}s) -- likely a crashed/killed run (e.g. SIGKILL, OOM, host crash), not a live one. Check ${STATE_DIR}/apply.log"
                fi
                ;;
        esac
    fi
done

###############################################################################
# 5 -- orphans: docker ps -a (tenant-* containers) vs /opt/stacks/*
###############################################################################

CONTAINER_SLUGS="$(docker ps -a --format '{{.Names}}' 2>/dev/null \
    | grep -E '^tenant-.+-(app|mysql|redis|nginx|horizon|scheduler)$' \
    | sed -E 's/^tenant-(.+)-(app|mysql|redis|nginx|horizon|scheduler)$/\1/' \
    | sort -u)"

STACK_DIR_SLUGS=""
if [ -d "$STACKS_ROOT" ]; then
    # -not -name '.*' excludes apply.sh's own .state/ directory (its
    # bookkeeping, not a tenant) -- unlike the bash glob used for SLUGS
    # above, `find` does not skip dot-entries by default, and without this
    # exclusion every run reported ".state" itself as an orphan directory.
    STACK_DIR_SLUGS="$(find "$STACKS_ROOT" -mindepth 1 -maxdepth 1 -type d -not -name '.*' -printf '%f\n' 2>/dev/null | sort -u)"
fi

while IFS= read -r s; do
    [ -n "$s" ] || continue
    printf '%s\n' "$STACK_DIR_SLUGS" | grep -qx "$s" \
        || finding "orphan containers: tenant-${s}-* running but ${STACKS_ROOT}/${s} does not exist"
done <<<"$CONTAINER_SLUGS"

while IFS= read -r s; do
    [ -n "$s" ] || continue
    printf '%s\n' "$CONTAINER_SLUGS" | grep -qx "$s" \
        || finding "orphan directory: ${STACKS_ROOT}/${s} exists but no tenant-${s}-* container is running"
done <<<"$STACK_DIR_SLUGS"

###############################################################################
# Report
###############################################################################

if [ ${#FINDINGS[@]} -eq 0 ]; then
    exit 0
fi

{
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] tenant-check: ${#FINDINGS[@]} problem(s)"
    for f in "${FINDINGS[@]}"; do echo "  - $f"; done
} | tee -a "$LOG_FILE" 2>/dev/null
exit 1
