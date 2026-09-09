<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Faza 4 krok 4.8 (plan-wdrozenia.md) — the location dimension on a
     * CartItem. Nullable and stays nullable FOREVER, same decision as
     * `rentals.location_id`/`order_items.location_id`'s own migrations.
     *
     * IMPORTANT — CartItem does NOT block availability. Per kontrakt-
     * dostepnosci.md ("Co rezerwuje, a co nie"), `getAvailableQuantity()`
     * never reads `cart_items` at all; this column carries no locking or
     * read-path significance today. It exists so a future write path
     * (CartService::addItem()/updateQuantity(), Faza 4 krok 4.4+) can
     * record which location a cart item was added for, and so the Faza 0
     * sibling-demand aggregation (Zasada 7 — CartItem rodzeństwo w tym
     * samym koszyku) can eventually be scoped per location too, matching
     * getAvailableQuantity()'s own location filter once wired. `carts.
     * location_id` (Faza 6 krok 6.1, on the CART, not the item) is the
     * actual "one order = one pickup location" invariant; this column is
     * deliberately NOT that — plan-wdrozenia.md's ClickUp decision `86cbahqfu`
     * asks for it on all three of rentals/order_items/cart_items regardless.
     *
     * nullOnDelete: cart_items is classified "ephemeral operational data" in
     * migrations.md's FK table (cascade/null, OK to drop) — unlike
     * rentals/order_items, it carries no legal-retention requirement at
     * all, so nullOnDelete here is the conservative choice for the SAME
     * reason as the other two (a deleted Location must not be blocked by,
     * nor need to cascade-delete, a customer's in-progress cart item),
     * not because this table needs the extra protection legal records do.
     *
     * Composite index (service_id, location_id, start_date, end_date) is
     * new for this table — `cart_items` had no composite date index before
     * this migration (only single-column `cart_id`/`service_id`).
     */
    public function up(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->foreignId('location_id')
                ->nullable()
                ->after('service_id')
                ->constrained('locations')
                ->nullOnDelete();

            $table->index(['service_id', 'location_id', 'start_date', 'end_date'], 'cart_items_service_location_dates_index');
        });
    }

    /**
     * Same drop order as the sibling migrations on rentals/order_items:
     * `dropForeign()` -> `dropIndex()` -> `dropColumn()`, never
     * `dropConstrainedForeignId()` (ci-cd-troubleshooting.md, Incydent
     * 2026-09-08 #8).
     */
    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropIndex('cart_items_service_location_dates_index');
            $table->dropColumn('location_id');
        });
    }
};
