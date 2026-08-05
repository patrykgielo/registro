# Rules Index

## TIER 1 — ZAWSZE (no paths frontmatter, load every session)

| File | Key Rule |
|------|----------|
| `self-improvement.md` | Production consent, error docs, search case-insensitive |
| `agent-usage.md` | ZAWSZE agent przed działaniem, który agent do czego, bounded retry (3 próby) |
| `git-workflow.md` | feature/* → develop → main, hook blokuje direct commits |
| `deployment.md` | FILESYSTEM_DISK=public, NIGDY migrate:fresh, .env.testing, zakaz /goal+/loop na destrukcyjnych op. |
| `planning.md` | Nowe zadanie = nowy plik planu |

TIER 1 ma budżet **12 000 znaków łącznie**. Nowa reguła zawsze-ładowana = wytnij tyle samo gdzie indziej albo zawęź `paths`.

## TIER 2 — NA ŻĄDANIE (paths frontmatter, load when editing matching files)

| File | Paths | Key Rules |
|------|-------|-----------|
| `claude-code-config.md` | `.claude/**`, `CLAUDE.md` | MCP tylko przez `claude mcp add`, znane bugi subagentów, worktree.baseRef |
| `ci-cd-troubleshooting.md` | `docker-compose*.yml`, `.github/workflows/**`, `scripts/**` | Kroniki incydentów: Docker API, dual queue worker ×2 |
| `models.md` | `app/Models/**` | first_name/last_name, BelongsToOrganization, Module System |
| `filament.md` | `app/Filament/**` | v4 namespace changes (BREAKING!) |
| `filament-resources.md` | `app/Filament/Resources/**` | StaysOnPageAfterSave, $module gating |
| `filament-settings-pages.md` | `app/Filament/Pages/*Settings.php` | Per-tab validation, HasGroupedSettings |
| `spatie-roles.md` | `app/Actions/**` | firstOrCreate przed assignRole! |
| `tests.md` | `tests/**` | SQLite in-memory, locale=pl, nigdy nie osłabiaj testu |
| `migrations.md` | `database/migrations/**` | Security, indexes, rollback |
| `security.md` | `app/Http/Controllers/Auth/**` | OWASP basics |
| `notifications.md` | `app/Notifications/**` | ShouldQueue, ShouldBeUnique |
| `onboarding.md` | `app/Actions/Onboarding/**` | Industry enum, vertical seeders |
| `services.md` | `app/Services/**` | DI, SettingsManager |
| `controllers.md` | `app/Http/Controllers/**` | Thin controllers |
| `frontend-quality.md` | `resources/views/**` | Animation GPU, a11y |
| `animations.md` | `resources/css/**` | transform/opacity only |
| `blade-components.md` | `resources/views/components/**` | Livewire compatibility |
| `dark-theme.md` | `resources/css/**` | Ciemne tło sekcji, NIE systemowy dark mode |
| `middleware.md` | `app/Http/Middleware/**` | Request lifecycle |
| `api-endpoints.md` | `routes/api.php` | REST security |
| `events-listeners.md` | `app/Events/**` | Event patterns |
| `console-commands.md` | `app/Console/Commands/**` | CLI structure |
| `polish-tax-ids.md` | `app/Rules/Valid*` | NIP/PESEL/REGON checksum bug |
| `release-documentation.md` | `docs/releases/**` | Features/Fixes/Improvements |
