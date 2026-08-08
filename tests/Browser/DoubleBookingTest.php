<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Pest\Browser\ServerManager;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| E2E: Double-booking guard — same unit, overlapping dates, two customers
|--------------------------------------------------------------------------
|
| The scenario that costs a real customer standing outside the warehouse for
| equipment that isn't there: quantity_total = 1, customer A completes a
| paid order for a date, then customer B tries to book the SAME date. This
| must be refused, and silently — nobody notices until the two customers
| actually collide.
|
| WHERE availability is actually enforced (read the code, don't assume):
| there are THREE separate layers, not one.
|   1. Read-only display: RentalBookingController::checkAvailability() /
|      monthlyAvailability() -> RentalAvailabilityService::getAvailableQuantity()
|      with forUpdate: false. This drives the calendar's day-cell colouring
|      AND the "Dodaj do koszyka" button's x-show="canBook" (services/show.blade.php,
|      the Alpine component's checkRangeAvailability()). It is real server data,
|      but it is a SNAPSHOT taken once, right after the customer picks a date —
|      Alpine never re-polls it, so it can go stale.
|   2. Actual write-time gate: CartService::addItem() (app/Services/Cart/CartService.php:96-99)
|      re-runs the SAME getAvailableQuantity() call, but with forUpdate: true,
|      inside a DB transaction that has already taken Service::lockForUpdate() —
|      and THROWS RentalUnavailableException if the requested quantity exceeds
|      what's actually still available. This is what CartController::add()
|      catches and turns into the "availability" flash error. THIS is the gate
|      that actually decides whether a CartItem (and therefore, eventually, an
|      Order) gets created.
|   3. A second copy of the same gate in CartService::convertToOrder() (the
|      checkout step) — belt-and-braces for the case a cart item was added
|      when the slot was free but sold out before checkout.
|
| This test targets layer 2 specifically — the layer that decides whether the
| add-to-cart request that actually creates DB rows succeeds — not layer 1
| (the calendar's display), because layer 1 uses a DIFFERENT code path
| (RentalBookingController, not CartService) and would still correctly show
| "unavailable" even if CartService::addItem()'s own check were deleted. A
| test that only proves "the button never appeared" would NOT catch a
| regression in the write-time gate, since nothing forces the button's
| visibility to track it.
|
| To exercise layer 2 specifically we need the browser to actually SUBMIT the
| add-to-cart form after it already rendered as available — i.e. a customer
| whose page went stale between "the calendar last checked" and "they clicked
| submit". That is precisely the realistic, silent failure mode described in
| the task: two customers, sequential, no visible conflict until the second
| one's click lands on the server.
|
|--------------------------------------------------------------------------
| Sequential, not concurrent — and why that's the honest scope here
|--------------------------------------------------------------------------
|
| A genuine "two customers click at the same instant" race cannot be driven
| through this harness: a second Playwright browser context/page opened while
| the first is still alive deadlocks the in-process test server with no
| exception (see tests/Browser/EmployeeCreationTest.php's docblock and
| .claude/rules/tests.md). So customer A's purchase is modelled directly at
| the DB layer (Order::factory()->paid() + a matching OrderItem), not through
| a second driven browser session — the full paid-checkout path through the
| real UI is already covered end-to-end by RentalCheckoutTest; repeating it
| here would only restate that coverage under a different name. What this
| test adds is customer B's stale-client attempt landing on the server AFTER
| A's purchase already committed — the realistic, common case ("a customer
| finished checking out a minute before you clicked buy"), not the rarer true
| race (both requests mid-flight at the exact same instant). If that true
| race turns out to have its own gap, it is a separate finding, not something
| this test can prove or disprove with a browser.
|
*/

afterEach(function () {
    $port = ServerManager::instance()->http()->port;
    app('url')->useAssetOrigin("http://127.0.0.1:{$port}");
});

it('refuses a second customer\'s stale add-to-cart for equipment already sold for that date', function () {
    // Same reason as in every other Browser test file: one PHP process for
    // the whole suite, leftover login-throttle hits and cached tenant
    // lookups from earlier files would otherwise bleed in here.
    Cache::flush();

    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

    // "grent" is one of only two slugs that resolve inside this container
    // (see tests/Pest.php). equipmentRental() sets booking_type=item_rental.
    $organization = Organization::factory()->equipmentRental()->create(['slug' => 'grent']);

    // The whole point: exactly one unit exists, so one sale exhausts it.
    $service = Service::factory()->itemRental()->create([
        'organization_id' => $organization->id,
        'name' => 'Zagęszczarka gruntu E2E',
        'quantity_total' => 1,
        'deposit_amount' => null,
        'price_on_request' => false,
    ]);

    $passwordB = 'e2e-customer-b-password';
    $customerB = User::factory()->create([
        'password' => Hash::make($passwordB),
    ]);
    $customerB->assignRole('customer');
    $customerB->organizations()->attach($organization->id, ['role' => 'customer']);

    // Customer A never logs into the browser here — see the docblock above
    // for why their purchase is modelled at the DB layer instead.
    $customerA = User::factory()->create();
    $customerA->assignRole('customer');
    $customerA->organizations()->attach($organization->id, ['role' => 'customer']);

    $port = ServerManager::instance()->http()->port;
    $baseUrl = "http://grent.registro.local:{$port}";

    // See RentalCheckoutTest's docblock: without this, Vite's module <script>
    // tag is cross-origin against the tenant subdomain and Alpine never
    // initializes on the storefront at all.
    app('url')->useAssetOrigin($baseUrl);

    // --- Customer B logs in and starts browsing while the slot is still free ---
    $page = visit("{$baseUrl}/login");

    $page->fill('email', $customerB->email)
        ->fill('password', $passwordB)
        ->click('button[type="submit"]')
        ->waitForEvent('load')
        ->assertDontSee('Zaloguj się');

    $page->navigate("{$baseUrl}/uslugi/{$service->slug}");
    $page->assertNoJavaScriptErrors();

    // Single-day rental on "today" — same [aria-current="date"] trick as
    // RentalCheckoutTest, second click on the same cell hits selectDate()'s
    // "second click on the start date" branch. At this point nothing has
    // been sold yet, so the cell IS clickable and the range-availability
    // fetch (Alpine's $watch on selectedEnd) is about to report 1 available.
    $page->click('[role="gridcell"][aria-current="date"]')
        ->click('[role="gridcell"][aria-current="date"]');

    // Fixed delay, not a guessed one: this plugin version exposes no public
    // "wait until element visible" primitive (only waitForEvent(), which
    // waits for a page-load event — there is none here, this is an in-page
    // fetch(), not a navigation). See EmployeeCreationTest's docblock for the
    // same limitation hit the same way. The fetch below is same-process,
    // in-memory SQLite — 1s is generous headroom, confirmed non-flaky across
    // repeated runs (see report).
    $page->wait(1);

    // Proves the staging assumption explicitly: the form rendered because the
    // slot really was free at this point — not a lucky race. If this line
    // itself started failing, that would mean the fetch above is slower than
    // 1s, not that the feature is broken.
    $page->assertVisible('Dodaj do koszyka');

    // --- Meanwhile, customer A finishes buying the ONLY unit for that exact
    // day. Modelled at the DB layer for the reasons in the top docblock. ---
    $competingOrder = Order::factory()->paid()->create([
        'organization_id' => $organization->id,
        'user_id' => $customerA->id,
    ]);

    OrderItem::factory()->create([
        'order_id' => $competingOrder->id,
        'service_id' => $service->id,
        'quantity' => 1,
        'start_date' => now()->toDateString(),
        'end_date' => now()->toDateString(),
    ]);

    // --- Customer B submits the now-stale form. Their browser still thinks
    // the slot is free (nothing re-polled it) — the server has the last
    // word, via CartService::addItem()'s forUpdate: true re-check. ---
    $page->click('Dodaj do koszyka')
        ->waitForEvent('load')
        ->assertSee('Dostępnych tylko 0 szt.');

    // --- DB-level proof: B never got a cart item at all, and exactly one
    // order exists for this service on this date — A's. ---
    $this->assertDatabaseMissing('cart_items', [
        'service_id' => $service->id,
    ]);

    $ordersForThisSlot = Order::where('organization_id', $organization->id)
        ->whereHas('items', fn ($q) => $q->where('service_id', $service->id)
            ->whereDate('start_date', now()->toDateString()))
        ->count();

    expect($ordersForThisSlot)->toBe(1);

    $this->assertDatabaseHas('orders', [
        'id' => $competingOrder->id,
        'status' => 'paid',
    ]);
});
