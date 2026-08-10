#!/bin/bash
###############################################################################
# Registro tenant-stack reconciler -- apply
#
# Task 6 of the stack-per-tenant epic. A SIBLING verb to scripts/server/
# deploy.sh, not a replacement and not a separate "create this tenant once"
# script: the same command provisions a brand-new tenant AND reconciles an
# existing one on every later release. A script that only ever runs once per
# client (a hypothetical tenant-new.sh) is exercised once, then never again --
# by the time it's needed a second time (the next tenant), the rest of the
# stack has moved and nobody notices until it breaks. `apply` is meant to be
# run for every tenant on every release, so it stays exercised and correct the
# same way deploy.sh does for the legacy stack.
#
# deploy.sh itself is NOT extended with this verb, on purpose -- see its own
# header: APP_DIR/COMPOSE_FILE are hardcoded to the ONE legacy directory, and
# every recovery path (force_clear_flag, the OVERRIDE_FILE it always passes)
# is written for that single, known stack. Threading a second tenant/directory
# concept through those functions would weaken the guarantees documented
# there. Locking, logging and the maintenance-mode safety net below are
# deliberately the SAME shapes deploy.sh uses, so an operator who already
# knows that script does not have to learn new conventions for this one.
#
# Usage (run as the `deploy` user, which already has docker-group access and
# an authenticated `docker login ghcr.io`):
#   /opt/registro/apply.sh <slug> <tag> [hosts] [--name=... --owner-email=...
#       --owner-name=... --industry=...] [--foreground]
#
# [hosts], when omitted, defaults to <slug>.<this machine's domain> -- see the
# APP_DOMAIN read further down for where "this machine's domain" comes from
# and why it is not a second hardcoded pair.
#
# Examples:
#   /opt/registro/apply.sh acme v1.4.0
#   /opt/registro/apply.sh acme v1.4.0 acme.example-client.com \
#       --name="Acme Sp. z o.o." --owner-email=owner@acme.pl --owner-name="Jan Kowalski"
#
# Detaches from the SSH session via `systemd-run --user` (see the block below
# for why --user, not root, and why --collect does not lose the answer to
# "did it work"). `--foreground` skips the relaunch -- used by this script's
# own test suite and by an operator who is already inside a `systemd-run`
# unit or a `screen`/`tmux` session and wants to watch it live.
#
# Exit codes (foreground only -- see the systemd-run block for the detached
# case): 0 success, 1 usage/validation, 2 preconditions failed, 3 apply
# failed, 4 another apply is already running for this slug.
###############################################################################

set -euo pipefail

###############################################################################
# Constants
###############################################################################

# Both overridable via env var, default unchanged (real server paths) --
# exists purely so this script's own guards can be exercised end-to-end in a
# sandbox without a real /opt/stacks or /var/www/registro, per this task's
# own validation requirement. Never set these in production.
readonly STACKS_ROOT="${REGISTRO_STACKS_ROOT:-/opt/stacks}"
# Where the legacy stack's own checkout lives -- the edge (docker-compose.edge.yml,
# docker/nginx/edge/**) is part of THAT repo, not duplicated per tenant. This
# script never touches the legacy stack's own containers or .env; it only reads
# two things from that checkout: its git remote (so a brand-new tenant checkout
# doesn't need a second hardcoded URL/credential) and CERT_DIR from its .env
# (the certificate directory sync-certificate.sh already maintains -- the same
# value edge-tls.local.conf was rendered with, per edge-stack.md).
readonly LEGACY_APP_DIR="${REGISTRO_LEGACY_APP_DIR:-/var/www/registro}"
readonly EDGE_COMPOSE_FILE="docker-compose.edge.yml"
# Generated, NEVER git-tracked -- see the edge-sync step below for why hand-
# editing docker-compose.edge.yml (as edge-stack.md's manual runbook currently
# describes) is actually unsafe once this script exists.
readonly EDGE_TENANTS_OVERRIDE="docker-compose.edge.tenants.override.yml"
readonly COMPOSE_FILE="docker-compose.prod.yml"
# Per-tenant, generated fresh every run -- see "Per-tenant network" below.
# Deliberately NOT docker-compose.legacy-public-ports.override.yml: a tenant
# stack must never publish 80/443 directly, only through the edge.
readonly TENANT_NETWORK_OVERRIDE="docker-compose.tenant-network.override.yml"
readonly BACKUP_ROOT="${REGISTRO_BACKUP_ROOT:-/opt/registro/tenant-backups}"
readonly MIN_FREE_MB=2048
readonly PRE_APPLY_DUMPS_KEEP=5
# Base host port for the FIRST tenant on a box; each subsequent tenant gets
# the next free pair. See allocate_ports() for why a per-tenant stack cannot
# reuse docker-compose.prod.yml's own 127.0.0.1:80 default the moment a SECOND
# tenant lands on the same host.
readonly PORT_BASE=18080
readonly PORT_STEP=10
# Base network for the /29 CIDR allocator -- see allocate_subnet(). Chosen to
# stay clear of Docker's own default bridge pools (172.17-31.0.0/16) and this
# project's dev compose network (172.18.0.0/16, confirmed via `docker network
# inspect app_registro` while writing this script).
readonly SUBNET_BASE="10.90"

log() {
    local line="[$(date '+%Y-%m-%d %H:%M:%S')] $*"
    # LOG_FILE is not known until after argument parsing (it is derived from
    # SLUG). die() is reachable from slug/tag validation, BEFORE that point --
    # found by actually exercising the "invalid tag" guard: with LOG_FILE
    # unset, `>>"$LOG_FILE"` under `set -u` killed the script on the redirect
    # itself, before the second (unconditional stdout) echo ever ran, so the
    # operator saw a bare "unbound variable" instead of the real validation
    # message. ${LOG_FILE:-/dev/null} makes the file-append a no-op until the
    # real path is known, rather than a hard crash that eats the error it was
    # trying to report.
    echo "$line" >>"${LOG_FILE:-/dev/null}" 2>/dev/null || true
    echo "$line" 2>/dev/null || true
}

die() {
    log "ERROR: $1"
    # Same reasoning: STACK_DIR/write_status are also unset this early for a
    # pure argument-validation failure, and write_status's own `mkdir -p`
    # would otherwise be the next thing to trip over an empty path.
    #
    # STATUS_FINALIZED=true here too, not just at the two deliberate OK/
    # DEGRADED writes near the end -- without it, `exit` below still runs
    # the EXIT trap, and on_exit's own fallback would immediately overwrite
    # this call's specific reason ("tenant isolation is inconsistent...",
    # "X-Tenant probe returned...") with its own generic "exit N -- see
    # LOG_FILE", which is a real loss of the one thing an operator actually
    # wants to read first. Plain assignment, safe under `set -u` regardless
    # of whether STATUS_FINALIZED has been declared yet at this call site
    # (early argument-validation failures call die() before it has).
    if [ -n "${STACK_DIR:-}" ]; then
        STATUS_FINALIZED=true
        write_status "FAILED" "$1"
    fi
    exit "${2:-1}"
}

###############################################################################
# Argument parsing
###############################################################################

SLUG=""
TAG=""
HOSTS=""
ORG_NAME=""
OWNER_EMAIL=""
OWNER_NAME=""
INDUSTRY="equipment_rental"
FOREGROUND=false

usage() {
    cat >&2 <<'USAGE'
Usage: apply.sh <slug> <tag> [hosts] [options]

  <slug>   Organization slug, e.g. acme (same rules as ValidOrganizationSlug)
  <tag>    Image tag, vMAJOR.MINOR.PATCH[-suffix] -- same format deploy.sh accepts
  [hosts]  Comma-separated hostnames, no scheme/port. Defaults to
           <slug>.<this machine's domain> -- see APP_DOMAIN below

Options (only consumed on first-time provisioning -- see "Seeds" below):
  --name=NAME                Organization display name
  --owner-email=EMAIL        Owner e-mail address
  --owner-name=NAME          Owner full name
  --industry=VALUE           Industry enum value (default: equipment_rental)
  --foreground               Do not detach via systemd-run; run inline and
                              return this process's own exit code
USAGE
    exit 1
}

[ $# -ge 2 ] || usage
SLUG="$1"; shift
TAG="$1"; shift
if [ $# -gt 0 ] && [[ "$1" != --* ]]; then
    HOSTS="$1"; shift
fi
while [ $# -gt 0 ]; do
    case "$1" in
        --name=*) ORG_NAME="${1#--name=}" ;;
        --owner-email=*) OWNER_EMAIL="${1#--owner-email=}" ;;
        --owner-name=*) OWNER_NAME="${1#--owner-name=}" ;;
        --industry=*) INDUSTRY="${1#--industry=}" ;;
        --foreground) FOREGROUND=true ;;
        *) echo "unknown argument: $1" >&2; usage ;;
    esac
    shift
done

# Anchored on both ends, same reason deploy.sh anchors its own action/tag
# parsing: user-supplied input becomes a directory name (STACK_DIR), a Docker
# container-name prefix, and a network name below -- an unanchored match
# would let a crafted slug escape all three.
[[ "$SLUG" =~ ^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$ ]] \
    || die "invalid slug '$SLUG' -- lowercase alnum and hyphens, 3-63 chars, no leading/trailing hyphen" 1
[[ "$TAG" =~ ^v[0-9]+\.[0-9]+\.[0-9]+(-[A-Za-z0-9.]+)?$ ]] \
    || die "invalid tag '$TAG' -- expected vMAJOR.MINOR.PATCH[-suffix]" 1

if [ -z "$HOSTS" ]; then
    # One machine, one domain -- see the two-machines plan's B1 for why a
    # hardcoded pair here is actively dangerous: the moment a second
    # machine's wildcard DNS exists, defaulting to both domains asks Let's
    # Encrypt to validate a name that resolves to the OTHER box, and it
    # rejects the WHOLE certificate order when any single name fails to
    # validate. The domain is a property of THIS machine, not of this
    # invocation, so it is read the same way CERT_DIR already is further
    # down (the edge-sync step) -- from the legacy checkout's own .env, not
    # a second hardcoded constant to keep in sync by hand. APP_DOMAIN
    # already lives there: deploy-init.sh's create_env_file already prompts
    # for it and writes it, for the legacy stack's own subdomain routing
    # (config/app.php's baseDomain) -- this reuses that exact value rather
    # than inventing a new key, so the live UAT machine (APP_DOMAIN already
    # set to registrolabs.com) needs no new configuration to keep working.
    EDGE_DOMAIN="$(grep -m1 '^APP_DOMAIN=' "${LEGACY_APP_DIR}/.env" 2>/dev/null | cut -d= -f2- || true)"
    [ -n "$EDGE_DOMAIN" ] \
        || die "APP_DOMAIN not set in ${LEGACY_APP_DIR}/.env -- this machine's domain must be configured (run deploy-init.sh on the legacy stack) before a tenant can be provisioned without an explicit [hosts] argument" 2
    HOSTS="${SLUG}.${EDGE_DOMAIN}"
fi
IFS=',' read -r -a HOST_ARRAY <<<"$HOSTS"
PRIMARY_HOST="${HOST_ARRAY[0]}"

readonly SLUG TAG HOSTS PRIMARY_HOST ORG_NAME OWNER_EMAIL OWNER_NAME INDUSTRY FOREGROUND
readonly STACK_DIR="${STACKS_ROOT}/${SLUG}"
# This script's OWN bookkeeping (lock, log, status, pre-apply safety dumps) --
# deliberately a SEPARATE directory from STACK_DIR, not dotfiles dropped
# inside it. STACK_DIR is a live `git` working tree (the tenant's own
# checkout); found by actually running the first-apply path that anything
# written into it before `git clone` (a lock file, a log file) makes the
# directory non-empty, and `git clone` refuses to clone into a non-empty
# directory. Keeping this reconciler's runtime state out of the git tree
# sidesteps that permanently, rather than special-casing the order lock/log
# writes are allowed to happen in relative to the clone.
readonly STATE_DIR="${STACKS_ROOT}/.state/${SLUG}"
readonly TENANT_PREFIX="tenant-${SLUG}"
readonly TENANT_NETWORK="tenant-${SLUG}-edge"
readonly LOG_FILE="${STATE_DIR}/apply.log"
readonly STATUS_FILE="${STATE_DIR}/apply-status"
readonly COMPOSE_ARGS=(-f "$COMPOSE_FILE" -f "$TENANT_NETWORK_OVERRIDE")

write_status() {
    # A durable, structured, systemd-independent answer to "did the last apply
    # succeed" -- see the systemd-run block for why this exists at all: a
    # transient --user unit with --collect stops being queryable once it
    # finishes, and journalctl retention is a policy an operator can change out
    # from under this script. This file is the one thing check.sh (and a human)
    # can trust regardless of either.
    mkdir -p "$STATE_DIR" 2>/dev/null || true
    printf '%s %s %s %s\n' "$1" "$TAG" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "${2:-}" >"$STATUS_FILE" 2>/dev/null || true
}

###############################################################################
# Detach from the SSH session -- systemd-run --user
#
# `--user`, not root: apply.sh does nothing that needs root (docker-group
# membership covers every command it runs), and the SSH key that reaches this
# script is the same `deploy` account deploy.sh already trusts -- there is no
# reason to escalate. `--user` requires the target account's systemd --user
# instance to keep running after the SSH session that started it closes,
# which needs `loginctl enable-linger deploy` run ONCE by root during server
# setup (see the runbook in app/docs/deployment/tenant-apply.md) -- without
# it the user manager, and everything under it, is torn down the moment the
# last session for that user closes, killing this unit along with it.
#
# What answers "did it work" is NOT this process's exit status -- the whole
# point of detaching is that nothing is left waiting on it. It is the STATUS
# FILE (write_status, above), which the operator checks with:
#   cat /opt/stacks/.state/<slug>/apply-status
# `--collect` unloads the transient unit once it finishes (success or not),
# keeping `systemctl --user list-units` clean across dozens of applies over a
# server's lifetime, but that means `systemctl --user status <unit>` stops
# answering anything shortly after completion -- exactly the failure mode
# "read the log and guess" describes. The status file has no such window: it
# is written as literally the last or first thing on every exit path (see the
# EXIT trap below) and never expires. journalctl remains available for the
# FULL narrative (`journalctl --user -u <unit>`) while an operator wants it,
# it is just not the thing this script asks anyone to depend on.
###############################################################################

if [ "$FOREGROUND" != true ] && [ -z "${INVOCATION_ID:-}" ]; then
    UNIT="registro-apply-${SLUG}-$(date +%s)"
    if ! command -v systemd-run >/dev/null 2>&1; then
        echo "systemd-run not found -- re-run with --foreground, or install systemd-run first" >&2
        exit 2
    fi
    systemd-run --user --unit="$UNIT" --collect \
        --description="registro apply ${SLUG} ${TAG}" \
        "$0" "$SLUG" "$TAG" "$HOSTS" \
        ${ORG_NAME:+--name="$ORG_NAME"} \
        ${OWNER_EMAIL:+--owner-email="$OWNER_EMAIL"} \
        ${OWNER_NAME:+--owner-name="$OWNER_NAME"} \
        --industry="$INDUSTRY" --foreground \
        >/dev/null
    cat <<EOF
Detached as systemd --user unit: ${UNIT}

Check the result with:
  cat ${STATE_DIR}/apply-status
Watch it live while it runs:
  journalctl --user -u ${UNIT} -f
EOF
    exit 0
fi

###############################################################################
# Lock -- one apply at a time PER TENANT (different tenants may run
# concurrently; deploy.sh's single global lock would serialize them for no
# reason, since each tenant is an independent stack in an independent
# directory). Lives in STATE_DIR, never inside STACK_DIR -- see that
# variable's own comment for why (git clone + a non-empty target directory).
###############################################################################

mkdir -p "$STATE_DIR"
exec 9>"${STATE_DIR}/apply.lock"
flock -n 9 || die "another apply is already running for ${SLUG}" 4

###############################################################################
# Maintenance-mode safety net AND signal traps -- deliberately set up here,
# as the very first thing after the lock, BEFORE the "RUNNING" status write
# below and before any real work (git clone, .env.secrets, anything).
#
# Found necessary by actually testing signal delivery, not by inspection:
# an earlier version of this script defined these further down, after
# "RUNNING" was already written, git-cloned, and .env.secrets handled. A
# SIGTERM landing in THAT window hit bash's own default disposition instead
# of on_signal below (no trap existed yet to catch it) -- the core
# guarantee (the file can never read OK after being interrupted) still held,
# because "RUNNING" had already been written unconditionally and nothing
# after that point could contradict it without going through an explicit
# checkpoint, but the INFORMATIVE half (an immediate, reasoned FAILED status
# instead of a stuck RUNNING an operator has to wait out check.sh's age
# threshold to notice) did not cover that window. Moving the whole block up
# here closes it: signal handling is armed before there is anything left to
# protect.
###############################################################################

MAINTENANCE_ON=false
KEEP_MAINTENANCE=false
# Set right before the two DELIBERATE, already-decided status writes at the
# very end of this script (OK and DEGRADED) -- both exit non-zero-capable
# paths that on_exit's own fallback below would otherwise treat as generic
# failures and overwrite back to FAILED, which is exactly wrong for
# DEGRADED (exit 5): that status means the release IS live and healthy, only
# the backup failed, and on_exit re-labelling it FAILED would recreate the
# same "operator can't tell a broken release from a healthy one with an
# unbacked-up database" confusion this status exists to remove.
STATUS_FINALIZED=false

force_clear_flag() {
    # Identical reasoning to deploy.sh's own force_clear_flag -- see that
    # file's comment and app/docs/deployment/tenant-compose-stack.md for the
    # full incident. The volume name is COMPUTED (${TENANT_PREFIX}_storage-
    # framework, Compose's own deterministic ${project}_${volume-key} naming,
    # project name = TENANT_PREFIX here since it is this script's own
    # argument, not something to grep out of a possibly-broken .env), never
    # asked of `docker compose`, because every subcommand interpolates the
    # WHOLE file and this file hard-requires APP_KEY/APP_DOMAIN/REDIS_PASSWORD.
    local vol image
    vol="${TENANT_PREFIX}_storage-framework"
    image="ghcr.io/patrykgielo/registro:${TAG}"

    docker volume inspect "$vol" >/dev/null 2>&1 \
        || { log "Volume ${vol} does not exist"; return 1; }

    timeout 60 docker run --rm --entrypoint rm -v "${vol}:/s" "$image" \
        -f /s/maintenance.php /s/down >/dev/null 2>&1 \
        || return 1
    log "Maintenance flag removed from volume ${vol}"
}

clear_maintenance() {
    [ "$MAINTENANCE_ON" = true ] || return 0
    if [ "$KEEP_MAINTENANCE" = true ]; then
        log "Leaving maintenance mode ON deliberately -- see the error above"
        return 0
    fi
    if timeout 60 docker compose "${COMPOSE_ARGS[@]}" exec -T app \
            php artisan up </dev/null >/dev/null 2>&1; then
        log "Maintenance mode cleared"
        MAINTENANCE_ON=false
        return 0
    fi
    if force_clear_flag; then
        MAINTENANCE_ON=false
        return 0
    fi
    log "WARNING: could not clear maintenance mode for ${SLUG}. Investigate manually."
}

on_exit() {
    local rc=$?
    if [ "$rc" -ne 0 ] && [ "$STATUS_FINALIZED" != true ]; then
        clear_maintenance
        write_status "FAILED" "exit ${rc} -- see ${LOG_FILE}"
    fi
    return "$rc"
}

# Best-effort fast path, same status as deploy.sh's own equivalent block:
# SIGKILL cannot be caught at all, so correctness cannot depend on this
# running -- that is what the unconditional "RUNNING" write above is for.
# What THIS gets an operator, when it does run, is a FAILED (not a stuck
# RUNNING) status immediately, with a reason, instead of waiting on check.sh's
# stuck-RUNNING age threshold to notice.
#
# Disarm every trap FIRST, before doing anything else -- same reason
# deploy.sh's own on_signal does: without it, the `exit 3` at the bottom of
# this handler re-triggers the EXIT trap (double-running clear_maintenance/
# write_status) or a second signal re-enters this handler mid-write.
on_signal() {
    trap '' HUP INT TERM PIPE EXIT
    log "Received SIG${1} -- writing FAILED status and cleaning up"
    clear_maintenance
    write_status "FAILED" "killed by SIG${1}"
    exit 3
}
trap on_exit EXIT
trap '' PIPE
for sig in HUP INT TERM; do
    # shellcheck disable=SC2064
    trap "on_signal ${sig}" "$sig"
done

# Written BEFORE any real work, now that traps are armed -- and this is the
# load-bearing part of the whole status-file design, not the traps above.
# Reproduced directly: a stale "OK" from a PREVIOUS successful run was still
# readable as "OK" after a SIGTERM killed a LATER run mid-flight, because
# on_exit's `$?` can read 0 even on a signal death (bash sees the exit
# status of the last command that ran, not the signal, when the signal
# lands between commands) -- so a trap alone cannot be trusted to always
# convert a killed run into something other than "OK". Overwriting to
# RUNNING here, unconditionally, on every invocation, means the ONLY way
# this run's `apply-status` can still say OK afterwards is if the explicit
# write_status "OK" at the very end of this script actually ran -- an
# interrupted run, however it dies (SIGTERM, SIGKILL, a host crash), can
# never be misread as success, because "RUNNING" is what's left behind by
# default, not "OK" left over from before.
write_status "RUNNING" ""

log "=== apply ${SLUG} ${TAG} ==="

###############################################################################
# Step: preconditions -- dig + disk space
#
# First, before ANYTHING else touches disk or Docker, so a box that is not
# ready fails in milliseconds instead of after a partial clone/pull. Re-run is
# free: nothing has been created yet on this path.
###############################################################################

check_dns() {
    command -v dig >/dev/null 2>&1 \
        || die "dig not found -- install dnsutils (see the runbook)" 2
    local host resolved
    for host in "${HOST_ARRAY[@]}"; do
        resolved="$(dig +short "$host" 2>/dev/null | grep -E '^[0-9.]+$|^[0-9a-fA-F:]+$' || true)"
        [ -n "$resolved" ] \
            || die "DNS for '${host}' does not resolve -- point it at this host before attaching it to the edge" 2
    done
    log "DNS: all ${#HOST_ARRAY[@]} hostname(s) resolve"
}

check_disk_space() {
    local docker_root avail_opt avail_docker
    mkdir -p "$STACKS_ROOT"
    avail_opt="$(df --output=avail -m "$STACKS_ROOT" 2>/dev/null | tail -1 | tr -d ' ')"
    [ -n "$avail_opt" ] || die "could not read free space on ${STACKS_ROOT}" 2
    [ "$avail_opt" -ge "$MIN_FREE_MB" ] \
        || die "only ${avail_opt}MB free on ${STACKS_ROOT}, need ${MIN_FREE_MB}MB" 2

    docker_root="$(docker info --format '{{.DockerRootDir}}' 2>/dev/null || true)"
    if [ -n "$docker_root" ]; then
        avail_docker="$(df --output=avail -m "$docker_root" 2>/dev/null | tail -1 | tr -d ' ')"
        if [ -n "$avail_docker" ] && [ "$avail_docker" -lt "$MIN_FREE_MB" ]; then
            die "only ${avail_docker}MB free on ${docker_root} (Docker's data root), need ${MIN_FREE_MB}MB" 2
        fi
    fi
    log "Disk space: ${avail_opt}MB free on ${STACKS_ROOT}"
}

check_dns
check_disk_space

###############################################################################
# Step: repository checkout
#
# One checkout PER TENANT (${STACK_DIR} itself), not a shared one. A shared
# checkout would mean this tenant's `git checkout --force tags/X` mutates the
# working tree every OTHER co-located tenant's compose file/nginx templates
# also read from mid-apply -- the exact hazard this project already
# encountered with the legacy stack being the only thing pointed at APP_DIR
# (see deploy.sh's own header). Cloned from the LEGACY checkout's own origin
# so this script needs no separate hardcoded URL/credential to maintain.
###############################################################################

if [ ! -d "${STACK_DIR}/.git" ]; then
    [ -d "${LEGACY_APP_DIR}/.git" ] \
        || die "${LEGACY_APP_DIR} is not a git checkout -- cannot determine origin to clone from" 2
    ORIGIN="$(git -C "$LEGACY_APP_DIR" remote get-url origin)"
    log "Cloning ${ORIGIN} into ${STACK_DIR}..."
    git clone --quiet "$ORIGIN" "$STACK_DIR" || die "git clone failed" 3
fi

cd "$STACK_DIR"
log "Fetching tags..."
git fetch --tags --prune origin || die "git fetch failed" 3
git rev-parse -q --verify "refs/tags/${TAG}" >/dev/null \
    || die "tag ${TAG} does not exist on origin" 3
log "Checking out ${TAG}..."
git checkout --quiet --force "tags/${TAG}" || die "git checkout failed" 3

###############################################################################
# Step: render this tenant's nginx vhost
#
# Same rendering convention as CERT_DOMAIN in app.prod-tls.conf and TENANT_
# SLUG_PLACEHOLDER's own header already documents: sed into a gitignored
# .local.conf, verify the placeholder is actually gone (sed exits 0 on zero
# substitutions), never edit the tracked template in place.
###############################################################################

NGINX_TEMPLATE="docker/nginx/production/app.tenant.conf"
NGINX_OUT="docker/nginx/production/app.tenant.local.conf"
[ -f "$NGINX_TEMPLATE" ] || die "${NGINX_TEMPLATE} missing at ${TAG}" 3
sed "s/TENANT_SLUG_PLACEHOLDER/${SLUG}/g" "$NGINX_TEMPLATE" >"${NGINX_OUT}.tmp" \
    || die "failed to render ${NGINX_OUT}" 3
if grep -q 'TENANT_SLUG_PLACEHOLDER' "${NGINX_OUT}.tmp"; then
    rm -f "${NGINX_OUT}.tmp"
    die "rendered nginx config still contains TENANT_SLUG_PLACEHOLDER -- template changed shape at ${TAG}" 3
fi
mv -f "${NGINX_OUT}.tmp" "$NGINX_OUT" || die "failed to install ${NGINX_OUT}" 3

###############################################################################
# Step: .env.secrets -- written ONLY when absent, refuses to overwrite
#
# APP_KEY encrypts audit_logs (see AuditLog's own cast). Regenerating it
# silently makes every existing encrypted record permanently undecryptable --
# there is no re-encryption path, because the OLD key is gone the moment this
# file would be overwritten. DB_PASSWORD/DB_ROOT_PASSWORD/REDIS_PASSWORD are
# grouped into the same file for the same reason as APP_KEY, not because they
# have the same failure mode: once written they are what the database and
# Redis actually hold, and changing them here without also changing them
# there locks this stack out of its own data.
#
# `set -o noclobber` is a second, structural guard on top of the if/else
# below: even a future logic bug that reaches the write branch while the file
# exists gets a hard shell error on `>`, not a silent overwrite.
###############################################################################

SECRETS_FILE="${STACK_DIR}/.env.secrets"

if [ -f "$SECRETS_FILE" ]; then
    log ".env.secrets already present -- using existing secrets (never regenerated)"
else
    log "Generating .env.secrets (first apply for ${SLUG})..."
    (
        set -o noclobber
        {
            printf "APP_KEY='base64:%s'\n" "$(openssl rand -base64 32)"
            printf "DB_PASSWORD='%s'\n" "$(openssl rand -base64 24)"
            printf "DB_ROOT_PASSWORD='%s'\n" "$(openssl rand -base64 24)"
            printf "REDIS_PASSWORD='%s'\n" "$(openssl rand -base64 24)"
        } >"$SECRETS_FILE"
    ) || die "failed to write ${SECRETS_FILE} (noclobber tripped -- it already existed?)" 2
    chmod 600 "$SECRETS_FILE"
fi

set -a
# shellcheck disable=SC1090
source "$SECRETS_FILE"
set +a
# `:?` alone only rejects EMPTY -- it would accept `APP_KEY='base64:'`, which
# is exactly what a silently-failed `openssl rand` above would have produced
# (command substitution failing inside `printf "...%s\n" "$(openssl ...)"`
# does not itself trip `set -e`, since printf's own exit status is what gets
# checked, and printf still "succeeds" with an empty substituted value).
# Validated on EVERY run, not just the generation branch, so a pre-existing
# but corrupted file (hand-edited, a bad restore) is caught here too --
# this is, deliberately, the one file this script refuses to regenerate, so
# catching a malformed value is the only chance to fail loudly instead of
# handing Laravel a garbage encryption key.
: "${APP_KEY:?APP_KEY missing from .env.secrets}"
[[ "$APP_KEY" =~ ^base64:[A-Za-z0-9+/=]{40,}$ ]] \
    || die "APP_KEY in .env.secrets is malformed (expected 'base64:' followed by 40+ base64 characters) -- do NOT regenerate this file (see its own header: it would make every existing audit_logs record permanently undecryptable). Restore .env.secrets from a backup, or replace it deliberately by hand with that consequence explicitly accepted." 2
: "${DB_PASSWORD:?DB_PASSWORD missing from .env.secrets}"
[ "${#DB_PASSWORD}" -ge 20 ] || die "DB_PASSWORD in .env.secrets is only ${#DB_PASSWORD} chars -- looks corrupted, not merely short" 2
: "${DB_ROOT_PASSWORD:?DB_ROOT_PASSWORD missing from .env.secrets}"
[ "${#DB_ROOT_PASSWORD}" -ge 20 ] || die "DB_ROOT_PASSWORD in .env.secrets is only ${#DB_ROOT_PASSWORD} chars -- looks corrupted, not merely short" 2
: "${REDIS_PASSWORD:?REDIS_PASSWORD missing from .env.secrets}"
[ "${#REDIS_PASSWORD}" -ge 20 ] || die "REDIS_PASSWORD in .env.secrets is only ${#REDIS_PASSWORD} chars -- looks corrupted, not merely short" 2

###############################################################################
# Step: per-tenant port + subnet allocation
#
# Needed because docker-compose.prod.yml's own HTTP_PORT_V4/V6 default
# (127.0.0.1:80/443) is correct for exactly ONE tenant stack per host. The
# moment check.sh's own job (auditing every /opt/stacks/*) makes sense at all,
# more than one tenant can share a box, and a second stack's nginx would fail
# to bind the same loopback:80 the first one already holds. Allocated once,
# on first apply, and left untouched afterwards -- read back from .env when
# present rather than recomputed, so a restart never reassigns a port a
# firewall rule or monitoring check already depends on.
###############################################################################

allocate_ports() {
    local existing candidate used=()
    for existing in "${STACKS_ROOT}"/*/.env; do
        [ -f "$existing" ] || continue
        [ "$existing" = "${STACK_DIR}/.env" ] && continue
        local p
        p="$(grep -m1 '^HTTP_PORT_V4=' "$existing" 2>/dev/null | grep -oE ':[0-9]+:' | tr -d ':' || true)"
        [ -n "$p" ] && used+=("$p")
    done
    candidate=$PORT_BASE
    while :; do
        if [[ ! " ${used[*]:-} " == *" ${candidate} "* ]] \
                && ! ss -ltn 2>/dev/null | grep -q ":${candidate} "; then
            break
        fi
        candidate=$((candidate + PORT_STEP))
    done
    echo "$candidate"
}

allocate_subnet() {
    # /29 per tenant: only the edge and this tenant's own nginx ever join it
    # (edge-stack.md), so 8 addresses (6 usable) is deliberately generous, not
    # tight -- an operator hand-picking CIDRs later has room to insert one
    # without renumbering everything else.
    local octet candidate used=() net
    for net in $(docker network ls --filter "name=^tenant-.*-edge$" --format '{{.Name}}' 2>/dev/null); do
        [ "$net" = "$TENANT_NETWORK" ] && continue
        local s
        s="$(docker network inspect "$net" --format '{{range .IPAM.Config}}{{.Subnet}}{{end}}' 2>/dev/null || true)"
        [ -n "$s" ] && used+=("$s")
    done
    for octet in $(seq 2 8 250); do
        candidate="${SUBNET_BASE}.${octet}.0/29"
        if [[ ! " ${used[*]:-} " == *" ${candidate} "* ]]; then
            echo "$candidate"
            return 0
        fi
    done
    return 1
}

# Concurrent provisioning of two DIFFERENT tenants is deliberately
# SUPPORTED, not refused -- an operator onboarding several clients around
# the same time should not have to serialize whole apply runs against each
# other just because of a shared host resource. That resource (the port
# number itself) is what actually needs serializing, not the runs -- so this
# is a short, GLOBAL (cross-tenant, cross-slug) lock held only around
# scan-then-reserve, not around this tenant's own STATE_DIR/apply.lock
# (per-tenant, held for the whole run). Found necessary by inspection, not
# by reproducing a real collision: allocate_ports() only READS other
# tenants' .env files, and this tenant's OWN .env is not written until the
# "reconcile .env" step further down -- between those two points, two
# tenants provisioned at the same moment scan an identical "used" set and
# can pick the identical candidate, and unlike allocate_subnet() below
# (nothing persists early, so a retry rescans and picks something else) a
# picked port DOES get persisted, at "reconcile .env" -- so the LOSING
# tenant's every future apply would keep reading its own colliding choice
# back out of .env (see the `if [ -f .env ] ...` branch above) instead of
# ever reallocating, stuck until an operator hand-edits the file. Blocking
# (not -n) on purpose: this critical section is microseconds long, worth a
# short wait rather than failing a concurrent apply outright.
mkdir -p "${STACKS_ROOT}/.state"
exec 8>"${STACKS_ROOT}/.state/.port-allocation.lock"
flock 8

if [ -f "${STACK_DIR}/.env" ] && grep -q '^HTTP_PORT_V4=' "${STACK_DIR}/.env"; then
    HTTP_PORT="$(grep -m1 '^HTTP_PORT_V4=' "${STACK_DIR}/.env" | grep -oE ':[0-9]+:' | tr -d ':')"
else
    HTTP_PORT="$(allocate_ports)"
    # Reserved immediately, INSIDE the same lock, before it is released --
    # a placeholder line is enough to make the NEXT concurrent
    # allocate_ports() scan (which only greps for this one key) see this
    # port as taken, even though the rest of this tenant's real .env is not
    # written until later in this same run. That later write includes this
    # exact line again with the same $HTTP_PORT value, so this is a
    # reservation, not a second source of truth -- nothing is lost or
    # overridden by the real regeneration superseding it.
    if ! grep -q '^HTTP_PORT_V4=' "${STACK_DIR}/.env" 2>/dev/null; then
        echo "HTTP_PORT_V4=127.0.0.1:${HTTP_PORT}:80" >>"${STACK_DIR}/.env"
    fi
fi
flock -u 8
HTTPS_PORT=$((HTTP_PORT + 1))

if docker network inspect "$TENANT_NETWORK" >/dev/null 2>&1; then
    SUBNET="$(docker network inspect "$TENANT_NETWORK" --format '{{range .IPAM.Config}}{{.Subnet}}{{end}}')"
    log "Network ${TENANT_NETWORK} already exists (${SUBNET})"
else
    SUBNET="$(allocate_subnet)" || die "no free /29 subnet found in ${SUBNET_BASE}.0.0/16" 2
    log "Creating network ${TENANT_NETWORK} (${SUBNET})..."
    docker network create --subnet "$SUBNET" "$TENANT_NETWORK" >/dev/null \
        || die "docker network create failed for ${TENANT_NETWORK}" 3
fi

###############################################################################
# Step: reconcile .env
#
# Fully regenerated every run (this file is tool-owned, not hand-maintained --
# see the runbook for where operator-supplied values like MAIL_USERNAME go).
# REGISTRO_VERSION is the one exception: its ON-DISK value is preserved
# through this write and only updated at the very end of the whole run (see
# the final step) -- everything between here and there operates on ${TAG} via
# the exported shell variable below, which wins over the .env file in Compose
# interpolation, exactly the mechanism deploy.sh's own REGISTRO_VERSION
# comment documents.
###############################################################################

PREVIOUS_VERSION=""
[ -f "${STACK_DIR}/.env" ] && PREVIOUS_VERSION="$(grep -m1 '^REGISTRO_VERSION=' "${STACK_DIR}/.env" 2>/dev/null | cut -d= -f2- || true)"

cat >"${STACK_DIR}/.env" <<ENV
# Generated by apply.sh -- DO NOT hand-edit THIS file, it is overwritten on
# every apply. Secrets live in .env.secrets instead (never regenerated).
# Business-specific credentials (MAIL_*, SMSAPI_*, GOOGLE_MAPS_*, P24_*) are
# left out of this generated block on purpose -- apply.sh is an
# infrastructure reconciler and has no way to know a tenant's own mail/SMS/
# payment credentials. Put those in .env.bak-manual instead (KEY=value, one
# per line) -- it is appended verbatim below and survives every future apply
# untouched; see the block right after this heredoc.
APP_NAME=Registro
APP_URL=https://${PRIMARY_HOST}
APP_DOMAIN=${PRIMARY_HOST}
TENANT_SLUG=${SLUG}
TENANT_HOSTS=${HOSTS}
TRUSTED_PROXIES_CIDR=${SUBNET}
TENANT_PREFIX=${TENANT_PREFIX}
NGINX_CONF=app.tenant.local.conf
HTTP_PORT_V4=127.0.0.1:${HTTP_PORT}:80
HTTP_PORT_V6=[::1]:${HTTP_PORT}:80
HTTPS_PORT_V4=127.0.0.1:${HTTPS_PORT}:443
HTTPS_PORT_V6=[::1]:${HTTPS_PORT}:443
DB_DATABASE=registro
DB_USERNAME=registro
REGISTRO_VERSION=${PREVIOUS_VERSION}
ENV

# Business credentials the operator may have already filled in by hand on a
# previous apply -- carried forward verbatim rather than reset to blank every
# run, which is the one deliberate exception to "fully regenerated every run"
# above: apply.sh cannot originate these values, so overwriting them with
# blanks on every release would be actively destructive, not just redundant.
if [ -f "${STACK_DIR}/.env.bak-manual" ]; then
    cat "${STACK_DIR}/.env.bak-manual" >>"${STACK_DIR}/.env"
fi

# Per-tenant network attachment, generated -- NEVER hand-edited into
# docker-compose.prod.yml itself. That file is git-tracked and every apply
# runs `git checkout --force` against it a few lines above; an in-place edit
# there would be silently reverted on the very next apply. Compose's default
# merge behaviour APPENDS list fields like this nginx `networks:` list across
# `-f` files (confirmed in tenant-compose-stack.md while investigating the
# unrelated `ports:` conflict) and UNIONS top-level `networks:` map keys, so a
# second file needs no `!override` tag here -- it only needs to exist.
cat >"${STACK_DIR}/${TENANT_NETWORK_OVERRIDE}" <<OVERRIDE
networks:
  ${TENANT_NETWORK}:
    external: true
services:
  nginx:
    networks:
      - ${TENANT_NETWORK}
OVERRIDE

export REGISTRO_VERSION="$TAG"

###############################################################################
# Step: pre-dump -- a fast, LOCAL safety net immediately before migration
#
# Deliberately separate from the durable restic backup near the end of this
# run: this dump exists purely so a bad migration can be undone in seconds
# without needing restic's restore machinery, and it captures the database as
# it stood on the OLD code, seconds before the new code's migrations run
# against it. Skipped on a brand-new stack's first apply -- there is no
# "before" yet.
###############################################################################

dump_database() {
    # Shared by the pre-dump here and the durable backup step near the end.
    # `--dump-date`/`--comments` are mysqldump's OWN defaults (not passed
    # explicitly) -- disabling either would remove the trailing
    # "-- Dump completed on ..." line this function's caller asserts on.
    local out="$1"
    docker compose "${COMPOSE_ARGS[@]}" exec -T mysql mysqldump \
        -uroot -p"${DB_ROOT_PASSWORD}" \
        --single-transaction --routines --triggers --events \
        "${DB_DATABASE:-registro}" >"$out" 2>>"$LOG_FILE"
    # Asserted on CONTENT, not exit status -- same reasoning as deploy.sh's
    # own CERT_DOMAIN-after-sed check: a killed or truncated mysqldump can
    # still leave `docker compose exec` reporting 0 if the failure happened
    # inside the container's own stdout pipe rather than its exit code.
    tail -5 "$out" | grep -q '^-- Dump completed' \
        || { rm -f "$out"; return 1; }
}

if [ -n "$(docker compose "${COMPOSE_ARGS[@]}" ps -q mysql 2>/dev/null)" ] \
        && docker compose "${COMPOSE_ARGS[@]}" exec -T mysql mysqladmin ping -h localhost --silent </dev/null 2>/dev/null; then
    mkdir -p "${STATE_DIR}/pre-apply-dumps"
    PRE_DUMP="${STATE_DIR}/pre-apply-dumps/pre-apply-$(date +%Y%m%d%H%M%S).sql"
    log "Pre-migration safety dump..."
    dump_database "$PRE_DUMP" || die "pre-migration dump failed or was truncated -- refusing to migrate without one" 3
    log "Pre-migration dump: ${PRE_DUMP}"
    # Keep the last N; a stranded stack should not fill its own disk with
    # safety nets nobody is reading months later.
    find "${STATE_DIR}/pre-apply-dumps" -maxdepth 1 -name '*.sql' -printf '%T@ %p\n' 2>/dev/null \
        | sort -rn | tail -n +$((PRE_APPLY_DUMPS_KEEP + 1)) | cut -d' ' -f2- | xargs -r rm -f
else
    log "No running mysql for ${SLUG} yet -- skipping pre-dump (first apply)"
fi

###############################################################################
# Step: pull + migrate from a one-off container on the NEW image, while any
# currently-serving containers stay on the OLD one.
#
# `run --rm --no-deps` starts a throwaway `app` container from the image just
# pulled, attached to the same networks (registro-prod) the real `app` service
# uses, WITHOUT starting/recreating mysql, redis, or the currently-serving app/
# nginx/horizon/scheduler containers. `--no-deps` only skips starting
# dependency SERVICES, not the container's own network attachments, so it can
# still reach mysql/redis on registro-prod. This bounds the schema-change
# window to the migration itself rather than the whole `up -d`/healthcheck-wait
# cycle deploy.sh needs, at the cost of a brief window where OLD code and NEW
# schema coexist -- acceptable for additive migrations, which is what this
# project's own migrations:check-rollback convention already pushes toward.
# mysql/redis are brought up first (idempotent -- a no-op if already healthy)
# because a brand-new stack has neither yet.
###############################################################################

log "Starting mysql/redis..."
docker compose "${COMPOSE_ARGS[@]}" up -d mysql redis

log "Waiting for MySQL..."
timeout 120 bash -c "until docker compose ${COMPOSE_ARGS[*]} exec -T mysql \
    mysqladmin ping -h localhost --silent </dev/null; do sleep 2; done" \
    || die "MySQL did not become ready" 3

log "Waiting for Redis..."
timeout 60 bash -c "until docker compose ${COMPOSE_ARGS[*]} exec -T redis \
    redis-cli -a \"\$REDIS_PASSWORD\" ping </dev/null 2>/dev/null | grep -q PONG; do sleep 2; done" \
    || die "Redis did not become ready" 3

log "Pulling ${TAG}..."
docker compose "${COMPOSE_ARGS[@]}" pull app horizon scheduler || die "docker pull failed" 3

# Maintenance mode on the OLD serving app, if one exists -- protects live
# traffic during the migration DDL even though the migration itself runs in a
# separate throwaway container. Same over-approximation as deploy.sh: assume
# the flag may be set once `down` has been attempted, regardless of its exit
# status, because `down` can leave the file on disk even when it reports
# failure (ssh/timeout mid-command).
if [ -n "$(docker compose "${COMPOSE_ARGS[@]}" ps -q app 2>/dev/null)" ]; then
    MAINTENANCE_ON=true
    docker compose "${COMPOSE_ARGS[@]}" exec -T app php artisan down --retry=15 </dev/null \
        || log "artisan down reported failure -- assuming the flag may be set anyway"
else
    log "No app container yet for ${SLUG} -- skipping maintenance mode (first bring-up)"
fi

log "Running migrations from a one-off container on ${TAG}..."
if ! docker compose "${COMPOSE_ARGS[@]}" run --rm --no-deps -T app php artisan migrate --force </dev/null; then
    KEEP_MAINTENANCE=true
    die "migrations failed -- ${SLUG} left in maintenance mode on ${TAG}. Restore ${PRE_DUMP:-<no pre-dump available>} if needed, then re-run apply on the previous tag." 3
fi

###############################################################################
# Step: up -d -- recreate all six services on the new image
###############################################################################

log "Starting containers..."
docker compose "${COMPOSE_ARGS[@]}" up -d --remove-orphans

log "Waiting for MySQL/Redis after recreation..."
timeout 120 bash -c "until docker compose ${COMPOSE_ARGS[*]} exec -T mysql \
    mysqladmin ping -h localhost --silent </dev/null; do sleep 2; done" \
    || die "MySQL did not become ready after up -d" 3
timeout 60 bash -c "until docker compose ${COMPOSE_ARGS[*]} exec -T redis \
    redis-cli -a \"\$REDIS_PASSWORD\" ping </dev/null 2>/dev/null | grep -q PONG; do sleep 2; done" \
    || die "Redis did not become ready after up -d" 3

log "Rebuilding caches..."
docker compose "${COMPOSE_ARGS[@]}" exec -T app php artisan optimize:clear </dev/null
docker compose "${COMPOSE_ARGS[@]}" exec -T app php artisan filament:optimize-clear </dev/null
docker compose "${COMPOSE_ARGS[@]}" exec -T app php artisan config:cache </dev/null
docker compose "${COMPOSE_ARGS[@]}" exec -T app php artisan route:cache </dev/null
docker compose "${COMPOSE_ARGS[@]}" exec -T app php artisan view:cache </dev/null
docker compose "${COMPOSE_ARGS[@]}" exec -T app php artisan event:cache </dev/null
docker compose "${COMPOSE_ARGS[@]}" exec -T app php artisan filament:optimize </dev/null

log "Restarting Horizon..."
docker compose "${COMPOSE_ARGS[@]}" restart horizon

###############################################################################
# Step: seeds -- registro:tenant-provisioned before deciding whether to run
# registro:tenant-provision (tenant-stack-provisioning.md's own documented
# gap this script exists to close).
#
# `--assert` is the only thing that notices when TENANT_SLUG, the singleton
# lock and the provisioning marker have gone out of sync -- see that
# command's own docstring. It is NOT, on its own, a clean "provisioned and
# consistent" gate: found by actually running it against a genuinely fresh
# stack -- ProvisioningStatusCommand::handle() runs assertConsistent() first,
# but even when that PASSES it falls through to the ordinary isProvisioned()
# check and returns FAILURE, printing the bare line "not-provisioned", for
# any stack that simply has not been provisioned yet. Treating exit-non-zero
# as "inconsistent, die" would have made every first-time apply fail here,
# always, on a perfectly healthy new stack. The two failure shapes are told
# apart by the OUTPUT, not the exit code: assertConsistent()'s own failures
# print one of four specific error() messages (see that command's source);
# only the benign case prints exactly "not-provisioned".
###############################################################################

log "Checking provisioning status..."
# `VAR="$(cmd)"` on its own line is NOT a conditional as far as `set -e` is
# concerned -- found by actually running this against a genuinely fresh
# stack, where the command's exit-1 "not-provisioned" case is the EXPECTED
# outcome, not an error: `set -e` killed the script right here, silently,
# before PROVISION_RC was ever read, on every first apply. Wrapping the
# assignment in `|| PROVISION_RC=$?` makes the compound statement's own exit
# status 0 regardless of which branch ran, which is what `set -e` needs to
# not treat this as a failure.
PROVISION_RC=0
PROVISION_STATUS="$(docker compose "${COMPOSE_ARGS[@]}" exec -T app php artisan registro:tenant-provisioned --assert </dev/null 2>&1)" \
    || PROVISION_RC=$?
if [ "$PROVISION_RC" -eq 0 ]; then
    log "Already provisioned and consistent -- skipping registro:tenant-provision"
elif ! printf '%s' "$PROVISION_STATUS" | grep -q '^not-provisioned$'; then
    log "$PROVISION_STATUS"
    # KEEP_MAINTENANCE=true: this specific failure means TENANT_SLUG, the
    # singleton lock and the provisioning marker disagree with each other --
    # exactly the shape that, per tenant-stack-provisioning.md's own
    # `--assert` docstring, can mean "registration and /platform are live
    # here" or "this container is pointed at another tenant's database".
    # Both are a live-traffic-may-reach-the-wrong-place risk, not a
    # readiness/availability one. Releasing maintenance here would put a
    # stack with unverified tenant isolation back in front of real visitors.
    KEEP_MAINTENANCE=true
    die "registro:tenant-provisioned --assert failed -- tenant isolation is inconsistent, investigate before serving traffic" 3
elif [ -n "$OWNER_EMAIL" ] && [ -n "$OWNER_NAME" ] && [ -n "$ORG_NAME" ]; then
    log "Provisioning organization..."
    docker compose "${COMPOSE_ARGS[@]}" exec -T app php artisan registro:tenant-provision \
        --slug="$SLUG" --name="$ORG_NAME" --industry="$INDUSTRY" \
        --owner-email="$OWNER_EMAIL" --owner-name="$OWNER_NAME" </dev/null | tee -a "$LOG_FILE"
    # The password-setup link above is now in BOTH stdout and this run's log
    # file. The TTL is User::PASSWORD_SETUP_TTL_HOURS -- 24 hours since the
    # task-7 change, not the 30 minutes this comment used to claim. Longer
    # exposure widens exactly this residual: the log is only as private as this
    # machine's /var/log. Treat it the same way -- readable by root/deploy
    # only (this log file is not world-readable, see the runbook), and hand
    # it to the client over a channel that isn't this log.
else
    log "Not yet provisioned and no --name/--owner-email/--owner-name given -- infra is up, organization is not. Re-run apply with those three options once you have the client's details."
fi

###############################################################################
# Step: edge-sync
#
# Runs from the LEGACY checkout, not this tenant's -- the edge stack
# (docker-compose.edge.yml, docker/nginx/edge/**) is not duplicated per
# tenant, see the header comment on LEGACY_APP_DIR.
###############################################################################

(
    cd "$LEGACY_APP_DIR"
    CERT_DIR="$(grep -m1 '^CERT_DIR=' .env 2>/dev/null | cut -d= -f2- || true)"
    [ -n "$CERT_DIR" ] || die "CERT_DIR not set in ${LEGACY_APP_DIR}/.env -- run deploy-init.sh on the legacy stack first" 3

    mkdir -p "docker/nginx/edge/tenant-pages/${SLUG}"
    cp -n "docker/nginx/edge/tenant-pages/_default/"*.html "docker/nginx/edge/tenant-pages/${SLUG}/" 2>/dev/null || true

    # nginx server_name wants a space-separated list; HOSTS/TENANT_HOSTS is
    # comma-separated everywhere else (matching config/app.php's own
    # TrustedTenantHosts parsing) -- converted only here, at the render
    # boundary. This is what makes server_name carry EXACTLY this stack's own
    # HOSTS (one name by default, more only if an explicit [hosts] argument
    # asked for it) instead of a second hardcoded domain pair that had to be
    # kept in sync with apply.sh by hand.
    # SAFETY DEPENDS ON ORDERING, and nothing here enforces it: every element of
    # HOSTS has already had to resolve through check_dns() long before this
    # point. That is the only reason a malformed value (trailing comma, empty
    # element, stray whitespace, an injected nginx directive) cannot reach this
    # substitution and produce a broken vhost that only surfaces later, after
    # apply has already reported success -- apply never runs `nginx -t` itself.
    # Move the render before check_dns, or add a [hosts] path that skips it, and
    # this gap reopens silently.
    VHOST="docker/nginx/edge/tenants.d/${SLUG}.conf"
    SERVER_NAMES="${HOSTS//,/ }"
    sed -e "s/SLUG/${SLUG}/g" -e "s/CERT_DOMAIN/${CERT_DIR}/g" \
        -e "s/TENANT_SERVER_NAMES/${SERVER_NAMES}/g" \
        docker/nginx/edge/tenants.d/_example.conf.disabled >"${VHOST}.tmp" \
        || die "failed to render ${VHOST}" 3
    if grep -qE '\bSLUG\b|CERT_DOMAIN|TENANT_SERVER_NAMES' "${VHOST}.tmp"; then
        rm -f "${VHOST}.tmp"
        die "rendered edge vhost still contains a placeholder -- _example.conf.disabled changed shape" 3
    fi
    mv -f "${VHOST}.tmp" "$VHOST"

    # Regenerated from the ground truth (the vhost files actually present),
    # not hand-maintained -- see this file's own header for why hand-editing
    # docker-compose.edge.yml (as edge-stack.md's manual runbook currently
    # describes) does not survive this same checkout's own `git checkout
    # --force` on the legacy stack's next deploy. Every OTHER tenant's
    # attachment is preserved automatically because it is re-derived from
    # its own still-present vhost file, not carried over by hand.
    {
        echo "networks:"
        for f in docker/nginx/edge/tenants.d/*.conf; do
            [ -f "$f" ] || continue
            s="$(basename "$f" .conf)"
            echo "  tenant-${s}-edge:"
            echo "    external: true"
        done
        echo "services:"
        echo "  edge-nginx:"
        echo "    networks:"
        for f in docker/nginx/edge/tenants.d/*.conf; do
            [ -f "$f" ] || continue
            s="$(basename "$f" .conf)"
            echo "      - tenant-${s}-edge"
        done
    } >"${EDGE_TENANTS_OVERRIDE}.tmp"
    mv -f "${EDGE_TENANTS_OVERRIDE}.tmp" "$EDGE_TENANTS_OVERRIDE"

    # Container name is READ from docker-compose.edge.yml, not hardcoded --
    # a rename of that service only needs to change in the one file that
    # already owns it. Anchored to the edge-nginx SERVICE block specifically
    # (grep the service line, then the first container_name AFTER it),
    # rather than "the first container_name in the file" -- correct today
    # because this file happens to define only one service, but a second
    # service defined ABOVE edge-nginx with its own container_name would
    # silently pick the wrong value under the looser match.
    EDGE_NGINX_CONTAINER="$(awk '/^[[:space:]]*edge-nginx:/{f=1} f && /container_name:/{print $2; exit}' "$EDGE_COMPOSE_FILE")"
    [ -n "$EDGE_NGINX_CONTAINER" ] \
        || die "could not read edge-nginx's container_name from ${EDGE_COMPOSE_FILE}" 3

    # CRITICAL, found by infrastructure review and reproduced on a throwaway
    # compose project before this comment was written: docker-compose.edge.yml
    # mounts nginx's config through `${EDGE_NGINX_CONF:-edge.conf}`. The
    # documented manual cutover (edge-stack.md's "Cutover sequencing") sets
    # that var ONLY for the one `docker compose ... up -d edge-nginx` command
    # that performs it -- it is never persisted into .env anywhere a LATER
    # invocation could read it back from. This step's own `up -d edge-nginx`
    # below runs on EVERY apply, for every tenant, in a process where that
    # var is unset -- so without this block, the FIRST apply after any
    # cutover silently recreates edge-nginx back onto the bootstrap
    # edge.conf, dropping TLS termination for every tenant behind the edge,
    # with no die() and nothing but a log line that reads as informational.
    # Fix: read what the CURRENTLY RUNNING container is actually mounted
    # with (ground truth, survives however EDGE_NGINX_CONF got set the first
    # time) and re-export that exact value for this invocation, so `up -d`
    # recreates the container with the SAME config it already had. A
    # container that isn't running yet (first-ever bring-up) has nothing to
    # preserve -- EDGE_NGINX_CONF stays unset, which is the correct
    # bootstrap default (edge.conf).
    EDGE_NGINX_CURRENT_SOURCE="$(docker inspect "$EDGE_NGINX_CONTAINER" \
        --format '{{range .Mounts}}{{if eq .Destination "/etc/nginx/conf.d/default.conf"}}{{.Source}}{{end}}{{end}}' 2>/dev/null || true)"
    if [ -n "$EDGE_NGINX_CURRENT_SOURCE" ]; then
        export EDGE_NGINX_CONF
        EDGE_NGINX_CONF="$(basename "$EDGE_NGINX_CURRENT_SOURCE")"
        log "Edge currently mounted with ${EDGE_NGINX_CONF} -- preserving it across this recreation"
    fi

    log "Reloading edge..."
    docker compose -f "$EDGE_COMPOSE_FILE" -f "$EDGE_TENANTS_OVERRIDE" up -d edge-nginx \
        || die "failed to reload the edge with ${SLUG} attached" 3

    # sync-certificate.sh reads NGINX_RELOAD_CONTAINER from THIS (legacy)
    # .env and its own comment says apply is "expected to write this var
    # itself once it performs that cutover" -- but this step runs on EVERY
    # apply, cutover or not, and the edge answers plain HTTP only
    # (EDGE_NGINX_CONF's own default, edge.conf) until the documented manual
    # cutover has actually happened. Writing this unconditionally would be
    # exactly the false claim this step exists to prevent: certbot would
    # keep renewing correctly, but the reload would target a container not
    # actually serving TLS, while the one that IS (registro-nginx, still on
    # the old cert) is never reloaded -- the identical symptom as leaving
    # the var unset, just pointed at a different wrong container.
    #
    # Re-inspected AFTER `up -d` (not reused from EDGE_NGINX_CURRENT_SOURCE
    # above) so this reads the container's ACTUAL post-recreation state --
    # the block above only preserves a cutover that already happened; this
    # is what proves it survived this specific run.
    EDGE_NGINX_CONF_SOURCE="$(docker inspect "$EDGE_NGINX_CONTAINER" \
        --format '{{range .Mounts}}{{if eq .Destination "/etc/nginx/conf.d/default.conf"}}{{.Source}}{{end}}{{end}}' 2>/dev/null || true)"
    if printf '%s' "$EDGE_NGINX_CONF_SOURCE" | grep -q 'edge-tls\.local\.conf$'; then
        # Idempotent: replace the existing line if present, append only if
        # not -- running apply for every tenant on every release must never
        # accumulate duplicate NGINX_RELOAD_CONTAINER lines in this shared
        # .env.
        if grep -q '^NGINX_RELOAD_CONTAINER=' .env; then
            sed -i "s|^NGINX_RELOAD_CONTAINER=.*|NGINX_RELOAD_CONTAINER=${EDGE_NGINX_CONTAINER}|" .env
        else
            echo "NGINX_RELOAD_CONTAINER=${EDGE_NGINX_CONTAINER}" >>.env
        fi
        log "Edge is terminating TLS (${EDGE_NGINX_CONF_SOURCE}) -- NGINX_RELOAD_CONTAINER set to ${EDGE_NGINX_CONTAINER} in ${LEGACY_APP_DIR}/.env"
    else
        log "Edge is not yet terminating TLS (config source: ${EDGE_NGINX_CONF_SOURCE:-unknown}) -- leaving NGINX_RELOAD_CONTAINER untouched"
    fi
) || exit $?

###############################################################################
# Step: assert X-Tenant
#
# Goes through THIS tenant's own nginx directly (not through the edge over
# HTTPS) -- the edge's certificate may not cover ${PRIMARY_HOST} yet (sync-
# certificate.sh's hostname source is a documented, pre-existing gap for
# dedicated tenant stacks -- see edge-stack.md's "Known gap" section, out of
# this task's scope to fix). What THIS assertion proves is the one thing
# actually in apply.sh's control: that app.tenant.local.conf was rendered
# with this tenant's real slug and the fastcgi hop sets it correctly -- same
# technique tenant-compose-stack.md's own manual verification used (a
# one-line probe dropped into the shared app_public volume).
###############################################################################

log "Asserting X-Tenant..."
docker compose "${COMPOSE_ARGS[@]}" exec -T app sh -c \
    'cat > /var/www/public/.registro-x-tenant-probe.php' <<'PHP'
<?php echo $_SERVER['HTTP_X_TENANT'] ?? 'MISSING';
PHP
PROBE_RESULT="$(docker compose "${COMPOSE_ARGS[@]}" exec -T nginx wget -qO- http://127.0.0.1/.registro-x-tenant-probe.php 2>/dev/null || true)"
docker compose "${COMPOSE_ARGS[@]}" exec -T app rm -f /var/www/public/.registro-x-tenant-probe.php </dev/null || true
# KEEP_MAINTENANCE=true: a wrong or missing X-Tenant means nginx is not
# reliably stamping this container's real slug onto every request reaching
# PHP-FPM -- the exact mechanism that is supposed to make it structurally
# impossible for this hop to serve, or attribute, a request to the wrong
# tenant. Leaving the stack live on a failed identity check is the specific
# incident shape this project's own worst incidents have taken; releasing
# maintenance here would be shipping that risk to real traffic.
[ "$PROBE_RESULT" = "$SLUG" ] || {
    KEEP_MAINTENANCE=true
    die "X-Tenant probe returned '${PROBE_RESULT}', expected '${SLUG}' -- app.tenant.local.conf did not render correctly" 3
}
log "X-Tenant confirmed: ${PROBE_RESULT}"

###############################################################################
# Step: asset gate -- identical logic to deploy.sh's own (manifest.json hash
# comparison, then a full public/ diff), scoped to this tenant's containers.
# See deploy.sh's own extensive comment on WHY the healthcheck alone cannot
# see this; not repeated here.
###############################################################################

asset_gate_failed() {
    KEEP_MAINTENANCE=true
    die "$1 -- re-run the same tag first (the sync is idempotent): /opt/registro/apply.sh ${SLUG} ${TAG} ${HOSTS}" 3
}

log "Verifying frontend assets..."
docker compose "${COMPOSE_ARGS[@]}" exec -T app php -r '
    $imageDir = "/tmp/public/build";
    $liveDir  = "/var/www/public/build";
    if (!is_file("$imageDir/manifest.json")) { fwrite(STDERR, "image has no build/manifest.json\n"); exit(1); }
    if (!is_file("$liveDir/manifest.json")) { fwrite(STDERR, "no manifest.json in the public volume\n"); exit(1); }
    if (hash_file("sha256", "$imageDir/manifest.json") !== hash_file("sha256", "$liveDir/manifest.json")) {
        fwrite(STDERR, "the public volume holds a DIFFERENT release than this image\n"); exit(1);
    }
    $entries = json_decode(file_get_contents("$liveDir/manifest.json"), true);
    if (!is_array($entries) || $entries === []) { fwrite(STDERR, "manifest.json is empty or unreadable\n"); exit(1); }
    $missing = [];
    foreach ($entries as $entry) {
        foreach (["file", "css", "assets"] as $key) {
            foreach ((array) ($entry[$key] ?? []) as $ref) {
                if (!is_file("$liveDir/$ref")) { $missing[] = $ref; }
            }
        }
    }
    if ($missing !== []) {
        fwrite(STDERR, sprintf("%d asset(s) missing, e.g. %s\n", count($missing), implode(", ", array_slice($missing, 0, 3))));
        exit(1);
    }
    printf("%d manifest entries, matching this image, all files present\n", count($entries));
' </dev/null || asset_gate_failed "build/ in the volume does not match this image"

docker compose "${COMPOSE_ARGS[@]}" exec -T app sh -c '
    out="$(diff -rq --exclude=storage /tmp/public /var/www/public 2>&1)"
    rc=$?
    if [ "$rc" -gt 1 ]; then echo "diff failed to run: $out"; exit 1; fi
    if [ -n "$out" ]; then echo "$out"; exit 1; fi
    echo "public/ matches the image"
' </dev/null || asset_gate_failed "public/ differs from the image beyond build/"

###############################################################################
# Step: clear maintenance, health check
###############################################################################

clear_maintenance

log "Health check (Host: ${PRIMARY_HOST}, port ${HTTP_PORT})..."
deadline=$((SECONDS + 180))
until curl -fsS -o /dev/null -H "Host: ${PRIMARY_HOST}" "http://127.0.0.1:${HTTP_PORT}/up"; do
    [ $SECONDS -lt $deadline ] || die "health check failed after 180s" 3
    sleep 5
done

###############################################################################
# Step: backup -- restic, one repository and one password PER TENANT
#
# See app/docs/deployment/tenant-apply.md for the full restic install/
# custody answer; this is the mechanical part only. `set -o pipefail` is
# NOT relevant to this block -- there is no pipe here, every command's exit
# status is read directly via `||`. The "Dump completed" assertion reuses
# dump_database() from the pre-dump step above rather than trusting restic's
# own exit status for a truncated source file -- restic backing up a
# half-written file successfully is not the same as the dump having
# succeeded.
#
# Deliberately does NOT call die() for its own failures. The health check
# above already passed: this tenant is live, serving the new release,
# correctly. A backup problem from here on is real and must not be silent,
# but it is a DIFFERENT problem than "the deploy failed" -- conflating the
# two (the previous version of this script did, via die()) left an operator
# reading FAILED for a release that was actually fine, with no way to tell
# the two apart without opening the log. BACKUP_FAILED tracks it instead;
# handled after REGISTRO_VERSION is pinned, below.
###############################################################################

BACKUP_FAILED=false
BACKUP_DIR="${BACKUP_ROOT}/${SLUG}"
# Guarded, not a bare `mkdir -p` -- found while testing the DEGRADED path:
# an unwritable BACKUP_ROOT (permissions, a full disk, /opt/registro not
# provisioned yet) previously died here UNCAUGHT, under `set -e`, before
# BACKUP_FAILED could ever be set -- the release was still live and
# healthy, but the run reported a generic FAILED anyway, exactly the
# conflation this whole step exists to remove. Every failure from here to
# the end of the backup block sets BACKUP_FAILED instead of dying.
if ! mkdir -p "$BACKUP_DIR" 2>>"$LOG_FILE"; then
    log "ERROR: could not create ${BACKUP_DIR} -- see ${LOG_FILE}"
    BACKUP_FAILED=true
elif [ ! -f "${BACKUP_DIR}/password" ] \
        && { ! openssl rand -base64 32 >"${BACKUP_DIR}/password" 2>>"$LOG_FILE" || ! chmod 600 "${BACKUP_DIR}/password"; }; then
    log "ERROR: could not create ${BACKUP_DIR}/password -- see ${LOG_FILE}"
    BACKUP_FAILED=true
fi

export RESTIC_REPOSITORY="${RESTIC_REPOSITORY:-${BACKUP_DIR}/repo}"
export RESTIC_PASSWORD_FILE="${BACKUP_DIR}/password"

if [ "$BACKUP_FAILED" = true ]; then
    :  # already logged above; skip straight to the pin/status step below
elif ! command -v restic >/dev/null 2>&1; then
    log "WARNING: restic not installed -- skipping the durable backup step (see the runbook). Apply is otherwise complete."
elif ! restic snapshots >/dev/null 2>&1; then
    mkdir -p "$RESTIC_REPOSITORY" 2>/dev/null || true
    if ! restic init >>"$LOG_FILE" 2>&1; then
        log "ERROR: restic init failed -- see ${LOG_FILE}"
        BACKUP_FAILED=true
    fi
fi

if [ "$BACKUP_FAILED" != true ] && command -v restic >/dev/null 2>&1; then
    FINAL_DUMP="$(mktemp "/tmp/${SLUG}-backup-XXXXXX.sql")"
    log "Dumping database for backup..."
    if ! dump_database "$FINAL_DUMP"; then
        log "ERROR: final backup dump failed or was truncated"
        BACKUP_FAILED=true
    else
        # --- storage volumes -------------------------------------------------
        #
        # Same staging + guard as tenant-backup.sh's own stage_volume() --
        # duplicated rather than sourced, same reasoning as this whole step's
        # own header comment on why apply.sh does not depend on an external
        # file. See that script for the full "why root, why chown back, why
        # one snapshot" reasoning; not repeated here.
        FINAL_STAGE_DIR="$(mktemp -d "/tmp/${SLUG}-backup-files-XXXXXX")"
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
            # silently produced an empty snapshot for both storage volumes.
            docker run --rm --user 0:0 --entrypoint sh \
                -v "${vol}:/src:ro" -v "${dest}:/dest" "ghcr.io/patrykgielo/registro:${TAG}" \
                -c "cp -a /src/. /dest/ && chown -R $(id -u):$(id -g) /dest" \
                >/dev/null 2>>"$LOG_FILE" || {
                log "ERROR: staging volume ${vol} failed"
                return 1
            }
        }

        RESTIC_TARGETS=("$FINAL_DUMP")
        if stage_volume "${TENANT_PREFIX}_storage-app-public" "${FINAL_STAGE_DIR}/storage-app-public"; then
            RESTIC_TARGETS+=("${FINAL_STAGE_DIR}/storage-app-public")
        else
            BACKUP_FAILED=true
        fi
        if stage_volume "${TENANT_PREFIX}_storage-app-private" "${FINAL_STAGE_DIR}/storage-app-private"; then
            RESTIC_TARGETS+=("${FINAL_STAGE_DIR}/storage-app-private")
        else
            BACKUP_FAILED=true
        fi

        log "Backing up to restic (host=tenant-${SLUG})..."
        # Output captured, not streamed straight to the log -- so it can be
        # inspected for the "repository already locked" shape below without
        # a second restic invocation. `set -e` is safe here: the assignment
        # is wrapped in `||`, same fix as PROVISION_STATUS above.
        BACKUP_OUTPUT="$(restic backup "${RESTIC_TARGETS[@]}" --host "tenant-${SLUG}" \
            --tag "slug=${SLUG}" --tag "apply=${TAG}" 2>&1)" || {
            echo "$BACKUP_OUTPUT" >>"$LOG_FILE"
            BACKUP_FAILED=true
            # A stale exclusive lock (left by a backup or apply that died
            # mid-run -- restic's own lock is not released on an unclean
            # exit) otherwise surfaces as an opaque restic error and blocks
            # EVERY future apply for this tenant until someone happens to
            # know `restic unlock`. Naming it here is the whole fix -- see
            # app/docs/deployment/tenant-apply.md for the full recovery
            # command with the right REPOSITORY/PASSWORD_FILE already filled
            # in.
            if printf '%s' "$BACKUP_OUTPUT" | grep -qi 'already locked'; then
                log "ERROR: restic repository is locked (a previous backup/apply likely died mid-run). Recover with:"
                log "  RESTIC_REPOSITORY='${RESTIC_REPOSITORY}' RESTIC_PASSWORD_FILE='${RESTIC_PASSWORD_FILE}' restic unlock"
            else
                log "ERROR: restic backup failed -- see ${LOG_FILE}"
            fi
        }
        rm -f "$FINAL_DUMP"
        rm -rf "$FINAL_STAGE_DIR"
        [ "$BACKUP_FAILED" = true ] || log "Backup complete"
    fi
fi

###############################################################################
# Step: pin REGISTRO_VERSION in .env -- LAST, only once the release itself
# (everything through the health check) succeeded.
#
# This protects the DURABLE RECORD, not the running state, and the
# distinction matters: REGISTRO_VERSION is exported as a shell variable
# (above, right after .env is regenerated) and wins over the .env file in
# Compose interpolation, so `pull`/`migrate`/`up -d` already ran ${TAG}
# WELL BEFORE this point -- an operator who fails between `up -d` and here
# and trusts .env alone would be told the OLD version is live when the NEW
# one already is. Writing it last is what makes a re-run of this exact
# command safe to retry from a clean baseline for every step ABOVE up -d --
# it is not a guarantee about the migrate step specifically, which is not
# atomic: MySQL DDL can leave a migration half-applied (column A added,
# column B's statement failed) with nothing recorded as run, so an
# identical re-run fails again with "column already exists" and needs
# manual schema surgery, not just a retry. See the migrate step's own die()
# message, which already points at the pre-migration dump for that case.
###############################################################################

if grep -q '^REGISTRO_VERSION=' "${STACK_DIR}/.env"; then
    sed -i "s|^REGISTRO_VERSION=.*|REGISTRO_VERSION=${TAG}|" "${STACK_DIR}/.env"
else
    echo "REGISTRO_VERSION=${TAG}" >>"${STACK_DIR}/.env"
fi

if [ "$BACKUP_FAILED" = true ]; then
    STATUS_FINALIZED=true
    write_status "DEGRADED" "backup failed after a healthy release -- see ${LOG_FILE}"
    log "=== apply ${SLUG} ${TAG} DEGRADED (release is live and healthy; backup failed, see above) ==="
    docker compose "${COMPOSE_ARGS[@]}" ps || true
    exit 5
fi

STATUS_FINALIZED=true
write_status "OK" ""
log "=== apply ${SLUG} ${TAG} OK ==="
docker compose "${COMPOSE_ARGS[@]}" ps || true
exit 0
