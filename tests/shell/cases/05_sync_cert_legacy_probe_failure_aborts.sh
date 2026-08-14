#!/bin/bash
###############################################################################
# Pins: ci-cd-troubleshooting.md, "sync-certificate.sh's sonda ... nie
# odróżniała 'nic tu nie ma' od 'nie potrafię sprawdzić'" -- a `docker
# inspect` failure for a reason OTHER than "no such object/container"
# (daemon unreachable, permission denied) must abort the WHOLE run before
# certbot is touched. Reading it as "legitimately nothing here" would
# silently strip every legacy hostname from the next renewal while the
# legacy stack keeps serving traffic on the OLD certificate.
#
# Runs the real sync-certificate.sh end-to-end (it is short and linear, no
# long-running loops) rather than extracting a fragment -- faithful to how
# this exact bug was found (a real, corrupted-.env reproduction), not an
# inspection of the source.
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "sync-certificate.sh: an unexplained legacy probe failure aborts before touching certbot"
sandbox_init
install_fake_id_root
install_fake_su

mkdir -p "$SANDBOX/legacy"
cat >"$SANDBOX/legacy/.env" <<'ENV'
CERT_DIR=example.com
MAIL_FROM_ADDRESS=admin@example.com
ENV

# `docker inspect` fails for a reason that is NOT "no such object" -- the
# daemon being unreachable, or a permission problem. This is the one
# ambiguous case the fix must never read as "nothing here".
fake_exe docker <<'EOS'
case "$1" in
    inspect)
        echo "Error response from daemon: permission denied while trying to connect to the Docker daemon socket" >&2
        exit 1
        ;;
esac
exit 0
EOS
fake_exe certbot <<'EOS'
exit 0
EOS

OUT="$(REGISTRO_LEGACY_APP_DIR="$SANDBOX/legacy" \
    REGISTRO_CERTIFICATE_LOG="$SANDBOX/cert.log" \
    REGISTRO_STACKS_ROOT="$SANDBOX/no-such-stacks-root" \
    bash "$SCRIPTS_DIR/sync-certificate.sh" 2>&1)"
RC=$?

assert_eq "1" "$RC" "sync-certificate.sh exit code"
assert_contains "$OUT" "could not determine whether the legacy stack is running" "error message"
assert_not_contains "$(cat "$CALL_LOG")" "certbot" "commands invoked -- certbot must never run"

test_finish
