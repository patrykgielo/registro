#!/bin/bash
# Notification Hook — Post-Compaction Rules Re-injection
#
# When context compaction occurs, rules loaded at session start get summarized
# into vague references and lose their enforcement power. This hook detects
# the "compact" notification and re-injects critical TIER 1 rules.

set -euo pipefail

INPUT=$(cat)

# Only trigger on SubagentCompact notifications
TYPE=$(echo "$INPUT" | jq -r '.type // empty' 2>/dev/null || true)

if [[ "$TYPE" != *"compact"* && "$TYPE" != *"Compact"* ]]; then
    exit 0
fi

cat << 'RULES'

## CONTEXT COMPACTED — TIER 1 RULES RE-ACTIVATION

Rules may have been lost in compaction. These are NON-NEGOTIABLE:

1. **AGENT FIRST** — NEVER start implementing without an agent (laravel-senior-architect, frontend-ui-architect, Explore)
2. **DOCS AFTER** — ALWAYS update app/docs/ and .claude/rules/ after implementation (Stop hook enforces this)
3. **BRANCH** — NEVER commit to develop/main directly (PreToolUse hook blocks this)
4. **FILESYSTEM_DISK=public** — NEVER use local
5. **User model** — first_name/last_name (no `name` column)
6. **Filament v4** — `Schema` not `Form`, `Filament\Actions` not `Filament\Tables\Actions`

State: "TIER 1 rules re-activated" before proceeding.

RULES

exit 0
