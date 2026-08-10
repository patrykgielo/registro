#!/bin/bash
###############################################################################
# Pins: ci-cd-troubleshooting.md, "6 bugów w jednej sesji walidacji" point 2
# -- "Plik locka leżał W ŚRODKU katalogu, do którego git clone miał dopiero
# sklonować repo." A lock/log file written into STACK_DIR before `git clone`
# made the directory non-empty, and `git clone` refuses to clone into a
# non-empty directory -- every FIRST apply for a brand-new tenant failed.
#
# Runs the real apply.sh end-to-end (not extracted) up through the point a
# faked DNS failure makes it die() -- lock/log/status are all written
# BEFORE check_dns runs (see apply.sh's own step ordering), so this needs no
# git/docker fakes at all to reach and prove the thing being pinned: after
# the run, the tenant's own git-clone target must still be untouched, and
# everything this script itself wrote must live in a SEPARATE directory.
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "apply.sh: lock/log/state live outside the git clone target (STACK_DIR)"
sandbox_init

# DNS never resolves -- the first real precondition check apply.sh performs,
# so the script dies here deterministically, right after the lock/state
# setup this test cares about and before anything needs git/docker/disk.
fake_exe dig <<'EOS'
exit 0
EOS

STACKS_ROOT="$SANDBOX/stacks"
OUT="$(REGISTRO_STACKS_ROOT="$STACKS_ROOT" \
    bash "$SCRIPTS_DIR/apply.sh" acme v1.0.0 acme.example.com --foreground 2>&1)"
RC=$?

assert_eq "2" "$RC" "apply.sh exit code (DNS precondition failure)"
assert_contains "$OUT" "does not resolve" "error message"

[ -e "${STACKS_ROOT}/acme" ] \
    && fail "STACK_DIR (${STACKS_ROOT}/acme) was created -- the git clone target must stay untouched/empty until git clone itself runs"

[ -f "${STACKS_ROOT}/.state/acme/apply.lock" ] \
    || fail "STATE_DIR's apply.lock is missing -- lock did not end up in ${STACKS_ROOT}/.state/acme/"
[ -f "${STACKS_ROOT}/.state/acme/apply.log" ] \
    || fail "STATE_DIR's apply.log is missing"
[ -f "${STACKS_ROOT}/.state/acme/apply-status" ] \
    || fail "STATE_DIR's apply-status is missing"
assert_contains "$(cat "${STACKS_ROOT}/.state/acme/apply-status" 2>/dev/null)" "FAILED" "apply-status content"

test_finish
