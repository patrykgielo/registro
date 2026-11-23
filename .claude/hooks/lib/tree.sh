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
# (a worktree on /mnt/c is owned by someone else as far as WSL can tell), and
# that prints paths verbatim. core.quotePath=false matters: by default git wraps
# a path containing non-ASCII bytes in double quotes and octal-escapes it, so it
# misses every `^app/...` anchor, and a PHP file whose name then ends in `.php"`
# never matches `\.php$` -- a changed file the gate cannot see.
registro_git() {
    local tree="$1"
    shift
    git -c safe.directory='*' -c core.quotePath=false -C "$tree" "$@"
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

# registro_tree_scope_changes TREE -- the paths used to pick WHICH tests run:
# everything registro_tree_changes reports PLUS what this branch has already
# committed since it left origin/develop. Without the second part, an agent that
# commits its work before stopping (or a branch with earlier commits) would look
# like "no product-form change" and get the fast scope. Falls back silently to
# the plain working-tree set when origin/develop does not resolve (no remote, or
# never fetched): a narrower scope is the old behaviour, not an error.
# NOT used for "is there anything to check" / "did any PHP change" -- those stay
# on the working-tree set, so a clean tree on a branch with commits still skips.
registro_tree_scope_changes() {
    local tree="$1" base
    {
        registro_tree_changes "$tree"
        if base="$(registro_git "$tree" merge-base origin/develop HEAD 2>/dev/null)" && [ -n "$base" ]; then
            registro_git "$tree" diff --name-only "$base"..HEAD 2>/dev/null
        fi
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

# registro_active_tree -- sets REGISTRO_ACTIVE_TREE, REGISTRO_ACTIVE_CHANGES (its
# changed paths) and REGISTRO_ACTIVE_SCOPE_CHANGES (those plus the branch's
# commits, see registro_tree_scope_changes); non-zero when no tree has changes.
# Not a $(...) function on purpose: a subshell would swallow the variables.
registro_active_tree() {
    local tree best="" best_mtime=-1 changes f m newest
    REGISTRO_ACTIVE_TREE=""
    REGISTRO_ACTIVE_CHANGES=""
    REGISTRO_ACTIVE_SCOPE_CHANGES=""
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
    REGISTRO_ACTIVE_SCOPE_CHANGES="$(registro_tree_scope_changes "$best")"
}
