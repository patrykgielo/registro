#!/bin/bash
# Stop Hook - Enforce documentation/rules update after code changes
#
# When Claude finishes a task, this hook checks if significant code was changed
# without any corresponding documentation/rules updates. If so, it blocks
# completion and forces documentation work.
#
# This is DETERMINISTIC enforcement — not advisory text that can be ignored.

set -uo pipefail

# Record last-response timestamp for cache expiry detection in prompt-submit hook
# Cache TTL = 5 min; after that the next turn pays 10x (full context rebuild)
_CC_TS_FILE="/tmp/cc_cache_ts_$(echo "${CLAUDE_PROJECT_DIR:-/}" | tr '/' '_' | tr -s '_')"
date +%s > "$_CC_TS_FILE" 2>/dev/null || true

count_files() {
    local pattern="$1"
    local count
    count=$(echo "$2" | grep -cE "$pattern" 2>/dev/null || true)
    echo "${count:-0}"
}

# Get all changed files (unstaged vs HEAD + staged)
DIFF_HEAD=$(git diff --name-only HEAD 2>/dev/null || true)
DIFF_CACHED=$(git diff --cached --name-only 2>/dev/null || true)
ALL_CHANGES=$(printf '%s\n%s' "$DIFF_HEAD" "$DIFF_CACHED" | sort -u)

if [ -z "$ALL_CHANGES" ]; then
    exit 0
fi

# Count source files
APP_COUNT=$(count_files '\.(php|blade\.php|js|ts|vue)$' "$ALL_CHANGES")

# Count documentation/rules/memory files
DOC_COUNT=$(count_files '^(app/docs/|\.claude/rules/|CLAUDE\.md)' "$ALL_CHANGES")
MEM_COUNT=$(count_files 'memory/' "$ALL_CHANGES")
NON_CODE=$((DOC_COUNT + MEM_COUNT))

# Threshold: 5+ source files changed with ZERO docs/rules/memory updates → block
if [ "$APP_COUNT" -ge 5 ] && [ "$NON_CODE" -eq 0 ]; then
    echo "BLOCKED: Changed $APP_COUNT source files but 0 documentation/rules/memory files."
    echo ""
    echo "Per self-improvement.md ZASADA 2: document IMMEDIATELY after implementation."
    echo ""
    echo "Checklist before finishing:"
    echo "  1. app/docs/ — updated if feature/architecture changed?"
    echo "  2. .claude/rules/ — updated if new pattern/error resolved?"
    echo "  3. memory/MEMORY.md — updated if significant for future sessions?"
    echo ""
    echo "Do these NOW. If genuinely not needed, explain why in your response."
    exit 1
fi

exit 0
