#!/bin/bash
###############################################################################
# Pins: the hooks read their stdin JSON WITHOUT jq, and a missing parser is a
# LOUD failure, never "an empty command".
#
# THE BUG: every hook did `jq -r ...` on stdin. jq is installed on neither the
# Windows host nor the WSL distro. `jq: command not found` inside $(...) just
# leaves the variable empty, so pre-tool-use.sh saw tool_name="" command="" and
# fell through every rule to "allow" -- it blocked NOTHING, silently, and the
# only symptom was a line on stderr nobody reads. (The same shape as the
# firecrawl and session-start-context failures in ci-cd-troubleshooting.md.)
#
# Proven both ways: a fake `jq` on PATH exits 99 and must never be called, and
# with the parser removed a guarded command is DENIED instead of waved through.
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "hooks: stdin JSON parsed without jq; no parser => guarded commands denied, not allowed"

HOOK="$REPO_ROOT/.claude/hooks/pre-tool-use.sh"
[ -f "$HOOK" ] || fail "hook not found at $HOOK"

sandbox_init
# A jq that would poison the result if anything still used it.
fake_exe jq <<'EOS'
exit 99
EOS

FEAT="$SANDBOX/feat"
new_repo "$FEAT" feature/x

run() { # JSON -> stdout; env: extra assignments passed as "$@"
    local json="$1"
    shift
    printf '%s' "$json" | env -u REGISTRO_HOOK_ORIGIN -u REGISTRO_HOOK_ASSUME_WINDOWS "$@" bash "$HOOK" 2>"$SANDBOX/stderr"
}

# --- 1. A hard JSON payload: quotes, an apostrophe, a newline, non-ASCII, and a
# real heredoc commit message -- aimed at a feature tree. Must parse and ALLOW.
CMD="git -C $FEAT commit -m \"\$(cat <<'EOF'
it's a \"quoted\" ünïcode message (with parens)
EOF
)\""
OUT="$(run "$(pretool_json "$CMD" "$SANDBOX")" "X=1")"
assert_eq "pass" "$(decision_of "$OUT")" "hard JSON payload (quotes/newline/unicode) on a feature tree"

# --- 2. jq was never needed.
assert_not_contains "$(cat "$CALL_LOG")" "jq" "the fake jq must never be invoked"

# --- 3. A destructive command is denied by parsing the real field, not by luck.
OUT="$(run "$(pretool_json 'php artisan migrate:fresh' "$SANDBOX")" "X=1")"
assert_eq "deny" "$(decision_of "$OUT")" "migrate:fresh denied with jq absent"
assert_contains "$(reason_of "$OUT")" "migrate:fresh" "deny reason names the rule"

# --- 4. NO PARSER AT ALL (python hidden): a guarded command must be DENIED.
OUT="$(run "$(pretool_json "git -C $FEAT commit -m x" "$SANDBOX")" "REGISTRO_HOOK_PYTHONS=no-such-python")"
assert_eq "deny" "$(decision_of "$OUT")" "guarded command with no parser"
assert_contains "$(reason_of "$OUT")" "python3" "deny reason says what to install"

# --- 5. ...and an innocent command is allowed, but not SILENTLY.
OUT="$(run "$(pretool_json 'ls -la' "$SANDBOX")" "REGISTRO_HOOK_PYTHONS=no-such-python")"
assert_eq "pass" "$(decision_of "$OUT")" "innocent command with no parser"
assert_contains "$(cat "$SANDBOX/stderr")" "python3" "the missing parser is announced on stderr"

# --- 6. Input that is not JSON is NOT read as an empty command either.
OUT="$(run 'this is { not json: git commit' "X=1")"
assert_eq "deny" "$(decision_of "$OUT")" "non-JSON input mentioning git commit"
OUT="$(run 'garbage' "X=1")"
assert_eq "pass" "$(decision_of "$OUT")" "non-JSON input that mentions nothing guarded"
assert_contains "$(cat "$SANDBOX/stderr")" "could not read" "garbage input announced on stderr"

# --- 7. The other hooks parse without jq too: prompt-submit must not warn about
# a missing parser when python3 is there, and must still produce its reminder.
OUT="$(printf '%s' '{"prompt":"zaimplementuj nowa funkcje rezerwacji dla tenantow"}' \
    | env -u REGISTRO_HOOK_ORIGIN -u REGISTRO_HOOK_ASSUME_WINDOWS CLAUDE_PROJECT_DIR="$SANDBOX" \
        bash "$REPO_ROOT/.claude/hooks/prompt-submit.sh" 2>"$SANDBOX/stderr")"
assert_contains "$OUT" "MANDATORY PROCESS" "prompt-submit still injects the reminder (jq absent)"
assert_not_contains "$(cat "$SANDBOX/stderr")" "no JSON parser" "prompt-submit found a parser"

test_finish
