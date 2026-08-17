# Rules Index

## TIER 1 — ZAWSZE (bez `paths`, ładowane w każdej sesji)

`self-improvement.md` · `agent-usage.md` · `git-workflow.md` · `deployment.md` · `planning.md`
oraz ten plik.

Budżet **12 000 znaków łącznie**, mierzy `cc-doctor`. Ponad nim reguły konkurują o uwagę i te
niżej są stosowane rzadziej, **bez żadnego sygnału**. Nowa reguła zawsze-ładowana = wytnij tyle
samo gdzie indziej albo zawęź `paths`.

## TIER 2 — ładowane automatycznie po `paths` (24 pliki, `ls .claude/rules/` dla pełnej listy)

Format: `plik.md → paths`. Szukaj po pliku, który edytujesz — to jest trigger.

- `claude-code-config.md` → `.claude/**`, `CLAUDE.md` — konfiguracja CC, znane bugi
- `ci-cd-troubleshooting.md` → `docker-compose*.yml`, `.github/workflows/**`, `scripts/**` — kroniki incydentów
- `models.md` → `app/Models/**`
- `filament.md` → `app/Filament/**` — v4 namespace (BREAKING); `filament-resources.md` → `app/Filament/Resources/**`
- `filament-settings-pages.md` → `app/Filament/Pages/*Settings.php`
- `spatie-roles.md` → `app/Actions/**` — role i uprawnienia
- `tests.md` → `tests/**`
- `migrations.md` → `database/migrations/**`
- `security.md` → `app/Http/Controllers/Auth/**`
- `auth-redirects.md` → `app/Support/Auth/**`, `app/Http/Responses/**`, `app/Providers/Filament/**` — powrót po zalogowaniu, walidacja origin
- `notifications.md` → `app/Notifications/**`
- `onboarding.md` → `app/Actions/Onboarding/**`, `ProvisionTenantCommand.php`
- `services.md` → `app/Services/**`
- `controllers.md` → `app/Http/Controllers/**`
- `frontend-quality.md`, `animations.md`, `blade-components.md`, `dark-theme.md` → `resources/**`
- `middleware.md` → `app/Http/Middleware/**`
- `api-endpoints.md` → `routes/api.php`
- `events-listeners.md` → `app/Events/**`
- `console-commands.md` → `app/Console/Commands/**`
- `polish-tax-ids.md` → `app/Rules/Valid*` — NIP/PESEL/REGON
- `release-documentation.md` → `docs/releases/**`
