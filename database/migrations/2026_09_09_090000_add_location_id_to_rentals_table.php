<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Faza 4 krok 4.8 (plan-wdrozenia.md, kontrakt-dostepnosci.md Zasada 8.8)
     * — the location dimension on a legacy Rental reservation row. Nullable
     * and stays nullable FOREVER: plan-wdrozenia.md explicitly rejects
     * forcing NOT NULL mid-plan as "jedynym krokiem nieodwracalnym,
     * niepodzielnym i umieszczonym w środku". `RentalAvailabilityService::
     * getAvailableQuantity()`'s `$locationId === null` branch (Zasada 2)
     * never reads this column — it stays inert until a later Faza 4 step
     * (4.4+) starts passing `$locationId` at the call sites and the backfill
     * migration below fills it in for today's open reservations. Until then
     * this migration has ZERO effect on availability or any other read
     * path.
     *
     * nullOnDelete, NOT restrictOnDelete: `rentals` is itself a legal record
     * (migrations.md's FK classification table — must survive org deletion
     * for ≥5-6 yrs), but a Location is not part of that legal-retention
     * requirement. A rental's own legal record must survive deleting the
     * BRANCH it was booked at exactly as it survives deleting the STAFF
     * member who booked it — `appointments.staff_id -> nullOnDelete` is the
     * direct precedent (migrations.md). restrictOnDelete here would make an
     * old, no-longer-primary Location permanently undeletable the moment it
     * had ever been referenced by a single historical rental — the same
     * class of mistake Faza 2's code-reviewer BLOKER 2 already caught once
     * on `service_location_stocks.service_id`.
     *
     * The composite index (service_id, location_id, start_date, end_date)
     * is ADDITIVE to the existing (service_id, start_date, end_date) index
     * from 2026_03_17_000002_change_rental_item_id_to_service_id_on_rentals_table
     * — that one is left untouched, since the `$locationId === null` branch
     * keeps using exactly that query shape; this new one is for the
     * location-aware branch.
     */
    public function up(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->foreignId('location_id')
                ->nullable()
                ->after('service_id')
                ->constrained('locations')
                ->nullOnDelete();

            $table->index(['service_id', 'location_id', 'start_date', 'end_date'], 'rentals_service_location_dates_index');
        });
    }

    /**
     * Order matters on MySQL (ci-cd-troubleshooting.md, Incydent 2026-09-08
     * #8): `dropForeign()` -> `dropIndex()` -> `dropColumn()`, never a
     * single `dropConstrainedForeignId()` — that helper merges the FK and
     * column drop into one call and leaves no room to drop the composite
     * index in between. Even though this index's leading column
     * (`service_id`) does not match the FK column (`location_id`) — unlike
     * the `order_items.service_unit_id` case that incident describes, where
     * the composite index's leftmost column DID match and was silently
     * relied on by InnoDB to satisfy the FK's own index requirement — this
     * migration keeps the same drop order as a deliberate, uniform
     * convention rather than relying on that distinction holding forever.
     */
    public function down(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropIndex('rentals_service_location_dates_index');
            $table->dropColumn('location_id');
        });
    }
};
