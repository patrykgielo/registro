#!/bin/bash
###############################################################################
# Pins: session-sync.sh -- the SessionStart hook that keeps the Windows mirror
# clone current and reports when its .claude/ + CLAUDE.md differ from the WSL
# tree.
#
# THE BUG: Claude Code loads CLAUDE.md and .claude/rules/** from the Windows
# clone it was opened in. The clone is a read-only mirror and silently fell
# behind -- four rule files behind the WSL tree at the time this was written --
# so sessions ran on stale rules with nothing to say so.
#
# What is pinned, with REAL git (a bare "origin", a "clone", a "WSL tree") and
# only wsl.exe faked (drops `-d D -e`, runs the rest):
#   * a clean, current clone is SILENT;
#   * a clone behind origin is fast-forwarded -- and an untracked file in it
#     (the real clone has .local-setup/) survives;
#   * nothing destructive is ever attempted: dirty tracked files, local commits
#     (diverged), a missing upstream and an unreachable origin are each
#     REPORTED and leave the clone exactly as it was;
#   * drift in .claude/ is named file by file; an unreadable WSL tree is "could
#     not check", never "no drift";
#   * outside Windows it does nothing at all.
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "session-sync.sh: ff-only the mirror clone, never destructive, reports drift, silent when clean"

sandbox_init

fake_exe wsl.exe <<'EOS'
while [ $# -gt 0 ]; do
    case "$1" in
        -d) shift 2 ;;
        -e) shift; break ;;
        *) shift ;;
    esac
done
exec "$@"
EOS

ORIGIN="$SANDBOX/origin.git"
SEED="$SANDBOX/seed"
git init -q --bare -b develop "$ORIGIN"
new_repo "$SEED" develop
mkdir -p "$SEED/.claude/hooks" "$SEED/.claude/rules"
cp -r "$REPO_ROOT/.claude/hooks/lib" "$SEED/.claude/hooks/lib"
cp "$REPO_ROOT/.claude/hooks/session-sync.sh" "$SEED/.claude/hooks/"
echo "rule one" >"$SEED/.claude/rules/one.md"
echo "# CLAUDE" >"$SEED/CLAUDE.md"
echo "<?php" >"$SEED/code.php"
git -C "$SEED" add -A && git -C "$SEED" commit -qm seed >/dev/null
git -C "$SEED" remote add origin "$ORIGIN"
git -C "$SEED" push -q -u origin develop 2>/dev/null

fresh() { # NAME -- a clone + a "WSL tree" clone, both on develop, both current
    rm -rf "$SANDBOX/clone" "$SANDBOX/tree"
    git clone -q "$ORIGIN" "$SANDBOX/clone" 2>/dev/null
    git clone -q "$ORIGIN" "$SANDBOX/tree" 2>/dev/null
    git -C "$SANDBOX/clone" config user.email t@e.st
    git -C "$SANDBOX/clone" config user.name t
    git -C "$SANDBOX/tree" checkout -q develop 2>/dev/null
}
ADV=0
advance_origin() { # FILE CONTENT -- push a NEW commit to origin from the seed repo
    mkdir -p "$(dirname "$SEED/$1")"
    ADV=$((ADV + 1))
    echo "$2 #$ADV" >"$SEED/$1" # unique content: an unchanged file would commit nothing
    git -C "$SEED" add -A && git -C "$SEED" commit -qm "advance $1" >/dev/null
    git -C "$SEED" push -q origin develop 2>/dev/null
}
sync() { # [env assignments...] -> stdout in $OUT
    OUT="$(printf '%s' '{"source":"startup"}' | env -u REGISTRO_HOOK_ORIGIN REGISTRO_HOOK_ASSUME_WINDOWS=1 \
        REGISTRO_WSL_TREE="$SANDBOX/tree" "$@" bash "$SANDBOX/clone/.claude/hooks/session-sync.sh" 2>"$SANDBOX/stderr")"
    RC=$?
}
head_of() { git -C "$1" rev-parse HEAD; }

# --- 1. current clone, no drift: SILENT ---------------------------------------------
fresh
sync X=1
assert_eq "0" "$RC" "clean: exit 0"
assert_eq "" "$OUT" "clean and current: not a word of output"

# --- 1b. S2: every git the hook runs must be forbidden from prompting ------------------
# GIT_TERMINAL_PROMPT=0 does not stop Git Credential Manager, which opens its own
# modal dialog; at session start that hangs the hook until its timeout.
REAL_GIT="$(command -v git)"
export REAL_GIT
fake_exe git <<'EOS'
printf '%s|%s\n' "${GCM_INTERACTIVE:-}" "${GIT_TERMINAL_PROMPT:-}" >>"$SANDBOX/git-env.log"
exec "$REAL_GIT" "$@"
EOS
fresh
: >"$SANDBOX/git-env.log"
sync X=1
assert_eq "0" "$(grep -vc '^never|0$' "$SANDBOX/git-env.log" || true)" "no git call in the hook runs without GCM_INTERACTIVE=never and GIT_TERMINAL_PROMPT=0"
[ "$(wc -l <"$SANDBOX/git-env.log")" -gt 0 ] || fail "the hook ran no git at all -- the check above proved nothing"

# --- 2. clone behind origin: fast-forwarded; untracked files survive ------------------
fresh
mkdir -p "$SANDBOX/clone/.local-setup" && echo keep >"$SANDBOX/clone/.local-setup/notes.txt"
advance_origin ".claude/rules/two.md" "rule two"
sync X=1
assert_eq "$(git -C "$ORIGIN" rev-parse develop)" "$(head_of "$SANDBOX/clone")" "clone fast-forwarded to origin/develop"
assert_eq "keep" "$(cat "$SANDBOX/clone/.local-setup/notes.txt")" "untracked .local-setup/ survived"
assert_contains "$OUT" "fast-forwarded the clone 1 commit" "the ff is reported"
assert_contains "$OUT" ".claude/rules/two.md" "...with the rule file that changed"
assert_contains "$OUT" "/clear or restart" "...and that THIS session still holds the old copy"

# A code-only advance is silent (nothing the session loaded changed).
fresh
advance_origin "code.php" "<?php // v2"
sync X=1
assert_eq "$(git -C "$ORIGIN" rev-parse develop)" "$(head_of "$SANDBOX/clone")" "code-only advance still fast-forwards"
assert_eq "" "$OUT" "...and says nothing (rules and CLAUDE.md are unchanged)"

# --- 3. nothing destructive: refuse and REPORT, leave the clone as it was ------------
fresh
advance_origin ".claude/rules/two.md" "rule two"
echo "local edit" >>"$SANDBOX/clone/CLAUDE.md"
BEFORE="$(head_of "$SANDBOX/clone")"
sync X=1
assert_eq "$BEFORE" "$(head_of "$SANDBOX/clone")" "uncommitted tracked change: clone NOT moved"
assert_contains "$OUT" "uncommitted changes to tracked files" "refusal is reported"
assert_eq "local edit" "$(tail -1 "$SANDBOX/clone/CLAUDE.md")" "the local edit is intact"

fresh
advance_origin ".claude/rules/two.md" "rule two"
echo "x" >"$SANDBOX/clone/local.txt" && git -C "$SANDBOX/clone" add local.txt && git -C "$SANDBOX/clone" commit -qm local >/dev/null
BEFORE="$(head_of "$SANDBOX/clone")"
sync X=1
assert_eq "$BEFORE" "$(head_of "$SANDBOX/clone")" "diverged clone: NOT touched"
assert_contains "$OUT" "diverged" "divergence is reported"

fresh
git -C "$SANDBOX/clone" branch --unset-upstream
sync X=1
assert_contains "$OUT" "no upstream" "a clone with no upstream is reported, not assumed fine"

fresh
git -C "$SANDBOX/clone" remote set-url origin "$SANDBOX/does-not-exist.git"
sync X=1
assert_contains "$OUT" "git fetch origin' failed" "an unreachable origin is reported"
assert_eq "0" "$RC" "...without failing the session start"

# --- 4. drift against the WSL tree ------------------------------------------------------
fresh
echo "branch-only rule" >"$SANDBOX/tree/.claude/rules/branch-only.md"
git -C "$SANDBOX/tree" add -A && git -C "$SANDBOX/tree" -c user.email=t@e.st -c user.name=t commit -qm "tree rule" >/dev/null
sync X=1
assert_contains "$OUT" "differ from the WSL tree" "drift is reported"
assert_contains "$OUT" ".claude/rules/branch-only.md" "...naming the file"

# S3: the WSL tree is normally on a FEATURE branch whose .claude/ legitimately
# differs from the clone's develop. That is not drift; warning on it every session
# start would teach everyone to ignore this hook. Silent in the normal case.
fresh
git -C "$SANDBOX/tree" checkout -q -b feature/something
echo "feature-only rule" >"$SANDBOX/tree/.claude/rules/feature-only.md"
git -C "$SANDBOX/tree" add -A && git -C "$SANDBOX/tree" -c user.email=t@e.st -c user.name=t commit -qm "feature rule" >/dev/null
sync X=1
assert_eq "" "$OUT" "WSL tree on a feature branch with different .claude/: silent (not drift)"

# An unreadable tree is NOT "no drift".
fresh
sync REGISTRO_WSL_TREE="$SANDBOX/no-such-tree"
assert_contains "$OUT" "could not read" "an unreadable WSL tree is reported as unchecked"
assert_not_contains "$OUT" "differ from the WSL tree" "...not as drift"

# --- 5. not a Windows session: nothing happens --------------------------------------------
fresh
advance_origin ".claude/rules/two.md" "rule two"
BEFORE="$(head_of "$SANDBOX/clone")"
OUT="$(printf '{}' | env -u REGISTRO_HOOK_ORIGIN -u REGISTRO_HOOK_ASSUME_WINDOWS bash "$SANDBOX/clone/.claude/hooks/session-sync.sh" 2>&1)"
assert_eq "" "$OUT" "native session: silent"
assert_eq "$BEFORE" "$(head_of "$SANDBOX/clone")" "native session: clone untouched"

test_finish
