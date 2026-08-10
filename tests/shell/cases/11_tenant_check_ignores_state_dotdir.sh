#!/bin/bash
###############################################################################
# BONUS pin (cheap, per this task's brief): ci-cd-troubleshooting.md, "6
# bugów" point 5 -- `find`, unlike a bash glob, does not skip dot-entries by
# default. tenant-check.sh's own orphan scan reported apply.sh's bookkeeping
# directory (STACKS_ROOT/.state/) as an "osierocony katalog tenanta".
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "tenant-check.sh: STACKS_ROOT/.state/ is never reported as an orphan tenant directory"
sandbox_init

STACKS_ROOT="$SANDBOX/stacks"
mkdir -p "$STACKS_ROOT/.state/acme"
: >"$STACKS_ROOT/.state/acme/apply.log"
mkdir -p "$STACKS_ROOT/acme"
cat >"$STACKS_ROOT/acme/.env" <<'ENV'
TRUSTED_PROXIES_CIDR=
ENV

# tenant-check.sh tolerates docker being entirely unreachable for the
# per-tenant container/network/mysql checks (every call is guarded with
# `>/dev/null 2>&1`) -- only the orphan section (docker ps -a) needs a
# real-looking answer, and an empty one is a legitimate "no containers".
fake_exe docker <<'EOS'
[ "$1" = "ps" ] && exit 0
exit 1
EOS

OUT="$(REGISTRO_STACKS_ROOT="$STACKS_ROOT" \
    REGISTRO_TENANT_CHECK_LOG="$SANDBOX/check.log" \
    bash "$SCRIPTS_DIR/tenant-check.sh" 2>&1)"

assert_not_contains "$OUT" ".state" "tenant-check.sh findings"

test_finish
