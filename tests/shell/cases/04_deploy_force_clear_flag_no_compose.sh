#!/bin/bash
###############################################################################
# Pins: the same incident as case 03 (ci-cd-troubleshooting.md, "docker
# compose run w forced-command recovery path"), in deploy.sh's own
# force_clear_flag() -- the ORIGINAL site of the bug (found in code review,
# never shipped). apply.sh's copy (case 03) is a later duplicate of the same
# fixed shape.
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "deploy.sh: force_clear_flag() never invokes 'docker compose'"
sandbox_init

fake_exe docker <<'EOS'
case "$1" in
    volume) [ "$2" = "inspect" ] && exit 0; exit 1 ;;
    run) exit 0 ;;
    compose) exit 1 ;;
esac
exit 0
EOS

mkdir -p "$SANDBOX/legacy"
cat >"$SANDBOX/legacy/.env" <<'ENV'
TENANT_PREFIX=
REDIS_PASSWORD=
ENV
APP_DIR="$SANDBOX/legacy"
LOG_FILE="$SANDBOX/log"
log() { echo "$*" >>"$LOG_FILE"; }

FN_SRC="$(extract_between_exact "$SCRIPTS_DIR/deploy.sh" 'force_clear_flag() {' '}')"
[ -n "$FN_SRC" ] || fail "could not extract force_clear_flag() from deploy.sh -- did its shape change?"

eval "$FN_SRC"

if force_clear_flag; then
    RC=0
else
    RC=$?
fi

CALLS="$(cat "$CALL_LOG")"
assert_eq "0" "$RC" "force_clear_flag() exit code"
assert_not_contains "$CALLS" "docker compose" "commands invoked"
assert_contains "$CALLS" "docker volume inspect registro_storage-framework" "docker volume inspect (empty TENANT_PREFIX -> registro default)"
assert_contains "$CALLS" "--entrypoint rm" "docker run invocation"

test_finish
