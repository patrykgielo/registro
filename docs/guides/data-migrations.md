# Data Migrations Pattern

**Last Updated:** 2025-12-03
**Version:** v0.3.1

## Overview

Laravel distinguishes between two types of migrations:

1. **Schema Migrations** - Create/alter database tables
2. **Data Migrations** - Seed/update reference data in production

**Critical Rule:** Seeders are for development/testing ONLY. Use data migrations for production reference data.

---

## When to Use Data Migrations

Use data migrations when you need to:

✅ Add new email/SMS templates to production
✅ Update system settings or configuration
✅ Seed lookup tables (countries, currencies, categories)
✅ Backfill data after schema changes
✅ Update existing records (data transformations)

❌ **DO NOT use seeders for:**
- Production deployments
- Reference data updates
- Any automatic deployment process

---

## Pattern: Email/SMS Template Updates

### Example: Adding New Email Templates

**Scenario:** v0.3.0 adds 2 new email templates for admin-created user welcome.

**Wrong Approach (Seeders):**
```bash
# ❌ NEVER do this in production
docker compose exec app php artisan db:seed --class=EmailTemplateSeeder
```

**Correct Approach (Data Migration):**

**Step 1:** Create migration
```bash
docker compose exec app php artisan make:migration add_admin_user_welcome_email_templates
```

**Step 2:** Implement migration
```php
<?php
// database/migrations/2025_12_03_120000_add_admin_user_welcome_email_templates.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $templates = [
            [
                'key' => 'admin-user-created',
                'language' => 'pl',
                'subject' => 'Twoje konto zostało utworzone',
                'html_body' => '...',
                'text_body' => '...',
                'variables' => json_encode(['user_name', 'app_name', 'setup_url', 'expires_at']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'admin-user-created',
                'language' => 'en',
                'subject' => 'Your account has been created',
                'html_body' => '...',
                'text_body' => '...',
                'variables' => json_encode(['user_name', 'app_name', 'setup_url', 'expires_at']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        foreach ($templates as $template) {
            DB::table('email_templates')->insertOrIgnore($template);
        }
    }

    public function down(): void
    {
        DB::table('email_templates')
            ->where('key', 'admin-user-created')
            ->delete();
    }
};
```

**Step 3:** Commit and deploy
```bash
git add database/migrations/2025_12_03_120000_add_admin_user_welcome_email_templates.php
git commit -m "feat(email): add admin-user-created email templates"
git push

# Deployment runs automatically:
php artisan migrate --force  # ← Runs new data migration
```

---

## Project Standards

### Naming Convention

```
{YYYYMMDD}_{HHMMSS}_{action}_{description}.php
```

**Examples:**
- `2025_12_02_224732_seed_email_templates.php` (initial seed)
- `2025_12_03_120000_add_admin_user_welcome_templates.php` (incremental update)
- `2025_12_05_140000_update_sms_character_limits.php` (data transformation)

### Idempotency Pattern

Use `insertOrIgnore()` for safety:

```php
// Safe - silently skips duplicates (relies on unique constraint)
DB::table('email_templates')->insertOrIgnore($templates);
```

**Alternative:** Manual check
```php
foreach ($templates as $template) {
    $exists = DB::table('email_templates')
        ->where('key', $template['key'])
        ->where('language', $template['language'])
        ->exists();

    if (!$exists) {
        DB::table('email_templates')->insert($template);
    }
}
```

### Rollback Support

Always implement `down()` method:

```php
public function down(): void
{
    // Delete specific templates added in this migration
    DB::table('email_templates')
        ->whereIn('key', ['admin-user-created', 'password-setup-link'])
        ->delete();
}
```

---

## Seeders vs Data Migrations Comparison

| Aspect | Seeders | Data Migrations |
|--------|---------|----------------|
| **Purpose** | Development/testing fixtures | Production reference data |
| **Execution** | Manual (`db:seed`) | Automatic (`migrate --force`) |
| **Tracking** | Not tracked | Tracked in `migrations` table |
| **Idempotency** | Not guaranteed | Guaranteed (runs once) |
| **Rollback** | No rollback support | `down()` method available |
| **Production** | ❌ NEVER use | ✅ Standard practice |
| **Environment** | Local, staging (testing) | Production, staging, local |

---

## Common Mistakes to Avoid

### ❌ Mistake 1: Running seeders in production
```bash
# WRONG - seeders are NOT production-safe
docker compose exec app php artisan db:seed --class=EmailTemplateSeeder --force
```

### ❌ Mistake 2: Using updateOrCreate() in migrations
```php
// WRONG - uses Eloquent model (slow, ORM overhead)
EmailTemplate::updateOrCreate(['key' => 'xxx'], $data);
```

### ❌ Mistake 3: No idempotency checks
```php
// WRONG - will duplicate if run twice
DB::table('email_templates')->insert($templates);
```

### ✅ Correct Pattern
```php
// RIGHT - uses DB facade, idempotent, fast
DB::table('email_templates')->insertOrIgnore($templates);
```

---

## FAQ

**Q: When should I use seeders?**
A: Only for local development and testing. Never in production.

**Q: Can I run migrate:fresh --seed in production?**
A: **ABSOLUTELY NOT.** This will destroy all production data.

**Q: How do I update existing templates in production?**
A: Create a data migration that updates specific records by key.

**Q: What if I need to change ALL email templates?**
A: Create a data migration that loops through templates and updates them.

**Q: Can data migrations use Eloquent models?**
A: Technically yes, but **use DB facade instead** for performance and to avoid model logic side effects.

---

## Example: Initial Template Seeding (v0.3.1)

This project uses two data migrations for initial template seeding:

### 1. Email Templates Migration

**File:** `database/migrations/2025_12_02_224732_seed_email_templates.php`

**What it does:**
- Seeds 30 email templates (15 event types × 2 languages: PL, EN)
- Uses `insertOrIgnore()` with unique constraint on `(key, language)`
- Replaces `EmailTemplateSeeder` for production deployments

**Templates included:**
- user-registered, password-reset, appointment-created
- appointment-rescheduled, appointment-cancelled
- appointment-reminder-24h, appointment-reminder-2h
- appointment-followup, admin-daily-digest
- email-change-requested, email-change-verification, email-change-completed
- account-deletion-requested, account-deletion-completed
- admin-user-created

### 2. SMS Templates Migration

**File:** `database/migrations/2025_12_02_225216_seed_sms_templates.php`

**What it does:**
- Seeds 14 SMS templates (7 event types × 2 languages: PL, EN)
- Uses `insertOrIgnore()` with unique constraint on `(key, language)`
- Replaces `SmsTemplateSeeder` for production deployments

**Templates included:**
- appointment-created, appointment-confirmed
- appointment-rescheduled, appointment-cancelled
- appointment-reminder-24h, appointment-reminder-2h
- appointment-followup

---

## Example: Incremental Template Update

**Scenario:** Add new template for booking confirmation SMS with map link.

```php
<?php
// database/migrations/2025_12_15_140000_add_booking_confirmation_map_link_sms.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $templates = [
            [
                'key' => 'appointment-created-with-map',
                'language' => 'pl',
                'message_body' => 'Witaj {{customer_name}}! Rezerwacja na {{service_name}} dnia {{appointment_date}} o {{appointment_time}}. Mapa: {{map_url}}',
                'variables' => json_encode(['customer_name', 'service_name', 'appointment_date', 'appointment_time', 'map_url']),
                'max_length' => 160,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'appointment-created-with-map',
                'language' => 'en',
                'message_body' => 'Hi {{customer_name}}! Your {{service_name}} booking on {{appointment_date}} at {{appointment_time}}. Map: {{map_url}}',
                'variables' => json_encode(['customer_name', 'service_name', 'appointment_date', 'appointment_time', 'map_url']),
                'max_length' => 160,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        foreach ($templates as $template) {
            DB::table('sms_templates')->insertOrIgnore($template);
        }
    }

    public function down(): void
    {
        DB::table('sms_templates')
            ->where('key', 'appointment-created-with-map')
            ->delete();
    }
};
```

---

## Deployment Workflow

### Development Environment

```bash
# 1. Fresh database with all data
docker compose exec app php artisan migrate:fresh --seed

# What happens:
# - Schema migrations create tables
# - Data migrations seed email/SMS templates (30 + 14 = 44 total)
# - DatabaseSeeder runs development seeders (Settings, Roles, Vehicle Types, Services)
```

### Production Environment

```bash
# 1. Run migrations (includes data migrations)
docker compose exec app php artisan migrate --force

# What happens:
# - Only NEW migrations run (tracked in migrations table)
# - Data migrations seed new templates (if any)
# - Idempotent - safe to run multiple times
# - NO seeders run (seeders are development-only)
```

---

## See Also

- [Quick Start Guide](./quick-start.md) - Initial setup
- [Commands Reference](./commands.md) - All artisan commands
- [CI/CD Deployment Runbook](../deployment/runbooks/ci-cd-deployment.md) - Deployment process
- [Feature Template](../templates/FEATURE_TEMPLATE.md) - Feature documentation template

---

**Last Updated:** 2025-12-03
**Maintained By:** Development Team
**Review Cycle:** As Needed
