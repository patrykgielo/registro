<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Data-only companion of App\Models\Concerns\SyncsRentalPriceWithDailyRate.
     *
     * From now on the model copies `price_per_day` into `price` for every item_rental
     * save. Rows written BEFORE that still carry whatever the old paths left: the DB
     * default 0.00 (created in the panel after stage A hid the field) or a copy that
     * went stale when `price_per_day` was edited later (imported / seeded rentals).
     * `price` is invisible to customers for a rental and is read only by the JSON-LD
     * `offers.price` and the tracker's `data-service-price`, so aligning it loses no
     * information a person ever saw or typed.
     *
     * Scope, exactly:
     *  - service_type = 'item_rental' only - a time_slot's `price` is real data;
     *  - price_per_day IS NOT NULL only - a rental without a daily rate keeps its `price`;
     *  - only rows where the two differ, so a second run matches nothing (idempotent).
     *
     * DB::table(), no models: no global tenant scope (every tenant of THIS database is
     * repaired - migrations run once per tenant DB, and on the shared UAT/dev database
     * for all of them), no observers, and `updated_at` is deliberately left alone -
     * the value is derived, not edited by anyone.
     */
    public function up(): void
    {
        DB::table('services')
            ->where('service_type', 'item_rental')
            ->whereNotNull('price_per_day')
            ->whereColumn('price', '<>', 'price_per_day')
            ->update(['price' => DB::raw('price_per_day')]);
    }

    /**
     * Deliberate no-op (same precedent as 2026_09_10_090001_backfill_location_id_for_active_carts).
     * The values this replaced were stale copies or the 0.00 default - no column
     * combination can bring them back, and restoring "wrong" numbers on a guess would
     * only reintroduce the drift. The model hook keeps `price` aligned on the next
     * save regardless of this migration's state.
     */
    public function down(): void
    {
        Log::info(
            'backfill_rental_price_from_price_per_day: down() is a deliberate no-op - '.
            'item_rental `price` stays aligned with `price_per_day`, see migration file docblock.'
        );
    }
};
