# Deployment Notes - Service Pages Feature (v0.3.0)

**Feature**: Service Pages with SEO optimization
**Branch**: `develop`
**Target Environment**: Staging → Production
**Deployment Date**: 2025-12-08
**Estimated Downtime**: ~30 seconds (migration run)

## Pre-Deployment Checklist

### 1. Code Review Status
- ✅ Code Review: 9.0/10 (APPROVED by laravel-senior-architect)
- ✅ Security Fixes: All CRITICAL issues resolved (XSS, Rate Limiting)
- ✅ Tests: Manual testing completed locally
- ✅ Documentation: Comprehensive (900+ lines)

### 2. Dependencies Check
```bash
# Verify composer dependencies installed
composer show | grep purifier
# Expected: mews/purifier 3.4.3
```

**New Dependencies**:
- `mews/purifier: ^3.4` - XSS protection (HTMLPurifier)
- Already in composer.json, no new installation needed

### 3. Environment Variables
**No new environment variables required** ✅

All configuration uses existing settings:
- `APP_NAME` - Used in Schema.org provider name
- `GOOGLE_MAPS_API_KEY` - Already configured

## Deployment Steps

### Step 1: Backup Current State

**Critical Backups**:
```bash
# 1. Database backup (before migration)
./scripts/backup-database.sh

# 2. Git commit hash
git log -1 --format="%H" > /tmp/pre-deployment-commit.txt

# 3. Services table snapshot
mysqldump paradocks services > /tmp/services_pre_migration.sql
```

### Step 2: Pull Latest Code

```bash
# On staging/production server
cd /var/www/paradocks/app
git fetch origin
git checkout develop
git pull origin develop

# Verify commit
git log -1 --oneline
# Expected: 63b9385 Merge feature/service-pages into develop
```

### Step 3: Install Dependencies

```bash
# Composer dependencies (includes mews/purifier)
composer install --no-dev --optimize-autoloader

# No new npm dependencies needed
```

### Step 4: Run Migration

**Migration File**: `2025_12_08_231804_add_cms_fields_to_services_table.php`

**What it does**:
1. Adds CMS fields to `services` table (slug, excerpt, body, content, etc.)
2. Auto-generates slugs for existing services
3. Sets `published_at = now()` for existing services
4. Adds unique constraint on `slug` column

**Estimated Duration**: ~2-3 seconds (8 services in database)

**Command**:
```bash
# Dry run (check SQL)
php artisan migrate --pretend

# Actual migration
php artisan migrate --force
```

**Expected Output**:
```
INFO  Running migrations.

2025_12_08_231804_add_cms_fields_to_services_table ........... [OK]

INFO  Migration completed successfully.
```

**Rollback Command** (if needed):
```bash
php artisan migrate:rollback --step=1
```

### Step 5: Clear Caches

```bash
# Clear all Laravel caches
php artisan optimize:clear

# Clear OPcache (restart PHP-FPM)
systemctl restart php8.2-fpm
# OR for Docker:
docker compose restart app

# Clear Filament caches
php artisan filament:optimize-clear
```

### Step 6: Verify Deployment

**Test URLs** (replace with actual domain):

1. **Homepage Service Cards**:
   ```
   https://staging.paradocks.com/
   # Verify: "Zobacz Szczegóły" button visible
   # Verify: Service cards clickable
   ```

2. **Service Index Page**:
   ```
   https://staging.paradocks.com/uslugi
   # Verify: HTTP 200
   # Verify: All published services listed
   ```

3. **Single Service Page**:
   ```
   https://staging.paradocks.com/uslugi/mycie-podstawowe
   # Verify: HTTP 200
   # Verify: Schema.org JSON-LD present (View Source)
   # Verify: OpenGraph tags present
   # Verify: "Zarezerwuj Termin" button works
   ```

4. **Schema.org Validation**:
   ```
   https://search.google.com/test/rich-results
   # Test URL: https://staging.paradocks.com/uslugi/mycie-podstawowe
   # Expected: Service + BreadcrumbList markup detected
   ```

5. **Rate Limiting**:
   ```bash
   # Test rate limiting (should block after 60 requests/min)
   for i in {1..65}; do curl -s -o /dev/null -w "%{http_code}\n" https://staging.paradocks.com/uslugi; done
   # Expected: First 60 = 200, remaining = 429 (Too Many Requests)
   ```

6. **Admin Panel**:
   ```
   https://staging.paradocks.com/admin/services
   # Verify: 4 sections visible (Podstawowe, Treść, Zaawansowane, SEO)
   # Verify: Auto-slug generation works
   # Verify: Preview button visible for published services
   ```

### Step 7: Monitoring

**First 30 Minutes**:
- Monitor error logs: `tail -f storage/logs/laravel.log`
- Monitor server load: `htop`
- Check failed jobs: `/admin/horizon/failed`
- Check application health: `curl https://staging.paradocks.com/up`

**First 24 Hours**:
- Google Search Console: Check for crawl errors
- Monitor page load times (should be <500ms)
- Check rate limiting logs (throttle middleware)
- Verify Schema.org markup in Google Search Console

## Migration Details

### Database Schema Changes

**Table**: `services`

**New Columns** (11 total):
```sql
-- Content fields
slug VARCHAR(255) UNIQUE NOT NULL
excerpt TEXT NULL
body TEXT NULL
content JSON NULL

-- SEO fields
meta_title VARCHAR(255) NULL
meta_description TEXT NULL
featured_image VARCHAR(255) NULL
published_at TIMESTAMP NULL (indexed)

-- Schema.org fields
price_from DECIMAL(10,2) NULL
area_served VARCHAR(255) NULL
```

**Indexes Added**:
- `services_slug_unique` - Unique constraint on slug
- `services_published_at_index` - For published scope queries

**Data Migration** (Automatic):
```sql
-- Auto-generate slugs
UPDATE services SET slug = LOWER(REPLACE(name, ' ', '-')) WHERE slug IS NULL;

-- Set all existing services as published
UPDATE services SET published_at = NOW() WHERE published_at IS NULL;
```

### Backwards Compatibility

✅ **100% Compatible** - All existing fields preserved:
- `id`, `name`, `description`, `duration_minutes`, `price`
- `is_active`, `sort_order`, `created_at`, `updated_at`

✅ **No Breaking Changes**:
- Existing booking system works unchanged
- Homepage cards continue to work (enhanced with clickable elements)
- Admin panel CRUD operations unchanged

## Rollback Procedure

### Quick Rollback (If Critical Issues Found)

**Step 1: Rollback Code**
```bash
cd /var/www/paradocks/app
git checkout <previous-commit-hash>
composer install --no-dev --optimize-autoloader
php artisan optimize:clear
systemctl restart php8.2-fpm
```

**Step 2: Rollback Database**
```bash
# Option A: Rollback migration (removes CMS fields)
php artisan migrate:rollback --step=1

# Option B: Restore from backup
mysql paradocks < /tmp/services_pre_migration.sql
```

**Step 3: Verify Rollback**
```bash
# Check services table schema
php artisan tinker --execute="
use Illuminate\Support\Facades\Schema;
echo 'Has slug column: ' . (Schema::hasColumn('services', 'slug') ? 'YES' : 'NO');
"
# Expected after rollback: NO
```

### Partial Rollback (Keep Database, Rollback Code)

If migration succeeded but code has issues:
```bash
# Keep CMS fields in database, just rollback code
git checkout <previous-commit-hash>
composer install --no-dev
php artisan optimize:clear
```

**Note**: New fields are nullable, so old code will ignore them safely.

## Post-Deployment Tasks

### Immediate (First Hour)

1. **Populate Service Content** (via Filament admin):
   - Add `excerpt` (short summary) for all 8 services
   - Add `meta_title` and `meta_description` for SEO
   - Upload `featured_image` for social sharing
   - Optionally add `body` content (RichEditor)

2. **Submit to Google Search Console**:
   - Submit sitemap with new `/uslugi/{slug}` URLs
   - Request indexing for top 3 services

3. **Test Booking Flow**:
   - Homepage → Service Page → Booking
   - Verify service pre-selection works

### First Week

1. **Monitor SEO Performance**:
   - Google Search Console: Impressions for service pages
   - Check Schema.org Rich Results appearance
   - Monitor organic traffic to `/uslugi/*` URLs

2. **Gather User Feedback**:
   - Heatmap analysis (service card clicks)
   - Booking conversion rate (homepage vs service page)
   - Mobile usability testing

3. **Content Optimization**:
   - Add gallery blocks for top services
   - Add video blocks (YouTube embed) for popular services
   - Optimize meta descriptions based on CTR

## Known Issues & Workarounds

### Issue 1: OPcache Not Clearing

**Symptom**: Code changes not applying immediately after deployment

**Workaround**:
```bash
# Restart PHP-FPM (clears OPcache workers)
systemctl restart php8.2-fpm

# Or for Docker:
docker compose restart app horizon queue scheduler
```

### Issue 2: View Cache Persists

**Symptom**: Blade templates show old content

**Workaround**:
```bash
php artisan view:clear
# AND delete compiled views manually:
rm -rf storage/framework/views/*
```

### Issue 3: Rate Limiting Too Aggressive

**Symptom**: Legitimate users getting 429 errors

**Workaround**:
```php
// Increase rate limit in routes/web.php
Route::middleware('throttle:120,1')->group(function () {
    // Service routes
});
```

## Metrics to Track

### Performance Metrics

**Target Performance**:
- Homepage load time: <800ms (with 8 service cards)
- Service page load time: <600ms (single service)
- Time to First Byte (TTFB): <200ms
- Lighthouse SEO score: 90+ (up from current score)

**Database Queries**:
- Homepage: 1 query (Service::published()->active()->get())
- Service index: 1 query (same as homepage)
- Service show: 2 queries (service + related services)

### SEO Metrics

**Week 1 Targets**:
- Google indexing: 8/8 service pages indexed
- Rich Results: Service markup detected for all pages
- Breadcrumb appearance: Visible in SERP snippets

**Month 1 Targets**:
- Organic impressions: +20% (new service page visibility)
- Click-through rate: +15% (Schema.org rich results)
- Avg. position: Improve by 5 positions for target keywords

### Business Metrics

**Conversion Tracking**:
- Booking conversion rate: Homepage cards vs Service pages
- Time on site: Expected increase (more content to read)
- Bounce rate: Expected decrease (better UX, clickable cards)

## Security Notes

### XSS Protection

**Implemented**:
- Video URL validation (YouTube/Vimeo only)
- HTMLPurifier for `body` content (`clean()` helper)
- Blade `{{ }}` escaping for user input

**Verification**:
```bash
# Test invalid video URL (should show error)
# In Filament: Add service with video block
# URL: javascript:alert(1)
# Expected: Validation error "URL musi być w formacie YouTube embed..."
```

### Rate Limiting

**Configured**:
- Throttle: 60 requests per minute per IP
- Applied to: `/uslugi` and `/uslugi/{slug}`

**Monitoring**:
```bash
# Check throttle logs
grep "429" storage/logs/laravel.log | tail -20
```

## Support Contacts

**Technical Issues**:
- Developer: [Your contact]
- Code Review: laravel-senior-architect agent (automated)

**Deployment Issues**:
- DevOps: [Your contact]
- Emergency Rollback: See "Rollback Procedure" above

## Documentation References

- **Feature Documentation**: `docs/features/service-pages/README.md`
- **Architecture Decisions**: `CLAUDE.md` (Service Pages section)
- **Code Review Report**: Commit `96f94e5` message
- **Security Fixes**: Commit `96f94e5` (XSS + Rate Limiting)

## Deployment Checklist

**Pre-Deployment** (Dev Environment):
- [x] Code review approved (9.0/10)
- [x] Security fixes implemented (XSS, Rate Limiting)
- [x] Migration tested locally
- [x] Dependencies verified (mews/purifier)
- [x] Documentation complete (900+ lines)

**Deployment** (Staging/Production):
- [ ] Backup database
- [ ] Git pull latest develop
- [ ] Composer install
- [ ] Run migration (`php artisan migrate --force`)
- [ ] Clear caches (`php artisan optimize:clear`)
- [ ] Restart PHP-FPM / Docker containers
- [ ] Verify all test URLs (6 checks)
- [ ] Monitor logs for 30 minutes

**Post-Deployment**:
- [ ] Populate service content (Filament admin)
- [ ] Submit sitemap to Google Search Console
- [ ] Test booking flow end-to-end
- [ ] Monitor SEO performance (first week)
- [ ] Gather user feedback

---

**Prepared by**: Claude Sonnet 4.5 (laravel-senior-architect)
**Review Date**: 2025-12-08
**Deployment Status**: Ready for Staging
**Production Readiness**: ✅ YES (post-security fixes)
