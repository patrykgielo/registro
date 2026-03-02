# Deployment Rules - CRITICAL

## Current Status

**No staging/production servers configured.** Development is local only via Docker Compose.

All GitHub Actions workflows are set to `workflow_dispatch` (manual trigger only).

---

## BEZWZGLĘDNY ZAKAZ - NIGDY NIE RÓB TEGO:

1. **NIGDY nie commituj .env, .env.production, .env.staging** - są w .gitignore
2. **NIGDY nie uruchamiaj seederów w CI/CD pipeline** - seedery nadpisują dane edytowane przez admina

## .env File Management

### Forbidden in Workflows

```yaml
# ZABRONIONE - Download .env from GitHub
- name: Download .env
  run: curl https://raw.githubusercontent.com/.../env.production

# ZABRONIONE - Write .env from secrets
- name: Create env
  run: echo "APP_KEY=${{ secrets.APP_KEY }}" > .env
```

## Critical Variables (MUST be validated)

```bash
APP_KEY          # Non-empty, base64:... format
REDIS_PASSWORD   # Non-empty
DB_PASSWORD      # Non-empty
FILESYSTEM_DISK  # Must be 'public' (not 'local')
APP_DEBUG        # Must be 'false' for production
```

## Seeder Management (CRITICAL)

**NIGDY nie uruchamiaj `php artisan db:seed` w deploy scripts!**

| Kontekst | Dozwolone? |
|----------|------------|
| Lokalne dev (`php artisan migrate:fresh --seed`) | Tak |
| CI testy (ephemeral DB) | Tak |
| Deploy staging/production | NIGDY |

## CI/CD Workflows (GitHub Actions)

All workflows disabled (workflow_dispatch only). Will be reconfigured when servers are set up.

| Workflow | Status |
|----------|--------|
| `ci-staging.yml` | Disabled |
| `test.yml` | Disabled |
| `deploy-production.yml` | Disabled |
| `fix-styling.yml` | Disabled |
| `changelog.yml` | Disabled |
| `cleanup-cache.yml` | Disabled |
