---
paths:
  - "database/migrations/**"
---

# Database Migration Rules

## Tenant-Scoped Unique Constraints (CRITICAL)

**Any table with `organization_id` MUST use composite unique constraints that include `organization_id`.**
Single-column uniques on tenant-scoped tables break multi-tenant onboarding: vertical seeders inserting
records (e.g., service names, page slugs) fail when a 2nd tenant signs up with the same data.

```php
// ❌ WRONG — breaks multi-tenancy
$table->unique('name');                    // services_name_unique
$table->unique('slug');                    // pages_slug_unique

// ✅ CORRECT — scoped per tenant
$table->unique(['organization_id', 'name'], 'services_org_name_unique');
$table->unique(['organization_id', 'slug'], 'pages_org_slug_unique');
```

**Exception — globally unique by design (no organization_id):** `orders.p24_session_id`,
`payments.p24_session_id`, `email_sends.message_key`, `sms_sends.message_key`.

**Exception — NULL-org global templates:** `email_templates` and `sms_templates` use
`(key, language)` global unique because all rows are NULL-org system templates. MySQL treats
NULL as distinct in unique indexes — converting to composite would break seed migration
idempotency (`insertOrIgnore` would allow duplicate NULL-org rows).

Incident 2026-06-29: 2nd equipment-rental tenant 500s on `UniqueConstraintViolationException`
at `services.services_name_unique`. Migration `2026_06_29_120000_fix_tenant_scoped_unique_constraints.php`
converted 9 constraints.

## FK onDelete Policy — tenant lifecycle (Faza 5.2)

`organization_id` FK behaviour is **category-driven**, not uniform:

- **Legal records** (`orders`, `payments`, `tenant_payments`, `rentals`) → `restrictOnDelete`. Must
  survive org deletion for ≥5–6 yrs (Art. 112 VAT / Art. 70 Ordynacja). The DB FK is the last-resort
  backstop; `OrganizationObserver::deleting()` throws `OrganizationHasLegalRecordsException` first.
- **Staff link** `appointments.staff_id` → `nullOnDelete` (column made nullable). Preserves historical
  appointments when a staff user is deleted. NEVER `restrict` here — it would conflict with the 5.1
  guard that only blocks *future* appointments.
- **Ephemeral** (`carts`, `statistics_daily_snapshots`, `analytics_events`) → `cascade`/`null`. OK to drop.

Changing an existing FK onDelete = `dropForeign(['col'])` → (optional `->nullable()->change()` guarded by
`DB::getDriverName() !== 'sqlite'`) → re-add `->foreign()...->restrictOnDelete()`. Ref:
`2026_06_30_000001_fix_lifecycle_fk_constraints.php`, `2026_03_20_000001_fix_rental_service_fk_cascade_behavior.php`.
When making a column nullable in `up()`, do NOT blindly restore NOT NULL in `down()` — it fails if null
rows exist; leave nullable (safe superset) or resolve nulls first.

## Security First

### Never in Migrations
- Raw SQL without bindings: `DB::statement("DELETE FROM users WHERE id = $id")`
- Plaintext passwords or secrets
- Default passwords in seeds that might leak to production

### Always Use
- Parameterized queries: `DB::statement("DELETE FROM users WHERE id = ?", [$id])`
- Environment variables for secrets
- Faker for test data, not real data

## Naming Conventions

```
YYYY_MM_DD_HHMMSS_action_table_column.php

Examples:
2025_01_15_100000_create_appointments_table.php
2025_01_15_100001_add_status_to_appointments_table.php
2025_01_15_100002_modify_duration_on_appointments_table.php
```

## Column Best Practices

### Indexes
- Foreign keys: Always add index
- Frequently queried: Add index
- Unique constraints: Use `unique()` not just index

```php
$table->foreignId('user_id')->constrained()->onDelete('cascade');
$table->string('email')->unique();
$table->index(['status', 'created_at']); // Compound index
```

### Soft Deletes
- Use `softDeletes()` for important records (users, appointments)
- Consider retention policies for GDPR compliance

### Timestamps
- Always use `timestamps()` for created_at/updated_at
- Add `deleted_at` with `softDeletes()` if needed

## Lokalne uruchomienie migracji (CRITICAL)

**Po utworzeniu migracji ZAWSZE powiedz uzytkownikowi ze musi uruchomic migracje lokalnie:**

```bash
docker compose exec -T app php artisan migrate
```

**Dlaczego:** Filament form fields odwoluja sie do nowych kolumn. Bez migracji zapis rekordu rzuci `SQLSTATE[42S22]: Column not found`.

**CI/CD uruchamia migracje automatycznie** (`php artisan migrate --force`) na staging i produkcji — ale lokalnie trzeba to zrobic recznie.

**Incident 2026-01-31:** Dodano pola `hero_overlay_color`/`hero_overlay_opacity` do ServiceResource bez uruchomienia migracji lokalnie. Zapis uslugi zwrocil blad Column not found.

### Checklist po utworzeniu migracji:
1. Uruchom lokalnie: `docker compose exec -T app php artisan migrate`
2. Dodaj nowe pola do `$fillable` w modelu
3. Dodaj `$casts` jesli potrzebne
4. Sprawdz ze CI/CD uruchamia `migrate --force` (juz skonfigurowane)
5. Dodaj wzmiankeo migracji w release notes

---

## Rollback Safety (CRITICAL — enforced automatically)

### Rules
- **MANDATORY:** Every `down()` must have a non-empty body. Empty body = blocked by `pre-commit` hook.
- **ALWAYS:** `Schema::dropIfExists()` not `Schema::drop()` — never fails on missing table.
- **Data-only migrations:** Cannot be reversed → use `throw new \RuntimeException('...')` explicitly.
- `MigrationRollbackTest` catches violations in CI before they reach develop.
- Manual audit: `php artisan migrations:check-rollback`
- Auto-run on merge/checkout: `.githooks/post-merge` + `.githooks/post-checkout` (activated via `composer install`)

### Patterns

```php
// Schema migration — always revert the column/table change
public function down(): void
{
    Schema::dropIfExists('appointments');
}

// Column change (nullable→NOT NULL): handle NULL rows FIRST or MySQL rejects the constraint
public function down(): void
{
    DB::table('users')->whereNull('password')->update([
        'password' => password_hash(\Illuminate\Support\Str::random(40), PASSWORD_BCRYPT),
    ]);
    Schema::table('users', function (Blueprint $table) {
        $table->string('password')->nullable(false)->change();
    });
}

// Irreversible data migration — explicit, never silent
public function down(): void
{
    throw new \RuntimeException('This migration is a data-only fix and cannot be rolled back safely.');
}
```

### Git Hooks Setup

Hooks live in `.githooks/` (committed to repo). They are activated automatically on `composer install`:
```bash
git config core.hooksPath .githooks
```

- `pre-commit` — rejects new migrations with empty `down()` (strips comments before checking)
- `post-merge` — auto-runs `php artisan migrate` if migration files changed
- `post-checkout` — same but on branch switches only
