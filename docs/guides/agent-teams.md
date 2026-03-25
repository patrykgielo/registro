# Agent Teams & Multi-Agent Configuration

**Added:** 2026-03-25
**Status:** Agent Teams = experimental | Subagent upgrades = active
**Requires:** Claude Code v2.1.32+

---

## Overview

Registro uses two levels of multi-agent work:

1. **Subagents** (stable) — 10 custom agents in `.claude/agents/`, spawned within a session
2. **Agent Teams** (experimental) — separate Claude Code instances working in parallel

---

## Subagent Configuration

### Effort Levels

| Agent | Effort | Why |
|-------|--------|-----|
| `laravel-senior-architect` | high | Deep architecture analysis |
| `frontend-ui-architect` | high | Complex UI decisions |
| `project-coordinator` | high | Multi-domain orchestration |
| `agent-security-audit-specialist` | high | Thorough OWASP audit |
| `web-research-specialist` | medium | Balance speed/quality |
| `design-system-guardian` | medium | Token validation |
| `commercial-estimate-specialist` | medium | Analysis + formatting |
| `content-strategist` | medium | Creative + research |
| `frontend-quality-auditor` | low | Mechanical audit (haiku) |
| `clickup-task-manager` | low | Simple API calls |

### Memory

Agents with `memory: project` persist knowledge between sessions in `.claude/agent-memory/<name>/MEMORY.md`.

| Agent | Memory | Scope |
|-------|--------|-------|
| `laravel-senior-architect` | project | Architecture patterns, model relationships |
| `frontend-ui-architect` | project | Design tokens, component patterns |
| `agent-security-audit-specialist` | project | Security incidents, audit findings |
| `web-research-specialist` | user | Cross-project research knowledge |

### Worktree Isolation

Agents with `isolation: worktree` work in a temporary git worktree — no conflicts with main working tree:

- `laravel-senior-architect` — isolated code implementation
- `frontend-ui-architect` — isolated UI changes

### Quality Gate (SubagentStop hook)

When `laravel-senior-architect` or `frontend-ui-architect` finishes, hook runs:
1. Pint style check
2. PHPUnit tests
3. Exit 2 (block) if new failures detected

Script: `.claude/hooks/subagent-quality-gate.sh`

---

## Agent Teams (Experimental)

### What is it?

Multiple Claude Code instances (teammates) working in parallel, communicating through file-based mailboxes.

### How to enable

Already enabled in `.claude/settings.local.json`:
```json
"env": {
  "CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS": "1"
}
```

### Controls

| Key | Action |
|-----|--------|
| `Shift+Down` | Cycle between teammates |
| `Ctrl+T` | Toggle shared task list |
| `Enter` | View teammate session |
| `Escape` | Interrupt teammate |

### When to use

| Scenario | Use Teams? |
|----------|-----------|
| Quick bug fix | No — overkill |
| Single-file change | No |
| Large feature (backend + frontend + tests) | Yes — parallel work |
| Research + simultaneous implementation | Yes |
| Code review + fix | Yes |

### Cost warning

Token cost grows **linearly** with teammate count. 3 teammates = ~3x tokens. Use max 3 teammates.

### Example prompt

```
Create a team of 3:
1. Backend agent: implement the payment API endpoint
2. Frontend agent: build the payment form UI
3. Test agent: write tests for the payment flow

They should coordinate via the shared task list.
```

---

## File Locations

| File | Purpose |
|------|---------|
| `.claude/agents/*.md` | Agent definitions (10 files) |
| `.claude/agent-memory/*/MEMORY.md` | Persistent agent knowledge |
| `.claude/hooks/subagent-quality-gate.sh` | Quality gate for SubagentStop |
| `.claude/settings.local.json` | Agent Teams env var + hooks |
