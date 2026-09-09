<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Faza 4 krok 4.8 (plan-wdrozenia.md) — assigns every currently OPEN
     * reservation on `rentals`/`order_items`/`cart_items` to its
     * organization's primary Location, so no reservation that still counts
     * against availability is left with `location_id = NULL` the moment a
     * future step starts passing `$locationId` at the call sites.
     *
     * "Open" is defined per table as EXACTLY the condition that already
     * decides whether a row blocks availability today — not a date filter,
     * which would leave stale-but-still-blocking rows behind:
     *
     * - rentals: `status` is one of `RentalStatus::blocksAvailability()`'s
     *   cases (held/pending/confirmed/active) — mirrors
     *   RentalAvailabilityService::getAvailableQuantity()'s own
     *   `$blockedStatuses` filter exactly.
     * - order_items: mirrors OrderItem::scopeBlockingAvailability() —
     *   orders.status IN (paid, confirmed, in_progress), OR pending_payment
     *   still within its TTL/P24-grace window at the moment THIS migration
     *   runs (Order::ttlGraceMinutes(), same grace value the live scope
     *   reads). A one-time snapshot against `now()` is correct here: this
     *   runs once at deploy time, not as a live query.
     * - cart_items: items belonging to an `active` cart. CartItems do not
     *   block availability at all (kontrakt-dostepnosci.md, "Co rezerwuje,
     *   a co nie") — backfilled anyway for consistency with the other two
     *   tables and so a customer's in-progress cart survives this deploy
     *   with a location already set, matching plan-wdrozenia.md's literal
     *   ClickUp decision `86cbahqfu` to add `location_id` to all three.
     *
     * Resolved per ORGANIZATION, not per row: every organization is
     * guaranteed exactly one primary Location today (Faza 1's own backfill
     * + `LocationObserver`'s "never delete the last location" guard), so
     * grouping by `organization_id` turns this into one UPDATE per
     * organization instead of one per reservation row. An organization that
     * is somehow missing a primary Location at the exact moment this runs
     * (should not happen in practice — defensive only, same posture as
     * 2026_08_28_090001's own "skip, don't crash the batch") is simply
     * absent from `$primaryLocationIdByOrganizationId` below and its rows
     * are left untouched, not zero-filled to some guessed location.
     *
     * `rentals.organization_id` is nullable in the schema (legacy) — any
     * row with a NULL organization_id can never resolve a primary Location
     * and is left with `location_id = NULL` for the same reason: there is
     * no tenant to look one up for.
     *
     * Deliberately uses DB::table(), not Eloquent — same reasoning as
     * 2026_08_28_090001_backfill_service_location_stocks_for_item_rental_services.php:
     * a migration should not depend on application model state (casts,
     * global scopes, observers) that can change shape independently of the
     * schema this migration is pinned to.
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

        $blockingRentalStatuses = collect(\App\Enums\RentalStatus::cases())
            ->filter(fn (\App\Enums\RentalStatus $status) => $status->blocksAvailability())
            ->map(fn (\App\Enums\RentalStatus $status) => $status->value)
            ->all();

        $graceMinutes = \App\Models\Order::ttlGraceMinutes();

        foreach ($primaryLocationIdByOrganizationId as $organizationId => $locationId) {
            DB::table('rentals')
                ->where('organization_id', $organizationId)
                ->whereIn('status', $blockingRentalStatuses)
                ->whereNull('location_id')
                ->update(['location_id' => $locationId, 'updated_at' => $now]);

            // IDs resolved into a plain PHP array FIRST, then a literal
            // whereIn() — not a subquery closure selecting from the SAME
            // table being updated. MySQL rejects that outright ("SQLSTATE
            // [HY000]: 1093 You can't specify target table 'order_items'
            // for update in FROM clause"); SQLite would have accepted it,
            // so this is exactly the class of gap ci-cd-troubleshooting.md's
            // "MySQL 8.0 gate" section warns about — measured against a real
            // mysql:8.0, not assumed. Two queries (SELECT ids, then UPDATE
            // ... WHERE id IN (...)) instead of one, acceptable for a
            // one-time deploy-time migration on tables this size.
            $orderItemIds = DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.organization_id', $organizationId)
                ->whereNull('order_items.location_id')
                ->where(function ($outer) use ($graceMinutes, $now) {
                    $outer->whereIn('orders.status', ['paid', 'confirmed', 'in_progress'])
                        ->orWhere(function ($pending) use ($graceMinutes, $now) {
                            $pending->where('orders.status', 'pending_payment')
                                ->where(function ($ttl) use ($graceMinutes, $now) {
                                    $ttl->where(function ($noTransaction) use ($now) {
                                        $noTransaction->whereNull('orders.p24_token')
                                            ->where('orders.expires_at', '>', $now);
                                    })->orWhere(function ($withTransaction) use ($graceMinutes, $now) {
                                        $withTransaction->whereNotNull('orders.p24_token')
                                            ->where('orders.expires_at', '>', $now->copy()->subMinutes($graceMinutes));
                                    });
                                });
                        });
                })
                ->pluck('order_items.id')
                ->all();

            if ($orderItemIds !== []) {
                DB::table('order_items')
                    ->whereIn('id', $orderItemIds)
                    ->update(['location_id' => $locationId, 'updated_at' => $now]);
            }

            $cartItemIds = DB::table('cart_items')
                ->join('carts', 'carts.id', '=', 'cart_items.cart_id')
                ->where('carts.organization_id', $organizationId)
                ->where('carts.status', 'active')
                ->whereNull('cart_items.location_id')
                ->pluck('cart_items.id')
                ->all();

            if ($cartItemIds !== []) {
                DB::table('cart_items')
                    ->whereIn('id', $cartItemIds)
                    ->update(['location_id' => $locationId, 'updated_at' => $now]);
            }
        }
    }

    /**
     * Deliberate no-op, same rationale and precedent as
     * 2026_08_28_090001_backfill_service_location_stocks_for_item_rental_services.php's
     * down(): a backfilled `location_id` is ordinary, correct data — a
     * reservation whose branch an admin could equally well have set by
     * hand once a future write path exists — and no column combination on
     * any of the three tables can tell those two cases apart. Rolling back
     * on a guess would erase real assignments. The actual, unconditionally
     * -safe way to undo this feature is rolling back this migration's own
     * three schema migrations underneath it (2026_09_09_090000/090001/090002),
     * which each DROP their `location_id` column entirely — see this
     * migration's own test for that path pinned as an executed test.
     */
    public function down(): void
    {
        Log::info(
            'backfill_location_id_for_open_reservations: down() is a deliberate no-op — '.
            'backfilled location_id values are preserved on rollback, see migration file docblock.'
        );
    }
};
