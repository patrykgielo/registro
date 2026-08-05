---
paths:
  - ".claude/**"
  - "CLAUDE.md"
---

# Claude Code Configuration Rules

Ładowane przy pracy nad konfiguracją Claude'a. Zasady obowiązujące zawsze (aliasy modeli, `/code-review` bez auto-wywołania) są w `agent-usage.md`.

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

## Workflow tool

**GA od v2.1.154** — `CLAUDE_CODE_WORKFLOWS=1` nie jest już potrzebne. Deterministyczna orkiestracja wielu agentów. Domyślny rozmiar: medium (<15 agentów), zmiana przez `workflowSizeGuideline` w settings lub `/config`.

### /code-review — effort levels

`low` / `medium` / `high` / `xhigh` / `max`. Od v2.1.218 działa jako background subagent (nie zapycha rozmowy).

**Od v2.1.215 Claude NIE uruchamia `/code-review` ani `/verify` sam** — trzeba wywołać jawnie. Oba mają `disable-model-invocation`, więc **nie wykonają się też w zaplanowanym odpaleniu `/loop`** (trafiają jako zwykły tekst).

---

## Context Management

Progi dla tego projektu: baseline `/context` **>30k przed pierwszym promptem = problem strukturalny**. `/compact` co 20–30 min w długich sesjach, z listą tego, co zachować.

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

**ZAWSZE alias (`sonnet`/`opus`/`haiku`), NIGDY przypięta wersja** (`claude-sonnet-4-6`). Przypięcie zamraża agenta na modelu sprzed miesięcy i nikt tego nie zauważa. Incydent 2026-08-05: trzej agenci siedzieli na `claude-sonnet-4-6` i `haiku-4-5-20251001` długo po wydaniu nowszych.

---

## MCP: `mcpServers` NIE działa w settings.json

**Incydent 2026-08-05.** Firecrawl był wpisany jako `mcpServers` w `~/.claude/settings.json`. Klucz API ważny, pakiet sprawny — a serwer nie działał, bo **`mcpServers` nie jest prawidłowym kluczem `settings.json`** (schemat ma 142 właściwości, tej wśród nich nie ma). Blok był po cichu ignorowany, ośmiu agentów wskazywało na nierozwiązywalne narzędzia, a reguła twierdziła „Firecrawl = domyślne".

**Zapobieganie:** serwery MCP dodawaj **wyłącznie** przez `claude mcp add` (zapisuje do `~/.claude.json` albo `.mcp.json`). Po dodaniu zweryfikuj `claude mcp list` — status musi brzmieć `✔ Connected`. Konfiguracja wklejona ręcznie do złego pliku nie zgłasza błędu.

**Zasada ogólna:** żadna konfiguracja nie jest sprawna, dopóki nie została uruchomiona. To ta sama klasa błędu co osierocony kontener `registro-queue` — plik mówił jedno, rzeczywistość drugie.

Firecrawl: Free = 500 kredytów jednorazowo, Hobby = 3 000/mies. Scrape = 1 kredyt/strona. Wyczerpane kredyty też nie dają błędu — agenci po prostu nie działają.
