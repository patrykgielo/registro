#!/bin/bash
###############################################################################
# SessionStart hook (Windows desktop app only) -- keep the mirror clone current.
#
# Claude Code loads CLAUDE.md and .claude/rules/** from the Windows clone it was
# opened in. Real work happens in WSL and the clone is only ever a read-only
# mirror, so it silently falls behind: at the time this was written it was four
# rule files behind the WSL tree, and nothing said so.
#
# Runs NATIVELY in Git Bash (HOOK_FORWARD=0) because it must operate on the
# Windows clone with Windows git -- WSL git over /mnt/c reports false mode
# changes. It reaches into WSL only to read the work tree's committed
# .claude/ + CLAUDE.md for the drift comparison.
#
# What it does, in order, and what it refuses to do:
#   1. git fetch origin                          (read-only)
#   2. git merge --ff-only @{u}                  (never anything else; refuses
#      when the clone has local commits, uncommitted tracked changes, a detached
#      HEAD or no upstream -- and SAYS so). Untracked files, such as the clone's
#      .local-setup/, are never touched: ff-only aborts rather than overwrite one.
#   3. compare .claude/ + CLAUDE.md of the clone against the WSL tree's current
#      branch and name the files that differ.
#
# Silent when everything is in order. A problem is reported both to the user
# (systemMessage) and to Claude (additionalContext).
#
# Not a Windows session (native WSL/Linux)? Nothing to mirror: exit 0.
###############################################################################

set -uo pipefail

HOOK_FORWARD=0
# shellcheck source=lib/wsl-forward.sh
source "${BASH_SOURCE[0]%/*}/lib/wsl-forward.sh"

hook_on_windows || exit 0
HOOK_INPUT_READ=1 # stdin is not needed; do not leave the pipe unread
cat >/dev/null 2>&1 || true

clone="${BASH_SOURCE[0]%/.claude/hooks/*}"
[ -d "$clone/.git" ] || clone="${CLAUDE_PROJECT_DIR:-$clone}"

problems=()
notes=()
export GIT_TERMINAL_PROMPT=0
# Git Credential Manager ignores GIT_TERMINAL_PROMPT and opens its own dialog; a
# modal prompt at session start would hang the hook until its timeout.
export GCM_INTERACTIVE=never

g() { git -C "$clone" "$@"; }

# --- 1. fetch ----------------------------------------------------------------
if command -v timeout >/dev/null 2>&1; then
    timeout 25 git -C "$clone" fetch --quiet origin >/dev/null 2>&1
else
    git -C "$clone" fetch --quiet origin >/dev/null 2>&1
fi || problems+=("'git fetch origin' failed (offline, or no credentials?) -- the clone may be stale")

# --- 2. fast-forward ---------------------------------------------------------
branch="$(g symbolic-ref --short -q HEAD)" || branch=""
upstream="$(g rev-parse --abbrev-ref --symbolic-full-name '@{u}' 2>/dev/null)" || upstream=""
old_head="$(g rev-parse HEAD 2>/dev/null)" || old_head=""

if [ -z "$old_head" ]; then
    problems+=("$clone is not a readable git repository -- not synchronised")
elif [ -z "$branch" ]; then
    problems+=("the clone is on a detached HEAD -- not fast-forwarded")
elif [ -z "$upstream" ]; then
    problems+=("clone branch '$branch' has no upstream -- not fast-forwarded")
else
    counts="$(g rev-list --left-right --count "HEAD...$upstream" 2>/dev/null)" || counts=""
    ahead="${counts%%[[:space:]]*}"
    behind="${counts##*[[:space:]]}"
    if ! [[ "$ahead" =~ ^[0-9]+$ && "$behind" =~ ^[0-9]+$ ]]; then
        problems+=("could not compare the clone with $upstream -- not fast-forwarded")
    elif [ "$behind" -gt 0 ]; then
        if [ "$ahead" -gt 0 ]; then
            problems+=("the clone has $ahead local commit(s) and is $behind behind $upstream (diverged) -- NOT touched; a mirror should have no local commits")
        elif [ -n "$(g status --porcelain --untracked-files=no 2>/dev/null)" ]; then
            problems+=("the clone has uncommitted changes to tracked files and is $behind commit(s) behind $upstream -- NOT fast-forwarded")
        elif g merge --ff-only --quiet "$upstream" >/dev/null 2>&1; then
            new_head="$(g rev-parse HEAD)"
            changed="$(g diff --name-only "$old_head" "$new_head" -- .claude CLAUDE.md 2>/dev/null | head -8 | paste -sd, -)"
            if [ -n "$changed" ]; then
                notes+=("fast-forwarded the clone $behind commit(s) to $(g rev-parse --short HEAD); rules/CLAUDE.md changed ($changed). The copy loaded into THIS session is the old one -- /clear or restart to load the new")
            fi
        else
            problems+=("'git merge --ff-only $upstream' was refused -- the clone is $behind commit(s) behind and was NOT updated")
        fi
    elif [ "$ahead" -gt 0 ]; then
        problems+=("the clone has $ahead local commit(s) not on $upstream -- a mirror should have none")
    fi
fi

# --- 3. drift against the WSL tree's current branch ---------------------------
norm() { awk -F'\t' '{ split($1, a, " "); print $2 "\t" a[3] }' | sort; }

clone_ls="$(g ls-tree -r HEAD -- .claude CLAUDE.md 2>/dev/null | norm)"
if ! command -v wsl.exe >/dev/null 2>&1; then
    problems+=("wsl.exe not found -- drift against the WSL tree was NOT checked")
else
    tree_ls="$(
        export MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*'
        wsl.exe -d "$REGISTRO_WSL_DISTRO" -e git -c safe.directory='*' -C "$REGISTRO_WSL_TREE" ls-tree -r HEAD -- .claude CLAUDE.md 2>/dev/null | tr -d '\r' | norm
    )"
    tree_branch="$(
        export MSYS_NO_PATHCONV=1 MSYS2_ARG_CONV_EXCL='*'
        wsl.exe -d "$REGISTRO_WSL_DISTRO" -e git -c safe.directory='*' -C "$REGISTRO_WSL_TREE" symbolic-ref --short -q HEAD 2>/dev/null | tr -d '\r'
    )"
    if [ -z "$tree_ls" ]; then
        # An empty listing is "could not read", never "nothing differs".
        problems+=("could not read .claude/ from the WSL tree $REGISTRO_WSL_TREE (distro $REGISTRO_WSL_DISTRO) -- drift was NOT checked")
    elif [ "$tree_branch" != "$branch" ]; then
        # The WSL tree is on another branch (normally a feature branch): its
        # .claude/ legitimately differs, and warning about it every session start
        # would train everyone to ignore this hook. The clone is kept equal to
        # origin by the fast-forward above, which is what matters; the tree is
        # compared only when it is on the same branch as the clone.
        :
    elif [ "$clone_ls" != "$tree_ls" ]; then
        diffs="$(diff <(printf '%s\n' "$clone_ls") <(printf '%s\n' "$tree_ls") | grep '^[<>]' | cut -c3- | cut -f1 | sort -u | head -8 | paste -sd, -)"
        problems+=("the clone's .claude/ + CLAUDE.md differ from the WSL tree (${tree_branch:-detached}): ${diffs}. Claude is reading the CLONE's copy")
    fi
fi

# --- report ------------------------------------------------------------------
[ ${#problems[@]} -gt 0 ] || [ ${#notes[@]} -gt 0 ] || exit 0

msg="Windows clone sync (${clone}):"
for x in "${notes[@]+"${notes[@]}"}"; do [ -n "$x" ] && msg="${msg}"$'\n'"  - ${x}"; done
for x in "${problems[@]+"${problems[@]}"}"; do [ -n "$x" ] && msg="${msg}"$'\n'"  - ${x}"; done
esc="$(hook_json_escape "$msg")"
printf '{"systemMessage":"%s","hookSpecificOutput":{"hookEventName":"SessionStart","additionalContext":"%s"}}\n' "$esc" "$esc"
exit 0
