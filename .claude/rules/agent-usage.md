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
| CI/CD, Docker | `devops-engineer` |
| Security, OWASP | `agent-security-audit-specialist` |
| Web research, docs | `web-research-specialist` |
| Code review (po implementacji) | `code-reviewer` (OBOWIĄZKOWY) |
| ClickUp | `clickup-task-manager` |
| Koordynacja wielu agentów | `project-coordinator` |

**Research:** Firecrawl = domyślne (statyczne strony). Browser-use = loginy, SPA, multi-step.

## Minimalny agent per zadanie

| Akcja | Minimum |
|-------|---------|
| Nowy feature | `Explore` → `laravel-senior-architect` |
| Bug fix | `Explore` (root cause) → fix |
| Frontend nowy | `web-research-specialist` → `frontend-ui-architect` |
| Frontend modyfikacja | `Explore` → `frontend-ui-architect` |
| Security | `agent-security-audit-specialist` |

## Agent Teams

`CLAUDE_CODE_EXPERIMENTAL_AGENT_TEAMS=1` — max 3 teammates równolegle.

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
