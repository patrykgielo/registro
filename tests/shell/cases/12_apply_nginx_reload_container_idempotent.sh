#!/bin/bash
###############################################################################
# BONUS pin (cheap, per this task's brief): apply.sh's edge-sync step writes
# NGINX_RELOAD_CONTAINER into the LEGACY .env once a completed TLS cutover
# is detected -- but that step runs on EVERY apply, for every tenant. Without
# an idempotent replace-or-append, a shared .env would accumulate one
# duplicate NGINX_RELOAD_CONTAINER= line per apply run.
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "apply.sh: writing NGINX_RELOAD_CONTAINER to the legacy .env is idempotent across repeated runs"
sandbox_init

fake_exe docker <<'EOS'
[ "$1" = "inspect" ] && { echo "/opt/registro/docker/nginx/edge/edge-tls.local.conf"; exit 0; }
exit 0
EOS

EDGE_NGINX_CONTAINER="registro-edge-nginx"
LEGACY_APP_DIR="$SANDBOX/legacy"
mkdir -p "$LEGACY_APP_DIR"
: >"$LEGACY_APP_DIR/.env"
LOG_FILE="$SANDBOX/log"
log() { echo "$*" >>"$LOG_FILE"; }

SNIPPET="$(extract_between_contains "$SCRIPTS_DIR/apply.sh" \
    'EDGE_NGINX_CONF_SOURCE="$(docker inspect "$EDGE_NGINX_CONTAINER"' \
    'leaving NGINX_RELOAD_CONTAINER untouched')"
[ -n "$SNIPPET" ] || fail "could not extract the NGINX_RELOAD_CONTAINER write block from apply.sh -- did its shape change?"
# The extraction stops one line short of the block's own closing `fi` (that
# line has no unique text to anchor on) -- appended back explicitly here.
SNIPPET="${SNIPPET}"$'\n'"fi"

(
    cd "$LEGACY_APP_DIR"
    eval "$SNIPPET"
    eval "$SNIPPET"
    eval "$SNIPPET"
)

COUNT="$(grep -c '^NGINX_RELOAD_CONTAINER=' "$LEGACY_APP_DIR/.env")"
assert_eq "1" "$COUNT" "number of NGINX_RELOAD_CONTAINER= lines in .env after 3 runs"
assert_contains "$(cat "$LEGACY_APP_DIR/.env")" "NGINX_RELOAD_CONTAINER=registro-edge-nginx" ".env content"

test_finish
