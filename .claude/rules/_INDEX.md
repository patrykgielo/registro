# Rules Index

## TIER 1 — ZAWSZE (bez `paths`, ładowane w każdej sesji)

`self-improvement.md` · `agent-usage.md` · `git-workflow.md` · `deployment.md` · `planning.md`
oraz ten plik.

Budżet **12 000 znaków łącznie**, mierzy `cc-doctor`. Ponad nim reguły konkurują o uwagę i te
niżej są stosowane rzadziej, **bez żadnego sygnału**. Nowa reguła zawsze-ładowana = wytnij tyle
samo gdzie indziej albo zawęź `paths`.

## TIER 2 — ładowane automatycznie po `paths`

| Plik | Paths | Co pokrywa |
|------|-------|-----------|
| `claude-code-config.md` | `.claude/**`, `CLAUDE.md` | konfiguracja CC, znane bugi |
| `ci-cd-troubleshooting.md` | `docker-compose*.yml`, `.github/workflows/**`, `scripts/**` | kroniki incydentów |
| `models.md` | `app/Models/**` | Eloquent, BelongsToOrganization |
| `filament.md` | `app/Filament/**` | v4 namespace (BREAKING) |
| `filament-resources.md` | `app/Filament/Resources/**` | zasoby |
| `filament-settings-pages.md` | `app/Filament/Pages/*Settings.php` | strony ustawień |
| `spatie-roles.md` | `app/Actions/**` | role i uprawnienia |
| `tests.md` | `tests/**` | testy PHP i powłoki |
| `migrations.md` | `database/migrations/**` | migracje, rollback |
| `security.md` | `app/Http/Controllers/Auth/**` | OWASP |
| `notifications.md` | `app/Notifications/**` | powiadomienia, kolejki |
| `onboarding.md` | `app/Actions/Onboarding/**`, `ProvisionTenantCommand.php` | provisioning z CLI |
| `services.md` | `app/Services/**` | serwisy, DI |
| `controllers.md` | `app/Http/Controllers/**` | kontrolery |
| `frontend-quality.md` | `resources/views/**` | jakość frontu, a11y |
| `animations.md` | `resources/css/**` | animacje |
| `blade-components.md` | `resources/views/components/**` | komponenty Blade |
| `dark-theme.md` | `resources/css/**` | ciemne sekcje |
| `middleware.md` | `app/Http/Middleware/**` | middleware |
| `api-endpoints.md` | `routes/api.php` | API |
| `events-listeners.md` | `app/Events/**` | zdarzenia |
| `console-commands.md` | `app/Console/Commands/**` | komendy CLI |
| `polish-tax-ids.md` | `app/Rules/Valid*` | NIP/PESEL/REGON |
| `release-documentation.md` | `docs/releases/**` | dokumentacja wydań |
