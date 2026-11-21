#!/bin/bash
###############################################################################
# Pins: no agent file may name an MCP tool whose server is not configured, and
# an agent that gets ClickUp BY INHERITANCE must keep inheriting.
#
# THE FAILURE (2026-11-21): nine agents listed mcp__firecrawl__* and one listed
# seventeen mcp__clickup__* names. This machine has no MCP server configured at
# all; ClickUp arrives as a claude.ai connector whose tool names embed an account
# id (mcp__<account-id>__clickup_*), so the hardcoded names could never resolve.
# cc-doctor reported it ("MCP server 'clickup' is referenced by agents ... but is
# not configured"). The fix removed the references; it must not have weakened the
# check, so this case proves BOTH directions:
#   * the agent set shipped in this repo is clean;
#   * the same mistake, re-introduced, is flagged again (an unconfigured server in
#     `tools`, a hardcoded connector id, a `tools` allowlist back on the inheriting
#     agent, a `disallowedTools` that no longer keeps it away from Edit/Write/Bash).
# A configured server is NOT flagged -- the check is "is it configured", not
# "is MCP forbidden".
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "cc-doctor.sh: agents may only name configured MCP servers; the ClickUp agent must inherit"

sandbox_init

P="$SANDBOX/proj"
USERDIR="$SANDBOX/userdir"
HOMEDIR="$SANDBOX/home"
mkdir -p "$USERDIR" "$HOMEDIR"

build() { # a fresh, committed project carrying THIS repo's real agents
    rm -rf "$P" "$HOMEDIR/.claude.json"
    mkdir -p "$P/scripts" "$P/.claude/hooks" "$P/.claude/agents"
    new_repo "$P" develop
    cp -r "$REPO_ROOT/.claude/hooks/." "$P/.claude/hooks/"
    cp "$REPO_ROOT/.claude/settings.json" "$P/.claude/settings.json"
    cp "$REPO_ROOT/.claude/agents/"*.md "$P/.claude/agents/"
    cp "$REPO_ROOT/scripts/cc-doctor.sh" "$P/scripts/cc-doctor.sh"
    chmod +x "$P"/.claude/hooks/*.sh "$P"/.claude/hooks/lib/*.sh "$P"/scripts/cc-doctor.sh
    git -C "$P" add -A && git -C "$P" commit -qm project >/dev/null
    touch "$P/.claude/.cc-doctor-mcp-stamp" # MCP health is checked weekly; not what this tests
}

doctor() { # -> OUT, RC
    OUT="$(env -u REGISTRO_HOOK_ORIGIN -u REGISTRO_HOOK_ASSUME_WINDOWS HOME="$HOMEDIR" \
        CLAUDE_CONFIG_DIR="$USERDIR" REGISTRO_WIN_CLONE= bash "$P/scripts/cc-doctor.sh" 2>&1)"
    RC=$?
}

AG="$P/.claude/agents"

# Replace the whole `key:` line of an agent's frontmatter (or insert after the
# first line when the key is absent). VALUE empty = delete the line.
set_fm() { # FILE KEY VALUE
    python3 - "$1" "$2" "$3" <<'PY'
import sys
path, key, value = sys.argv[1:4]
lines = open(path, encoding="utf-8").read().split("\n")
end = lines.index("---", 1)
head = [l for l in lines[1:end] if not l.startswith(key + ":")]
if value:
    head.append(f"{key}: {value}")
open(path, "w", encoding="utf-8", newline="").write("\n".join(["---"] + head + lines[end:]))
PY
}

# --- 0. the agents shipped in this repo are clean ---------------------------------------
build
doctor
assert_eq "0" "$RC" "shipped agents: exit 0"
assert_contains "$OUT" "cc-doctor: clean" "shipped agents: reported clean"
assert_not_contains "$OUT" "MCP server" "shipped agents: no agent names an unconfigured MCP server"
assert_eq "0" "$(grep -rlE 'mcp__[A-Za-z0-9_-]+__' "$AG" | wc -l | tr -d ' ')" "shipped agents: no mcp__<server>__ name appears in any agent file"

# --- 1. the clickup agent really is the inheriting shape the doctor expects ----------------
if grep -q '^tools:' "$AG/clickup-task-manager.md"; then
    fail "clickup-task-manager has a tools: allowlist -- it must inherit"
fi
DENY="$(grep '^disallowedTools:' "$AG/clickup-task-manager.md")"
for t in Edit Write Bash; do
    assert_contains "$DENY" "$t" "clickup-task-manager denies $t"
done

# --- 2. the original mistake, re-introduced, is flagged again -----------------------------
build
set_fm "$AG/laravel-senior-architect.md" tools "Read, Grep, mcp__firecrawl__firecrawl_scrape, WebSearch"
doctor
assert_eq "1" "$RC" "unconfigured server in a tools list: findings"
assert_contains "$OUT" "MCP server 'firecrawl' is referenced by agents (laravel-senior-architect)" "...the server and the agent are named"

build
set_fm "$AG/commercial-estimate-specialist.md" tools "Read, mcp__clickup__clickup_search"
doctor
assert_contains "$OUT" "MCP server 'clickup' is referenced by agents (commercial-estimate-specialist)" "mcp__clickup__ is flagged on a machine with no 'clickup' server"

# A connector's account id hardcoded into the agent: the very thing the inheritance design avoids.
build
set_fm "$AG/clickup-task-manager.md" tools "Read, mcp__c9b7629f-31e1-493e-8873-57717c22d926__clickup_create_task"
doctor
assert_contains "$OUT" "MCP server 'c9b7629f-31e1-493e-8873-57717c22d926' is referenced by agents (clickup-task-manager)" "a hardcoded connector id is flagged by check 3"
assert_contains "$OUT" "agent clickup-task-manager has a 'tools:' allowlist" "...and the allowlist itself by check 3b"

# --- 3. the inheriting agent: allowlist back, deny list gone or incomplete -------------------
build
set_fm "$AG/clickup-task-manager.md" tools "Read, Grep, Glob"
doctor
assert_eq "1" "$RC" "tools allowlist back on the clickup agent: findings"
assert_contains "$OUT" "agent clickup-task-manager has a 'tools:' allowlist" "an allowlist that lists no mcp name is still flagged (it ends inheritance)"

build
set_fm "$AG/clickup-task-manager.md" disallowedTools ""
doctor
assert_eq "1" "$RC" "no disallowedTools: findings"
assert_contains "$OUT" "agent clickup-task-manager has no 'disallowedTools'" "with no allowlist and no deny list the agent inherits Edit/Write/Bash -- flagged"

build
set_fm "$AG/clickup-task-manager.md" disallowedTools "Edit, Write, NotebookEdit, Agent"
doctor
assert_contains "$OUT" "disallowedTools does not deny Bash" "a deny list that forgot Bash is flagged by name"
assert_not_contains "$OUT" "does not deny Edit" "...and only for what is actually missing"
assert_not_contains "$OUT" "does not deny Write" "...Write is denied, so no finding for it"

build
set_fm "$AG/clickup-task-manager.md" disallowedTools "Agent"
doctor
assert_contains "$OUT" "does not deny Edit" "a deny list of just Agent: Edit flagged"
assert_contains "$OUT" "does not deny Write" "...Write flagged"
assert_contains "$OUT" "does not deny Bash" "...Bash flagged"

# YAML list form of disallowedTools is as valid as the comma string.
build
python3 - "$AG/clickup-task-manager.md" <<'PY'
import sys
p = sys.argv[1]
t = open(p, encoding="utf-8").read()
lines = t.split("\n")
i = next(n for n, l in enumerate(lines) if l.startswith("disallowedTools:"))
lines[i:i + 1] = ["disallowedTools:", "  - Edit", "  - Write", "  - NotebookEdit", "  - Bash", "  - Agent"]
open(p, "w", encoding="utf-8", newline="").write("\n".join(lines))
PY
doctor
assert_eq "0" "$RC" "disallowedTools as a YAML list: exit 0"
assert_contains "$OUT" "cc-doctor: clean" "disallowedTools as a YAML list: accepted (and the frontmatter still parses)"

# YAML also allows the sequence at the key's own column. It is valid, Claude Code reads it,
# and neither check 1b nor check 3b may call it a problem (before: 1b said "neither a key
# nor a continuation", 3b saw no entries at all and reported all three tools undenied).
build
python3 - "$AG/clickup-task-manager.md" <<'PY'
import sys
p = sys.argv[1]
lines = open(p, encoding="utf-8").read().split("\n")
i = next(n for n, l in enumerate(lines) if l.startswith("disallowedTools:"))
lines[i:i + 1] = ["disallowedTools:", "- Edit", "- Write", "- NotebookEdit", "- Bash", "- Agent"]
open(p, "w", encoding="utf-8", newline="").write("\n".join(lines))
PY
doctor
assert_eq "0" "$RC" "unindented YAML sequence under disallowedTools: exit 0"
assert_contains "$OUT" "cc-doctor: clean" "unindented YAML sequence: accepted by check 1b and check 3b"

# ...and an unindented sequence that really is missing a tool is still caught.
python3 - "$AG/clickup-task-manager.md" <<'PY'
import sys
p = sys.argv[1]
t = open(p, encoding="utf-8").read().replace("- Bash\n", "")
open(p, "w", encoding="utf-8", newline="").write(t)
PY
doctor
assert_contains "$OUT" "disallowedTools does not deny Bash" "unindented sequence without Bash: Bash flagged"
assert_not_contains "$OUT" "does not deny Edit" "...Edit (present in the sequence) is not"

# Flow-style and quoted entries are the same list.
build
set_fm "$AG/clickup-task-manager.md" disallowedTools '[Edit, "Write", NotebookEdit, Bash, Agent]'
doctor
assert_eq "0" "$RC" "disallowedTools as a flow list with a quoted entry: exit 0"

# A SCOPED entry is not the plain tool: Bash(git push *) is not "Bash is denied".
build
set_fm "$AG/clickup-task-manager.md" disallowedTools "Edit, Write, NotebookEdit, Bash(git push *), Agent"
doctor
assert_eq "1" "$RC" "Bash(git push *) in place of Bash: findings"
assert_contains "$OUT" "disallowedTools does not deny Bash" "a scoped Bash(...) entry does not count as denying Bash"
assert_contains "$OUT" "only a scoped 'Bash(...)' entry is listed -- list plain 'Bash'" "...and the finding says why"
assert_not_contains "$OUT" "does not deny Edit" "...while the plain entries still count"

build
python3 - "$AG/clickup-task-manager.md" <<'PY'
import sys
p = sys.argv[1]
lines = open(p, encoding="utf-8").read().split("\n")
i = next(n for n, l in enumerate(lines) if l.startswith("disallowedTools:"))
lines[i:i + 1] = ["disallowedTools:", "  - Edit", "  - Write", "  - 'Bash(*)'", "  - Agent"]
open(p, "w", encoding="utf-8", newline="").write("\n".join(lines))
PY
doctor
assert_contains "$OUT" "disallowedTools does not deny Bash" "a scoped Bash(...) entry in list form does not count either"

# --- 4. a CONFIGURED server is not flagged: the check is "configured", not "forbidden" -------
build
printf '{"mcpServers":{"ghost":{"type":"http","url":"https://example.invalid/mcp"}}}\n' >"$HOMEDIR/.claude.json"
set_fm "$AG/web-research-specialist.md" tools "Read, mcp__ghost__lookup"
doctor
assert_not_contains "$OUT" "MCP server 'ghost'" "an agent naming a configured server is not flagged"
assert_eq "0" "$RC" "configured server: exit 0"

# --- 5. the absent agent is not a finding (nothing to mislead anyone) -------------------------
build
git -C "$P" rm -q -f .claude/agents/clickup-task-manager.md
doctor
assert_not_contains "$OUT" "clickup-task-manager" "no clickup agent file: nothing to check, nothing reported"

test_finish
