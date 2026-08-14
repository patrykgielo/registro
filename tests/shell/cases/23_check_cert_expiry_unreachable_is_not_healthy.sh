#!/bin/bash
###############################################################################
# check-certificate-expiry.sh: the fail-safe branch -- an unreachable/empty
# connection ("nothing came back") is NOT the same as "certificate is fine",
# and must not be read as one. Distinct exit code (3) from both WARNING (1)
# and CRITICAL (2), and a distinct log message, so an operator (or the
# REGISTRO_CERT_ALERT_URL webhook) can tell "I could not tell" apart from
# "there is a real, dated problem" -- exactly the distinction this project's
# other scripts (sync-certificate.sh's own docker-inspect probe) already
# insist on.
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "check-certificate-expiry.sh: an unreadable certificate is 'could not tell', never silently healthy"
sandbox_init

# openssl s_client returns NOTHING (empty stdin/no connection) -- same shape
# a refused connection or a mid-handshake timeout produces.
install_fake_openssl_for_s_client
export FAKE_OPENSSL_CERT_PATH="$SANDBOX/does-not-exist.pem"
fake_exe curl <<'EOS'
exit 0
EOS

LOG_PATH="$SANDBOX/cert-expiry.log"
OUT="$(REGISTRO_CERT_CHECK_SNI=unreachable.local \
    REGISTRO_CERT_EXPIRY_LOG="$LOG_PATH" \
    REGISTRO_CERT_ALERT_URL="https://example.test/alert" \
    bash "$SCRIPTS_DIR/check-certificate-expiry.sh" 2>&1)"
RC=$?

assert_eq "3" "$RC" "exit code -- distinct from WARNING(1)/CRITICAL(2)"
assert_contains "$OUT" "could not read a certificate" "stdout names the failure mode"
assert_not_contains "$OUT" "expires in" "must never report a days-remaining figure it never actually read"
CURL_CALL="$(grep '^curl' "$CALL_LOG" || true)"
[ -n "$CURL_CALL" ] || fail "alert webhook was never pinged on a could-not-determine finding"

test_finish
