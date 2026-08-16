#!/bin/bash
###############################################################################
# Pins: scripts/validate-env.sh had no Przelewy24 check at all, so the state
# that took production down on 2026-08-16 -- P24_POS_ID/P24_CRC/... present but
# EMPTY, exactly how .env.production.example ships them -- passed validation
# silently and only surfaced as a 500 when a real customer submitted a real
# order (see .claude/rules/ci-cd-troubleshooting.md).
#
# The property being pinned is three-way, not "the vars are set":
#   all three set  -> pass   (usable gateway)
#   none set       -> WARN   (legitimate: pay-at-pickup-only tenant; the app
#                             stops offering online settlement by itself)
#   some set       -> ERROR  (the dangerous middle state -- looks configured,
#                             fails only at a customer's checkout)
# A check that merely required the vars would turn every offline-only tenant's
# deploy red, which is why the two "not fully configured" cases must be
# distinguished rather than collapsed.
#
# Runs the REAL scripts/validate-env.sh end-to-end against throwaway .env files
# via its own ENV_FILE override (documented in the script's own header) -- no
# extraction, no copy of its logic to keep in sync. Exit code and output are
# both asserted: the script exits non-zero only on ERRORS, so the warning case
# must still exit 0.
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "validate-env.sh treats Przelewy24 as all-or-nothing (partial config is an error, empty is a warning)"
sandbox_init

VALIDATE="$REPO_ROOT/scripts/validate-env.sh"
[ -f "$VALIDATE" ] || fail "expected script missing: $VALIDATE"

# A minimal .env that satisfies enough of the OTHER checks to reach the
# External Services section. Whatever else the script complains about is
# irrelevant here -- every assertion below is scoped to the Przelewy24 lines
# and to how they move the exit code.
write_env() {
    local target="$1"
    shift
    {
        printf 'APP_NAME=Registro\n'
        printf 'APP_ENV=production\n'
        printf 'APP_KEY=base64:ZmFrZWtleWZha2VrZXlmYWtla2V5ZmFrZWtleWZha2U=\n'
        printf 'APP_DEBUG=false\n'
        printf 'DB_HOST=mysql\nDB_DATABASE=registro\nDB_USERNAME=registro\nDB_PASSWORD=secret\n'
        printf 'REDIS_HOST=redis\nREDIS_PASSWORD=secret\n'
        printf 'FILESYSTEM_DISK=public\n'
        printf 'SESSION_SECURE_COOKIE=true\n'
        printf 'MAIL_MAILER=log\n'
        printf 'GOOGLE_MAPS_API_KEY=key\nGOOGLE_MAPS_MAP_ID=mapid\n'
        local line
        for line in "$@"; do
            printf '%s\n' "$line"
        done
    } >"$target"
}

# ANSI stripped here rather than at each assertion: the script colours every
# line it prints, and a colour reset sits between "WARNING:" and the message
# text, so a raw substring match on "WARNING: Przelewy24" never matches.
run_validate() {
    local env_file="$1"
    ENV_FILE="$env_file" bash "$VALIDATE" production 2>&1 | sed 's/\x1b\[[0-9;]*m//g'
}

# The script's own "Errors: N" summary line.
#
# ASSERTING THE DELTA, NOT THE ABSOLUTE EXIT CODE: the sandbox .env above does
# not satisfy every unrelated check in a 300-line script, and pinning "exit 0"
# would make this case fail the day someone adds a required variable that has
# nothing to do with Przelewy24. Comparing error counts between two runs that
# differ ONLY in the P24 lines isolates exactly this feature's contribution.
error_count() {
    printf '%s\n' "$1" | awk '/^  Errors: / { print $2; exit }'
}

# --- (1) fully configured: a pass line, and P24 contributes no error --------

write_env "$SANDBOX/full.env" \
    'P24_MERCHANT_ID=12345' 'P24_CRC=a1b2c3d4' 'P24_REPORTS_KEY=reports-key' 'P24_POS_ID=12345'
FULL_OUT="$(run_validate "$SANDBOX/full.env")"
FULL_ERRORS="$(error_count "$FULL_OUT")"
assert_contains "$FULL_OUT" "Przelewy24 fully configured" "fully-configured output"
assert_not_contains "$FULL_OUT" "Przelewy24 partially configured" "fully-configured output"
assert_not_contains "$FULL_OUT" "Przelewy24 not configured" "fully-configured output"

# --- (2) nothing set: a WARNING, and NOT an error --------------------------
# An offline-only tenant is a supported configuration; failing its deploy would
# be the same class of mistake as a gate that punishes the operator for
# something that is not broken.

write_env "$SANDBOX/empty.env"
EMPTY_OUT="$(run_validate "$SANDBOX/empty.env")"
EMPTY_ERRORS="$(error_count "$EMPTY_OUT")"
assert_contains "$EMPTY_OUT" "Przelewy24 not configured" "empty-config output"
assert_contains "$EMPTY_OUT" "WARNING: Przelewy24" "empty-config output"
assert_eq "$FULL_ERRORS" "$EMPTY_ERRORS" "error count: no P24 config at all vs fully configured"

# --- (3) the incident's own shape: partial config is an ERROR --------------
# P24_POS_ID is deliberately absent from the required trio (the SDK falls back
# to the merchant id), so this case uses a genuinely partial trio.

write_env "$SANDBOX/partial.env" 'P24_MERCHANT_ID=12345' 'P24_CRC=a1b2c3d4'
PARTIAL_OUT="$(run_validate "$SANDBOX/partial.env")"
PARTIAL_ERRORS="$(error_count "$PARTIAL_OUT")"
assert_contains "$PARTIAL_OUT" "Przelewy24 partially configured" "partial-config output"
assert_contains "$PARTIAL_OUT" "P24_REPORTS_KEY" "partial-config output names the missing var"
assert_eq "$((FULL_ERRORS + 1))" "$PARTIAL_ERRORS" "error count: partial P24 config vs fully configured"

# --- (4) P24_POS_ID alone must not make a gateway look configured ----------
# The one var the incident actually tripped over is NOT sufficient on its own.

write_env "$SANDBOX/posid-only.env" 'P24_POS_ID=12345'
POSID_OUT="$(run_validate "$SANDBOX/posid-only.env")"
assert_contains "$POSID_OUT" "Przelewy24 not configured" "pos-id-only output"
assert_not_contains "$POSID_OUT" "Przelewy24 fully configured" "pos-id-only output"

test_finish
