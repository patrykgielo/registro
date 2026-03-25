# Development Team — Agent Configuration

**Updated:** 2026-03-25
**Agents:** 13 (4 layers)
**Agent Teams:** experimental (enabled)

---

## Team Structure (4 Layers, 13 Agents)

### Layer 1: Implementation (write code)

| Agent | Effort | Memory | Isolation | Scope |
|-------|--------|--------|-----------|-------|
| `laravel-senior-architect` | high | project | worktree | Backend: models, services, controllers, Filament (logic/auth) |
| `frontend-ui-architect` | high | project | worktree | Frontend: Blade, Tailwind, Alpine.js, Filament (UI/UX/a11y) |
| `test-engineer` | high | project | — | Tests: PHPUnit, factories, TDD-first |
| `devops-engineer` | medium | — | — | CI/CD, Docker, deployment, .env |

### Layer 2: Quality Gates (read-only, review)

| Agent | Effort | Tools | Scope |
|-------|--------|-------|-------|
| `code-reviewer` | high | Read, Grep, Glob, Bash | Post-implementation review (architecture, security, tokens, tests) |
| `agent-security-audit-specialist` | high | + Edit, Write | OWASP, GDPR, Laravel security |
| `frontend-quality-auditor` | low (haiku) | Read, Grep, Glob, Bash | Animation perf, a11y, design tokens |
| `design-system-guardian` | medium | + Edit, Write | Token compliance, OKLCH sync |

### Layer 3: Research & Support

| Agent | Effort | Scope |
|-------|--------|-------|
| `web-research-specialist` | medium | External knowledge, docs, competitive analysis |
| `project-coordinator` | high | Orchestration, cross-domain tasks, enforces review gates |
| `content-strategist` | medium | Polish dev content (tweets, LinkedIn) |

### Layer 4: Business Tools

| Agent | Effort | Scope |
|-------|--------|-------|
| `clickup-task-manager` | low | Task creation, time tracking |
| `commercial-estimate-specialist` | medium | Client-ready pricing estimates |

---

## How Agents Collaborate — Real Example

### Scenario: "Add payment integration to rental system"

```
Step 1: RESEARCH
┌─────────────────────────────────────────────────┐
│ web-research-specialist                         │
│ → Research Polish payment providers (Przelewy24,│
│   PayU, Stripe PL). Compare APIs, fees, Laravel │
│   packages. Return recommendations.             │
└─────────────────┬───────────────────────────────┘
                  │ findings
                  ▼
Step 2: ARCHITECTURE
┌─────────────────────────────────────────────────┐
│ laravel-senior-architect                        │
│ → Design payment service, migrations, model     │
│   changes. Define PaymentService interface,     │
│   Filament Resource (logic + auth).             │
│   Delegates: UI polish → frontend-ui-architect  │
│              Tests → test-engineer              │
└──────┬──────────────────────┬───────────────────┘
       │ code                 │ test specs
       ▼                     ▼
Step 3: PARALLEL IMPLEMENTATION
┌──────────────────┐  ┌──────────────────┐
│ frontend-ui-      │  │ test-engineer    │
│ architect         │  │                  │
│ → Payment form    │  │ → Feature tests  │
│   UI, Alpine.js   │  │   for payment    │
│   validation,     │  │   flow, unit     │
│   success/error   │  │   tests for      │
│   states          │  │   pricing logic  │
└────────┬─────────┘  └────────┬─────────┘
         │                     │
         ▼                     ▼
Step 4: QUALITY GATES
┌─────────────────────────────────────────────────┐
│ code-reviewer (READ-ONLY)                       │
│ → Review ALL changed files:                     │
│   - Architecture: SOLID? Service pattern?       │
│   - Security: payment data handling?            │
│   - Tokens: hardcoded colors?                   │
│   - Tests: coverage adequate?                   │
│   - Multi-tenant: scoped to organization?       │
│                                                 │
│ Output: Critical/Warning/Suggestion list        │
│ If Critical → back to implementer               │
│ If clean → proceed to commit                    │
└──────────────────────┬──────────────────────────┘
                       │
                       ▼
Step 5: COMMIT + PR
┌─────────────────────────────────────────────────┐
│ Orchestrator (main Claude Code session)         │
│ → Show diff to user                             │
│ → User approves → git commit → gh pr create     │
└─────────────────────────────────────────────────┘
```

### Scenario: "Fix bug in rental hold expiration"

```
Step 1: DIAGNOSE
┌─────────────────────────────────────────────────┐
│ laravel-senior-architect (Explore mode first)   │
│ → Read RentalAvailabilityService, Rental model  │
│ → Identify root cause                           │
│ → Propose fix                                   │
└──────────────────────┬──────────────────────────┘
                       │
Step 2: FIX + TEST (parallel)
┌──────────────────┐  ┌──────────────────┐
│ laravel-senior-  │  │ test-engineer    │
│ architect        │  │                  │
│ → Apply fix      │  │ → Regression test│
│                  │  │   (reproduce bug │
│                  │  │    as failing    │
│                  │  │    test first)   │
└────────┬─────────┘  └────────┬─────────┘
         │                     │
Step 3: REVIEW
┌─────────────────────────────────────────────────┐
│ code-reviewer → verify fix is correct           │
│ SubagentStop hook → Pint + tests pass           │
└─────────────────────────────────────────────────┘
```

### Scenario: "Prepare for production deployment"

```
┌──────────────────┐  ┌──────────────────┐  ┌──────────────────┐
│ devops-engineer  │  │ security-audit   │  │ frontend-quality │
│                  │  │ specialist       │  │ auditor          │
│ → Validate .env  │  │ → Full OWASP     │  │ → a11y scan      │
│ → Check Docker   │  │   scan           │  │ → perf audit     │
│ → Review CI/CD   │  │ → Auth flow      │  │ → token check    │
│ → Health checks  │  │ → Input valid.   │  │ → animation perf │
└────────┬─────────┘  └────────┬─────────┘  └────────┬─────────┘
         │                     │                      │
         └─────────────────────┼──────────────────────┘
                               │
                    ┌──────────▼──────────┐
                    │ production readiness│
                    │ report to user      │
                    └─────────────────────┘
```

---

## Filament Ownership Split

| Aspect | Agent |
|--------|-------|
| Resource definition, table columns, filters | `laravel-senior-architect` |
| Authorization policies, data queries | `laravel-senior-architect` |
| Form actions (business logic) | `laravel-senior-architect` |
| Form layout, field grouping, UX | `frontend-ui-architect` |
| Field styling, responsive tables | `frontend-ui-architect` |
| Accessibility (ARIA, keyboard) | `frontend-ui-architect` |

**Handoff rule:** Architect creates Resource with schema + auth, then frontend polishes UI/UX.

---

## Agent Memory

| Agent | Memory | Path | What it stores |
|-------|--------|------|----------------|
| `laravel-senior-architect` | project | `.claude/agent-memory/laravel-senior-architect/` | Architecture patterns, model relationships, Filament v4 gotchas |
| `frontend-ui-architect` | project | `.claude/agent-memory/frontend-ui-architect/` | Design tokens, component patterns, migration state |
| `test-engineer` | project | `.claude/agent-memory/test-engineer/` | Pre-existing failures, factory states, SQLite constraints |
| `agent-security-audit-specialist` | project | `.claude/agent-memory/agent-security-audit-specialist/` | Security incidents, audit findings |
| `web-research-specialist` | user | `~/.claude/agent-memory/web-research-specialist/` | Cross-project research knowledge |

---

## Quality Gates

### Automatic (hooks)

| Hook | Trigger | What it does |
|------|---------|--------------|
| `SubagentStop` | After laravel-architect or frontend-architect finishes | Runs Pint + tests. Exit 2 = block on new failures. |
| `PreToolUse` | Before any Bash command | Blocks dangerous git ops, destructive DB commands |
| `Stop` | Before ending session | Blocks if 5+ files changed with 0 docs updates |

### Manual (orchestrator enforces)

| Step | Who | When |
|------|-----|------|
| `code-reviewer` | Orchestrator spawns after implementation | After ANY code changes |
| `frontend-quality-auditor` | Orchestrator spawns for UI changes | After Blade/CSS changes |
| `design-system-guardian` | On demand | When token compliance questioned |

---

## Agent Teams (Experimental)

Enabled: `CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS=1`

Separate Claude Code instances working in parallel. Use for large features (3 teammates max). Controls: `Shift+Down` (cycle), `Ctrl+T` (task list).

---

## File Locations

| File | Purpose |
|------|---------|
| `.claude/agents/*.md` | Agent definitions (13 files) |
| `.claude/agent-memory/*/MEMORY.md` | Persistent agent knowledge |
| `.claude/hooks/subagent-quality-gate.sh` | SubagentStop quality gate |
| `.claude/settings.local.json` | Agent Teams env var + hooks |
| `.claude/rules/agent-usage.md` | When to use which agent |
| `.claude/skills/frontend-design/SKILL.md` | Anthropic official design skill |
