# shellcheck shell=bash
###############################################################################
# Which working tree does a Stop / SubagentStop gate inspect?
#
# These gates used to diff their own cwd. From the Windows app that cwd is the
# mirror clone, which never changes, so they inspected nothing and passed.
# The work happens in a WSL tree -- and not always the main one: this repo is
# worked in several git worktrees (`git worktree list`), each on its own branch.
#
# Native session (not forwarded): the tree is the repo containing the project
# directory, exactly as before.
# Forwarded from Windows: of the worktrees with uncommitted changes, the one
# whose changes are NEWEST. It is a heuristic, so the gates always say which
# tree they looked at -- a wrong pick must be visible, not silent.
###############################################################################

# registro_git TREE ARGS... -- git that does not trip on "dubious ownership"
# (a worktree on /mnt/c is owned by someone else as far as WSL can tell).
registro_git() {
    local tree="$1"
    shift
    git -c safe.directory='*' -C "$tree" "$@"
}

# registro_tree_changes TREE -- every changed path: tracked diff vs HEAD, staged,
# and untracked-but-not-ignored (a feature built from NEW files is still a
# change; `git diff HEAD` alone cannot see it).
registro_tree_changes() {
    local tree="$1"
    {
        registro_git "$tree" diff --name-only HEAD 2>/dev/null
        registro_git "$tree" diff --cached --name-only 2>/dev/null
        registro_git "$tree" ls-files --others --exclude-standard 2>/dev/null
    } | sort -u | sed '/^$/d'
}

# registro_candidate_trees -- one tree per line.
registro_candidate_trees() {
    if [ "${REGISTRO_HOOK_ORIGIN:-}" = windows ]; then
        registro_git "$REGISTRO_WSL_TREE" worktree list --porcelain 2>/dev/null | sed -n 's/^worktree //p'
    else
        registro_git "${CLAUDE_PROJECT_DIR:-.}" rev-parse --show-toplevel 2>/dev/null
    fi
}

# registro_active_tree -- sets REGISTRO_ACTIVE_TREE and REGISTRO_ACTIVE_CHANGES (its
# changed paths); non-zero when no tree has changes. Not a $(...) function on
# purpose: a subshell would swallow the second variable.
registro_active_tree() {
    local tree best="" best_mtime=-1 changes f m newest
    REGISTRO_ACTIVE_TREE=""
    REGISTRO_ACTIVE_CHANGES=""
    while IFS= read -r tree; do
        [ -n "$tree" ] && [ -d "$tree" ] || continue
        changes="$(registro_tree_changes "$tree")"
        [ -n "$changes" ] || continue
        newest=0
        while IFS= read -r f; do
            m="$(stat -c %Y "$tree/$f" 2>/dev/null || echo 0)"
            [ "$m" -gt "$newest" ] && newest="$m"
        done <<<"$changes"
        if [ "$newest" -gt "$best_mtime" ]; then
            best="$tree"
            best_mtime="$newest"
            REGISTRO_ACTIVE_CHANGES="$changes"
        fi
    done < <(registro_candidate_trees)
    [ -n "$best" ] || return 1
    REGISTRO_ACTIVE_TREE="$best"
}
