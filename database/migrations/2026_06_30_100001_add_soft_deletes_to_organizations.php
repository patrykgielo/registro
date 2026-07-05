<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Faza 5.3a: SoftDeletes on Organization.
 *
 * Organizations are soft-deleted (deleted_at set) by the PurgeClosedOrganizationsCommand
 * after PII anonymization and ephemeral data purge. Hard-delete is intentionally avoided:
 *
 * - Legal records (orders, payments, tenant_payments, rentals) have RESTRICT FKs and must
 *   survive for ≥5–6 years (Art. 112 VAT / Art. 70 Ordynacja). Soft-delete leaves these
 *   intact — FK RESTRICT only fires on hard DELETE, not on UPDATE deleted_at.
 * - Soft-deleted orgs are excluded automatically from all Eloquent queries (global scope).
 * - ResolveTenant filters on lifecycle_state='active' — soft-deleted orgs are already
 *   excluded by that guard, so the soft-delete is belt-and-suspenders.
 *
 * down() uses dropSoftDeletes() for clean rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->softDeletes()->after('closure_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
