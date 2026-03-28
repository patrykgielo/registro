---
name: SQLite CHECK constraint workaround in migrations
description: ALTER TABLE ... ADD CONSTRAINT ... CHECK is not supported in SQLite — must guard the statement
type: feedback
---

SQLite does not support `ALTER TABLE ... ADD CONSTRAINT ... CHECK (...)` syntax. Running it in a test migration crashes the entire migration batch with a PDO exception, failing all tests in the suite.

**Why:** The `cart_items` migration used this MySQL-only syntax for end_date >= start_date and quantity >= 1 constraints. SQLite silently accepts ENUM as varchar, but rejects ALTER TABLE CHECK constraints entirely.

**How to apply:** Any migration that adds CHECK constraints via `DB::statement('ALTER TABLE ... ADD CONSTRAINT')` must be guarded:

```php
if (DB::getDriverName() !== 'sqlite') {
    DB::statement('ALTER TABLE cart_items ADD CONSTRAINT chk_... CHECK (...)');
}
```

Apply this pattern to all future migrations that use MySQL-specific CHECK constraint syntax.
