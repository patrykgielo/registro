---
paths:
  - "database/migrations/**"
---

# Database Migration Rules

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

## Rollback Safety

Always implement `down()` method:
```php
public function down(): void
{
    Schema::dropIfExists('appointments');
}
```
