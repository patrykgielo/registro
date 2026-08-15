#!/bin/bash
###############################################################################
# Pins: ci-cd-troubleshooting.md "TENANT_PREFIX not in .env" -- deploy.sh's
# `status` action (and, separately, force_clear_flag()) read TENANT_PREFIX
# via `grep -m1 ... | cut -d= -f2-` under `set -euo pipefail`. An ABSENT key
# -- the legacy stack's normal, documented state (deployment.md:
# "TENANT_PREFIX= PUSTE na legacy") -- makes grep exit 1 (no match) while cut
# exits 0; pipefail takes the higher of the two, so the pipeline itself
# reports failure and `set -e` kills the script before `docker ps` ever runs.
# Found on UAT by running the real forced command, not by reading the code:
# `bash -x` stopped exactly at this substitution with no output and exit 1.
#
# Extracts the real `status)` case arm verbatim (technique 2 in harness.sh's
# own header) rather than a copy, so this fails against the bug and passes
# against the fix using the file's ACTUAL current text -- a grep for the
# `|| true` string would pass on cosmetic rewrites that don't fix the
# underlying pipefail interaction, and fail on a correct fix phrased
# differently (e.g. `grep ... || printf ''`).
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "deploy.sh: status action survives .env with no TENANT_PREFIX line at all"
sandbox_init

# Legacy .env: no TENANT_PREFIX line whatsoever -- not even empty. This is
# the documented, normal legacy state, not a corrupted file.
cat >"$SANDBOX/.env" <<'ENV'
APP_URL=https://example.com
REDIS_PASSWORD=secret
ENV

fake_exe docker <<'EOS'
exit 0
EOS

BLOCK="$(extract_between_exact "$SCRIPTS_DIR/deploy.sh" '    status)' '        ;;')"
[ -n "$BLOCK" ] || fail "could not extract the status) case arm from deploy.sh -- did its shape change?"

OUT="$(APP_DIR="$SANDBOX" bash -c "
set -euo pipefail
APP_DIR=\"\$1\"
case \"status\" in
$BLOCK
esac
" _ "$SANDBOX" 2>&1)"
RC=$?

assert_eq "0" "$RC" "status action exit code with TENANT_PREFIX absent"
assert_contains "$OUT" "" "status action produced output"

CALLS="$(cat "$CALL_LOG")"
assert_contains "$CALLS" "name=registro-" "docker ps filter falls back to the 'registro' default when TENANT_PREFIX is absent"

test_finish
