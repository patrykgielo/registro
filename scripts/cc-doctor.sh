#!/bin/bash
###############################################################################
# Checks that the Claude Code configuration still does what it claims.
#
# Exists because every configuration failure this project has hit failed
# SILENTLY. Firecrawl was declared under `mcpServers` in settings.json -- a key
# that does not exist in that file's schema -- so it was ignored without a word
# for months while eight agents referenced its tools. Three agents sat pinned to
# model snapshots from months earlier. The SessionStart hook tested for a file
# that is never created, so its reminder never once fired.
#
# The design rule that follows: a missing artifact is a FAILURE, never a
# silent skip. Anything this script cannot verify, it says so about.
#
# Only deterministic checks belong here -- true or false, no judgement. Whether
# a new Claude Code feature is worth adopting is not mechanisable and is not
# attempted.
#
#   scripts/cc-doctor.sh           # local checks; MCP health at most weekly
#   scripts/cc-doctor.sh --full    # force the MCP health check
#   scripts/cc-doctor.sh --hook    # JSON for SessionStart, silent when clean
#
# Exit: 0 clean, 1 findings.
###############################################################################

set -uo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
USER_DIR="${CLAUDE_CONFIG_DIR:-$HOME/.claude}"
STAMP="$PROJECT_DIR/.claude/.cc-doctor-mcp-stamp"
TIER1_BUDGET=12000
MCP_MAX_AGE_DAYS=7

MODE="normal"
case "${1:-}" in
    --full) MODE="full" ;;
    --hook) MODE="hook" ;;
    --help|-h) sed -n '2,22p' "${BASH_SOURCE[0]}" | sed 's/^# \?//'; exit 0 ;;
esac

FINDINGS=()
finding() { FINDINGS+=("$1"); }

###############################################################################
# 1. Agent models must be aliases
#
# A pinned snapshot (claude-sonnet-4-6) freezes the agent on a model from
# whenever the file was written. Nothing warns; the agent simply keeps getting
# worse relative to everything else.
###############################################################################
for f in "$PROJECT_DIR"/.claude/agents/*.md "$USER_DIR"/agents/*.md; do
    [ -f "$f" ] || continue
    model="$(grep -m1 '^model:' "$f" 2>/dev/null | sed 's/^model:[[:space:]]*//')"
    [ -n "$model" ] || continue
    case "$model" in
        sonnet|opus|haiku|fable|inherit) ;;
        *) finding "agent $(basename "$f" .md): model '$model' is pinned, use an alias" ;;
    esac
done

###############################################################################
# 1b. Agent frontmatter must survive the loader
#
# A plain `description:` broken by a REAL newline ends the scalar; YAML then
# reads the next line as a top-level key, the whole frontmatter fails, and the
# agent is dropped from the registry with no error anywhere. Found 2026-08-11:
# design-system-guardian and commercial-estimate-specialist had been dead this
# way for an unknown length of time. Multi-line values are fine as block
# scalars (`>-`, `|`); prose spanning bare lines is not.
###############################################################################
frontmatter_problems() {
    python3 - "$@" <<'PY' 2>/dev/null
import os, re, sys

KEY = re.compile(r'^[A-Za-z_][A-Za-z0-9_-]*:(\s|$)')
# A block scalar indicator is the ENTIRE rest of the line -- `>`, `|`, plus an
# optional chomping/indentation indicator. `description: >500 tenants...` is a
# plain scalar that merely starts with `>`, and must not buy indent permission.
BLOCK = re.compile(r'^[>|][+-]?[0-9]?[+-]?$')

for path in sys.argv[1:]:
    try:
        with open(path, encoding="utf-8") as fh:
            text = fh.read()
    except Exception:
        continue
    name = os.path.basename(path)[:-3]
    if not text.startswith("---\n"):
        print(f"{name}: no YAML frontmatter -- the agent will not load")
        continue
    end = text.find("\n---\n", 3)
    if end < 0:
        print(f"{name}: frontmatter is never closed by ---")
        continue
    # An indented line is legal only under a key that opened a block scalar
    # (`>`/`|`) or that has no inline value at all (a list or nested mapping).
    indent_ok = False
    for n, line in enumerate(text[4:end].split("\n"), start=2):
        if not line.strip():
            continue
        if line[0] in " \t":
            if not indent_ok:
                print(f"{name}: line {n} is indented under a key that takes an "
                      f"inline value -- frontmatter stops parsing here")
                break
            continue
        if not KEY.match(line):
            print(f"{name}: line {n} is neither a key nor a continuation -- "
                  f"frontmatter stops parsing here ({line.strip()[:48]!r})")
            break
        value = line.split(":", 1)[1].strip()
        indent_ok = value == "" or bool(BLOCK.match(value))
PY
}

while IFS= read -r problem; do
    [ -n "$problem" ] || continue
    finding "agent $problem"
done <<<"$(frontmatter_problems "$PROJECT_DIR"/.claude/agents/*.md "$USER_DIR"/agents/*.md)"

###############################################################################
# 2. `mcpServers` must not appear in any settings file
#
# It is not part of the settings schema. Claude Code ignores the block without
# error, so the server looks configured and never runs.
###############################################################################
for f in "$USER_DIR/settings.json" "$PROJECT_DIR/.claude/settings.json" "$PROJECT_DIR/.claude/settings.local.json"; do
    [ -f "$f" ] || continue
    if grep -q '"mcpServers"' "$f" 2>/dev/null; then
        finding "$f declares mcpServers -- not a valid settings key, silently ignored. Use: claude mcp add"
    fi
done

###############################################################################
# 3. Every MCP server an agent references must be configured
#
# Reads the config rather than calling the CLI, so this stays fast enough for
# every session start. Connectivity is checked separately below.
###############################################################################
configured_servers() {
    python3 - "$USER_DIR" "$PROJECT_DIR" <<'PY' 2>/dev/null
import json, os, sys
names = set()
for path in (
    os.path.join(os.path.expanduser("~"), ".claude.json"),
    os.path.join(sys.argv[2], ".mcp.json"),
):
    try:
        with open(path, encoding="utf-8") as fh:
            data = json.load(fh)
    except Exception:
        continue
    names.update((data.get("mcpServers") or {}).keys())
    for proj in (data.get("projects") or {}).values():
        if isinstance(proj, dict):
            names.update((proj.get("mcpServers") or {}).keys())
print("\n".join(sorted(names)))
PY
}

SERVERS="$(configured_servers)"
referenced="$(grep -rhoE 'mcp__[a-zA-Z0-9_-]+__' \
    "$PROJECT_DIR"/.claude/agents/*.md "$USER_DIR"/agents/*.md 2>/dev/null \
    | sed -E 's/^mcp__//; s/__$//' | sort -u)"

while IFS= read -r srv; do
    [ -n "$srv" ] || continue
    if ! printf '%s\n' "$SERVERS" | grep -qx "$srv"; then
        users="$(grep -rlE "mcp__${srv}__" "$PROJECT_DIR"/.claude/agents/*.md "$USER_DIR"/agents/*.md 2>/dev/null \
                 | xargs -r -n1 basename | sed 's/\.md$//' | paste -sd, -)"
        finding "MCP server '$srv' is referenced by agents ($users) but is not configured anywhere"
    fi
done <<<"$referenced"

###############################################################################
# 4. Hook commands must exist and be executable
#
# A hook whose script is missing does nothing and reports nothing.
###############################################################################
hook_commands() {
    python3 - "$@" <<'PY' 2>/dev/null
import json, sys
for path in sys.argv[1:]:
    try:
        with open(path, encoding="utf-8") as fh:
            data = json.load(fh)
    except Exception:
        continue
    for entries in (data.get("hooks") or {}).values():
        for entry in entries or []:
            for hook in entry.get("hooks") or []:
                cmd = hook.get("command")
                if cmd:
                    print(cmd)
PY
}

while IFS= read -r cmd; do
    [ -n "$cmd" ] || continue
    path="${cmd%% *}"
    path="${path//\"/}"
    path="${path//\$CLAUDE_PROJECT_DIR/$PROJECT_DIR}"
    path="${path//\$HOME/$HOME}"
    case "$path" in
        /*) ;;
        *) continue ;;
    esac
    if [ ! -f "$path" ]; then
        finding "hook script missing: $path"
    elif [ ! -x "$path" ]; then
        finding "hook script not executable: $path"
    fi
done < <(hook_commands "$USER_DIR/settings.json" \
                       "$PROJECT_DIR/.claude/settings.json" \
                       "$PROJECT_DIR/.claude/settings.local.json")

###############################################################################
# 5. Files a hook depends on must exist
#
# Heuristic, and deliberately so: a hook that guards its work with
# `if [ -f "$SOME_FILE" ]` goes quiet when the file is absent, which is the one
# case that should be loud. This catches the assignment-then-test shape, which
# is how session-start-context.sh stayed inert for months.
###############################################################################
for hook in "$USER_DIR"/hooks/*.sh "$PROJECT_DIR"/.claude/hooks/*.sh; do
    [ -f "$hook" ] || continue
    while IFS= read -r line; do
        var="${line%%=*}"
        grep -q "\[ -f \"\$$var\" \]" "$hook" 2>/dev/null || continue
        val="$(sed -n "s/^[[:space:]]*${var}=\"\(.*\)\"[[:space:]]*$/\1/p" "$hook" | head -1)"
        [ -n "$val" ] || continue
        case "$val" in *'$'[A-Za-z_]*) resolved="${val//\$HOME/$HOME}" ;; *) resolved="$val" ;; esac
        case "$resolved" in *'$'*) continue ;; esac
        [ -e "$resolved" ] || finding "$(basename "$hook") tests \$$var but $resolved does not exist -- that branch never runs"
    done < <(grep -oE '^[[:space:]]*[A-Z_]+="[^"]*"' "$hook" 2>/dev/null | sed 's/^[[:space:]]*//')
done

###############################################################################
# 6. Always-loaded rules must stay inside the budget
#
# TIER 1 is every rules file without `paths` frontmatter, so it enters context
# in every session. Past the budget, rules compete for attention and the ones
# below the fold get followed less, without anything saying so.
###############################################################################
tier1=0
for f in "$PROJECT_DIR"/.claude/rules/*.md; do
    [ -f "$f" ] || continue
    head -3 "$f" | grep -q '^paths:' || tier1=$(( tier1 + $(wc -c < "$f") ))
done
if [ "$tier1" -gt "$TIER1_BUDGET" ]; then
    finding "TIER 1 rules are ${tier1} chars, over the ${TIER1_BUDGET} budget -- narrow one with paths frontmatter"
fi

###############################################################################
# 7. MCP servers must actually connect
#
# `claude mcp list` runs real health checks and costs seconds, so it runs at
# most weekly. A missing stamp means it has never run, which counts as due
# rather than as nothing to do.
###############################################################################
mcp_due() {
    [ "$MODE" = "full" ] && return 0
    [ -f "$STAMP" ] || return 0
    local age=$(( ( $(date +%s) - $(stat -c %Y "$STAMP" 2>/dev/null || echo 0) ) / 86400 ))
    [ "$age" -ge "$MCP_MAX_AGE_DAYS" ]
}

if mcp_due && command -v claude >/dev/null 2>&1; then
    if out="$(timeout 60 claude mcp list 2>&1)"; then
        while IFS= read -r line; do
            case "$line" in
                *"✔ Connected"*) ;;
                *:*-*) finding "MCP server not healthy: ${line%% - *} (${line##* - })" ;;
            esac
        done < <(printf '%s\n' "$out" | grep -E '^[A-Za-z0-9._ -]+:.* - ')
        mkdir -p "$(dirname "$STAMP")" && touch "$STAMP"
    else
        finding "could not run 'claude mcp list' to verify server health"
    fi
fi

###############################################################################
# Report
###############################################################################
if [ "$MODE" = "hook" ]; then
    [ ${#FINDINGS[@]} -eq 0 ] && exit 0
    msg="cc-doctor found ${#FINDINGS[@]} configuration problem(s):"
    for f in "${FINDINGS[@]}"; do msg="${msg}"$'\n'"  - ${f}"; done
    msg="${msg}"$'\n'"Run scripts/cc-doctor.sh for detail."
    if command -v jq >/dev/null 2>&1; then
        jq -n --arg ctx "$msg" \
          '{hookSpecificOutput:{hookEventName:"SessionStart",additionalContext:$ctx}}'
    fi
    exit 1
fi

if [ ${#FINDINGS[@]} -eq 0 ]; then
    echo "cc-doctor: clean (TIER 1 ${tier1}/${TIER1_BUDGET} chars)"
    exit 0
fi

echo "cc-doctor: ${#FINDINGS[@]} problem(s)"
for f in "${FINDINGS[@]}"; do echo "  - $f"; done
exit 1
