#!/bin/bash
###############################################################################
# check-certificate-expiry.sh: no REGISTRO_CERT_CHECK_SNI override and no
# CERT_DIR in the legacy .env -- refuses before ever touching the network,
# same "die() before doing anything, never guess" shape as sync-
# certificate.sh's own CERT_DIR precondition. Exit 3 ("could not determine"),
# same code as case 23's connection failure -- both are "I could not run the
# check", just at different points.
#
# Also asserts the alert webhook fires on THIS specific exit-3 sub-path, not
# just case 23's connection-failure one -- review found alert() is correctly
# called before all three exit-3 branches in the real script, but only one
# of them (case 23) had REGISTRO_CERT_ALERT_URL configured to prove it. "I
# could not determine" is exactly the state nobody notices on their own --
# it must never be quieter than a dated finding.
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "check-certificate-expiry.sh: no way to determine which hostname to check refuses, does not guess"
sandbox_init

mkdir -p "$SANDBOX/legacy"
# Deliberately no CERT_DIR line at all.
: >"$SANDBOX/legacy/.env"
fake_exe curl <<'EOS'
exit 0
EOS

LOG_PATH="$SANDBOX/cert-expiry.log"
OUT="$(REGISTRO_LEGACY_APP_DIR="$SANDBOX/legacy" \
    REGISTRO_CERT_EXPIRY_LOG="$LOG_PATH" \
    REGISTRO_CERT_ALERT_URL="https://example.test/alert" \
    bash "$SCRIPTS_DIR/check-certificate-expiry.sh" 2>&1)"
RC=$?

assert_eq "3" "$RC" "exit code"
assert_contains "$OUT" "could not determine which hostname to check" "stdout"
assert_contains "$(cat "$LOG_PATH")" "REGISTRO_CERT_CHECK_SNI" "log names the way to fix it"
CURL_CALL="$(grep '^curl' "$CALL_LOG" || true)"
[ -n "$CURL_CALL" ] || fail "alert webhook was never pinged on the missing-SNI-source exit-3 path"

test_finish
