#!/bin/bash
###############################################################################
# Pins: ci-cd-troubleshooting.md, "Faza 3 ... stage_volume() cicho tworzyło
# PUSTY backup obu wolumenów storage na prawdziwym obrazie" -- the registro
# image's own entrypoint.sh refuses to run as anyone but `laravel`, so
# `docker run --user 0:0 ...` without `--entrypoint` dies before `cp` ever
# runs, and the surrounding `stage_volume()` swallowed that as a plain
# `return 1` -- indistinguishable from "restic not installed yet" to an
# operator reading DEGRADED.
#
# Extracts the REAL stage_volume() body out of tenant-backup.sh (not a copy)
# so editing the file and re-running this case is what proves red-then-green
# -- see lib/harness.sh's own header on why extraction, not a full run, is
# used here.
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "tenant-backup.sh: stage_volume() must pass --entrypoint to docker run"
sandbox_init

# Simulates the REAL image's entrypoint.sh: `docker run` "succeeds" at
# starting a container that refuses to do anything and exits 1, UNLESS
# --entrypoint is among the arguments -- exactly the behaviour that made the
# original bug look like a successful, empty backup instead of a failure.
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
REGISTRO_IMAGE="ghcr.io/patrykgielo/registro:vTEST"
log() { echo "$*" >>"$LOG_FILE"; }

FN_SRC="$(extract_between_exact "$SCRIPTS_DIR/tenant-backup.sh" 'stage_volume() {' '}')"
[ -n "$FN_SRC" ] || fail "could not extract stage_volume() from tenant-backup.sh -- did its shape change?"

eval "$FN_SRC"

if stage_volume "fake-vol" "$SANDBOX/dest"; then
    RC=0
else
    RC=$?
fi

assert_eq "0" "$RC" "stage_volume() exit code (with the real, current source)"
assert_contains "$(cat "$CALL_LOG")" "--entrypoint" "docker run invocation"

test_finish
