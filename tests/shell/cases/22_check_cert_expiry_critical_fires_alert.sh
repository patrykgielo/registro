#!/bin/bash
###############################################################################
# check-certificate-expiry.sh: below the CRITICAL threshold -- exit 2, a
# CRITICAL line, and (REGISTRO_CERT_ALERT_URL configured this time) the
# optional webhook is actually pinged. The URL itself never appears in the
# log line (it can carry a per-tenant secret token) -- only the fact that a
# ping was attempted/failed does.
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "check-certificate-expiry.sh: critical threshold fires the alert webhook"
sandbox_init

# 2 days left, WARN=30/CRIT=10 -- past both thresholds.
make_throwaway_cert 2 crit.local "$SANDBOX/cert.pem" "$SANDBOX/key.pem"
install_fake_openssl_for_s_client
export FAKE_OPENSSL_CERT_PATH="$SANDBOX/cert.pem"
fake_exe curl <<'EOS'
exit 0
EOS

LOG_PATH="$SANDBOX/cert-expiry.log"
OUT="$(REGISTRO_CERT_CHECK_SNI=crit.local \
    REGISTRO_CERT_EXPIRY_LOG="$LOG_PATH" \
    REGISTRO_CERT_WARN_DAYS=30 REGISTRO_CERT_CRIT_DAYS=10 \
    REGISTRO_CERT_ALERT_URL="https://example.test/hooks/secret-token" \
    bash "$SCRIPTS_DIR/check-certificate-expiry.sh" 2>&1)"
RC=$?

assert_eq "2" "$RC" "exit code"
assert_contains "$OUT" "CRITICAL" "stdout"
CURL_CALL="$(grep '^curl' "$CALL_LOG" || true)"
[ -n "$CURL_CALL" ] || fail "alert webhook was never pinged on a critical finding"
assert_contains "$CURL_CALL" "https://example.test/hooks/secret-token" "curl invocation carries the configured URL"
assert_not_contains "$OUT" "secret-token" "the alert URL itself must never be echoed into the log/stdout"

test_finish
