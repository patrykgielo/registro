# Pre-Production Deployment Checklist

**Created:** 2026-02-07
**Incident Reference:** Simple Repeater [object Object] bug (2026-02-05)

---

## MANDATORY BEFORE ANY PRODUCTION DEPLOY

This checklist prevents incidents where CI passes but application breaks.

---

## Phase 1: Staging Verification (BLOCKING)

### 1.1 CI/CD Status
```bash
# Verify staging deploy succeeded
gh run list --branch staging --limit 1
# Must show: completed, success
```

### 1.2 Data Integrity Check
```bash
# SSH to staging and verify critical settings
ssh -i ~/.ssh/id_ed25519_staging_deploy deploy@45.93.138.193 \
  "cd /var/www/paradocks && docker compose -f docker-compose.staging.yml exec -T app \
   php artisan tinker --execute=\"
     echo 'before_visit_items: ' . json_encode(
       \App\Models\Setting::where('group','booking_wizard')
         ->where('key','before_visit_items')->first()->value
     );
   \""

# Expected: ["Text 1", "Text 2"] (flat array)
# FAIL if: [["item" => "Text"]] or [object Object] or null
```

### 1.3 Manual UI Verification (5-10 min)

**REQUIRED - DO NOT SKIP!**

1. Open: https://srv1203357.hstgr.cloud/admin/system-settings
2. Login with admin credentials
3. Navigate to: **System rezerwacji** tab
4. Check **"Przed wizytą"** (Simple Repeater):
   - [ ] Text displays correctly (NOT [object Object])
   - [ ] Can add new item
   - [ ] Can edit existing item
   - [ ] Can delete item
   - [ ] **SAVE → RELOAD (F5) → Data persists**

5. Check **"Typy lokalizacji"** (Complex Repeater):
   - [ ] All fields display (name, icon, description)
   - [ ] Can add new type
   - [ ] **SAVE → RELOAD (F5) → Data persists**

6. Check **Wygląd** tab (FileUpload):
   - [ ] Logos display if set
   - [ ] Can upload new logo
   - [ ] **SAVE → RELOAD → Logo persists**

### 1.4 Error Log Check
```bash
ssh -i ~/.ssh/id_ed25519_staging_deploy deploy@45.93.138.193 \
  "tail -100 /var/www/paradocks/storage/logs/laravel.log | grep -i error"

# Expected: No critical errors related to Settings/Repeater
```

---

## Phase 2: Production Preparation

### 2.1 Database Backup (MANDATORY)
```bash
# Create backup BEFORE any production deploy
ssh deploy@72.60.17.138 \
  "cd /var/www/paradocks && ./scripts/backup-database.sh"

# Verify backup exists
ssh deploy@72.60.17.138 "ls -la /var/www/paradocks/backups/ | tail -5"
```

### 2.2 Check Current Production State
```bash
# What's currently on production?
ssh deploy@72.60.17.138 \
  "cd /var/www/paradocks && git describe --tags --always"

# Check current settings format (before migration)
ssh deploy@72.60.17.138 \
  "cd /var/www/paradocks && docker compose exec -T app \
   php artisan tinker --execute=\"
     \\\$s = \App\Models\Setting::where('group','booking_wizard')
       ->where('key','before_visit_items')->first();
     echo \\\$s ? json_encode(\\\$s->value) : 'NOT_SET';
   \""
```

### 2.3 Migration Review
```bash
# List pending migrations
ssh deploy@72.60.17.138 \
  "cd /var/www/paradocks && docker compose exec -T app \
   php artisan migrate:status | grep -v 'Ran'"

# Check migration is safe (no destructive operations)
cat database/migrations/2026_02_05_220000_fix_repeater_settings_data_format.php
```

---

## Phase 3: Release Process

### 3.1 Create Release Branch
```bash
git checkout staging
git pull origin staging
git checkout -b release/vX.Y.Z
```

### 3.2 Update Release Notes
- Create/update `docs/releases/vX.Y.Z.md`
- List all changes (Features/Fixes/Improvements)

### 3.3 Create PR to Main
```bash
git push -u origin release/vX.Y.Z
gh pr create --base main --title "Release vX.Y.Z"
```

### 3.4 Merge and Tag (REQUIRES USER CONSENT!)

```
⛔ STOP! Ask user before tagging:

"Release vX.Y.Z jest gotowy do produkcji.
Zmiany: [lista zmian]
Backup bazy wykonany.
Staging zweryfikowany.

Czy mogę utworzyć tag i wdrożyć na produkcję?"

WAIT for explicit "Tak" / "OK" / "Wdrażaj"
```

```bash
# ONLY after user consent:
git checkout main
git pull origin main
git tag -a vX.Y.Z -m "Release vX.Y.Z"
git push origin vX.Y.Z
```

---

## Phase 4: Post-Deployment Verification

### 4.1 Deployment Status
```bash
# Wait for CI/CD (5 min approval gate)
gh run list --workflow=deploy-production.yml --limit 1

# Must show: completed, success
```

### 4.2 Migration Verification
```bash
ssh deploy@72.60.17.138 \
  "cd /var/www/paradocks && docker compose exec -T app \
   php artisan migrate:status | tail -5"

# Verify latest migration ran
```

### 4.3 Data Integrity Check
```bash
ssh deploy@72.60.17.138 \
  "cd /var/www/paradocks && docker compose exec -T app \
   php artisan tinker --execute=\"
     echo 'Settings check:' . PHP_EOL;
     echo 'before_visit_items: ' . json_encode(
       \App\Models\Setting::where('group','booking_wizard')
         ->where('key','before_visit_items')->first()->value
     );
   \""
```

### 4.4 Production Smoke Test
```bash
# Homepage
curl -f -s -o /dev/null -w "%{http_code}" https://paradocks.pl
# Expected: 200

# Admin panel
curl -f -s -o /dev/null -w "%{http_code}" https://paradocks.pl/admin/login
# Expected: 200

# API health
curl -f -s https://paradocks.pl/api/services | head -c 100
# Expected: JSON response
```

### 4.5 Manual Verification (CRITICAL)

**Login to production admin and verify:**
- [ ] Homepage loads correctly
- [ ] Booking wizard works
- [ ] Settings page loads without errors
- [ ] Repeater fields display correctly

---

## Phase 5: Rollback Plan (If Issues Found)

### 5.1 Quick Rollback (Code Only)
```bash
# Revert to previous tag
ssh deploy@72.60.17.138 "cd /var/www/paradocks && \
  git fetch --tags && \
  git checkout <previous_tag> && \
  composer install --no-dev --optimize-autoloader && \
  php artisan optimize"
```

### 5.2 Full Rollback (Code + Database)
```bash
# 1. Revert code
ssh deploy@72.60.17.138 "cd /var/www/paradocks && git checkout <previous_tag>"

# 2. Restore database
ssh deploy@72.60.17.138 "./scripts/restore-database.sh <backup_file>"

# 3. Clear caches
ssh deploy@72.60.17.138 "cd /var/www/paradocks && \
  docker compose exec -T app php artisan optimize:clear"

# 4. Verify
curl -I https://paradocks.pl
```

### 5.3 Emergency Contacts
- Server access: deploy@72.60.17.138
- GitHub: https://github.com/patrykgielo/paradocks
- CI/CD logs: https://github.com/patrykgielo/paradocks/actions

---

## Incident History

| Date | Issue | Root Cause | Prevention |
|------|-------|------------|------------|
| 2026-02-05 | [object Object] in Repeater | Wrong data format assumption | Added integration tests, staging verification |
| 2026-01-24 | FileUpload 500 error | Missing saveUploadedFileUsing | Added FileUpload rules in docs |
| 2026-01-07 | Redis crash | Empty REDIS_PASSWORD | Added env validation |

---

## Related Documentation

- [Git Workflow](./GIT_WORKFLOW.md)
- [Environment Variables](./environment-variables.md)
- [CI/CD Troubleshooting](./CI-CD-TROUBLESHOOTING.md)
- [Filament Settings Rules](../../.claude/rules/filament-settings-pages.md)
- [Self-Improvement Rules](../../.claude/rules/self-improvement.md)
