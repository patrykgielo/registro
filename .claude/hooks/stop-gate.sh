#!/bin/bash
# Stop Hook - Enforce documentation/rules update after code changes
#
# When Claude finishes a task, this hook checks the uncommitted diff:
#
#   GATE 1  5+ source files changed and ZERO docs/rules/memory files.
#   GATE 2  anything a customer or tenant owner can SEE changed (views, public
#           routes, notifications/mail, Filament panel, controllers, lang) and
#           docs/oferta/ was not touched. docs/oferta/ is what marketing and
#           sales read; it went stale for a month (2026-08 -> 2026-09) because
#           nothing ever asked about it.
#
# Blocking mechanics (Claude Code hooks): a Stop hook blocks ONLY with exit 2,
# and only stderr is fed back to Claude. Until 2026-09-27 this script used
# `exit 1` + stdout, which is a non-blocking notice -- it never blocked once.
#
# Loop guard: when Claude continues BECAUSE of this hook, the next Stop event
# carries "stop_hook_active": true and we let it through. So each gate forces
# exactly one explicit answer ("updated X" or "no impact, because Y"), never an
# endless loop -- GATE 2 is a question, not a wall, because many customer-facing
# diffs (a CSS tweak, an a11y fix) genuinely change nothing in the offer.

set -uo pipefail

# Record last-response timestamp for cache expiry detection in prompt-submit hook
# Cache TTL = 5 min; after that the next turn pays 10x (full context rebuild)
_CC_TS_FILE="/tmp/cc_cache_ts_$(echo "${CLAUDE_PROJECT_DIR:-/}" | tr '/' '_' | tr -s '_')"
date +%s > "$_CC_TS_FILE" 2>/dev/null || true

INPUT="$(cat 2>/dev/null || true)"
if printf '%s' "$INPUT" | grep -qE '"stop_hook_active"[[:space:]]*:[[:space:]]*true'; then
    exit 0
fi

count_files() {
    local pattern="$1"
    local count
    count=$(echo "$2" | grep -cE "$pattern" 2>/dev/null || true)
    echo "${count:-0}"
}

# Get all changed files (unstaged vs HEAD + staged + untracked)
DIFF_HEAD=$(git diff --name-only HEAD 2>/dev/null || true)
DIFF_CACHED=$(git diff --cached --name-only 2>/dev/null || true)
UNTRACKED=$(git ls-files --others --exclude-standard 2>/dev/null || true)
ALL_CHANGES=$(printf '%s\n%s\n%s' "$DIFF_HEAD" "$DIFF_CACHED" "$UNTRACKED" | grep -v '^$' | sort -u)

if [ -z "$ALL_CHANGES" ]; then
    exit 0
fi

APP_COUNT=$(count_files '\.(php|blade\.php|js|ts|vue)$' "$ALL_CHANGES")
DOC_COUNT=$(count_files '^(app/docs/|docs/|\.claude/rules/|CLAUDE\.md)' "$ALL_CHANGES")
MEM_COUNT=$(count_files 'memory/' "$ALL_CHANGES")
NON_CODE=$((DOC_COUNT + MEM_COUNT))

CUSTOMER_FACING=$(count_files '^(resources/views/|routes/web\.php|app/Notifications/|app/Mail/|app/Filament/|app/Http/Controllers/|lang/|resources/lang/)' "$ALL_CHANGES")
OFFER_COUNT=$(count_files '^docs/oferta/' "$ALL_CHANGES")

if [ "$APP_COUNT" -ge 5 ] && [ "$NON_CODE" -eq 0 ]; then
    {
        echo "BLOCKED: Changed $APP_COUNT source files but 0 documentation/rules/memory files."
        echo ""
        echo "Checklist before finishing:"
        echo "  1. app/docs/ (features, flows) — updated if feature/architecture/flow changed?"
        echo "  2. docs/oferta/ — does the offer describe what a customer now gets?"
        echo "  3. .claude/rules/ — updated if new pattern/error resolved?"
        echo "  4. memory — updated if significant for future sessions?"
        echo ""
        echo "Do these NOW. If genuinely not needed, say why in your response."
    } >&2
    exit 2
fi

if [ "$CUSTOMER_FACING" -ge 1 ] && [ "$OFFER_COUNT" -eq 0 ]; then
    {
        echo "OFFER CHECK: $CUSTOMER_FACING customer-facing file(s) changed, docs/oferta/ untouched."
        echo ""
        echo "Did anything a customer or tenant owner experiences change — a capability,"
        echo "a step, a message they receive, a setting in the panel?"
        echo "  YES → update docs/oferta/ (feature page + catalogue status in docs/oferta/README.md)."
        echo "  NO  → state in your reply: \"Brak wpływu na ofertę: <reason>\"."
        echo "Rules: docs/oferta/README.md, section \"Jak utrzymywać\"."
    } >&2
    exit 2
fi

exit 0
