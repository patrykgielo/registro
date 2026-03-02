#!/bin/bash
#######################################################################
# Rollback Profile Feature Script
# Created: 2025-11-27
# Purpose: Safely rollback Customer Profile & Settings feature
#
# Usage:
#   ./scripts/rollback-profile-feature.sh
#
# This script will:
# 1. Rollback the 3 profile-related migrations
# 2. Remove all new files created for this feature
# 3. Restore modified files to their original state
# 4. Clear all caches
# 5. Restart containers
#######################################################################

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}=========================================${NC}"
echo -e "${BLUE}  Rollback Profile Feature Started${NC}"
echo -e "${BLUE}=========================================${NC}"
echo ""

# Confirm before proceeding
echo -e "${YELLOW}WARNING: This will rollback all Customer Profile feature changes!${NC}"
echo ""
read -p "Are you sure you want to continue? (y/N) " -n 1 -r
echo ""
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo -e "${RED}Rollback cancelled.${NC}"
    exit 1
fi

echo ""

# Step 1: Rollback migrations
echo -e "${BLUE}[1/5] Rolling back migrations...${NC}"
docker compose exec app php artisan migrate:rollback --step=3
echo -e "${GREEN}✓ Migrations rolled back${NC}"
echo ""

# Step 2: Remove new files
echo -e "${BLUE}[2/5] Removing new files...${NC}"

# Models
rm -f app/Models/UserVehicle.php && echo "  - Removed app/Models/UserVehicle.php"
rm -f app/Models/UserAddress.php && echo "  - Removed app/Models/UserAddress.php"

# Services
rm -f app/Services/ProfileService.php && echo "  - Removed app/Services/ProfileService.php"
rm -f app/Services/UserVehicleService.php && echo "  - Removed app/Services/UserVehicleService.php"
rm -f app/Services/UserAddressService.php && echo "  - Removed app/Services/UserAddressService.php"

# Controllers
rm -f app/Http/Controllers/ProfileController.php && echo "  - Removed app/Http/Controllers/ProfileController.php"
rm -f app/Http/Controllers/UserVehicleController.php && echo "  - Removed app/Http/Controllers/UserVehicleController.php"
rm -f app/Http/Controllers/UserAddressController.php && echo "  - Removed app/Http/Controllers/UserAddressController.php"

# Form Requests
rm -rf app/Http/Requests/Profile/ && echo "  - Removed app/Http/Requests/Profile/"

# Views
rm -rf resources/views/profile/ && echo "  - Removed resources/views/profile/"

# JavaScript
rm -f resources/js/profile-manager.js && echo "  - Removed resources/js/profile-manager.js"

# Filament Relation Managers
rm -f app/Filament/Resources/CustomerResource/RelationManagers/VehiclesRelationManager.php && echo "  - Removed VehiclesRelationManager.php"
rm -f app/Filament/Resources/CustomerResource/RelationManagers/AddressesRelationManager.php && echo "  - Removed AddressesRelationManager.php"

# Migrations (these are tracked by Laravel, but remove the files)
rm -f database/migrations/*_create_user_vehicles_table.php 2>/dev/null && echo "  - Removed create_user_vehicles_table migration"
rm -f database/migrations/*_create_user_addresses_table.php 2>/dev/null && echo "  - Removed create_user_addresses_table migration"
rm -f database/migrations/*_add_profile_fields_to_users_table.php 2>/dev/null && echo "  - Removed add_profile_fields_to_users migration"

echo -e "${GREEN}✓ New files removed${NC}"
echo ""

# Step 3: Restore modified files from git
echo -e "${BLUE}[3/5] Restoring original files...${NC}"

# Only restore if file was actually modified
if git diff --quiet app/Models/User.php 2>/dev/null; then
    echo "  - app/Models/User.php (no changes to restore)"
else
    git checkout HEAD -- app/Models/User.php && echo "  - Restored app/Models/User.php"
fi

if git diff --quiet app/Services/Email/EmailService.php 2>/dev/null; then
    echo "  - app/Services/Email/EmailService.php (no changes to restore)"
else
    git checkout HEAD -- app/Services/Email/EmailService.php && echo "  - Restored app/Services/Email/EmailService.php"
fi

if git diff --quiet app/Services/Sms/SmsService.php 2>/dev/null; then
    echo "  - app/Services/Sms/SmsService.php (no changes to restore)"
else
    git checkout HEAD -- app/Services/Sms/SmsService.php && echo "  - Restored app/Services/Sms/SmsService.php"
fi

if git diff --quiet app/Http/Controllers/BookingController.php 2>/dev/null; then
    echo "  - app/Http/Controllers/BookingController.php (no changes to restore)"
else
    git checkout HEAD -- app/Http/Controllers/BookingController.php && echo "  - Restored app/Http/Controllers/BookingController.php"
fi

if git diff --quiet app/Filament/Resources/CustomerResource.php 2>/dev/null; then
    echo "  - app/Filament/Resources/CustomerResource.php (no changes to restore)"
else
    git checkout HEAD -- app/Filament/Resources/CustomerResource.php && echo "  - Restored app/Filament/Resources/CustomerResource.php"
fi

if git diff --quiet routes/web.php 2>/dev/null; then
    echo "  - routes/web.php (no changes to restore)"
else
    git checkout HEAD -- routes/web.php && echo "  - Restored routes/web.php"
fi

if git diff --quiet database/seeders/EmailTemplateSeeder.php 2>/dev/null; then
    echo "  - database/seeders/EmailTemplateSeeder.php (no changes to restore)"
else
    git checkout HEAD -- database/seeders/EmailTemplateSeeder.php && echo "  - Restored database/seeders/EmailTemplateSeeder.php"
fi

echo -e "${GREEN}✓ Original files restored${NC}"
echo ""

# Step 4: Clear caches
echo -e "${BLUE}[4/5] Clearing caches...${NC}"
docker compose exec app php artisan optimize:clear
docker compose exec app php artisan filament:optimize-clear 2>/dev/null || echo "  - Filament cache already clear"
echo -e "${GREEN}✓ Caches cleared${NC}"
echo ""

# Step 5: Restart containers
echo -e "${BLUE}[5/5] Restarting containers...${NC}"
docker compose restart app horizon queue
echo -e "${GREEN}✓ Containers restarted${NC}"
echo ""

echo -e "${GREEN}=========================================${NC}"
echo -e "${GREEN}  Rollback Complete!${NC}"
echo -e "${GREEN}=========================================${NC}"
echo ""

# Verification hints
echo -e "${YELLOW}Verification steps:${NC}"
echo "1. Check migration status:"
echo "   docker compose exec app php artisan migrate:status"
echo ""
echo "2. Check that tables are removed:"
echo "   docker compose exec mysql mysql -u registro -ppassword registro -e \"SHOW TABLES LIKE 'user_%';\""
echo ""
echo "3. Check that users table columns are removed:"
echo "   docker compose exec mysql mysql -u registro -ppassword registro -e \"DESCRIBE users;\" | grep -E '(max_vehicles|email_marketing|pending_email|deletion_)'"
echo ""
echo "4. Verify app is working:"
echo "   curl -k https://registro.local:8444/admin"
echo ""

# If you need full database restore
echo -e "${YELLOW}If you need to restore the entire database from backup:${NC}"
echo "1. Find your backup file: ls -la backups/"
echo "2. Restore: docker compose exec -T mysql mysql -u registro -ppassword registro < backups/registro_pre_profile_YYYYMMDD_HHMMSS.sql"
echo "3. Clear cache: docker compose exec app php artisan optimize:clear"
echo ""
