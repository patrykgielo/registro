#!/bin/bash
###############################################################################
# check-certificate-expiry.sh: a certificate below the WARNING threshold but
# still above CRITICAL -- exit 1, a WARNING line written to the log, and
# (since certbot's own renewal timer fires at 30 days, independent of this
# project's own 15-minute reconcile) the message explicitly says renewal may
# be broken rather than "days left" alone. No alert ping configured here on
# purpose -- proves the WARNING path works with the feature fully inert.
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "check-certificate-expiry.sh: warning threshold crossed"
sandbox_init

# 20 days left, WARN=30/CRIT=10 -- inside the warning band, not critical.
make_throwaway_cert 20 warn.local "$SANDBOX/cert.pem" "$SANDBOX/key.pem"
install_fake_openssl_for_s_client
export FAKE_OPENSSL_CERT_PATH="$SANDBOX/cert.pem"

LOG_PATH="$SANDBOX/cert-expiry.log"
OUT="$(REGISTRO_CERT_CHECK_SNI=warn.local \
    REGISTRO_CERT_EXPIRY_LOG="$LOG_PATH" \
    REGISTRO_CERT_WARN_DAYS=30 REGISTRO_CERT_CRIT_DAYS=10 \
    bash "$SCRIPTS_DIR/check-certificate-expiry.sh" 2>&1)"
RC=$?

assert_eq "1" "$RC" "exit code"
assert_contains "$OUT" "WARNING" "stdout"
assert_contains "$OUT" "renewal may be broken" "stdout explains WHY 30 days matters"
[ -f "$LOG_PATH" ] || fail "log file was not written on a warning finding"
assert_contains "$(cat "$LOG_PATH")" "WARNING" "durable log"

test_finish
