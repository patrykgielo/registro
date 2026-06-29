<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Faza 5.2: DB-level FK backstop for lifecycle guards.
 *
 * Legal records (orders, payments, tenant_payments, rentals) → restrictOnDelete:
 *   These must never be silently destroyed when an organization is deleted.
 *   OrganizationHasLegalRecordsException in OrganizationObserver::deleting() provides
 *   the human-readable error before the DB is even touched; this FK is the last-resort
 *   enforcement at the engine level (e.g. forceDelete/tinker bypass).
 *
 * appointments.staff_id → nullOnDelete:
 *   Deleting a staff member (User) preserves historical appointments with staff_id = null.
 *   Future appointments are blocked by EmployeeResource::canDelete() guard (Faza 5.7).
 *   The column must be made nullable first on MySQL; SQLite is skipped (no Doctrine ALTER
 *   support) — the FK change is a no-op on SQLite anyway.
 *
 * Untouched (stay cascade/null):
 *   carts.organization_id        — ephemeral, OK to cascade
 *   statistics_*.organization_id — ephemeral aggregate, OK to cascade
 *   analytics_events.org_id      — already nullOnDelete, leave alone
 *   payments.order_id            — payment is bound to the order; org_id is the legal backstop
 *   appointments.organization_id — already nullOnDelete (2026_03_08_000003)
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Legal records: cascade → restrict on organization_id ──────────────

        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete();
        });

        Schema::table('tenant_payments', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete();
        });

        Schema::table('rentals', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->restrictOnDelete();
        });

        // ── appointments.staff_id: cascade → nullOnDelete ─────────────────────

        // Step 1: make staff_id nullable (MySQL only; SQLite has no ALTER COLUMN support).
        // The column remains NOT NULL in SQLite test environments — test behaviour at app level.
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('appointments', function (Blueprint $table) {
                $table->unsignedBigInteger('staff_id')->nullable()->change();
            });
        }

        // Step 2: replace cascade FK with nullOnDelete FK.
        // dropForeign is a no-op on SQLite; the re-add is also a no-op (no real FK enforcement).
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropForeign(['staff_id']);
            $table->foreign('staff_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        // ── Restore legal records to cascade ──────────────────────────────────

        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->cascadeOnDelete();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->cascadeOnDelete();
        });

        Schema::table('tenant_payments', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->cascadeOnDelete();
        });

        Schema::table('rentals', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->cascadeOnDelete();
        });

        // ── Restore appointments.staff_id: nullOnDelete → cascade ─────────────

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropForeign(['staff_id']);
            $table->foreign('staff_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });

        // staff_id is intentionally LEFT nullable on rollback. Restoring NOT NULL would fail
        // at the DB level if any staff member was deleted while this migration was applied
        // (those appointments now hold staff_id = null). nullable is a looser constraint than
        // NOT NULL, so leaving it does not corrupt data — re-running up() is still safe. If a
        // strict NOT NULL restore is ever required, first resolve null rows:
        //   UPDATE appointments SET staff_id = <fallback> WHERE staff_id IS NULL;
    }
};
