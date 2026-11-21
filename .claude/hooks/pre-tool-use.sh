#!/bin/bash
# PreToolUse Hook - Enforces critical rules BEFORE Claude executes commands
# See: .claude/rules/git-workflow.md, .claude/rules/deployment.md
#
# Runs inside WSL even when Claude Code runs from Windows: lib/wsl-forward.sh
# re-executes this script there. See .claude/rules/claude-code-config.md.
#
# The branch rules (1, 2, 5) judge the branch of the tree the COMMAND targets,
# resolved by lib/pretool_target.py -- never the hook's own cwd. When the target
# cannot be determined the answer is DENY with the form to use, not a guess.
#
# Tools covered: Bash (full rules) and PowerShell (the primary shell tool on this
# Windows machine). The resolver lexes POSIX shell only, so for PowerShell the
# text rules 3/4 apply and any git commit / git push / gh pr create is DENIED
# with an instruction to use the Bash tool -- fail closed rather than guess.

set -euo pipefail

_HOOKS="${BASH_SOURCE[0]%/*}"
# shellcheck source=lib/wsl-forward.sh
source "$_HOOKS/lib/wsl-forward.sh"

# This hook is a GUARD: an answer is JSON on rc 0, and nothing else counts as
# the hook having run (see lib/wsl-forward.sh).
HOOK_EXPECT_OUTPUT=1
# Claude Code kills a hook at 20 s (settings.json) and then runs the command
# with no decision -- fail-open. Answer first: bound wsl.exe below that.
HOOK_WSL_TIMEOUT="${REGISTRO_WSL_TIMEOUT:-15}"

# Git Bash only. PreToolUse fires on every Bash call and a WSL round trip costs
# a few hundred ms, so calls that cannot possibly concern a guarded rule are
# answered here. Deliberately a substring test on the RAW json: it can only
# over-match (forward needlessly), never miss a guarded command.
hook_windows_prefilter() {
    case "$HOOK_INPUT" in
        *git* | *"gh "* | *migrate:* | *db:wipe* | *FILESYSTEM_DISK*) return 1 ;;
    esac
    hook_pass
    return 0
}

# A guarded command whose guard could not run is not checked. Say so and block.
hook_on_forward_failure() {
    hook_deny "BLOCKED: the PreToolUse guard did not answer inside WSL (rc=$1; 124 = timed out, 99 = it died or returned no JSON), so this command was NOT checked against the branch/migration rules. Start WSL and retry; diagnose with: wsl.exe -d ${REGISTRO_WSL_DISTRO} -e true. See: .claude/rules/claude-code-config.md"
    exit 0
}

registro_wsl_forward "${BASH_SOURCE[0]}" "$@"

# --- From here on we are on Linux (WSL, or a native session) -----------------

hook_read_input

# CRASH GUARD. Under `set -e`/`set -u` any unexpected failure aborts this script
# with exit 1 -- which Claude Code reads as a NON-blocking error: the command
# runs unguarded. Exiting without having answered is never "no objection": on a
# call that mentions a guarded operation, answer DENY. (The forwarded path has
# the same protection in the shim; this covers a native Linux session.)
_pretool_crash_guard() {
    local rc=$?
    [ "$HOOK_REPLIED" -eq 0 ] || return 0
    case "$HOOK_INPUT" in
        *git*commit* | *git*push* | *gh*pr*create* | *migrate:* | *db:wipe* | *FILESYSTEM_DISK*)
            hook_deny "BLOCKED: the PreToolUse guard crashed (exit $rc) before reaching a verdict, so this command that mentions a guarded operation was NOT checked. Retry; if it persists run scripts/cc-doctor.sh. See: .claude/rules/claude-code-config.md"
            ;;
        *)
            echo "pre-tool-use: crashed (exit $rc) on a call that mentions nothing guarded -- passing." >&2
            hook_pass
            ;;
    esac
    exit 0
}
trap _pretool_crash_guard EXIT

tool_name="" command="" cwd=""
parsed=1
hook_parse tool_name:tool_name command:tool_input.command cwd:cwd || parsed=0

if [ "$parsed" -eq 0 ]; then
    # No python3, or stdin was not JSON. Do not read that as "empty command".
    case "$HOOK_INPUT" in
        *git*commit* | *git*push* | *gh*pr*create* | *migrate:* | *db:wipe* | *FILESYSTEM_DISK*)
            hook_deny "BLOCKED: this call may touch a guarded operation (git commit/push, gh pr create, migrate:*, db:wipe, FILESYSTEM_DISK) but the hook cannot read its input: python3 is missing or the input was not valid JSON. Install python3 in WSL (sudo apt install python3), then retry. See: .claude/rules/claude-code-config.md"
            exit 0
            ;;
    esac
    echo "pre-tool-use: could not read the hook input (python3 missing, or the input is not valid JSON) -- guard skipped for this call. Install python3 in WSL." >&2
    hook_pass
    exit 0
fi

# has_word WORD -- WORD appears as a whole word anywhere in the command text.
has_word() {
    local re="\\b$1\\b"
    [[ "$command" =~ $re ]]
}

# -----------------------------------------------------------------------------
# PowerShell tool: the resolver lexes POSIX shell, not PowerShell. Do not guess.
# -----------------------------------------------------------------------------
if [[ "$tool_name" == "PowerShell" ]]; then
    if { has_word git && { has_word commit || has_word push; }; } || { has_word gh && has_word pr && has_word create; }; then
        hook_deny "BLOCKED: git commit / git push / gh pr create through the PowerShell tool cannot be checked against the branch rules (the target-tree resolver understands POSIX shell, not PowerShell), so it is refused rather than guessed. Run git through the Bash tool instead, with the tree spelled out: wsl -e bash -c 'cd /var/www/projects/registro/<tree> && git ...'. See: .claude/rules/claude-code-config.md"
        exit 0
    fi
fi

# -----------------------------------------------------------------------------
# Which tree does the command act on? (only needed for the branch rules)
# -----------------------------------------------------------------------------
INV_KIND=() INV_DIR=() INV_BRANCH=() INV_NOTE=()
SINK=0
RERR=""
if [[ "$tool_name" == "Bash" && ( "$command" == *git* || "$command" == *"gh "* ) ]]; then
    if hook_python; then
        _out="$(printf '%s' "$command" | "$HOOK_PYTHON" -X utf8 "$_HOOKS/lib/pretool_target.py" "${cwd:-$PWD}" 2>/dev/null)" ||
            RERR="the target resolver failed to run"
        # The resolver prints shell code with every value shell-quoted; eval it
        # only behind its marker line.
        if [ -z "$RERR" ] && [[ "$_out" == "#REGISTRO_RESOLVER_OK"* ]]; then
            eval "$_out"
        elif [ -z "$RERR" ]; then
            RERR="the target resolver returned unusable output"
        fi
    else
        RERR="python3 is not installed in WSL (sudo apt install python3)"
    fi
fi

# deny_unknown WHY -- the target tree could not be determined.
deny_unknown() {
    hook_deny "BLOCKED: cannot determine which git work tree this command acts on ($1), so its branch cannot be checked. Spell the target out, one of: wsl -e bash -c 'cd /var/www/projects/registro/<tree> && git ...'  |  wsl.exe --cd /var/www/projects/registro/<tree> -- git ...  |  git -C /var/www/projects/registro/<tree> ... . See: .claude/rules/claude-code-config.md"
}

# note_for DIR -- where the command resolved to; a hint when that is the
# Windows mirror clone, which is read-only and always on develop.
note_for() {
    case "$1" in
        /mnt/*) printf ' NOTE: this resolved to %s, the Windows mirror clone (read-only, always develop). Run git in WSL: wsl -e bash -c '"'"'cd /var/www/projects/registro/<tree> && git ...'"'"'.' "$1" ;;
        *) printf ' [tree: %s]' "$1" ;;
    esac
}

# When no real invocation of a guarded operation was found, but the resolver
# could not read the command (ERR) or it runs code built at run time (SINK), a
# command that still MENTIONS the operation as a whole word is not given the
# benefit of the doubt. (It used to require the literal text "git commit", which
# `git -C dir commit -m "$(echo # it's ...)"` simply does not contain.) A plain
# quoted mention parses cleanly, sets neither flag, and still passes.
unreadable() { [ -n "$RERR" ] || [ "$SINK" -eq 1 ]; }

# =============================================================================
# RULE 1: Block direct commits to develop/staging/main
# Three-tier promotion model (2026-08-16): feature/* -> develop -> staging -> main.
# staging is where rc* tags are cut for UAT and is merge-only, same as develop/main.
# =============================================================================
if [[ "$tool_name" == "Bash" ]]; then
    n=0
    for i in "${!INV_KIND[@]}"; do
        [ "${INV_KIND[$i]}" = commit ] || continue
        n=$((n + 1))
        inv_dir="${INV_DIR[$i]}" inv_branch="${INV_BRANCH[$i]}" inv_note="${INV_NOTE[$i]}"
        if [ -z "$inv_dir" ]; then deny_unknown "$inv_note"; exit 0; fi
        if [ "$inv_branch" = "?" ]; then deny_unknown "$inv_dir is not a git work tree"; exit 0; fi
        case "$inv_branch" in
            develop)
                hook_deny "BLOCKED: Direct commit to develop is FORBIDDEN! You MUST: 1. git checkout -b feature/your-feature-name 2. Make changes on feature branch 3. Create PR to develop. See: .claude/rules/git-workflow.md$(note_for "$inv_dir")"
                exit 0 ;;
            staging)
                hook_deny "BLOCKED: Direct commit to staging is FORBIDDEN! staging only advances via PR from develop (feature/* -> develop -> staging -> main). See: .claude/rules/git-workflow.md$(note_for "$inv_dir")"
                exit 0 ;;
            main)
                hook_deny "BLOCKED: Direct commit to main is FORBIDDEN! main only advances via PR from staging (or release/*, hotfix/* for an emergency patch). See: .claude/rules/git-workflow.md$(note_for "$inv_dir")"
                exit 0 ;;
        esac
    done
    if [ "$n" -eq 0 ] && has_word git && has_word commit && unreadable; then
        deny_unknown "${RERR:-the command runs dynamically built code}"
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
if [[ "$tool_name" == "Bash" ]]; then
    n=0
    for i in "${!INV_KIND[@]}"; do
        [ "${INV_KIND[$i]}" = push-main ] || continue
        n=$((n + 1))
        inv_dir="${INV_DIR[$i]}" inv_branch="${INV_BRANCH[$i]}" inv_note="${INV_NOTE[$i]}"
        if [ -z "$inv_dir" ]; then deny_unknown "$inv_note"; exit 0; fi
        if [ "$inv_branch" = "?" ]; then deny_unknown "$inv_dir is not a git work tree"; exit 0; fi
        if [[ "$inv_branch" != release/* && "$inv_branch" != hotfix/* ]]; then
            hook_deny "BLOCKED: Push to main only allowed from release/* or hotfix/* branches. See: .claude/rules/git-workflow.md$(note_for "$inv_dir")"
            exit 0
        fi
    done
    if [ "$n" -eq 0 ] && has_word git && has_word push && unreadable; then
        deny_unknown "${RERR:-the command runs dynamically built code}"
        exit 0
    fi
fi

# =============================================================================
# RULE 3: Block ALL destructive database commands (ANY environment)
# Incident 2026-03-17: RefreshDatabase wiped dev MySQL via Docker test run.
# Claude must NEVER run migrate:fresh, migrate:reset, db:wipe, or migrate:refresh.
# Only the developer may run these manually if needed.
# (Bash AND PowerShell: pure text, no shell grammar needed.)
# =============================================================================
if [[ "$tool_name" == "Bash" || "$tool_name" == "PowerShell" ]]; then
    if [[ "$command" == *"migrate:fresh"* || "$command" == *"migrate:reset"* || "$command" == *"migrate:refresh"* || "$command" == *"db:wipe"* ]]; then
        hook_deny "BLOCKED: migrate:fresh/reset/refresh/db:wipe is FORBIDDEN for Claude in ALL environments! These destroy data. Only the developer may run these manually. Incident 2026-03-17: RefreshDatabase wiped dev MySQL. See: .claude/rules/deployment.md"
        exit 0
    fi
fi

# =============================================================================
# RULE 4: Block FILESYSTEM_DISK=local
# =============================================================================
if [[ "$tool_name" == "Bash" || "$tool_name" == "PowerShell" ]] && [[ "$command" == *"FILESYSTEM_DISK"* && "$command" == *"local"* ]]; then
    hook_deny "BLOCKED: FILESYSTEM_DISK=local is FORBIDDEN! Always use FILESYSTEM_DISK=public. See: CLAUDE.md"
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
if [[ "$tool_name" == "Bash" ]]; then
    n=0
    for i in "${!INV_KIND[@]}"; do
        [ "${INV_KIND[$i]}" = pr-create ] || continue
        n=$((n + 1))
        inv_dir="${INV_DIR[$i]}" inv_branch="${INV_BRANCH[$i]}" inv_note="${INV_NOTE[$i]}"
        if [ -z "$inv_dir" ]; then deny_unknown "$inv_note"; exit 0; fi
        if [ "$inv_branch" = "?" ]; then deny_unknown "$inv_dir is not a git work tree"; exit 0; fi
        branch="$inv_branch"

        # Release and hotfix branches CAN target main
        if [[ "$branch" == release/* || "$branch" == hotfix/* ]]; then
            if [[ "$command" != *"--base main"* && "$command" != *"--base develop"* && "$command" != *"--base staging"* ]]; then
                hook_deny "BLOCKED: gh pr create requires --base flag! For release/hotfix branches, use: --base main. See: .claude/rules/git-workflow.md$(note_for "$inv_dir")"
                exit 0
            fi
        # staging is the promotion branch to production -- it must target main only.
        elif [[ "$branch" == "staging" ]]; then
            if [[ "$command" != *"--base main"* ]]; then
                hook_deny "BLOCKED: gh pr create from staging must use --base main! staging -> main is the production promotion (feature/* -> develop -> staging -> main). See: .claude/rules/git-workflow.md$(note_for "$inv_dir")"
                exit 0
            fi
        # All other branches (feature/*, bugfix/*, develop itself for a develop->staging
        # promotion PR, ...) must target develop or staging.
        elif [[ "$command" != *"--base develop"* && "$command" != *"--base staging"* ]]; then
            hook_deny "BLOCKED: gh pr create without --base develop or --base staging! PRs from feature/* must target develop, not main. Use: gh pr create --base develop. See: .claude/rules/git-workflow.md$(note_for "$inv_dir")"
            exit 0
        fi
    done
    if [ "$n" -eq 0 ] && has_word gh && has_word pr && has_word create && unreadable; then
        deny_unknown "${RERR:-the command runs dynamically built code}"
        exit 0
    fi
fi

# =============================================================================
# Default: no objection. NOT `permissionDecision: allow` -- see hook_pass.
# =============================================================================
hook_pass
exit 0
