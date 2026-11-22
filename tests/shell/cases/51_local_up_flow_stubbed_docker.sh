#!/bin/bash
###############################################################################
# Runs the REAL scripts/local/up.sh end to end against fake docker / curl /
# systemctl / sudo on PATH and asserts on what it CALLED and what it EXITED
# with. Nothing here reaches a real daemon.
#
# What each scenario pins:
#   status   changes nothing: no `compose up`, no rm of public/hot, no systemctl
#   up       names the services explicitly and node is NOT among them (a bare
#            `up -d` would start the Vite container)
#   hot      removed only with node stopped; kept with node running; kept when
#            docker cannot be asked
#   docker   down + sudo refused -> exit 10 naming the exact command; down +
#            sudo ok -> proceeds; --status never tries to start it
#   certs    missing -> exit 11 BEFORE any `compose up`
#   502      forever -> exit 14 within the timeout; 404 -> exit 14 fast
#   service  restarting -> exit 13 even though HTTP is 200
#   compose  `config` or `up` failing -> exit 12
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "local up.sh: real script against stubbed docker (status read-only, node never started, exit codes)"
sandbox_init

UP="${REPO_ROOT}/scripts/local/up.sh"
if [ ! -f "$UP" ]; then
    fail "scripts/local/up.sh missing"
    test_finish
fi

# --- fakes -------------------------------------------------------------------
fake_exe docker <<'EOS'
case "$1" in
    info)
        [ -e "$SANDBOX/docker-started" ] && exit 0
        exit "${FAKE_DOCKER_INFO_RC:-0}"
        ;;
    compose)
        shift
        case "$1" in
            config)
                [ "${FAKE_CONFIG_RC:-0}" -eq 0 ] || { echo "config exploded" >&2; exit "$FAKE_CONFIG_RC"; }
                # A compose WARN goes to STDERR; the service list is stdout only.
                [ -z "${FAKE_CONFIG_WARN:-}" ] || echo 'time="x" level=warning msg="The \"FOO\" variable is not set. Defaulting to a blank string."' >&2
                printf '%s\n' redis mysql app scheduler horizon mailpit nginx node
                ;;
            ps)
                [ "${FAKE_PS_RC:-0}" -eq 0 ] || exit "$FAKE_PS_RC"
                if [ -n "${FAKE_PS_FLAP:-}" ]; then
                    # odd call: all running; even call: redis restarting. The
                    # script's own state read (public/hot) is call #1.
                    n=$(( $(cat "$SANDBOX/ps-count" 2>/dev/null || echo 0) + 1 ))
                    echo "$n" >"$SANDBOX/ps-count"
                    if [ $((n % 2)) -eq 0 ]; then
                        sed 's/^redis|running|healthy/redis|restarting|/' "$FAKE_PS_FILE"
                        exit 0
                    fi
                fi
                cat "$FAKE_PS_FILE"
                ;;
            up)
                [ "${FAKE_UP_RC:-0}" -eq 0 ] || { echo "up exploded" >&2; exit "$FAKE_UP_RC"; }
                echo " Container registro-app  Running"
                ;;
        esac
        ;;
esac
exit 0
EOS

fake_exe curl <<'EOS'
url=""
for a in "$@"; do case "$a" in https://*) url="$a" ;; esac; done
case "$url" in
    https://registro.local:*) printf '%s' "${FAKE_CURL_CENTRAL:-200}" ;;
    *) printf '%s' "${FAKE_CURL_TENANT:-200}" ;;
esac
EOS

fake_exe systemctl <<'EOS'
if [ "$1" = "is-active" ]; then
    echo "${FAKE_DOCKER_ACTIVE:-inactive}"
    [ "${FAKE_DOCKER_ACTIVE:-inactive}" = "active" ] && exit 0
    exit 3
fi
exit 0
EOS

# Non-root, so the script must go through sudo -n.
fake_exe id <<'EOS'
echo 1000
EOS

fake_exe sudo <<'EOS'
[ "${FAKE_SUDO_RC:-1}" -eq 0 ] || exit "$FAKE_SUDO_RC"
: >"$SANDBOX/docker-started"
exit 0
EOS

# --- sandbox project tree ----------------------------------------------------
PROJ="$SANDBOX/proj"
mkdir -p "$PROJ/docker/ssl" "$PROJ/public"
: >"$PROJ/docker-compose.yml"
make_throwaway_cert 30 registro.local "$PROJ/docker/ssl/cert.pem" "$PROJ/docker/ssl/key.pem"

export REGISTRO_LOCAL_ROOT="$PROJ"
export REGISTRO_LOCAL_POLL_INTERVAL=1
export FAKE_PS_FILE="$SANDBOX/ps.txt"

all_running() {
    cat >"$FAKE_PS_FILE" <<'EOS'
app|running|
horizon|running|
mailpit|running|healthy
mysql|running|
nginx|running|
node|exited|
redis|running|healthy
scheduler|running|
EOS
}

reset_env() {
    unset FAKE_DOCKER_INFO_RC FAKE_CONFIG_RC FAKE_CONFIG_WARN FAKE_PS_RC FAKE_PS_FLAP FAKE_UP_RC FAKE_CURL_TENANT FAKE_CURL_CENTRAL FAKE_DOCKER_ACTIVE FAKE_SUDO_RC
    rm -f "$SANDBOX/docker-started" "$SANDBOX/ps-count" "$PROJ/public/hot"
    : >"$CALL_LOG"
    all_running
}

# run_up ARGS... -> OUT, RC
run_up() {
    RC=0
    OUT="$(bash "$UP" "$@" 2>&1)" || RC=$?
}

calls() { cat "$CALL_LOG"; }

# --- 1. status on a healthy stack: exit 0 and ZERO changes -------------------
reset_env
touch "$PROJ/public/hot" # node is exited -> up would remove it; status must not
run_up --status --timeout 2
assert_eq "16" "$RC" "status with a stale public/hot reports it (exit 16)"
assert_contains "$OUT" "stale public/hot present" "status names the stale hot file"
[ -e "$PROJ/public/hot" ] || fail "status REMOVED public/hot -- it must change nothing"
assert_not_contains "$(calls)" "compose up" "status never runs compose up"
assert_not_contains "$(calls)" "systemctl start" "status never starts docker"

reset_env
run_up --status --timeout 2
assert_eq "0" "$RC" "status on a healthy stack -> 0"
assert_contains "$OUT" "7/7 running" "status counts the seven non-node services"
assert_contains "$OUT" "HTTP 200" "status shows the tenant HTTP code"
assert_not_contains "$(calls)" "compose up" "healthy status: no compose up"
assert_not_contains "$(calls)" "sudo" "healthy status: no sudo"

# --- 2. up: explicit services, node never among them -------------------------
reset_env
run_up --timeout 2
assert_eq "0" "$RC" "up on a healthy stack -> 0"
UP_CALL="$(grep 'compose up' "$CALL_LOG" || true)"
assert_contains "$UP_CALL" "up -d --no-build --pull never" "up uses -d, never builds and never pulls (offline = error, not a hang)"
assert_contains "$UP_CALL" "app horizon mailpit mysql nginx redis scheduler" "all seven services are named"
assert_not_contains "$UP_CALL" "node" "node is NEVER passed to compose up"
assert_not_contains "$(calls)" " down" "never compose down"
assert_not_contains "$(calls)" "migrate" "never migrates"
assert_not_contains "$(calls)" "exec" "never execs into a container"

# --- 3. public/hot decisions in up mode --------------------------------------
reset_env
touch "$PROJ/public/hot"
run_up --timeout 2
assert_eq "0" "$RC" "up with stale hot and node stopped -> 0"
[ ! -e "$PROJ/public/hot" ] || fail "stale public/hot (node exited) was not removed"
assert_contains "$OUT" "removed" "up says it removed the stale hot file"

reset_env
sed -i 's/^node|exited|/node|running|/' "$FAKE_PS_FILE"
touch "$PROJ/public/hot"
run_up --timeout 2
assert_eq "0" "$RC" "up with node running -> 0"
[ -e "$PROJ/public/hot" ] || fail "public/hot removed while node is RUNNING -- Vite dev mode broken"
assert_contains "$OUT" "kept" "up says the hot file was kept"

reset_env
touch "$PROJ/public/hot"
export FAKE_PS_RC=1 # docker cannot be asked what node is doing
run_up --timeout 2
[ -e "$PROJ/public/hot" ] || fail "public/hot removed although docker could not say whether node runs"
assert_eq "13" "$RC" "a failing 'compose ps' is exit 13, not a success"

# --- 4. docker down ----------------------------------------------------------
reset_env
export FAKE_DOCKER_INFO_RC=1 FAKE_DOCKER_ACTIVE=inactive FAKE_SUDO_RC=1
run_up --timeout 2
assert_eq "10" "$RC" "docker down + sudo refused -> 10"
assert_contains "$OUT" "sudo systemctl start docker" "the message names the exact command"
assert_not_contains "$(calls)" "compose up" "no compose up when docker is down"

reset_env
export FAKE_DOCKER_INFO_RC=1 FAKE_DOCKER_ACTIVE=inactive FAKE_SUDO_RC=0
run_up --timeout 2
assert_eq "0" "$RC" "docker down + sudo works -> continues and succeeds"
assert_contains "$(calls)" "sudo -n systemctl start docker" "starts docker non-interactively through systemctl"

reset_env
export FAKE_DOCKER_INFO_RC=1 FAKE_DOCKER_ACTIVE=inactive FAKE_SUDO_RC=0
run_up --status
assert_eq "10" "$RC" "status with docker down -> 10"
assert_not_contains "$(calls)" "sudo" "status never tries to start docker"

reset_env
export FAKE_DOCKER_INFO_RC=1 FAKE_DOCKER_ACTIVE=active
run_up --timeout 2
assert_eq "10" "$RC" "daemon active but docker info fails -> 10 (not 'start it again')"
assert_not_contains "$(calls)" "sudo" "an active daemon is never restarted"

# --- 5. certs ----------------------------------------------------------------
reset_env
mv "$PROJ/docker/ssl/cert.pem" "$PROJ/docker/ssl/cert.pem.bak"
run_up --timeout 2
assert_eq "11" "$RC" "missing cert.pem -> 11"
assert_contains "$OUT" "cert.pem" "names the missing file"
assert_not_contains "$(calls)" "compose up" "certs are checked BEFORE compose up"
: >"$PROJ/docker/ssl/cert.pem"
run_up --timeout 2
assert_eq "11" "$RC" "empty cert.pem -> 11"
printf 'not a certificate\n' >"$PROJ/docker/ssl/cert.pem"
run_up --timeout 2
assert_eq "11" "$RC" "garbage cert.pem -> 11"
mv "$PROJ/docker/ssl/cert.pem.bak" "$PROJ/docker/ssl/cert.pem"

# --- 6. HTTP outcomes --------------------------------------------------------
reset_env
export FAKE_CURL_TENANT=502
START=$SECONDS
run_up --timeout 2
ELAPSED=$((SECONDS - START))
assert_eq "14" "$RC" "502 forever -> timeout exit 14"
assert_contains "$OUT" "HTTP 502" "reports the last code seen"
[ "$ELAPSED" -le 8 ] || fail "timeout 2 took ${ELAPSED}s -- the wait is not bounded by --timeout"

reset_env
export FAKE_CURL_TENANT=404
START=$SECONDS
run_up --timeout 60
ELAPSED=$((SECONDS - START))
assert_eq "14" "$RC" "404 -> exit 14"
assert_contains "$OUT" "not 200" "404 explains it is not a transient"
[ "$ELAPSED" -le 10 ] || fail "a steady 404 waited ${ELAPSED}s of a 60 s budget instead of failing fast"

# --- 7. service states -------------------------------------------------------
reset_env
sed -i 's/^redis|running|healthy/redis|restarting|/' "$FAKE_PS_FILE"
run_up --timeout 2
assert_eq "13" "$RC" "a restarting container is exit 13 even with HTTP 200"
assert_contains "$OUT" "redis=restarting" "names the restarting service"

reset_env
sed -i 's/^horizon|running|/horizon|exited|/' "$FAKE_PS_FILE"
START=$SECONDS
run_up --timeout 60
ELAPSED=$((SECONDS - START))
assert_eq "13" "$RC" "an exited service -> 13"
assert_contains "$OUT" "horizon=down" "names the exited service"
[ "$ELAPSED" -le 10 ] || fail "an exited container waited ${ELAPSED}s of a 60 s budget; waiting cannot revive it"

reset_env
sed -i 's/^mailpit|running|healthy/mailpit|running|unhealthy/' "$FAKE_PS_FILE"
run_up --timeout 2
assert_eq "13" "$RC" "unhealthy -> 13"

# A container that is "running" in one sample and restarting in the next must
# never be reported OK on one lucky sample.
reset_env
export FAKE_PS_FLAP=1
run_up --timeout 3
assert_eq "13" "$RC" "running/restarting flapping is never OK (needs two consecutive good samples)"
assert_contains "$OUT" "redis=restarting" "names the flapping service"
reset_env
export FAKE_PS_FLAP=1
echo 1 >"$SANDBOX/ps-count" # shift parity: first loop sample is GOOD, second is bad
run_up --status
assert_eq "13" "$RC" "--status applies the same two-sample rule (one good sample is not OK)"

# --- 8. compose failures -----------------------------------------------------
reset_env
export FAKE_CONFIG_RC=1
run_up --timeout 2
assert_eq "12" "$RC" "compose config failing -> 12"
assert_contains "$OUT" "config exploded" "on failure compose's stderr IS shown"
assert_not_contains "$(calls)" "compose up" "no up when the service list is unknown"

# A compose WARN on stderr must never become a service name.
reset_env
export FAKE_CONFIG_WARN=1
run_up --timeout 2
assert_eq "0" "$RC" "compose WARN on stderr of 'config --services' -> still 0"
UP_CALL="$(grep 'compose up' "$CALL_LOG" || true)"
assert_contains "$UP_CALL" "app horizon mailpit mysql nginx redis scheduler" "services intact next to a WARN"
assert_not_contains "$UP_CALL" "level=warning" "the WARN text is not passed to compose up as a service"
assert_not_contains "$UP_CALL" "FOO" "no fragment of the WARN reaches compose up"
assert_contains "$OUT" "7/7 running" "seven services, not seven plus WARN words"
assert_not_contains "$OUT" "level=warning" "a successful run does not echo compose's stderr"

reset_env
export FAKE_UP_RC=1
run_up --timeout 2
assert_eq "12" "$RC" "compose up failing -> 12"
assert_contains "$OUT" "up exploded" "compose's own error is shown"

# --- 9. precondition / usage -------------------------------------------------
reset_env
REGISTRO_LOCAL_ROOT="$SANDBOX/not-a-project" run_up
assert_eq "15" "$RC" "not a Registro tree -> 15"
reset_env
run_up --bogus
assert_eq "2" "$RC" "unknown flag -> 2"
assert_not_contains "$(calls)" "compose" "bad args run no docker"

test_finish
