# Agent Usage Rules - CRITICAL (TIER 1)

## ZASADA GŁÓWNA — BEZWZGLĘDNA

**NIGDY nie pisz kodu / nie diagnozuj / nie zmieniaj konfiguracji bez agenta.**
Incident 2026-03-14: onboarding bez agenta → pominięto Spatie roles → crash.

```
STOP → agent → implementuj
```

## Który agent do czego

| Zadanie | Agent |
|---------|-------|
| Laravel/PHP, Filament (logic) | `laravel-senior-architect` |
| Frontend, Blade, Tailwind, UI | `frontend-ui-architect` |
| Testy, PHPUnit, TDD | `test-engineer` |
| Skrypty, Docker, nginx, certy, CI, serwer | `registro-devops-engineer` (pisze) |
| Review infrastruktury | `devops-engineer` (read-only) |
| Security, OWASP | `agent-security-audit-specialist` |
| Web research, docs | `web-research-specialist` |
| Code review (po implementacji) | `code-reviewer` (OBOWIĄZKOWY) |
| ClickUp | `clickup-task-manager` |
| Koordynacja wielu agentów | `project-coordinator` |

**Research:** Firecrawl = domyślne (statyczne strony). Browser-use = loginy, SPA, multi-step.

**Modele:** wszyscy agenci na aliasach (`sonnet`/`opus`/`haiku`), nigdy przypięta wersja — szczegóły i tabela w `claude-code-config.md`.

**Claude sam NIE uruchamia `/code-review` ani `/verify`** — wywołuj jawnie (szczegóły: `claude-code-config.md`).

## Minimalny agent per zadanie

| Akcja | Minimum |
|-------|---------|
| Nowy feature | `Explore` → `laravel-senior-architect` |
| Bug fix | `Explore` (root cause) → fix |
| Frontend nowy | `web-research-specialist` → `frontend-ui-architect` |
| Frontend modyfikacja | `Explore` → `frontend-ui-architect` |
| Security | `agent-security-audit-specialist` |

## Weryfikacja NIE dotyka dev-bazy

Tinker **tylko do odczytu**. Zapis do dev-MySQL zabroniony — także „na chwilę, zaraz cofnę".

**Gdy musisz zobaczyć realną wartość:** tymczasowy `fwrite(STDERR, ...)` w teście i
`docker compose exec -T app php artisan test` (SQLite). Nigdy `tinker --execute` z `factory()->create()`
— zagnieżdżone fabryki tworzą więcej wierszy, niż widzisz.

**Sprzątanie agenta weryfikuj LICZNIKIEM, nie jego raportem.** Dwa razy raport brzmiał „usunąłem
wszystko" i dwa razy zostawała sierota: 2026-08-07 (audytor dopisał rolę realnemu super-adminowi),
2026-08-12 (fabryka zrobiła DWÓCH userów w tej samej sekundzie, agent skasował jednego). Policz
wiersze przed i po oraz `WHERE created_at >= NOW() - INTERVAL 3 HOUR` po wszystkich tabelach.

## Bounded Retry — Auto-Fix Loops

Ten SAM błąd/test 3 razy bez postępu (identyczny błąd, brak zmiany w diffie) → STOP, zgłoś
człowiekowi. Licz per konkretny błąd, nie per plik. Inny próg niż `feedback_stop_and_research`
(2 próby na NIEZDIAGNOZOWANYM problemie → research): tu błąd jest ZNANY, więc eskalacja, nie research.

## ClickUp

Tylko praca nad produktem. Zero ticketów o Claude, promptach, regułach.
