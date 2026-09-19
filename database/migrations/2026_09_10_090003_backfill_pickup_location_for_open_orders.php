<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Faza 6 krok 6.3 (plan-wdrozenia.md) — companion to
     * 2026_09_10_090002_add_pickup_location_to_orders_table.php.
     *
     * Team-lead question (2026-09-10): does this mirror Faza 4.8's backfill
     * of OPEN reservations to the primary Location, or the opposite ("a
     * closed order is a historical record we should not touch")? Answer:
     * BOTH, split by exactly the same "open" boundary Faza 4.8 already
     * drew, for the same reason it drew it there.
     *
     * - An order that is still `paid`/`confirmed`/`in_progress`, or
     *   `pending_payment` within its own TTL/P24-grace window AT THE MOMENT
     *   THIS MIGRATION RUNS, is NOT settled history yet — Faza 6 krok 6.5
     *   (handover protocol PDF, out of scope for this delivery but coming)
     *   will need a real pickup address for exactly these rows, and staff
     *   handling an in-flight order should not see a blank pickup point on
     *   something they are about to hand over. These get the organization's
     *   primary Location, mirroring the exact `$blockingConditions` this
     *   codebase already uses to decide "does this order still block
     *   inventory" (Order::scopeExpired(), OrderItem::scopeBlockingAvailability(),
     *   and this migration's own sibling for order_items).
     * - A `completed`/`cancelled` order (or a `pending_payment` order that
     *   has already expired at migration time) is a closed, historical
     *   record that predates the concept of a pickup location entirely. It
     *   is not accurate to retroactively assign it the CURRENT primary
     *   Location — the equipment may since have moved, the org may since
     *   have opened a second branch, and nothing about the order's own data
     *   says where it was actually collected. Left with
     *   `pickup_location_id = NULL` and both snapshot columns NULL — an
     *   accurate "unknown, predates this feature", not a guessed value
     *   dressed up as fact. Same posture
     *   2026_09_08_110000_add_service_unit_identifier_snapshot_to_order_items_table.php
     *   takes for its own snapshot column.
     *
     * Resolved per ORGANIZATION (one primary Location each, guaranteed by
     * Faza 1's own backfill + LocationObserver's "never delete the last
     * location" guard) — an organization missing one at the exact moment
     * this runs (defensive only) is simply absent from
     * `$primaryLocations` and its orders are left untouched.
     *
     * Snapshot values are captured from the primary Location's OWN columns
     * at the moment this migration runs — not derived by joining to
     * `locations` at read time — because that is the entire point of a
     * snapshot (see the schema migration's own docblock): if that Location
     * is later renamed, the snapshot on these backfilled orders must not
     * silently change with it.
     *
     * Deliberately DB::table(), not Eloquent — same reasoning as every
     * other backfill migration in this plan: must not depend on
     * application model state (casts, global scopes, observers, the
     * Order::updating() immutability guard which would reject writing
     * `pickup_location_id` on a row Eloquent considers "already created")
     * that can change shape independently of the schema this migration is
     * pinned to.
     */
    public function up(): void
    {
        $now = now();

        $primaryLocations = DB::table('locations')
            ->where('primary_slot', 1)
            ->get(['id', 'organization_id', 'name', 'street', 'building', 'postal_code', 'city'])
            ->keyBy('organization_id');

        if ($primaryLocations->isEmpty()) {
            return;
        }

        $graceMinutes = \App\Models\Order::ttlGraceMinutes();

        foreach ($primaryLocations as $organizationId => $location) {
            $address = trim(trim(($location->street ?? '').' '.($location->building ?? '')).', '.trim(($location->postal_code ?? '').' '.($location->city ?? '')), ', ');

            DB::table('orders')
                ->where('organization_id', $organizationId)
                ->whereNull('pickup_location_id')
                ->where(function ($outer) use ($graceMinutes, $now) {
                    $outer->whereIn('status', ['paid', 'confirmed', 'in_progress'])
                        ->orWhere(function ($pending) use ($graceMinutes, $now) {
                            $pending->where('status', 'pending_payment')
                                ->where(function ($ttl) use ($graceMinutes, $now) {
                                    $ttl->where(function ($noTransaction) use ($now) {
                                        $noTransaction->whereNull('p24_token')
                                            ->where('expires_at', '>', $now);
                                    })->orWhere(function ($withTransaction) use ($graceMinutes, $now) {
                                        $withTransaction->whereNotNull('p24_token')
                                            ->where('expires_at', '>', $now->copy()->subMinutes($graceMinutes));
                                    });
                                });
                        });
                })
                ->update([
                    'pickup_location_id' => $location->id,
                    'pickup_location_name' => $location->name,
                    'pickup_location_address' => $address,
                    'updated_at' => $now,
                ]);
        }
    }

    /**
     * Deliberate no-op, same rationale and precedent as every other
     * backfill migration in this plan: a backfilled pickup location is
     * ordinary, correct data indistinguishable from one a future write path
     * would have set. Roll back the schema migration underneath this one
     * (2026_09_10_090002) to undo the feature entirely.
     */
    public function down(): void
    {
        Log::info(
            'backfill_pickup_location_for_open_orders: down() is a deliberate no-op — '.
            'backfilled pickup_location values are preserved on rollback, see migration file docblock.'
        );
    }
};
