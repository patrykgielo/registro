#!/bin/bash
###############################################################################
# Pins: ci-cd-troubleshooting.md, "Incydent 2026-08-15: v0.13.0-rc12's redis
# hardening crashlooped on a volume with real prior data". docker-compose.prod
# .yml's redis service had `cap_drop: ALL` / `cap_add: [SETUID, SETGID]` --
# looks correct because it IS correct against a brand-new, never-used volume
# (redis:7.2-alpine's own image already owns /data as redis:redis, so the
# entrypoint's `find . \! -user redis -exec chown redis {} +` has nothing to
# do and no restrictive directory to enter). This project's own entrypoint
# umask (0077) means a volume with real history has `appendonlydir` at mode
# 0700 -- root without CAP_DAC_OVERRIDE cannot opendir() it, and the
# entrypoint dies with `find: ./appendonlydir: Permission denied` before ever
# calling redis-server. That is exactly what took down UAT: the "app",
# "horizon", "nginx" and "scheduler" containers never started because Compose
# treats a failed healthcheck as a failed dependency, same shape as the dual-
# queue and apply.sh incidents in this same file -- one bad line, whole stack.
#
# WHY A REAL CONTAINER, NOT A GREP ON THE YAML: a text match on `DAC_OVERRIDE`
# would pass the instant someone typed the capability name, without ever
# proving root actually needs it to read a 0700 directory it does not own --
# the exact false-confidence pattern tests.md warns about, and the same
# reasoning as case 19 (nginx) and case 30 (MySQL 8 JSON operator).
#
# WHY A REAL, ORGANICALLY-CREATED appendonlydir, NOT HAND-WRITTEN FILES: an
# `mkdir`+`echo`-built directory inherits THIS shell's umask (typically 0022,
# giving 0755 -- world-readable), which never reproduces the bug at all (root
# can already opendir() a 0755 directory without any extra capability). Only
# a directory actually created by redis-server, under this project's own
# entrypoint umask, has the 0700 permission bits the incident hinges on.
#
# COST: two real redis:7.2-alpine containers plus one seeding container
# (~5-8s). Same accepted precedent as cases 19 and 30.
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "redis hardening (docker-compose.prod.yml) survives a volume with real prior AOF data"
sandbox_init

COMPOSE_FILE="$REPO_ROOT/docker-compose.prod.yml"

# --- extract the real redis cap_drop/cap_add out of the compose file, never
# hardcoded -- anchored on the EXACT indentation of the top-level service key
# (docker-compose.prod.yml also has a `redis:` line under `app`'s
# `depends_on:`, at deeper indentation -- case 30 already documents this trap
# for the same file). Stops at the next top-level service (2-space indent) so
# it never bleeds into `nginx:`.
REDIS_BLOCK="$(awk '
    /^  redis:[ \t]*$/ { r = 1; next }
    r && /^  [a-zA-Z]/ { exit }
    r { print }
' "$COMPOSE_FILE")"
[ -n "$REDIS_BLOCK" ] || fail "could not extract the redis service block from $COMPOSE_FILE"

CAP_ADD="$(printf '%s\n' "$REDIS_BLOCK" | awk '
    /^    cap_add:[ \t]*$/ { c = 1; next }
    c && /^    [a-zA-Z]/ { exit }
    c && /^      - / { sub(/^      - /, ""); print }
')"
[ -n "$CAP_ADD" ] || fail "could not extract redis's cap_add list from $COMPOSE_FILE"

DOCKER_CAP_ARGS=(--cap-drop ALL)
while IFS= read -r cap; do
    DOCKER_CAP_ARGS+=(--cap-add "$cap")
done <<<"$CAP_ADD"

# --- build a volume with REAL prior history: unhardened boot, real keys, a
# real BGREWRITEAOF so appendonlydir/ exists with this project's own
# entrypoint's umask 0077 permissions (0700), not synthetic files. -----------

VOL="cc31-redis-data-$$"
docker volume rm "$VOL" >/dev/null 2>&1 || true
docker volume create "$VOL" >/dev/null

cleanup() {
    docker rm -f cc31-seed cc31-old-caps cc31-new-caps >/dev/null 2>&1 || true
    docker volume rm "$VOL" >/dev/null 2>&1 || true
}
trap 'cleanup; rm -rf "$SANDBOX"' EXIT

docker run -d --name cc31-seed -v "$VOL:/data" redis:7.2-alpine \
    redis-server --appendonly yes --requirepass seedpass >/dev/null 2>&1
for i in $(seq 1 20); do
    docker exec cc31-seed redis-cli -a seedpass --no-auth-warning ping >/dev/null 2>&1 && break
    sleep 1
done
docker exec cc31-seed redis-cli -a seedpass --no-auth-warning set foo bar >/dev/null
docker exec cc31-seed redis-cli -a seedpass --no-auth-warning bgrewriteaof >/dev/null
for i in $(seq 1 20); do
    ip="$(docker exec cc31-seed redis-cli -a seedpass --no-auth-warning info persistence 2>/dev/null | grep -c 'aof_rewrite_in_progress:0')"
    [ "$ip" = "1" ] && break
    sleep 1
done
docker rm -f cc31-seed >/dev/null 2>&1

# --- (1) negative control: the OLD spec (SETUID+SETGID only) must fail, the
# same way UAT actually failed -- proves this case would have caught the
# original bug, not just that it accepts today's fix. -----------------------

OLD_OUT="$(timeout 8 docker run --rm --name cc31-old-caps \
    -v "$VOL:/data" \
    --security-opt no-new-privileges:true \
    --cap-drop ALL --cap-add SETUID --cap-add SETGID \
    redis:7.2-alpine \
    redis-server --appendonly yes --requirepass testpass 2>&1)"
assert_contains "$OLD_OUT" "Permission denied" "old cap set (SETUID+SETGID only) against a volume with real prior data"

# --- (2) the file's ACTUAL current cap_add must succeed, load the real seeded
# key, and answer a real authenticated PING. ---------------------------------

docker run -d --name cc31-new-caps \
    -v "$VOL:/data" \
    --security-opt no-new-privileges:true \
    "${DOCKER_CAP_ARGS[@]}" \
    redis:7.2-alpine \
    redis-server --appendonly yes --requirepass testpass >/dev/null 2>&1

PONG=""
FOO=""
for i in $(seq 1 15); do
    PONG="$(docker exec cc31-new-caps redis-cli -a testpass --no-auth-warning ping 2>/dev/null)"
    [ "$PONG" = "PONG" ] && break
    sleep 1
done
[ "$PONG" = "PONG" ] || fail "redis under the compose file's current cap_add ($CAP_ADD) never answered PING against a volume with real prior data:
$(docker logs cc31-new-caps 2>&1 | tail -20)"

FOO="$(docker exec cc31-new-caps redis-cli -a testpass --no-auth-warning get foo 2>/dev/null)"
assert_eq "bar" "$FOO" "value persisted before hardening was reloaded correctly after it"

test_finish
