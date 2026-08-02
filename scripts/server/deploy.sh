#!/bin/bash
###############################################################################
# Registro production deploy -- SSH forced-command target
#
# Installed by scripts/setup-production-server.sh to /opt/registro/deploy.sh,
# owned root:root, mode 755. It deliberately lives OUTSIDE /var/www/registro:
# that directory is owned by `deploy`, so a script kept there could be rewritten
# by whoever the forced command is supposed to constrain.
#
# authorized_keys entry on the server:
#   command="/opt/registro/deploy.sh",no-pty,no-agent-forwarding,
#   no-port-forwarding,no-X11-forwarding,restrict ssh-ed25519 AAAA... ci@github
#
# The client's command line is ignored by sshd and handed to us in
# SSH_ORIGINAL_COMMAND, which is why every argument below is re-validated here
# rather than trusted.
#
# Usage (as the SSH command from CI):
#   ssh deploy@host "deploy v1.2.3"
#   ssh deploy@host "rollback v1.2.2"    # same, minus migrations
#   ssh deploy@host "status"
#
# Exit codes:
#   0 - success            3 - deploy failed
#   1 - usage/validation   4 - another deploy is running
###############################################################################

set -euo pipefail

readonly APP_DIR="/var/www/registro"
readonly COMPOSE_FILE="docker-compose.prod.yml"
# Both under paths the deploy user owns: /var/lock is root-only, so a lock file
# there would make every run abort before it started.
readonly LOCK_FILE="${APP_DIR}/.deploy.lock"
readonly LOG_FILE="/var/log/registro-deploy.log"
readonly HEALTH_TIMEOUT=180

log() {
    local line="[$(date '+%Y-%m-%d %H:%M:%S')] $*"
    echo "$line"
    echo "$line" >>"$LOG_FILE" 2>/dev/null || true
}

die() {
    log "ERROR: $1"
    exit "${2:-1}"
}

###############################################################################
# Parse and validate the request
###############################################################################

# Interactive invocation (no forced command) still works for a human on the box.
REQUEST="${SSH_ORIGINAL_COMMAND:-$*}"

read -r ACTION VERSION EXTRA <<<"$REQUEST"

[ -z "${EXTRA:-}" ] || die "too many arguments -- expected '<action> <tag>'"

case "${ACTION:-}" in
    deploy|rollback)
        # Anchored on both ends: this is the only thing standing between a
        # forced command and arbitrary argument injection.
        [[ "${VERSION:-}" =~ ^v[0-9]+\.[0-9]+\.[0-9]+(-[A-Za-z0-9.]+)?$ ]] \
            || die "invalid tag '${VERSION:-}' -- expected vMAJOR.MINOR.PATCH[-suffix]"
        ;;
    status)
        # Deliberately `docker ps`, not `docker compose ps`. The compose file
        # uses the ${VAR:?} form for APP_DOMAIN, APP_KEY and REDIS_PASSWORD, and
        # that interpolation is evaluated for EVERY subcommand -- ps and logs
        # included, not just up. So `compose ps` returns an interpolation error
        # instead of container state in exactly the situations where you most
        # need to look: .env not written yet (all of Phase 4), or a password
        # blanked by a bad edit. This action is the only diagnostic the forced
        # command exposes, so it must not depend on .env being valid.
        docker ps -a --filter "name=registro-" \
            --format 'table {{.Names}}\t{{.Status}}\t{{.Image}}'
        exit 0
        ;;
    *)
        die "unknown action '${ACTION:-}' -- allowed: deploy <tag> | rollback <tag> | status"
        ;;
esac

###############################################################################
# One deploy at a time
###############################################################################

exec 9>"$LOCK_FILE"
flock -n 9 || die "another deploy is already running" 4

###############################################################################
# Deploy
###############################################################################

cd "$APP_DIR" || die "$APP_DIR not found -- server not bootstrapped"
[ -f .env ] || die ".env missing in $APP_DIR"
[ -f "$COMPOSE_FILE" ] || die "$COMPOSE_FILE missing in $APP_DIR"

# Needed below for REDIS_PASSWORD (readiness probe) and APP_URL (health check).
set -a
# shellcheck disable=SC1091
source .env
set +a
: "${REDIS_PASSWORD:?REDIS_PASSWORD not set in .env}"
: "${APP_URL:?APP_URL not set in .env}"
APP_HOST="${APP_URL#*://}"
APP_HOST="${APP_HOST%%/*}"

PREVIOUS="$(git -C "$APP_DIR" describe --tags --exact-match 2>/dev/null || git -C "$APP_DIR" rev-parse --short HEAD)"
log "=== ${ACTION} ${VERSION} (currently at ${PREVIOUS}) ==="

###############################################################################
# Maintenance-mode safety net
#
# The maintenance flag lives in storage/framework, a named volume, so it
# survives container recreation and reboots -- which is what makes it usable
# across `up -d`, and also what makes a stranded flag permanent. Every failure
# path between `artisan down` and the end of the run must clear it, or the site
# stays 503 forever: public/index.php short-circuits EVERY request including
# /up, and this script's grammar (deploy|rollback|status) offers no way to run
# `artisan up`. Recovery would need the separate root key.
#
# `rollback` -- the operator's instinctive next move after a failed deploy --
# would otherwise inherit the 503 and then fail its own health check because of
# it, reporting a broken rollback that actually worked.
###############################################################################

MAINTENANCE_ON=false
KEEP_MAINTENANCE=false

clear_maintenance() {
    [ "$MAINTENANCE_ON" = true ] || return 0
    if [ "$KEEP_MAINTENANCE" = true ]; then
        log "Leaving maintenance mode ON deliberately -- see the error above"
        return 0
    fi
    docker compose -f "$COMPOSE_FILE" exec -T app php artisan up </dev/null >/dev/null 2>&1 \
        && log "Maintenance mode cleared" \
        || log "WARNING: could not clear maintenance mode -- run 'artisan up' as root"
    MAINTENANCE_ON=false
}

on_exit() {
    local rc=$?
    [ "$rc" -eq 0 ] || clear_maintenance
    return "$rc"
}
trap on_exit EXIT

# A rollback is a recovery action: clear any flag a previous failed deploy left
# behind, whether or not this run sets one. Harmless when nothing is stranded.
if [ "$ACTION" = "rollback" ]; then
    MAINTENANCE_ON=true
fi

# Repository state comes from git, not from curl-ing raw.githubusercontent:
# one source of truth, and it works on a private repo without a token.
log "Fetching tags..."
git fetch --tags --prune origin || die "git fetch failed" 3
git rev-parse -q --verify "refs/tags/${VERSION}" >/dev/null \
    || die "tag ${VERSION} does not exist on origin" 3

log "Checking out ${VERSION}..."
git checkout --quiet --force "tags/${VERSION}" || die "git checkout failed" 3

# The TLS config nginx mounts is generated, not tracked: the checkout above
# would revert an in-place edit of the template back to its CERT_DOMAIN
# placeholder, and nginx would then refuse to start the next time anything
# recreated the container. Regenerating here also propagates template changes
# that ship with a new release, and recreates the file if it went missing.
if [ "${NGINX_CONF:-}" = "app.prod-tls.local.conf" ]; then
    TLS_DIR="docker/nginx/production"
    TLS_TEMPLATE="${TLS_DIR}/app.prod-tls.conf"
    TLS_OUT="${TLS_DIR}/app.prod-tls.local.conf"

    [ -f "$TLS_TEMPLATE" ] || die "${TLS_TEMPLATE} missing at ${VERSION}" 3

    # NOT derived from APP_URL. certbot names the live directory after the first
    # -d, and appends -0001 whenever the SAN set changes -- which happens the
    # first time www.<host> starts resolving, since deploy-init.sh adds it
    # conditionally. Rewriting the config to <host> on every deploy would then
    # point nginx at a directory that no longer exists. CERT_DIR in .env is the
    # authority; APP_HOST is only the initial guess.
    CERT_DIR="${CERT_DIR:-$APP_HOST}"
    [ -d "/etc/letsencrypt/live/${CERT_DIR}" ] \
        || die "/etc/letsencrypt/live/${CERT_DIR} does not exist -- set CERT_DIR in .env to the certbot directory name" 3

    sed "s|/etc/letsencrypt/live/CERT_DOMAIN/|/etc/letsencrypt/live/${CERT_DIR}/|g" \
        "$TLS_TEMPLATE" >"${TLS_OUT}.tmp" \
        || die "failed to render ${TLS_OUT}" 3

    # sed exits 0 when it substitutes nothing, so a template whose placeholder
    # was renamed or whose cert paths were restructured would yield a file that
    # still says CERT_DOMAIN and that nginx cannot start on. Check the result,
    # not the exit status.
    if grep -q 'CERT_DOMAIN' "${TLS_OUT}.tmp"; then
        rm -f "${TLS_OUT}.tmp"
        die "rendered TLS config still contains CERT_DOMAIN -- template changed shape at ${VERSION}" 3
    fi

    # Move into place only once it is complete and correct: a truncated write
    # (disk full) would otherwise sit there until the next reboot recreated
    # nginx on it, long after this run reported failure.
    mv -f "${TLS_OUT}.tmp" "$TLS_OUT" || die "failed to install ${TLS_OUT}" 3
    log "Regenerated app.prod-tls.local.conf for ${CERT_DIR}"
fi

# Pin the image tag for app, horizon and scheduler. Exported rather than written
# to .env yet: a shell variable wins over the .env file in Compose interpolation,
# so every `docker compose` call below already runs on ${VERSION} while .env
# still names the version that is actually deployed. Persisting it before the
# pull would leave .env pointing at images that may not exist on the host, and a
# later bare `docker compose up -d` would fail on a missing image.
export REGISTRO_VERSION="$VERSION"

log "Pulling images..."
docker compose -f "$COMPOSE_FILE" pull || die "docker pull failed" 3

if [ "$ACTION" = "deploy" ]; then
    # Maintenance mode goes up BEFORE the new images do. Between `up -d` and the
    # migration below there is a wait on MySQL and Redis that can run for minutes
    # on a cold start, and every second of it would otherwise serve new code
    # against the old schema. The flag lives in storage/framework, a named
    # volume, so it survives the container recreation that `up -d` performs.
    # Fails harmlessly on a first bring-up, when no app container exists yet.
    log "Enabling maintenance mode..."
    docker compose -f "$COMPOSE_FILE" exec -T app php artisan down --retry=15 </dev/null || true
    MAINTENANCE_ON=true
fi

log "Starting containers..."
docker compose -f "$COMPOSE_FILE" up -d --remove-orphans

log "Waiting for MySQL..."
timeout 120 bash -c "until docker compose -f '$COMPOSE_FILE' exec -T mysql \
    mysqladmin ping -h localhost --silent </dev/null; do sleep 2; done" \
    || die "MySQL did not become ready" 3

log "Waiting for Redis..."
timeout 60 bash -c "until docker compose -f '$COMPOSE_FILE' exec -T redis \
    redis-cli -a \"\$REDIS_PASSWORD\" ping </dev/null 2>/dev/null | grep -q PONG; do sleep 2; done" \
    || die "Redis did not become ready" 3

if [ "$ACTION" = "deploy" ]; then
    log "Running migrations..."
    # Re-assert: on a first bring-up the `down` above had no container to run in.
    docker compose -f "$COMPOSE_FILE" exec -T app php artisan down --retry=15 </dev/null || true
    if ! docker compose -f "$COMPOSE_FILE" exec -T app php artisan migrate --force </dev/null; then
        # Stay in maintenance. A failed migration leaves the schema in an
        # unknown, possibly half-applied state, and serving the new code against
        # it risks user-visible errors and bad writes -- worse than an honest
        # 503. This is only a safe choice because `rollback` lifts the flag
        # unconditionally, so recovery does not need the root key.
        KEEP_MAINTENANCE=true
        die "migrations failed -- site left in maintenance mode on ${VERSION}. Recover with: ssh deploy@host 'rollback ${PREVIOUS}'" 3
    fi
    clear_maintenance
else
    # Rolling back an image does not roll back schema. Additive migrations are
    # survivable, drops and renames are not -- see migrations:check-rollback.
    log "Rollback: skipping migrations (schema is NOT reverted)"
    # Unconditional: a rollback is what an operator reaches for after a deploy
    # died holding the maintenance flag, so it must lift it even though this run
    # never set one.
    clear_maintenance
fi

log "Rebuilding caches..."
docker compose -f "$COMPOSE_FILE" exec -T app php artisan optimize:clear </dev/null
docker compose -f "$COMPOSE_FILE" exec -T app php artisan filament:optimize-clear </dev/null
docker compose -f "$COMPOSE_FILE" exec -T app php artisan config:cache </dev/null
docker compose -f "$COMPOSE_FILE" exec -T app php artisan route:cache </dev/null
docker compose -f "$COMPOSE_FILE" exec -T app php artisan view:cache </dev/null
docker compose -f "$COMPOSE_FILE" exec -T app php artisan event:cache </dev/null
docker compose -f "$COMPOSE_FILE" exec -T app php artisan filament:optimize </dev/null
docker compose -f "$COMPOSE_FILE" exec -T app php artisan storage:link </dev/null || true

# No `composer install` here. The image ships a built vendor/ directory; running
# composer against a live container adds a packagist dependency mid-deploy and
# writes into a container layer that the next up -d discards.

log "Restarting Horizon..."
docker compose -f "$COMPOSE_FILE" restart horizon

# Host header matters: nginx selects the server block by name, and without it
# the probe can land on the default server and "pass" against the wrong vhost.
log "Health check (Host: ${APP_HOST})..."
deadline=$((SECONDS + HEALTH_TIMEOUT))
until curl -fsS -o /dev/null -H "Host: ${APP_HOST}" "http://127.0.0.1/up"; do
    [ $SECONDS -lt $deadline ] || die "health check failed after ${HEALTH_TIMEOUT}s" 3
    sleep 5
done

# Only now is ${VERSION} genuinely what is deployed and serving. Writing it any
# earlier means a failure between the write and here leaves .env naming a
# version the running containers are not on, and the next bare
# `docker compose up -d` -- or a reboot, via `restart: unless-stopped` -- would
# silently promote that version, in the migration case against a schema that was
# never migrated for it.
log "Pinning REGISTRO_VERSION=${VERSION} in .env..."
if grep -q '^REGISTRO_VERSION=' .env; then
    sed -i "s|^REGISTRO_VERSION=.*|REGISTRO_VERSION=${VERSION}|" .env
else
    echo "REGISTRO_VERSION=${VERSION}" >> .env
fi

log "Pruning images older than 24h..."
docker image prune -af --filter "until=24h" >/dev/null 2>&1 || true

log "=== ${ACTION} ${VERSION} OK (was ${PREVIOUS}) ==="
docker compose -f "$COMPOSE_FILE" ps
