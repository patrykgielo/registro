<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DB-level lock against a 2nd organization, for dedicated tenant-stack containers.
 *
 * Why this needs to exist at all: BelongsToOrganization disables its tenant
 * filter entirely inside `runningInConsole()` (see the trait), and every queue
 * worker/scheduled job (Horizon, RecalculateDailyStatisticsJob, ProcessRemindersJob,
 * SendAdminDigestJob, MarkCartsAbandonedJob, ...) runs as console and iterates
 * `Organization::pluck('id')`/equivalent with NO tenant scoping. On a dedicated
 * single-tenant stack that is safe only because there is structurally nothing else
 * in the table to iterate into. A 2nd row — created by a bug, a bad migration
 * restore, or an operator running the wrong command against the wrong container —
 * would silently start mixing tenant A's reminders/digests/statistics into tenant
 * B's data with no exception and no `failed_jobs` entry. Optimistic checks in PHP
 * (e.g. inside registro:tenant-provision) can't close this gap by themselves
 * because nothing stops a 2nd insert through any OTHER path (tinker, a future
 * script, a bug). This has to be enforced where nothing can bypass it: the schema.
 *
 * Mechanism: a STORED GENERATED column that is always `1`, with a UNIQUE index on
 * it. MySQL then rejects any INSERT past the first row with a duplicate-key error,
 * regardless of which code path attempted it.
 *
 * Conditional on TENANT_SLUG (config('app.tenant_slug')), NOT unconditional, because:
 * - Dev and the shared legacy stack run TENANT_SLUG unset and host MANY
 *   organizations by design (this is the entire current product) — the lock would
 *   break them outright, not just in an edge case.
 * - SQLite (.env.testing — see deployment.md on why that file must never be
 *   touched) has no `GENERATED ALWAYS AS (...) STORED` support with a matching
 *   unique-index story portable with MySQL's; test suites never set TENANT_SLUG,
 *   so this migration is a clean no-op for them rather than something that needs
 *   SQLite-specific syntax.
 *
 * Soft-delete interaction (organizations.deleted_at, Faza 5.3a): a soft-deleted
 * org still occupies the unique slot — MySQL's UNIQUE KEY has no idea about
 * `deleted_at`, only Eloquent's read-time global scope does. This is intentional,
 * not a gap to close: legal records (orders, payments, tenant_payments, rentals)
 * tied to that organization are retained for 5-6 years (Art. 112 VAT / Art. 70
 * Ordynacja) in this SAME database via restrictOnDelete FKs. The entire point of
 * one-DB-per-tenant is that a stack's database is scoped to exactly one tenant's
 * data for the life of that retention window; re-provisioning a 2nd, different
 * organization into a stack that still holds a closed tenant's protected legal
 * records would contaminate that retention boundary. When a tenant's single org is
 * closed for good, the correct operation is decommissioning the whole stack, not
 * reusing its database for a new tenant — so the lock correctly continues to
 * block a 2nd INSERT after soft-delete.
 *
 * Operational note (verified against a real MySQL 8.0 instance while writing this):
 * this migration can only succeed on a table with 0 or 1 existing row. Adding a
 * generated column that is always `1` to a table that already has 2+ rows makes
 * every one of them collide on the new UNIQUE index, and MySQL rejects the ADD
 * UNIQUE step outright (`Duplicate entry '1' for key ...`) -- the ADD COLUMN half
 * commits regardless (MySQL DDL is not transactional), so a failed attempt on
 * such a table leaves the plain `singleton` column behind without its index or a
 * recorded migration row; re-running `migrate` will then fail again with
 * `Duplicate column name 'singleton'` until that leftover column is dropped by
 * hand. This is a deliberate fail-loud property, not a bug: in the real
 * deployment order `organizations` is always empty when migrations run
 * (registro:tenant-provision creates the one row afterwards), so it only fires
 * if this migration is ever run against the wrong database.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->shouldLockSingleton()) {
            return;
        }

        Schema::table('organizations', function (Blueprint $table) {
            $table->tinyInteger('singleton')->storedAs('1')->unique('organizations_singleton_unique');
        });
    }

    public function down(): void
    {
        // Keyed on what the schema actually contains, NOT on shouldLockSingleton().
        // Re-evaluating the config here would make down() a no-op whenever
        // TENANT_SLUG happens to be unset at rollback time (a shell without it
        // exported, for instance) — the migration would be marked rolled back
        // while the column and its index survived, and the next `migrate` would
        // then die on `Duplicate column name 'singleton'`.
        if (! Schema::hasColumn('organizations', 'singleton')) {
            return;
        }

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropUnique('organizations_singleton_unique');
            $table->dropColumn('singleton');
        });
    }

    private function shouldLockSingleton(): bool
    {
        return DB::getDriverName() === 'mysql' && filled(config('app.tenant_slug'));
    }
};
