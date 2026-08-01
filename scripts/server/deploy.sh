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
        cd "$APP_DIR"
        docker compose -f "$COMPOSE_FILE" ps
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

# Repository state comes from git, not from curl-ing raw.githubusercontent:
# one source of truth, and it works on a private repo without a token.
log "Fetching tags..."
git fetch --tags --prune origin || die "git fetch failed" 3
git rev-parse -q --verify "refs/tags/${VERSION}" >/dev/null \
    || die "tag ${VERSION} does not exist on origin" 3

log "Checking out ${VERSION}..."
git checkout --quiet --force "tags/${VERSION}" || die "git checkout failed" 3

# Pin the image tag for app, horizon and scheduler. Written into .env so a
# subsequent bare `docker compose up -d` on the box stays on the same version.
if grep -q '^REGISTRO_VERSION=' .env; then
    sed -i "s|^REGISTRO_VERSION=.*|REGISTRO_VERSION=${VERSION}|" .env
else
    echo "REGISTRO_VERSION=${VERSION}" >> .env
fi

log "Pulling images..."
docker compose -f "$COMPOSE_FILE" pull || die "docker pull failed" 3

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
    docker compose -f "$COMPOSE_FILE" exec -T app php artisan down --retry=15 </dev/null || true
    if ! docker compose -f "$COMPOSE_FILE" exec -T app php artisan migrate --force </dev/null; then
        docker compose -f "$COMPOSE_FILE" exec -T app php artisan up </dev/null || true
        die "migrations failed -- application left up on ${VERSION}, roll back manually" 3
    fi
    docker compose -f "$COMPOSE_FILE" exec -T app php artisan up </dev/null || true
else
    # Rolling back an image does not roll back schema. Additive migrations are
    # survivable, drops and renames are not -- see migrations:check-rollback.
    log "Rollback: skipping migrations (schema is NOT reverted)"
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

log "Pruning images older than 24h..."
docker image prune -af --filter "until=24h" >/dev/null 2>&1 || true

log "=== ${ACTION} ${VERSION} OK (was ${PREVIOUS}) ==="
docker compose -f "$COMPOSE_FILE" ps
