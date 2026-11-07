<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tenant isolation for the "outside the service area" waitlist.
     *
     * Why this column was missing: 2026_03_08_000003_add_organization_id_to_existing_tables
     * (and 2026_03_08_000004_seed_default_organization) list `service_area_waitlists`
     * — PLURAL. The real table, created by 2025_12_13_162443, is
     * `service_area_waitlist` — singular. The `Schema::hasTable()` guard around
     * that list skipped the typo silently, so every tenant on a shared stack
     * read and wrote ONE list (name, email, phone, address, GPS).
     *
     * This migration only adds the column (nullable, so existing rows survive
     * the ALTER). Filling it and turning it NOT NULL is the companion
     * 2026_11_07_110001 — kept separate so the data step is independently
     * testable and reversible.
     *
     * FK is cascadeOnDelete, deliberately NOT the 000003 pattern (nullOnDelete)
     * and NOT restrictOnDelete: a waitlist entry is PII with no legal-retention
     * duty (unlike orders/payments), so it must not outlive an offboarded
     * tenant; and nullOnDelete is impossible on the NOT NULL column the
     * companion migration produces.
     *
     * The signup unique `(email, lat, lng)` becomes `(organization_id, email,
     * lat, lng)` — migrations.md "Tenant-Scoped Unique Constraints". Left
     * global, one person joining two tenants' waitlists for the same address
     * would get a spurious 409 on the second. Its leading column also serves
     * as the organization_id index (no separate index needed).
     */
    public function up(): void
    {
        Schema::table('service_area_waitlist', function (Blueprint $table) {
            $table->foreignId('organization_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->cascadeOnDelete();
        });

        Schema::table('service_area_waitlist', function (Blueprint $table) {
            $table->dropUnique('unique_waitlist_entry');
            $table->unique(
                ['organization_id', 'email', 'requested_latitude', 'requested_longitude'],
                'unique_waitlist_entry_per_org'
            );
        });
    }

    /**
     * Order matters on MySQL (ci-cd-troubleshooting.md, Incydent 2026-09-08 #8):
     * FK first, then the composite unique that leads with the FK column, then
     * the column. `dropConstrainedForeignId()` is never used.
     *
     * Restoring the old global unique can fail if two tenants hold the same
     * (email, location) — legal under the per-organization unique, impossible
     * in the old schema. Refuse BEFORE touching anything rather than silently
     * deleting someone's sign-up.
     */
    public function down(): void
    {
        $hasCrossTenantDuplicates = DB::table('service_area_waitlist')
            ->select('email', 'requested_latitude', 'requested_longitude')
            ->groupBy('email', 'requested_latitude', 'requested_longitude')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasCrossTenantDuplicates) {
            throw new RuntimeException(
                'Cannot roll back: service_area_waitlist holds the same (email, location) for more than one '.
                'organization, which the pre-migration global unique_waitlist_entry cannot represent. '.
                'Resolve the duplicates manually first; no data was changed.'
            );
        }

        Schema::table('service_area_waitlist', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
        });

        Schema::table('service_area_waitlist', function (Blueprint $table) {
            $table->dropUnique('unique_waitlist_entry_per_org');
            $table->unique(['email', 'requested_latitude', 'requested_longitude'], 'unique_waitlist_entry');
        });

        Schema::table('service_area_waitlist', function (Blueprint $table) {
            $table->dropColumn('organization_id');
        });
    }
};
