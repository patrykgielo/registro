#!/bin/bash
###############################################################################
# Keeps the TLS certificate covering every live tenant subdomain.
#
# Installed by scripts/setup-production-server.sh to /opt/registro/, owned
# root:root. Run from cron as root -- certbot needs to write /etc/letsencrypt
# and nginx needs reloading, neither of which the deploy user can do.
#
# WHY THIS EXISTS AND NOT A WILDCARD
#
# Let's Encrypt issues wildcard certificates only through the DNS-01 challenge,
# which requires publishing _acme-challenge.<domain> TXT. This host's name lives
# inside Hostinger's own hstgr.cloud zone -- confirmed: the SOA and NS for
# srv1342834.hstgr.cloud are any1/any2.hostinger.com, and there is no delegation
# to a zone we control. A wildcard is therefore not obtainable here at all.
#
# What IS obtainable: one certificate carrying every tenant subdomain as a SAN,
# validated over HTTP-01. Hostinger publishes a wildcard A record, so every
# <slug>.<domain> already resolves to this machine and nginx answers the ACME
# path from the catch-all server block.
#
# hstgr.cloud is on the Public Suffix List, so Let's Encrypt treats
# srv1342834.hstgr.cloud as its own registered domain: the rate limits are this
# server's alone rather than shared with every Hostinger VPS. Budget is 50 new
# orders per week and 100 names per certificate -- so this reconciles only when
# the name set actually changed, never on a timer.
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
if ! docker exec registro-nginx nginx -t >>"$LOG_FILE" 2>&1; then
    die "nginx rejected its configuration after renewal -- NOT reloading"
fi

docker exec registro-nginx nginx -s reload >>"$LOG_FILE" 2>&1 \
    || die "nginx reload failed"

log "Certificate now covers ${COUNT} name(s); nginx reloaded"
