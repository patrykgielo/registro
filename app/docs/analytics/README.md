# Analytics Documentation

Ten katalog zawiera dokumentację systemu analitycznego Registro. Dwa systemy, dwie dokumentacje.

## Pliki

| Plik | Audience | Zawartość |
|------|---------|----------|
| [`technical-reference.md`](technical-reference.md) | Developerzy, PM, QA | Event taxonomy, schema, API, latency, ops commands |
| [`client-guide.md`](client-guide.md) | Administratorzy tenantów (PL) | Co, gdzie i jak czytać dane — bez technicznych szczegółów |
| [`behavioral-analytics-implementation-plan.md`](behavioral-analytics-implementation-plan.md) | Developerzy | Plan implementacji Phase B/C/D + spec botów symulujących ruch |

## Szybkie linki

- Panel admina: `/admin/statystyki` (KPI finansowe) + `/admin/analityka` (ruch na stronie)
- Panel platformy: `/platform/statystyki` (SaaS MRR, tenants)
- Testy: `tests/Feature/Analytics/` (4 klasy, ~50 asercji)
- GDPR: [`../legal/analytics-gdpr-lia.md`](../legal/analytics-gdpr-lia.md)
- Tracking plan (history): [`../features/analytics-event-tracking.md`](../features/analytics-event-tracking.md)
- Future roadmap: [`../features/analytics-expansion-plan.md`](../features/analytics-expansion-plan.md)
