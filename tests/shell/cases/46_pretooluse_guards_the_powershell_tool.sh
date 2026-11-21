#!/bin/bash
###############################################################################
# Pins: the PowerShell tool goes through the same guard as Bash.
#
# THE BUG (code review, CRITICAL): settings.json wired PreToolUse with matcher
# "Bash" only. On this machine the PRIMARY shell tool is PowerShell, so
#     wsl -e bash -c 'cd /var/www/projects/registro/app && git commit -m x'
#     git push origin main
#     docker compose exec app php artisan migrate:fresh
# run through it were never seen by the hook at all -- the guard covered the
# secondary tool and none of the primary.
#
# tool_name for that tool is "PowerShell" and the command is in
# tool_input.command (confirmed against real Claude Code, not assumed). The
# resolver lexes POSIX shell, not PowerShell, so for PowerShell the text rules 3
# and 4 apply and ANY git commit / git push / gh pr create is DENIED with the
# instruction to use the Bash tool -- fail closed rather than guess.
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "pre-tool-use.sh: PowerShell tool gets rules 3/4 and git commit/push/gh pr create is refused"

HOOK="$REPO_ROOT/.claude/hooks/pre-tool-use.sh"
SETTINGS="$REPO_ROOT/.claude/settings.json"
sandbox_init
FEAT="$SANDBOX/feat"; new_repo "$FEAT" feature/x

ps() { # COMMAND -> VERDICT, REASON (PowerShell tool)
    RAW="$(cd "$FEAT" && pretool_json "$1" "$FEAT" PowerShell | env -u REGISTRO_HOOK_ORIGIN -u REGISTRO_HOOK_ASSUME_WINDOWS bash "$HOOK" 2>/dev/null)"
    REASON="$(reason_of "$RAW")"
    VERDICT="$(decision_of "$RAW")"
}

# --- the three repros from the review ------------------------------------------------
ps "wsl -e bash -c 'cd /var/www/projects/registro/app && git commit -m x'"
assert_eq "deny" "$VERDICT" "PowerShell: wsl ... git commit"
assert_contains "$REASON" "Bash tool" "...and the reason says to use the Bash tool"

ps "git push origin main"
assert_eq "deny" "$VERDICT" "PowerShell: git push origin main"

ps "docker compose exec app php artisan migrate:fresh"
assert_eq "deny" "$VERDICT" "PowerShell: migrate:fresh (rule 3)"
assert_contains "$REASON" "migrate:fresh" "...by rule 3"

# --- the rest of the surface ------------------------------------------------------------
ps "php artisan db:wipe"
assert_eq "deny" "$VERDICT" "PowerShell: db:wipe"
ps "\$env:FILESYSTEM_DISK = 'local'"
assert_eq "deny" "$VERDICT" "PowerShell: FILESYSTEM_DISK=local (rule 4)"
ps "git -C /var/www/projects/registro/app-hooks commit -m x"
assert_eq "deny" "$VERDICT" "PowerShell: git -C <tree> commit is refused too (no guessing)"
ps "gh pr create --base develop --title x"
assert_eq "deny" "$VERDICT" "PowerShell: gh pr create"
ps "git add -A; git commit -m x"
assert_eq "deny" "$VERDICT" "PowerShell: chained"

# --- and it does NOT turn into a blanket ban on the tool ---------------------------------
ps "Get-ChildItem -Recurse | Select-Object -First 5"
assert_eq "pass" "$VERDICT" "PowerShell: unrelated command passes"
ps "git status"
assert_eq "pass" "$VERDICT" "PowerShell: git status passes"
ps "git log --oneline -5"
assert_eq "pass" "$VERDICT" "PowerShell: git log passes"
ps "wsl -e bash -c 'cd /var/www/projects/registro/app && git diff'"
assert_eq "pass" "$VERDICT" "PowerShell: read-only git through wsl passes"
ps "\$env:FILESYSTEM_DISK = 'public'"
assert_eq "pass" "$VERDICT" "PowerShell: FILESYSTEM_DISK=public passes"
assert_not_contains "$RAW" "permissionDecision" "...and a pass still carries no permissionDecision"

# --- the Bash tool is unaffected by the new branch -----------------------------------------
RAW="$(cd "$FEAT" && pretool_json "git commit -m x" "$FEAT" | env -u REGISTRO_HOOK_ORIGIN bash "$HOOK" 2>/dev/null)"
assert_eq "pass" "$(decision_of "$RAW")" "Bash: git commit on a feature tree still passes"

# --- the wiring: the committed matcher must actually select PowerShell ----------------------
python3 - "$SETTINGS" <<'PY'
import json, re, sys
d = json.load(open(sys.argv[1]))
ok = False
for e in d["hooks"]["PreToolUse"]:
    if any("pre-tool-use.sh" in h.get("command", "") for h in e["hooks"]):
        m = e.get("matcher", "")
        ok = (m in ("", "*")) or (re.fullmatch(m, "Bash") and re.fullmatch(m, "PowerShell"))
sys.exit(0 if ok else 1)
PY
assert_eq "0" "$?" "settings.json PreToolUse matcher selects BOTH Bash and PowerShell"

test_finish
