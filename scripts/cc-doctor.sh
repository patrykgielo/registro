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
# Exit: 0 clean, 1 findings. In --hook mode: always 0 -- Claude Code only reads
# a hook's JSON on exit 0, so reporting findings with exit 1 delivered nothing.
#
# On the Windows desktop app this re-executes itself inside WSL (the shim in
# .claude/hooks/lib), where python3 lives. It then inspects the Windows clone's
# files through /mnt/c -- those are what Claude Code actually loads.
###############################################################################

set -uo pipefail

_DOCTOR_LIB="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/.claude/hooks/lib"
if [ -f "$_DOCTOR_LIB/wsl-forward.sh" ]; then
    # shellcheck source=../.claude/hooks/lib/wsl-forward.sh
    source "$_DOCTOR_LIB/wsl-forward.sh"
    registro_wsl_forward "${BASH_SOURCE[0]}" "$@"
fi

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# Forwarded from Windows, "home" is the WINDOWS profile: that is where Claude
# Code keeps ~/.claude and ~/.claude.json. WSL's own $HOME has neither.
USER_HOME="${REGISTRO_WIN_HOME:-$HOME}"
USER_DIR="${CLAUDE_CONFIG_DIR:-$USER_HOME/.claude}"
STAMP="$PROJECT_DIR/.claude/.cc-doctor-mcp-stamp"
TIER1_BUDGET=12000
MCP_MAX_AGE_DAYS=7

MODE="normal"
case "${1:-}" in
    --full) MODE="full" ;;
    --hook) MODE="hook" ;;
    --help|-h) sed -n '2,28p' "${BASH_SOURCE[0]}" | sed 's/^# \?//'; exit 0 ;;
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
    seq_ok = False  # the last key had no inline value: an UNINDENTED `- item` list may follow
    for n, line in enumerate(text[4:end].split("\n"), start=2):
        if not line.strip():
            continue
        # YAML allows a sequence to sit at the parent key's own column:
        #   disallowedTools:
        #   - Edit
        if seq_ok and (line.startswith("- ") or line.rstrip() == "-"):
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
        seq_ok = value == ""
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
    python3 - "$USER_DIR" "$PROJECT_DIR" "$USER_HOME" <<'PY' 2>/dev/null
import json, os, sys
names = set()
for path in (
    os.path.join(sys.argv[3], ".claude.json"),
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
# Without python3 the configured list above is empty, which would flag EVERY
# server as unconfigured. That is check 4d's finding, not forty false ones.
command -v python3 >/dev/null 2>&1 || referenced=""

while IFS= read -r srv; do
    [ -n "$srv" ] || continue
    if ! printf '%s\n' "$SERVERS" | grep -qx "$srv"; then
        users="$(grep -rlE "mcp__${srv}__" "$PROJECT_DIR"/.claude/agents/*.md "$USER_DIR"/agents/*.md 2>/dev/null \
                 | xargs -r -n1 basename | sed 's/\.md$//' | paste -sd, -)"
        finding "MCP server '$srv' is referenced by agents ($users) but is not configured anywhere"
    fi
done <<<"$referenced"

###############################################################################
# 3b. An agent that gets its tools by INHERITANCE must keep doing so
#
# ClickUp reaches this project as a claude.ai connector, whose tool names embed
# an account id (mcp__<account-id>__clickup_*) that differs per machine and so
# cannot be written into an agent file. clickup-task-manager therefore has NO
# `tools:` allowlist -- it inherits the session's -- and `disallowedTools` is
# what keeps it from writing files or running commands. Check 3 cannot see a
# connector (it reads ~/.claude.json and .mcp.json only), so this guards the two
# ways the arrangement silently breaks:
#   * a `tools:` line comes back: whatever names it lists either do not resolve
#     (the agent loses ClickUp without a word) or the agent loses inheritance;
#   * `disallowedTools` loses Edit/Write/Bash: an agent that was meant to be
#     read-only on the repository can now rewrite it.
# An absent agent file is not a finding: there is nothing to mislead anyone.
###############################################################################
INHERITING_AGENTS="clickup-task-manager"
for name in $INHERITING_AGENTS; do
    f="$PROJECT_DIR/.claude/agents/$name.md"
    [ -f "$f" ] || continue
    fm="$(awk 'NR==1 && $0=="---" {inside=1; next} inside && $0=="---" {exit} inside' "$f")"
    if printf '%s\n' "$fm" | grep -qE '^tools:'; then
        finding "agent $name has a 'tools:' allowlist -- it must inherit the session's tools (a connector's tool names embed an account id and cannot be listed); restrict with disallowedTools instead"
    fi
    # The key's own line plus its continuation lines: an indented `  - X`, or the
    # sequence at the key's own column (`- X`), which YAML also allows.
    deny="$(printf '%s\n' "$fm" | awk '/^disallowedTools:/ {on=1; print; next} on && (/^[[:space:]]/ || /^-([[:space:]]|$)/) {print; next} {on=0}')"
    if [ -z "$deny" ]; then
        finding "agent $name has no 'disallowedTools' -- with no tools allowlist it inherits Edit, Write and Bash too"
    else
        # One entry per line, exactly as written: strip the key, list dashes,
        # flow brackets, quotes and spaces. A SCOPED entry such as Bash(git push *)
        # stays `Bash(git push *)` and so is not the plain name. The docs say a
        # specifier in disallowedTools still removes the whole tool; this check
        # asks for the plain name anyway, so the intent is unambiguous and does not
        # depend on that reading holding.
        entries="$(printf '%s\n' "$deny" \
            | sed -E 's/^disallowedTools:[[:space:]]*//; s/^[[:space:]]*-([[:space:]]+|$)//' \
            | tr ',' '\n' | tr -d '[]' \
            | sed -E "s/^[[:space:]]+//; s/[[:space:]]+\$//; s/^[\"']//; s/[\"']\$//")"
        for t in Edit Write Bash; do
            if ! printf '%s\n' "$entries" | grep -qxF "$t"; then
                hint=""
                printf '%s\n' "$entries" | grep -qF "$t(" && hint=" (only a scoped '$t(...)' entry is listed -- list plain '$t')"
                finding "agent $name: disallowedTools does not deny $t -- it inherits it from the session${hint}"
            fi
        done
    fi
done

###############################################################################
# 3c. No agent may use `allowed-tools:` in its frontmatter
#
# `allowed-tools` is a skill / slash-command field. A subagent reads `tools` (an
# allowlist) and `disallowedTools`; anything else is ignored WITHOUT an error, so
# the agent inherits EVERY tool of the session -- Bash, Agent, Edit and every MCP
# connector (mail send, Drive share, ClickUp...). content-strategist carried the
# line and was listed as "Tools: All tools". Only the frontmatter is
# inspected: the words may well appear in an agent's body text.
###############################################################################
for f in "$PROJECT_DIR"/.claude/agents/*.md; do
    [ -f "$f" ] || continue
    fm="$(awk 'NR==1 && $0=="---" {inside=1; next} inside && $0=="---" {exit} inside' "$f")"
    if printf '%s\n' "$fm" | grep -qE '^allowed-tools:'; then
        finding "agent $(basename "$f" .md) uses 'allowed-tools:' in its frontmatter -- that field is ignored for subagents, so the agent inherits EVERY session tool (Bash, Agent, every MCP connector). Use 'tools:' (allowlist) / 'disallowedTools:'"
    fi
done

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

# Executable means: mode 100755 in the git index when the file is tracked. The
# Windows clone lives on NTFS, where /mnt/c reports every file as 777 and the
# exec bit of the working copy proves nothing; the index is what a Linux
# checkout of the same commit will get.
script_is_executable() {
    local rel mode
    rel="${1#"$PROJECT_DIR"/}"
    mode="$(git -c safe.directory='*' -C "$PROJECT_DIR" ls-files -s -- "$rel" 2>/dev/null | cut -c1-6)"
    if [ -n "$mode" ]; then
        [ "$mode" = 100755 ]
    else
        [ -x "$1" ]
    fi
}

SETTINGS_FILES=("$USER_DIR/settings.json"
                "$PROJECT_DIR/.claude/settings.json"
                "$PROJECT_DIR/.claude/settings.local.json")

while IFS= read -r cmd; do
    [ -n "$cmd" ] || continue
    path="${cmd//\"/}"
    path="${path#bash }"
    path="${path#sh }"
    path="${path%% *}"
    path="${path//\$\{CLAUDE_PROJECT_DIR\}/$PROJECT_DIR}"
    path="${path//\$CLAUDE_PROJECT_DIR/$PROJECT_DIR}"
    path="${path//\$HOME/$USER_HOME}"
    case "$path" in
        /*) ;;
        *) continue ;;
    esac
    if [ ! -f "$path" ]; then
        finding "hook script missing: $path"
    elif ! script_is_executable "$path"; then
        finding "hook script not executable (git mode is not 100755): $path"
    fi
done < <(hook_commands "${SETTINGS_FILES[@]}")

###############################################################################
# 4b. Settings files must be valid JSON
#
# hook_commands above skips a file it cannot parse, so a stray comma in
# settings.json would unwire every hook and say nothing.
###############################################################################
for f in "${SETTINGS_FILES[@]}"; do
    [ -f "$f" ] || continue
    if command -v python3 >/dev/null 2>&1 && ! python3 -c 'import json,sys; json.load(open(sys.argv[1], encoding="utf-8"))' "$f" 2>/dev/null; then
        finding "$f is not valid JSON -- Claude Code ignores the whole file, so everything it wires is off"
    fi
done

###############################################################################
# 4c. Every hook script must be wired, and wired once
#
# The hooks lived for months in a gitignored settings.local.json that did not
# exist on this machine: five scripts, a rules file claiming they enforced
# things, and nothing running. The wiring is now committed in
# .claude/settings.json. A script nobody calls is the same failure, so list
# them. The reverse mistake -- the same command in settings.json AND
# settings.local.json -- runs the hook twice, so that is flagged too.
###############################################################################
if [ ! -f "$PROJECT_DIR/.claude/settings.json" ]; then
    finding ".claude/settings.json is missing -- hooks are wired nowhere (it is committed; restore it from git)"
fi

ALL_WIRED="$(hook_commands "${SETTINGS_FILES[@]}")"
for hook in "$PROJECT_DIR"/.claude/hooks/*.sh "$PROJECT_DIR"/scripts/cc-doctor.sh; do
    [ -f "$hook" ] || continue
    printf '%s\n' "$ALL_WIRED" | grep -qF "$(basename "$hook")" ||
        finding "hook script exists but is wired in no settings file: ${hook#"$PROJECT_DIR"/}"
done

if [ -f "$PROJECT_DIR/.claude/settings.json" ] && [ -f "$PROJECT_DIR/.claude/settings.local.json" ]; then
    dups="$(sort <(hook_commands "$PROJECT_DIR/.claude/settings.json") \
                 <(hook_commands "$PROJECT_DIR/.claude/settings.local.json") | uniq -d)"
    while IFS= read -r d; do
        [ -n "$d" ] || continue
        finding "hook wired in BOTH settings.json and settings.local.json (it runs twice): $d"
    done <<<"$dups"
fi

###############################################################################
# 4c2. The PreToolUse guard must cover EVERY shell tool
#
# On the Windows desktop app the primary shell tool is PowerShell, not Bash. A
# matcher of just "Bash" leaves it entirely unguarded -- git commit, git push and
# migrate:fresh through it are never seen. Flag a pre-tool-use.sh entry whose
# matcher does not select both tools.
###############################################################################
if command -v python3 >/dev/null 2>&1; then
    while IFS= read -r problem; do
        [ -n "$problem" ] || continue
        finding "$problem"
    done < <(python3 - "${SETTINGS_FILES[@]}" <<'PY' 2>/dev/null
import json, re, sys
for path in sys.argv[1:]:
    try:
        with open(path, encoding="utf-8") as fh:
            data = json.load(fh)
    except Exception:
        continue
    for entry in (data.get("hooks") or {}).get("PreToolUse") or []:
        if not any("pre-tool-use.sh" in (h.get("command") or "") for h in entry.get("hooks") or []):
            continue
        m = entry.get("matcher", "")
        if m in ("", "*"):
            continue
        # Claude Code: letters/digits/_-,| and spaces are an exact list, anything else a regex.
        if re.fullmatch(r"[A-Za-z0-9_,| -]+", m):
            names = {n for n in re.split(r"[|, ]+", m) if n}
            covered = {"Bash", "PowerShell"} <= names
        else:
            try:
                covered = bool(re.fullmatch(m, "Bash") and re.fullmatch(m, "PowerShell"))
            except re.error:
                covered = False
        if not covered:
            print("%s: PreToolUse matcher '%s' for pre-tool-use.sh does not select BOTH Bash and PowerShell -- the PowerShell tool (primary shell on Windows) bypasses the guard entirely" % (path, m))
PY
)
fi

###############################################################################
# 4d. The hooks need a JSON parser where they RUN
#
# They used jq, which exists nowhere; `jq: command not found` inside $(...)
# leaves the variable empty, so pre-tool-use.sh read every command as empty and
# blocked nothing. They now use python3. Without it the guard fails closed on
# guarded commands, but that is a degraded state worth a loud line.
###############################################################################
if [ -f "$_DOCTOR_LIB/json.sh" ]; then
    # shellcheck source=../.claude/hooks/lib/json.sh
    source "$_DOCTOR_LIB/json.sh"
    if ! hook_python; then
        finding "no python3 in $(uname -s)${REGISTRO_HOOK_ORIGIN:+ (WSL, forwarded)}: hooks cannot read their input. PreToolUse now DENIES guarded commands and skips the rest. Fix: sudo apt install python3"
    fi
else
    finding "hook library missing: .claude/hooks/lib/json.sh -- every hook fails to start"
fi

###############################################################################
# 4e. Windows clone drift (manual runs only)
#
# Claude Code on the Windows desktop app loads CLAUDE.md and rules from a
# mirror clone that falls behind the WSL tree. session-sync.sh fixes and
# reports this at session start; --hook mode leaves it to that script so the
# two do not race and report twice. Manual runs check it here too.
###############################################################################
if [ "$MODE" != "hook" ]; then
    # REGISTRO_WIN_CLONE set but EMPTY means "there is no Windows clone" (tests,
    # pure-Linux machines); only an UNSET variable falls back to probing.
    CLONE="${REGISTRO_WIN_CLONE:-}"
    if [ -z "$CLONE" ] && [ -z "${REGISTRO_WIN_CLONE+x}" ]; then
        case "$PROJECT_DIR" in /mnt/*) CLONE="$PROJECT_DIR" ;; esac
        [ -n "$CLONE" ] || { [ -d /mnt/c/projects/registro/.git ] && CLONE=/mnt/c/projects/registro; }
    fi
    WSL_TREE="${REGISTRO_WSL_TREE:-/var/www/projects/registro/app}"
    if [ -n "$CLONE" ]; then
        if [ ! -d "$WSL_TREE" ]; then
            finding "WSL tree $WSL_TREE does not exist -- Windows-clone drift cannot be checked (REGISTRO_WSL_TREE)"
        else
            cg() { git -c safe.directory='*' -C "$CLONE" "$@"; }
            norm() { awk -F'\t' '{ split($1, a, " "); print $2 "\t" a[3] }' | sort; }
            clone_ls="$(cg ls-tree -r HEAD -- .claude CLAUDE.md 2>/dev/null | norm)"
            tree_ls="$(git -c safe.directory='*' -C "$WSL_TREE" ls-tree -r HEAD -- .claude CLAUDE.md 2>/dev/null | norm)"
            if [ -z "$clone_ls" ] || [ -z "$tree_ls" ]; then
                finding "could not read .claude/ from the clone ($CLONE) or the WSL tree ($WSL_TREE) -- drift NOT checked"
            elif [ "$clone_ls" != "$tree_ls" ]; then
                # Only meaningful when both sit on the SAME branch. The WSL tree is
                # usually on a feature branch whose .claude/ legitimately differs
                # from the clone's develop -- that is not drift, and flagging it
                # would make every run noisy.
                tree_branch="$(git -c safe.directory='*' -C "$WSL_TREE" symbolic-ref --short -q HEAD 2>/dev/null || true)"
                clone_branch="$(cg symbolic-ref --short -q HEAD 2>/dev/null || true)"
                if [ -n "$tree_branch" ] && [ "$tree_branch" = "$clone_branch" ]; then
                    diffs="$(diff <(printf '%s\n' "$clone_ls") <(printf '%s\n' "$tree_ls") | grep '^[<>]' | cut -c3- | cut -f1 | sort -u | head -8 | paste -sd, -)"
                    finding "Windows clone's .claude/ + CLAUDE.md differ from the WSL tree (both on $tree_branch): $diffs"
                fi
            fi
            behind="$(cg rev-list --count 'HEAD..@{u}' 2>/dev/null)" || behind=""
            if [[ "$behind" =~ ^[0-9]+$ ]] && [ "$behind" -gt 0 ]; then
                finding "Windows clone is $behind commit(s) behind its upstream (as of its last fetch) -- the next session start fast-forwards it"
            fi
        fi
    fi
fi

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
    # No jq: it exists nowhere we run, and its absence used to make this block
    # print nothing at all -- the findings were computed and then dropped.
    # Both fields: additionalContext reaches Claude, systemMessage the user.
    esc="${msg//\\/\\\\}"; esc="${esc//\"/\\\"}"; esc="${esc//$'\n'/\\n}"; esc="${esc//$'\t'/\\t}"
    printf '{"systemMessage":"%s","hookSpecificOutput":{"hookEventName":"SessionStart","additionalContext":"%s"}}\n' "$esc" "$esc"
    exit 0 # Claude Code reads a hook's JSON only on exit 0
fi

if [ ${#FINDINGS[@]} -eq 0 ]; then
    echo "cc-doctor: clean (TIER 1 ${tier1}/${TIER1_BUDGET} chars)"
    exit 0
fi

echo "cc-doctor: ${#FINDINGS[@]} problem(s)"
for f in "${FINDINGS[@]}"; do echo "  - $f"; done
exit 1
