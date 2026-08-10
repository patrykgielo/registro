#!/bin/bash
###############################################################################
# Pins: ci-cd-troubleshooting.md, "apply.sh's krok edge-sync cofał zakończony
# cutover TLS przy KAŻDYM kolejnym apply" -- docker-compose.edge.yml mounts
# nginx's config through ${EDGE_NGINX_CONF:-edge.conf}. The manual cutover
# sets that var for ONE command and never persists it -- so `up -d
# edge-nginx` on every later apply, in a process where the var is unset,
# would silently recreate the edge back onto the bootstrap config, dropping
# TLS for every tenant behind it. The fix reads the RUNNING container's own
# bind mount and re-exports EDGE_NGINX_CONF to that same value before `up -d`
# recreates it.
#
# Extracts the real block (not a copy) out of apply.sh's edge-sync subshell.
# The assertion is on what environment variable the fake `docker compose up
# -d edge-nginx` call actually RECEIVED -- an exported bash var is real
# process environment for a faked subprocess too, so this proves the
# preservation genuinely happens, not just that some code path exists.
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "apply.sh: edge-sync preserves the running edge's TLS config across recreation"
sandbox_init

fake_exe docker <<EOS
case "\$1" in
    inspect)
        # The currently-running edge-nginx container's own bind mount --
        # simulates a completed TLS cutover.
        echo "/opt/registro/docker/nginx/edge/edge-tls.local.conf"
        exit 0
        ;;
    compose)
        printf 'EDGE_NGINX_CONF=%s\n' "\${EDGE_NGINX_CONF:-<unset>}" >"$SANDBOX/edge_nginx_conf_seen"
        exit 0
        ;;
esac
exit 0
EOS

EDGE_NGINX_CONTAINER="registro-edge-nginx"
EDGE_COMPOSE_FILE="docker-compose.edge.yml"
EDGE_TENANTS_OVERRIDE="docker-compose.edge.tenants.override.yml"
SLUG="acme"
LOG_FILE="$SANDBOX/log"
log() { echo "$*" >>"$LOG_FILE"; }
die() { echo "DIE: $*" >>"$SANDBOX/die_log"; exit 9; }

SNIPPET="$(extract_between_contains "$SCRIPTS_DIR/apply.sh" \
    'EDGE_NGINX_CURRENT_SOURCE="$(docker inspect "$EDGE_NGINX_CONTAINER"' \
    'die "failed to reload the edge with ${SLUG} attached" 3')"
[ -n "$SNIPPET" ] || fail "could not extract the edge-sync preservation block from apply.sh -- did its shape change?"

eval "$SNIPPET"

[ -f "$SANDBOX/die_log" ] && fail "the block called die() unexpectedly: $(cat "$SANDBOX/die_log")"
[ -f "$SANDBOX/edge_nginx_conf_seen" ] || fail "docker compose ... up -d edge-nginx was never invoked"
assert_eq "EDGE_NGINX_CONF=edge-tls.local.conf" "$(cat "$SANDBOX/edge_nginx_conf_seen" 2>/dev/null)" \
    "EDGE_NGINX_CONF seen by 'docker compose up -d edge-nginx'"

test_finish
