<?php

declare(strict_types=1);

namespace App\Services\Cart;

use App\Exceptions\CartItemOwnershipException;
use App\Exceptions\CartNotActiveException;
use App\Exceptions\PickupLocationRequiredException;
use App\Exceptions\RentalUnavailableException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use App\Services\RentalAvailabilityService;
use App\Support\LocationContext;
use App\Support\Settings\SettingsManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CartService
{
    public function __construct(
        protected RentalAvailabilityService $availability,
        protected SettingsManager $settings,
        protected LocationContext $locationContext,
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
     *
     * `location_id` (Faza 6 krok 6.1) is stamped ONLY on a brand-new INSERT,
     * from LocationContext::selectedId() — the ambient session selection at
     * the moment this cart is first created. Deliberately NOT re-stamped on
     * the `$existing` branch above: an existing cart already has whatever
     * location it was created with (or backfilled to, for carts predating
     * this column — see 2026_09_10_090001's own migration), and silently
     * overwriting that here — behind the customer's back, mid-session, with
     * no revalidation of the cart's own items against the new location's
     * stock — is precisely the job Faza 6 krok 6.2's
     * `CartService::setLocation()` is built for. This is a one-time stamp
     * at creation, not a live sync.
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
                    'location_id' => $this->locationContext->selectedId(),
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
     * $locationId (Faza 4 krok 4.4, kontrakt-dostepnosci.md) is where the
     * location dimension ENTERS the cart — unlike updateQuantity()/
     * convertToOrder() below, there is no existing CartItem row yet to read
     * it off. Defaults to null (unchanged behaviour for a cart with no
     * pickup location — single-location/no-location tenant).
     *
     * Faza 6 krok 6.2 fix (rental-availability.md, "pickup location = stock
     * location until Phase 7"): `$cart->location_id`, when set, ALWAYS wins
     * over whatever the caller passed — this is the single line that makes
     * the cart's own pickup point the one source of truth for which pool
     * this add is validated against, rather than trusting each call site to
     * agree. Load-bearing in production: `App\Http\Controllers\CartController
     * ::add()` has never passed a `$locationId` argument at all, so without
     * this derivation a multi-location tenant's every real add-to-cart
     * validated against the tenant-wide pool regardless of which branch the
     * customer had selected — the tile said "unavailable in this branch",
     * the cart said "sure". `syncItemLocationsToCart()` below heals any
     * sibling row already in this cart whose stored value predates this fix
     * (or a later branch switch), so the sibling-demand query a few lines
     * down — which filters by the STORED `location_id` column — stays
     * correct too. The value is both forwarded to getAvailableQuantity() AND
     * persisted on the created row, so updateQuantity()/convertToOrder() can
     * read it back later from that same row.
     *
     * **Race fix (2026-09-19, rental-availability.md Zasada 9):** the caller's
     * `$cart` instance is loaded in a SEPARATE, already-committed transaction
     * (`CartController::add()` -> `getOrCreateCart()`), so by the time THIS
     * transaction opens it can be arbitrarily stale — a concurrent
     * `setLocation()` confirming a branch switch commits a new `location_id`
     * on this exact row in between. `$cart->location_id` below is therefore
     * read off a FRESH, locked re-fetch of the row (same global lock order as
     * `setLocation()`/`convertToOrder()`: cart row first, then services),
     * never the caller's own in-memory object — see that re-fetch's own
     * inline comment for why an unlocked re-read would not be enough either.
     *
     * @throws RentalUnavailableException when requested quantity exceeds available stock
     */
    public function addItem(Cart $cart, Service $service, Carbon $start, Carbon $end, int $quantity, ?int $locationId = null): CartItem
    {
        return DB::transaction(function () use ($cart, $service, $start, $end, $quantity, $locationId): CartItem {
            // Locking read, not a plain re-fetch: under MySQL REPEATABLE READ a
            // normal SELECT can still return the pre-commit snapshot while a
            // concurrent setLocation() holds this row locked mid-transaction —
            // only `lockForUpdate()` is guaranteed to block until that
            // transaction commits and then return the fresh, post-commit
            // value (same reasoning as setLocation()/convertToOrder()'s own
            // `Cart::where('id', $cart->id)->lockForUpdate()->firstOrFail()`,
            // now shared by all four cart write paths).
            $cart = Cart::where('id', $cart->id)->lockForUpdate()->firstOrFail();

            $locationId = $cart->location_id ?? $locationId;

            $service = Service::lockForUpdate()->findOrFail($service->id);

            // $cart->location_id — the RAW column, not the just-derived
            // $locationId above — is the sync target. When the cart has no
            // pickup location of its own ($cart->location_id === null),
            // this must no-op regardless of which explicit $locationId THIS
            // particular add happens to carry: a cart with no pickup point
            // is exactly the scenario where a caller may still add items at
            // several different explicit locations independently (see
            // CartServiceLocationTest) — syncing them all to whatever
            // location the LATEST add() call passed would silently corrupt
            // every earlier sibling instead of healing anything.
            $this->syncItemLocationsToCart($cart, $cart->location_id);

            // forUpdate: true — see RentalAvailabilityService::getAvailableQuantity()
            // docblock: locking the Service row alone does not guarantee this
            // re-read sees another transaction's just-committed reservation
            // under MySQL REPEATABLE READ; the count queries must themselves be
            // locking reads.
            $available = $this->availability->getAvailableQuantity($service, $start, $end, forUpdate: true, locationId: $locationId);

            // getAvailableQuantity() only sees committed Rentals/OrderItems — it
            // is blind to sibling CartItems already sitting in THIS cart for
            // the same service (kontrakt-dostepnosci.md Zasada 7). Without
            // aggregating them, a user can add the same equipment to their own
            // cart repeatedly and oversell themselves (ClickUp 86cb93tfw).
            //
            // Scoped to THIS $locationId too (Zasada 7's per-location addendum,
            // Faza 4 krok 4.4): a sibling in a DIFFERENT location must not
            // count against this one, or two non-competing locations would
            // falsely serialise against each other. `where('location_id', null)`
            // resolves to whereNull() — see CartServiceLocationTest for the
            // proof this is unchanged while every caller still passes null.
            $existingDemand = (int) CartItem::where('cart_id', $cart->id)
                ->where('service_id', $service->id)
                ->where('location_id', $locationId)
                ->overlappingDates($start, $end)
                ->sum('quantity');

            $totalDemand = $existingDemand + $quantity;

            if ($totalDemand > $available) {
                throw RentalUnavailableException::forItem($service->name, $totalDemand, $available, $start, $end);
            }

            $rentalDays = (int) $start->diffInDays($end) + 1;
            $pricing = $this->availability->calculatePricing($service, $rentalDays, $quantity);

            return CartItem::create([
                'cart_id' => $cart->id,
                'service_id' => $service->id,
                'location_id' => $locationId,
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
     * Faza 6 krok 6.2 fix — until Phase 7 (transfers) exists, the pickup
     * location and the stock location for every line in a cart MUST be the
     * same one (rental-availability.md). `cart_items.location_id` is only
     * ever written at addItem() time; a cart whose OWN `location_id`
     * changes afterwards (via setLocation()'s branch switch, or via the
     * one-time gap between Faza 6 krok 6.1's deploy — which started
     * stamping NEW carts — and this fix — which is the first thing to
     * forward that value into `addItem()`) would otherwise leave rows
     * behind that no longer match, and every query in this class that reads
     * `location_id` straight off a CartItem row (the sibling-demand
     * aggregations in addItem()/updateQuantity(), and
     * convertToOrder()'s own per-item validation + the value it carries onto
     * `order_items.location_id`) would silently validate against — or sell
     * from — the wrong pool. Re-asserting this invariant here, before any of
     * that math runs, means every OTHER line in this file can go on trusting
     * `$item->location_id` verbatim.
     *
     * Takes the target location as an explicit parameter rather than always
     * reading `$cart->location_id` itself — convertToOrder() must sync
     * against its own RESOLVED, active-filtered `$pickupLocation`, not the
     * cart's raw column value (which can still point at a location that was
     * deactivated moments ago; see that method's own call site for why).
     * addItem()/updateQuantity() pass `$cart->location_id` straight through,
     * since neither has (or needs) a separate resolution step.
     *
     * No-ops when `$locationId === null` (single-location/no-location
     * tenant, a multi-location tenant that has never resolved one, or —
     * inside convertToOrder() — one whose resolved pickup point is null) —
     * Zasada 6, kontrakt-dostepnosci.md: that case must stay bit-for-bit
     * identical to before this fix, never zero-filled to a guessed location.
     */
    private function syncItemLocationsToCart(Cart $cart, ?int $locationId): void
    {
        if ($locationId === null) {
            return;
        }

        CartItem::where('cart_id', $cart->id)
            ->where(function ($query) use ($locationId): void {
                $query->whereNull('location_id')->orWhere('location_id', '!=', $locationId);
            })
            ->update(['location_id' => $locationId]);
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
     * @throws PickupLocationRequiredException when the cart's location_id does not
     *                                         resolve to a Location still belonging
     *                                         to this cart's own organization (see
     *                                         the resolution below for when this
     *                                         can happen despite SubmitCheckoutRequest's
     *                                         own upstream validation)
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

            // Faza 6 krok 6.3/6.4 — resolved from the JUST-LOCKED, freshly re-fetched
            // $cart above (not from LocationContext directly, and not from the
            // caller's own $cart instance) so this reflects the CURRENT committed
            // value of carts.location_id, closing the same class of race
            // convertToOrder()'s own lockForUpdate() docblock describes for
            // $cart->status. SubmitCheckoutRequest already validated this same
            // value (fail-closed — see its own docblock) BEFORE this method was
            // ever called; this is defense-in-depth against the narrow window
            // between that validation and this locked read — TWO independent
            // races, not one (code review 2026-09-10, the second one was
            // missing entirely before this fix):
            //   1. The Location is DELETED in between — nullOnDelete's FK
            //      will already have turned carts.location_id itself to NULL
            //      by the time this SELECT runs, so `$cart->location_id !==
            //      null` below is already false and find() is never reached.
            //   2. The Location is DEACTIVATED in between — deactivation does
            //      NOT touch carts.location_id at all (no FK/observer wipes
            //      it), so a bare find() would still return the now-closed
            //      row and this guard would never fire. `->active()` is what
            //      closes THIS race — without it, an order could be created
            //      with pickup_location_id pointing at a branch that is no
            //      longer selling anything.
            $pickupLocation = $cart->location_id !== null
                ? Location::withoutGlobalScope('organization')
                    ->where('organization_id', $cart->organization_id)
                    ->active()
                    ->find($cart->location_id)
                : null;

            // Only genuinely ambiguous (2+ active locations, still nothing
            // resolved) blocks the order. Code review (2026-09-10): this check
            // is `$pickupLocation === null && selectionRequired()`, NOT a call
            // to `LocationContext::mustPrompt()` itself — the two are only
            // FUNCTIONALLY equivalent here, not the same implementation:
            // `mustPrompt()` is `selectionRequired() && selected() === null`,
            // and `$pickupLocation === null` is a DIFFERENT null-check (the
            // cart's OWN persisted location_id having failed to resolve a row),
            // not `LocationContext::selected()`. They agree in this codebase
            // TODAY because SubmitCheckoutRequest's prepareForValidation()
            // sources `pickup_location_id` from this exact cart, and
            // CartService::getOrCreateCart() sources `carts.location_id` from
            // `LocationContext::selectedId()` at creation — but a future change
            // to either resolution path could make them diverge silently. A 0-
            // or 1-location tenant simply creates the order with
            // `pickup_location_id = null` below — the feature stays invisible
            // to a tenant that has not adopted it.
            //
            // Correct, worth naming (code review 2026-09-10): the SAME branch
            // also covers a tenant whose active count just dropped to exactly
            // one by deactivating a DIFFERENT location than the one this cart
            // points to (LocationObserver::updating() only blocks dropping to
            // ZERO, never to one — see that method's own docblock). If that
            // drop makes selectionRequired() false, this guard does NOT throw
            // even though $pickupLocation is null (filtered out by ->active()
            // above) — the order proceeds with pickup_location_id = null,
            // identical to how a genuine 0-/1-location tenant is handled. Not
            // a gap: a tenant effectively down to one active location has
            // nothing ambiguous left to resolve.
            if ($pickupLocation === null && $this->locationContext->selectionRequired()) {
                throw PickupLocationRequiredException::make();
            }

            // Faza 6 krok 6.2 fix — see syncItemLocationsToCart() docblock.
            // Deliberately targets the RESOLVED, active-filtered
            // `$pickupLocation` computed above, not the cart's raw
            // `location_id` column: a cart still pointing at a location that
            // was JUST deactivated (the "drops to exactly one" branch
            // above) must validate its items against the SAME pool the
            // order itself falls back to (global, `pickup_location_id =
            // null`) — not against a now-closed branch's stock, which this
            // method already treats as "nothing to resolve" one guard
            // earlier. Must run before $items is queried below: a cart item
            // added before this fix existed (or before a later
            // setLocation() branch switch) can still carry a stale/NULL
            // location_id, and this method's own per-item validation below
            // trusts $item->location_id verbatim.
            $this->syncItemLocationsToCart($cart, $pickupLocation?->id);

            // Deterministic lock order (by service_id) across concurrent checkouts
            // avoids lock-ordering deadlocks when a cart has multiple items.
            // Secondary `orderBy('id')` makes the sibling-demand aggregation
            // below (Zasada 7) deterministic for multiple items of the same
            // service too, instead of relying on incidental DB row order.
            $items = $cart->items()->orderBy('service_id')->orderBy('id')->get();

            if ($items->isEmpty()) {
                throw CartNotActiveException::make('Koszyk jest pusty.');
            }

            // Collected across ALL items instead of throwing on the first miss —
            // the customer needs the full picture (every unavailable item) in a
            // single checkout attempt, not one-at-a-time whack-a-mole. Locks are
            // still acquired for every item before we decide whether to throw;
            // the whole transaction rolls back together either way.
            $unavailableItems = [];

            // getAvailableQuantity() only sees committed Rentals/OrderItems — it
            // has no idea what earlier iterations of THIS loop already claimed
            // (kontrakt-dostepnosci.md Zasada 7). Without aggregating sibling
            // demand, three 1-unit CartItems for the same quantity_total=1
            // service each see the same unclaimed unit and all pass (ClickUp
            // 86cb93tfw). Keyed by "service_id|location_id" (Faza 4 krok 4.4
            // addendum — NOT service_id alone), keeps only the start/end/quantity
            // of items ALREADY ACCEPTED in this loop — a rejected item's own
            // demand must not poison a later, non-overlapping item's count (see
            // test_convert_to_order_does_not_over_reject_when_only_middle_item_
            // bridges_two_non_overlapping_windows in CartServiceTest: three
            // items A/B/C where only B overlaps both A and C — summing ALL
            // same-service items regardless of overlap would wrongly reject A
            // and C too). Per-location keying prevents the SAME mistake along a
            // second axis: two items of the same service in DIFFERENT locations
            // must not sum against each other (false reject), and two items in
            // the SAME location must (oversell) — see CartServiceLocationTest for
            // both directions, falsified independently.
            $acceptedByService = [];

            foreach ($items as $item) {
                $service = Service::lockForUpdate()->findOrFail($item->service_id);

                $itemStart = Carbon::parse($item->start_date);
                $itemEnd = Carbon::parse($item->end_date);

                // forUpdate: true — see RentalAvailabilityService::getAvailableQuantity()
                // docblock. Locking the Service row alone is NOT sufficient: under
                // MySQL REPEATABLE READ a plain re-read here could still return a
                // snapshot taken before a concurrent checkout (that queued on the
                // same Service lock and has since committed) — only a locking read
                // of rentals/order_items is guaranteed to see latest-committed data.
                $available = $this->availability->getAvailableQuantity(
                    $service,
                    $itemStart,
                    $itemEnd,
                    forUpdate: true,
                    locationId: $item->location_id
                );

                $demandKey = $item->service_id.'|'.($item->location_id ?? 'null');

                $siblingDemand = collect($acceptedByService[$demandKey] ?? [])
                    ->filter(fn (array $accepted): bool => $itemStart->lte($accepted['end']) && $itemEnd->gte($accepted['start']))
                    ->sum('quantity');

                $totalDemand = $siblingDemand + $item->quantity;

                if ($totalDemand > $available) {
                    $unavailableItems[] = RentalUnavailableException::describeItem(
                        $service->name,
                        $totalDemand,
                        $available,
                        $itemStart,
                        $itemEnd
                    );
                } else {
                    $acceptedByService[$demandKey][] = [
                        'start' => $itemStart,
                        'end' => $itemEnd,
                        'quantity' => $item->quantity,
                    ];
                }

                // Reuse the locked, fresh instance below — avoids a second N+1 query per item.
                $item->setRelation('service', $service);
            }

            if ($unavailableItems !== []) {
                throw RentalUnavailableException::forItems($unavailableItems);
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

            // Validated against the tenant's enabled methods in SubmitCheckoutRequest;
            // defaults to 'online' for callers that don't pass it at all (e.g.
            // Dev/FakePaymentController), preserving today's only behaviour.
            $settlementMethod = ($checkoutData['settlement_method'] ?? 'online') === 'offline'
                ? 'offline'
                : 'online';

            // Online keeps the original fixed 20-minute abandon-cart TTL (unchanged —
            // Order::scopeExpired() layers an additional P24 grace period on top once
            // registerTransaction() sets p24_token). Offline has no gateway session to
            // wait on, so it gets its own, tenant-configurable hold instead — both
            // branches only ever WRITE expires_at here; scopeExpired() and
            // OrderItem::scopeBlockingAvailability() read it back unchanged, so the two
            // stay trivially in sync without touching either scope.
            $expiresAt = $settlementMethod === 'offline'
                ? $now->copy()->addHours($this->settings->offlineReservationHoldHours())
                : $now->copy()->addMinutes(20);

            $order = Order::create([
                'organization_id' => $cart->organization_id,
                'user_id' => $cart->user_id,
                'order_number' => $orderNumber,
                'status' => 'pending_payment',
                'settlement_method' => $settlementMethod,
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
                'expires_at' => $expiresAt,
                // Pickup location (Faza 6 krok 6.3) — all three set together, once,
                // from the $pickupLocation resolved above. Order::updating()'s guard
                // deliberately does NOT protect these from later mutation (see that
                // model's own docblock) — but a future writer changing them MUST
                // still update all three atomically, the same contract this single
                // mass-assignment already satisfies.
                'pickup_location_id' => $pickupLocation?->id,
                'pickup_location_name' => $pickupLocation?->name,
                'pickup_location_address' => $pickupLocation?->formattedAddress(),
            ]);

            // Faza 3 krok 2 (plan-wdrozenia.md, "Ilość > 1 — rozstrzygnięcie"): a cart item
            // with quantity N expands into N separate OrderItems of quantity 1 each — Faza 3's
            // service_units gives staff exactly one slot per order item to record which
            // physical unit was handed over/returned, and a quantity=3 row has nowhere to put
            // three different unit numbers.
            //
            // Splitting total_price by dividing by the ORIGINAL quantity is exact to the cent
            // today, but NOT for the reason it looks like: calculatePricing() applies
            // round(..., 2) to the WHOLE product, AFTER multiplying by quantity
            // (RentalAvailabilityService.php:259 and :267) — it does not round a per-unit rate
            // and then multiply. What actually guarantees zero remainder is that every price
            // input is decimal(10,2) (price_per_day, price_per_week, price_per_day_long) and
            // every multiplier in the formula is an integer (durationDays, weeks, remainingDays,
            // quantity). A whole number of grosze times an integer is still a whole number of
            // grosze, so that round() only scrubs float representation noise — it never discards
            // real value, and dividing back by quantity recovers the per-unit amount exactly.
            //
            // That invariant lives in the price COLUMN TYPES and in the multipliers being
            // integers — not here. If a future pricing rule ever introduces a fractional
            // per-unit rate (the obvious candidate is $weeklyPerDay = price_per_week / 7, which
            // calculatePricing():250 already computes but today only compares, never multiplies),
            // this division starts leaving a remainder and this block must distribute it
            // instead — otherwise SUM(order_items.total_price) drifts a grosz from orders.subtotal.
            foreach ($items as $item) {
                $perUnitTotalPrice = round(((float) $item->total_price) / $item->quantity, 2);
                // Only `total` is re-scoped to one unit; `unit`/`unit_price` are per-unit
                // already, so they carry over verbatim. Nothing reads price_snapshot today
                // (grepped app/ and resources/) — a future reader must not assume `total`
                // still means "whole cart line", because since this step it means one unit.
                $perUnitSnapshot = array_merge($item->price_snapshot ?? [], ['total' => $perUnitTotalPrice]);

                for ($unit = 0; $unit < $item->quantity; $unit++) {
                    OrderItem::create([
                        'order_id' => $order->id,
                        'service_id' => $item->service_id,
                        // Carries the CartItem's own location_id forward verbatim
                        // (Faza 4 krok 4.4) — every unit split off this line was
                        // validated against, and claims capacity from, this exact
                        // (service, location) pair above.
                        'location_id' => $item->location_id,
                        'service_name' => $item->service->name,
                        'quantity' => 1,
                        'start_date' => $item->start_date,
                        'end_date' => $item->end_date,
                        'rental_days' => $item->rental_days,
                        'unit_price' => $item->unit_price,
                        'total_price' => $perUnitTotalPrice,
                        'price_snapshot' => $perUnitSnapshot,
                        'deposit_amount' => $item->service->deposit_amount ?? 0,
                    ]);
                }
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

        return DB::transaction(function () use ($cart, $item, $quantity): CartItem {
            // Race fix (2026-09-19) — same reasoning as addItem()'s own
            // inline comment: the caller's $cart instance can be stale by
            // the time this transaction opens, so `$cart->location_id` below
            // (both here and inside syncItemLocationsToCart()) must come from
            // a FRESH, locked re-fetch, not the caller's in-memory object.
            // Same global lock order as addItem()/setLocation()/
            // convertToOrder(): cart row first, then the service.
            $cart = Cart::where('id', $cart->id)->lockForUpdate()->firstOrFail();

            // Faza 6 krok 6.2 fix — see syncItemLocationsToCart() docblock.
            // Must run BEFORE $item->location_id is read below, then refresh
            // $item so the in-memory instance reflects whatever this just
            // wrote (the helper issues a query-builder UPDATE, which does
            // not touch $item's own attributes).
            $this->syncItemLocationsToCart($cart, $cart->location_id);
            $item->refresh();

            $service = Service::lockForUpdate()->findOrFail($item->service_id);

            $start = Carbon::parse($item->start_date);
            $end = Carbon::parse($item->end_date);

            // forUpdate: true — see RentalAvailabilityService::getAvailableQuantity() docblock.
            // $item->location_id (Faza 4 krok 4.4) is the row's OWN, already-set
            // location — addItem() is the only place that dimension enters the
            // cart; this method only ever propagates what's already on the row.
            $available = $this->availability->getAvailableQuantity($service, $start, $end, forUpdate: true, locationId: $item->location_id);

            // Same aggregation as addItem() (kontrakt-dostepnosci.md Zasada 7),
            // excluding this item's OWN (pre-update) row — otherwise its
            // existing quantity would double-count against itself, the same
            // reason getAvailableQuantity() has an $excludeRentalId parameter.
            // Scoped to the SAME location as this item, matching addItem() —
            // a sibling in a different location must not count against it.
            $siblingDemand = (int) CartItem::where('cart_id', $cart->id)
                ->where('service_id', $item->service_id)
                ->where('location_id', $item->location_id)
                ->where('id', '!=', $item->id)
                ->overlappingDates($start, $end)
                ->sum('quantity');

            $totalDemand = $siblingDemand + $quantity;

            if ($totalDemand > $available) {
                throw RentalUnavailableException::forItem($service->name, $totalDemand, $available, $start, $end);
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

    /**
     * Faza 6 krok 6.2 (86cbahqgv, plan-wdrozenia.md) — read-only projection
     * of what switching the cart's PICKUP location (`carts.location_id`,
     * krok 6.1) to $newLocation would do to its existing items, for the
     * confirmation prompt the acceptance criterion demands ("pytanie, nie
     * błąd") BEFORE anything is mutated. `forUpdate: false` throughout —
     * this is display for a question, not the point of commitment; see
     * setLocation() below for the authoritative, locked re-check.
     *
     * Deliberately does NOT read `cart_items.location_id` for the
     * evaluation itself — every item is evaluated directly against
     * `$newLocation`'s own capacity (the question being asked is "what
     * would this item claim if the cart's pickup point becomes
     * $newLocation", not "what did it claim before"), the same way
     * addItem() would if the customer added that exact item fresh at the
     * new branch. `setLocation()` below IS the write path that brings the
     * stored column in line with this evaluation once the customer
     * confirms — see that method's own docblock (Faza 6 krok 6.2 fix).
     *
     * @return list<array{item: CartItem, requested: int, available: int, kept: int}>
     */
    public function previewLocationChange(Cart $cart, Location $newLocation): array
    {
        return $this->evaluateLocationChange($cart, $newLocation, forUpdate: false);
    }

    /**
     * Faza 6 krok 6.2 — the authoritative write path for changing a cart's
     * pickup location, named ahead of time by `carts.location_id`'s own
     * migration docblock ("WITH revalidation of the cart's existing
     * items"). Re-validates every item against $newLocation's OWN capacity
     * under lock (Zasada 3/7, rental-availability.md — same discipline as
     * convertToOrder()) rather than trusting whatever
     * previewLocationChange() showed the customer moments earlier: stock at
     * the new branch can change in that window exactly like it can between
     * checkout's own prompt and its locked re-check.
     *
     * Never throws for an item that no longer fully fits — the ticket's own
     * framing rules that out ("odmowa bez wyjścia jest sprzeczna z
     * zamówieniem": the customer already said yes to the branch switch, a
     * hard rejection here would leave them with no way forward). Each
     * item's quantity is clamped down to whatever IS available at the new
     * branch (never increased above what the customer already had), or the
     * item is removed entirely when nothing is available there. The
     * returned report describes exactly what changed so the caller can
     * disclose it — mirroring convertToOrder()'s "collect every problem,
     * decide once" shape one step earlier in the funnel, except resolving
     * instead of blocking.
     *
     * Faza 6 krok 6.2 fix: DOES now stamp `cart_items.location_id` to
     * `$newLocation->id` on every surviving item (previously deliberately
     * skipped — see git history — on the theory that nothing downstream
     * read the column yet). That stopped being true the moment addItem()
     * started deriving its own `$locationId` from `$cart->location_id`:
     * without re-stamping here, a customer who switches branches keeps
     * every EXISTING item pointed at the OLD branch's pool while every NEW
     * item they add lands in the new one, which is precisely the
     * pickup-location-vs-stock-location mismatch this whole fix exists to
     * close. Written for every kept item, not only the ones whose quantity
     * changed — `evaluateLocationChange()` re-validates ALL of them against
     * `$newLocation`, so a decision that happens to keep the same quantity
     * is still a decision about the new location and must not leave a
     * stale value behind.
     *
     * @return array{reduced: list<array{item: CartItem, from: int, to: int}>, removed: list<CartItem>}
     */
    public function setLocation(Cart $cart, Location $newLocation): array
    {
        return DB::transaction(function () use ($cart, $newLocation): array {
            // Same reasoning as convertToOrder()'s own lockForUpdate() docblock:
            // a locking read on this SPECIFIC row, not a Builder discarded
            // without ->first()/->get(). Guards against a double-submitted
            // confirm (two tabs) racing each other's revalidation.
            $cart = Cart::where('id', $cart->id)->lockForUpdate()->firstOrFail();

            $decisions = $this->evaluateLocationChange($cart, $newLocation, forUpdate: true);

            $reduced = [];
            $removed = [];

            foreach ($decisions as $decision) {
                $item = $decision['item'];
                $kept = $decision['kept'];

                if ($kept === 0) {
                    $removed[] = $item;
                    $item->delete();

                    continue;
                }

                if ($kept < $decision['requested']) {
                    $pricing = $this->availability->calculatePricing($item->service, $item->rental_days, $kept);

                    $item->update([
                        'quantity' => $kept,
                        'location_id' => $newLocation->id,
                        'unit_price' => $pricing['unit_price'],
                        'total_price' => $pricing['total'],
                        'price_snapshot' => $pricing,
                    ]);

                    $reduced[] = ['item' => $item->fresh(), 'from' => $decision['requested'], 'to' => $kept];
                } else {
                    // Quantity/pricing unchanged, but this row must still be
                    // re-stamped to the NEW pickup location — see this
                    // method's own docblock.
                    $item->update(['location_id' => $newLocation->id]);
                }
            }

            $cart->update(['location_id' => $newLocation->id]);

            return ['reduced' => $reduced, 'removed' => $removed];
        });
    }

    /**
     * Shared core of previewLocationChange()/setLocation() — greedy,
     * deterministic ordering (`orderBy('service_id')->orderBy('id')`,
     * same as convertToOrder()) so both methods reach the SAME decision for
     * the same cart state, and so reverting $forUpdate manually reproduces
     * convertToOrder()'s own read/write split for a falsifiability check.
     *
     * Sibling aggregation mirrors convertToOrder()'s Zasada 7 pattern, keyed
     * by service_id ALONE (not "service_id|location_id" like convertToOrder) —
     * every item here is being evaluated against the SAME $newLocation, so
     * there is only one location bucket in play, unlike convertToOrder()
     * where each item can carry its own already-set location_id.
     *
     * `$forUpdate` controls BOTH the Service row lock and
     * getAvailableQuantity()'s own locking reads together — never locking
     * for a read-only preview (would serialise unrelated readers for
     * nothing) and never skipping the lock for the real write (would reopen
     * exactly the oversell race Zasada 3 exists to close).
     *
     * @return list<array{item: CartItem, requested: int, available: int, kept: int}>
     */
    private function evaluateLocationChange(Cart $cart, Location $newLocation, bool $forUpdate): array
    {
        if ($newLocation->organization_id !== $cart->organization_id || ! $newLocation->is_active) {
            throw new \InvalidArgumentException(
                'CartService::setLocation() requires an active Location belonging to the cart\'s own organization.'
            );
        }

        $items = $cart->items()->with('service')->orderBy('service_id')->orderBy('id')->get();

        $acceptedByService = [];
        $decisions = [];

        foreach ($items as $item) {
            $service = $forUpdate
                ? Service::lockForUpdate()->findOrFail($item->service_id)
                : $item->service;

            $itemStart = Carbon::parse($item->start_date);
            $itemEnd = Carbon::parse($item->end_date);

            $available = $this->availability->getAvailableQuantity(
                $service,
                $itemStart,
                $itemEnd,
                forUpdate: $forUpdate,
                locationId: $newLocation->id
            );

            $siblingDemand = collect($acceptedByService[$item->service_id] ?? [])
                ->filter(fn (array $accepted): bool => $itemStart->lte($accepted['end']) && $itemEnd->gte($accepted['start']))
                ->sum('quantity');

            $kept = min($item->quantity, max(0, $available - $siblingDemand));

            if ($kept > 0) {
                $acceptedByService[$item->service_id][] = ['start' => $itemStart, 'end' => $itemEnd, 'quantity' => $kept];
            }

            $decisions[] = [
                'item' => $item,
                'requested' => $item->quantity,
                'available' => $available,
                'kept' => $kept,
            ];
        }

        return $decisions;
    }
}
