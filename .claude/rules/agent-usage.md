# Agent Usage Rules - CRITICAL (TIER 1)

## ZASADA GŁÓWNA — BEZWZGLĘDNA

**NIGDY nie rozpoczynaj żadnego działania bez uprzedniego użycia agenta.**

Dotyczy WSZYSTKIEGO: implementacji, diagnozowania, refactoringu, zmian konfiguracji, nawet "prostych" zmian.

**Naruszenie = przerwanie pracy.**

### Incident 2026-03-14: RoleDoesNotExist
Implementacja onboardingu bez agenta architektury → pominięto zależność Spatie roles → crash na produkcji.
Testy przeszły bo TestCase seeduje role, ale fresh DB nie ma ról. Agent by to wychwycił.

### Workflow PRZED każdym zadaniem:

```
1. STOP — nie pisz kodu!
2. Uruchom agenta (Explore/Plan/laravel-senior-architect)
3. Agent analizuje zależności, istniejący kod, edge cases
4. Dopiero po raporcie agenta → implementuj
```

## Kiedy używać którego agenta

| Zadanie | Agent |
|---------|-------|
| Laravel/PHP, architektura, refactoring | `laravel-senior-architect` |
| Frontend, Blade, Tailwind, UI/UX | `frontend-ui-architect` |
| Komponenty DaisyUI/iOS | `daisyui-ios-component-architect` |
| **Security audit, OWASP, GDPR, pełny audyt** | `agent-security-audit-specialist` ⭐ |
| Research, dokumentacja, nowa wiedza | `web-research-specialist` + firecrawl |
| ClickUp, task management | `clickup-task-manager` |
| Koordynacja wielu agentów | `project-coordinator` |
| Design tokens, spójność UI | `design-system-guardian` |
| Wyceny komercyjne | `commercial-estimate-specialist` |

### Security

Jeden agent: `agent-security-audit-specialist` (54KB). Legacy stuby `security-scanner` i `security-advisor` zostały usunięte (2026-03-20).

## Research i braki wiedzy

**KAŻDY brak wiedzy → `web-research-specialist` + firecrawl MCP**

Przykłady:
- Nie wiesz jak coś działa → research agent
- Potrzebujesz aktualnej dokumentacji → research agent
- Konfiguracja zewnętrznego serwisu → research agent
- Porównanie rozwiązań → research agent

## Wyrównanie konfiguracji

Gdy coś nie działa (staging vs production):
1. `laravel-senior-architect` - analiza konfiguracji Laravel/Docker
2. `web-research-specialist` - jeśli brak wiedzy o konkretnym serwisie

## NIGDY nie rób sam

- Nie diagnozuj błędów bez agenta
- Nie pisz kodu bez agenta
- Nie zmieniaj konfiguracji bez agenta
- Nie badaj problemu bez agenta
- Nie twórz dokumentacji bez agenta (Explore do audytu stanu)
- **Nie zakładaj "to proste"** — agent sprawdza zależności których nie widać

**Agenci = kontrola jakości + specjalistyczna wiedza**

### Minimalny agent per typ zadania

| Akcja | Minimum | Dlaczego |
|-------|---------|----------|
| Nowy kod / feature | `Explore` → `laravel-senior-architect` | Sprawdzi istniejące wzorce, zależności |
| Bug fix | `Explore` (root cause) → fix | Nie zgaduj, zbadaj |
| Refactoring | `Explore` → `Plan` | Znajdzie użycia, wpływ zmian |
| Frontend/UI | `Explore` → `frontend-ui-architect` | Sprawdzi komponenty, a11y |
| Dokumentacja | `Explore` (audit stanu) → pisanie | Sprawdzi co jest, czego brakuje |
| Security | `agent-security-audit-specialist` | Pełny OWASP check |

## ClickUp Ticket Rules

### ZAKAZ tworzenia ticketów dla:
- Optymalizacja agentów Claude
- Konfiguracja Claude Code
- Usprawnienia promptów/rules
- Wewnętrzne tooling AI

### DOZWOLONE tickety:
- Implementacja techniczna (features, bugfixy)
- Architektura aplikacji
- Infrastruktura (CI/CD, Docker, deployment)
- Bezpieczeństwo (OWASP, GDPR - audyty techniczne)
- Dokumentacja techniczna projektu

**ClickUp = tylko praca nad produktem, NIE nad narzędziami AI.**
