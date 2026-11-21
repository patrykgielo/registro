---
paths:
  - ".claude/**"
  - "CLAUDE.md"
---

# Claude Code Configuration Rules

Ładowane przy pracy nad konfiguracją Claude'a. Zasady obowiązujące zawsze (aliasy modeli, `/code-review` bez auto-wywołania) są w `agent-usage.md`.

## Znane Bugi (v2.1.84+)

### Bug: omitClaudeMd:true — built-in subagenci tracą CLAUDE.md

**GitHub Issue #40459** — zamknięty jako *not planned* (zweryfikowane 2026-08-12), czyli to potwierdzone bieżące zachowanie produktu, nie coś obalonego ani czekającego na fix.

Od v2.1.84 built-in subagenci (`Explore`, `Plan`) mają `omitClaudeMd: true` hardcoded, wzmocnione flagą `tengu_slim_subagent_claudemd` (default true). Nie widzą naszego CLAUDE.md — nie znają FILESYSTEM_DISK, user model, git workflow.

**Zakres: TYLKO agenci wbudowani.** Nasi właśni agenci z `.claude/agents/` ładują CLAUDE.md normalną ścieżką i ten bug ich nie dotyczy. Do 2026-08-12 ten plik twierdził inaczej i uzasadniał tym dublowanie reguł w każdym agencie — błędne przypisanie. Uzasadnieniem dublowania jest #7515 poniżej, nie ten bug.

### Bug: system_prompt body partially ignored (Issue #7515)

**Zamknięty jako *not planned*** (zgłoszony 2025-09-12, zweryfikowane 2026-08-12) — Anthropic nie zamierza tego zmieniać, więc traktuj jako trwałe zachowanie. Prompt wykonawczy subagenta bywa generowany z samej pary `name` + `description`, z pominięciem ciała system_promptu.

**To jest jedyny powód, dla którego krytyczne reguły idą na początek body** każdego `.claude/agents/*.md` — pierwsze, co model widzi, jeśli w ogóle zobaczy resztę.

### Bug: Claude stops calling subagents (Issue #8558)

Jeśli agenci nie są wywoływani: `/clear` i spróbuj ponownie.

---

## Konfiguracja (`.claude/settings.json` — commitowany)

Od 2026-11-21 wspólna konfiguracja i **całe wpięcie hooków** siedzą w **`.claude/settings.json`, który jest w gitcie**: `hooks`, `env` (Agent Teams), `attribution` (puste), `worktree.baseRef`. `.claude/settings.local.json` (gitignorowany) to tylko osobiste nadpisania. **Nie wpisuj tam tych samych hooków** — hooki z obu plików się sumują i odpalą dwa razy (`cc-doctor` to flaguje). Poprzednio wpięcie żyło wyłącznie w gitignorowanym pliku, którego na tej maszynie nie było — patrz „Hooki: Windows → WSL".

### worktree.baseRef = "head"

Od v2.1.133 default = `fresh` (gubi niepushed commits). Ustawione w `.claude/settings.json`:
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

## Hooki: Windows → WSL (od 2026-11-21)

Wpięcie: `.claude/settings.json`. Skrypty: `.claude/hooks/*.sh` + `lib/`. Testy: `tests/shell/cases/38–47`.

**Incydent 2026-11-21: hooki nie biegły nigdzie, a po wpięciu by szkodziły.**
- **Problem:** pięć hooków w repo, wpięcie wyłącznie w gitignorowanym `settings.local.json`, którego nie było — zero egzekwowania, `rules/` opisywały egzekwowanie, którego nie było. Gdyby je wpiąć tak jak były: `pre-tool-use.sh` odmówiłby **każdego** `git commit` (też na gałęzi feature w WSL), a bramki Stop/SubagentStop nie sprawdzałyby niczego.
- **Przyczyna:** (1) wpięcie w pliku poza gitem; (2) Claude Code z aplikacji Windows uruchamia hooki w **Git Bashu** (`OSTYPE=msys`) z cwd = klon lustrzany `C:\projects\registro` — zawsze `develop`, nigdy się nie zmienia, bez `docker` i `jq`, a prawdziwy kod i Docker są w WSL; (3) wszystkie hooki parsowały stdin przez `jq`, którego nie ma nigdzie — `jq: command not found` w `$(...)` zostawia pustą zmienną, więc hook widział pustą komendę i **nic nie blokował, bez słowa**; (4) `stop-gate.sh` „blokował" przez `exit 1` + stdout — Stop blokuje **wyłącznie exit 2** (stderr wraca do Claude'a), exit 1 to błąd nieblokujący; (5) `cc-doctor` w trybie `--hook` budował JSON przez `jq` (znowu cicho nic) i kończył `exit 1` (JSON czytany tylko przy exit 0), a przy braku wpięcia raportował „clean".
- **Rozwiązanie:** wpięcie w commitowany `.claude/settings.json`; wspólna powłoka `lib/wsl-forward.sh` (każdy hook ją `source`'uje): w Git Bashu uruchamia **ten sam skrypt** w WSL (`wsl.exe -d $REGISTRO_WSL_DISTRO -e bash lib/wsl-boot.sh …`) ze stdin, cwd = drzewo WSL, `CLAUDE_PROJECT_DIR` = drzewo WSL, `REGISTRO_WIN_CLONE` = klon; na Linuksie nic nie robi (natywna sesja działa jak dawniej). Parsowanie JSON przez python3 (`lib/json.sh`), nie jq. Gałąź w regule 1/2/5 to gałąź drzewa, na które **celuje komenda** (`lib/pretool_target.py`).
- **Zapobieganie:** `cc-doctor` flaguje brak wpięcia/skryptu/trybu 100755/parsera/dryfu; testy 38–47 (każdy dowiedziony czerwono na starym kodzie); `session-sync.sh` dogania klon i raportuje dryf przy starcie sesji.

**Zmierzone realnym `claude -p` na tej maszynie (2026-11-21):** komenda hooka idzie przez Git Bash; `$CLAUDE_PROJECT_DIR` = `C:/Users/…` (ukośniki w przód); pole `cwd` w JSON-ie = `C:\\Users\\…`; `wsl.exe` jest w PATH hooka. Forma `"$CLAUDE_PROJECT_DIR"/.claude/hooks/x.sh` działa przy wszystkich trzech zapisach ścieżki (`C:\…`, `C:/…`, `/c/…`). **End-to-end realnym Claude Code** (`claude -p`, v2.1.327, kopia klona z tym `settings.json`): oba hooki `SessionStart` → exit 0/success; `PreToolUse:Bash` odmówił `git commit` w klonie na `develop` (powód z `/mnt/c/…` dotarł do modelu).

### Zachowanie hooków

| Hook (zdarzenie) | Co robi | Koszt z Windows |
|---|---|---|
| `pre-tool-use.sh` (PreToolUse, matcher **`Bash\|PowerShell`**) | **Bash:** reguły 1–5; **gałąź = drzewo docelowe komendy** (`cd X &&`, `wsl --cd X --`, `git -C X`, `bash -c`, podpowłoka, heredoc; gołe `git commit` → pole `cwd` z JSON-a). Celu nie da się ustalić (`cd "$(…)"`, `$VAR`, `eval`, `\| bash`, inna dystrybucja WSL) albo komendy nie da się sparsować, a zawiera całe słowa `commit`/`push`/`pr create` → **DENY z podaniem formy do użycia**, nigdy zgadywanie. Przechodzi **cytowana** wzmianka, która parsuje się czysto (`grep "git commit" docs`), i `git log --grep=commit`. **PowerShell** (główne narzędzie powłoki na tej maszynie; `tool_name` = `PowerShell`, komenda w `tool_input.command` — sprawdzone na realnym Claude Code): reguły 3/4 na tekście, a **każde** `git commit` / `git push` / `gh pr create` jest odrzucane z poleceniem użycia narzędzia **Bash** — resolver rozumie POSIX, nie PowerShell, więc nie zgadujemy. **Fail closed** na komendach strzeżonych: WSL nie działa, `wsl.exe` wisi dłużej niż 15 s (`REGISTRO_WSL_TIMEOUT`; własny limit CC to 20 s i jest fail-**open**), hook padł w środku (`set -e`/`-u`) albo odpowiedział nie-JSON-em → DENY. **Granice:** komenda bez `git`/`gh`/`migrate:`/`db:wipe`/`FILESYSTEM_DISK` nigdy nie dociera do strażnika (prefiltr); jeśli sam skrypt hooka lub `settings.json` znikną, CC pokaże tylko nieblokujący „hook error" — od tego jest `cc-doctor` | ~120 ms bez słów strzeżonych (prefiltr w Git Bashu), ~450 ms z przekazaniem do WSL |
| `stop-gate.sh` (Stop) | 5+ plików kodu (w tym nowych, nieśledzonych) bez docs/rules/memory → **exit 2** + stderr. `stop_hook_active=true` nigdy nie blokuje. Drzewo = worktree z najnowszymi niezacommitowanymi zmianami (raportuje które) | ~0,5 s |
| `subagent-quality-gate.sh` (SubagentStop, matcher `laravel-senior-architect\|frontend-ui-architect`) | Pint + testy w kontenerze **tylko gdy `registro-app` montuje zmienione drzewo**; inaczej `SKIPPED` (`systemMessage`) — kontener montuje JEDNO drzewo, a testy w nim nie widzą zmian z innego worktree. „Testy przeszły" wymaga **dodatniego dowodu**: linii podsumowania `Tests: N …`; pad kontenera / fatal PHP / exit≠0 bez zidentyfikowanego testu = BLOK. Lista znanych porażek (`BookingServiceArea\|BookingConfirmation\|TenantFeature`, z 03.2026) usprawiedliwia **wyłącznie linię `FAILED`** z taką nazwą (wcześniej liczyła każdą linię z nazwą, także PASS). **Nie zweryfikowano**, czy te testy nadal padają na develop — jeśli nie, usuń `KNOWN_RE` w skrypcie | zależy od testów |
| `prompt-submit.sh` (UserPromptSubmit) | przypomnienie procesu + fakty architektury (`architecture-facts.sh`, ~3 s gdy prompt dotyka tenantów/deployu) | ~0,4 s (+ ~3 s facts) |
| `session-sync.sh` (SessionStart `startup\|resume`, **natywnie** w Git Bashu) | `git fetch` + **`merge --ff-only`** klona; nigdy nic destrukcyjnego (brudne śledzone pliki / lokalne commity / brak upstreamu / niedostępny origin → raport, klon bez zmian; nieśledzone, np. `.local-setup/`, nietknięte). Raportuje różnice `.claude/` + `CLAUDE.md` względem drzewa WSL **tylko gdy drzewo jest na tej samej gałęzi co klon** (zwykle jest na feature-branchu, gdzie różnica jest normalna). Cicho, gdy wszystko gra. `GIT_TERMINAL_PROMPT=0` + `GCM_INTERACTIVE=never` — Git Credential Manager nie otwiera okienka na starcie sesji | ~2 s (fetch) |
| `cc-doctor.sh --hook` (SessionStart) | patrz niżej | ~2 s |
| `notification-post-compact.sh` (SessionStart `compact`) | wstrzykuje reguły TIER 1 po kompakcji; czysty tekst, bez WSL | ~0,2 s |

**Uwaga: fast-forward działa na NASTĘPNĄ sesję.** `CLAUDE.md` i reguły są już załadowane, gdy hook startuje — raport mówi, że bieżąca sesja trzyma starą kopię (`/clear` albo restart).

### Zmienne środowiskowe

| Zmienna | Domyślnie | Znaczenie |
|---|---|---|
| `REGISTRO_WSL_DISTRO` | `Ubuntu-24.04` | dystrybucja, w której biegną hooki |
| `REGISTRO_WSL_TREE` | `/var/www/projects/registro/app` | główne drzewo WSL (cwd hooka, kotwica `git worktree list`) |
| `REGISTRO_APP_CONTAINER` | `registro-app` | kontener, którego montaż rozstrzyga o bramce jakości |
| `REGISTRO_WSL_TIMEOUT` | `15` | sekundy, po których PreToolUse przestaje czekać na `wsl.exe` i odmawia komendy strzeżonej (limit CC = 20 s) |
| `REGISTRO_HOOK_ASSUME_WINDOWS`, `REGISTRO_HOOK_PYTHONS` | — | **tylko testy** (udawanie Git Basha, ukrycie pythona) |

### Odzyskiwanie, gdy hooki nie działają

1. `scripts/cc-doctor.sh` (z Git Basha też — przekaże się do WSL) — mówi, co jest niewpięte / bez parsera / w dryfie.
2. `wsl.exe -d Ubuntu-24.04 -e true` — czy dystrybucja startuje. Rozpoznanie błędu: komunikat `did NOT run` / `could NOT run inside WSL` w stderr hooka albo deny z `rc=` w PreToolUse.
3. Brak python3 w WSL: `sudo apt install python3` (robi to człowiek). Do czasu instalacji PreToolUse **odmawia komend strzeżonych** i przepuszcza resztę z ostrzeżeniem.
4. `git checkout -- .claude/settings.json` — odtwarza wpięcie.
5. Ręczny test dokładnie jak robi to Claude Code: `printf '%s' '{"tool_name":"Bash","cwd":"C:\\projects\\registro","tool_input":{"command":"git commit -m x"}}' | CLAUDE_PROJECT_DIR=C:/projects/registro bash -c '"$CLAUDE_PROJECT_DIR"/.claude/hooks/pre-tool-use.sh'` — klon na `develop` ⇒ `deny`.

**Świadomie niezrobione:** `"onFailure": "block"` w PreToolUse. Uszkodzony hook blokowałby **każde** wywołanie narzędzia, także `Edit`, którym dałoby się go naprawić; fail-closed jest wbudowany w sam skrypt (trap na wyjściu + shim: tylko `rc 0` z JSON-em albo `rc 2` liczy się jako odpowiedź), i tylko dla komend strzeżonych. **PreToolUse nigdy nie nadaje uprawnień (decyzja 2026-11-21):** komenda, która przejdzie reguły, dostaje `{"suppressOutput":true}` — poprawny JSON **bez** `permissionDecision` (`hook_pass` w `lib/json.sh`), więc dalej działa normalny tryb uprawnień użytkownika. Stary `permissionDecision: allow` pomijałby pytanie o zgodę przy każdym przepuszczonym Bashu (cichy awans uprawnień). Pusty stdout też odpada: shim czyta go jako „hook się nie uruchomił" i odmawia. Straż w teście 39/40: odpowiedź „pass" nie zawiera klucza `permissionDecision`.

---

## `scripts/cc-doctor.sh` — weryfikacja konfiguracji

Uruchamiany automatycznie przy starcie sesji (hook `SessionStart`, tryb `--hook`, milczy gdy czysto). Ręcznie: `./scripts/cc-doctor.sh` albo `--full` żeby wymusić sprawdzenie MCP.

Wpięcie: `.claude/settings.json` (commitowany) — `SessionStart` → `session-sync.sh` + `cc-doctor.sh --hook`. Odzyskanie po utracie: `git checkout -- .claude/settings.json`.

Sprawdza rzeczy rozstrzygalne: aliasy modeli, brak `mcpServers` w settings, czy serwery MCP używane przez agentów są skonfigurowane i odpowiadają (`mcp__X__` w pliku agenta bez serwera `X` = porażka; wpisane na sztywno id konektora też), czy agent zbudowany na dziedziczeniu narzędzi (`clickup-task-manager`) nie dostał z powrotem allowlisty `tools` i ma `disallowedTools`, czy skrypty hooków istnieją i mają w indeksie gita tryb 100755, czy pliki testowane przez hooki istnieją, budżet TIER 1 — oraz (od 2026-11-21): brak `settings.json`, settings nie-JSON, **skrypt hooka niewpięty nigdzie**, ten sam hook w `settings.json` i `settings.local.json`, **brak parsera JSON (python3) tam, gdzie hooki biegną**, **matcher PreToolUse niewybierający i `Bash`, i `PowerShell`**, dryf klona Windows względem drzewa WSL (tylko ręcznie, tylko gdy oba na tej samej gałęzi; w `--hook` robi to `session-sync.sh`). W trybie `--hook` zawsze exit 0 — Claude Code czyta JSON hooka tylko przy exit 0. Nie ocenia, czy nową funkcję warto wdrożyć.

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

## Agenci a MCP: Firecrawl usunięty, ClickUp przez dziedziczenie (2026-11-21)

**Problem.** `cc-doctor` zgłaszał, że serwery `clickup` i `firecrawl` są używane przez agentów, a nie są skonfigurowane nigdzie. Zmierzone 2026-11-21: `~/.claude.json` nie ma **żadnego** serwera MCP, `.mcp.json` nie istnieje, a `browser-use` też nie jest skonfigurowany (ani jako serwer, ani jako skill użytkownika). Dziewięciu agentów miało `mcp__firecrawl__*` (ośmiu w `tools`, `content-strategist` w `allowed-tools`), `clickup-task-manager` — 17 nazw `mcp__clickup__*`, `commercial-estimate-specialist` — dwie.

**Przyczyna.** (1) Firecrawl: brak serwera i klucza na tej maszynie. (2) ClickUp dociera do sesji jako **konektor claude.ai**; nazwy jego narzędzi niosą id konta (`mcp__<id-konta>__clickup_create_task`), a nie `mcp__clickup__…`. Allowlista z `mcp__clickup__…` nie mogła więc nigdy się rozwiązać, a `cc-doctor` czyta tylko `~/.claude.json` i `.mcp.json`, więc konektorów nie widzi w ogóle. Dokumentacja podagentów: lista `tools`, z której **nic** się nie rozwiązuje, kończy się błędem startu; co robi lista częściowo nierozwiązywalna — dokumentacja nie mówi.

**Rozwiązanie.**
- Firecrawl usunięty z agentów, z treści `web-research-specialist`, skilla `deep-research` i reguł TIER 1. Ścieżka researchu: `WebSearch` / `WebFetch`. Strony za loginem i SPA: przeglądarka sesji głównej (Claude in Chrome / wbudowana), nie podagent.
- `clickup-task-manager`: **bez `tools`** (dziedziczy z sesji), `disallowedTools: Edit, Write, NotebookEdit, Bash, Agent, WebFetch, WebSearch, Skill`; na początku treści (reguła krytyczna idzie pierwsza — #7515 wyżej) jest instrukcja: sprawdź, czy masz narzędzia `clickup_*`, a jeśli nie — **zatrzymaj się i zgłoś**, nie udawaj. W treści narzędzia występują jako `clickup_*`, bez prefiksu serwera.
- `commercial-estimate-specialist`: ClickUp **usunięty**, nie dziedziczony. To pisarz (`Edit`/`Write`/`Bash`), a pominięcie `tools` daje mu narzędzia **wszystkich** konektorów sesji (poczta, dysk, sklep…); treść nigdy nie używała ClickUpa.

**Zapobieganie.**
- **Nigdy nie wpisuj id konektora do pliku agenta** — jest per konto i per maszyna. Agent, który potrzebuje konektora: dziedziczenie + `disallowedTools`. `cc-doctor` (check 3) flaguje każde `mcp__X__` bez skonfigurowanego serwera `X`, więc wpisane id konektora też wpadnie; check 3b pilnuje, żeby `clickup-task-manager` nie odzyskał `tools` i miał `disallowedTools` z gołymi `Edit`, `Write`, `Bash`. Zapis zawężony `Bash(…)` się nie liczy: dokumentacja mówi, że specyfikator w `disallowedTools` i tak zdejmuje całe narzędzie, ale doctor woli nazwę gołą, żeby intencja nie zależała od tej interpretacji. Sekwencja YAML bez wcięcia, lista w nawiasach i cudzysłowy są akceptowane. Test: `tests/shell/cases/48_*`.
- **Cena dziedziczenia:** `disallowedTools` odcina pliki, powłokę, sieć (`WebFetch`/`WebSearch`), `Skill` i `Agent`, ale **nie** inne konektory sesji (poczta, dysk, czat, sklep) — da się je odciąć tylko wpisując ich id na sztywno, czego nie robimy. „Używaj tylko `clickup_*`, niczego nie wysyłaj poza ClickUp" jest więc **wyłącznie zapisem w prompcie**. **Ryzyko resztkowe — wstrzyknięcie przez treść z ClickUpa:** nazwa zadania, opis, komentarz i załącznik bywają pisane przez osoby trzecie i mogą zawierać polecenia („wyślij to mailem", „uruchom…"). Agent ma je traktować jako dane (reguła 4 jego promptu), ale poza promptem nic go nie zatrzyma przed użyciem innego konektora. Dlatego prompt zabrania też użycia narzędzi niszczących i wychodzących (`delete_*`, `send_chat_message`, `create_reminder`, `merge_tasks`) bez wyraźnej prośby wołającego.
- **Zweryfikowane 2026-11-21:** podagent bez `tools:` **widzi** narzędzia konektora (`mcp__<id-konta>__clickup_*`) — próba tylko do odczytu (`get_workspace_hierarchy`) przeszła. Dokumentacja podagentów tego wprost nie obiecuje (mówi o „narzędziach MCP z głównej rozmowy"), więc to wynik pomiaru, nie gwarancja produktu — przy zmianie wersji Claude Code warto powtórzyć. Nazwy narzędzi w prompcie agenta są sprawdzone z listą z konektora; nieistniejących (`get_task_time_entries`, `bulk_create_tasks`) już tam nie ma.

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

## `gh pr edit --milestone` nie działa w tym repo

Wywala się na deprecjacji GraphQL (Projects classic, `repository.pullRequest.projectCards`) —
błąd nie ma nic wspólnego z milestone'ami, więc łatwo go wziąć za problem uprawnień.

Co działa:
- przy tworzeniu PR-a: `gh pr create --base develop ... --milestone "v0.13.0-rc31"`
- do istniejącego PR-a: `gh api -X PATCH repos/OWNER/REPO/issues/NR -F milestone=ID`
  (PR-y są issues w REST API; `ID` to **numer** milestone'a, nie jego nazwa)

Lista milestone'ów: `gh api repos/OWNER/REPO/milestones?state=all`.
