#!/bin/bash
###############################################################################
# Pins: the ownership gap named in ci-cd-troubleshooting.md and
# instalacja-tenanta-od-zera.md Część 8.6/8.7 -- the backup side chowns
# staged files back to a known owner after `cp -a` re-stamps them; the
# restore side had NO counterpart at all until tenant-restore.sh existed,
# so files restored into a live volume could land unwritable by the app's
# real UID (ADR-013's fixed `laravel`, 1000).
#
# Extracts the REAL restore_files_live() body out of tenant-restore.sh (not
# a copy) so editing the file and re-running this case is what proves
# red-then-green -- same technique as cases 01/02 for the backup-side
# stage_volume() bug, applied here to its restore-side counterpart.
#
# Deliberately does NOT rely on any host UID coincidence (the trap named in
# ci-cd-troubleshooting.md's Faza 2 finding: a test whose runner UID
# happens to equal the app's UID can pass even with the chown missing).
# The fake `docker run` below only records invocation arguments -- it does
# not actually extract or chown anything -- so this assertion is purely
# "did the script ASK for 1000:1000", independent of who runs the test.
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "tenant-restore.sh: restore_files_live() must chown -R 1000:1000 after extracting"
sandbox_init

fake_exe docker <<'EOS'
case "$1" in
    volume) [ "$2" = "inspect" ] && exit 0; exit 1 ;;
    # `cat >/dev/null` drains stdin before exiting -- the REAL `docker run -i
    # ... tar -x` this stands in for genuinely reads all of restic's piped
    # output before its own process exits. Without this, the fake exits
    # immediately and can close its end of the pipe before the fake restic
    # below (a separate forked process) has even started writing -- under
    # CPU contention this is a real, reproducible race: `echo` in the writer
    # then gets SIGPIPE (exit 141), and `set -o pipefail` (this file's own
    # `set -uo pipefail`) turns that into the pipeline's own exit status,
    # failing this assertion despite restore_files_live() itself being
    # correct. Found by stress-testing the suite under artificial CPU load
    # (`yes >/dev/null &` on every core) after this test was reported flaky
    # under load in review -- reproduced red without this line, green with
    # it, both confirmed across a dozen loaded runs.
    run) cat >/dev/null; exit 0 ;;
esac
exit 0
EOS
fake_exe restic <<'EOS'
[ "$1" = "dump" ] && { echo "fake-tar-stream"; exit 0; }
exit 0
EOS

LOG_FILE="$SANDBOX/log"
SNAPSHOT="latest"
EXTRACT_IMAGE="debian:bookworm-slim"
log() { echo "$*" >>"$LOG_FILE"; }

FN_SRC="$(extract_between_contains "$SCRIPTS_DIR/tenant-restore.sh" 'restore_files_live() {' 'ERROR: restoring into volume')"
[ -n "$FN_SRC" ] || fail "could not extract restore_files_live() from tenant-restore.sh -- did its shape change?"
# extract_between_contains stops at the first line CONTAINING the end
# marker, which lands mid-block (inside the `|| {` on failure) -- close the
# two open braces the same way case 01/02 rely on extract_between_exact's
# precise brace matching; here contains is needed because the failure
# message itself is the only unique anchor near the function's real end.
FN_SRC="${FN_SRC}
        return 1
    }
}"

eval "$FN_SRC"

restore_files_live "/tmp/x-backup-files-AbCdEf/storage-app-public" "3" "tenant-acme_storage-app-public"
RC=$?

assert_eq "0" "$RC" "restore_files_live() exit code (with the real, current source)"
# %q-quoted in the call log (fake_exe's own logging, harness.sh) -- spaces
# inside the single `sh -c` argument come out backslash-escaped, not as
# literal spaces.
assert_contains "$(cat "$CALL_LOG")" 'chown\ -R\ 1000:1000\ /dest' "docker run invocation must chown to the fixed laravel UID"
assert_contains "$(cat "$CALL_LOG")" 'tar\ -x\ -C\ /dest\ --strip-components=3' "docker run invocation must extract with the right strip count"

test_finish
