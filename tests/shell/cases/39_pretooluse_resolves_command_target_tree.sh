#!/bin/bash
###############################################################################
# Pins: pre-tool-use.sh judges the branch of the tree the COMMAND targets, not
# the branch of the hook's own cwd.
#
# THE BUG (found by reading the wiring before it was ever switched on): the
# hook ran `git branch --show-current` in its own cwd. Claude Code on the
# Windows desktop app runs hooks with cwd = a read-only mirror clone that is
# permanently on `develop`. Wired as it was, the hook would have denied EVERY
# `git commit` -- the command text contains "git commit" and the cwd branch is
# always develop -- including commits aimed at a feature branch in WSL. And if
# it had instead guessed "feature", it would have waved through a commit to a
# protected branch.
#
# The fix resolves the target from the command (`cd X &&`, `wsl --cd X --`,
# `git -C X`, nested `bash -c`, subshells, heredocs), falls back to the session
# cwd for a bare `git commit`, and when the target CANNOT be determined it
# denies with the spelled-out form to use -- never a guess in either direction.
#
# Runs the REAL hook end to end; the `wsl` commands are only parsed, never run.
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "pre-tool-use.sh: branch is resolved from the command's target tree, undeterminable => deny"

HOOK="$REPO_ROOT/.claude/hooks/pre-tool-use.sh"
[ -f "$HOOK" ] || fail "hook not found at $HOOK"

sandbox_init
DEV="$SANDBOX/trees/dev";   new_repo "$DEV" develop
FEAT="$SANDBOX/trees/feat"; new_repo "$FEAT" feature/x
STG="$SANDBOX/trees/stg";   new_repo "$STG" staging
MAIN="$SANDBOX/trees/main"; new_repo "$MAIN" main
HOT="$SANDBOX/trees/hot";   new_repo "$HOT" hotfix/y
DET="$SANDBOX/trees/det";   new_repo "$DET" feature/d
git -C "$DET" checkout -q --detach

export WSL_DISTRO_NAME="Ubuntu-24.04"

REASON=""
RAW=""
VERDICT=""
verdict() { # COMMAND CWD -> sets VERDICT (pass|deny) and REASON. Never call via $(...): a subshell would lose REASON.
    local out
    # The PROCESS cwd is the develop tree -- exactly what the Windows mirror clone
    # is to a hook -- while the JSON `cwd` and the command say where the work is.
    out="$(cd "$DEV" && pretool_json "$1" "$2" | env -u REGISTRO_HOOK_ORIGIN -u REGISTRO_HOOK_ASSUME_WINDOWS bash "$HOOK" 2>/dev/null)"
    RAW="$out"
    REASON="$(reason_of "$out")"
    VERDICT="$(decision_of "$out")"
}
expect() { # LABEL WANT COMMAND CWD
    verdict "$3" "$4"
    assert_eq "$2" "$VERDICT" "$1"
}

# --- The defect itself: session cwd is develop, command targets a feature tree.
expect "cwd=develop, wsl -e bash -c 'cd FEAT && git commit' (the defect)" pass \
    "wsl -e bash -c 'cd $FEAT && git commit -m x'" "$DEV"
expect "cwd=develop, wsl.exe -d D --cd FEAT -- git commit" pass \
    "wsl.exe -d Ubuntu-24.04 --cd $FEAT -- git commit -m x" "$DEV"
expect "cwd=develop, git -C FEAT commit" pass "git -C $FEAT commit -m x" "$DEV"
expect "cwd=develop, cd FEAT && git commit" pass "cd $FEAT && git add -A && git commit -m x" "$DEV"
expect "cwd=develop, nested bash -lc" pass \
    "wsl.exe -d Ubuntu-24.04 -e bash -lc \"cd $FEAT && git commit -m x\"" "$DEV"

# --- ...and the opposite direction: cwd is a feature tree, target is protected.
expect "cwd=feature, wsl cd DEV && git commit" deny "wsl -e bash -c 'cd $DEV && git commit -m x'" "$FEAT"
expect "cwd=feature, git -C STG commit" deny "git -C $STG commit -m x" "$FEAT"
expect "cwd=feature, git -C MAIN commit" deny "git -C $MAIN commit -m x" "$FEAT"
expect "chain FEAT then DEV: the second commit is judged too" deny \
    "cd $FEAT && git commit -m a && cd $DEV && git commit -m b" "$FEAT"
expect "subshell cd does not leak out" pass "(cd $DEV && git status); git commit -m x" "$FEAT"

# --- A bare `git commit` targets the session cwd.
expect "bare git commit, cwd=develop" deny "git commit -m x" "$DEV"
expect "bare git commit, cwd=feature" pass "git commit -m x" "$FEAT"
expect "bare git commit, cwd=staging" deny "git commit -m x" "$STG"
expect "bare git commit, cwd=main" deny "git commit -m x" "$MAIN"
expect "detached HEAD is not a protected branch" pass "git commit -m x" "$DET"

# --- Realistic messages: heredoc with an apostrophe and parens, and a mention.
MSG="git -C $FEAT commit -m \"\$(cat <<'EOF'
fix: don't break (parens) -- see 'git commit' docs
EOF
)\""
expect "heredoc commit message with apostrophe/parens" pass "$MSG" "$DEV"
expect "mention only: grep \"git commit\"" pass 'grep -rn "git commit" docs .claude/rules' "$DEV"
expect "heredoc DATA mentioning git commit is not a commit" pass "cat > notes.txt <<'EOF'
remember to git commit
EOF" "$DEV"

# --- Undeterminable target: DENY, and say which form to use.
expect "cd \"\$(git rev-parse --show-toplevel)\" && git commit" deny \
    'cd "$(git rev-parse --show-toplevel)" && git commit -m x' "$FEAT"
assert_contains "$REASON" "git -C" "the deny reason spells out the form to use"
expect "git -C \"\$UNSET\" commit" deny 'git -C "$UNSET" commit -m x' "$FEAT"
expect "wsl into another distro: its paths are not ours" deny \
    "wsl -d Debian --cd $FEAT -- git commit -m x" "$FEAT"
expect "echo ... | bash hides the command" deny 'echo "git commit -m x" | bash' "$FEAT"
expect "unparseable command that mentions git commit" deny "echo 'unterminated; git commit" "$FEAT"
expect "a Windows cwd that is not a work tree" deny "git commit -m x" 'C:\nowhere\clone'
assert_contains "$REASON" "/mnt/c/nowhere/clone" "Windows cwd is translated to its /mnt path in the reason"

# --- Variables assigned in the same command ARE followed.
expect "var assigned then used with git -C" pass "t=$FEAT; git -C \$t commit -m x" "$DEV"

# --- Push to main (rule 2).
expect "push main from hotfix tree" pass "git -C $HOT push origin main" "$DEV"
expect "push main from feature tree" deny "git -C $FEAT push origin main" "$HOT"
expect "push HEAD:main from feature tree" deny "git -C $FEAT push origin HEAD:main" "$HOT"
expect "push a feature branch" pass "git -C $FEAT push origin feature/x" "$DEV"

# --- gh pr create (rule 5): the branch is the target tree's, not the cwd's.
expect "feature tree, --base develop" pass "cd $FEAT && gh pr create --base develop --title x" "$DEV"
expect "feature tree, --base main" deny "cd $FEAT && gh pr create --base main --title x" "$DEV"
expect "staging tree, --base main (cwd=develop)" pass \
    "wsl -e bash -c 'cd $STG && gh pr create --base main --title x'" "$DEV"
expect "staging tree, --base develop" deny "cd $STG && gh pr create --base develop --title x" "$FEAT"

# --- Branch-independent rules are untouched.
expect "migrate:fresh" deny "wsl -e bash -c 'cd $FEAT && php artisan migrate:fresh'" "$FEAT"
expect "migrate:refresh" deny "php artisan migrate:refresh" "$FEAT"
expect "db:wipe" deny "php artisan db:wipe" "$FEAT"
expect "FILESYSTEM_DISK=local" deny "echo FILESYSTEM_DISK=local >> .env" "$FEAT"
expect "FILESYSTEM_DISK=public" pass "echo FILESYSTEM_DISK=public >> .env" "$FEAT"
expect "unrelated command" pass "ls -la" "$DEV"

# REGRESSION GUARD: a passing command must carry NO permissionDecision key at all.
# `permissionDecision: allow` skips the user's permission prompt for every call that
# clears the rules -- the hook would silently escalate permissions. A guard denies;
# everything else falls through to the normal permission flow. (Empty output is not
# the answer either: the shim reads empty stdout as "the hook never ran".)
assert_not_contains "$RAW" "permissionDecision" "a passing command must not carry any permissionDecision"
assert_not_contains "$RAW" "allow" "a passing command must never say allow"
assert_eq '{"suppressOutput":true}' "$RAW" "a pass is valid JSON with no decision"
verdict "git -C $FEAT commit -m x" "$DEV"
assert_not_contains "$RAW" "permissionDecision" "a pass that went through the branch resolver carries no permissionDecision"

# --- Non-Bash tools are not this hook's business.
OUT="$(printf '%s' '{"tool_name":"Write","tool_input":{"file_path":"/x","content":"git commit"}}' \
    | env -u REGISTRO_HOOK_ORIGIN bash "$HOOK" 2>/dev/null)"
assert_eq "pass" "$(decision_of "$OUT")" "non-Bash tool"
assert_not_contains "$OUT" "permissionDecision" "non-Bash tool: no permissionDecision either"

test_finish
