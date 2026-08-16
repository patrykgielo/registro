#!/bin/bash
###############################################################################
# Pins: .claude/hooks/pre-tool-use.sh against the three-tier branch model
# (feature/* -> develop -> staging -> main, staging cuts rc* tags for UAT,
# see git-workflow.md) adopted 2026-08-16. Two real gaps existed in the
# two-tier version of this hook, both confirmed by running it (not reading
# it), against the file's actual state on develop at the time:
#
#   GAP 1 (RULE 5): `staging` is not `release/*`/`hotfix/*`, so a
#   `gh pr create --base main` run FROM staging fell into the generic
#   "everything else" branch, which requires --base develop/staging --
#   denying exactly the PR that promotes a release to production.
#
#   GAP 2 (RULE 1): direct `git commit` was blocked on develop and main but
#   not on staging, even though staging is meant to be merge-only exactly
#   like the other two.
#
# Runs the REAL hook script end-to-end (stdin JSON in, JSON out), inside a
# real throwaway git repo so `git branch --show-current` reflects each
# scenario -- not an extracted copy, not a grep for a string.
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "pre-tool-use.sh: three-tier branch model gates (staging<->main, staging commits)"

HOOK="$REPO_ROOT/.claude/hooks/pre-tool-use.sh"
[ -f "$HOOK" ] || fail "hook not found at $HOOK"

sandbox_init
REPO_DIR="$SANDBOX/repo"
mkdir -p "$REPO_DIR"
cd "$REPO_DIR"
git init -q
git config user.email test@example.com
git config user.name test
: >f.txt
git add f.txt
git commit -qm init >/dev/null

run_hook() {
    local branch="$1" command_json="$2"
    git checkout -q -B "$branch" >/dev/null 2>&1
    printf '{"tool_name":"Bash","tool_input":{"command":%s}}' "$command_json" | bash "$HOOK"
}

decision_of() {
    echo "$1" | jq -r '.hookSpecificOutput.permissionDecision // "MALFORMED"'
}

# --- Case 1: gh pr create --base main from staging -- must be ALLOWED. ---
OUT="$(run_hook staging '"gh pr create --base main --title x"')"
assert_eq "allow" "$(decision_of "$OUT")" "gh pr create --base main from staging (GAP 1)"

# --- Case 2: gh pr create --base main from feature/* -- must be BLOCKED. ---
OUT="$(run_hook feature/foo '"gh pr create --base main --title x"')"
assert_eq "deny" "$(decision_of "$OUT")" "gh pr create --base main from feature/*"

# --- Case 3: gh pr create --base develop/staging from staging -- must be
# BLOCKED (staging's only legal promotion target is main). ---
OUT="$(run_hook staging '"gh pr create --base develop --title x"')"
assert_eq "deny" "$(decision_of "$OUT")" "gh pr create --base develop from staging"

# --- Case 4: git commit while on staging -- must be BLOCKED (GAP 2). ---
OUT="$(run_hook staging '"git commit -m test"')"
assert_eq "deny" "$(decision_of "$OUT")" "git commit on staging (GAP 2)"

# --- Case 5: git commit while on feature/* -- must be ALLOWED (unaffected
# by this change, guards against a regression narrowing feature/* too). ---
OUT="$(run_hook feature/foo '"git commit -m test"')"
assert_eq "allow" "$(decision_of "$OUT")" "git commit on feature/*"

# --- Case 6: gh pr create --base staging from develop -- must be ALLOWED
# (this is how the develop -> staging promotion itself is opened). ---
OUT="$(run_hook develop '"gh pr create --base staging --title x"')"
assert_eq "allow" "$(decision_of "$OUT")" "gh pr create --base staging from develop"

# --- Case 7: git push origin main from staging -- must stay BLOCKED.
# Promotion to main is staging -> main via PR (gh pr merge), never a raw
# push from staging -- confirms RULE 2 was correctly left unchanged. ---
OUT="$(run_hook staging '"git push origin main"')"
assert_eq "deny" "$(decision_of "$OUT")" "git push origin main from staging"

# --- Case 8: git push origin main from hotfix/* -- must stay ALLOWED
# (the documented emergency escape hatch, unaffected by this change). ---
OUT="$(run_hook hotfix/x '"git push origin main"')"
assert_eq "allow" "$(decision_of "$OUT")" "git push origin main from hotfix/*"

test_finish
