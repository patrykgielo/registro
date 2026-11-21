#!/bin/bash
# Stop Hook - Enforce documentation/rules update after code changes
#
# When Claude finishes a task, this hook checks if significant code was changed
# without any corresponding documentation/rules updates. If so, it blocks
# completion and forces documentation work.
#
# This is DETERMINISTIC enforcement — not advisory text that can be ignored.
#
# Runs inside WSL even when Claude Code runs from Windows (lib/wsl-forward.sh),
# and looks at the WSL tree that was actually changed (lib/tree.sh) -- not at
# the Windows mirror clone, which never changes.
#
# Exit 2 + stderr is the ONLY way a Stop hook blocks (Claude Code docs): exit 1
# is a non-blocking error, and stdout of a blocking hook is not shown to Claude.
# This script exited 1 with its message on stdout, which is to say it never
# blocked anything.

set -uo pipefail

_HOOKS="${BASH_SOURCE[0]%/*}"
# shellcheck source=lib/wsl-forward.sh
source "$_HOOKS/lib/wsl-forward.sh"
registro_wsl_forward "${BASH_SOURCE[0]}" "$@"
# shellcheck source=lib/tree.sh
source "$_HOOKS/lib/tree.sh"

hook_read_input

# Record last-response timestamp for cache expiry detection in prompt-submit hook
# Cache TTL = 5 min; after that the next turn pays 10x (full context rebuild)
_CC_TS_FILE="/tmp/cc_cache_ts_$(echo "${CLAUDE_PROJECT_DIR:-/}" | tr '/' '_' | tr -s '_')"
date +%s > "$_CC_TS_FILE" 2>/dev/null || true

# We are already continuing because of a stop hook: do not block again, or a
# condition that never resolves loops forever. Plain regex on the raw json, so
# this works with no JSON parser at all.
if [[ "$HOOK_INPUT" =~ \"stop_hook_active\"[[:space:]]*:[[:space:]]*true ]]; then
    exit 0
fi

count_files() {
    local pattern="$1"
    local count
    count=$(echo "$2" | grep -cE "$pattern" 2>/dev/null || true)
    echo "${count:-0}"
}

if ! registro_active_tree; then
    if [ -z "$(registro_candidate_trees)" ]; then
        # Nothing to inspect AT ALL is different from "nothing changed".
        printf '{"systemMessage":"stop-gate: no git work tree found to inspect (REGISTRO_WSL_TREE=%s) -- the documentation gate did NOT run."}\n' \
            "$(hook_json_escape "${REGISTRO_WSL_TREE:-unset}")"
    fi
    exit 0
fi

ALL_CHANGES="$REGISTRO_ACTIVE_CHANGES"

# Count source files
APP_COUNT=$(count_files '\.(php|blade\.php|js|ts|vue)$' "$ALL_CHANGES")

# Count documentation/rules/memory files
DOC_COUNT=$(count_files '^(app/docs/|\.claude/rules/|CLAUDE\.md)' "$ALL_CHANGES")
MEM_COUNT=$(count_files 'memory/' "$ALL_CHANGES")
NON_CODE=$((DOC_COUNT + MEM_COUNT))

# Threshold: 5+ source files changed with ZERO docs/rules/memory updates → block
if [ "$APP_COUNT" -ge 5 ] && [ "$NON_CODE" -eq 0 ]; then
    {
        echo "BLOCKED: Changed $APP_COUNT source files but 0 documentation/rules/memory files."
        echo "(tree inspected: $REGISTRO_ACTIVE_TREE)"
        echo ""
        echo "Per self-improvement.md ZASADA 2: document IMMEDIATELY after implementation."
        echo ""
        echo "Checklist before finishing:"
        echo "  1. app/docs/ — updated if feature/architecture changed?"
        echo "  2. .claude/rules/ — updated if new pattern/error resolved?"
        echo "  3. memory/MEMORY.md — updated if significant for future sessions?"
        echo ""
        echo "Do these NOW. If genuinely not needed, explain why in your response."
    } >&2
    exit 2
fi

exit 0
