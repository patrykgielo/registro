# Self-Improvement Rules - CRITICAL

## ZASADA 0: PRODUCTION WYMAGA ZGODY UŻYTKOWNIKA (BEZWZGLĘDNA!)

**NIGDY nie deployuj na produkcję bez EXPLICIT zgody użytkownika!**

### Punkty wymagające zgody:

```
git tag vX.Y.Z        ← STOP! Zapytaj użytkownika!
git push origin vX.Y.Z ← STOP! Zapytaj użytkownika!
```

### Obowiązkowy dialog przed tagowaniem:

```
"Release jest gotowy do produkcji.
Tag: vX.Y.Z
Zmiany: [lista zmian]

Czy mogę utworzyć tag i wdrożyć na produkcję?"
```

### CZEKAJ na odpowiedź:
- "Tak" / "OK" / "Wdrażaj" → możesz tagować
- Brak odpowiedzi → CZEKAJ
- "Nie" / "Poczekaj" → NIE taguj

### Dlaczego to jest ZASADA 0 (najważniejsza):
- Tag triggeruje AUTOMATYCZNY deploy na produkcję
- Produkcja = live users = NIEODWRACALNE
- Użytkownik MUSI mieć kontrolę nad tym momentem
- Staging deploy = OK bez pytania (testowe środowisko)
- Production deploy = ZAWSZE pytaj

**Incident 2026-01-21:** Deploy v4.14.0 na produkcję bez zgody użytkownika.
Zakończony sukcesem, ale naruszył zasadę kontroli użytkownika nad produkcją.

---

## ZASADA 1: Czekaj na Deploy

**NIGDY nie przechodź do kolejnych zadań dopóki deploy nie przejdzie!**

```bash
# Po każdym MERGE do develop:
gh run watch <run-id> --exit-status

# Lub sprawdź status:
gh run list --branch develop --limit 1
```

**Jeśli deploy FAILED:**
1. STOP - nie rób nic innego
2. Diagnozuj przyczynę
3. Napraw
4. Zaczekaj aż przejdzie
5. DOPIERO wtedy kontynuuj

---

## ZASADA 2: Natychmiastowa Analiza Błędów

**Gdy napotkasz JAKIKOLWIEK błąd:**

### Krok 1: Zrozum DLACZEGO
```
Nie zgaduj. Nie próbuj na ślepo.
→ Sprawdź logi
→ Sprawdź dokumentację
→ Jeśli nie wiesz - użyj web-research-specialist agenta
```

### Krok 2: Udokumentuj ROZWIĄZANIE
Po naprawieniu błędu NATYCHMIAST:

1. **ADR w `docs/`** - dla znaczących incydentów
   ```
   docs/deployment/ADR-XXX-nazwa-problemu.md
   ```

2. **Rules w `.claude/rules/`** - reguły zapobiegające
   ```
   .claude/rules/relevant-area.md
   ```

3. **Troubleshooting** - jeśli błąd może się powtórzyć
   ```
   docs/deployment/CI-CD-TROUBLESHOOTING.md
   docs/troubleshooting-*.md
   ```

### Krok 3: Zaktualizuj CLAUDE.md (jeśli krytyczne)
Tylko dla uniwersalnych, często występujących problemów.

---

## ZASADA 3: Samodokumentacja i Uczenie

### Format dokumentacji błędu:

```markdown
## Problem
[Dokładny komunikat błędu]

## Przyczyna
[Root cause - DLACZEGO tak się stało]

## Rozwiązanie
[Jak naprawić]

## Zapobieganie
[Reguły/sprawdzenia żeby nie powtórzyć]
```

### Przykład dobrej dokumentacji:

```markdown
## Problem
curl: (23) Failure writing output to destination

## Przyczyna
Pliki nginx config owned by root:root zamiast deploy:deploy.
Ktoś wykonał operację jako root na serwerze.

## Rozwiązanie
ssh root@server "chown deploy:deploy /path/to/files"

## Zapobieganie
- NIGDY SSH jako root dla operacji aplikacyjnych
- Weryfikuj ownership: find /path -not -user deploy -ls
```

---

## ZASADA 4: Research Przed Zgadywaniem

**NIE WIEM = RESEARCH**

```
Nie znasz odpowiedzi?
    ↓
Użyj: web-research-specialist agent
    ↓
Znajdź źródło (docs, GitHub issues, Stack Overflow)
    ↓
Zastosuj rozwiązanie
    ↓
Udokumentuj w rules/docs
```

**Przykłady gdy MUSISZ użyć research:**
- Nieznany format błędu
- Nowa technologia/narzędzie
- Zmiany w API/bibliotece
- "Deprecation warning"
- Konfiguracja zewnętrznych serwisów

---

## ZASADA 5: Uczenie się z Historii

**Przed rozwiązywaniem problemu:**

1. Sprawdź czy podobny problem już był:
   ```bash
   grep -r "error message" docs/
   grep -r "problem keyword" .claude/rules/
   ```

2. Sprawdź ADR-y:
   ```bash
   ls docs/deployment/ADR-*.md
   ```

3. Sprawdź troubleshooting:
   ```bash
   cat docs/deployment/CI-CD-TROUBLESHOOTING.md
   ```

---

## ZASADA 6: Przeszukiwanie Całego Projektu (CRITICAL)

**Gdy użytkownik prosi o sprawdzenie/zmianę czegoś w CAŁYM projekcie:**

### OBOWIĄZKOWE kroki:

1. **Case-insensitive search** - ZAWSZE używaj flagi `-i`:
   ```bash
   # ✅ POPRAWNIE
   Grep pattern="od.*zł" -i="true"

   # ❌ ŹÓLE - pominie "od", "OD", "Od"
   Grep pattern="Od.*zł"
   ```

2. **Wielokrotne wzorce** - szukaj WSZYSTKICH wariantów:
   ```bash
   # Szukaj różnych form zapisu
   Grep pattern="[Oo]d.*zł|from.*price|price.*from"
   ```

3. **Sprawdź WSZYSTKIE typy plików**:
   - `.blade.php` - widoki
   - `.php` - kontrolery, modele, serwisy
   - `.js`, `.vue` - frontend
   - `.css` - style (może być content w CSS)

4. **Weryfikacja po zmianie** - upewnij się że ZERO wyników:
   ```bash
   Grep pattern="szukany_tekst" -i="true"
   # Oczekiwany wynik: "No matches found"
   ```

### Incident 2026-02-05:
Użytkownik poprosił o usunięcie "Od" z cen. Szukałem tylko "Od" (wielka litera),
pominąłem "od" (mała litera) w `service-details.blade.php`.

**Root cause:** Brak case-insensitive search.
**Zapobieganie:** ZAWSZE `-i` przy project-wide searches.

---

## ZASADA 7: Weryfikacja na Staging (CRITICAL)

**CI PASS ≠ DZIAŁA!**

### Po KAŻDYM deploy na staging:

1. **Otwórz przeglądarkę** - wejdź na staging URL
2. **Przetestuj RĘCZNIE** zmienioną funkcjonalność
3. **Pełen cykl** - nie tylko "wyświetla się" ale też "zapisuje i ładuje"
4. **DOPIERO WTEDY** powiedz użytkownikowi "gotowe"

### Przed commitem funkcjonalności UI:

```
NIE WYSTARCZY:
✓ Pint przechodzi
✓ PHPUnit przechodzi
✓ Kod wygląda dobrze

WYMAGANE DODATKOWO:
✓ Otworzyłem przeglądarkę lokalnie
✓ Przetestowałem CAŁY flow (create/save/reload/edit)
✓ Sprawdziłem edge cases (pusty formularz, 1 element, wiele elementów)
```

### Incident 2026-02-05: [object Object] bug

**Sytuacja:** Wdrożyłem Service Location Types, CI przeszło, powiedziałem "gotowe".
**Rzeczywistość:** Na stagingu Repeater pokazywał [object Object], nie zapisywał.
**Przyczyna:** Nie zweryfikowałem ręcznie na stagingu. Bug był w istniejącym kodzie.

**Lekcja:** "CI zielone" to MINIMUM, nie WYSTARCZAJĄCE kryterium sukcesu.

---

## ZASADA 8: ZAWSZE Agenci Przed Działaniem (CRITICAL)

**NIGDY nie rozpoczynaj implementacji bez uprzedniego użycia agenta!**

Pełne zasady: → `.claude/rules/agent-usage.md` (TIER 1)

### Minimum:

```
1. STOP — nie pisz kodu!
2. Uruchom agenta (Explore/Plan/laravel-senior-architect)
3. Agent analizuje zależności, istniejący kod, edge cases
4. Dopiero po raporcie agenta → implementuj
```

### Dlaczego:

Agenci wychwytują zależności których nie widać na pierwszy rzut oka.
Bez agenta łatwo pominąć wymagane setup (seedery, role, migracje).

### Incident 2026-03-14: RoleDoesNotExist

**Sytuacja:** Implementacja onboardingu bez agenta architektury.
**Skutek:** `assignRole('admin')` crash bo tabela `roles` pusta na fresh DB.
**Root cause:** Brak `Role::firstOrCreate()` — agent by to wychwycił analizując zależności Spatie.
**Lekcja:** "Proste" zadanie miało ukrytą zależność. Agent = kontrola jakości.

---

## Checklist po KAŻDYM rozwiązanym błędzie

- [ ] Zrozumiałem ROOT CAUSE (nie tylko symptom)
- [ ] Udokumentowałem w odpowiednim miejscu
- [ ] Dodałem reguły zapobiegające powtórzeniu
- [ ] Deploy przeszedł pomyślnie
- [ ] **ZWERYFIKOWAŁEM RĘCZNIE na staging** (dla zmian UI)
- [ ] Mogę kontynuować pracę
