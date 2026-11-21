#!/bin/bash
###############################################################################
# Pins the three ways the PreToolUse guard used to fail OPEN while claiming to
# fail closed (code review W1, W2, W3).
#
# W1  A hook that dies halfway (`set -e` / `set -u` abort) exits 1, and Claude
#     Code treats exit 1 as a NON-blocking error: the command runs unguarded.
#     The shim only caught "rc 0 and empty output". Now a guard counts as having
#     answered only with rc 0 + JSON (or rc 2); and the script itself traps its
#     own exit so a native Linux session, which has no shim, is covered too.
# W2  Claude Code kills a hook at its own timeout (20 s) and runs the command with
#     no decision. wsl.exe is now bounded below that; rc 124 => deny.
# W3  When the command could not be parsed (or runs code built at run time), the
#     fallback deny required the literal text "git commit" -- which
#         git -C <develop> commit -m "$(echo hi # it's
#         )"
#     does not contain. It now fires on the WHOLE WORDS commit / push / pr create,
#     while a quoted mention that parses cleanly still passes.
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "pre-tool-use.sh: crash, timeout and unreadable commands fail CLOSED, plain mentions still pass"

sandbox_init
DEV="$SANDBOX/trees/dev"; new_repo "$DEV" develop
FEAT="$SANDBOX/trees/feat"; new_repo "$FEAT" feature/x
TREE="$SANDBOX/wsltree"; mkdir -p "$TREE"

# A project copy whose lib we can sabotage, with the REAL hook and shim.
mkproj() { # NAME -> $SANDBOX/NAME
    local p="$SANDBOX/$1"
    rm -rf "$p"
    mkdir -p "$p/.claude/hooks"
    cp -r "$REPO_ROOT/.claude/hooks/lib" "$p/.claude/hooks/lib"
    cp "$REPO_ROOT/.claude/hooks/pre-tool-use.sh" "$p/.claude/hooks/"
}

fake_exe wsl.exe <<'EOS'
while [ $# -gt 0 ]; do
    case "$1" in
        -d) shift 2 ;;
        -e) shift; break ;;
        *) shift ;;
    esac
done
case "${FAKE_WSL_MODE:-run}" in
    die)     cat >/dev/null; exit 1 ;;                       # hook aborted: rc 1, no output
    garbage) cat >/dev/null; echo "not json at all"; exit 0 ;;
    sleep)   exec sleep 30 ;;                                # wsl.exe hangs
esac
exec "$@"
EOS

via_shim() { # PROJ JSON [env...] -> RAW, RC   (pretends to be Windows; wsl.exe is the fake)
    local proj="$1" json="$2"
    shift 2
    RAW="$(printf '%s' "$json" | env REGISTRO_HOOK_ASSUME_WINDOWS=1 REGISTRO_WSL_TREE="$TREE" "$@" bash "$proj/.claude/hooks/pre-tool-use.sh" 2>"$SANDBOX/stderr")"
    RC=$?
}
GUARDED="$(pretool_json "git -C $FEAT commit -m x" "$SANDBOX")"

# =========================== W1 ==============================================
mkproj p1
via_shim "$SANDBOX/p1" "$GUARDED" FAKE_WSL_MODE=die
assert_eq "deny" "$(decision_of "$RAW")" "W1: forwarded hook died with rc 1 and no output => deny, not a non-blocking error"
assert_eq "0" "$RC" "W1: ...answered with exit 0 so the deny is actually read"

via_shim "$SANDBOX/p1" "$GUARDED" FAKE_WSL_MODE=garbage
assert_eq "deny" "$(decision_of "$RAW")" "W1: rc 0 with a non-JSON answer => deny"

via_shim "$SANDBOX/p1" "$(pretool_json 'ls -la' "$SANDBOX")" FAKE_WSL_MODE=die
assert_eq "pass" "$(decision_of "$RAW")" "W1: an unguarded command never reaches WSL, so a dead WSL does not block it"

# Native session (no shim): sabotage the resolver so the script itself aborts.
mkproj p2
cat >"$SANDBOX/p2/.claude/hooks/lib/pretool_target.py" <<'EOS'
#!/usr/bin/env python3
print("#REGISTRO_RESOLVER_OK")
print("INV_KIND=(")
EOS
RAW="$(printf '%s' "$GUARDED" | env -u REGISTRO_HOOK_ORIGIN -u REGISTRO_HOOK_ASSUME_WINDOWS bash "$SANDBOX/p2/.claude/hooks/pre-tool-use.sh" 2>/dev/null)"
RC=$?
assert_eq "deny" "$(decision_of "$RAW")" "W1: native session, hook crashes mid-run on a guarded command => deny (exit trap)"
assert_eq "0" "$RC" "W1: ...and exits 0 so Claude Code reads it"
assert_contains "$(reason_of "$RAW")" "crashed" "W1: ...saying it crashed"

RAW="$(printf '%s' "$(pretool_json 'git status' "$SANDBOX")" | env -u REGISTRO_HOOK_ORIGIN -u REGISTRO_HOOK_ASSUME_WINDOWS bash "$SANDBOX/p2/.claude/hooks/pre-tool-use.sh" 2>/dev/null)"
assert_eq "pass" "$(decision_of "$RAW")" "W1: a crash on a call that mentions nothing guarded passes (loudly on stderr), it does not brick the session"

# Through the shim AND the real boot script: the forwarded process crashes.
via_shim "$SANDBOX/p2" "$GUARDED"
assert_eq "deny" "$(decision_of "$RAW")" "W1: forwarded, real boot, hook crashes inside => deny"

# =========================== W2 ==============================================
mkproj p3
START=$SECONDS
via_shim "$SANDBOX/p3" "$GUARDED" FAKE_WSL_MODE=sleep REGISTRO_WSL_TIMEOUT=1
ELAPSED=$((SECONDS - START))
assert_eq "deny" "$(decision_of "$RAW")" "W2: wsl.exe hangs => deny"
assert_contains "$(reason_of "$RAW")" "rc=124" "W2: ...recognised as a timeout (rc 124)"
[ "$ELAPSED" -le 8 ] || fail "W2: the guard answered after ${ELAPSED}s -- it must beat Claude Code's own 20 s kill, not wait for it"

# =========================== W3 ==============================================
verdict() { # COMMAND CWD -> VERDICT/REASON
    RAW="$(cd "$DEV" && pretool_json "$1" "$2" | env -u REGISTRO_HOOK_ORIGIN -u REGISTRO_HOOK_ASSUME_WINDOWS bash "$REPO_ROOT/.claude/hooks/pre-tool-use.sh" 2>/dev/null)"
    REASON="$(reason_of "$RAW")"
    VERDICT="$(decision_of "$RAW")"
}
NL=$'\n'

verdict "git -C $DEV commit -m \"\$(echo hi # it's${NL})\"" "$FEAT"
assert_eq "deny" "$VERDICT" "W3: the review's repro -- unparseable git -C <develop> commit"
assert_contains "$REASON" "cannot determine" "W3: ...refused because the target is unreadable, with the form to use"

verdict "git -C $DEV push origin \"\$(echo x # it's${NL})\"" "$FEAT"
assert_eq "deny" "$VERDICT" "W3: unparseable git push"

verdict "gh pr create --base main --title \"\$(echo x # it's${NL})\"" "$FEAT"
assert_eq "deny" "$VERDICT" "W3: unparseable gh pr create"

verdict "echo 'git status # it's" "$FEAT"
assert_eq "pass" "$VERDICT" "W3: an unparseable command that mentions git but not commit/push/pr create passes"

verdict 'grep -rn "git commit" docs .claude/rules' "$DEV"
assert_eq "pass" "$VERDICT" "W3: a QUOTED mention that parses cleanly still passes"

verdict 'git log --grep=commit --oneline' "$DEV"
assert_eq "pass" "$VERDICT" "W3: git log --grep=commit is a real, parsed, non-commit invocation"

test_finish
