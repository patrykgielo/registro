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

### isolation: worktree — WYŁĄCZONE (2026-08-07)

`laravel-senior-architect` i `frontend-ui-architect` miały `isolation: worktree`. Zdjęte, bo
**synchronizacja zawiodła w 3 na 3 uruchomieniach** jednej sesji: praca zostawała w worktree, a
checkout główny wracał czysty. Za każdym razem trzeba było ręcznie kopiować pliki, porównując wersje,
żeby nie nadpisać nowszych poprawek starszymi.

Worktree kupuje izolację przy agentach pracujących **równolegle** na tym samym repo. Nie robimy tego —
agenci lecą po kolei. Koszt był realny (ręczne odzyskiwanie, ryzyko cichej utraty), zysk zerowy.

Wracać do tego dopiero, gdy faktycznie pojawi się kilku agentów piszących równolegle — i wtedy
zweryfikować, czy synchronizacja działa, zanim się na niej oprzemy.

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
- Max **12,000 znaków łącznie** wszystkich załadowanych reguł (TIER 1) — mierzy to `cc-doctor`
- "for each line: would removing this cause mistakes? If not — cut it" (Anthropic)

Limit **łączny** jest ostry: ponad nim reguły konkurują o uwagę i te niżej są stosowane rzadziej, bez żadnego sygnału. Limit **per plik** jest miękki dla plików zawężonych przez `paths` — gdy edytujesz `app/Models/**`, reguły modeli mają być w kontekście. Nie tnij ich mechanicznie do liczby; jeśli plik jest za duży, rozbij go na lekki główny + pliki zasobów.

---

## `scripts/cc-doctor.sh` — weryfikacja konfiguracji

Uruchamiany automatycznie przy starcie sesji (hook `SessionStart`, tryb `--hook`, milczy gdy czysto). Ręcznie: `./scripts/cc-doctor.sh` albo `--full` żeby wymusić sprawdzenie MCP.

Wpięcie żyje w `.claude/settings.local.json`, który **jest gitignorowany** — skrypt jedzie w repo, wpięcie nie. Po świeżym klonie albo utracie tego pliku odtwórz:

```json
"SessionStart": [
  { "hooks": [{ "type": "command",
      "command": "\"$CLAUDE_PROJECT_DIR\"/scripts/cc-doctor.sh --hook" }] }
]
```

Sprawdza rzeczy rozstrzygalne: aliasy modeli, brak `mcpServers` w settings, czy serwery MCP używane przez agentów są skonfigurowane i odpowiadają, czy skrypty hooków istnieją i są wykonywalne, czy pliki testowane przez hooki istnieją, budżet TIER 1. Nie ocenia, czy nową funkcję warto wdrożyć.

**Powstał, bo każda awaria konfiguracji tutaj była cicha** — firecrawl w nieistniejącym kluczu, agenci zamrożeni na starych modelach, hook testujący plik, którego nic nie tworzy. Stąd jego zasada: **brak artefaktu to porażka, nie pominięcie.**

Sprawdzenie MCP kosztuje ~3,5 s, więc biegnie najwyżej raz na tydzień (znacznik `.claude/.cc-doctor-mcp-stamp`). Brak znacznika = nigdy nie sprawdzano = do zrobienia teraz.

---

## Modele agentów (aktualne)

| Agent | Model | Effort |
|-------|-------|--------|
| laravel-senior-architect | sonnet | high |
| registro-devops-engineer | sonnet | high |
| frontend-ui-architect | sonnet | high |
| test-engineer | sonnet | high |
| code-reviewer | sonnet | high |
| web-research-specialist | sonnet | medium |
| frontend-quality-auditor | sonnet | medium |
| content-strategist | sonnet | medium |
| agent-security-audit-specialist | sonnet | — |
| devops-engineer (review) | sonnet | medium |

**Podział pisze/recenzuje.** `registro-devops-engineer` ma `Write`/`Edit` i jest jedynym agentem,
który zmienia `scripts/**`, `docker-compose*.yml`, `docker/**`. `devops-engineer` **nie ma**
`Write`/`Edit` — celowo, tak samo jak `code-reviewer` wobec `laravel-senior-architect`. Powstało
2026-08-11, bo do tego dnia stary `devops-engineer` był read-only i całą infrastrukturę pisał
`laravel-senior-architect` — agent PHP/Eloquent/Filamenta, bez ani jednej reguły o Dockerze,
powłoce czy TLS. Wszystkie regresje warstwy wdrożeniowej wyszły spod niego.

**Model discipline (regent0x zasada):** Opus = architektura + deep debug. Sonnet = 80% codziennej pracy. Nie używaj Opus do formatowania plików i rename.

**ZAWSZE alias (`sonnet`/`opus`/`haiku`), NIGDY przypięta wersja** (`claude-sonnet-4-6`). Przypięcie zamraża agenta na modelu sprzed miesięcy i nikt tego nie zauważa. Incydent 2026-08-05: trzej agenci siedzieli na `claude-sonnet-4-6` i `haiku-4-5-20251001` długo po wydaniu nowszych.

---

## MCP: `mcpServers` NIE działa w settings.json

**Incydent 2026-08-05.** Firecrawl był wpisany jako `mcpServers` w `~/.claude/settings.json`. Klucz API ważny, pakiet sprawny — a serwer nie działał, bo **`mcpServers` nie jest prawidłowym kluczem `settings.json`** (schemat ma 142 właściwości, tej wśród nich nie ma). Blok był po cichu ignorowany, ośmiu agentów wskazywało na nierozwiązywalne narzędzia, a reguła twierdziła „Firecrawl = domyślne".

**Zapobieganie:** serwery MCP dodawaj **wyłącznie** przez `claude mcp add` (zapisuje do `~/.claude.json` albo `.mcp.json`). Po dodaniu zweryfikuj `claude mcp list` — status musi brzmieć `✔ Connected`. Konfiguracja wklejona ręcznie do złego pliku nie zgłasza błędu.

## Frontmatter agenta: prawdziwy newline zabija cały plik

**Incydent 2026-08-11.** `design-system-guardian` i `commercial-estimate-specialist` **nie ładowały się w ogóle** — nie było ich na liście dostępnych agentów, przez nieznany czas, bez jednego komunikatu.

Przyczyna: `description:` jako zwykły skalar, przerwany **prawdziwym** znakiem nowej linii. YAML kończy wtedy wartość, a następną linię (`Context: User completed a feature…`, `<commentary>`) czyta jako kolejny klucz najwyższego poziomu → cały frontmatter się wywala → agent wypada z rejestru.

Co działa, a co nie:

```yaml
description: Tekst\n\n<example>\nContext: ...\n</example>   # OK — jedna linia, \n jako escape
description: |                                              # OK — block scalar
  Tekst
  w wielu liniach
description: Tekst                                          # ŹLE — plain scalar
Context: ...                                                #        przerwany newlinem
```

Dwuznaczne `: ` w środku jednoliniowego skalara (`Context: User…`) Claude Code przepuszcza, mimo że PyYAML odrzuca — **nie waliduj tego PyYAML-em**, dostaniesz fałszywe alarmy na agentach, które działają. `cc-doctor` sprawdza to strukturalnie (check 1b): każda linia frontmatteru to klucz albo wcięta kontynuacja klucza bez wartości inline. Sprawdzone w obie strony — flaguje wersję sprzed naprawy, przepuszcza po naprawie.

**Zasada ogólna:** żadna konfiguracja nie jest sprawna, dopóki nie została uruchomiona. To ta sama klasa błędu co osierocony kontener `registro-queue` — plik mówił jedno, rzeczywistość drugie.

Firecrawl: Free = 500 kredytów jednorazowo, Hobby = 3 000/mies. Scrape = 1 kredyt/strona. Wyczerpane kredyty też nie dają błędu — agenci po prostu nie działają.
