# Praca nad Registro z drugiej maszyny (i zdalne sterowanie)

Zmierzone 2026-10-05 na maszynie `patrick`, Claude Code **2.1.289**, konto claude.ai
(`authMethod: claude.ai`, nie klucz API — to warunek Remote Control i sesji w chmurze).

Dokument odpowiada na jedno pytanie: **co trzeba zrobić, żeby prowadzić ten projekt
z innego komputera** — i czego zrobić nie można.

---

## Trzy warianty, różnią się tym, czy masz lokalnego Dockera

| | Remote Control | Sesja w chmurze | Pełne drugie środowisko |
|---|---|---|---|
| Gdzie działa kod | ta maszyna | VM Anthropic | druga maszyna |
| Ta maszyna musi być włączona | **tak** | nie | nie |
| Docker, MySQL, `.env`, MCP | **wszystko** | brak | własne |
| Pełny zestaw testów | tak | **nie** | tak |
| Wdrożenie na UAT | tak | nie | tak |

**Remote Control** (`claude --remote-control "registro"`) to **jedyny wariant, w którym
proces z `CLAUDE.md` działa bez zmian**: testy w Dockerze, `npm run build`, test wyścigu
na prawdziwym MySQL, `gh workflow run deploy-production.yml`. Sesja żyje tutaj, a z drugiego
urządzenia (claude.ai/code, aplikacja mobilna) widzisz tę samą rozmowę i zatwierdzasz zgody.

Weryfikacja na tej maszynie: `--remote-control [name]` to **flaga**, nie podkomenda
(`claude remote-control` nie istnieje w 2.1.289). Dostępne są też `--cloud`,
`--environment <id>` i `--teleport [session]`.

> **Zamknięty terminal albo uśpiony komputer = koniec sesji.** Jeśli ma przetrwać
> rozłączenie, uruchom pod `tmux`.

**Sesja w chmurze** (`claude --cloud "opis"`, odbiór `claude --teleport`) nie widzi lokalnego
Dockera, MySQL-a, `.env` ani naszych serwerów MCP. Dla tego projektu znaczy to, że **wypada
cała weryfikacja, na której stoi nasz proces**. Nadaje się do przeglądu kodu, dokumentacji
i planowania; nie do wdrożeń ani do testów wymagających bazy.

---

## Lista kontrolna: uruchomienie na drugiej maszynie

### 1. Repozytorium i zależności
```bash
git clone git@github.com:patrykgielo/registro.git && cd registro/app
composer install        # instaluje też `git config core.hooksPath .githooks`
```
`composer install` kopiuje `.env.example` do `.env`, jeśli `.env` nie istnieje, i podpina
`.githooks` (`post-merge`/`post-checkout` uruchamiają migracje, `pre-commit` blokuje puste
`down()`). **Bez `composer install` te zabezpieczenia nie działają.**

### 2. Środowisko
Stack to 8 usług: `app`, `nginx`, `mysql`, `redis`, `node`, `horizon`, `scheduler`, `mailpit`.
W kontenerze: PHP **8.3.33**, Node **20.20.2**, Composer **2.10.2**.

```bash
docker compose up -d
docker compose exec -T app php artisan key:generate
docker compose exec -T app php artisan migrate
docker compose exec -T app npm ci && docker compose exec -T app npm run build
```

W `.env` **muszą** być (patrz `.claude/rules/deployment.md`):
- `FILESYSTEM_DISK=public` — nigdy `local`, psuje wysyłanie plików
- `APP_LOCALE=pl`, `APP_FALLBACK_LOCALE=en` — **od rc39 `en` przełącza całą stronę klienta
  na angielski**; do rc38 ta zmienna była nieszkodliwa, bo nie było tłumaczeń
- `TRUSTED_PROXIES_CIDR=` puste, nigdy `*`
- `APP_KEY` niepuste — szyfruje `audit_logs`

Hosty tenantów w `/etc/hosts` (wzorzec `<slug>.registro.local`), inaczej subdomeny nie
odpowiedzą. Certyfikat lokalny jest samopodpisany: `curl -k` działa, a **Chrome pokazuje
stronę błędu** — to nie awaria aplikacji.

### 3. Claude Code
Jedzie z repozytorium i nie wymaga niczego: `CLAUDE.md`, `.claude/rules/**`,
`.claude/agents/**`, `app/docs/**`.

**Nie jedzie i trzeba odtworzyć:**
- `.claude/settings.local.json` — gitignorowany; **kopia JSON do odtworzenia leży
  w `.claude/rules/claude-code-config.md`**. Zawiera 6 zdarzeń hooków
  (`PreToolUse`, `UserPromptSubmit`, `Stop`, `Notification`, `SubagentStop`, `SessionStart`).
  Sprawdzone 2026-10-05: **zero ścieżek bezwzględnych**, 6 użyć `$CLAUDE_PROJECT_DIR` —
  czyli hooki są przenośne bez edycji.
- Serwery MCP — konfiguracja jest per maszyna, tokeny OAuth nie są przenośne.
  Po `git clone` zrób `claude mcp list` i zaloguj te, które pokazują „Needs authentication".
  **MCP dodawaj wyłącznie przez `claude mcp add`** — `mcpServers` w `settings.json`
  jest po cichu ignorowane (incydent 2026-08-05).
- `CLICKUP_API_TOKEN` i `CLICKUP_SPACE_ID` w profilu powłoki (na tej maszynie w `~/.bashrc`).
  Bez nich `scripts/clickup.py` nie działa, a masówka przez MCP spali dzienny limit.
- Pamięć projektu — patrz niżej.

```bash
./scripts/cc-doctor.sh --full   # weryfikuje konfigurację; milczy, gdy czysto
```

### 4. Pamięć projektu (78 plików, 452 KB)
Siedzi w `~/.claude/projects/-var-www-projects-registro-app/memory/`, **poza repozytorium**,
i nie synchronizuje się sama. To tam są wnioski, których nie ma w kodzie: że `varchar(2)`
po cichu obcina kod języka, że `| tail` maskuje kod wyjścia wdrożenia, że szablon maila
dodany do seedera nie dociera na wdrożone środowisko.

Przeniesienie: archiwum z maszyny źródłowej, rozpakowane **pod ścieżką odpowiadającą
katalogowi roboczemu drugiej maszyny** (nazwa katalogu to zamieniona ścieżka projektu, więc
inny katalog = inna nazwa).

```bash
# na maszynie źródłowej
tar czf registro-claude-memory.tar.gz -C ~/.claude/projects/-var-www-projects-registro-app memory/
# na docelowej (przykład dla tej samej ścieżki projektu)
mkdir -p ~/.claude/projects/-var-www-projects-registro-app
tar xzf registro-claude-memory.tar.gz -C ~/.claude/projects/-var-www-projects-registro-app
```

> Notatki zawierają adresy serwerów i numery zgłoszeń. Archiwum przenoś prywatnym kanałem
> i **nie commituj go** — ten katalog nie ma prawa wejść do repozytorium.

### 5. Czego nie przeniesiesz nigdy
- **Zapisu rozmów.** `~/.claude/projects/<katalog>/*.jsonl` jest lokalny; skopiowanie go nie
  pozwala wznowić sesji na innej maszynie. Trwałym nośnikiem kontekstu są repozytorium
  i pamięć projektu, nie rozmowa.
- **Tokenów OAuth** (MCP, konektory) — leżą w magazynie poświadczeń systemu.
- **`.env`**, kluczy SSH, certyfikatów.
- **Plików zdalnego dostępu** (`REMOTE_ACCESS_SETUP.md`, `setup-remote-access.sh`,
  `backup-cc-vps.txt`) — ignorowane z repozytorium od 2026-10-05. Wcześniej chronił je tylko
  `.git/info/exclude`, który **jest lokalny i nie jedzie z klonem**.

---

## Dwie maszyny naraz — pułapki

- **Limity są na konto, nie na maszynę.** Dwie równoległe sesje zużywają ten sam budżet.
- **Jedno drzewo robocze na raz.** Reguła „agenty piszące po kolei" obowiązuje też między
  maszynami: dwie sesje na tej samej gałęzi to konflikt, którego nikt nie pilnuje.
- **Nie mierz czasu testów, gdy druga maszyna albo agent robi własny przebieg.** Nakładające
  się przebiegi zawyżają czas (zmierzone: 146 s → 227 s → 344 s) i mogą dawać fałszywe
  porażki na tej samej bazie testowej. Sprawdź `docker top registro-app | grep "artisan test"`.
- **Pamięć się rozjedzie.** Jeśli obie maszyny zapisują notatki, zsynchronizuj je świadomie
  albo trzymaj trwałe wnioski w `.claude/rules/` i `app/docs/`, gdzie widzi je PR.

---

## Powiązane

- `.claude/rules/claude-code-config.md` — kopia `settings.local.json`, znane bugi CC, modele agentów
- `.claude/rules/deployment.md` — zmienne krytyczne, zakazy
- `app/docs/deployment/instalacja-tenanta-od-zera.md` — procedury operatora na serwerze
