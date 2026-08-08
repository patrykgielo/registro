<?php

declare(strict_types=1);

use App\Models\Order;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Pest\Browser\ServerManager;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| E2E: Rent equipment, get a paid order — the money path
|--------------------------------------------------------------------------
|
| The first test in this suite that touches the storefront instead of the
| admin panel: service page -> add to cart -> checkout -> paid order. This
| is how the business actually earns money, so the point of the test is the
| Order row at the end, not the screens along the way.
|
| Logged-in customer, not a guest: the cart.* / checkout.* route group
| carries the `auth` middleware (routes/web.php) — a guest is redirected to
| /login before ever reaching CartController, so "guest checkout" isn't a
| real path to test here at all.
|
| Payment is deliberately NOT exercised. checkout.submit's real path calls
| Przelewy24Service::registerTransaction() against the actual sandbox API —
| no network in this container, and mocking it would defeat the point of an
| E2E test. Instead this drives the same DEV-only bypass a human tester
| would click: the "[DEV] Zapłać testowo" button on checkout/show.blade.php,
| POSTing to Dev\FakePaymentController (routes/web.php, gated on
| `! app()->isProduction()`). It builds its own minimal order payload from
| the authenticated user's profile — no terms/RODO/withdrawal checkboxes,
| no PESEL/NIP/company fields, no payment gateway — and transitions the
| order straight to 'paid'. That is *why* this test can stay narrow: the
| ~15-field legal/billing form (SubmitCheckoutRequest) is real and already
| covered by tests/Feature/Cart/CheckoutFlowTest.php at the HTTP layer; a
| browser test re-typing all of it would be wide and brittle for no extra
| signal. FakePaymentController itself has no prior test coverage at all —
| this is the first one.
|
| Single-day rental (today -> today), not a date range: the calendar
| widget's day cells carry no id/data-testid, only a computed aria-label
| that embeds the Polish month name and day number — matching it without
| hardcoding "which day of the month is 'today' when this runs" would need
| either a second, brittle date-formatting implementation in PHP mirroring
| the Alpine component's `polishMonths` array, or a multi-day range risking
| a month-boundary edge case (today being the last day of the month) that
| would additionally require driving the calendar's next-month button.
| `[aria-current="date"]` sidesteps both: Alpine sets it on exactly one
| cell regardless of which day that is. Clicking that same cell twice hits
| selectDate()'s "second click on the start date" branch (single-day
| range) — see the inline Alpine component in services/show.blade.php.
|
| No explicit waits anywhere in this file: fill()/click() already
| auto-wait for their target to be visible/enabled (see tests/Pest.php),
| which is exactly the state "Dodaj do koszyka" is in before the
| range-availability AJAX call (triggered by Alpine's $watch on
| selectedEnd) resolves canBook=true — the button start disabled/hidden
| and click() simply retries until it isn't.
|
| useAssetOrigin() below is NOT optional — this is a real, previously
| undiscovered gap in the Browser harness, not a style choice. Every
| Browser test before this one only ever drove the Filament admin panel,
| whose own JS never hit this: LaravelHttpServer::bootstrap() (vendor)
| force-sets config('app.url') to "http://127.0.0.1:{port}" regardless of
| which tenant subdomain the page actually renders on, and Vite's @vite()
| directive builds <script> tags from that value. resources/js/app.js
| compiles to type="module", and per spec module scripts are ALWAYS
| fetched in CORS mode (unlike classic scripts or <link> stylesheets,
| which is why the CSS bundle loads fine regardless) — so the browser
| silently refused to execute it when the page's real origin
| (http://grent.registro.local:{port}) didn't match the script's origin.
| Net effect: Alpine.js never initialized on ANY storefront page under
| this harness — x-show never resolves, x-for never renders a single
| calendar cell, and the failure is invisible to assertNoJavaScriptErrors()
| because failed *resource* loads dispatch a non-bubbling `error` event
| that vendor's window.addEventListener('error', ...) (no capture:true)
| never sees. Confirmed by direct diagnosis (window.Alpine was
| "undefined", 0 gridcells in the DOM) before finding this fix, and by
| window.Alpine becoming a real object + cells rendering after it.
| useAssetOrigin() is a genuine public Laravel API (Illuminate\Routing\
| UrlGenerator), not a monkeypatch — this just corrects vendor's origin
| override to match the tenant host actually under test, mirroring what
| bootstrap() already does for the wrong host. Restored in afterEach()
| because it mutates a process-wide singleton that later Browser test
| files in this same run would otherwise inherit.
|
*/

afterEach(function () {
    $port = ServerManager::instance()->http()->port;
    app('url')->useAssetOrigin("http://127.0.0.1:{$port}");
});

it('lets a logged-in customer rent equipment and creates a paid order', function () {
    // Same reason as loginAsTenantAdmin() in tests/Pest.php: the whole
    // Browser suite runs in one PHP process, so a leftover login-throttle
    // hit or a stale cached tenant:slug:grent Organization from an earlier
    // test file would otherwise bleed into this one.
    Cache::flush();

    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

    // "grent" is one of only two slugs that resolve inside this container
    // (see tests/Pest.php). equipmentRental() sets booking_type=item_rental,
    // which SettingsManager::isRentalEnabled() requires — without it,
    // CheckRentalEnabled would redirect every cart/checkout route to home.
    $organization = Organization::factory()->equipmentRental()->create(['slug' => 'grent']);

    $service = Service::factory()->itemRental()->create([
        'organization_id' => $organization->id,
        'name' => 'Betoniarka E2E 200L',
        'quantity_total' => 5,
        'deposit_amount' => null,
        'price_on_request' => false,
    ]);

    $password = 'e2e-customer-password';
    $customer = User::factory()->create([
        'password' => Hash::make($password),
    ]);
    $customer->assignRole('customer');
    // Mirrors what RegisterController::registered() does for a real
    // subdomain sign-up — not required by CartController/CheckoutController
    // (neither checks canAccessTenant() for non-admin/staff users), but
    // this is what an actual customer account looks like.
    $customer->organizations()->attach($organization->id, ['role' => 'customer']);

    $port = ServerManager::instance()->http()->port;
    $baseUrl = "http://grent.registro.local:{$port}";

    // See the docblock above — corrects vendor's asset-origin override so
    // Vite's module <script> tag is same-origin with the tenant subdomain
    // and Alpine.js actually initializes.
    app('url')->useAssetOrigin($baseUrl);

    // --- Step 1: log in as the customer through the real storefront login form ---
    // Plain Blade (resources/views/auth/login.blade.php via x-ios.input), NOT
    // Filament — inputs render id="email"/id="password" (== the field name),
    // unlike the admin panel's dotted "form.email" statePath.
    $page = visit("{$baseUrl}/login");

    $page->fill('email', $customer->email)
        ->fill('password', $password)
        ->click('button[type="submit"]')
        ->waitForEvent('load')
        ->assertDontSee('Zaloguj się');

    // --- Step 2: service page — select a rental date via the real calendar, add to cart ---
    $page->navigate("{$baseUrl}/uslugi/{$service->slug}");

    // Guards against a real regression found on this exact page: a dead,
    // unconditionally-rendered duplicate CTA block used to bind
    // `:href="bookingUrl"` — a getter that was deleted from the Alpine
    // component when the old rental.step1 wizard was replaced by the
    // Cart/Checkout flow, but the template usage was never cleaned up.
    // Alpine evaluates every binding on initial render regardless of
    // whether the element is ever clicked, so this threw a
    // ReferenceError on every item_rental service page — invisible to
    // the 1054 non-browser tests since none of them execute JS.
    $page->assertNoJavaScriptErrors();

    // Text, not `button[type="submit"]`: a logged-in customer also gets the
    // storefront navbar's own type="submit" logout button on this page, so a
    // bare type-based selector is ambiguous (2 matches) — confirmed the hard
    // way (Playwright strict-mode violation) while building this test.
    $page->click('[role="gridcell"][aria-current="date"]')
        ->click('[role="gridcell"][aria-current="date"]')
        ->click('Dodaj do koszyka')
        ->waitForEvent('load')
        ->assertSee('Dodano do koszyka.');

    // --- Step 3: checkout page, DEV fake-pay bypass instead of Przelewy24 ---
    // Scoped to the fake-pay <form> specifically: checkout/show.blade.php also
    // has the REAL "Zamawiam i płacę" submit button on the same page, so a bare
    // `button[type="submit"]` selector would be ambiguous.
    $page->navigate("{$baseUrl}/koszyk/zamowienie")
        ->click('form[action$="/dev/fake-pay"] button[type="submit"]')
        ->waitForEvent('load')
        ->assertSee('Dziękujemy za zamówienie!');

    // --- Step 4: DB-level proof — order exists, paid, scoped to the right org + service ---
    // The screen-level assertion above already proves an order was found by
    // (p24_session_id, organization_id, user_id) — see CheckoutController::return().
    // This corroborates WHICH order and that it is actually money-complete.
    $order = Order::where('organization_id', $organization->id)
        ->where('user_id', $customer->id)
        ->firstOrFail();

    expect($order->status)->toBe('paid');
    expect($order->items()->count())->toBe(1);
    expect($order->items()->first()->service_id)->toBe($service->id);

    $this->assertDatabaseHas('carts', [
        'user_id' => $customer->id,
        'organization_id' => $organization->id,
        'status' => 'converted',
    ]);
});
