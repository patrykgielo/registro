# Claude Code Configuration Rules

## Znane Bugi (v2.1.84+)

### Bug: omitClaudeMd:true — built-in subagenci tracą CLAUDE.md

**GitHub Issue #40459** — bez oficjalnej naprawy (stan: 2026-05-08)

Od v2.1.84 built-in subagenci (`Explore`, `Plan`) mają `omitClaudeMd: true` hardcoded.
Nie widzą naszego CLAUDE.md — nie znają FILESYSTEM_DISK, user model, git workflow.

**Workaround:** Krytyczne reguły zduplikowane na początku body każdego `.claude/agents/*.md`.

### Bug: system_prompt body partially ignored (Issue #7515)

Krytyczne reguły **muszą być na początku body** (pierwsze co widzi model).

### Bug: Claude stops calling subagents (Issue #8558)

Jeśli agenci nie są wywoływani: `/clear` i spróbuj ponownie.

---

## Konfiguracja (settings.local.json)

### worktree.baseRef = "head"

Od v2.1.133 default = `fresh` (gubi niepushed commits). Ustawione w `.claude/settings.local.json`:
```json
"worktree": { "baseRef": "head" }
```

### Agent Teams

`CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS=1` — max 3 teammates równolegle.

---

## Nowe funkcje CC (v2.1.146–147, maj 2026)

### Workflow tool — deterministyczna orkiestracja agentów

```bash
CLAUDE_CODE_WORKFLOWS=1   # OFF by default — włącz aby używać
```

Workflow tool = deterministic multi-agent orchestration. Alternatywa dla "let the agent figure it out" która zawodzi w production scale. Sandbox hardened przeciw prototype-pollution.

### /code-review (dawniej /simplify) — z effort levels

```
/code-review low     # szybki, latency-sensitive
/code-review medium  # cost-sensitive, mniej tokenów
/code-review high    # balans tokenów i jakości
/code-review xhigh   # recommended default na Opus 4.7
/code-review max     # demanding tasks, diminishing returns — test before adopting
```

Może postować inline GitHub PR comments.

### Background sessions — permission persistence

```
CLAUDE_BG_SESSION_PERMISSION_RULES   # nowa env var
```

Backgrounded sessions nie re-promptują o tool permissions oznaczone "don't ask again".
**Kluczowe dla Routines/cron agentów** — jeden re-prompt zabija całą pętlę.

### Nowe modele w CLI surface (v2.1.146)

- `claude-for-financial-services`
- `claude-for-legal`

---

## Context Management — Kluczowe Komendy

```
/context     # Zawsze uruchamiaj na początku sesji — sprawdź baseline tokenów
             # Baseline >30k PRZED pierwszym promptem = problem strukturalny
/compact     # Uruchamiaj co 20-30 min podczas długich sesji
             # /compact [zachowaj: schemat DB, aktualny branch, ostatnie błędy]
/clear       # Full reset przy przejściu na inny task
```

### Rule file limits

- Max **6,000 znaków** na jeden plik reguł
- Max **12,000 znaków łącznie** wszystkich załadowanych reguł
- "for each line: would removing this cause mistakes? If not — cut it" (Anthropic)

---

## Modele agentów (aktualne)

| Agent | Model | Effort |
|-------|-------|--------|
| laravel-senior-architect | sonnet | high |
| frontend-ui-architect | sonnet | high |
| test-engineer | sonnet | high |
| code-reviewer | sonnet | high |
| web-research-specialist | sonnet | medium |
| frontend-quality-auditor | sonnet | medium |
| content-strategist | sonnet | medium |
| agent-security-audit-specialist | sonnet | — |

**Model discipline (regent0x zasada):** Opus = architektura + deep debug. Sonnet = 80% codziennej pracy. Nie używaj Opus do formatowania plików i rename.

---

## Firecrawl limity (2026)

| Plan | Kredyty |
|------|---------|
| Free | 500 jednorazowo |
| Hobby | 3,000/miesiąc (~$9) |

Scrape = 1 kredyt/strona. Jeśli Free i kredyty skończyły się — brak błędu, agenci po prostu nie działają.

---

## Nowe funkcje do rozważenia

- **`CLAUDE_CODE_WORKFLOWS=1`** — deterministyczna orkiestracja (v2.1.147, warto przetestować)
- **Routines** + `CLAUDE_BG_SESSION_PERMISSION_RULES` — cron agenci bez permission blocków
- **`/ultrareview`** — parallel multi-agent code review (user-triggered)
