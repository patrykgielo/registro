<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Faza 6 krok 6.1 (plan-wdrozenia.md) — companion to
     * 2026_09_10_090000_add_location_id_to_carts_table.php. Without this,
     * every ACTIVE cart that already existed before this deploy keeps
     * `location_id = NULL` forever: CartService::getOrCreateCart() only
     * stamps the column on a brand-new INSERT (see that method's own
     * docblock), it never retroactively fixes up a cart it just fetched as
     * "already existing". Faza 6 krok 6.4's fail-closed checkout validation
     * (SubmitCheckoutRequest requiring a valid `pickup_location_id`,
     * sourced from `$cart->location_id`) would then lock out every
     * mid-session customer the moment this feature deploys — a real
     * regression, not a hypothetical one.
     *
     * Same "open"/"active" definition and same per-organization primary-
     * Location resolution as the `cart_items` block of
     * 2026_09_09_090003_backfill_location_id_for_open_reservations.php —
     * intentionally NOT re-deriving a different definition here. Every
     * organization is guaranteed exactly one primary Location (Faza 1's own
     * backfill + LocationObserver's "never delete the last location"
     * guard); an organization missing one at the exact moment this runs
     * (defensive only, should not happen in practice) is simply absent from
     * `$primaryLocationIdByOrganizationId` and its carts are left
     * untouched, not zero-filled to a guessed location.
     *
     * Deliberately DB::table(), not Eloquent — same reasoning as
     * 2026_08_28_090001 and 2026_09_09_090003: a migration should not
     * depend on application model state (casts, global scopes, observers)
     * that can change shape independently of the schema this migration is
     * pinned to.
     */
    public function up(): void
    {
        $now = now();

        $primaryLocationIdByOrganizationId = DB::table('locations')
            ->where('primary_slot', 1)
            ->pluck('id', 'organization_id');

        if ($primaryLocationIdByOrganizationId->isEmpty()) {
            return;
        }

        foreach ($primaryLocationIdByOrganizationId as $organizationId => $locationId) {
            DB::table('carts')
                ->where('organization_id', $organizationId)
                ->where('status', 'active')
                ->whereNull('location_id')
                ->update(['location_id' => $locationId, 'updated_at' => $now]);
        }
    }

    /**
     * Deliberate no-op, same rationale and precedent as
     * 2026_09_09_090003_backfill_location_id_for_open_reservations.php's
     * down(): a backfilled `location_id` is ordinary, correct data — no
     * column combination can tell it apart from a value a future write path
     * would have set anyway. Rolling back on a guess would erase real
     * assignments. The actual, unconditionally-safe way to undo this
     * feature is rolling back this migration's own schema migration
     * underneath it (2026_09_10_090000), which drops the `location_id`
     * column entirely.
     */
    public function down(): void
    {
        Log::info(
            'backfill_location_id_for_active_carts: down() is a deliberate no-op — '.
            'backfilled location_id values are preserved on rollback, see migration file docblock.'
        );
    }
};
