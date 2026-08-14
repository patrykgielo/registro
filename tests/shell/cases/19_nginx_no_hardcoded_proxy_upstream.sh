#!/bin/bash
###############################################################################
# Pins: docker/nginx/default.conf's Vite locations, and
# docker/nginx/staging/app.staging.conf's PHP-FPM location, used to write
# proxy_pass/fastcgi_pass with the upstream container name literal in the
# directive. nginx resolves a literal proxy_pass/fastcgi_pass hostname (and a
# literal `server host:port;` inside an `upstream {}` block) ONCE, at config
# load -- if that container is absent at that instant (e.g. `node` not
# started, or `npm run build` used instead of `npm run dev`, the workflow
# CLAUDE.md mandates; or `app` not yet up when staging's nginx (re)loads),
# nginx refuses to start AT ALL, taking every location in the file down, not
# just the one that needed the missing upstream. Observed live: 2026-08-12,
# `registro-nginx` stuck in a restart loop with `node` stopped,
# `[emerg] host not found in upstream "registro-node"`.
#
# Fix: `resolver 127.0.0.11 ...;` (Docker's embedded DNS) + `set $upstream_x
# host:port;` + `proxy_pass $upstream_x;` / `fastcgi_pass $upstream_x;` --
# deferring resolution to request time, so a stopped upstream only 502s its
# own location. Pattern already established in
# docker/nginx/production/app.prod.conf and
# docker/nginx/edge/tenants.d/_example.conf.disabled.
#
# WHY A REAL `nginx -t`, NOT A GREP: an earlier version of this case matched
# `(proxy_pass|fastcgi_pass)\s+host:port;` textually. Code review reproduced
# two bypasses that regex never anticipated, both of which still crash nginx
# exactly like the original bug:
#   1. A trailing space before the semicolon (`proxy_pass https://host:port
#      ;`) -- an editor auto-format away from slipping the regex.
#   2. `upstream nodeup { server host:port; }` + `proxy_pass http://nodeup;`
#      -- a natural-looking refactor. This one is WORSE: `resolve=` on an
#      `upstream` `server` line is nginx-plus only, so the variable trick
#      this rule recommends cannot fix it -- a static check recommending an
#      unusable fix is worse than no check.
# A grep pins the SPELLING of today's bug, not the PROPERTY. The actual
# invariant -- "nginx starts even when no upstream container exists" -- is
# cheaply executable, so this case executes it for real instead of
# approximating it with text matching. `--network none` makes "the upstream
# is unresolvable" deterministic and offline (no embedded DNS at all, so
# every hostname fails exactly like a stopped container would), rather than
# depending on no container of that name existing on whatever bridge network
# happens to be active.
#
# NOT actually offline, and NOT sub-second like the rest of this suite
# (tests.md's own claim) -- this is the one case in tests/shell/ that shells
# out to the real Docker daemon and a real nginx:1.25-alpine, ~7 invocations
# at ~0.2s each when nginx fails fast (variable-form configs, or a config
# error caught before any resolution is attempted) but ~1.2s each for the
# three known-bad literal-upstream shapes: `--network none` still ships a
# `/etc/resolv.conf` copied from the HOST's real one, and libc's synchronous
# resolver used for a literal proxy_pass/fastcgi_pass/upstream-server host
# tries to actually reach it before giving up. A bare `--network none` with
# no override measured ~5.2s per bad shape (host's real nameserver, full
# retry/timeout cycle); overriding /etc/resolv.conf to a loopback address
# with nothing listening (`nameserver 127.0.0.1`, `options timeout:1
# attempts:1`) cuts that to ~1.2s by failing fast instead of timing out.
# Total added wall time for this one case: ~5s. Judged worth it because the
# alternative (a grep) provably misses bugs, one of them unfixable by the
# fix the grep would have "detected" as absent -- see the trailing-space and
# upstream-block bypasses above, both reproduced and both still misdetected
# by textual matching.
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "nginx confs: real nginx -t starts even when every upstream container is unresolvable"
sandbox_init

NGINX_IMAGE="nginx:1.25-alpine"
CERT_DIR="$SANDBOX/certs"
mkdir -p "$CERT_DIR"
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj "/CN=test" \
    -keyout "$CERT_DIR/key.pem" -out "$CERT_DIR/cert.pem" >/dev/null 2>&1
# default.conf wants cert.pem/key.pem under /etc/nginx/ssl/; app.staging.conf
# wants fullchain.pem/privkey.pem/chain.pem under a certbot-shaped
# /etc/letsencrypt/live/<domain>/ -- same key material, both naming schemes.
cp "$CERT_DIR/cert.pem" "$CERT_DIR/fullchain.pem"
cp "$CERT_DIR/cert.pem" "$CERT_DIR/chain.pem"
cp "$CERT_DIR/key.pem" "$CERT_DIR/privkey.pem"

# `--network none` still ships a /etc/resolv.conf copied from the host's
# real one -- pointing a literal-hostname lookup at a loopback address with
# nothing listening fails in ~1.2s (immediate ICMP-unreachable-driven retry,
# then give up) instead of ~5.2s (a full timeout cycle against a real,
# unreachable nameserver). Only affects the synchronous libc resolution path
# a literal proxy_pass/fastcgi_pass/upstream-server hits; the fixed
# variable-form configs never take this path at all (see header).
printf 'nameserver 127.0.0.1\noptions timeout:1 attempts:1\n' >"$SANDBOX/resolv.conf"

# nginx_t CONF_FILE -- mounts CONF_FILE as the sole conf.d file plus both
# cert layouts our real confs need (default.conf's /etc/nginx/ssl/*.pem,
# app.staging.conf's /etc/letsencrypt/live/srv1203357.hstgr.cloud/*.pem),
# runs `nginx -t` with no network at all, returns nginx's own exit code.
# `--network none`, not a throwaway bridge: a bridge network still carries
# Docker's embedded DNS, which could coincidentally resolve a literal
# hostname if some OTHER container in this environment happened to share it.
# No network removes that possibility entirely.
nginx_t() {
    local conf_file="$1"
    docker run --rm --network none \
        -v "$conf_file:/etc/nginx/conf.d/default.conf:ro" \
        -v "$CERT_DIR:/etc/nginx/ssl:ro" \
        -v "$CERT_DIR:/etc/letsencrypt/live/srv1203357.hstgr.cloud:ro" \
        -v "$SANDBOX/resolv.conf:/etc/resolv.conf:ro" \
        "$NGINX_IMAGE" nginx -t >"$SANDBOX/nginx_t.out" 2>&1
}

# --- (1) the real repo files, today: must start clean --------------------
for real_conf in \
    "$REPO_ROOT/docker/nginx/default.conf" \
    "$REPO_ROOT/docker/nginx/staging/app.staging.conf"
do
    # Guard against docker's own footgun (bind-mounting a path that does not
    # exist silently creates an empty directory there instead of erroring)
    # by asserting the source file is real before ever handing it to `-v`.
    [ -f "$real_conf" ] || { fail "expected conf file missing: $real_conf"; continue; }

    if ! nginx_t "$real_conf"; then
        fail "real conf failed nginx -t with no network (should start with upstream unresolvable): $real_conf
$(cat "$SANDBOX/nginx_t.out")"
    fi
done

# --- (2) the check itself actually detects all three known bug shapes ----
cat >"$SANDBOX/bad_literal.conf" <<'EOS'
server {
    listen 443 ssl;
    server_name registro.local;
    ssl_certificate /etc/nginx/ssl/cert.pem;
    ssl_certificate_key /etc/nginx/ssl/key.pem;
    location /@vite/ {
        proxy_pass https://registro-node:5173;
    }
}
EOS

cat >"$SANDBOX/bad_trailing_space.conf" <<'EOS'
server {
    listen 443 ssl;
    server_name registro.local;
    ssl_certificate /etc/nginx/ssl/cert.pem;
    ssl_certificate_key /etc/nginx/ssl/key.pem;
    location /@vite/ {
        proxy_pass https://registro-node:5173 ;
    }
}
EOS

cat >"$SANDBOX/bad_upstream_block.conf" <<'EOS'
upstream nodeup {
    server registro-node:5173;
}
server {
    listen 443 ssl;
    server_name registro.local;
    ssl_certificate /etc/nginx/ssl/cert.pem;
    ssl_certificate_key /etc/nginx/ssl/key.pem;
    location /@vite/ {
        proxy_pass http://nodeup;
    }
}
EOS

cat >"$SANDBOX/good_variable.conf" <<'EOS'
server {
    listen 443 ssl;
    server_name registro.local;
    ssl_certificate /etc/nginx/ssl/cert.pem;
    ssl_certificate_key /etc/nginx/ssl/key.pem;
    resolver 127.0.0.11 valid=5s ipv6=off;
    set $upstream_node https://registro-node:5173;
    location /@vite/ {
        proxy_pass $upstream_node;
    }
}
EOS

for bad in bad_literal bad_trailing_space bad_upstream_block; do
    if nginx_t "$SANDBOX/$bad.conf"; then
        fail "did not catch known-bad shape '$bad' -- nginx -t passed with an unresolvable upstream:
$(cat "$SANDBOX/nginx_t.out")"
    fi
done

if ! nginx_t "$SANDBOX/good_variable.conf"; then
    fail "false positive on the fixed (variable-form) shape:
$(cat "$SANDBOX/nginx_t.out")"
fi

test_finish
