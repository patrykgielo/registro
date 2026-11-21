#!/bin/bash
###############################################################################
# Pins: no agent may carry `allowed-tools:` in its frontmatter (cc-doctor 3c).
#
# THE FAILURE (2026-11-21): content-strategist declared
# `allowed-tools: Read, Write, Grep, Glob, WebSearch, WebFetch`. That is a
# skill / slash-command field. A subagent reads `tools` and `disallowedTools`, so
# the line was ignored without a word and the agent inherited EVERY session tool:
# Bash, Agent, Edit and every MCP connector (mail send, Drive share, ClickUp).
# The session's own agent listing showed "Tools: All tools". Nothing flagged it.
#
# Proved in both directions:
#   * the agents shipped in this repo carry no such key;
#   * the key in the FRONTMATTER is flagged, by agent name;
#   * `tools:` is not flagged, and neither is the phrase `allowed-tools` merely
#     mentioned in an agent's BODY (or in a file with no frontmatter at all).
###############################################################################
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../lib/harness.sh"
test_start "cc-doctor.sh: an agent's frontmatter must not use 'allowed-tools:' (ignored for subagents)"

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
MARK="uses 'allowed-tools:' in its frontmatter"

write_agent() { # NAME FRONTMATTER-LINE BODY-LINE
    printf -- '---\nname: %s\ndescription: fixture\nmodel: sonnet\n%s\n---\n\n# %s\n\n%s\n' \
        "$1" "$2" "$1" "$3" >"$AG/$1.md"
}

# --- 0. the agents shipped in this repo are clean ------------------------------------------
assert_eq "0" "$(grep -lE '^allowed-tools:' "$REPO_ROOT"/.claude/agents/*.md 2>/dev/null | wc -l | tr -d ' ')" \
    "repo agents: no file has an allowed-tools: line at all"
build
doctor
assert_not_contains "$OUT" "allowed-tools" "shipped agents: no allowed-tools finding"
assert_eq "0" "$RC" "shipped agents: exit 0"
assert_contains "$OUT" "cc-doctor: clean" "shipped agents: reported clean"
# content-strategist is the agent that had it: it must now carry a real allowlist, and no Bash/Agent.
CS="$(grep -E '^tools:' "$REPO_ROOT/.claude/agents/content-strategist.md")"
assert_contains "$CS" "Read" "content-strategist has a tools: allowlist"
assert_not_contains "$CS" "Bash" "...without Bash"
assert_not_contains "$CS" "Agent" "...without Agent"
assert_not_contains "$CS" "mcp__" "...without any MCP tool"

# --- 1. the mistake, re-introduced, is flagged --------------------------------------------
build
write_agent fixture-bad 'allowed-tools: Read, Write, Grep' 'Piszesz drafty.'
doctor
assert_eq "1" "$RC" "allowed-tools in frontmatter: findings"
assert_contains "$OUT" "agent fixture-bad $MARK" "...the agent is named"
assert_contains "$OUT" "ignored for subagents" "...and the finding says why"
assert_contains "$OUT" "inherits EVERY session tool" "...and what it costs"
assert_contains "$OUT" "'tools:' (allowlist) / 'disallowedTools:'" "...and what to write instead"

# the exact original line, put back on the real agent
build
sed -i 's/^tools: /allowed-tools: /' "$AG/content-strategist.md"
doctor
assert_eq "1" "$RC" "the original content-strategist line back: findings"
assert_contains "$OUT" "agent content-strategist $MARK" "...flagged on the real agent file"

# --- 2. `tools:` is the right field and is not flagged --------------------------------------
build
write_agent fixture-good 'tools: Read, Write, Grep' 'Piszesz drafty.'
doctor
assert_not_contains "$OUT" "fixture-good" "tools: in frontmatter: no finding for that agent"
assert_not_contains "$OUT" "allowed-tools" "...no allowed-tools finding at all"
assert_eq "0" "$RC" "tools: fixture: exit 0"

# --- 3. the words in the BODY are not frontmatter -------------------------------------------
build
write_agent fixture-body 'tools: Read, Grep' 'allowed-tools: to pole skilli, nie agenta.'
doctor
assert_not_contains "$OUT" "fixture-body" "allowed-tools only in the body: no finding"
assert_eq "0" "$RC" "body-only mention: exit 0"

# a file with no frontmatter at all has no frontmatter key to flag
build
printf '# no frontmatter\n\nallowed-tools: Read\n' >"$AG/fixture-bare.md"
doctor
assert_not_contains "$OUT" "agent fixture-bare $MARK" "no frontmatter: the body line is not a key"

# --- 4. only the offender is named ------------------------------------------------------------
build
write_agent fixture-bad 'allowed-tools: Read' 'x'
write_agent fixture-good 'tools: Read' 'x'
doctor
assert_contains "$OUT" "agent fixture-bad $MARK" "mixed set: the offender is flagged"
assert_not_contains "$OUT" "agent fixture-good" "mixed set: the compliant agent is not"

test_finish
