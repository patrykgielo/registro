#!/bin/bash
###############################################################################
# Pins: architecture-models.md, "Incydent 2026-08-22" -- a claim about a REMOTE
# installation's deployment model was produced by reading THIS machine's
# config. The whole point of architecture-facts.sh is that the remote half is
# never inferred, so the two properties worth pinning are:
#
#   1. With no UAT snapshot on disk, --hook must say NIEZNANY / NIE ZMIERZONY
#      and must NOT print a model verdict for UAT.
#   2. classify() must never call a blank-TENANT_SLUG installation "dedicated",
#      because that is the exact direction the incident got wrong.
#
# Property 1 is exercised through the real script with a sandboxed cache dir
# (so a developer's own cached snapshot cannot make this pass by accident) and
# with `docker` faked into being absent -- that drives the "container not
# running" branch, which must still refuse to guess at UAT.
#
# Property 2 extracts the REAL classify() out of the script rather than
# copying it, so editing the function and re-running this case is what proves
# red-then-green.
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "architecture-facts.sh: never reports a remote model it did not measure"
sandbox_init

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
SCRIPT="${REPO_ROOT}/scripts/architecture-facts.sh"

if [ ! -x "$SCRIPT" ]; then
    fail "scripts/architecture-facts.sh missing or not executable"
    test_finish
fi

# --- property 1: no snapshot => explicit UNKNOWN, never a verdict -----------

# `docker` absent drives measure_local()'s "container not running" branch.
fake_exe docker <<'EOS'
exit 1
EOS

# Point the script's cache at the sandbox so a real snapshot on this developer's
# machine cannot silently satisfy the assertion below.
SANDBOX_PROJECT="${SANDBOX}/proj"
mkdir -p "${SANDBOX_PROJECT}/scripts" "${SANDBOX_PROJECT}/.claude"
cp "$SCRIPT" "${SANDBOX_PROJECT}/scripts/architecture-facts.sh"
chmod +x "${SANDBOX_PROJECT}/scripts/architecture-facts.sh"

HOOK_OUT="$("${SANDBOX_PROJECT}/scripts/architecture-facts.sh" --hook 2>/dev/null || true)"

assert_contains "$HOOK_OUT" "NIEZNANY" \
    "--hook must mark an unmeasured remote as NIEZNANY"
assert_contains "$HOOK_OUT" "NIEZWERYFIKOWANE" \
    "--hook must state that production claims are unverified without a snapshot"

# The failure mode being pinned: presenting a model for UAT that was never
# measured. Neither verdict string may appear on a UAT line.
UAT_LINE="$(echo "$HOOK_OUT" | grep -i 'UAT' || true)"
assert_not_contains "$UAT_LINE" "STACK WSPÓŁDZIELONY" \
    "UAT line must not carry a shared-stack verdict without a measurement"
assert_not_contains "$UAT_LINE" "STACK DEDYKOWANY" \
    "UAT line must not carry a dedicated-stack verdict without a measurement"

# --- property 2: blank slug is never classified as dedicated ---------------

CLASSIFY_BODY="$(extract_between_contains "$SCRIPT" "classify-body-start" "classify-body-end")"
if [ -z "$CLASSIFY_BODY" ]; then
    fail "could not extract classify() from architecture-facts.sh"
    test_finish
fi

# printf, not "{ $BODY }": the extracted body ends in a comment line and
# command substitution strips the trailing newline, so a closing brace glued
# onto the same line lands INSIDE that comment and the function never closes.
eval "$(printf 'classify() {\n%s\n}\n' "$CLASSIFY_BODY")"

BLANK_VERDICT="$(classify "" "not-provisioned")"
assert_contains "$BLANK_VERDICT" "WSPÓŁDZIELONY" \
    "blank TENANT_SLUG + not-provisioned must classify as the shared stack"
assert_not_contains "$BLANK_VERDICT" "DEDYKOWANY" \
    "blank TENANT_SLUG must never classify as a dedicated tenant stack"

PINNED_VERDICT="$(classify "budowlana" "provisioned")"
assert_contains "$PINNED_VERDICT" "DEDYKOWANY" \
    "a set TENANT_SLUG must classify as the dedicated tenant stack"

# Blank slug but a database that claims singleton provisioning is a real
# inconsistency (apply.sh's own --assert guards it); it must be reported as
# such, never silently resolved to either model.
MIXED_VERDICT="$(classify "" "provisioned for \"budowlana\"")"
assert_contains "$MIXED_VERDICT" "NIESPÓJNY" \
    "blank slug with a provisioned database must be reported as inconsistent"

# --- property 3: no `compose exec` may eat the caller's stdin ---------------
#
# Found live against UAT 2026-08-22: measure_uat() pipes its body to ssh as a
# heredoc, so the remote shell's stdin IS the rest of the script. `docker
# compose exec -T` inherits and CONSUMES it, swallowing every following line --
# the session ended mid-read and the script then blamed a missing directory
# that existed. Same class as case 17.
#
# Tested as a PROPERTY with a negative control, not as a spelling: a fake
# `docker` that drains stdin runs under a real heredoc, and the assertion is
# whether a line AFTER the exec still executes.
fake_exe docker <<'EOS'
if [ "${1:-}" = "compose" ]; then
    for a in "$@"; do
        if [ "$a" = "exec" ]; then cat >/dev/null 2>&1; exit 0; fi
    done
fi
exit 0
EOS

PROBE_GOOD="$(bash <<'HEREDOC'
docker compose exec -T app php artisan tinker --execute 'x' </dev/null >/dev/null 2>&1
echo "MARKER_REACHED"
HEREDOC
)"
assert_contains "$PROBE_GOOD" "MARKER_REACHED" \
    "with </dev/null the line after a compose exec must still run under a heredoc"

PROBE_BAD="$(bash <<'HEREDOC'
docker compose exec -T app php artisan tinker --execute 'x' >/dev/null 2>&1
echo "MARKER_REACHED"
HEREDOC
)"
assert_not_contains "$PROBE_BAD" "MARKER_REACHED" \
    "negative control: without </dev/null the exec swallows the rest of the heredoc"

# Both call sites (local measure_local, remote measure_uat) must carry it.
EXEC_TOTAL="$(grep -c 'compose exec -T app php artisan tinker' "$SCRIPT" || true)"
# Counts the exact guarded shape at both tinker call sites. `grep -A3` was
# wrong here: it also swept up the neighbouring tenant-provisioned line, which
# carries its own redirect, so the count passed for the wrong reason.
EXEC_GUARDED="$(grep -c '</dev/null 2>/dev/null | tr' "$SCRIPT" || true)"
assert_eq "2" "$EXEC_TOTAL" \
    "expected exactly two tinker exec call sites (local + remote) in architecture-facts.sh"
assert_eq "2" "$EXEC_GUARDED" \
    "both tinker exec call sites must redirect stdin with </dev/null"

test_finish
