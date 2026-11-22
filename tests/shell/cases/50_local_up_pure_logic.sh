#!/bin/bash
###############################################################################
# Pins scripts/local/up.sh's pure decisions -- the ones that, wrong, either
# start the Vite dev container nobody asked for, delete a hot file that is
# live, or wait 120 s on a typo. The real file is SOURCED (its main is guarded),
# so editing the script and re-running this is what proves red-then-green; there
# is no copy to keep in sync.
#
#   * filter_services:  `node` is never in the set `compose up -d` gets
#   * missing_services: wanted-minus-running, blank lines ignored
#   * classify_service: restarting/unhealthy/exited are never "ok"
#   * classify_http:    502/000 retry; 200 ready; 404/302 is NOT a transient
#   * hot_decision:     remove ONLY when node is positively not running --
#                       "could not ask docker" must keep the file
#   * parse_args:       slug and timeout are validated (they reach curl --resolve)
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "local up.sh: pure decisions (service diff, node exclusion, public/hot, http, args)"

UP="${REPO_ROOT}/scripts/local/up.sh"
if [ ! -f "$UP" ]; then
    fail "scripts/local/up.sh missing"
    test_finish
fi

# shellcheck source=/dev/null
source "$UP"
set +e # up.sh sets -u/pipefail only; assertions below inspect rc by hand

# --- filter_services ---------------------------------------------------------
GOT="$(printf 'redis\nnode\napp\r\nmysql\n\napp\n' | filter_services | tr '\n' ' ')"
assert_eq "app mysql redis " "$GOT" "filter_services drops node, blank lines, CR and duplicates"

GOT="$(printf 'nodex\nnode\nsubnode\n' | filter_services | tr '\n' ' ')"
assert_eq "nodex subnode " "$GOT" "filter_services excludes exactly 'node', not names containing it"

GOT="$(printf 'node\n' | filter_services)"
assert_eq "" "$GOT" "a list of only node filters to empty (caller must refuse to guess)"

GOT="$(printf 'app\nlast-no-newline' | filter_services | tr '\n' ' ')"
assert_eq "app last-no-newline " "$GOT" "last line without trailing newline is kept"

# --- missing_services --------------------------------------------------------
GOT="$(missing_services $'app\nhorizon\nredis' $'app\nredis' | tr '\n' ' ')"
assert_eq "horizon " "$GOT" "missing_services: wanted but not running"
GOT="$(missing_services $'app\nredis' $'app\nredis\nnode' | tr '\n' ' ')"
assert_eq "" "$GOT" "missing_services: extra running services are not 'missing'"
GOT="$(missing_services $'app\nredis' '' | tr '\n' ' ')"
assert_eq "app redis " "$GOT" "missing_services: empty running list = everything missing"

# --- classify_service --------------------------------------------------------
assert_eq "ok" "$(classify_service running '')" "running, no healthcheck"
assert_eq "ok" "$(classify_service running healthy)" "running, healthy"
assert_eq "starting" "$(classify_service running starting)" "running, health starting"
assert_eq "unhealthy" "$(classify_service running unhealthy)" "running, unhealthy"
assert_eq "restarting" "$(classify_service restarting '')" "restarting is not ok"
assert_eq "down" "$(classify_service exited '')" "exited"
assert_eq "down" "$(classify_service '' '')" "no container at all"
assert_eq "down" "$(classify_service paused '')" "paused"

# --- classify_http -----------------------------------------------------------
assert_eq "ready" "$(classify_http 200)" "200"
assert_eq "retry" "$(classify_http 502)" "502 while php-fpm boots"
assert_eq "retry" "$(classify_http 000)" "000 nothing answered"
assert_eq "retry" "$(classify_http 503)" "503"
assert_eq "retry" "$(classify_http 500)" "500 during boot"
assert_eq "unexpected" "$(classify_http 404)" "404 is not a transient"
assert_eq "unexpected" "$(classify_http 302)" "302 is not a transient"
assert_eq "unexpected" "$(classify_http 403)" "403 is not a transient"

# --- hot_decision ------------------------------------------------------------
assert_eq "none" "$(hot_decision no exited)" "no hot file -> nothing to do"
assert_eq "none" "$(hot_decision no running)" "no hot file, node running -> nothing to do"
assert_eq "remove" "$(hot_decision yes exited)" "hot + node exited -> remove"
assert_eq "remove" "$(hot_decision yes absent)" "hot + no node container -> remove"
assert_eq "remove" "$(hot_decision yes created)" "hot + node created (never started) -> remove"
assert_eq "keep" "$(hot_decision yes running)" "hot + node RUNNING -> keep (live Vite)"
assert_eq "keep" "$(hot_decision yes restarting)" "hot + node restarting -> keep"
assert_eq "keep" "$(hot_decision yes unknown)" "hot + docker could not be asked -> keep"
assert_eq "keep" "$(hot_decision yes '')" "hot + empty state string -> keep (cannot tell)"

# --- parse_args --------------------------------------------------------------
reset_args() { MODE=up TENANT=firma TIMEOUT=120; }

reset_args; parse_args >/dev/null 2>&1; RC=$?
assert_eq "0" "$RC" "no args is valid"
assert_eq "up" "$MODE" "default mode"

reset_args; parse_args --status --tenant demo-2 --timeout 30 >/dev/null 2>&1; RC=$?
assert_eq "0" "$RC" "all flags valid"
assert_eq "status" "$MODE" "--status"
assert_eq "demo-2" "$TENANT" "--tenant"
assert_eq "30" "$TIMEOUT" "--timeout"

for bad in 'a b' '../x' 'UPPER' '-lead' 'trail-' 'a.b' 'a;b' '$(x)' ''; do
    reset_args; parse_args --tenant "$bad" >/dev/null 2>&1; RC=$?
    assert_eq "2" "$RC" "tenant [${bad}] rejected"
done
for bad in 0 abc -5 '1.5' '' '10s'; do
    reset_args; parse_args --timeout "$bad" >/dev/null 2>&1; RC=$?
    assert_eq "2" "$RC" "timeout [${bad}] rejected"
done
reset_args; parse_args --tenant >/dev/null 2>&1; RC=$?
assert_eq "2" "$RC" "--tenant without a value"
reset_args; parse_args --timeout >/dev/null 2>&1; RC=$?
assert_eq "2" "$RC" "--timeout without a value"
reset_args; parse_args --fresh >/dev/null 2>&1; RC=$?
assert_eq "2" "$RC" "unknown flag is refused, never ignored"
reset_args; parse_args --help >/dev/null 2>&1; RC=$?
assert_eq "64" "$RC" "--help"

test_finish
