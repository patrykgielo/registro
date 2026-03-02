# Agent Usage Rules - OBOWIĄZKOWE

## ZASADA GŁÓWNA

**ZAWSZE używaj agentów z `.claude/agents/` do KAŻDEGO zadania.**

Nawet do najmniejszych zadań - agenci mają specjalistyczną wiedzę i kontrolują jakość.

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

### Security Agents - Hierarchia

| Agent | Rozmiar | Użycie |
|-------|---------|--------|
| `agent-security-audit-specialist` | 54KB | **GŁÓWNY** - pełne audyty OWASP/GDPR |
| `security-scanner` | 1.8KB | Legacy stub - użyj głównego |
| `security-advisor` | 1.6KB | Legacy stub - użyj głównego |

⭐ **Zawsze używaj `agent-security-audit-specialist`** dla zadań bezpieczeństwa.

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

**Agenci = kontrola jakości + specjalistyczna wiedza**

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
