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

readonly APP_DIR="/var/www/registro"
readonly COMPOSE_FILE="docker-compose.prod.yml"
readonly LOG_FILE="/var/log/registro-certificate.log"
readonly WEBROOT="/var/www/letsencrypt"

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
# certificate's ACME webroot and terminating TLS, set
# NGINX_RELOAD_CONTAINER=registro-edge-nginx in .env -- no script change
# needed. Task 6 (the `apply` script) is expected to write this var itself
# once it performs that cutover; until then it's a manual step.
NGINX_CONTAINER="$(grep -m1 '^NGINX_RELOAD_CONTAINER=' .env 2>/dev/null | cut -d= -f2- || true)"
[ -n "${NGINX_CONTAINER:-}" ] || NGINX_CONTAINER="registro-nginx"

EMAIL="$(grep -m1 '^MAIL_FROM_ADDRESS=' .env 2>/dev/null | cut -d= -f2- | tr -d '"' || true)"
[ -n "${EMAIL:-}" ] || EMAIL="admin@${CERT_DIR}"

###############################################################################
# What the certificate should cover
###############################################################################

# Run as the deploy user: the app container is theirs, and root does not need a
# docker session to read a list of names.
DESIRED="$(su - deploy -s /bin/bash -c \
    "cd ${APP_DIR} && docker compose -f ${COMPOSE_FILE} exec -T app php artisan tenants:hostnames" \
    2>/dev/null | tr -d '\r' | grep -E '^[A-Za-z0-9.-]+$' | sort -u || true)"

[ -n "$DESIRED" ] || die "could not read the hostname list from the application"

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
        DESIRED="$(printf '%s\n%s\n' "$DESIRED" "$WWW_DOMAIN" | sort -u)"
        log "${WWW_DOMAIN} resolves -- including it in the certificate"
    else
        log "${WWW_DOMAIN} does not resolve -- not including it"
    fi
fi

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
