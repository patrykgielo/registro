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
# Restores the public port-80 binding docker-compose.prod.yml's nginx service
# no longer defaults to as of task 4 (stack-per-tenant epic) -- that file now
# defaults every tenant stack to loopback-only so a forgotten override can't
# publish it to the internet redundantly alongside the edge. THIS script is,
# and always has been, exclusively the legacy single-stack's deploy target
# (APP_DIR above is hardcoded to one directory) -- a future tenant stack gets
# its own deploy mechanism (task 6), not this file with a different override.
# See app/docs/deployment/tenant-compose-stack.md for the full reasoning and
# why a static `${VAR:-default}` alone could not express "public here, private
# everywhere else" without this second file.
readonly OVERRIDE_FILE="docker-compose.legacy-public-ports.override.yml"
readonly COMPOSE_ARGS=(-f "$COMPOSE_FILE" -f "$OVERRIDE_FILE")
# Both under paths the deploy user owns: /var/lock is root-only, so a lock file
# there would make every run abort before it started.
readonly LOCK_FILE="${APP_DIR}/.deploy.lock"
readonly LOG_FILE="/var/log/registro-deploy.log"
readonly HEALTH_TIMEOUT=180

log() {
    local line="[$(date '+%Y-%m-%d %H:%M:%S')] $*"
    # Durable write FIRST, stdout second, `|| true` on both. When CI's step
    # timeout kills the ssh client this script is orphaned with a closed stdout,
    # and writing there raises SIGPIPE -- so anything after it would be lost.
    # Ordering it this way means the log file records what happened even when
    # nobody is listening any more.
    echo "$line" >>"$LOG_FILE" 2>/dev/null || true
    echo "$line" 2>/dev/null || true
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
        #
        # Container names are no longer the literal "registro-*" -- task 4
        # (docker-compose.prod.yml rebuilt into a per-tenant stack) templated
        # every container_name from TENANT_PREFIX. A single `grep` for that one
        # key, not `source .env`, keeps this diagnostic independent of every
        # other var in the file the way the comment above requires -- an
        # unset/missing key defaults to "registro", identical to today.
        prefix="$(grep -m1 '^TENANT_PREFIX=' "${APP_DIR}/.env" 2>/dev/null | cut -d= -f2-)"
        docker ps -a --filter "name=${prefix:-registro}-" \
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
[ -f "$OVERRIDE_FILE" ] || die "$OVERRIDE_FILE missing in $APP_DIR"

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
# stays 503 forever -- including /up, which is served by the application and so
# goes through PreventRequestsDuringMaintenance like everything else. This
# script's grammar (deploy|rollback|status) offers no way to run `artisan up`,
# so recovery would otherwise need the separate root key.
#
# `rollback` -- the operator's instinctive next move after a failed deploy --
# would otherwise inherit the 503 and then fail its own health check because of
# it, reporting a broken rollback that actually worked.
###############################################################################

MAINTENANCE_ON=false
KEEP_MAINTENANCE=false

# Removes the maintenance flag without needing a WORKING app container --
# and, critically, without needing Compose to successfully interpolate the
# WHOLE file.
#
# `artisan up` is tried first (below, in clear_maintenance) so this stays
# correct whatever maintenance driver is configured, but it needs the
# container to be running -- and the situations that strand a flag (failed
# `up -d`, MySQL never ready, crash-looping image) are exactly the ones where
# it is not.
#
# An earlier version of this function used `docker compose run` on this same
# file, on the theory that Compose would resolve the correct volume the same
# way `up -d` already had, with nothing to disambiguate. That was wrong: EVERY
# Compose subcommand -- `run`, `config`, `ps` -- interpolates the ENTIRE file
# before selecting a service, and this file hard-requires APP_KEY, APP_DOMAIN
# and REDIS_PASSWORD via `${VAR:?}`. A blanked or corrupted .env therefore
# breaks `docker compose run` at interpolation, before `--entrypoint rm` ever
# runs -- and that is EXACTLY the scenario this function exists for ("a
# password blanked by a bad edit" is the literal reason the `status` action
# above already avoids `docker compose ps`). `clear_maintenance()`'s primary
# path (`docker compose exec -T app php artisan up`) already goes through
# that same interpolation, so a `docker compose`-based fallback shares its
# failure with the thing it is meant to catch -- one bad edit takes out both.
#
# Fixed by never asking Compose anything. The volume name is COMPUTED, not
# looked up: Compose's own deterministic auto-naming is `${project}_
# ${volume-key}` (confirmed by inspection -- bringing this exact file up
# under project name "citest" created a volume literally named
# "citest_storage-framework"), and the project name is `${TENANT_PREFIX:-
# registro}` (this file's own `name:` field, task 4) -- read here via a bare
# `grep` on .env, the same technique `status` above already uses, which needs
# nothing but that one line to be present and un-corrupted. `docker volume
# inspect` (also raw docker, not compose) confirms the volume actually exists
# before touching it: `docker run -v name:/path` SILENTLY CREATES an empty
# named volume for a name that doesn't exist yet, which would make this
# function report success while removing nothing from a volume that never
# had the flag. The image is the already-pulled application image, NOT
# alpine:3: this script prunes unreferenced images older than 24h on every
# successful deploy, so alpine would have to be re-pulled at the exact moment
# the stack is broken.
force_clear_flag() {
    local prefix vol image
    prefix="$(grep -m1 '^TENANT_PREFIX=' "${APP_DIR}/.env" 2>/dev/null | cut -d= -f2-)"
    vol="${prefix:-registro}_storage-framework"
    image="ghcr.io/patrykgielo/registro:${REGISTRO_VERSION:-latest}"

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

    # Bounded: a wedged PHP process would otherwise hang this indefinitely and,
    # under CI's step timeout, reproduce the very problem it is here to fix.
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

    log "WARNING: could not clear maintenance mode. Try: ssh deploy@host 'rollback ${PREVIOUS}'"
}

###############################################################################
# Stale-flag recovery -- the thing that actually makes this safe
#
# Traps are best-effort and always will be: SIGKILL cannot be caught at all, and
# a handler that logs to a closed stdout can die before it does any work. So
# correctness must NOT depend on them.
#
# Instead, EVERY run -- deploy and rollback alike -- clears any flag left behind
# before it does anything else. A stranded 503 therefore survives at most until
# the next deploy attempt, including the automatic CI retry, with no signal
# handling involved. The traps below remain as a best-effort fast path.
###############################################################################

MAINTENANCE_ON=true          # assume a flag may exist; clearing is idempotent
log "Clearing any maintenance flag left by a previous run..."
clear_maintenance
MAINTENANCE_ON=false

on_exit() {
    local rc=$?
    [ "$rc" -eq 0 ] || clear_maintenance
    # `return` is a no-op for the script's status -- bash preserves the pre-trap
    # exit code unless the trap itself calls exit. Kept for clarity.
    return "$rc"
}

# Best-effort fast path only; the startup sweep above is the guarantee. Note
# that no trap can cover SIGKILL, which is why the guarantee cannot live here.
#
# Disarm every trap as the FIRST action, before logging: the handler's own log
# line goes to stdout, and when stdout is the closed pipe of a killed ssh client
# that raises SIGPIPE inside the handler, re-entering it. PIPE is ignored
# outright rather than handled, and log() writes to the durable file before
# touching stdout, so a dead stdout cannot stop the cleanup.
on_signal() {
    trap '' HUP INT TERM PIPE EXIT
    log "Received SIG${1} -- cleaning up"
    clear_maintenance
    exit 3
}
trap on_exit EXIT
trap '' PIPE
for sig in HUP INT TERM; do
    # shellcheck disable=SC2064
    trap "on_signal ${sig}" "$sig"
done

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

    # certbot creates /etc/letsencrypt/live as 0700 root:root, and this script
    # runs as the deploy user. `[ -d ... ]` is therefore FALSE for a certificate
    # that exists perfectly well -- which is exactly how the first TLS deploy on
    # this server died, with a message telling the operator to set a variable
    # that was already correct.
    #
    # Only treat absence as fatal when the parent is actually readable. When it
    # is not, trust CERT_DIR: nginx runs as root inside its container, reads the
    # certificate directly, and refuses to start on a bad path -- so a wrong
    # value still fails the deploy, one step later and for the real reason.
    if [ -r /etc/letsencrypt/live ]; then
        [ -d "/etc/letsencrypt/live/${CERT_DIR}" ] \
            || die "/etc/letsencrypt/live/${CERT_DIR} does not exist -- set CERT_DIR in .env to the certbot directory name" 3
    else
        log "Cannot read /etc/letsencrypt/live as $(id -un); trusting CERT_DIR=${CERT_DIR}"
    fi

    sed "s|/etc/letsencrypt/live/CERT_DOMAIN/|/etc/letsencrypt/live/${CERT_DIR}/|g" \
        "$TLS_TEMPLATE" >"${TLS_OUT}.tmp" \
        || die "failed to render ${TLS_OUT}" 3

    # sed exits 0 when it substitutes nothing, so a template whose placeholder
    # was renamed or whose cert paths were restructured would yield a file that
    # still says CERT_DOMAIN and that nginx cannot start on. Check the result,
    # not the exit status.
    #
    # Match the PATH, not the bare word. The template explains CERT_DOMAIN in its
    # header comments, so `grep -q CERT_DOMAIN` matches those comments on every
    # render and this check fired unconditionally -- meaning every TLS deploy
    # died with "template changed shape". Found by switching a real server to TLS.
    if grep -q '/etc/letsencrypt/live/CERT_DOMAIN/' "${TLS_OUT}.tmp"; then
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
docker compose "${COMPOSE_ARGS[@]}" pull || die "docker pull failed" 3

if [ "$ACTION" = "deploy" ]; then
    # Maintenance mode goes up BEFORE the new images do. Between `up -d` and the
    # migration below there is a wait on MySQL and Redis that can run for minutes
    # on a cold start, and every second of it would otherwise serve new code
    # against the old schema. The flag lives in storage/framework, a named
    # volume, so it survives the container recreation that `up -d` performs.
    # Fails harmlessly on a first bring-up, when no app container exists yet.
    log "Enabling maintenance mode..."
    # Deliberately over-approximate: MAINTENANCE_ON is set whenever an app
    # container EXISTS, regardless of what `artisan down` returns.
    #
    # `down` writes storage/framework/down before it writes maintenance.php and
    # before it prints anything, so it can fail -- disk full, or the ssh client
    # dying mid-command -- with the flag already on disk. Trusting its exit
    # status would leave MAINTENANCE_ON=false while the site is 503ing, and the
    # cleanup below would skip it. The errors are not symmetric: a false positive
    # costs one no-op `artisan up`, a false negative is a silent outage.
    if [ -n "$(docker compose "${COMPOSE_ARGS[@]}" ps -q app 2>/dev/null)" ]; then
        MAINTENANCE_ON=true
        docker compose "${COMPOSE_ARGS[@]}" exec -T app php artisan down --retry=15 </dev/null \
            || log "artisan down reported failure -- assuming the flag may be set anyway"
    else
        log "No app container yet -- skipping maintenance mode (first bring-up)"
    fi
fi

log "Starting containers..."
docker compose "${COMPOSE_ARGS[@]}" up -d --remove-orphans

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
    # Re-assert now that the container definitely exists: on a first bring-up the
    # `down` above had nothing to run in. Same over-approximation as there.
    MAINTENANCE_ON=true
    docker compose "${COMPOSE_ARGS[@]}" exec -T app php artisan down --retry=15 </dev/null \
        || log "artisan down reported failure -- assuming the flag may be set anyway"
    if ! docker compose "${COMPOSE_ARGS[@]}" exec -T app php artisan migrate --force </dev/null; then
        # Stay in maintenance. A failed migration leaves the schema in an
        # unknown, possibly half-applied state, and serving the new code against
        # it risks user-visible errors and bad writes -- worse than an honest
        # 503. Safe only because `rollback` lifts the flag before it does
        # anything else, and because the lift falls back to deleting the flag
        # from the volume when the app container is unusable.
        KEEP_MAINTENANCE=true
        die "migrations failed -- site left in maintenance mode on ${VERSION}. Recover with: ssh deploy@host 'rollback ${PREVIOUS}'" 3
    fi
else
    # Rolling back an image does not roll back schema. Additive migrations are
    # survivable, drops and renames are not -- see migrations:check-rollback.
    log "Rollback: skipping migrations (schema is NOT reverted)"
fi

log "Rebuilding caches..."
docker compose "${COMPOSE_ARGS[@]}" exec -T app php artisan optimize:clear </dev/null
docker compose "${COMPOSE_ARGS[@]}" exec -T app php artisan filament:optimize-clear </dev/null
docker compose "${COMPOSE_ARGS[@]}" exec -T app php artisan config:cache </dev/null
docker compose "${COMPOSE_ARGS[@]}" exec -T app php artisan route:cache </dev/null
docker compose "${COMPOSE_ARGS[@]}" exec -T app php artisan view:cache </dev/null
docker compose "${COMPOSE_ARGS[@]}" exec -T app php artisan event:cache </dev/null
docker compose "${COMPOSE_ARGS[@]}" exec -T app php artisan filament:optimize </dev/null
# Only when it is actually missing.
#
# docker/entrypoint.sh already creates this link on container start, so by the
# time a deploy reaches this line it always exists -- and `storage:link` then
# prints "ERROR  The [public/storage] link already exists." on EVERY deploy. One
# red line in an otherwise clean log is not harmless: it sends the next person
# reading a deploy log hunting for a problem that is not there.
#
# Kept as a safety net rather than deleted, for the case where the entrypoint
# did not run, but it now stays quiet when there is nothing to do.
docker compose "${COMPOSE_ARGS[@]}" exec -T app \
    sh -c '[ -L public/storage ] || php artisan storage:link' </dev/null || true

# No `composer install` here. The image ships a built vendor/ directory; running
# composer against a live container adds a packagist dependency mid-deploy and
# writes into a container layer that the next up -d discards.

log "Restarting Horizon..."
docker compose "${COMPOSE_ARGS[@]}" restart horizon

# Both asset gates fail through here.
#
# KEEP_MAINTENANCE only bites on the `deploy` path -- `rollback` never sets
# MAINTENANCE_ON (it lifts any stranded flag at startup and adds none), so on
# that path the site is LIVE and saying otherwise would send an operator looking
# for a 503 that is not there.
#
# The advice is deliberately "re-run the same tag" rather than "roll back": the
# sync is idempotent, so a transient copy failure clears on a second attempt,
# whereas rolling back does NOT revert migrations and would put old code against
# the new schema -- worse than mismatched static files.
asset_gate_failed() {
    KEEP_MAINTENANCE=true
    if [ "$MAINTENANCE_ON" = true ]; then
        die "$1 -- site left in maintenance. Re-run the same tag first (the sync is idempotent): ssh deploy@host '${ACTION} ${VERSION}'" 3
    fi
    die "$1 -- site is LIVE with assets that do not match ${VERSION}. Re-run the same tag first (the sync is idempotent): ssh deploy@host '${ACTION} ${VERSION}'" 3
}

# Frontend assets: the volume's build/ must be THIS image's build/.
#
# The health check above cannot see this. /var/www/public is a NAMED VOLUME
# shared with nginx, and Docker seeds those from the image only when empty, so a
# regression in the entrypoint sync leaves an older release's build/ in place.
#
# Note what that failure actually looks like, because it is easy to describe
# wrongly: the frozen build/ keeps its manifest AND its assets together, so it
# stays internally consistent and nothing 404s. The site serves the OLD frontend
# indefinitely -- a UI fix is deployed, reported successful, and simply never
# appears. The sharp edge is a newly added Vite entry point: it is absent from
# the stale manifest, so Laravel raises "Unable to locate file in Vite manifest"
# and that page 500s.
#
# Comparing the volume's manifest against the image's is therefore the check
# that matters. Verifying only that referenced files exist passes happily on a
# volume that is a year out of date -- verified by reproduction.
log "Verifying frontend assets..."
docker compose "${COMPOSE_ARGS[@]}" exec -T app php -r '
    $imageDir = "/tmp/public/build";
    $liveDir  = "/var/www/public/build";

    if (!is_file("$imageDir/manifest.json")) {
        fwrite(STDERR, "image has no build/manifest.json -- was the frontend built?\n");
        exit(1);
    }
    if (!is_file("$liveDir/manifest.json")) {
        fwrite(STDERR, "no manifest.json in the public volume -- the entrypoint sync did not run\n");
        exit(1);
    }
    if (hash_file("sha256", "$imageDir/manifest.json") !== hash_file("sha256", "$liveDir/manifest.json")) {
        fwrite(STDERR, "the public volume holds a DIFFERENT release than this image;\n");
        fwrite(STDERR, "the site would keep serving the old frontend. See the sync in docker/entrypoint.sh.\n");
        exit(1);
    }

    $entries = json_decode(file_get_contents("$liveDir/manifest.json"), true);
    if (!is_array($entries) || $entries === []) {
        fwrite(STDERR, "manifest.json is empty or unreadable\n");
        exit(1);
    }

    // Catches a partial or interrupted copy, which the hash comparison alone
    // would not: manifest.json can land while the files it names do not.
    $missing = [];
    foreach ($entries as $entry) {
        foreach (["file", "css", "assets"] as $key) {
            foreach ((array) ($entry[$key] ?? []) as $ref) {
                if (!is_file("$liveDir/$ref")) {
                    $missing[] = $ref;
                }
            }
        }
    }
    if ($missing !== []) {
        fwrite(STDERR, sprintf(
            "%d asset(s) named by the manifest are not in the volume, e.g. %s\n",
            count($missing),
            implode(", ", array_slice($missing, 0, 3))
        ));
        exit(1);
    }

    printf("%d manifest entries, matching this image, all files present\n", count($entries));
' </dev/null || asset_gate_failed "build/ in the volume does not match this image"

# The manifest check above covers build/ only. This covers the rest of public/ --
# index.php, .htaccess, css/, js/, fonts/, images/, vendor/ -- which the same
# freeze had pinned just as hard, and which the entrypoint syncs entry by entry
# with its own failure paths. `storage` is excluded: it is a runtime symlink that
# deliberately does not exist in the image.
log "Verifying the rest of public/..."
# `--exclude=storage` already removes the runtime symlink from the comparison,
# and diff does not follow it. Measured at ~10 ms over 78 files / 11 MB.
# `out` is captured rather than piped so diff's own exit status is not lost:
# a diff that failed to run would otherwise produce no output and pass the gate.
docker compose "${COMPOSE_ARGS[@]}" exec -T app sh -c '
    out="$(diff -rq --exclude=storage /tmp/public /var/www/public 2>&1)"
    rc=$?
    if [ "$rc" -gt 1 ]; then
        echo "diff failed to run: $out"
        exit 1
    fi
    if [ -n "$out" ]; then
        echo "$out"
        exit 1
    fi
    echo "public/ matches the image"
' </dev/null || asset_gate_failed "public/ differs from the image beyond build/"

# Lift maintenance HERE: after the caches are warm, so the first real request
# does not land on a container that just had optimize:clear run against it, and
# before the health check, which goes through nginx to /up and would get the
# maintenance 503 otherwise.
clear_maintenance

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
#
# The residual, stated plainly: this position has a mirror-image window. If the
# health check fails AFTER migrations succeeded, .env still names the PREVIOUS
# version, so a reboot brings containers up on the old image against the new
# schema. That is the better default -- old code on a migrated schema usually
# degrades, new code on an unmigrated one corrupts -- but it is a trade, not a
# solution. A migration containing a drop or a rename makes it an outage either
# way; roll forward, do not reboot and hope.
log "Pinning REGISTRO_VERSION=${VERSION} in .env..."
if grep -q '^REGISTRO_VERSION=' .env; then
    sed -i "s|^REGISTRO_VERSION=.*|REGISTRO_VERSION=${VERSION}|" .env
else
    echo "REGISTRO_VERSION=${VERSION}" >> .env
fi

log "Pruning images older than 24h..."
docker image prune -af --filter "until=24h" >/dev/null 2>&1 || true

log "=== ${ACTION} ${VERSION} OK (was ${PREVIOUS}) ==="

# `|| true` and an explicit `exit 0`: this is cosmetic output, and as the last
# command its status would otherwise become the script's. A hiccup in `ps` must
# not report a failed deploy for a deploy that succeeded.
docker compose "${COMPOSE_ARGS[@]}" ps || true
exit 0
