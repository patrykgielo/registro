#!/usr/bin/env bash
###############################################################################
# scripts/local/up.sh -- bring the LOCAL Registro dev stack up and PROVE it is
# serving. WSL side. The Windows entry point (start-wsl.ps1) calls this.
#
#   bash scripts/local/up.sh                  # bring up + wait until it answers
#   bash scripts/local/up.sh --status         # report only, change NOTHING
#   bash scripts/local/up.sh --tenant firma   # which tenant's login to probe
#   bash scripts/local/up.sh --timeout 180    # seconds to wait (default 120)
#
# WHAT IT DOES (and nothing else):
#   1. Docker daemon active?  Starts it with systemctl only if it is not.
#   2. TLS certs present in docker/ssl/ (and not expired)?
#   3. `docker compose up -d <every service except node>`.  `node` is the Vite
#      dev server: only for `npm run dev` / builds, never started by default --
#      and the services are named explicitly because a bare `up -d` WOULD
#      start it.
#   4. Removes public/hot, but ONLY when it is certain node is not running (a
#      stale hot file makes the site load assets from a dead Vite server).
#   5. Polls the tenant login URL until it returns HTTP 200.  Right after boot
#      nginx answers 502 for a few seconds because php-fpm in `app` is not up
#      yet (its entrypoint waits for MySQL first); that is the expected
#      transient this loop absorbs.
#   6. Final check: every service running, none restarting / unhealthy, and the
#      login 200 -- on TWO consecutive samples (one can catch a restart loop
#      in its "running" moment). --status takes the same two samples.
#
# WHAT IT NEVER DOES: migrations, seeders, anything that writes to the DB,
# `docker compose down`, `down -v`, `--force-recreate`, `--build`, or a registry
# pull (`--no-build --pull never`: a missing image is an error, not a hang).
#
# EXIT CODES (also printed with the message):
#    0  stack up and serving
#    2  bad arguments
#   10  Docker daemon not usable (down and could not be started, or no access)
#   11  TLS certificates missing, empty or expired (docker/ssl/)
#   12  `docker compose` failed (config, up, or the service list is empty)
#   13  a service is down / restarting / unhealthy
#   14  timed out (or the app answers something other than 200)
#   15  precondition: not the project tree, or curl missing
#   16  public/hot: stale and could not be removed (--status: stale present)
#
# OUTPUT IS ASCII ON PURPOSE: it passes through wsl.exe into a Windows console.
#
# SOURCEABLE: tests/shell sources this file and calls the pure functions
# (filter_services, missing_services, classify_service, classify_http,
# hot_decision, parse_args) with no docker. The guard at the bottom keeps
# main from running on `source`.
###############################################################################
set -uo pipefail

ROOT="${REGISTRO_LOCAL_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)}"
EXCLUDED_SERVICES="${REGISTRO_LOCAL_EXCLUDE:-node}"
HTTPS_PORT="${REGISTRO_LOCAL_PORT:-8444}"
CENTRAL_HOST="registro.local"

E_USAGE=2 E_DOCKER=10 E_CERTS=11 E_COMPOSE=12 E_SERVICES=13 E_TIMEOUT=14 E_PRECOND=15 E_HOT=16

MODE="up"
TENANT="${REGISTRO_LOCAL_TENANT:-firma}"
TIMEOUT="${REGISTRO_LOCAL_TIMEOUT:-120}"
POLL_INTERVAL="${REGISTRO_LOCAL_POLL_INTERVAL:-2}"

die() {
    local code="$1"
    shift
    printf 'ERROR (exit %s): %s\n' "$code" "$*" >&2
    exit "$code"
}

say() { printf '%s\n' "$*"; }

usage() {
    cat <<'EOS'
Usage: bash scripts/local/up.sh [--status] [--tenant SLUG] [--timeout SECONDS] [--help]

  (no flag)    bring the local stack up and wait until the tenant login is HTTP 200
  --status     report only: starts nothing, removes nothing, waits for nothing
  --tenant S   tenant whose /admin/login is probed (default: firma, or $REGISTRO_LOCAL_TENANT)
  --timeout N  seconds to wait for the app (default: 120, or $REGISTRO_LOCAL_TIMEOUT)
EOS
}

# --- pure logic (no docker, no network) ---------------------------------------

# parse_args "$@" -- sets MODE / TENANT / TIMEOUT. Returns 0, or E_USAGE after
# printing why. Returns 64 for --help (caller prints usage and exits 0).
parse_args() {
    while [ $# -gt 0 ]; do
        case "$1" in
            --status) MODE="status" ;;
            --tenant)
                [ $# -ge 2 ] || { printf 'ERROR: --tenant needs a value\n' >&2; return "$E_USAGE"; }
                TENANT="$2"
                shift
                ;;
            --timeout)
                [ $# -ge 2 ] || { printf 'ERROR: --timeout needs a value\n' >&2; return "$E_USAGE"; }
                TIMEOUT="$2"
                shift
                ;;
            -h | --help) return 64 ;;
            *)
                printf 'ERROR: unknown argument: %s\n' "$1" >&2
                return "$E_USAGE"
                ;;
        esac
        shift
    done
    # The slug becomes part of a hostname that is handed to curl --resolve:
    # accept a hostname label and nothing else.
    if ! printf '%s' "$TENANT" | grep -Eq '^[a-z0-9]([a-z0-9-]*[a-z0-9])?$'; then
        printf 'ERROR: --tenant must be a lowercase hostname label (a-z, 0-9, -), got: [%s]\n' "$TENANT" >&2
        return "$E_USAGE"
    fi
    if ! printf '%s' "$TIMEOUT" | grep -Eq '^[1-9][0-9]*$'; then
        printf 'ERROR: --timeout must be a positive integer, got: [%s]\n' "$TIMEOUT" >&2
        return "$E_USAGE"
    fi
    return 0
}

# filter_services -- stdin: one service name per line. Prints them sorted,
# unique, WITHOUT the excluded ones (node).
filter_services() {
    local excl=" ${EXCLUDED_SERVICES} " s
    while IFS= read -r s || [ -n "$s" ]; do
        s="${s%$'\r'}"
        [ -n "$s" ] || continue
        case "$excl" in
            *" ${s} "*) continue ;;
        esac
        printf '%s\n' "$s"
    done | sort -u
}

# missing_services WANTED RUNNING -- both newline lists. Prints the names that
# are wanted but not in RUNNING.
missing_services() {
    comm -23 \
        <(printf '%s\n' "$1" | sed '/^$/d' | sort -u) \
        <(printf '%s\n' "$2" | sed '/^$/d' | sort -u)
}

# classify_service STATE HEALTH -- one word:
#   ok | starting | unhealthy | restarting | down
# STATE is compose's State ("running", "exited", ...), "" when the container
# does not exist. HEALTH is "", "healthy", "starting" or "unhealthy".
classify_service() {
    local state="$1" health="$2"
    case "$state" in
        running)
            case "$health" in
                "" | healthy) echo ok ;;
                starting) echo starting ;;
                unhealthy) echo unhealthy ;;
                *) echo unhealthy ;;
            esac
            ;;
        restarting) echo restarting ;;
        *) echo down ;;
    esac
}

# classify_http CODE -- ready | retry | unexpected.
# 200 is ready. 000 (nothing answered) and 5xx (502 while php-fpm boots) are
# the transient we wait out. Anything else means PHP DID answer, just not with
# the login page -- waiting longer will not fix a wrong tenant slug.
classify_http() {
    case "$1" in
        200) echo ready ;;
        000 | 5[0-9][0-9]) echo retry ;;
        *) echo unexpected ;;
    esac
}

# hot_decision HOT_EXISTS NODE_STATE -- remove | keep | none.
# HOT_EXISTS: yes|no.  NODE_STATE: "absent" (no node container), a compose
# state, or "unknown" (docker could not be asked).
# Remove ONLY when node is positively not running. Unknown is not "not
# running": while `npm run dev` is up the hot file is the truth.
hot_decision() {
    local exists="$1" node_state="$2"
    if [ "$exists" != "yes" ]; then
        echo none
        return
    fi
    case "$node_state" in
        absent | exited | created | dead) echo remove ;;
        *) echo keep ;;
    esac
}

# --- docker-facing helpers ----------------------------------------------------

docker_ok() { timeout 15 docker info >/dev/null 2>&1 </dev/null; }

compose() { docker compose "$@" </dev/null; }

declare -A SVC_STATE=() SVC_HEALTH=()

# load_states -- fills SVC_STATE / SVC_HEALTH from `compose ps -a`. Returns the
# docker rc: a failed query must read as "could not tell", never as "no
# containers".
load_states() {
    local out line svc state health
    SVC_STATE=()
    SVC_HEALTH=()
    out="$(compose ps -a --format '{{.Service}}|{{.State}}|{{.Health}}' 2>/dev/null)" || return 1
    while IFS= read -r line; do
        [ -n "$line" ] || continue
        IFS='|' read -r svc state health <<<"$line"
        SVC_STATE["$svc"]="$state"
        SVC_HEALTH["$svc"]="${health:-}"
    done <<<"$out"
    return 0
}

# evaluate WANTED -- classifies each wanted service from the loaded states.
# Sets EVAL_OK (count), EVAL_BAD (hard problems, "svc=reason" lines),
# EVAL_PENDING (still starting). Returns 0 when everything is ok.
evaluate() {
    local wanted="$1" s verdict
    EVAL_OK=0
    EVAL_BAD=""
    EVAL_PENDING=""
    while IFS= read -r s; do
        [ -n "$s" ] || continue
        verdict="$(classify_service "${SVC_STATE[$s]:-}" "${SVC_HEALTH[$s]:-}")"
        case "$verdict" in
            ok) EVAL_OK=$((EVAL_OK + 1)) ;;
            starting) EVAL_PENDING="${EVAL_PENDING}${s} " ;;
            *) EVAL_BAD="${EVAL_BAD}${s}=${verdict}(${SVC_STATE[$s]:-no container}) " ;;
        esac
    done <<<"$wanted"
    [ -z "$EVAL_BAD" ] && [ -z "$EVAL_PENDING" ]
}

probe_http() {
    local host="$1" path="$2" max="${3:-15}" code
    # curl -s hides the error text, NOT the -w output: a refused connection
    # still prints 000. That is the value we want.
    code="$(curl -ks -o /dev/null -w '%{http_code}' --max-time "$max" \
        --resolve "${host}:${HTTPS_PORT}:127.0.0.1" \
        "https://${host}:${HTTPS_PORT}${path}" 2>/dev/null)" || true
    [ -n "$code" ] || code=000
    printf '%s' "$code"
}

ensure_docker() {
    command -v docker >/dev/null 2>&1 || die "$E_DOCKER" "docker CLI not found in this WSL distro."
    if docker_ok; then return 0; fi

    local active
    active="$(systemctl is-active docker 2>/dev/null)" || true
    if [ "$MODE" = "status" ]; then
        die "$E_DOCKER" "Docker is not usable (systemd says: ${active:-unknown}). Run without --status to start it, or: sudo systemctl start docker"
    fi
    if [ "$active" = "active" ]; then
        die "$E_DOCKER" "Docker daemon is active but 'docker info' fails for this user. Check group membership (id) and 'docker info' by hand."
    fi

    say "Docker is ${active:-not running}; starting it with systemctl ..."
    if [ "$(id -u)" -eq 0 ]; then
        systemctl start docker || die "$E_DOCKER" "systemctl start docker failed."
    else
        sudo -n systemctl start docker 2>/dev/null \
            || die "$E_DOCKER" "Docker is down and 'sudo -n systemctl start docker' was refused (sudo wants a password). Run it by hand: sudo systemctl start docker"
    fi
    local i
    for i in $(seq 1 30); do
        if docker_ok; then
            say "Docker is up."
            return 0
        fi
        sleep 1
    done
    die "$E_DOCKER" "Docker did not become usable within 30 s after systemctl start (journalctl -u docker)."
}

# check_certs -- returns 0, or prints the reason and returns 1.
check_certs() {
    local f
    for f in cert.pem key.pem; do
        if [ ! -s "${ROOT}/docker/ssl/${f}" ]; then
            CERT_PROBLEM="docker/ssl/${f} is missing or empty. nginx cannot start without it. Regenerate with mkcert for registro.local and *.registro.local (see docker/ssl/README.md)."
            return 1
        fi
    done
    if command -v openssl >/dev/null 2>&1 && ! openssl x509 -in "${ROOT}/docker/ssl/cert.pem" -noout -checkend 0 >/dev/null 2>&1; then
        CERT_PROBLEM="docker/ssl/cert.pem is expired or not a readable certificate. Regenerate it with mkcert (see docker/ssl/README.md)."
        return 1
    fi
    return 0
}

# --- main -----------------------------------------------------------------------

main() {
    local rc=0
    parse_args "$@" || rc=$?
    if [ "$rc" -eq 64 ]; then
        usage
        return 0
    fi
    [ "$rc" -eq 0 ] || return "$rc"

    cd "$ROOT" 2>/dev/null || die "$E_PRECOND" "cannot cd to project root: $ROOT"
    [ -f docker-compose.yml ] || die "$E_PRECOND" "no docker-compose.yml in $ROOT -- not the Registro tree."
    command -v curl >/dev/null 2>&1 || die "$E_PRECOND" "curl is not installed in this WSL distro (sudo apt install curl)."

    local tenant_host="${TENANT}.${CENTRAL_HOST}"
    local tenant_path="/admin/login"
    local problem_code=0 problem_msgs=""

    # 1. docker
    ensure_docker

    # 2. certs
    CERT_PROBLEM=""
    if ! check_certs; then
        if [ "$MODE" = "status" ]; then
            problem_code="$E_CERTS"
            problem_msgs="${problem_msgs}${CERT_PROBLEM}"$'\n'
        else
            die "$E_CERTS" "$CERT_PROBLEM"
        fi
    fi

    # 3. what should be running
    # stdout ONLY is the service list: a compose WARN on stderr must never
    # become a "service". stderr is kept aside and shown only on failure.
    local cfg cfg_err_file cfg_err cfg_rc=0 wanted
    cfg_err_file="$(mktemp)" || die "$E_PRECOND" "mktemp failed; cannot capture compose stderr."
    cfg="$(compose config --services 2>"$cfg_err_file")" || cfg_rc=$?
    cfg_err="$(cat "$cfg_err_file" 2>/dev/null)" || true
    rm -f "$cfg_err_file"
    [ "$cfg_rc" -eq 0 ] || die "$E_COMPOSE" "'docker compose config --services' failed (rc=${cfg_rc}):
${cfg_err}"
    wanted="$(printf '%s\n' "$cfg" | filter_services)"
    [ -n "$wanted" ] || die "$E_COMPOSE" "compose lists no services once '${EXCLUDED_SERVICES}' is excluded; refusing to guess."
    local -a wanted_arr
    mapfile -t wanted_arr <<<"$wanted"

    if [ "$MODE" = "up" ]; then
        local out up_rc=0
        say "docker compose up -d ${wanted_arr[*]}   (excluding: ${EXCLUDED_SERVICES})"
        # --no-build: never build here. --pull never: never reach for a registry
        # (offline, that hangs with no timeout). A missing image is a clear error.
        out="$(docker compose up -d --no-build --pull never "${wanted_arr[@]}" 2>&1 </dev/null)" || up_rc=$?
        if [ "$up_rc" -ne 0 ]; then
            die "$E_COMPOSE" "'docker compose up -d' failed (rc=${up_rc}). A missing image is neither built nor pulled here: run 'docker compose build' (app images) or 'docker compose pull <service>' by hand.
${out}"
        fi
        local changed
        changed="$(printf '%s\n' "$out" | grep -E '(Started|Created|Recreated|Starting|Creating|Recreate|Pulled)' || true)"
        if [ -n "$changed" ]; then
            say "compose changed something:"
            printf '%s\n' "$changed" | sed 's/^/    /'
        else
            say "compose: nothing to do (already up)."
        fi
        if printf '%s\n' "$out" | grep -qi 'orphan'; then
            say "WARNING: compose reports orphan containers (a removed service still running). See ci-cd-troubleshooting.md; remove with 'docker rm -f <name>'."
        fi
    fi

    # 4. public/hot
    local hot_exists="no" node_state="unknown" hot_action
    [ -e "${ROOT}/public/hot" ] && hot_exists="yes"
    if load_states; then
        node_state="${SVC_STATE[node]:-absent}"
    fi
    hot_action="$(hot_decision "$hot_exists" "$node_state")"
    HOT_NOTE=""
    case "$hot_action" in
        remove)
            if [ "$MODE" = "up" ]; then
                if rm -f "${ROOT}/public/hot" && [ ! -e "${ROOT}/public/hot" ]; then
                    HOT_NOTE="stale public/hot removed (node is ${node_state})"
                else
                    die "$E_HOT" "stale public/hot exists (node is ${node_state}) and could not be removed: rm ${ROOT}/public/hot"
                fi
            else
                [ "$problem_code" -ne 0 ] || problem_code="$E_HOT"
                problem_msgs="${problem_msgs}stale public/hot present (node is ${node_state}); up.sh removes it."$'\n'
                HOT_NOTE="STALE public/hot present (node is ${node_state})"
            fi
            ;;
        keep)
            HOT_NOTE="public/hot kept (node is ${node_state}: Vite dev mode)"
            ;;
        none)
            HOT_NOTE="no public/hot"
            ;;
    esac

    # 5+6. wait for the app, then final service check
    local http_code="000" verdict="retry" unexpected_streak=0 deadline waited_ok=0
    local services_ok=1
    deadline=$((SECONDS + TIMEOUT))
    if [ "$MODE" = "status" ]; then
        deadline=$SECONDS
    else
        say "waiting for https://${tenant_host}:${HTTPS_PORT}${tenant_path} (up to ${TIMEOUT} s) ..."
    fi
    local down_streak=0 ok_streak=0
    while :; do
        services_ok=1
        if load_states; then
            evaluate "$wanted" && services_ok=0
        else
            EVAL_OK=0 EVAL_PENDING="" EVAL_BAD="docker compose ps failed (cannot tell what is running)"
        fi
        http_code="$(probe_http "$tenant_host" "$tenant_path")"
        verdict="$(classify_http "$http_code")"
        if [ "$verdict" = "unexpected" ]; then
            unexpected_streak=$((unexpected_streak + 1))
        else
            unexpected_streak=0
        fi
        # OK needs TWO consecutive good samples (services running + login 200):
        # one sample can catch a restart-looping container in its "running"
        # moment. Any bad sample resets the count.
        if [ "$verdict" = "ready" ] && [ "$services_ok" -eq 0 ]; then
            ok_streak=$((ok_streak + 1))
            if [ "$ok_streak" -ge 2 ]; then
                waited_ok=1
                break
            fi
            sleep "$POLL_INTERVAL"
            continue
        fi
        ok_streak=0
        [ "$MODE" = "status" ] && break
        # A container that has exited will not come back by waiting.
        if printf '%s' "$EVAL_BAD" | grep -q '=down('; then
            down_streak=$((down_streak + 1))
        else
            down_streak=0
        fi
        if [ "$down_streak" -ge 3 ]; then
            break
        fi
        if [ "$unexpected_streak" -ge 3 ]; then
            break
        fi
        [ "$SECONDS" -lt "$deadline" ] || break
        sleep "$POLL_INTERVAL"
    done

    local tenant_url="https://${tenant_host}:${HTTPS_PORT}${tenant_path}"
    if [ "$waited_ok" -ne 1 ]; then
        say ""
        say "NOT OK"
        say "  tenant login : ${tenant_url} -> HTTP ${http_code}"
        say "  services     : ${EVAL_OK}/${#wanted_arr[@]} ok"
        [ -z "$EVAL_BAD" ] || say "  problem      : ${EVAL_BAD}"
        [ -z "$EVAL_PENDING" ] || say "  still starting: ${EVAL_PENDING}"
        [ -z "$problem_msgs" ] || printf '  %s\n' "$problem_msgs"
        say "  public/hot   : ${HOT_NOTE}"
        local code="$E_TIMEOUT"
        if [ -n "$EVAL_BAD" ]; then
            code="$E_SERVICES"
            say "  hint         : docker compose logs --tail 50 <service>"
        elif [ "$verdict" = "unexpected" ]; then
            say "  hint         : the app answered HTTP ${http_code}, not 200. Wrong tenant? try --tenant <slug>."
        elif [ "$verdict" = "ready" ] && [ "$problem_code" -ne 0 ]; then
            code="$problem_code"
        else
            say "  hint         : 5xx/000 for long usually means php-fpm in 'app' is not up: docker compose logs --tail 50 app"
        fi
        say "  exit code    : ${code}"
        return "$code"
    fi

    # Healthy so far. A status run can still carry a degraded finding.
    if [ "$problem_code" -ne 0 ]; then
        say ""
        say "NOT OK (degraded)"
        printf '  %s\n' "$problem_msgs"
        say "  exit code    : ${problem_code}"
        return "$problem_code"
    fi

    local central_code branch node_note
    central_code="$(probe_http "$CENTRAL_HOST" "/" 15)"
    branch="$(git -C "$ROOT" branch --show-current 2>/dev/null)" || true
    [ -n "$branch" ] || branch="unknown"
    node_note="${SVC_STATE[node]:-absent}"

    say ""
    if [ "$MODE" = "status" ]; then
        say "OK (status only; nothing was changed)"
    else
        say "OK"
    fi
    say "  services : ${EVAL_OK}/${#wanted_arr[@]} running -- ${wanted_arr[*]}"
    say "  node     : ${node_note} (by design: only for 'npm run dev'; never started here)"
    say "  tenant   : ${tenant_url} -> HTTP ${http_code}"
    say "  central  : https://${CENTRAL_HOST}:${HTTPS_PORT}/ -> HTTP ${central_code} (informational)"
    say "  assets   : ${HOT_NOTE}"
    say "  branch   : ${branch}"
    return 0
}

if [ "${BASH_SOURCE[0]}" = "$0" ]; then
    main "$@"
    exit $?
fi
