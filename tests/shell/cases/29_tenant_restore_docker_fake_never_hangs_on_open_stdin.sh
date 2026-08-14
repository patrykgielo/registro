#!/bin/bash
###############################################################################
# Regression test for a bug found in review, never shipped: an earlier fix
# for cases 15/16/17/18's SIGPIPE flake (see those files' own comments) put
# the stdin-drain (`cat >/dev/null`) in the CATCH-ALL branch of the fake
# `docker` in 16/17/18, not scoped to the one actually-piped invocation like
# case 15 does. Every OTHER, non-piped docker call (docker compose exec,
# etc.) then also tried to read its stdin to EOF -- but those inherit
# WHATEVER stdin the test process itself has, not a pipe from a fake writer.
# Run interactively (a terminal) or with a FIFO held open by something else
# (this case's own setup), that never reaches EOF, so `cat` blocks forever.
#
# `bash tests/shell/run.sh` -- documented as THE one command for this suite
# (tests.md) -- does `out="$(bash "$case_file" 2>&1)"` with no stdin
# redirection of its own, so it inherits run.sh's own stdin. From an
# interactive terminal that is exactly the shape that hangs. This case
# proves the current (narrow-scoped) fix terminates under that shape; run
# against the broad catch-all version it is meant to catch, it times out
# (verified manually before writing this case: `timeout 8 bash
# 16_...sh <fifo` -> exit 124 on the catch-all version, exit 0 -- with a
# real PASS -- on the narrow-scoped one).
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "tenant-restore.sh test cases: fake docker must never block on an open (non-EOF) stdin"
sandbox_init

CASES_DIR="$(dirname "${BASH_SOURCE[0]}")"
FIFO="$SANDBOX/open-stdin.fifo"
mkfifo "$FIFO"
# `<>` (read-write), not `>` (write-only), and on THIS shell's own fd 3, not
# a backgrounded subprocess -- the standard non-blocking way to hold a FIFO
# open as a permanent, silent, never-EOF writer. A plain `exec 3>"$FIFO"`
# blocks until a reader connects, and nothing has opened the read end yet at
# this point in the script -- an actual subprocess (a backgrounded `sleep`)
# was tried first and reverted: it forks a REAL child process for `sleep`
# that a `kill` on the subshell's own PID does not reach (the child is
# reparented, not killed, once its parent dies), leaking an orphaned `sleep`
# for the rest of its natural duration on every run -- confirmed directly
# (`ps aux` still showed it immediately after this script's own exit). It
# ALSO, independently, inherited this script's own stdout as a background
# job with `&` and no redirect, which held run.sh's own `$(...)` command
# substitution open for the full sleep duration even though "PASS" had
# already printed -- a background job with `&` inherits open fds unless
# explicitly redirected. Opening fd 3 in THIS process, never forking
# anything, has neither failure mode: fd 3 closes automatically (or via the
# explicit `exec 3>&-` below) the moment this script's own process exits,
# same instant `$(...)` around it returns, nothing left running after.
exec 3<>"$FIFO"

for case_name in 16_tenant_restore_live_maintenance_wraps_both_phases \
                 17_tenant_restore_live_skip_db_still_enters_maintenance \
                 18_tenant_restore_live_db_failure_aborts_before_files; do
    OUT="$(timeout 10 bash "${CASES_DIR}/${case_name}.sh" <"$FIFO" 2>&1)"
    RC=$?
    if [ "$RC" -eq 124 ]; then
        fail "${case_name}: hung past the 10s bound with an open (non-EOF) stdin -- a catch-all stdin drain in its fake docker would produce exactly this"
    elif ! printf '%s' "$OUT" | grep -q '^PASS'; then
        fail "${case_name}: did not hang, but did not pass either -- unrelated failure, see: ${OUT}"
    fi
done

exec 3>&-

test_finish
