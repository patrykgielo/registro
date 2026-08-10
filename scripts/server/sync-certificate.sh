#!/bin/bash
###############################################################################
# Keeps the TLS certificate covering every live tenant subdomain.
#
# Installed by scripts/setup-production-server.sh to /opt/registro/, owned
# root:root. Run from cron as root -- certbot needs to write /etc/letsencrypt
# and nginx needs reloading, neither of which the deploy user can do.
#
# WHY THIS EXISTS, AND WHY IT'S SANs AND NOT A WILDCARD (YET)
#
# The app domain is registrolabs.com, a domain we own -- we control its DNS
# zone, so a wildcard IS technically obtainable (Let's Encrypt issues wildcards
# via the DNS-01 challenge, which needs _acme-challenge.registrolabs.com TXT
# published, and nothing stops us from publishing it). It just isn't
# implemented yet: the zone is parked on Hostinger, and Hostinger is supported
# by neither certbot's DNS plugins nor acme.sh's dnsapi (checked directly
# against both plugin lists). Getting DNS-01 working means writing custom
# certbot --manual-auth-hook/--manual-cleanup-hook scripts against Hostinger's
# REST API -- which does support adding a single TXT with overwrite=false and
# deleting by name+type, so the hooks are plausible, just not written.
#
# What IS implemented, and what this script does: one certificate carrying
# every tenant subdomain as a SAN, validated over HTTP-01. registrolabs.com
# publishes a wildcard A/AAAA record, so every <slug>.<domain> already resolves
# to this machine and nginx answers the ACME path from the catch-all server
# block. Cost of staying on this approach: a new tenant has a ~15-minute window
# between signup and its subdomain being covered by the certificate (until the
# next reconcile), and every tenant slug that has ever existed is permanently
# visible in public Certificate Transparency logs. A wildcard would remove both.
#
# registrolabs.com is on Let's Encrypt's own registered-domain boundary (it is
# the registered domain, not a suffix inside someone else's), so the rate
# limits are this server's alone rather than shared with anything else on
# Hostinger. Budget is 50 new orders per week and 100 names per certificate --
# so this reconciles only when the name set actually changed, never on a timer.
#
# Exit codes: 0 - nothing to do or success, 1 - error
###############################################################################

set -euo pipefail

# Overridable for this script's own validation harness -- default unchanged
# (the real legacy checkout). Same name apply.sh already uses for the
# identical concept (its own LEGACY_APP_DIR), so a sandbox that fakes one
# fakes both with no new convention to learn.
readonly APP_DIR="${REGISTRO_LEGACY_APP_DIR:-/var/www/registro}"
readonly COMPOSE_FILE="docker-compose.prod.yml"
# Same override convention as tenant-check.sh's own REGISTRO_TENANT_CHECK_LOG
# -- lets this script's validation harness assert on log content without
# writing to /var/log.
readonly LOG_FILE="${REGISTRO_CERTIFICATE_LOG:-/var/log/registro-certificate.log}"
readonly WEBROOT="/var/www/letsencrypt"
# Same env var name and default apply.sh already uses for the identical
# concept (where dedicated tenant stacks live) -- a sandbox that fakes one
# fakes both.
readonly STACKS_ROOT="${REGISTRO_STACKS_ROOT:-/opt/stacks}"

log() {
    local line="[$(date '+%Y-%m-%d %H:%M:%S')] $*"
    echo "$line" >>"$LOG_FILE" 2>/dev/null || true
    echo "$line" 2>/dev/null || true
}

die() {
    log "ERROR: $1"
    exit 1
}

[ "$(id -u)" -eq 0 ] || die "must run as root (certbot writes /etc/letsencrypt)"
cd "$APP_DIR" || die "$APP_DIR not found"

CERT_DIR="$(grep -m1 '^CERT_DIR=' .env 2>/dev/null | cut -d= -f2- || true)"
[ -n "${CERT_DIR:-}" ] || die "CERT_DIR not set in .env -- run deploy-init.sh first"

# Which container to `nginx -t`/`-s reload`. Defaults to today's hardcoded
# name so the live single-stack deployment (docker-compose.prod.yml) keeps
# working with no .env change at all. Once the edge stack (docker-compose.edge.yml,
# see app/docs/deployment/edge-stack.md) is the one actually holding the
# certificate's ACME webroot and terminating TLS, this key reads
# NGINX_RELOAD_CONTAINER=registro-edge-nginx from .env -- no script change
# needed. `apply.sh`'s own edge-sync step writes this line itself, but only
# once it can see the edge container is ACTUALLY running with the TLS config
# (checked against the container's own bind mount, not an env var) -- until
# the documented manual cutover (edge-stack.md's "Cutover sequencing") has
# happened, this stays unset/registro-nginx on purpose.
NGINX_CONTAINER="$(grep -m1 '^NGINX_RELOAD_CONTAINER=' .env 2>/dev/null | cut -d= -f2- || true)"
[ -n "${NGINX_CONTAINER:-}" ] || NGINX_CONTAINER="registro-nginx"

EMAIL="$(grep -m1 '^MAIL_FROM_ADDRESS=' .env 2>/dev/null | cut -d= -f2- | tr -d '"' || true)"
[ -n "${EMAIL:-}" ] || EMAIL="admin@${CERT_DIR}"

###############################################################################
# What the certificate should cover
###############################################################################

# --- Legacy shared stack ----------------------------------------------------
#
# A machine with no legacy stack running (a fresh PreProd box, per the
# two-machines plan's Faza 4 -- "checkout jako katalog sterujący ... bez
# uruchamiania starych kontenerów") is a LEGAL configuration that contributes
# ZERO legacy names, not an error -- this script must reconcile there too, not
# just on the one machine that still runs the legacy shared stack. The
# distinction that matters is "is the legacy stack actually running" versus
# "is it running but broken".
#
# Probed via `docker inspect` on the container's OWN fixed name, NOT
# `docker compose ps` -- found by infrastructure review and reproduced
# directly: `docker compose ps` (like every other Compose subcommand,
# ci-cd-troubleshooting.md's own established fact) interpolates the WHOLE
# .env file before it can answer anything, and docker-compose.prod.yml
# hard-requires APP_KEY/APP_DOMAIN/REDIS_PASSWORD. A blanked or corrupted
# legacy .env -- the exact REDIS_PASSWORD incident already in that chronicle
# -- makes `docker compose ps -q app` FAIL (nonzero exit, empty stdout) even
# while the container is still up and serving live traffic on the
# environment it was originally started with. Read through the old
# `2>/dev/null || true` probe, that failure was indistinguishable from
# "legitimately not running" -- exactly the silent-shrink bug this file
# exists to prevent, reintroduced one level down: certbot would reissue
# stripped of every legacy hostname while the legacy stack keeps serving on
# the old certificate. `docker inspect <name>` is a raw query against the
# container object itself; it never touches compose interpolation, so a
# broken .env cannot break it.
#
# The container's name is FIXED, not read from anywhere: docker-compose.prod.yml's
# own `container_name: ${TENANT_PREFIX:-registro}-app`, and TENANT_PREFIX
# stays unset on the legacy stack by design (that file's own header) --
# same reasoning NGINX_CONTAINER's own "registro-nginx" default above
# already relies on for the identical constant.
readonly LEGACY_APP_CONTAINER="registro-app"

# Run as the deploy user: the app container is theirs, and root does not need
# a docker session to read a list of names.
#
# `VAR="$(cmd)"` alone is NOT a condition under `set -e` -- already bit this
# project once (apply.sh's own PROVISION_RC, ci-cd-troubleshooting.md's
# "6 bugów" incident, point 4): when the substituted command fails, `set -e`
# kills the script AT THIS LINE, before `LEGACY_INSPECT_RC=$?` on the next
# line ever runs -- reproduced here directly (docker inspect against a
# nonexistent container, the exact "absent checkout" case this branch is
# supposed to handle, silently exited the whole script instead of falling
# through to the "no such object" branch below). `|| LEGACY_INSPECT_RC=$?`
# makes the compound statement's own exit status 0 regardless of which
# branch ran, which is what `set -e` needs to not treat this as a failure.
LEGACY_INSPECT_RC=0
LEGACY_INSPECT_OUTPUT="$(su - deploy -s /bin/bash -c \
    "docker inspect --format '{{.State.Running}}' ${LEGACY_APP_CONTAINER}" \
    2>&1)" || LEGACY_INSPECT_RC=$?

DESIRED=""
if [ "$LEGACY_INSPECT_RC" -eq 0 ] && [ "$LEGACY_INSPECT_OUTPUT" = "true" ]; then
    DESIRED="$(su - deploy -s /bin/bash -c \
        "cd ${APP_DIR} && docker compose -f ${COMPOSE_FILE} exec -T app php artisan tenants:hostnames" \
        2>/dev/null | tr -d '\r' | grep -E '^[A-Za-z0-9.-]+$' | sort -u || true)"
    # The container answered "yes, I am running" (above) but the query itself
    # came back empty or failed -- this is the "legacy stack running but
    # broken" case the fail-safe below (dedicated stacks, same shape) also
    # guards against. Never silently reconcile against a shrunken list.
    [ -n "$DESIRED" ] \
        || die "legacy stack is running but 'tenants:hostnames' returned no names or the query failed -- aborting WITHOUT touching the certificate"
    log "Legacy stack: $(printf '%s' "$DESIRED" | tr '\n' ' ')"
elif [ "$LEGACY_INSPECT_RC" -eq 0 ] && [ "$LEGACY_INSPECT_OUTPUT" = "false" ]; then
    log "Legacy container '${LEGACY_APP_CONTAINER}' exists but is not running -- contributing zero legacy hostnames"
elif [ "$LEGACY_INSPECT_RC" -ne 0 ] \
        && printf '%s' "$LEGACY_INSPECT_OUTPUT" | grep -qi 'no such object\|no such container'; then
    log "No '${LEGACY_APP_CONTAINER}' container on this machine -- contributing zero legacy hostnames (this machine never ran the legacy stack)"
else
    # `docker inspect` itself failed for a reason OTHER than "the container
    # genuinely does not exist" -- daemon unreachable, permission denied, `su`
    # failing to even invoke docker. THIS is the "I could not tell" case, and
    # it must never be silently read as "nothing here" -- doing so would be
    # the exact silent-shrink bug this file exists to prevent.
    die "could not determine whether the legacy stack is running (docker inspect failed unexpectedly, exit ${LEGACY_INSPECT_RC}): $(printf '%s' "$LEGACY_INSPECT_OUTPUT" | tail -3 | tr '\n' ' ')"
fi

###############################################################################
# --- Dedicated per-tenant stacks (task 6, stack-per-tenant epic) -----------
#
# Each dedicated stack under STACKS_ROOT/<slug>/ has its OWN database and is
# never seen by the `tenants:hostnames` call above -- that command only ever
# queries the LEGACY stack's own organizations table. Before this section
# existed, a tenant provisioned onto its own stack was simply absent from the
# certificate, and worse: certbot --expand reissues against EXACTLY the name
# list computed here, so any SAN an operator added for it by hand was
# silently stripped on the very next 15-minute cron run.
#
# WHY THE SOURCE IS TENANT_HOSTS, NOT ANOTHER tenants:hostnames CALL
#
# tenants:hostnames' own logic (baseDomain, then "<org-slug>.<baseDomain>" for
# every active/suspended org) is written for the legacy shared-tenant
# architecture, where baseDomain is the bare app domain and org slugs are
# subdomains OF it. Neither holds on a dedicated stack: apply.sh sets
# APP_DOMAIN to the tenant's own PRIMARY_HOST (already e.g.
# "acme.registrolabs.com", not "registrolabs.com"), and the one organization
# row inside that stack's own database carries the SAME tenant slug again
# ("acme", set by registro:tenant-provision --slug). Pointing tenants:hostnames
# at a dedicated stack would therefore emit "acme.acme.registrolabs.com" -- a
# name nothing serves and the single-level wildcard A record does not cover,
# which would fail HTTP-01 validation for that one name and (Let's Encrypt
# rejects the WHOLE order on any single failed name) break certificate
# renewal for every tenant AND the legacy stack together.
#
# TENANT_HOSTS is not a proxy for the right answer, it IS the right answer:
# app/Support/TrustedTenantHosts.php and ResolveTenant already treat it as
# the exact, fixed-at-apply-time allowlist of "every hostname this container
# is willing to answer on" (see config/app.php's own comment on the key) --
# precisely what belongs on the certificate, no recomputation needed.
#
# Read from the stack's OWN .env ON DISK, not from the running container's
# environment (docker compose exec) -- changed from the original design,
# which used the live container specifically so the query would double as a
# reachability probe. That was correct for "one always-on stack per client";
# it stopped being correct once UAT started hosting prospect projects that
# get created, torn down, and left stopped between sessions. "Directory
# present, container down" became a NORMAL state there, and this script's own
# fail-safe (below) treated it as indistinguishable from "broken", freezing
# certificate renewal for every OTHER tenant on the box over one sleeping
# stack. apply.sh writes TENANT_HOSTS into this same stack's .env (see its
# own "reconcile .env" step) with the IDENTICAL value the container would
# report -- it is not a proxy for the live value, it is the same value,
# readable whether or not the container happens to be running right now.
###############################################################################

read_tenant_stack_hostnames() {
    local dir="$1" raw
    local envfile="${dir}/.env"
    if [ ! -f "$envfile" ]; then
        log "ERROR: ${envfile} missing"
        return 1
    fi
    raw="$(grep -m1 '^TENANT_HOSTS=' "$envfile" 2>/dev/null | cut -d= -f2- || true)"
    if [ -z "$raw" ]; then
        log "ERROR: TENANT_HOSTS not set (or empty) in ${envfile}"
        return 1
    fi
    # Same trim/lowercase/split-on-comma normalisation config/app.php applies
    # to TENANT_HOSTS itself, so what this script compares is exactly what
    # ResolveTenant/TrustedTenantHosts compare too.
    printf '%s' "$raw" | tr ',' '\n' | tr -d '\r' | tr '[:upper:]' '[:lower:]' \
        | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' \
        | grep -E '^[a-z0-9.-]+$' | sort -u
}

if [ -d "$STACKS_ROOT" ]; then
    # `find`, unlike a bash glob, does NOT skip dot-entries by default --
    # already bit tenant-check.sh once (its own comment on this exact
    # exclusion). Without -not -name '.*' this would report apply.sh's own
    # STACKS_ROOT/.state/ bookkeeping directory as a "tenant" to query.
    while IFS= read -r stack_dir; do
        [ -n "$stack_dir" ] || continue
        slug="$(basename "$stack_dir")"

        if [ ! -f "${stack_dir}/${COMPOSE_FILE}" ]; then
            # Not a provisioned stack -- either genuine clutter under
            # STACKS_ROOT, or a `git clone` (apply.sh's own first step) that
            # died before checkout finished. Nothing here could plausibly
            # contribute a hostname yet, so this is a quiet skip, never an
            # abort -- unlike a directory that DOES have a compose file and
            # still has no readable TENANT_HOSTS, below.
            log "Skipping ${stack_dir} -- no ${COMPOSE_FILE}, not a provisioned stack"
            continue
        fi

        log "Reading tenant stack '${slug}' hosts from .env..."
        if ! STACK_NAMES="$(read_tenant_stack_hostnames "$stack_dir")" || [ -z "$STACK_NAMES" ]; then
            # THE core fail-safe requirement, unchanged: a provisioned stack
            # whose hosts cannot be read is a reason to do NOTHING, never a
            # reason to proceed with a name list that silently omits it --
            # certbot's own --expand would then strip its SAN from the live
            # certificate on this exact run. die() below runs before
            # anything has touched certbot, so the current certificate is
            # left byte-for-byte as it was. What changed is what counts as
            # "cannot be read": a stopped container no longer does (see the
            # block comment above) -- only a missing/unreadable .env or an
            # empty/missing TENANT_HOSTS key inside it does now.
            die "tenant stack '${slug}' (${stack_dir}) exists but its TENANT_HOSTS could not be read from .env -- aborting WITHOUT touching the certificate; its current name list is left exactly as it is. Investigate ${stack_dir}/.env and re-run."
        fi
        # `sed '/^$/d'` matters now in a way it didn't before this task: DESIRED
        # can legitimately start as an EMPTY string (no legacy stack running,
        # see above), and `printf '%s\n%s\n' "" "$STACK_NAMES"` would otherwise
        # leave one blank line in the merged, sorted result -- which `sort -u`
        # then treats as a distinct "name", producing a bare `-d ` argument to
        # certbot further down. Found by actually running this exact scenario
        # (no legacy stack, one dedicated stack) in the sandbox, not by
        # inspection.
        DESIRED="$(printf '%s\n%s\n' "$DESIRED" "$STACK_NAMES" | sed '/^$/d' | sort -u)"
        log "Tenant stack '${slug}': $(printf '%s' "$STACK_NAMES" | tr '\n' ' ')"
    done < <(find "$STACKS_ROOT" -mindepth 1 -maxdepth 1 -type d -not -name '.*' | sort)
else
    # Today's state (and the ONLY state before task 4/6 landed) -- must
    # behave exactly as before: legacy hostnames only.
    log "${STACKS_ROOT} does not exist -- only the legacy stack's hostnames are considered"
fi

# tenants:hostnames deliberately means "tenants" (app.domain plus tenant
# subdomains) -- see ListTenantHostnamesCommand's own docblock -- so it never
# emits www on purpose. Added here instead, and only if it actually resolves:
# Let's Encrypt fails the WHOLE request when any single name fails HTTP-01
# validation, so an unconditional add would silently break renewal for every
# name on the certificate the moment www stopped resolving. Mirrors the same
# check in scripts/deploy-init.sh.
APP_DOMAIN="$(grep -m1 '^APP_DOMAIN=' .env 2>/dev/null | cut -d= -f2- || true)"
if [ -n "$APP_DOMAIN" ]; then
    WWW_DOMAIN="www.${APP_DOMAIN}"
    if host "$WWW_DOMAIN" >/dev/null 2>&1 || getent hosts "$WWW_DOMAIN" >/dev/null 2>&1; then
        # Same blank-line guard as the dedicated-stacks merge above -- DESIRED
        # may still be empty here (e.g. www is the ONLY source that ends up
        # contributing anything).
        DESIRED="$(printf '%s\n%s\n' "$DESIRED" "$WWW_DOMAIN" | sed '/^$/d' | sort -u)"
        log "${WWW_DOMAIN} resolves -- including it in the certificate"
    else
        log "${WWW_DOMAIN} does not resolve -- not including it"
    fi
fi

# The one case none of the three sources above can rule out on its own: a
# machine with no legacy stack running (legitimate, see above) AND no
# dedicated stacks under STACKS_ROOT yet (also legitimate, first-boot state)
# ends up here with nothing collected at all. Legitimate per-source, but
# never a reason to ask certbot for a certificate covering zero names.
[ -n "$DESIRED" ] \
    || die "no hostnames collected from any source (legacy stack, dedicated tenant stacks, or www) -- refusing to touch the certificate for zero names"

###############################################################################
# What it covers today
###############################################################################

CURRENT="$(certbot certificates --cert-name "$CERT_DIR" 2>/dev/null \
    | awk '/Domains:/ { $1=""; print }' | tr ' ' '\n' | grep -E '^[A-Za-z0-9.-]+$' | sort -u || true)"

if [ "$DESIRED" = "$CURRENT" ]; then
    log "Certificate already covers $(echo "$DESIRED" | wc -l) name(s) -- nothing to do"
    exit 0
fi

log "Name set changed:"
log "  added:   $(comm -23 <(echo "$DESIRED") <(echo "$CURRENT") | tr '\n' ' ')"
log "  removed: $(comm -13 <(echo "$DESIRED") <(echo "$CURRENT") | tr '\n' ' ')"

# 100 SANs per certificate is a hard Let's Encrypt limit. Failing here is far
# better than issuing a request that is rejected and counts against the weekly
# order budget anyway.
COUNT="$(echo "$DESIRED" | wc -l)"
[ "$COUNT" -le 100 ] || die "$COUNT names exceeds the 100-per-certificate limit; split across certificates"

###############################################################################
# Re-issue
###############################################################################

DOMAIN_ARGS=()
while IFS= read -r name; do
    DOMAIN_ARGS+=(-d "$name")
done <<<"$DESIRED"

log "Requesting certificate for ${COUNT} name(s)..."

# --cert-name pins the lineage so the path nginx is configured with never
# changes; without it certbot would create <domain>-0001 and nginx would keep
# reading the old files.
if ! certbot certonly --webroot -w "$WEBROOT" "${DOMAIN_ARGS[@]}" \
        --cert-name "$CERT_DIR" --expand \
        --email "$EMAIL" --agree-tos --no-eff-email --non-interactive >>"$LOG_FILE" 2>&1; then
    die "certbot failed -- see $LOG_FILE (each failed validation counts against the rate limit)"
fi

# Reload rather than restart: the certificate path is unchanged, so nginx only
# needs to re-read the files, and open connections are not dropped.
if ! docker exec "$NGINX_CONTAINER" nginx -t >>"$LOG_FILE" 2>&1; then
    die "nginx rejected its configuration after renewal -- NOT reloading"
fi

docker exec "$NGINX_CONTAINER" nginx -s reload >>"$LOG_FILE" 2>&1 \
    || die "nginx reload failed"

log "Certificate now covers ${COUNT} name(s); nginx reloaded"
