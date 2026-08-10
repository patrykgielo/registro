#!/bin/bash
###############################################################################
# Pins: edge-stack.md "Known gap, fixed" / ci-cd-troubleshooting.md's
# "4 poprawki blokujące" -- a dedicated tenant stack is read from its OWN
# .env on disk (TENANT_HOSTS), never from the live container. Before this
# fix, a stack whose container happened to be stopped between sessions (a
# normal state once UAT started hosting prospect projects) froze
# certificate renewal for every OTHER tenant on the box.
#
# Deliberately never fakes a `tenant-acme-*` container at all -- the whole
# point of the fix is that this stack's hostnames must be readable with ZERO
# docker calls for it, proving no dependency on container state exists.
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "sync-certificate.sh: a dedicated tenant stack contributes its hosts from .env alone"
sandbox_init
install_fake_id_root
install_fake_su

mkdir -p "$SANDBOX/legacy"
cat >"$SANDBOX/legacy/.env" <<'ENV'
CERT_DIR=example.com
MAIL_FROM_ADDRESS=admin@example.com
ENV

mkdir -p "$SANDBOX/stacks/acme"
: >"$SANDBOX/stacks/acme/docker-compose.prod.yml"
cat >"$SANDBOX/stacks/acme/.env" <<'ENV'
TENANT_HOSTS=acme.example.com
ENV

# Legacy container never existed on this box -- a legal "zero legacy
# hostnames" case, not the failure this test is about (see case 05 for that
# one). `exec` (nginx -t / -s reload after a successful reissue) always
# succeeds.
fake_exe docker <<'EOS'
case "$1" in
    inspect) echo "Error: No such object: registro-app" >&2; exit 1 ;;
    exec) exit 0 ;;
esac
exit 0
EOS
fake_exe certbot <<'EOS'
case "$1" in
    certificates) exit 0 ;;
    certonly) exit 0 ;;
esac
exit 0
EOS

OUT="$(REGISTRO_LEGACY_APP_DIR="$SANDBOX/legacy" \
    REGISTRO_CERTIFICATE_LOG="$SANDBOX/cert.log" \
    REGISTRO_STACKS_ROOT="$SANDBOX/stacks" \
    bash "$SCRIPTS_DIR/sync-certificate.sh" 2>&1)"
RC=$?

assert_eq "0" "$RC" "sync-certificate.sh exit code"
CERTONLY_CALL="$(grep '^certbot certonly' "$CALL_LOG" || true)"
assert_contains "$CERTONLY_CALL" "acme.example.com" "certbot certonly -d arguments"

test_finish
