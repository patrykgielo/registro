#!/bin/bash
###############################################################################
# Reports how many days the certificate ACTUALLY SERVED on :443 has left,
# and fails loudly once that drops below a warning/critical threshold.
#
# WHY THE SERVED CERTIFICATE, NOT THE FILE ON DISK
#
# sync-certificate.sh only ever touches /etc/letsencrypt and reloads nginx --
# it never proves the running nginx picked the new file up. This project has
# already been bitten by exactly that gap on the config side (2026-08-08,
# see project_domain_migration memory / ci-cd-troubleshooting.md's nginx
# incident): a single-file bind mount holds its inode, so a rewritten file on
# disk can leave the running container serving the OLD one indefinitely while
# every on-disk check says "renewed". Reading /etc/letsencrypt/live/.../
# cert.pem directly would repeat that exact mistake for certificates instead
# of nginx config. `openssl s_client` against the real socket is what a
# browser gets -- it is the one check that also catches "renewed on disk,
# never reloaded", not just "about to expire". The cost: this script needs
# network access to :443 and nothing to answer on it is itself a finding,
# not a silent pass -- see the CRITICAL/exit-3 branch below.
#
# WHAT THIS DOES NOT CHECK, ON PURPOSE
#
# The certificate is one SAN list shared by every tenant (deployment.md,
# sync-certificate.sh's own header) -- expiry is a property of that ONE leaf
# certificate, not of any single name on it, so one connection is enough.
# Per-name COVERAGE (a name silently missing from a reissued certificate) is
# a DIFFERENT failure this project has already hit and already fixed
# (ci-cd-troubleshooting.md, "sync-certificate.sh enumerował TYLKO starą
# bazę") -- that reconciliation lives in sync-certificate.sh, which recomputes
# the full desired name list every 15 minutes and dies rather than silently
# dropping a name. Re-deriving that same list here would duplicate logic that
# can drift out of sync with the real fix. This script still surfaces the
# SAN count it observed in its log line, purely informational, so a human
# comparing two runs can notice a sudden drop -- it does not fail on it.
#
# FRAGILE DEPENDENCY ON TODAY'S "ONE CERT FOR EVERYONE" ARCHITECTURE
#
# The default SNI (CERT_DIR, read below) is only guaranteed to reach a real
# certificate today because nginx does NOT yet select a certificate per SNI
# -- one shared cert file answers every server block regardless of what name
# is presented (edge-tls.conf's own header). On a legacy-free machine
# (PreProd, instalacja-tenanta-od-zera.md 10.3) CERT_DIR is set to the bare
# apex (e.g. registroapps.com) purely as certbot's `--cert-name` LABEL --
# that section explicitly documents the apex itself is NEVER a SAN on the
# certificate, only `<slug>.registroapps.com` per tenant is. Once per-domain
# SNI-based cert selection ships (edge-tls.conf's own documented next step),
# presenting SNI=registroapps.com will hit whatever server block matches
# that exact name, if any -- not necessarily the certificate actually
# protecting real tenant traffic. Whoever does that wildcard work should
# revisit this default (point REGISTRO_CERT_CHECK_SNI at a real tenant
# hostname instead of CERT_DIR on a legacy-free machine) at the same time.
#
# WHY THIS THRESHOLD
#
# certbot's own renewal timer (independent of sync-certificate.sh's 15-minute
# reconcile, which only re-issues on a NAME CHANGE -- see that script's own
# "Certificate already covers N name(s) -- nothing to do" branch) renews at
# 30 days remaining. Being AT OR BELOW 30 days with no recent renewal is
# therefore itself suspicious -- the mechanism that should already have fired
# has not -- so 30 is the WARNING threshold, not the deadline. 14 days is the
# hard CRITICAL threshold: half of certbot's own remaining runway, chosen to
# leave a human real time to intervene manually (certbot's own hooks, a
# manual `certonly`, or the machine's clock/network) before the actual
# outage a silent renewal failure would otherwise produce.
#
# HOW IT REPORTS -- fits tenant-check.sh's own convention, not a new one:
# silent and exit 0 on a clean run, nothing written anywhere; loud (stdout +
# a durable log, only ON a finding) otherwise. apply.sh's own RUNNING/OK/
# DEGRADED status file is for a reconciler that can be killed mid-flight --
# this is a synchronous, few-second probe with no such state to lose, so
# that heavier convention does not fit here.
#
# Exit codes: 0 healthy, 1 warning threshold crossed, 2 critical threshold
# crossed, 3 could not determine (connection or parse failure -- this is
# "I could not tell", never read as "there is nothing to worry about").
#
# Usage: no positional arguments -- everything is env-var-driven, all
# optional (this script has zero REQUIRED configuration of its own; it reuses
# CERT_DIR, which is only required in the sense that sync-certificate.sh
# already requires it):
#   /opt/registro/check-certificate-expiry.sh
#
# Environment variables (all optional -- sane defaults for the real UAT/
# PreProd layout; only worth overriding from a shell, or from this script's
# own test suite):
#   REGISTRO_CERT_CHECK_SNI     Hostname to present via SNI and to check.
#                                Default: read CERT_DIR from the legacy
#                                checkout's own .env (see REGISTRO_LEGACY_
#                                APP_DIR below) -- the SAME value sync-
#                                certificate.sh already requires, not a new
#                                one to configure. Refuses with exit 3 if
#                                neither this nor CERT_DIR is set.
#   REGISTRO_LEGACY_APP_DIR     Where to read CERT_DIR from, if
#                                REGISTRO_CERT_CHECK_SNI is unset.
#                                Default: /var/www/registro
#   REGISTRO_CERT_CHECK_ADDR    Socket to dial. Default: 127.0.0.1 (THIS
#                                machine's own nginx, not a DNS lookup of the
#                                public hostname -- see the header above).
#   REGISTRO_CERT_CHECK_PORT    Default: 443
#   REGISTRO_CERT_WARN_DAYS     Default: 30
#   REGISTRO_CERT_CRIT_DAYS     Default: 14
#   REGISTRO_CERT_EXPIRY_LOG    Durable log, written to ONLY on a finding.
#                                Default: /var/log/registro-certificate-
#                                expiry.log
#   REGISTRO_CERT_ALERT_URL     Optional webhook (plain GET), pinged only on
#                                a finding. No vendor implied. Default: unset
#                                -- log/exit-code only, same as tenant-
#                                check.sh today.
###############################################################################

set -uo pipefail

# Same override convention as sync-certificate.sh's own APP_DIR -- reused
# rather than a new variable name, so a sandbox faking one fakes both.
readonly APP_DIR="${REGISTRO_LEGACY_APP_DIR:-/var/www/registro}"
readonly LOG_FILE="${REGISTRO_CERT_EXPIRY_LOG:-/var/log/registro-certificate-expiry.log}"
readonly WARN_DAYS="${REGISTRO_CERT_WARN_DAYS:-30}"
readonly CRIT_DAYS="${REGISTRO_CERT_CRIT_DAYS:-14}"
# What socket to dial -- 127.0.0.1, not the public hostname, deliberately:
# this machine's own nginx is what must be proven to serve a fresh cert, not
# whatever a DNS lookup or a load balancer elsewhere happens to answer with.
readonly CONNECT_ADDR="${REGISTRO_CERT_CHECK_ADDR:-127.0.0.1}"
readonly CONNECT_PORT="${REGISTRO_CERT_CHECK_PORT:-443}"
# Optional, inert when unset -- no vendor named here on purpose (see this
# task's own "no provider lock-in" constraint). A plain GET, fired only when
# there IS a finding (WARNING or CRITICAL) -- the inverse of tenant-
# backup.sh's dead-man's-switch, which pings on SUCCESS so an external
# service can alert on silence. Here the log+exit-code convention above is
# the primary, required channel; this is a bounded, best-effort addition on
# top of it, never a replacement -- a failed ping never changes this
# script's own exit code.
readonly ALERT_URL="${REGISTRO_CERT_ALERT_URL:-}"

log() {
    local line="[$(date '+%Y-%m-%d %H:%M:%S')] $*"
    echo "$line" >>"$LOG_FILE" 2>/dev/null || true
    echo "$line"
}

alert() {
    [ -n "$ALERT_URL" ] || return 0
    # --max-time bounds the WHOLE operation (DNS + connect + transfer) --
    # this can run from cron, and an unbounded curl there is a stuck job
    # forever (the same trap tenant-backup.sh's own ping below is written to
    # avoid). Never allowed to affect this script's own exit code -- a
    # monitoring endpoint being unreachable is not evidence the certificate
    # itself is fine or not.
    curl -fs --max-time 10 "$ALERT_URL" >/dev/null 2>&1 \
        || log "WARNING: could not reach REGISTRO_CERT_ALERT_URL to report the finding above"
}

# Which name to present via SNI -- same value sync-certificate.sh already
# reads and requires (CERT_DIR), not a new key to configure. A machine that
# has never run sync-certificate.sh successfully has nothing for this script
# to check either, so requiring the same value is not new scope, it is the
# same precondition already documented.
SNI="${REGISTRO_CERT_CHECK_SNI:-}"
if [ -z "$SNI" ]; then
    SNI="$(grep -m1 '^CERT_DIR=' "${APP_DIR}/.env" 2>/dev/null | cut -d= -f2- || true)"
fi
if [ -z "$SNI" ]; then
    log "CRITICAL: could not determine which hostname to check -- set REGISTRO_CERT_CHECK_SNI or ensure ${APP_DIR}/.env has CERT_DIR="
    alert
    exit 3
fi

###############################################################################
# One connection, both pieces of information read from it -- avoids a second
# TLS handshake (and a second `s_client -naccept` on the serving side) just
# to also read the SAN list.
###############################################################################

RAW_CERT="$(echo | timeout 10 openssl s_client -connect "${CONNECT_ADDR}:${CONNECT_PORT}" \
    -servername "$SNI" 2>/dev/null)"

ENDDATE_LINE="$(printf '%s' "$RAW_CERT" | openssl x509 -noout -enddate 2>/dev/null || true)"
if [ -z "$ENDDATE_LINE" ]; then
    log "CRITICAL: could not read a certificate from ${CONNECT_ADDR}:${CONNECT_PORT} (SNI=${SNI}) -- connection failed, handshake did not complete, or no certificate was returned"
    alert
    exit 3
fi

ENDDATE="${ENDDATE_LINE#notAfter=}"
END_EPOCH="$(date -u -d "$ENDDATE" +%s 2>/dev/null || true)"
if [ -z "$END_EPOCH" ]; then
    log "CRITICAL: could not parse certificate notAfter date '${ENDDATE}' returned for ${SNI}"
    alert
    exit 3
fi

NOW_EPOCH="$(date -u +%s)"
DAYS_LEFT=$(( (END_EPOCH - NOW_EPOCH) / 86400 ))

# Informational only -- see this script's own header on why coverage is
# deliberately not reconciled or failed on here.
SAN_COUNT="$(printf '%s' "$RAW_CERT" | openssl x509 -noout -text 2>/dev/null \
    | grep -A1 'Subject Alternative Name' | tail -1 | tr ',' '\n' | grep -c 'DNS:' || true)"
[ -n "$SAN_COUNT" ] || SAN_COUNT=0

if [ "$DAYS_LEFT" -le "$CRIT_DAYS" ]; then
    log "CRITICAL: certificate for ${SNI} (served on ${CONNECT_ADDR}:${CONNECT_PORT}, covering ${SAN_COUNT} name(s)) expires in ${DAYS_LEFT} day(s) (notAfter=${ENDDATE}) -- at or below the ${CRIT_DAYS}-day critical threshold"
    alert
    exit 2
elif [ "$DAYS_LEFT" -le "$WARN_DAYS" ]; then
    log "WARNING: certificate for ${SNI} (served on ${CONNECT_ADDR}:${CONNECT_PORT}, covering ${SAN_COUNT} name(s)) expires in ${DAYS_LEFT} day(s) (notAfter=${ENDDATE}) -- at or below the ${WARN_DAYS}-day threshold certbot's own renewal normally fires at; renewal may be broken"
    alert
    exit 1
fi

# Clean run: silent, exactly like tenant-check.sh -- nothing written to
# LOG_FILE, nothing on stdout, exit 0.
exit 0
