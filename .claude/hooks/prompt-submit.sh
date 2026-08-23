#!/bin/bash
# UserPromptSubmit Hook - Inject agent-first reminder on implementation tasks
#
# Fires before Claude processes ANY user prompt. When the prompt looks like
# an implementation task, it injects a mandatory process reminder that Claude
# MUST acknowledge before proceeding.
#
# This combats "implementation mode" where Claude skips agent-usage rules.

set -euo pipefail

INPUT=$(cat)
PROMPT=$(echo "$INPUT" | jq -r '.prompt // empty' 2>/dev/null || true)

# --- Cache expiry detection ---
# Anthropic prompt cache TTL = 5 minutes. After idle > 5 min the next turn
# rebuilds full context at ~10x cost. Warn before the expensive turn fires.
_CC_TS_FILE="/tmp/cc_cache_ts_$(echo "${CLAUDE_PROJECT_DIR:-/}" | tr '/' '_' | tr -s '_')"
if [ -f "$_CC_TS_FILE" ]; then
    _LAST_TS=$(cat "$_CC_TS_FILE" 2>/dev/null || echo "0")
    _NOW=$(date +%s)
    if [[ "$_LAST_TS" =~ ^[0-9]+$ ]]; then
        _ELAPSED=$(( _NOW - _LAST_TS ))
        # Warn only for 5 min – 2h idle (beyond 2h = fresh session, no point)
        if [ "$_ELAPSED" -gt 300 ] && [ "$_ELAPSED" -lt 7200 ]; then
            _MINUTES=$(( _ELAPSED / 60 ))
            echo "## CACHE WYGASL (${_MINUTES} min bezczynnosci)"
            echo "Ten turn przetworzy PELNY kontekst od nowa (koszt ~10x wyzszy)."
            echo "Rozwaz: /compact [instrukcje] PRZED tym turnem aby zmniejszyc kontekst."
            echo "Lub kontynuuj swiadomie — cache odbuduje sie na kolejny turn."
            echo ""
        fi
    fi
fi

# --- Architecture ground truth ---
# Injected on prompts that touch tenant identity, environments or deployment.
#
# Fires on QUESTIONS too, deliberately: the failure this guards against was
# answering "what happens in production when X" by tracing code and reading
# THIS machine's .env -- producing a confident answer about the wrong
# deployment model. The repo cannot tell you which model is running; only a
# measurement can, and the injected block names what has NOT been measured.
#
# Runs before the length check: "co na produkcji?" is short and is exactly the
# kind of prompt that needs this.
_ARCH_FACTS="${CLAUDE_PROJECT_DIR:-.}/scripts/architecture-facts.sh"
if [ -x "$_ARCH_FACTS" ] && echo "$PROMPT" | grep -qiE '(tenant|produkcj|prod\b|uat|staging|deploy|wdro|architekt|stack|subdomen|domen|multi-?tenan|provision|horizon|kolejk|queue|middleware|resolve|APP_URL|serwer|maszyn)'; then
    "$_ARCH_FACTS" --hook 2>/dev/null || true
fi

# Skip short prompts (yes/no, confirmations, follow-ups)
if [ "${#PROMPT}" -lt 25 ]; then
    exit 0
fi

# Detect implementation-like prompts (PL + EN keywords)
if echo "$PROMPT" | grep -qiE '(implement|create|add|build|fix|refactor|write|make|develop|zrob|zaimplementuj|dodaj|napraw|stworz|popraw|napisz|przygotuj|wdróż|plan:|krok|phase|faz)'; then
    cat << 'REMINDER'

## MANDATORY PROCESS — HOOKS ENFORCE THIS

Before writing ANY code:
1. **AGENT FIRST** — `laravel-senior-architect` (PHP) or `frontend-ui-architect` (UI) or `Explore` (research)
2. **Branch check** — must be on `feature/*` (not develop/main)
3. **After implementation** — update `app/docs/`, `.claude/rules/`, `memory/` (Stop hook will block you if you skip this)
4. **Tests** — `./vendor/bin/pint --test && php artisan test`

Stop hook counts modified files. If 5+ source files changed with 0 docs/rules updates, you CANNOT finish.

REMINDER
fi

exit 0
