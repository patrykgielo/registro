# Self-Improvement Rules - CRITICAL

## ZASADA 0: PRODUCTION WYMAGA ZGODY (BEZWZGLĘDNA)

**NIGDY nie taguj/deployuj na produkcję bez zgody użytkownika!**
`git tag vX.Y.Z` i `git push origin vX.Y.Z` → STOP, zapytaj najpierw.
Tag triggeruje automatyczny deploy = NIEODWRACALNE.

## ZASADA 1: Czekaj na Deploy

Po każdym merge do develop: sprawdź `gh run list --branch develop --limit 1`.
Jeśli FAILED → diagnozuj i napraw ZANIM przejdziesz dalej.

## ZASADA 2: Analiza Błędów

Napotkałeś błąd → zrozum ROOT CAUSE (nie zgaduj) → udokumentuj:
- Rules w `.claude/rules/` — reguły zapobiegające
- `app/docs/` — dla znaczących incydentów

Format: **Problem / Przyczyna / Rozwiązanie / Zapobieganie**

## ZASADA 3: Research Przed Zgadywaniem

Nie wiesz? → `web-research-specialist` + firecrawl. Nie próbuj na ślepo.

## ZASADA 4: Przeszukiwanie Projektu

Project-wide search → ZAWSZE case-insensitive (`-i`). Szukaj wszystkich wariantów.
Incident 2026-02-05: szukano "Od" (wielka) → pominięto "od" (mała) w blade.

## ZASADA 5: Weryfikacja UI

CI zielone ≠ działa. Po zmianach UI: otwórz przeglądarkę, przetestuj pełen flow.
Incident 2026-02-05: [object Object] bug — CI przeszło, staging nie działał.

## ZASADA 6: ZAWSZE Agent Przed Działaniem

→ patrz `agent-usage.md`

## Checklist po każdym błędzie

- [ ] Zrozumiałem root cause
- [ ] Udokumentowałem w rules/docs
- [ ] Deploy przeszedł
- [ ] Zweryfikowałem ręcznie (UI changes)
