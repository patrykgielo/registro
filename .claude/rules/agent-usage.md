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

**Modele:** wszyscy agenci na aliasach (`sonnet`/`opus`/`haiku`), nigdy przypięta wersja. Opus = architektura i deep debug, Sonnet = 80% pracy.

**Od v2.1.215 Claude sam NIE uruchamia `/code-review` ani `/verify`** — wywołuj jawnie. Oba mają `disable-model-invocation`, więc nie wykonają się też w zaplanowanym odpaleniu `/loop`.

## Minimalny agent per zadanie

| Akcja | Minimum |
|-------|---------|
| Nowy feature | `Explore` → `laravel-senior-architect` |
| Bug fix | `Explore` (root cause) → fix |
| Frontend nowy | `web-research-specialist` → `frontend-ui-architect` |
| Frontend modyfikacja | `Explore` → `frontend-ui-architect` |
| Security | `agent-security-audit-specialist` |

## Weryfikacja NIE dotyka dev-bazy

Agent sprawdzający swoją pracę używa `php artisan test` (SQLite z `.env.testing`). Tinker **tylko do
odczytu**. Zapis do dev-MySQL w trakcie weryfikacji jest zabroniony — także „na chwilę, zaraz cofnę".

Incydent 2026-08-07: audytor bezpieczeństwa pobrał „pierwszego użytkownika z bazy" jako aktora testowego,
trafił na realnego super-admina, dopisał mu rolę i zostawił osierocone rekordy fabryki. Cofnął, ale nie
wszystko — sierotę znalazłem dopiero przy ręcznym sprawdzeniu.

## Bounded Retry — Auto-Fix Loops

Jeśli agent (dowolny) próbuje naprawić TEN SAM błąd/test 3 razy bez postępu
(identyczny błąd, brak zmiany w diffie) → STOP, zgłoś człowiekowi, NIE
kontynuuj czwartej próby automatycznie. Nie licz "prób" per plik czy funkcja
— licz per konkretny, powtarzający się błąd.

To inny próg niż w `feedback_stop_and_research.md` (2 próby na NIEZDIAGNOZOWANYM
problemie → research root cause). Tu chodzi o ZNANY, powtarzający się błąd w
pętli auto-fix (np. Gate 5b w `/implement`) → eskalacja do człowieka, nie research.

## ClickUp — ZAKAZ ticketów dla

Optymalizacja Claude, konfiguracja AI, usprawnienia promptów/rules.
ClickUp = tylko praca nad produktem.
