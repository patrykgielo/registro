<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Faza 6 krok 6.1 (plan-wdrozenia.md, ClickUp 86cbahqgt) — the location
     * dimension on the CART itself, deliberately NOT on `cart_items`
     * (`cart_items.location_id`, Faza 4 krok 4.8, already exists and stays a
     * SEPARATE, independent concern — see this column's own docblock below
     * for how the two relate).
     *
     * "On the cart, not the item" is the whole point of this step: it makes
     * "one order = one pickup point" impossible to violate by construction,
     * instead of a rule an application-layer sum would have to re-derive
     * from N cart_items every time. Consistent with the existing unique
     * `carts_org_user_active_unique` (organization_id, user_id, active_slot)
     * — a cart is already a single, whole-order-shaped unit, not a bag of
     * independently-addressable lines.
     *
     * Relationship to `cart_items.location_id` (read this before touching
     * either column): `cart_items.location_id` is an AVAILABILITY dimension
     * — kontrakt-dostepnosci.md Zasada 7's per-(service,location) sibling-
     * demand aggregation in CartService::addItem()/updateQuantity()/
     * convertToOrder() — answering "which branch's stock pool does this
     * line claim against". `carts.location_id` is a PICKUP dimension —
     * "where will the customer collect the whole order". They are NOT the
     * same question and this migration does not unify them: CartController::add()
     * has never passed a $locationId to CartService::addItem() (grepped
     * 2026-09-10 — every call site still passes the implicit `null`
     * default), so cart_items.location_id is unwired dead weight in
     * production today, unaffected by this migration either way.
     *
     * Nullable and stays nullable — same "no forced NOT NULL mid-plan"
     * decision as every other `location_id` column this plan has added
     * (rentals/order_items/cart_items, Faza 4 krok 4.8). A cart with zero
     * resolvable locations (tenant not yet provisioned with any Location —
     * should not happen post-Faza-1's backfill, but this column does not
     * assume it) legitimately has no value here.
     *
     * WHO writes it: Faza 6 krok 6.2 (`CartService::setLocation()`, WITH
     * revalidation of the cart's existing items) is the intended long-term
     * write path for CHANGING a non-empty cart's location, and is
     * explicitly out of scope for this delivery (86cbahqgt only asks for
     * the migration + $fillable). CartService::getOrCreateCart() DOES stamp
     * this column — but only at the moment a brand-new (therefore always
     * EMPTY) Cart row is inserted, from LocationContext::selectedId() — see
     * that method's own docblock for why an empty cart needs no
     * revalidation and this is not krok 6.2 in disguise.
     *
     * nullOnDelete: `carts` is the "ephemeral operational data" category in
     * migrations.md's FK table (cascade/null, OK to drop) — unlike
     * `orders.pickup_location_id` (Faza 6 krok 6.3, a protected legal
     * record with its own name/address snapshot), a cart carries no legal-
     * retention requirement, so a deleted Location simply clearing this
     * column is the correct, conservative choice — never restrictOnDelete
     * (would make a Location permanently undeletable the moment any
     * customer's in-progress cart referenced it).
     */
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->foreignId('location_id')
                ->nullable()
                ->after('user_id')
                ->constrained('locations')
                ->nullOnDelete();
        });
    }

    /**
     * Same drop order as every sibling `location_id` migration in this plan
     * (`dropForeign()` -> `dropColumn()`, no index to drop here — unlike
     * cart_items/order_items, this column carries no composite date index
     * of its own; nothing on `carts` ever queries "which carts want branch
     * X between dates Y-Z", so none was added):
     * `dropConstrainedForeignId()` is never used (ci-cd-troubleshooting.md,
     * Incydent 2026-09-08 #8).
     */
    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->dropColumn('location_id');
        });
    }
};
