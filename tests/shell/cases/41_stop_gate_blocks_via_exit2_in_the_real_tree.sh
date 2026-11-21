#!/bin/bash
###############################################################################
# Pins: stop-gate.sh actually BLOCKS, and looks at the tree that changed.
#
# TWO BUGS, both found by running it:
#
#  1. It "blocked" with `exit 1` and its message on STDOUT. Per the Claude Code
#     hooks reference a Stop hook blocks only with exit 2 (stderr is fed back to
#     Claude); exit 1 is a non-blocking error. The documentation gate never once
#     stopped anything.
#  2. It diffed its own cwd. From the Windows app that is the mirror clone,
#     which never changes -- so even a correct exit code would have inspected
#     nothing. The work is in a WSL tree, and this repo is worked in several git
#     worktrees; the gate now picks the worktree with the newest uncommitted
#     changes and says which one it looked at. New (untracked) files count: a
#     feature built from new files was invisible to `git diff HEAD`.
#
# Also pinned: stop_hook_active=true never blocks (a condition that cannot
# resolve would otherwise loop), and "nothing to inspect" is announced rather
# than passed off as "nothing changed".
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "stop-gate.sh: exit 2 + stderr blocks; inspects the worktree that changed; no loops"

HOOK="$REPO_ROOT/.claude/hooks/stop-gate.sh"
[ -f "$HOOK" ] || fail "hook not found at $HOOK"
sandbox_init

R="$SANDBOX/main"
W="$SANDBOX/wt"
new_repo "$R" develop
git -C "$R" worktree add -q -b feature/w "$W" >/dev/null 2>&1

add_php() { # TREE COUNT [PREFIX] -- new, untracked php files
    local tree="$1" n="$2" prefix="${3:-F}" i
    mkdir -p "$tree/app"
    for i in $(seq 1 "$n"); do echo "<?php // $i" >"$tree/app/${prefix}${i}.php"; done
}

# run NATIVE|FORWARDED TREE_FOR_ENV STOPJSON
run() {
    local mode="$1" tree="$2" json="${3:-{\}}"
    if [ "$mode" = NATIVE ]; then
        printf '%s' "$json" | env -u REGISTRO_HOOK_ORIGIN -u REGISTRO_HOOK_ASSUME_WINDOWS \
            CLAUDE_PROJECT_DIR="$tree" bash "$HOOK" >"$SANDBOX/stdout" 2>"$SANDBOX/stderr"
    else
        printf '%s' "$json" | env -u REGISTRO_HOOK_ASSUME_WINDOWS REGISTRO_HOOK_ORIGIN=windows \
            REGISTRO_WSL_TREE="$tree" CLAUDE_PROJECT_DIR="$tree" bash "$HOOK" >"$SANDBOX/stdout" 2>"$SANDBOX/stderr"
    fi
    RC=$?
}

# --- 1. native: 5 NEW php files, no docs => BLOCK with exit 2 + stderr -------------
add_php "$R" 5
run NATIVE "$R"
assert_eq "2" "$RC" "5 new php files, 0 docs: exit 2 (the only code that blocks a Stop hook)"
assert_contains "$(cat "$SANDBOX/stderr")" "BLOCKED" "the reason is on stderr, where Claude Code reads it"
assert_contains "$(cat "$SANDBOX/stderr")" "tree inspected: $R" "the gate says which tree it looked at"
assert_eq "" "$(cat "$SANDBOX/stdout")" "nothing on stdout"

# --- 1b. TRACKED edits (the only kind the old gate could see) block with 2, not 1 --
T="$SANDBOX/tracked"
new_repo "$T" develop
add_php "$T" 5 T
git -C "$T" add -A && git -C "$T" commit -qm php >/dev/null
for f in "$T"/app/T*.php; do echo "<?php // edited" >"$f"; done
run NATIVE "$T"
assert_eq "2" "$RC" "5 modified TRACKED php files: exit 2, not the non-blocking exit 1 the old gate used"

# --- 2. a docs change lifts the block; 4 files never blocked ----------------------
mkdir -p "$R/.claude/rules" && echo "# rule" >"$R/.claude/rules/x.md"
run NATIVE "$R"
assert_eq "0" "$RC" "docs/rules updated: exit 0"
rm -f "$R/.claude/rules/x.md" "$R/app/F5.php"
run NATIVE "$R"
assert_eq "0" "$RC" "4 php files: below the threshold"
add_php "$R" 5

# --- 3. stop_hook_active: never block twice in a row ------------------------------
run NATIVE "$R" '{"stop_hook_active": true}'
assert_eq "0" "$RC" "stop_hook_active=true must not block again (loop guard)"
run NATIVE "$R" '{"stop_hook_active":false}'
assert_eq "2" "$RC" "stop_hook_active=false still blocks"

# --- 4. FORWARDED from Windows: the worktree that changed is the one inspected -----
rm -f "$R"/app/*.php
run FORWARDED "$R"
assert_eq "0" "$RC" "all trees clean: nothing to block"

add_php "$W" 5 W
run FORWARDED "$R"
assert_eq "2" "$RC" "the violation is in a worktree, not in the main tree"
assert_contains "$(cat "$SANDBOX/stderr")" "tree inspected: $W" "...and it is the worktree that is named"

# Both dirty; the NEWER changes win. Main tree (5 files, newer) vs worktree (older).
add_php "$R" 5 M
touch -d '2 hours ago' "$W"/app/W*.php
run FORWARDED "$R"
assert_contains "$(cat "$SANDBOX/stderr")" "tree inspected: $R" "newest uncommitted changes win (main tree)"
touch -d '3 hours ago' "$R"/app/M*.php
run FORWARDED "$R"
assert_contains "$(cat "$SANDBOX/stderr")" "tree inspected: $W" "newest uncommitted changes win (worktree)"

# --- 5. nothing to inspect AT ALL is announced, not read as "clean" ----------------
run FORWARDED "$SANDBOX/not-a-tree"
assert_eq "0" "$RC" "no tree: do not block"
assert_contains "$(cat "$SANDBOX/stdout")" "did NOT run" "...but say that the gate did not run"

rm -f /tmp/cc_cache_ts_*"$(basename "$SANDBOX")"* 2>/dev/null || true
test_finish
