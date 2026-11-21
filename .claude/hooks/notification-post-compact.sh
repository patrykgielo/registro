#!/bin/bash
# Post-Compaction Rules Re-injection (wired as SessionStart, matcher "compact")
#
# When context compaction occurs, rules loaded at session start get summarized
# into vague references and lose their enforcement power. This hook re-injects
# the critical TIER 1 rules. SessionStart stdout is added to Claude's context.
#
# Pure text, so it never needs WSL (HOOK_FORWARD=0) and never needs a JSON
# parser: the matcher already restricts it to compaction, and the test below is
# a regex on the raw input.

set -euo pipefail

HOOK_FORWARD=0
# shellcheck source=lib/wsl-forward.sh
source "${BASH_SOURCE[0]%/*}/lib/wsl-forward.sh"

hook_read_input

# SessionStart reports `"source":"compact"`; the legacy Notification event used `type`.
if ! [[ "$HOOK_INPUT" =~ \"(source|type|notification_type)\"[[:space:]]*:[[:space:]]*\"[^\"]*[Cc]ompact ]]; then
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
