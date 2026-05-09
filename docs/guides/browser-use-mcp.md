# Browser-use Integration

**Added:** 2026-03-22
**Status:** Active
**Repo:** https://github.com/browser-use/browser-use (79k+ stars)
**Docs:** https://docs.browser-use.com

---

## Dwa tryby integracji

### 1. `/browser-use` Skill (lokalne CLI) — GŁÓWNY

Slash command w Claude Code. Otwiera **widoczną przeglądarkę** na ekranie, Claude steruje nią komenda po komendzie z terminala. Daemon działa w tle (~50ms latency).

**Instalacja:**
```bash
# CLI
curl -fsSL https://browser-use.com/cli/install.sh | bash
browser-use doctor

# Skill
mkdir -p ~/.claude/skills/browser-use
curl -o ~/.claude/skills/browser-use/SKILL.md \
  https://raw.githubusercontent.com/browser-use/browser-use/main/skills/browser-use/SKILL.md
```

**Plik:** `~/.claude/skills/browser-use/SKILL.md`

**Użycie w Claude Code:**
```
/browser-use otwórz registro.local:8444 i sprawdź formularz
```

**Kluczowe komendy CLI:**
```bash
browser-use --headed open <url>      # Widoczna przeglądarka
browser-use state                     # Lista klikalnych elementów
browser-use click <index>             # Klik po indeksie
browser-use input <index> "text"      # Wpisz tekst
browser-use screenshot [path.png]     # Screenshot
browser-use --profile "Default" open <url>  # Z zalogowanym Chrome
browser-use close                     # Zamknij
```

### 2. Cloud MCP (HTTP) — DODATKOWY

Autonomiczny agent w chmurze. Nie wymaga lokalnego Chromium. Wolniejszy (60-120s), max 10 kroków.

**Config:** `~/.claude.json` (user scope) + `BROWSER_USE_API_KEY` w `~/.bashrc`

```bash
# ~/.bashrc
export BROWSER_USE_API_KEY="<klucz>"

# ~/.claude.json (user-level mcpServers) — already configured
"browser-use": {
  "type": "http",
  "url": "https://api.browser-use.com/mcp",
  "headers": {
    "X-Browser-Use-API-Key": "${BROWSER_USE_API_KEY}"
  }
}
```

**Narzędzia MCP:**

| Tool | Co robi | Czas |
|------|---------|------|
| `browser_task` | Autonomiczny agent (max 10 kroków) | 60-120s |
| `list_browser_profiles` | Lista profili | <1s |
| `monitor_task` | Status taska | <1s |

---

## Kiedy używać browser-use vs Firecrawl

| Scenariusz | Narzędzie | Dlaczego |
|------------|-----------|----------|
| Scraping statycznej strony | **Firecrawl** | Szybszy (2-5s vs 60-120s) |
| Bulk scraping wielu URL | **Firecrawl** | Obsługuje batch |
| Strona za loginem | **Browser-use** | Może kliknąć login form |
| SPA/React/Vue app | **Browser-use** | Renderuje JS, czeka na content |
| Multi-step navigation | **Browser-use** | Agent decyduje gdzie kliknąć |
| Testowanie naszego UI | **Browser-use** | Może otworzyć registro.local |
| Wypełnianie formularzy | **Browser-use** | Klika, wpisuje, submituje |
| Proste wyszukiwanie | **Firecrawl** | `firecrawl_search` jest szybszy |

**Zasada:** Firecrawl = domyślne (90% zadań). Browser-use = interaktywne zadania, strony za loginem, testowanie UI (10% zadań).

---

## Przykłady użycia

### Research z zapisem do repo

```
Prompt: "Użyj browser-use żeby wejść na stronę wypozyczalnia-krakow.pl,
         znajdź cennik sprzętu budowlanego i zapisz w app/docs/research/"

Flow:
1. Claude wywołuje browser_task → agent przegląda stronę
2. Wynik wraca jako tekst do Claude
3. Claude zapisuje do pliku w repo
4. Agenci (laravel-senior-architect etc.) mogą czytać ten plik
```

### Testowanie UI

```
Prompt: "Browser-use: otwórz registro.local:8444/admin,
         sprawdź czy formularz usług się ładuje poprawnie"
```

### Scraping dynamicznej strony

```
Prompt: "Użyj browser-use żeby wejść na stronę z React SPA,
         kliknij w zakładkę 'Cennik' i wyciągnij tabelę z cenami"
```

---

## Ograniczenia Cloud MCP

- **Max 10 kroków** per `browser_task` call — złożone zadania wymagają chainowania
- **Brak stealth/CAPTCHA** na darmowym tierze
- **10 krajów proxy:** us, uk, fr, it, jp, au, de, fi, ca, in
- **Token warning** — Claude Code ostrzega gdy output > 10,000 tokenów
- **Czas** — 60-120s na task (vs 2-5s Firecrawl)

---

## Bezpieczeństwo

- API key NIGDY w kodzie — tylko env variable
- `~/.bashrc` nie jest w repo (bezpieczne)
- Cloud MCP nie ma dostępu do lokalnego filesystem
- Unikaj przekazywania wrażliwych danych (hasła, tokeny) w task description
- Browser-use cloud widzi odwiedzane strony — nie używaj do stron z wrażliwymi danymi

---

## Skill vs Cloud MCP — kiedy co

| Aspekt | `/browser-use` Skill | Cloud MCP |
|--------|----------------------|-----------|
| Widoczna przeglądarka | Tak (`--headed`) | Nie |
| Sterowanie krok po kroku | Tak | Nie (autonomiczny agent) |
| Użycie Chrome profilu | Tak (`--profile`) | Tak (cloud profiles) |
| Szybkość per komenda | ~50ms | 60-120s per task |
| Limit kroków | Brak | Max 10 |
| Wymaga Python | Tak (lokalnie) | Nie |
| Wymaga API key | Nie | Tak |
| Testowanie lokalnego UI | Tak | Nie (brak dostępu do localhost) |

**Zasada:** Skill = główne narzędzie. Cloud MCP = fallback gdy nie masz dostępu do lokalnego Chromium.

---

## Powiązane pliki

| Plik | Opis |
|------|------|
| `~/.claude/skills/browser-use/SKILL.md` | Skill definition (slash command) |
| `~/.bashrc` | `BROWSER_USE_API_KEY` env variable (Cloud MCP) |
| `~/.claude.json` | Cloud MCP server config (user scope) |
| `.claude/rules/agent-usage.md` | Kiedy używać którego agenta |
