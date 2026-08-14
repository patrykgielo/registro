#!/bin/bash
###############################################################################
# Pins: ci-cd-troubleshooting.md, "docker compose run w forced-command
# recovery path" -- every Compose subcommand (run/config/ps) interpolates
# the WHOLE file before selecting a service, and docker-compose.prod.yml
# hard-requires APP_KEY/APP_DOMAIN/REDIS_PASSWORD via ${VAR:?}. A blanked or
# corrupted .env -- the exact scenario this function exists to recover from
# -- makes `docker compose run` die at interpolation, before --entrypoint rm
# ever executes. The fix computes the volume name and calls raw `docker`
# only, never `docker compose`.
#
# Asserts on OBSERVABLE BEHAVIOUR (which binary/subcommand was actually
# invoked), not on source text -- a grep for "docker compose" would pass
# even if the fix were reverted in a way that still avoided the literal
# string, and would break on innocent comment edits.
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "apply.sh: force_clear_flag() never invokes 'docker compose'"
sandbox_init

fake_exe docker <<'EOS'
case "$1" in
    volume) [ "$2" = "inspect" ] && exit 0; exit 1 ;;
    run) exit 0 ;;
    compose)
        # The exact regression: recorded so the assertion below can catch
        # it, but this fake still "succeeds" so a reintroduced bug shows up
        # as a wrong call log, not a hang.
        exit 1
        ;;
esac
exit 0
EOS

TENANT_PREFIX="tenant-acme"
TAG="v1.0.0"
LOG_FILE="$SANDBOX/log"
log() { echo "$*" >>"$LOG_FILE"; }

FN_SRC="$(extract_between_exact "$SCRIPTS_DIR/apply.sh" 'force_clear_flag() {' '}')"
[ -n "$FN_SRC" ] || fail "could not extract force_clear_flag() from apply.sh -- did its shape change?"

eval "$FN_SRC"

if force_clear_flag; then
    RC=0
else
    RC=$?
fi

CALLS="$(cat "$CALL_LOG")"
assert_eq "0" "$RC" "force_clear_flag() exit code"
assert_not_contains "$CALLS" "docker compose" "commands invoked"
assert_contains "$CALLS" "docker volume inspect tenant-acme_storage-framework" "docker volume inspect"
assert_contains "$CALLS" "--entrypoint rm" "docker run invocation"

test_finish
