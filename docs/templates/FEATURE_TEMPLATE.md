# [Feature Name]

**Version:** vX.Y.Z
**Status:** [Planning | In Development | Completed | Deprecated]
**Author:** [Your Name]
**Date:** YYYY-MM-DD

## Overview

[1-2 paragraph summary of feature]

**Business Value:**
[Why is this feature being built? What problem does it solve?]

**User Benefit:**
[How does this improve the user experience?]

**Scope:**
[What's included and what's explicitly excluded]

---

## Database Changes

### Migrations

**⚠️ REQUIRED FOR DEPLOYMENT**

#### Migration Files

1. **YYYY_MM_DD_HHMMSS_migration_name.php**
   - **Purpose:** [What does this migration do]
   - **Tables affected:** [table1, table2]
   - **Columns added:** [column1, column2]
   - **Indexes added:** [index1, index2]
   - **Rollback safe:** ✅ Yes / ❌ No (explain why)

**Schema Changes:**
```sql
-- Example schema changes
ALTER TABLE users ADD COLUMN password_setup_token VARCHAR(64) NULLABLE;
ALTER TABLE users ADD INDEX idx_password_setup_token (password_setup_token);
```

**Verification Commands:**
```bash
# Check migration applied
docker compose exec app php artisan migrate:status | grep migration_name

# Check columns exist
docker compose exec mysql mysql -u registro -ppassword registro -e "DESCRIBE users;"

# Check indexes exist
docker compose exec mysql mysql -u registro -ppassword registro -e "SHOW INDEXES FROM users;"
```

### Seeders

**⚠️ IMPORTANT:** Seeders are for development/testing ONLY. For production reference data, use **data migrations** instead.

- **Use seeders:** Local dev fixtures, test data
- **Use data migrations:** Email templates, SMS templates, lookup tables, system settings

**See:** [Data Migrations Guide](../../guides/data-migrations.md)

---

**⚠️ CRITICAL FOR DEPLOYMENT (if using data migrations)**

#### Seeder Files

1. **[SeederName]Seeder.php**
   - **Purpose:** Seed [lookup data / configuration / templates]
   - **Idempotent:** ✅ Yes (uses updateOrCreate) / ❌ No
   - **Production safe:** ✅ Yes (no test data, no user creation)
   - **Record count:** XX records
   - **Dependencies:** [Other seeders that must run first]

**Unique Constraints** (for idempotency):
```php
EmailTemplate::updateOrCreate(
    [
        'key' => $template['key'],           // Composite unique key
        'language' => $template['language'], // Composite unique key
    ],
    $template // All other fields
);
```

**Deploy Script Recognition:**
- ✅ If reference data (email templates, SMS templates, settings):
  - Use **data migration** instead of seeder
  - Runs automatically via `php artisan migrate --force`
  - See: [Data Migrations Guide](../../guides/data-migrations.md)

- ✅ If development-only data:
  - Keep as seeder in `DatabaseSeeder.php`
  - Only runs locally via `migrate:fresh --seed`

**Verification Commands:**
```bash
# Check records seeded
docker compose exec app php artisan tinker --execute="echo App\\Models\\[Model]::count()"

# Check specific records
docker compose exec app php artisan tinker --execute="App\\Models\\[Model]::where('key', 'value')->get()"

# Verify no duplicates
docker compose exec app php artisan tinker --execute="App\\Models\\[Model]::select('key')->groupBy('key')->havingRaw('COUNT(*) > 1')->get()"
```

---

## Configuration Changes

### Environment Variables

**New variables** (add to `.env.example` and production `.env`):
```bash
FEATURE_ENABLED=true
FEATURE_API_KEY=your-api-key-here
FEATURE_TIMEOUT=30
```

**Modified variables** (update existing):
```bash
EXISTING_VAR=new_value  # Changed from: old_value
```

**Docker Compose Variables:**
```yaml
# Add to docker-compose.prod.yml environment section
environment:
  - FEATURE_ENABLED=${FEATURE_ENABLED}
  - FEATURE_API_KEY=${FEATURE_API_KEY}
```

### Config Files

**Modified:** `config/feature.php`
```php
return [
    'enabled' => env('FEATURE_ENABLED', false),
    'api_key' => env('FEATURE_API_KEY'),
    'timeout' => env('FEATURE_TIMEOUT', 30),
];
```

---

## Deployment Steps

### Pre-Deployment Checklist

- [ ] Merge feature branch to develop
- [ ] Deploy to staging environment
- [ ] Run full regression tests
- [ ] Verify seeder idempotency (run twice, check no duplicates)
- [ ] Update CHANGELOG.md with feature summary
- [ ] Create release branch (release/vX.Y.Z)
- [ ] Update version numbers (composer.json, package.json)
- [ ] Notify stakeholders of deployment schedule

### Deployment Sequence

1. **GitHub Actions triggered** (tag push vX.Y.Z)
2. **Build Docker image** (5-10 min)
3. **Manual approval** (production environment)
4. **SSH to VPS** (automated)
5. **Pull new image** (1-2 min)
6. **Start new container** (zero-downtime strategy)
7. **Run migrations** (~15s downtime)
   ```bash
   docker exec app php artisan migrate --force
   ```
8. **Run seeders** (~5-30s additional downtime)
   ```bash
   docker exec app php artisan deploy:seed
   ```
9. **Switch traffic** (new container serves requests)
10. **Clear caches** (optimize, filament)
11. **Health check** (verify /up endpoint)

**Total downtime:** 20-45s (migrations + seeders)

### Post-Deployment Verification

```bash
# 1. Check feature works
curl -s https://registro.com/feature-endpoint | jq

# 2. Check database
docker compose exec mysql mysql -u registro -ppassword registro -e "SELECT COUNT(*) FROM table;"

# 3. Check logs for errors
docker compose logs -f app | grep "ERROR"

# 4. Check specific functionality
# [Add feature-specific verification steps here]
```

---

## Testing

### Unit Tests

**Files:**
- `tests/Unit/[Feature]Test.php`
- `tests/Unit/Services/[Feature]ServiceTest.php`
- `tests/Unit/Models/[Feature]ModelTest.php`

**Key test cases:**
```php
test('it_validates_required_fields')
test('it_handles_edge_cases')
test('it_returns_correct_data_structure')
test('it_throws_exception_on_invalid_input')
```

### Integration Tests

**Files:**
- `tests/Feature/[Feature]Test.php`
- `tests/Feature/Http/Controllers/[Feature]ControllerTest.php`

**Key test cases:**
```php
test('it_creates_resource_successfully')
test('it_returns_404_for_nonexistent_resource')
test('it_updates_resource_successfully')
test('it_deletes_resource_successfully')
test('it_requires_authentication')
test('it_requires_proper_permissions')
```

### Manual Testing Checklist

#### Happy Path
- [ ] [User flow step 1]
- [ ] [User flow step 2]
- [ ] [User flow step 3]

#### Edge Cases
- [ ] [Edge case 1]
- [ ] [Edge case 2]
- [ ] [Edge case 3]

#### Error Handling
- [ ] Invalid input
- [ ] Missing permissions
- [ ] Network failures
- [ ] Database errors

---

## Rollback Procedures

### Safe Rollback (No Database Changes)

```bash
# Deploy previous version
./scripts/deploy-update.sh vX.Y.Z-1
```

**When to use:**
- Bug in application code (not database-related)
- UI/UX issues discovered post-deployment
- Performance regression without data corruption

### Emergency Rollback (Database Restore)

```bash
# 1. Enable emergency maintenance
docker compose exec app php artisan maintenance:enable --type=emergency \
  --message="Emergency rollback in progress"

# 2. Stop application containers
docker compose stop app horizon scheduler

# 3. Restore database backup
docker compose exec mysql mysql -u registro -ppassword registro < backups/db-vX.Y.Z-1-YYYYMMDD_HHMMSS.sql

# 4. Deploy previous version (skip migrations)
./scripts/deploy-update.sh vX.Y.Z-1 --skip-migrations

# 5. Verify application health
docker compose exec app php artisan optimize:clear
curl -f https://registro.com/up

# 6. Disable maintenance
docker compose exec app php artisan maintenance:disable
```

**When to use:**
- Migration corrupted database
- Seeder failed partially (inconsistent state)
- Data integrity issues discovered

---

## Troubleshooting

### Issue 1: [Common Problem Name]

**Symptoms:**
[What the user sees / experiences]

**Cause:**
[Technical root cause]

**Solution:**
```bash
# Commands to fix
docker compose exec app php artisan fix:command
```

### Issue 2: Seeder Failure

**Symptoms:**
Deployment aborted with "Seeder execution failed - deployment aborted"

**Diagnosis:**
```bash
# Check logs
docker compose logs app | grep -A 20 "deploy:seed"

# Check database state
docker compose exec mysql mysql -u registro -ppassword registro -e "SELECT COUNT(*) FROM settings;"

# Check for constraint violations
docker compose logs app | grep "SQLSTATE"
```

**Solution:**
```bash
# If first deployment detection failed
docker compose exec app php artisan deploy:seed --force-all

# If specific seeder failed (run manually)
docker compose exec app php artisan db:seed --class=EmailTemplateSeeder --force

# If duplicate key error (unique constraint violated)
# Check for data corruption, restore backup if needed
```

### Issue 3: Migration Rollback Needed

**Symptoms:**
Migration applied but caused issues, need to rollback

**Solution:**
```bash
# Check migration status
docker compose exec app php artisan migrate:status

# Rollback last migration batch
docker compose exec app php artisan migrate:rollback --step=1

# Verify rollback
docker compose exec app php artisan migrate:status

# Re-run migrations if rollback succeeded
docker compose exec app php artisan migrate --force
```

---

## Architecture Decision Records

### ADR-XXX: [Decision Title]

**Date:** YYYY-MM-DD

**Context:**
[Why did we need to make this decision? What was the problem?]

**Decision:**
[What did we decide to do?]

**Consequences:**
[What are the trade-offs, benefits, and drawbacks of this decision?]

**Alternatives Considered:**
1. **[Alternative 1]** - Rejected because [reason]
2. **[Alternative 2]** - Rejected because [reason]

**Status:** [Proposed | Accepted | Deprecated | Superseded]

---

## Related Documentation

- [Link to API documentation]
- [Link to user guide]
- [Link to related feature documentation]
- [Link to relevant ADRs]

---

## Changelog

### vX.Y.Z - YYYY-MM-DD

**Added:**
- [New functionality]
- [New API endpoints]

**Changed:**
- [Modified behavior]
- [Updated dependencies]

**Fixed:**
- [Bug fixes]
- [Performance improvements]

**Security:**
- [Security improvements]
- [Vulnerability fixes]

---

**Last Updated:** YYYY-MM-DD
**Maintained By:** [Team Name]
**Review Cycle:** [Monthly | Quarterly | As Needed]
