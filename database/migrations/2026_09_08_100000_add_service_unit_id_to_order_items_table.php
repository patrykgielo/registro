<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Faza 3 kroki 3.6/3.7 (app/docs/features/lokalizacje/model-danych.md) —
     * links one physical ServiceUnit to the order item it was handed out on.
     * Nullable and optional by product decision: identifiers on ServiceUnit
     * are themselves optional, so a tenant that doesn't track units must be
     * able to hand over and accept a return exactly as before, with zero
     * extra clicks (Faza 3 krok 3.6 requirement #2).
     *
     * Since order_items has no organization_id column of its own (isolation
     * goes through the order, not a column here — see OrderItem's own
     * scopeBlockingAvailability() docblock), tenant isolation for this FK is
     * enforced in App\Services\Order\OrderService::handOver()/completeReturn(),
     * not by the schema: both check service_unit.service_id ===
     * order_item.service_id (a ServiceUnit's service_id is immutable and
     * belongs to exactly one organization) AND service_unit.organization_id
     * === order.organization_id as a redundant, cheap defense-in-depth check.
     *
     * FK onDelete: nullOnDelete, NOT restrictOnDelete. order_items is the
     * protected legal record here (retention, Art. 112 VAT — migrations.md's
     * FK classification table); service_units is ephemeral operational data
     * on the OTHER side of this FK. restrictOnDelete would make a ServiceUnit
     * permanently undeletable the moment it was ever handed out once — for
     * the multi-year retention period order_items carries — exactly the
     * class of mistake migrations.md's classification table exists to avoid
     * (see service_units' own creation migration, "Faza 2's code-reviewer
     * BLOKER 2"). Losing the specific-unit enrichment on an old, already-
     * completed order when its unit is later deleted is an acceptable trade;
     * losing the ability to ever delete a retired physical unit is not.
     * Precedent: appointments.staff_id -> nullOnDelete for the same shape of
     * problem (migrations.md's FK onDelete Policy table).
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('service_unit_id')
                ->nullable()
                ->after('service_id')
                ->constrained('service_units')
                ->nullOnDelete();

            $table->index(['service_unit_id', 'start_date', 'end_date'], 'order_items_service_unit_dates_index');
        });
    }

    /**
     * Order matters on MySQL: `dropIndex()` before `dropConstrainedForeignId()`
     * fails with 1553 ("needed in a foreign key constraint"). `up()` adds the
     * FK constraint (via `constrained()`) BEFORE the composite index below it,
     * so at ALTER time InnoDB has nothing else to satisfy the FK's own index
     * requirement and ends up relying on `order_items_service_unit_dates_index`
     * itself (its leftmost column is `service_unit_id`) — dropping that index
     * first leaves the still-live FK constraint without any backing index,
     * which MySQL refuses. Dropping the FK constraint FIRST (`dropForeign`)
     * frees the index to be dropped safely, and only then is the now-unused
     * column itself removed. Verified against a throwaway mysql:8.0 container,
     * see CreateServiceUnitsTableMigrationTest/
     * GenerateServiceUnitsFromQuantityTotalMigrationTest's own rollback tests.
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign(['service_unit_id']);
            $table->dropIndex('order_items_service_unit_dates_index');
            $table->dropColumn('service_unit_id');
        });
    }
};
