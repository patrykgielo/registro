<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The anchor for Faza 2 (app/docs/features/lokalizacje/model-danych.md,
     * kontrakt-dostepnosci.md Zasada 2) — how many units of a service stand
     * in a given location. `services.quantity_total` becomes a MIRROR of
     * `SUM(quantity)` per service (Service::recalculateQuantityTotal()), but
     * `getAvailableQuantity()` keeps reading `quantity_total` literally when
     * `$locationId === null` until Faza 4 — this table has ZERO effect on
     * availability today.
     *
     * onDelete choices, measured against the org-hard-delete path (proof:
     * tests/Feature/Organizations/ServiceLocationStockCascadeDeletionTest.php)
     * AND against deleting a single Service directly from the panel (proof:
     * tests/Feature/Filament/ServiceResourceDeletionTest.php):
     *
     * - `service_id` -> cascadeOnDelete. NOT the same policy as
     *   `rentals.service_id`/`order_items.service_id` (both restrictOnDelete)
     *   despite the superficial resemblance — those two protect LEGAL
     *   RECORDS (migrations.md's FK table: "Legal records [orders, payments,
     *   ..., rentals] -> restrictOnDelete. Must survive org deletion for
     *   ≥5-6 yrs"), so deleting a Service that has real booking/order
     *   history must fail loudly. A `service_location_stocks` row is not a
     *   legal record — it is exactly this table's own "ephemeral
     *   operational data" classification (see `organization_id` below),
     *   a live count with no retention requirement. code-reviewer BLOKER 2
     *   (Faza 2): restrictOnDelete here meant almost every existing
     *   item_rental service — ANY of them opened once in a single-active-
     *   location tenant (8/8 real tenants today) via
     *   RouteQuantityFieldToPrimaryLocationStock — silently stopped being
     *   deletable from the panel the moment it got its first stock anchor
     *   row, with `ServiceResource`'s `DeleteAction` surfacing a raw
     *   `QueryException` instead of the working "Usuń" it has today.
     * - `location_id` -> cascadeOnDelete, DELIBERATELY NOT restrictOnDelete.
     *   `locations.organization_id` is cascadeOnDelete (Faza 1). If this
     *   column were restrictOnDelete instead, hard-deleting an organization
     *   would race two SIBLING cascades hanging off the same parent row
     *   (organizations -> locations, organizations -> service_location_stocks)
     *   with no guaranteed ordering between them: if MySQL happened to
     *   cascade-delete a `locations` row before this table's own rows
     *   referencing it were gone, the restrict would reject the whole
     *   organization DELETE outright. Making this FK cascadeOnDelete instead
     *   turns the path into a genuine multi-level cascade (organizations ->
     *   locations -> service_location_stocks), which MySQL resolves
     *   correctly regardless of sibling-cascade ordering, because it is
     *   triggered BY the `locations` row actually being deleted, not raced
     *   against it.
     * - `organization_id` -> cascadeOnDelete too — consistent with this
     *   table's own "ephemeral operational data" classification
     *   (migrations.md's FK table: "carts/statistics_daily_snapshots/
     *   analytics_events -> cascade/null. OK to drop."). Redundant with the
     *   location_id path for the organization-hard-delete scenario above,
     *   but is what actually cleans up a stray row if one were ever created
     *   with a location belonging to a DIFFERENT organization than its own
     *   organization_id (should never happen given every write path in this
     *   codebase sets both from the same source — see
     *   App\Actions\Inventory\SyncServiceLocationStock and
     *   App\Actions\Inventory\RouteQuantityFieldToPrimaryLocationStock — but
     *   costs nothing to have as an independent backstop).
     *
     * UNIQUE (service_id, location_id) is intentionally NOT prefixed with
     * organization_id, unlike locations.slug (migrations.md's "Tenant-Scoped
     * Unique Constraints"). That rule targets columns that are themselves a
     * shared, tenant-chosen STRING (a slug, a name) where two different
     * organizations can legitimately type the identical value. `service_id`
     * and `location_id` are foreign-key integers: each value already
     * resolves to exactly one row owned by exactly one organization, so two
     * different tenants can never produce the same (service_id, location_id)
     * pair in the first place — the FK referential integrity on those two
     * columns already IS the tenant scoping.
     */
    public function up(): void
    {
        Schema::create('service_location_stocks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();

            $table->unsignedInteger('quantity')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['service_id', 'location_id'], 'service_location_stocks_service_location_unique');
            $table->index(['location_id', 'service_id'], 'service_location_stocks_location_service_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_location_stocks');
    }
};
