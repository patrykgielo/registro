<?php

declare(strict_types=1);

namespace App\Services\Cart;

use App\Exceptions\CartItemOwnershipException;
use App\Exceptions\CartNotActiveException;
use App\Exceptions\RentalUnavailableException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use App\Services\RentalAvailabilityService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CartService
{
    public function __construct(
        protected RentalAvailabilityService $availability
    ) {}

    /**
     * Returns an existing active cart or creates a new one.
     *
     * Wrapped in a transaction + lockForUpdate() on the lookup to shorten the
     * race window between two concurrent first-time requests; the DB-level
     * unique constraint `carts_org_user_active_unique` (organization_id,
     * user_id, active_slot) is the actual backstop — if two requests still
     * both reach the INSERT, the loser's QueryException is caught and it
     * re-fetches the row the winner just created.
     */
    public function getOrCreateCart(Organization $organization, User $user): Cart
    {
        return DB::transaction(function () use ($organization, $user): Cart {
            $existing = Cart::with('items.service')
                ->active()
                ->forUser($user)
                ->where('organization_id', $organization->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            try {
                return Cart::create([
                    'organization_id' => $organization->id,
                    'user_id' => $user->id,
                    'status' => 'active',
                    'expires_at' => now()->addHours(2),
                ]);
            } catch (QueryException $e) {
                $cart = Cart::with('items.service')
                    ->active()
                    ->forUser($user)
                    ->where('organization_id', $organization->id)
                    ->first();

                if ($cart === null) {
                    throw $e;
                }

                return $cart;
            }
        });
    }

    /**
     * Adds an item to the cart after checking availability.
     *
     * Locks the Service row for the duration of the check + insert to shorten
     * (not eliminate — SQLite has no real row locking) the race window against
     * other addItem()/updateQuantity()/convertToOrder() calls for the same
     * service. convertToOrder() re-validates availability again at checkout
     * time, which is the actual point of no return for inventory.
     *
     * @throws RentalUnavailableException when requested quantity exceeds available stock
     */
    public function addItem(Cart $cart, Service $service, Carbon $start, Carbon $end, int $quantity): CartItem
    {
        return DB::transaction(function () use ($cart, $service, $start, $end, $quantity): CartItem {
            $service = Service::lockForUpdate()->findOrFail($service->id);

            // forUpdate: true — see RentalAvailabilityService::getAvailableQuantity()
            // docblock: locking the Service row alone does not guarantee this
            // re-read sees another transaction's just-committed reservation
            // under MySQL REPEATABLE READ; the count queries must themselves be
            // locking reads.
            $available = $this->availability->getAvailableQuantity($service, $start, $end, forUpdate: true);

            if ($quantity > $available) {
                throw new RentalUnavailableException("Dostępnych tylko {$available} szt.");
            }

            $rentalDays = (int) $start->diffInDays($end) + 1;
            $pricing = $this->availability->calculatePricing($service, $rentalDays, $quantity);

            return CartItem::create([
                'cart_id' => $cart->id,
                'service_id' => $service->id,
                'quantity' => $quantity,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'rental_days' => $rentalDays,
                'unit_price' => $pricing['unit_price'],
                'total_price' => $pricing['total'],
                'price_snapshot' => $pricing,
            ]);
        });
    }

    /**
     * Removes an item from the cart, verifying ownership first.
     *
     * @throws CartItemOwnershipException when item does not belong to the cart
     */
    public function removeItem(Cart $cart, CartItem $item): void
    {
        if ($item->cart_id !== $cart->id) {
            throw CartItemOwnershipException::make();
        }

        $item->delete();
    }

    /**
     * Converts an active cart into a pending Order within a single transaction.
     *
     * Re-validates inventory availability for every item before creating any
     * Order/OrderItem rows — CartItems in *other* users' carts are invisible to
     * RentalAvailabilityService::getAvailableQuantity() (it only counts
     * committed Rentals/OrderItems), so the only way to prevent two concurrent
     * checkouts from both claiming the last unit is to lock each Service row
     * here (same pattern as the deprecated RentalAvailabilityService::createHold())
     * and re-check within that lock, atomically, before committing the Order.
     *
     * @param  array<string, mixed>  $checkoutData
     *
     * @throws CartNotActiveException when cart is not active or has no items
     * @throws RentalUnavailableException when any item no longer has enough stock
     */
    public function convertToOrder(Cart $cart, array $checkoutData): Order
    {
        return DB::transaction(function () use ($cart, $checkoutData): Order {
            // `Model::lockForUpdate()` forwards to `$this->newQuery()->lockForUpdate()`,
            // returning a fresh, unexecuted Builder — `$cart->refresh()->lockForUpdate();`
            // (the previous code here) discarded that Builder without ever calling
            // ->first()/->get(), so NO row lock was ever acquired (confirmed via
            // query log: only a plain, unlocked `select * from carts where id = ?
            // limit 1` was issued). A retried/double-submitted POST could
            // therefore convert the SAME cart twice. Fix: an actual locking read
            // targeting this specific row.
            $cart = Cart::where('id', $cart->id)->lockForUpdate()->firstOrFail();

            if ($cart->status !== 'active') {
                throw CartNotActiveException::make();
            }

            // Deterministic lock order (by service_id) across concurrent checkouts
            // avoids lock-ordering deadlocks when a cart has multiple items.
            $items = $cart->items()->orderBy('service_id')->get();

            if ($items->isEmpty()) {
                throw CartNotActiveException::make('Koszyk jest pusty.');
            }

            foreach ($items as $item) {
                $service = Service::lockForUpdate()->findOrFail($item->service_id);

                // forUpdate: true — see RentalAvailabilityService::getAvailableQuantity()
                // docblock. Locking the Service row alone is NOT sufficient: under
                // MySQL REPEATABLE READ a plain re-read here could still return a
                // snapshot taken before a concurrent checkout (that queued on the
                // same Service lock and has since committed) — only a locking read
                // of rentals/order_items is guaranteed to see latest-committed data.
                $available = $this->availability->getAvailableQuantity(
                    $service,
                    Carbon::parse($item->start_date),
                    Carbon::parse($item->end_date),
                    forUpdate: true
                );

                if ($item->quantity > $available) {
                    throw new RentalUnavailableException(
                        "Dostępnych tylko {$available} szt. dla \"{$service->name}\" w wybranym terminie."
                    );
                }

                // Reuse the locked, fresh instance below — avoids a second N+1 query per item.
                $item->setRelation('service', $service);
            }

            $orderNumber = $this->generateOrderNumber($cart->organization_id);

            $subtotal = $items->sum('total_price');

            $customerType = $checkoutData['customer_type'] ?? 'natural_person';
            $isBusinessCustomer = $customerType === 'business';

            // Calculate total deposit from cart items (snapshot at checkout time)
            $depositTotal = $items->sum(function ($item) {
                return ($item->service->deposit_amount ?? 0) * $item->quantity;
            });

            $now = now();

            $order = Order::create([
                'organization_id' => $cart->organization_id,
                'user_id' => $cart->user_id,
                'order_number' => $orderNumber,
                'status' => 'pending_payment',
                'currency' => 'PLN',
                'subtotal' => $subtotal,
                'discount_amount' => 0,
                'tax_amount' => 0,
                'total_amount' => $subtotal,
                // Customer data
                'customer_email' => $checkoutData['customer_email'] ?? null,
                'customer_first_name' => $checkoutData['customer_first_name'] ?? null,
                'customer_last_name' => $checkoutData['customer_last_name'] ?? null,
                'customer_phone' => $checkoutData['customer_phone'] ?? null,
                // Legal fields
                'customer_type' => $customerType,
                'customer_pesel' => $checkoutData['customer_pesel'] ?? null,
                'customer_street' => $checkoutData['customer_street'] ?? null,
                'customer_building' => $checkoutData['customer_building'] ?? null,
                'customer_apartment' => $checkoutData['customer_apartment'] ?? null,
                'customer_city' => $checkoutData['customer_city'] ?? null,
                'customer_postal_code' => $checkoutData['customer_postal_code'] ?? null,
                // Invoice — for business always requested
                'invoice_requested' => $isBusinessCustomer ? true : ($checkoutData['invoice_requested'] ?? false),
                'invoice_company_name' => $checkoutData['invoice_company_name'] ?? null,
                'invoice_nip' => $checkoutData['invoice_nip'] ?? null,
                'invoice_street' => $checkoutData['invoice_street'] ?? null,
                'invoice_street_number' => $checkoutData['invoice_street_number'] ?? null,
                'invoice_postal_code' => $checkoutData['invoice_postal_code'] ?? null,
                'invoice_city' => $checkoutData['invoice_city'] ?? null,
                // Business extras
                'company_regon' => $checkoutData['company_regon'] ?? null,
                'company_krs' => $checkoutData['company_krs'] ?? null,
                'company_contact_name' => $checkoutData['company_contact_name'] ?? null,
                'signatory_id_number' => $checkoutData['signatory_id_number'] ?? null,
                'pickup_person_name' => $checkoutData['pickup_person_name'] ?? null,
                'pickup_person_id_number' => $checkoutData['pickup_person_id_number'] ?? null,
                // Deposit (kaucja)
                'deposit_amount' => $depositTotal,
                'deposit_status' => $depositTotal > 0 ? 'pending' : 'not_required',
                // Legal acceptances with timestamps + IP
                'rodo_accepted_at' => $now,
                'rodo_accepted_ip' => $checkoutData['ip'] ?? null,
                'terms_accepted_at' => $now,
                'withdrawal_exclusion_accepted_at' => $now,
                // Meta
                'cart_id' => $cart->id,
                'ip_address' => $checkoutData['ip'] ?? null,
                'expires_at' => $now->addMinutes(20),
            ]);

            foreach ($items as $item) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'service_id' => $item->service_id,
                    'service_name' => $item->service->name,
                    'quantity' => $item->quantity,
                    'start_date' => $item->start_date,
                    'end_date' => $item->end_date,
                    'rental_days' => $item->rental_days,
                    'unit_price' => $item->unit_price,
                    'total_price' => $item->total_price,
                    'price_snapshot' => $item->price_snapshot,
                    'deposit_amount' => $item->service->deposit_amount ?? 0,
                ]);
            }

            // Optionally persist checkout data back to the user's profile
            if (! empty($checkoutData['save_to_profile'])) {
                $this->saveProfileData($cart->user_id, $checkoutData);
            }

            $cart->update(['status' => 'converted']);

            return $order;
        });
    }

    /**
     * Restores a just-converted cart back to a usable 'active' state.
     *
     * Used when checkout fails AFTER convertToOrder() already committed
     * (e.g. Przelewy24Service::registerTransaction() throws) — the cart's
     * items are untouched by convertToOrder(), so flipping status back to
     * 'active' (and refreshing the TTL) lets the customer retry checkout
     * without re-adding every item.
     *
     * Guards against a two-tab race: if the user already has ANOTHER active
     * cart for this user/org (e.g. they opened a second tab and started a
     * fresh cart while this one was mid-compensation), do NOT create a second
     * simultaneous active cart — getOrCreateCart() would then resolve between
     * them non-deterministically. Leave this cart in its current
     * ('converted') state instead; the customer already has a usable active
     * cart to continue with. The check-then-update below is not atomic (a
     * genuine TOCTOU window remains), but the DB-level unique constraint
     * `carts_org_user_active_unique` (organization_id, user_id, active_slot)
     * is the actual backstop for the rare case both requests still land in
     * that window — see the catch below, matching the same pattern already
     * used in getOrCreateCart().
     */
    public function reactivate(Cart $cart): void
    {
        $hasOtherActiveCart = Cart::query()
            ->active()
            ->where('organization_id', $cart->organization_id)
            ->where('user_id', $cart->user_id)
            ->where('id', '!=', $cart->id)
            ->exists();

        if ($hasOtherActiveCart) {
            return;
        }

        // A query-builder update, not `$cart->update()`: the caller's $cart instance
        // can be stale by this point (CartService::convertToOrder() re-fetches its
        // OWN Cart instance inside its transaction rather than mutating the caller's
        // object in place, so the caller's copy still shows the pre-conversion
        // in-memory status). Calling `$cart->update(['status' => 'active', ...])`
        // on that stale instance would have `status` match Eloquent's own dirty-check
        // baseline (both say 'active') and silently skip that column in the SQL
        // UPDATE — only `expires_at` would change, leaving the DB row stuck
        // 'converted'. A direct query-builder update is immune to the caller's
        // object staleness; `active_slot` is set explicitly since a query-builder
        // update bypasses the `booted()` saving hook that normally keeps it in sync.
        try {
            Cart::where('id', $cart->id)->update([
                'status' => 'active',
                'active_slot' => 1,
                'expires_at' => now()->addHours(2),
            ]);
        } catch (QueryException $e) {
            // Lost the race: another active cart was created for this user/org
            // between the check above and this update — the unique constraint
            // rejected it. Leave this cart 'converted', same as the intentional
            // early-return above; the customer still has a usable active cart.
            return;
        }

        $cart->refresh();
    }

    /**
     * Persist checkout data back to the user profile when "save_to_profile" is requested.
     * Only updates non-null fields to avoid overwriting existing data with empty values.
     */
    private function saveProfileData(int $userId, array $checkoutData): void
    {
        $user = User::find($userId);

        if ($user === null) {
            return;
        }

        $updateData = array_filter([
            'customer_type' => $checkoutData['customer_type'] ?? null,
            'pesel' => $checkoutData['customer_pesel'] ?? null,
            'street_name' => $checkoutData['customer_street'] ?? null,
            'street_number' => $checkoutData['customer_building'] ?? null,
            'city' => $checkoutData['customer_city'] ?? null,
            'postal_code' => $checkoutData['customer_postal_code'] ?? null,
            'company_name' => $checkoutData['invoice_company_name'] ?? null,
            'nip' => $checkoutData['invoice_nip'] ?? null,
            'regon' => $checkoutData['company_regon'] ?? null,
            'krs' => $checkoutData['company_krs'] ?? null,
            'billing_street' => $checkoutData['invoice_street'] ?? null,
            'billing_building_number' => $checkoutData['invoice_street_number'] ?? null,
            'billing_postal_code' => $checkoutData['invoice_postal_code'] ?? null,
            'billing_city' => $checkoutData['invoice_city'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        if (! empty($updateData)) {
            $user->update($updateData);
        }
    }

    /**
     * Generates a unique, sequential order number for the given organisation and current year.
     *
     * Must be called inside a DB transaction. lockForUpdate() on the latest order row
     * serialises concurrent checkouts so that count() + 1 races are impossible.
     */
    private function generateOrderNumber(int $organizationId): string
    {
        $last = Order::where('organization_id', $organizationId)
            ->whereYear('created_at', now()->year)
            ->lockForUpdate()
            ->orderByDesc('id')
            ->first();

        $seq = $last ? ((int) substr($last->order_number, -5)) + 1 : 1;

        return 'ORG'.$organizationId.'-'.now()->year.'-'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Updates item quantity after re-checking availability.
     *
     * @throws CartItemOwnershipException when item does not belong to the cart
     * @throws RentalUnavailableException when quantity exceeds available stock
     */
    public function updateQuantity(Cart $cart, CartItem $item, int $quantity): CartItem
    {
        if ($item->cart_id !== $cart->id) {
            throw CartItemOwnershipException::make();
        }

        return DB::transaction(function () use ($item, $quantity): CartItem {
            $service = Service::lockForUpdate()->findOrFail($item->service_id);

            $start = Carbon::parse($item->start_date);
            $end = Carbon::parse($item->end_date);

            // forUpdate: true — see RentalAvailabilityService::getAvailableQuantity() docblock.
            $available = $this->availability->getAvailableQuantity($service, $start, $end, forUpdate: true);

            if ($quantity > $available) {
                throw new RentalUnavailableException("Dostępnych tylko {$available} szt.");
            }

            $pricing = $this->availability->calculatePricing($service, $item->rental_days, $quantity);

            $item->update([
                'quantity' => $quantity,
                'unit_price' => $pricing['unit_price'],
                'total_price' => $pricing['total'],
                'price_snapshot' => $pricing,
            ]);

            return $item->fresh();
        });
    }
}
