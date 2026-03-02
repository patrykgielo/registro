#!/bin/bash
# PreToolUse Hook - Enforces critical rules BEFORE Claude executes commands
# See: .claude/rules/git-workflow.md, .claude/rules/deployment.md

set -euo pipefail

# Read COMPLETE JSON from stdin (not just one line!)
input=$(cat)

# Extract fields using jq
tool_name=$(echo "$input" | jq -r '.tool_name // empty')
command=$(echo "$input" | jq -r '.tool_input.command // empty')

# =============================================================================
# RULE 1: Block direct commits to develop/main
# =============================================================================
if [[ "$tool_name" == "Bash" && "$command" == *"git commit"* ]]; then
    branch=$(git branch --show-current 2>/dev/null || echo "unknown")

    if [[ "$branch" == "develop" ]]; then
        cat <<'EOF'
{"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"deny","permissionDecisionReason":"BLOCKED: Direct commit to develop is FORBIDDEN! You MUST: 1. git checkout -b feature/your-feature-name 2. Make changes on feature branch 3. Create PR to develop. See: .claude/rules/git-workflow.md"}}
EOF
        exit 0
    fi

    if [[ "$branch" == "main" ]]; then
        cat <<'EOF'
{"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"deny","permissionDecisionReason":"BLOCKED: Direct commit to main is FORBIDDEN! Only release/* and hotfix/* branches can be merged to main. See: .claude/rules/git-workflow.md"}}
EOF
        exit 0
    fi
fi

# =============================================================================
# RULE 2: Block push to main without release/hotfix branch
# =============================================================================
if [[ "$tool_name" == "Bash" && "$command" == *"git push"* && "$command" == *"main"* ]]; then
    branch=$(git branch --show-current 2>/dev/null || echo "unknown")

    if [[ "$branch" != release/* && "$branch" != hotfix/* ]]; then
        cat <<'EOF'
{"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"deny","permissionDecisionReason":"BLOCKED: Push to main only allowed from release/* or hotfix/* branches. See: .claude/rules/git-workflow.md"}}
EOF
        exit 0
    fi
fi

# =============================================================================
# RULE 3: Block dangerous production commands
# =============================================================================
if [[ "$tool_name" == "Bash" && ("$command" == *"migrate:fresh"* || "$command" == *"migrate:reset"*) ]]; then
    if [[ "${APP_ENV:-}" == "production" || "$command" == *"production"* ]]; then
        cat <<'EOF'
{"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"deny","permissionDecisionReason":"BLOCKED: migrate:fresh/reset is FORBIDDEN in production! Use: php artisan migrate --force. See: .claude/rules/deployment.md"}}
EOF
        exit 0
    fi
fi

# =============================================================================
# RULE 4: Block FILESYSTEM_DISK=local
# =============================================================================
if [[ "$tool_name" == "Bash" && "$command" == *"FILESYSTEM_DISK"* && "$command" == *"local"* ]]; then
    cat <<'EOF'
{"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"deny","permissionDecisionReason":"BLOCKED: FILESYSTEM_DISK=local is FORBIDDEN! Always use FILESYSTEM_DISK=public. See: CLAUDE.md"}}
EOF
    exit 0
fi

# =============================================================================
# RULE 5: Block gh pr create without proper --base target
# - feature/* and bugfix/* branches must target develop or staging
# - release/* and hotfix/* branches can target main
# =============================================================================
if [[ "$tool_name" == "Bash" && "$command" == *"gh pr create"* ]]; then
    branch=$(git branch --show-current 2>/dev/null || echo "unknown")

    # Release and hotfix branches CAN target main
    if [[ "$branch" == release/* || "$branch" == hotfix/* ]]; then
        if [[ "$command" != *"--base main"* && "$command" != *"--base develop"* && "$command" != *"--base staging"* ]]; then
            cat <<'EOF'
{"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"deny","permissionDecisionReason":"BLOCKED: gh pr create requires --base flag! For release/hotfix branches, use: --base main. See: .claude/rules/git-workflow.md"}}
EOF
            exit 0
        fi
    # All other branches must target develop or staging
    elif [[ "$command" != *"--base develop"* && "$command" != *"--base staging"* ]]; then
        cat <<'EOF'
{"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"deny","permissionDecisionReason":"BLOCKED: gh pr create without --base develop or --base staging! PRs from feature/* must target develop, not main. Use: gh pr create --base develop. See: .claude/rules/git-workflow.md"}}
EOF
        exit 0
    fi
fi

# =============================================================================
# Default: Allow the action
# =============================================================================
echo '{"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"allow"}}'
exit 0
