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
# RULE 1: Block direct commits to develop/staging/main
# Three-tier promotion model (2026-08-16): feature/* -> develop -> staging -> main.
# staging is where rc* tags are cut for UAT and is merge-only, same as develop/main.
# =============================================================================
if [[ "$tool_name" == "Bash" && "$command" == *"git commit"* ]]; then
    branch=$(git branch --show-current 2>/dev/null || echo "unknown")

    if [[ "$branch" == "develop" ]]; then
        cat <<'EOF'
{"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"deny","permissionDecisionReason":"BLOCKED: Direct commit to develop is FORBIDDEN! You MUST: 1. git checkout -b feature/your-feature-name 2. Make changes on feature branch 3. Create PR to develop. See: .claude/rules/git-workflow.md"}}
EOF
        exit 0
    fi

    if [[ "$branch" == "staging" ]]; then
        cat <<'EOF'
{"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"deny","permissionDecisionReason":"BLOCKED: Direct commit to staging is FORBIDDEN! staging only advances via PR from develop (feature/* -> develop -> staging -> main). See: .claude/rules/git-workflow.md"}}
EOF
        exit 0
    fi

    if [[ "$branch" == "main" ]]; then
        cat <<'EOF'
{"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"deny","permissionDecisionReason":"BLOCKED: Direct commit to main is FORBIDDEN! main only advances via PR from staging (or release/*, hotfix/* for an emergency patch). See: .claude/rules/git-workflow.md"}}
EOF
        exit 0
    fi
fi

# =============================================================================
# RULE 2: Block push to main without release/hotfix branch
# Evaluated against the three-tier model (2026-08-16), left unchanged: promotion
# to main is staging -> main via PR (gh pr merge calls GitHub's merge API, not
# `git push origin main`), so this rule never needs to know about staging.
# Unchanged also means staging itself still cannot push straight to main --
# correct, that would bypass the PR/review gate the whole model exists for.
# release/*, hotfix/* remain the only direct-push escape hatch, for a genuine
# production emergency that cannot wait for a staging round-trip.
# =============================================================================
if [[ "$tool_name" == "Bash" && "$command" == *"git push"* && ("$command" == *"origin main"* || "$command" == *"push main"*) ]]; then
    branch=$(git branch --show-current 2>/dev/null || echo "unknown")

    if [[ "$branch" != release/* && "$branch" != hotfix/* ]]; then
        cat <<'EOF'
{"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"deny","permissionDecisionReason":"BLOCKED: Push to main only allowed from release/* or hotfix/* branches. See: .claude/rules/git-workflow.md"}}
EOF
        exit 0
    fi
fi

# =============================================================================
# RULE 3: Block ALL destructive database commands (ANY environment)
# Incident 2026-03-17: RefreshDatabase wiped dev MySQL via Docker test run.
# Claude must NEVER run migrate:fresh, migrate:reset, db:wipe, or migrate:refresh.
# Only the developer may run these manually if needed.
# =============================================================================
if [[ "$tool_name" == "Bash" ]]; then
    if [[ "$command" == *"migrate:fresh"* || "$command" == *"migrate:reset"* || "$command" == *"migrate:refresh"* || "$command" == *"db:wipe"* ]]; then
        cat <<'EOF'
{"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"deny","permissionDecisionReason":"BLOCKED: migrate:fresh/reset/refresh/db:wipe is FORBIDDEN for Claude in ALL environments! These destroy data. Only the developer may run these manually. Incident 2026-03-17: RefreshDatabase wiped dev MySQL. See: .claude/rules/deployment.md"}}
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
# Three-tier promotion model (2026-08-16): feature/* -> develop -> staging -> main.
# - feature/*, bugfix/*, and any other branch not matched below: --base develop or staging
# - staging (the promotion branch that cuts rc* tags for UAT): --base main ONLY.
#   staging is not release/* or hotfix/*, so it needs its own branch, not the
#   release/hotfix bucket below -- falling through to the catch-all would
#   require --base develop/staging and block exactly the PR that promotes to
#   production (Gap found 2026-08-16, see git-workflow.md).
# - release/* and hotfix/* (emergency-only, direct from main): --base main
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
    # staging is the promotion branch to production -- it must target main only.
    elif [[ "$branch" == "staging" ]]; then
        if [[ "$command" != *"--base main"* ]]; then
            cat <<'EOF'
{"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"deny","permissionDecisionReason":"BLOCKED: gh pr create from staging must use --base main! staging -> main is the production promotion (feature/* -> develop -> staging -> main). See: .claude/rules/git-workflow.md"}}
EOF
            exit 0
        fi
    # All other branches (feature/*, bugfix/*, develop itself for a develop->staging
    # promotion PR, ...) must target develop or staging.
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
