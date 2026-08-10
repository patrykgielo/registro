#!/bin/bash
###############################################################################
# Pins: the same incident as case 01 (ci-cd-troubleshooting.md, "Faza 3"),
# in apply.sh's OWN duplicate of stage_volume() -- deliberately duplicated
# rather than shared (see that function's own header comment), so it can
# drift independently and needs its own pin.
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "apply.sh: stage_volume() must pass --entrypoint to docker run"
sandbox_init

fake_exe docker <<'EOS'
case "$1" in
    volume)
        [ "$2" = "inspect" ] && exit 0
        exit 1
        ;;
    run)
        shift
        has_entrypoint=false
        for a in "$@"; do [ "$a" = "--entrypoint" ] && has_entrypoint=true; done
        if [ "$has_entrypoint" = true ]; then
            exit 0
        fi
        echo "CRITICAL: Running as 'root' but expected 'laravel'" >&2
        exit 1
        ;;
esac
exit 0
EOS

LOG_FILE="$SANDBOX/log"
TAG="v9.9.9"
log() { echo "$*" >>"$LOG_FILE"; }

FN_SRC="$(extract_between_exact "$SCRIPTS_DIR/apply.sh" '        stage_volume() {' '        }')"
[ -n "$FN_SRC" ] || fail "could not extract apply.sh's stage_volume() -- did its shape/indentation change?"

eval "$FN_SRC"

if stage_volume "fake-vol" "$SANDBOX/dest"; then
    RC=0
else
    RC=$?
fi

assert_eq "0" "$RC" "stage_volume() exit code (with the real, current source)"
assert_contains "$(cat "$CALL_LOG")" "--entrypoint" "docker run invocation"

test_finish
