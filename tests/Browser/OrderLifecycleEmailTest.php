<?php

declare(strict_types=1);

use App\Models\EmailSend;
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
| E2E: an order's real email trail, payment -> confirmed -> returned
|--------------------------------------------------------------------------
|
| Every existing order/rental test under tests/Feature uses Notification::fake(),
| which means the layer from Notification -> EmailServiceChannel -> EmailService
| -> `email_templates` lookup is completely untested — that is exactly where
| this project's email bugs have actually lived (PR #141: a failed send blocked
| its own retry forever). This test does NOT fake notifications: in
| APP_ENV=testing the gateway is already swapped to FakeEmailGateway
| (AppServiceProvider::register(), NOT this file) — it logs instead of sending
| but still performs the real template lookup and writes real `email_sends`
| rows, which is what every assertion below reads.
|
| Reuses RentalCheckoutTest's storefront flow verbatim through "paid order" —
| see that file's docblock for the reasoning behind useAssetOrigin(), the
| single-day calendar double-click, and why FakePaymentController (not a real
| Przelewy24 round-trip) is the right way to reach a paid order in this
| harness. This file starts where that one stops.
|
| Across this order's lifecycle (paid -> confirmed -> in_progress ->
| completed), there are still THREE separate, unrelated things going on —
| kept from the original investigation because two of them are still true
| after the fix below:
|
| 1. paid: FakePaymentController calls `$order->status()->transitionTo('paid')`
|    directly. The ONLY place in the app that dispatches `event(new
|    OrderPaid($order))` is Przelewy24Service::handleWebhook()
|    (app/Services/Payment/Przelewy24Service.php:243), reached from the real
|    webhook, never from this dev bypass — a dev/test-tooling gap, not a
|    production bug (a human tester clicking the same "[DEV] Zapłać testowo"
|    button gets no emails either), and NOT touched by the fix below. Still
|    asserted as 0 emails after this step, for that reason.
|
| 2. confirmed: FORMERLY a real production bug, NOW FIXED. Clicking the real
|    "Potwierdź" admin action correctly transitions the order to 'confirmed'
|    in the database — and OrderConfirmedNotification now genuinely sends,
|    because `EmailTemplate::resolveActive()` (app/Models/EmailTemplate.php)
|    bypasses BelongsToOrganization's tenant-restricting global scope
|    deliberately and replaces it with an explicit one: the current tenant's
|    own override OR the global (NULL-organization) row, never another
|    tenant's row (full cross-tenant argument in that method's docblock).
|    `EmailService::sendFromTemplate()` calls it instead of a plain
|    `::where()->first()`. The admin now sees the real success toast
|    ("Zamówienie potwierdzone") for an action that actually succeeded — no
|    more silent false-negative error toast.
|
| 3. in_progress / completed: OrderStatusStateMachine::afterTransitionHooks()
|    only defines hooks for 'confirmed' and 'cancelled' — handing over and
|    getting equipment back never even attempt an email. This is a
|    deliberate, pre-existing gap in the code as it stands today (no hook
|    exists to fire), untouched by this fix, and NOT what this test is
|    about — see the final assertion's comment for why it must survive
|    unchanged.
|
*/

afterEach(function () {
    $port = ServerManager::instance()->http()->port;
    app('url')->useAssetOrigin("http://127.0.0.1:{$port}");
});

it('emails the customer for confirmed, but still not for paid (dev bypass) or in_progress/completed (no hook)', function () {
    Cache::flush();

    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

    $organization = Organization::factory()->equipmentRental()->create(['slug' => 'grent']);

    $service = Service::factory()->itemRental()->create([
        'organization_id' => $organization->id,
        'name' => 'Betoniarka E2E 200L',
        'quantity_total' => 5,
        'deposit_amount' => null,
        'price_on_request' => false,
    ]);

    $customerPassword = 'e2e-customer-password';
    $customer = User::factory()->create([
        'password' => Hash::make($customerPassword),
    ]);
    $customer->assignRole('customer');
    $customer->organizations()->attach($organization->id, ['role' => 'customer']);

    // Admin user created up front (DB-only, no browser interaction yet) so
    // step 3 below can log in as them without ever opening a second browser
    // context — see EmployeeCreationTest's docblock for why a second visit()
    // deadlocks the whole process.
    $adminPassword = 'e2e-admin-password';
    $admin = User::factory()->create([
        'password' => Hash::make($adminPassword),
    ]);
    $admin->assignRole('admin');
    $admin->organizations()->attach($organization->id, ['role' => 'owner']);

    $port = ServerManager::instance()->http()->port;
    $baseUrl = "http://grent.registro.local:{$port}";

    // See RentalCheckoutTest's docblock — required for Alpine.js (the
    // calendar widget, "Dodaj do koszyka") to initialize at all under this
    // harness.
    app('url')->useAssetOrigin($baseUrl);

    // --- Step 1: customer rents equipment and pays -> paid Order ---
    $page = visit("{$baseUrl}/login");

    $page->fill('email', $customer->email)
        ->fill('password', $customerPassword)
        ->click('button[type="submit"]')
        ->waitForEvent('load')
        ->assertDontSee('Zaloguj się');

    $page->navigate("{$baseUrl}/uslugi/{$service->slug}");
    $page->assertNoJavaScriptErrors();

    $page->click('[role="gridcell"][aria-current="date"]')
        ->click('[role="gridcell"][aria-current="date"]')
        ->click('Dodaj do koszyka')
        ->waitForEvent('load')
        ->assertSee('Dodano do koszyka.');

    $page->navigate("{$baseUrl}/koszyk/zamowienie")
        ->click('form[action$="/dev/fake-pay"] button[type="submit"]')
        ->waitForEvent('load')
        ->assertSee('Dziękujemy za zamówienie!');

    $order = Order::where('organization_id', $organization->id)
        ->where('user_id', $customer->id)
        ->firstOrFail();

    expect($order->status)->toBe('paid');

    // --- Step 2: the real email trail for payment — REASON #1 above ---
    expect(EmailSend::count())->toBe(0);

    // --- Step 3: log out through the real storefront form, log in as tenant admin ---
    // Same ONE-browser-context constraint as EmployeeCreationTest: switching
    // users happens by driving the real logout form in this session, never by
    // a second visit(). The real logout <form> (resources/views/components/nav/header.blade.php)
    // only lives inside an Alpine dropdown that is x-cloak'd shut until its
    // trigger is clicked — clicking straight at the hidden button would just
    // time out. Opening it first via a text-based selector on the customer's
    // (fake-data, possibly quote-containing) first_name was tried and
    // deadlocked the whole in-process server on the very first run — no
    // exception, no timeout, just an indefinitely blocked process (the same
    // failure class EmployeeCreationTest's docblock warns about for a second
    // visit(), but reached a different way here: most likely a malformed
    // `:has-text("...")` selector, or the resulting memory blow-up from
    // several such stuck processes accumulating across retries — see this
    // file's own retro in test-engineer agent memory for the full postmortem).
    // Submitting the SAME real, already server-rendered `<form action="/logout">`
    // (real CSRF token included) via script() sidesteps the fragility
    // entirely while still hitting the exact same route the button would.
    //
    // SECOND real finding, confirmed by direct DOM inspection of a 429 response
    // page: `Route::middleware([ResolveTenant::class, 'throttle:5,1'])` wraps
    // BOTH POST /login and POST /logout (routes/web.php) — and Laravel's bare
    // `throttle:N,M` (no third "prefix" argument) builds its cache key from
    // ONLY `$request->user()?->getAuthIdentifier() ?? "{$domain}|{$ip}"`
    // (ThrottleRequests::resolveRequestSignature()), with NO route or limit
    // value in the key. The rental availability endpoints the calendar widget
    // polls during step 1 use the exact same bare `throttle:60,1` — for an
    // AUTHENTICATED user this is the SAME empty-prefix key, so it is the SAME
    // shared bucket. A few calendar/date-selection AJAX calls are enough to
    // push the *stricter* 5/min logout limit over its cap by the time this
    // step runs, even though logout itself is only ever requested once. This
    // is a real, previously-undocumented cross-route rate-limit collision —
    // NOT specific to this test — reported as-is, not fixed here (out of a
    // test-engineer's remit). Cache::flush() here is the same workaround
    // tests/Pest.php already documents for the identical class of problem.
    Cache::flush();
    $page->script('document.querySelector(\'form[action$="/logout"]\').submit();');
    $page->waitForEvent('load');

    $page->navigate("{$baseUrl}/admin/login")
        ->fill('[id="form.email"]', $admin->email)
        ->fill('[id="form.password"]', $adminPassword)
        ->click('button[type="submit"]')
        ->waitForEvent('load')
        ->assertPathIs('/admin');

    // --- Step 4: admin confirms the order through the real Filament action ---
    // EditOrder's header action (app/Filament/Resources/OrderResource/Pages/EditOrder.php)
    // fires the exact same `$record->status()->transitionTo('confirmed')` call
    // as the table's grouped "Potwierdź zamówienie" action in OrderResource.php
    // itself — same model, same afterTransitionHooks(), same OrderConfirmed
    // event. Deliberately used here instead of the table's ActionGroup: that
    // trigger is bound on `x-on:mousedown` inside a generic `.fi-dropdown-trigger`
    // that Filament reuses for several unrelated dropdowns on the same list
    // page (bulk actions, column toggle), so it is not a stable, unambiguous
    // selector — this page has exactly one order and one direct, single-purpose
    // action button instead.
    $page->navigate("{$baseUrl}/admin/orders/{$order->id}/edit")
        ->click('Potwierdź')
        // The modal mounts asynchronously over Livewire (wire:click="mountAction(...)")
        // — confirmed by direct DOM inspection that `.fi-modal-footer-actions`
        // genuinely does not exist yet immediately after click(), even past
        // waitForEvent('networkidle'). There is no "modal mounted" wait
        // primitive in this plugin version (same class of gap as
        // EmployeeCreationTest's Livewire-hydration wait, see that file's
        // docblock), so a short fixed wait is used here deliberately.
        ->wait(2)
        // The confirmation modal's own submit button is ALSO labelled
        // "Potwierdź" (Filament's pl translation for both the action's label
        // and the generic modal confirm button happen to collide) — scoped to
        // the modal footer so this doesn't hit the trigger button again.
        ->click('.fi-modal-footer-actions button[type="submit"]')
        ->wait(2);

    // The real success toast now — no more silent false-negative error toast
    // for an action that actually worked (REASON #2, file docblock).
    $page->assertSee('Zamówienie potwierdzone');

    $order->refresh();
    expect($order->status)->toBe('confirmed');

    // THE central assertion of this test: the real order-confirmed email now
    // exists, because EmailTemplate::resolveActive() (REASON #2, file
    // docblock) finds the global template instead of throwing. If someone
    // narrows or removes that fix, this assertion starts failing and THIS
    // TEST must be updated deliberately, not silently loosened back to null.
    $confirmedEmail = EmailSend::where('template_key', 'order-confirmed')
        ->where('recipient_email', $customer->email)
        ->first();

    expect($confirmedEmail)->not->toBeNull();
    expect($confirmedEmail->status)->not->toBe('failed');
    expect($confirmedEmail->error_message)->toBeNull();

    // --- Step 5: handover + return through the same real admin actions ---
    // REASON #3 (file docblock): no hook exists for these transitions at all —
    // untouched by this fix — so these two simply succeed with no email ever
    // attempted, same as before.
    $page->click('Wydano klientowi')
        ->wait(2)
        ->click('.fi-modal-footer-actions button[type="submit"]')
        ->wait(2);

    $order->refresh();
    expect($order->status)->toBe('in_progress');

    $page->click('Sprzęt zwrócony')
        ->wait(2)
        ->click('.fi-modal-footer-actions button[type="submit"]')
        ->wait(2);

    $order->refresh();
    expect($order->status)->toBe('completed');

    // --- Step 6: the full lifecycle, end to end — exactly one email, two gaps left ---
    // OrderStatusStateMachine::afterTransitionHooks() (app/StateMachines/OrderStatusStateMachine.php)
    // only hooks 'confirmed' and 'cancelled' — the customer is never emailed
    // that their equipment was handed over or that the return was accepted
    // (REASON #3), and the dev payment bypass never dispatches OrderPaid at all
    // (REASON #1). Neither is touched by this fix. So the single order-confirmed
    // row from Step 4 is the ONLY email this exact flow produces end to end —
    // not zero (the bug this file used to document), not four (REASONS #1 and
    // #3 are real, separate gaps, not this bug). This assertion documents that
    // end state; it does NOT endorse REASONS #1/#3. If someone later wires
    // emails for any of those steps, this assertion starts failing and THIS
    // TEST must be updated deliberately — do not delete it to make the suite
    // green.
    expect(EmailSend::count())->toBe(1);
});
