<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Faza 4 krok 4.8 (plan-wdrozenia.md, kontrakt-dostepnosci.md) — the
     * location dimension on an OrderItem (the live reservation track,
     * unlike legacy `rentals`). Nullable and stays nullable FOREVER — same
     * "no forced NOT NULL mid-plan" decision as `rentals.location_id`'s own
     * migration, which this docblock does not repeat in full.
     *
     * Filtering by this column at read time is governed by
     * kontrakt-dostepnosci.md Zasada 5 ("filtr lokalizacji w outer WHERE"):
     * `RentalAvailabilityService::getAvailableQuantity()` applies its own
     * top-level `->where(...)` AFTER calling `OrderItem::scopeBlockingAvailability()`
     * — never inside that scope's JOIN on `orders`, never inside
     * `Order::scopeExpired()`'s mirrored `pending_payment` branch. This
     * migration only adds the column; it does not touch either scope.
     *
     * Like `rentals.location_id`: `service_id` here has NO organization_id
     * column of its own (isolation flows through `orders`, per OrderItem's
     * own docblock), so no tenant-scoping concern applies to this FK either.
     *
     * nullOnDelete, NOT restrictOnDelete — same reasoning and the same
     * direct precedent (`order_items.service_unit_id`'s own migration,
     * 2026_09_08_100000, itself citing `appointments.staff_id`): order_items
     * is the protected legal record on THIS side of the relationship;
     * Location is not, and restrictOnDelete would make an old Location
     * permanently undeletable the moment a single historical order item
     * referenced it.
     *
     * Composite index (service_id, location_id, start_date, end_date) is
     * additive to the existing (start_date, end_date) index from
     * 2026_03_26_000004_create_order_items_table and to
     * `order_items_service_unit_dates_index` from 2026_09_08_100000 — both
     * left untouched.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('location_id')
                ->nullable()
                ->after('service_id')
                ->constrained('locations')
                ->nullOnDelete();

            $table->index(['service_id', 'location_id', 'start_date', 'end_date'], 'order_items_service_location_dates_index');
        });
    }

    /**
     * Same drop order as `rentals.location_id`'s own migration and the
     * `order_items.service_unit_id` migration it is modelled on:
     * `dropForeign()` -> `dropIndex()` -> `dropColumn()`, never
     * `dropConstrainedForeignId()` (ci-cd-troubleshooting.md, Incydent
     * 2026-09-08 #8).
     */
    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropIndex('order_items_service_location_dates_index');
            $table->dropColumn('location_id');
        });
    }
};
