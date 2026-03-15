# Rules Index - START HERE

## Quick Reference

**Before ANY task:** Check which TIER applies to your work.

---

## TIER 1 - CRITICAL (Read BEFORE every task)

| File | Purpose | When |
|------|---------|------|
| `self-improvement.md` | Error documentation, wait for deploy | ALWAYS (meta-rules) |
| `agent-usage.md` | ZAWSZE agenci przed działaniem! | ALWAYS (before ANY action) |
| `git-workflow.md` | Branch strategy, commit rules | Before ANY git operation |
| `deployment.md` | Deploy safety, env validation | Before ANY deployment |
| `security.md` | Auth, input validation, OWASP | Before writing ANY code |

**Automated Protection:** PreToolUse hook blocks dangerous operations automatically.

**Self-Learning:** After EVERY resolved error → document in rules/docs!

---

## TIER 2 - IMPLEMENTATION (Read before writing code in area)

| File | Paths | Key Rules |
|------|-------|-----------|
| `models.md` | `app/Models/**` | User first_name/last_name, Organization.industry, BelongsToOrganization, **Module System (Phase 6)** |
| `onboarding.md` | `app/Actions/Onboarding/**`, `app/Enums/Industry.php` | Industry enum, vertical seeders, registration flow |
| `spatie-roles.md` | `app/Actions/**`, `app/Listeners/**` | ZAWSZE firstOrCreate przed assignRole! **Module-namespaced permissions (Phase 6)** |
| `services.md` | `app/Services/**` | DI, SettingsManager integration |
| `controllers.md` | `app/Http/Controllers/**` | Thin controllers, delegate to services |
| `filament.md` | `app/Filament/**` | v4 namespace changes (BREAKING!) |
| `filament-settings-pages.md` | `app/Filament/Pages/*Settings.php` | Per-tab validation, HasGroupedSettings |
| `filament-resources.md` | `app/Filament/Resources/**` | StaysOnPageAfterSave, CreatesAndRedirectsToEdit, **$module gating (Phase 6)** |
| `notifications.md` | `app/Notifications/**` | Queue, ShouldBeUnique |
| `tests.md` | `tests/**` | CI uses SQLite, locale=pl |
| `migrations.md` | `database/migrations/**` | Security, indexes, rollback |
| `middleware.md` | `app/Http/Middleware/**` | Request lifecycle |
| `release-documentation.md` | `docs/releases/**` | Features/Fixes/Improvements sections |
| `polish-tax-ids.md` | `app/Rules/Valid*NIP*` | NIP/PESEL/REGON checksum=10 bug |

---

## TIER 3 - ENHANCEMENT (Improve quality)

| File | Purpose |
|------|---------|
| `frontend-quality.md` | A11y, performance |
| `animations.md` | GPU-accelerated patterns |
| `blade-components.md` | Livewire compatibility |
| `console-commands.md` | CLI structure |
| `events-listeners.md` | Event patterns |
| `api-endpoints.md` | REST security |
| `dark-theme.md` | Dark = ciemne tło sekcji, NIE systemowy dark mode |

---

## Checklists

### Before Committing
```bash
git branch  # NOT on main/develop?
./vendor/bin/pint --test  # Code style OK?
php artisan test  # Tests pass?
```

### Before Deploying
```bash
./scripts/validate-env.sh production
# Check: FILESYSTEM_DISK=public
# Check: APP_DEBUG=false
```

### Before PR
- Tests pass locally
- Code style passes (Pint)
- Documentation updated in `app/docs/`

---

## Path-Based Auto-Loading

Rules with `paths:` frontmatter load automatically when editing matching files:

```yaml
---
paths:
  - "app/Models/**"
---
```

This means `models.md` loads only when you edit models - keeps context clean.

---

## Settings Configuration

### Attribution (AI markers disabled)
```json
// .claude/settings.local.json
"attribution": {"commit": "", "pr": ""}
```

### PreToolUse Hook (auto-protection)
```json
"hooks": {
  "PreToolUse": [{
    "matcher": "Bash",
    "hooks": [{"type": "command", "command": ".claude/hooks/pre-tool-use.sh"}]
  }]
}
```
