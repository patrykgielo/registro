#!/bin/bash
###############################################################################
# tests/shell/lib/harness.sh -- plain-bash test harness for scripts/server/*.sh
#
# WHY PLAIN BASH, NOT BATS: bats is not installed on the host or in the `app`
# container, and adding it would be a new package-manager dependency (npm/
# npx is off the table too). Nothing here needs bats' own features (TAP
# output, fixture files) -- a fake-executable-on-PATH + call-log + assert
# pattern is enough for a suite this size, and it stays plain, greppable
# shell that runs with nothing but bash itself. This is deliberately the
# simplest thing that gives clear pass/fail output and a non-zero exit code
# on failure, per this task's own constraint.
#
# WHY FAKE EXECUTABLES ON PATH, NOT A REAL DOCKER DAEMON: the scripts under
# test (apply.sh, tenant-backup.sh, sync-certificate.sh, deploy.sh,
# tenant-check.sh) are 1-1358 lines of operator tooling that call `docker`,
# `git`, `certbot`, `su`, `restic` dozens of times each. Reproducing every
# regression this suite pins requires controlling exactly what those
# commands report back -- a real daemon cannot be made to fail in the
# specific, narrow ways these regressions need (a corrupted .env making
# `docker compose ps` interpolate-fail; an image entrypoint refusing non-
# `laravel` UIDs; a certbot order). This is the exact pattern the project's
# own throwaway validation sandboxes already used successfully (see
# ci-cd-troubleshooting.md's "Faza 2/3" incidents) -- reused here rather than
# invented fresh, and made permanent instead of discarded after one PR.
#
# TWO WAYS A CASE EXERCISES THE REAL SOURCE:
#   1. Run the real script file end-to-end (or up to the point a fake
#      dependency makes it fail/succeed) -- used for sync-certificate.sh
#      (short, linear, no long-running loops) and for apply.sh's very first
#      steps (lock/log/state, before anything needs a real container).
#   2. Extract one function or block VERBATIM out of a script that cannot be
#      run standalone (`set -euo pipefail` plus argument parsing executes
#      immediately at the top of the file) via extract_between_exact/
#      extract_between_contains below, then source and call just that
#      fragment with fakes on PATH. This still tests the ACTUAL current text
#      of the file -- edit the real script and a case using extraction picks
#      up the change on its next run, with no copy to keep in sync.
###############################################################################

set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
SCRIPTS_DIR="$REPO_ROOT/scripts/server"

TEST_NAME=""
TEST_FAILURES=()

sandbox_init() {
    SANDBOX="$(mktemp -d "${TMPDIR:-/tmp}/registro-shelltest-XXXXXX")"
    FAKEBIN="$SANDBOX/fakebin"
    CALL_LOG="$SANDBOX/calls.log"
    mkdir -p "$FAKEBIN"
    : >"$CALL_LOG"
    export SANDBOX FAKEBIN CALL_LOG
    export PATH="$FAKEBIN:$PATH"
    trap 'rm -rf "$SANDBOX"' EXIT
}

# fake_exe NAME <<'EOS' ... EOS -- installs $FAKEBIN/NAME as an executable
# that (1) unconditionally records its own invocation to $CALL_LOG, one line
# per call, %q-quoted so an empty or whitespace-containing argument is
# visible instead of disappearing into the log, THEN (2) runs the heredoc
# body with "$@" intact. Every case can therefore assert on "was this called,
# with exactly which arguments" even when the body itself exits non-zero.
fake_exe() {
    local name="$1"
    local path="$FAKEBIN/$name"
    {
        printf '#!/bin/bash\n'
        printf 'printf %%s %q >>"$CALL_LOG"\n' "$name"
        printf 'for a in "$@"; do printf '"'"' %%q'"'"' "$a" >>"$CALL_LOG"; done\n'
        printf 'printf '"'"'\\n'"'"' >>"$CALL_LOG"\n'
        cat
    } >"$path"
    chmod +x "$path"
}

# Shared by every sync-certificate.sh case: the script hard-requires root
# (certbot writes /etc/letsencrypt) and shells out through `su - deploy` to
# read the app container as a non-root user. Neither needs to be real in the
# sandbox -- `su` here just drops the user-switch and runs the -c command
# inline, inheriting the already-faked PATH (docker etc.), so it does not
# recurse or need an actual `deploy` account to exist.
install_fake_id_root() {
    fake_exe id <<'EOS'
echo 0
EOS
}

install_fake_su() {
    fake_exe su <<'EOS'
while [ $# -gt 0 ]; do
    case "$1" in
        -c) shift; exec bash -c "$1" ;;
        *) shift ;;
    esac
done
EOS
}

test_start() { TEST_NAME="$1"; TEST_FAILURES=(); }

fail() { TEST_FAILURES+=("$1"); }

assert_eq() {
    local expected="$1" actual="$2" label="${3:-value}"
    [ "$expected" = "$actual" ] || fail "${label}: expected [${expected}], got [${actual}]"
}

assert_contains() {
    local haystack="$1" needle="$2" label="${3:-output}"
    case "$haystack" in
        *"$needle"*) ;;
        *) fail "${label}: expected to contain [${needle}], got:
${haystack}" ;;
    esac
}

assert_not_contains() {
    local haystack="$1" needle="$2" label="${3:-output}"
    case "$haystack" in
        *"$needle"*) fail "${label}: expected NOT to contain [${needle}], got:
${haystack}" ;;
    esac
}

test_finish() {
    if [ ${#TEST_FAILURES[@]} -eq 0 ]; then
        echo "PASS: $TEST_NAME"
        exit 0
    fi
    echo "FAIL: $TEST_NAME"
    local f
    for f in "${TEST_FAILURES[@]}"; do
        echo "  - $f"
    done
    exit 1
}

# extract_between_exact FILE START_LINE END_LINE -- prints the inclusive
# range between the first RAW line that equals START_LINE byte-for-byte
# (including leading whitespace) and the first RAW line AFTER it that equals
# END_LINE byte-for-byte. Used for function bodies, where the boundary is
# "the closing brace at THIS function's own indentation" -- an inner
# `|| { ...; }` compound command closes at a DEEPER indentation and must not
# be mistaken for the function's own end. Exact (not trimmed) matching is
# what makes that distinction possible.
extract_between_exact() {
    local file="$1" start="$2" end="$3"
    awk -v s="$start" -v e="$end" '
        $0 == s { f = 1 }
        f { print }
        f && $0 == e { exit }
    ' "$file"
}

# extract_between_contains FILE START_SUBSTR END_SUBSTR -- prints the
# inclusive range between the first line CONTAINING start_substr and the
# first line after it CONTAINING end_substr. For blocks whose boundary is
# not a bare brace but a line with unique, identifying text (a log message,
# a specific die() call) -- awk's index() is a literal substring search, not
# a regex, so parens/braces/$-signs in real shell source need no escaping.
extract_between_contains() {
    local file="$1" start="$2" end="$3"
    awk -v s="$start" -v e="$end" '
        index($0, s) { f = 1 }
        f { print }
        f && index($0, e) { exit }
    ' "$file"
}
