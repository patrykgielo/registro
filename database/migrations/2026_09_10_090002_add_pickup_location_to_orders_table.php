<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Faza 6 krok 6.3 (plan-wdrozenia.md, ClickUp 86cbahqh4) — the pickup
     * point on the ORDER, the legal/operational record. Sourced at checkout
     * from `carts.location_id` (krok 6.1) — see
     * CartService::convertToOrder()'s own docblock for the exact resolution
     * and its fail-closed guard.
     *
     * `pickup_location_name`/`pickup_location_address`: a point-in-time
     * copy alongside the FK, EXACTLY the pattern
     * 2026_09_08_110000_add_service_unit_identifier_snapshot_to_order_items_table.php
     * already established for `order_items.service_unit_identifier_snapshot`
     * (that migration's own docblock is the fuller version of this
     * reasoning, not repeated here) — a Location can be renamed or deleted,
     * but the handover protocol / order confirmation the customer already
     * signed or received must keep showing what was true AT THE TIME, not
     * whatever the Location record says today.
     *
     * nullOnDelete on `pickup_location_id`, NOT restrictOnDelete: same
     * precedent, same reasoning as `order_items.location_id`'s own
     * migration (2026_09_09_090001) — orders is the protected legal record
     * on THIS side of the relationship, Location is not, and restrictOnDelete
     * would make an old Location permanently undeletable the moment a
     * single historical order referenced it. The snapshot columns are
     * exactly what makes that safe: the human-readable identity survives
     * the FK going to NULL.
     *
     * KNOWN AUDIT GAP (code review 2026-09-10, documented not fixed — see
     * `Order::$auditInclude`'s own note next to `pickup_location_id` for the
     * full explanation): this nullOnDelete write happens at the DATABASE
     * level when a Location row is deleted — MySQL zeroes the FK directly,
     * without Eloquent ever loading the Order, so `Auditable` never logs it.
     * The point-in-time snapshot (`pickup_location_name`/`_address`) is
     * unaffected — only `audit_logs` has no record of the FK going to NULL.
     *
     * Both snapshot columns are nullable — NOT because they are optional
     * once an order exists (CartService::convertToOrder() always populates
     * all three together, see its docblock), but for the same reason
     * `service_unit_identifier_snapshot` is nullable: a row that predates
     * this feature (an order placed before this migration/deploy) has
     * nothing to backfill FROM for a terminal/closed order — see the
     * companion backfill migration's own docblock for which orders DO get
     * one and why.
     *
     * `pickup_location_name`/`_address` are plain `string`, not `text` —
     * Location's own `name` column is `string` and its address fields
     * (street/building/postal_code/city) are all short `string` columns
     * too (see 2026_08_27_120000_create_locations_table.php); a formatted
     * one-line address from those four fields comfortably fits.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('pickup_location_id')
                ->nullable()
                ->after('cart_id')
                ->constrained('locations')
                ->nullOnDelete();

            $table->string('pickup_location_name')->nullable()->after('pickup_location_id');
            $table->string('pickup_location_address')->nullable()->after('pickup_location_name');
        });
    }

    /**
     * Same drop order as every sibling `location_id` migration in this plan
     * (`dropForeign()` -> `dropColumn()`): `dropConstrainedForeignId()` is
     * never used (ci-cd-troubleshooting.md, Incydent 2026-09-08 #8). Both
     * snapshot columns are plain, non-FK strings — no foreign key to drop
     * for either.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['pickup_location_id']);
            $table->dropColumn(['pickup_location_id', 'pickup_location_name', 'pickup_location_address']);
        });
    }
};
