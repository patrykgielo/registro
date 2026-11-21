#!/bin/bash
###############################################################################
# Pins: no byte in a command can forge the hook's own framing, and a deny is
# always valid JSON.
#
# THE BUG (code review, CRITICAL): hook_parse joined tool_name/command/cwd with
# 0x1f and split on the FIRST 0x1f. A command that contained one --
#     echo '<0x1f>'; git commit -m x
# -- was cut short at the separator, so every rule saw only `echo '` and the
# hook answered "no objection": a total bypass of rules 1-5 (it equally hid
# `migrate:fresh` and `FILESYSTEM_DISK=local`). The target resolver had the same
# weakness: its INV records were fields joined by 0x1f, so a `git -C <dir>` whose
# directory name contained 0x1f shifted the fields and the branch check read the
# wrong column.
#
# Now both sides speak shell-QUOTED values that are eval'd behind a marker; a
# quoted value cannot be confused by its own contents.
#
# Also pinned (suggestion S1): hook_json_escape escaped only \n \r \t, so any other
# control character in a deny reason (a directory name with 0x01) produced invalid
# JSON, which Claude Code reads as "no decision" -- the deny evaporated.
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "hooks: a 0x1f (or any byte) in the command cannot bypass the rules; deny JSON is always valid"

HOOK="$REPO_ROOT/.claude/hooks/pre-tool-use.sh"
RESOLVER="$REPO_ROOT/.claude/hooks/lib/pretool_target.py"
sandbox_init
US=$'\x1f'

DEV="$SANDBOX/trees/dev";   new_repo "$DEV" develop
FEAT="$SANDBOX/trees/feat"; new_repo "$FEAT" feature/x

verdict() { # COMMAND CWD -> VERDICT, REASON, RAW (never via $(...))
    RAW="$(cd "$DEV" && pretool_json "$1" "$2" | env -u REGISTRO_HOOK_ORIGIN -u REGISTRO_HOOK_ASSUME_WINDOWS bash "$HOOK" 2>/dev/null)"
    REASON="$(reason_of "$RAW")"
    VERDICT="$(decision_of "$RAW")"
}

# --- 1. THE BYPASS: a 0x1f ahead of the real command -----------------------------
verdict "echo '${US}'; git commit -m x" "$DEV"
assert_eq "deny" "$VERDICT" "0x1f in front of git commit on develop must still be denied"
assert_contains "$REASON" "develop" "...for the right reason"

verdict "echo '${US}'; php artisan migrate:fresh" "$FEAT"
assert_eq "deny" "$VERDICT" "0x1f in front of migrate:fresh must still be denied"

verdict "echo '${US}'; echo FILESYSTEM_DISK=local >> .env" "$FEAT"
assert_eq "deny" "$VERDICT" "0x1f in front of FILESYSTEM_DISK=local must still be denied"

verdict "${US}${US}; git commit -m x; ${US}" "$DEV"
assert_eq "deny" "$VERDICT" "0x1f on both sides of the real command"

verdict "git commit -m x" "${DEV}${US}"
assert_eq "deny" "$VERDICT" "0x1f in the cwd field does not shift the other fields"

# --- 2. hook_parse round-trips every awkward byte exactly ---------------------------
(
    # shellcheck disable=SC1091
    source "$REPO_ROOT/.claude/hooks/lib/json.sh"
    HOOK_INPUT="$(python3 -c '
import json
print(json.dumps({"tool_name": "Bash", "cwd": "/w\x1fk\nd", "tool_input": {"command": "a\x1fb\x1f\x1fc\nd \"q\" \x27s\x27 $(x) `y` \\\\ über"}}))')"
    HOOK_INPUT_READ=1
    tool_name="" command="" cwd=""
    hook_parse tool_name:tool_name command:tool_input.command cwd:cwd || exit 9
    [ "$tool_name" = "Bash" ] || exit 1
    [ "$cwd" = $'/w\x1fk\nd' ] || exit 2
    [ "$command" = $'a\x1fb\x1f\x1fc\nd "q" \'s\' $(x) `y` \\\\ über' ] || exit 3
    exit 0
)
assert_eq "0" "$?" "hook_parse returns every field byte for byte (0x1f, newline, quotes, \$(), backtick, unicode)"

# --- 3. the resolver's output: a directory name holding 0x1f survives ----------------
WEIRD="$SANDBOX/trees/de${US}v"
new_repo "$WEIRD" develop
OUT="$(printf '%s' "git -C '$WEIRD' commit -m x" | python3 "$RESOLVER" /tmp)"
(
    eval "$OUT"
    [ "${#INV_DIR[@]}" -eq 1 ] || exit 1
    [ "${INV_DIR[0]}" = "$WEIRD" ] || exit 2
    [ "${INV_BRANCH[0]}" = "develop" ] || exit 3
    [ "${INV_KIND[0]}" = "commit" ] || exit 4
    exit 0
)
assert_eq "0" "$?" "resolver round-trip: INV_DIR/INV_BRANCH/INV_KIND intact for a 0x1f directory"

verdict "git -C '$WEIRD' commit -m x" "$FEAT"
assert_eq "deny" "$VERDICT" "a develop tree whose NAME contains 0x1f is still recognised as develop"
assert_contains "$REASON" "Direct commit to develop" "...and denied as a commit to develop"

# --- 4. S1: a deny whose reason contains control characters is still valid JSON --------
CTRL="$SANDBOX/no-such-$(printf '\x01\x02\x1b')dir"
verdict "git -C '$CTRL' commit -m x" "$FEAT"
assert_eq "deny" "$VERDICT" "a non-repo target with control characters in its name"
assert_contains "$REASON" "not a git work tree" "...yields a deny that PARSES (not MALFORMED)"

(
    # shellcheck disable=SC1091
    source "$REPO_ROOT/.claude/hooks/lib/json.sh"
    bad=0
    for i in $(seq 1 31) 127; do
        c="$(printf "\\x$(printf '%02x' "$i")")"
        [ "$i" -eq 10 ] && continue # command substitution cannot hold a bare \n here; covered by \\n above
        esc="$(hook_json_escape "a${c}b\"c\\d")"
        python3 -c 'import json,sys; v=json.loads("\"" + sys.argv[1] + "\""); sys.exit(0 if v == "a" + chr(int(sys.argv[2])) + "b\"c\\d" else 1)' "$esc" "$i" || { echo "control char $i not round-tripped: $esc" >&2; bad=1; }
    done
    exit "$bad"
)
assert_eq "0" "$?" "hook_json_escape round-trips every control character 1-31 and 127 through a real JSON parser"

test_finish
