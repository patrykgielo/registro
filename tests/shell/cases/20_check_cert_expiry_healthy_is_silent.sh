#!/bin/bash
###############################################################################
# check-certificate-expiry.sh: a certificate with plenty of days left is a
# clean run -- exit 0, nothing written to the log, nothing on stdout, and no
# alert ping. Same silent-on-clean convention as tenant-check.sh (see that
# script's own header on why: cron's mail-on-output convention is not a safe
# assumption on this project's hosts, and a log that only ever grows on a
# problem is the one thing worth tailing).
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "check-certificate-expiry.sh: healthy certificate is a silent clean pass"
sandbox_init

make_throwaway_cert 400 healthy.local "$SANDBOX/cert.pem" "$SANDBOX/key.pem"
install_fake_openssl_for_s_client
export FAKE_OPENSSL_CERT_PATH="$SANDBOX/cert.pem"
fake_exe curl <<'EOS'
exit 0
EOS

LOG_PATH="$SANDBOX/cert-expiry.log"
OUT="$(REGISTRO_CERT_CHECK_SNI=healthy.local \
    REGISTRO_CERT_EXPIRY_LOG="$LOG_PATH" \
    REGISTRO_CERT_ALERT_URL="https://example.test/alert" \
    bash "$SCRIPTS_DIR/check-certificate-expiry.sh")"
RC=$?

assert_eq "0" "$RC" "exit code"
assert_eq "" "$OUT" "stdout on a clean run"
[ -f "$LOG_PATH" ] && fail "log file was written on a clean run: $(cat "$LOG_PATH")"
assert_not_contains "$(cat "$CALL_LOG")" "curl" "curl must not be invoked when there is nothing to report"

test_finish
