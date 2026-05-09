# Claude Code Configuration Rules

## Znane Bugi (v2.1.84+)

### Bug: omitClaudeMd:true — built-in subagenci tracą CLAUDE.md

**GitHub Issue #40459** — bez oficjalnej naprawy (stan: 2026-05-08)

Od v2.1.84 built-in subagenci (`Explore`, `Plan`) mają `omitClaudeMd: true` hardcoded.
Nie widzą naszego CLAUDE.md — nie znają FILESYSTEM_DISK, user model, git workflow.

**Nie dotyczy** custom agentów z `.claude/agents/` — oni dziedziczą CLAUDE.md normalnie.

**Workaround:** Krytyczne reguły są zduplikowane w sekcji "CRITICAL PROJECT CONSTRAINTS"
na początku body każdego agenta `.claude/agents/*.md`.

### Bug: system_prompt body partially ignored (Issue #7515)

Wybór agenta bazuje na `description` frontmatter. Body `.md` może być częściowo pominięte.
Dlatego krytyczne reguły **muszą być na początku body** (pierwsze co widzi model).

### Bug: Claude stops calling subagents (Issue #8558)

Intermittent regression — w niektórych sesjach model przestaje wywoływać subagentów.
Jeśli agenci nie są wywoływani: `/clear` i spróbuj ponownie.

---

## Konfiguracja (settings.local.json)

### worktree.baseRef = "head"

Od v2.1.133 default zmienił się na `fresh` (branchi z `origin/<default-branch>`).
Nasze worktree-agenci (laravel-senior-architect, frontend-ui-architect) używają `isolation: worktree`.
Bez `worktree.baseRef: "head"` gubią niepushed commits.

Ustawione w `.claude/settings.local.json`:
```json
"worktree": { "baseRef": "head" }
```

### Agent Teams

`CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS=1` — max 3 teammates równolegle (rekomendacja Anthropic: 3-5).

---

## Modele agentów (aktualne)

| Agent | Model | Effort | Zmiana |
|-------|-------|--------|--------|
| laravel-senior-architect | sonnet | high | — |
| frontend-ui-architect | sonnet | high | — |
| test-engineer | sonnet | high | — |
| code-reviewer | sonnet | high | — |
| web-research-specialist | sonnet | medium | — |
| agent-security-audit-specialist | sonnet | — | — |
| frontend-quality-auditor | **sonnet** | medium | Zmieniony z haiku (2026-05-08) |
| content-strategist | **sonnet** | medium | Dodany model (2026-05-08) |
| devops-engineer | sonnet | — | Brak Write/Edit tools (celowe?) |

---

## Nowe funkcje CC do rozważenia

- **`$CLAUDE_EFFORT`** w hookach — `prompt-submit.sh` może rozróżniać behavior wg effort
- **`alwaysLoad: true`** dla MCP firecrawl — eliminuje deferred tools problem u subagentów
- **Routines** — cloud agents na harmonogramie (cron) — do rozważenia dla automatyzacji
- **`/ultrareview`** — parallel multi-agent code review

---

## Firecrawl limity (2026)

| Plan | Kredyty |
|------|---------|
| Free | 500 jednorazowo (NIE miesięcznie!) |
| Hobby | 3,000/miesiąc (~$9) |
| Standard | 100,000/miesiąc |

Scrape = 1 kredyt/strona. Jeśli na Free i kredyty się skończyły — agenci nie działają (brak błędu!).
