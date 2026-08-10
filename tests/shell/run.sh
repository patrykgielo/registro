#!/bin/bash
###############################################################################
# tests/shell/run.sh -- run every tests/shell/cases/*.sh and report.
#
# The one command for this suite (documented in .claude/rules/tests.md and
# app/docs/deployment/tenant-apply.md):
#
#   bash tests/shell/run.sh
#
# Each case in cases/ is a standalone bash script that prints exactly one
# "PASS: <name>" or "FAIL: <name>" line (plus indented reasons on failure)
# and exits 0/1 accordingly -- see lib/harness.sh's test_start/test_finish.
# This runner does not know or care what a case tests; it only aggregates
# exit codes, so adding a new case is "drop a new file in cases/", nothing
# else to register.
###############################################################################

set -uo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")"

total=0
failed=0

for case_file in cases/*.sh; do
    [ -f "$case_file" ] || continue
    total=$((total + 1))
    out="$(bash "$case_file" 2>&1)"
    rc=$?
    echo "$out"
    if [ "$rc" -ne 0 ]; then
        failed=$((failed + 1))
    fi
done

echo
echo "== ${total} test(s), $((total - failed)) passed, ${failed} failed =="

[ "$failed" -eq 0 ]
