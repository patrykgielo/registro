# Service Areas ID Skew Fix

**Date:** 2025-12-14
**Issue:** Service area ID=34 when only 1 record exists (expected IDs 1-3)
**Root Cause:** Multiple `migrate:fresh --seed` runs without AUTO_INCREMENT reset

## Problem Description

The `service_areas` table has AUTO_INCREMENT=35 but only contains 1 record (ID=34). This happened because:

1. Database was wiped and reseeded 11+ times during development
2. MySQL AUTO_INCREMENT persists across TRUNCATE operations
3. Seeder last run stopped after creating only first city (Warszawa)

## Investigation Results

```bash
# Current state (2025-12-14)
SELECT id, city_name, is_active, created_at FROM service_areas ORDER BY id;
# Result: Only ID=34 (Warszawa) exists

# AUTO_INCREMENT value
SELECT AUTO_INCREMENT FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'paradocks' AND TABLE_NAME = 'service_areas';
# Result: 35

# Expected: IDs 1, 2, 3 for Warszawa, Kraków, Gdańsk
```

## Solution 1: Complete Cleanup (Recommended for Development)

This resets AUTO_INCREMENT and recreates all 3 cities with sequential IDs.

```bash
# Step 1: Truncate table and reset AUTO_INCREMENT
docker compose exec mysql mysql -u paradocks -ppassword paradocks -e "
USE paradocks;
TRUNCATE TABLE service_areas;
ALTER TABLE service_areas AUTO_INCREMENT = 1;
"

# Step 2: Re-run seeder
docker compose exec app php artisan db:seed --class=ServiceAreaSeeder

# Step 3: Verify
docker compose exec mysql mysql -u paradocks -ppassword paradocks -e "
SELECT id, city_name, is_active FROM service_areas ORDER BY id;
"
# Expected: IDs 1, 2, 3
```

## Solution 2: Quick Fix (Keep ID=34, Add Missing Cities)

If you want to keep the existing Warszawa record at ID=34:

```bash
# Add missing cities
docker compose exec mysql mysql -u paradocks -ppassword paradocks -e "
USE paradocks;

INSERT INTO service_areas (city_name, latitude, longitude, radius_km, description, color_hex, sort_order, is_active, created_at, updated_at)
VALUES
('Kraków', 50.0647, 19.9450, 30, 'Kraków city and surrounding areas', '#2196F3', 2, 1, NOW(), NOW()),
('Gdańsk', 54.3520, 18.6466, 40, 'Tri-City area (Gdańsk, Gdynia, Sopot)', '#FF9800', 3, 1, NOW(), NOW());
"

# Verify
docker compose exec mysql mysql -u paradocks -ppassword paradocks -e "
SELECT id, city_name, is_active FROM service_areas ORDER BY sort_order;
"
# Expected: IDs 34, 35, 36
```

## Solution 3: Production-Safe Cleanup (For Staging/Production)

⚠️ **WARNING:** Only use if service_areas table has NO foreign key dependencies or related data.

```bash
# Step 1: Backup existing data
docker compose exec mysql mysql -u paradocks -ppassword paradocks -e "
SELECT * FROM service_areas;
" > /tmp/service_areas_backup.sql

# Step 2: Truncate and reset
docker compose exec mysql mysql -u paradocks -ppassword paradocks -e "
USE paradocks;
SET FOREIGN_KEY_CHECKS=0;
TRUNCATE TABLE service_areas;
ALTER TABLE service_areas AUTO_INCREMENT = 1;
SET FOREIGN_KEY_CHECKS=1;
"

# Step 3: Re-run seeder
docker compose exec app php artisan db:seed --class=ServiceAreaSeeder
```

## Prevention: Improve Seeder Idempotency

Update `ServiceAreaSeeder.php` to use `updateOrCreate()` instead of `create()`:

```php
// database/seeders/ServiceAreaSeeder.php
foreach ($areas as $area) {
    ServiceArea::updateOrCreate(
        ['city_name' => $area['city_name']], // Match on city_name
        $area // Update/create with full data
    );
}
```

This makes the seeder safe to run multiple times without creating duplicates.

## Verification Checklist

After cleanup:

- [ ] Exactly 3 service areas exist
- [ ] IDs are 1, 2, 3 (or sequential starting from 1)
- [ ] All cities active: Warszawa, Kraków, Gdańsk
- [ ] Filament admin panel shows all 3 areas at `/admin/service-areas`
- [ ] No duplicate city names
- [ ] AUTO_INCREMENT matches next expected ID (4 for Solution 1)

## Related Files

- **Model:** `/var/www/projects/paradocks/app/app/Models/ServiceArea.php`
- **Seeder:** `/var/www/projects/paradocks/app/database/seeders/ServiceAreaSeeder.php`
- **Migration:** `/var/www/projects/paradocks/app/database/migrations/2025_12_13_162408_create_service_areas_table.php`
- **Filament Resource:** `/var/www/projects/paradocks/app/app/Filament/Resources/ServiceAreaResource.php`

## Impact Assessment

**Development Environment:**
- ✅ Safe to use Solution 1 (complete cleanup)
- ✅ No foreign key dependencies exist yet
- ✅ No production data to preserve

**Staging/Production:**
- ⚠️ Check for `service_area_waitlist` entries first
- ⚠️ Check for any appointments using service areas
- ⚠️ Use Solution 3 with backup

## Commands Reference

```bash
# Check current state
docker compose exec mysql mysql -u paradocks -ppassword paradocks -e "
SELECT id, city_name, is_active, created_at FROM service_areas ORDER BY id;
"

# Check AUTO_INCREMENT
docker compose exec mysql mysql -u paradocks -ppassword paradocks -e "
SELECT TABLE_NAME, AUTO_INCREMENT FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'paradocks' AND TABLE_NAME = 'service_areas';
"

# Check for duplicates
docker compose exec mysql mysql -u paradocks -ppassword paradocks -e "
SELECT city_name, COUNT(*) as count FROM service_areas
GROUP BY city_name HAVING count > 1;
"

# Count total vs active
docker compose exec mysql mysql -u paradocks -ppassword paradocks -e "
SELECT COUNT(*) as total, COUNT(CASE WHEN is_active = 1 THEN 1 END) as active
FROM service_areas;
"
```
