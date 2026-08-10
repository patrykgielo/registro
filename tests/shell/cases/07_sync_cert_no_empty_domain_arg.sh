#!/bin/bash
###############################################################################
# Pins: ci-cd-troubleshooting.md, "DESIRED mogło teraz legalnie zaczynać się
# jako pusty string ... zostawiała JEDNĄ pustą linię w wyniku sort -u ...
# dawało to gołe `-d ` (pusty argument domeny) przekazane do certbota". Same
# scenario as case 06 (legacy contributes zero names, one dedicated stack
# merges into an initially-empty DESIRED) -- that merge is exactly where the
# blank-line guard (`sed '/^$/d'`) matters, so this reuses the setup and
# asserts a DIFFERENT thing: no `-d` in the final certbot call is empty.
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "sync-certificate.sh: no empty element ever reaches certbot's -d list"
sandbox_init
install_fake_id_root
install_fake_su

mkdir -p "$SANDBOX/legacy"
cat >"$SANDBOX/legacy/.env" <<'ENV'
CERT_DIR=example.com
MAIL_FROM_ADDRESS=admin@example.com
ENV

mkdir -p "$SANDBOX/stacks/beta"
: >"$SANDBOX/stacks/beta/docker-compose.prod.yml"
cat >"$SANDBOX/stacks/beta/.env" <<'ENV'
TENANT_HOSTS=beta.example.com
ENV

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
[ -n "$CERTONLY_CALL" ] || fail "certbot certonly was never invoked"
# fake_exe logs each argument %q-quoted -- an empty argument surviving the
# merge would show up as a literal "-d ''" (bash's own %q rendering of an
# empty string), never as a real hostname.
assert_not_contains "$CERTONLY_CALL" "-d ''" "certonly -d arguments (empty element)"
assert_contains "$CERTONLY_CALL" "-d beta.example.com" "certonly -d arguments"

test_finish
