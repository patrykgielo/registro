<?php

declare(strict_types=1);

use App\Models\EmailSend;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Pest\Browser\ServerManager;
use Spatie\Permission\Models\Role;

/*
|--------------------------------------------------------------------------
| E2E: customer order cancellation — cancels cleanly and emails the customer
|--------------------------------------------------------------------------
|
| Two things this file protects, deliberately kept in one file because they
| are the same feature's two halves (can cancel / cannot cancel), not two
| unrelated concerns:
|
| 1. What ACTUALLY happens today when a customer cancels a pending_payment
|    order. Like OrderLifecycleEmailTest, notifications are NOT faked here —
|    the FakeEmailGateway swap (AppServiceProvider::register(), APP_ENV=testing)
|    still exercises the real `email_templates` lookup and writes real
|    `email_sends` rows.
|
| 2. The "Anuluj zamówienie" button in resources/views/orders/show.blade.php
|    is gated by `@if($order->status === 'pending_payment')` — a 'paid' order
|    must not even show the button. That HTTP-layer guard
|    (`abort_unless($order->status === 'pending_payment', 403)` in
|    OrderController::cancel()) is a Feature test's job, not this one's — this
|    file only proves the button itself is genuinely absent from the screen.
|
| FORMERLY a genuine, confirmed 500: `EmailTemplate` (app/Models/EmailTemplate.php)
| uses `BelongsToOrganization` (app/Traits/BelongsToOrganization.php), whose
| global scope filtered every query to `organization_id = <current tenant>`
| the instant a tenant was resolved — but every seeded template has
| `organization_id = NULL` (by design — they are meant to be global unless a
| tenant explicitly overrides one). `OrderCancelledNotification`'s
| `EmailService::sendFromTemplate('order-cancelled', ...)` therefore always
| threw "Email template 'order-cancelled' not found for language 'pl'.", and
| unlike the admin "confirm" action (Filament wraps it in a try/catch, see the
| other file), `OrderController::cancel()` has NO try/catch around
| `OrderService::cancel()`, so the exception reached the customer as a bare
| Laravel error page.
|
| FIXED: `EmailTemplate::resolveActive()` (app/Models/EmailTemplate.php)
| bypasses the trait's global scope deliberately and replaces it with an
| explicit one — current tenant's own override OR the global (NULL) row,
| never another tenant's row (see that method's docblock for the full
| cross-tenant argument). `EmailService::sendFromTemplate()` now calls it
| instead of a plain `::where()->first()`. `OrderController::cancel()` is
| intentionally UNCHANGED — the missing try/catch is a separate resilience
| question, decided separately; with the template lookup fixed it simply
| never throws on this path anymore.
|
*/

afterEach(function () {
    $port = ServerManager::instance()->http()->port;
    app('url')->useAssetOrigin("http://127.0.0.1:{$port}");
});

it('cancels cleanly, sets cancelled_at, and emails the customer', function () {
    Cache::flush();

    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

    $organization = Organization::factory()->equipmentRental()->create(['slug' => 'grent']);

    $password = 'e2e-customer-password';
    $customer = User::factory()->create([
        'password' => Hash::make($password),
    ]);
    $customer->assignRole('customer');
    $customer->organizations()->attach($organization->id, ['role' => 'customer']);

    $order = Order::factory()->pendingPayment()->create([
        'organization_id' => $organization->id,
        'user_id' => $customer->id,
        'customer_email' => $customer->email,
        'customer_first_name' => $customer->first_name,
        'customer_last_name' => $customer->last_name,
    ]);
    OrderItem::factory()->create(['order_id' => $order->id]);

    $port = ServerManager::instance()->http()->port;
    $baseUrl = "http://grent.registro.local:{$port}";

    // Not strictly required by this page (the cancel button is plain
    // server-rendered Blade with an inline onsubmit, no Alpine binding) but
    // the shared layout's navbar dropdown IS Alpine-driven — kept for
    // consistency with every other storefront test in this suite and to
    // avoid a false-negative assertNoJavaScriptErrors() elsewhere later.
    app('url')->useAssetOrigin($baseUrl);

    $page = visit("{$baseUrl}/login");

    $page->fill('email', $customer->email)
        ->fill('password', $password)
        ->click('button[type="submit"]')
        ->waitForEvent('load')
        ->assertDontSee('Zaloguj się');

    $page->navigate("{$baseUrl}/moje-zamowienia/{$order->id}");

    // TRAP: the cancel form is `onsubmit="return confirm(...)"`
    // (resources/views/orders/show.blade.php:655). Playwright auto-dismisses
    // native dialogs, so confirm() returns false and the form silently never
    // submits — a click() here would report success while testing nothing.
    // The plugin has no dialog-handling API, so neutralise confirm() itself
    // before clicking, and verify the click really submitted via the DB
    // assertions below, not just what the screen shows.
    $page->script('window.confirm = () => true;');

    $page->click('form[action$="/anuluj"] button[type="submit"]')
        ->waitForEvent('load');

    // The friendly redirect-with-flash-message OrderController::cancel() always
    // intended — no more bare 500 (see file docblock for the fixed root cause).
    $page->assertSee('Zamówienie zostało anulowane.');

    // The order is genuinely cancelled server-side, AND cancelled_at is now
    // actually set — OrderService::cancel()'s update() call runs to completion
    // because the notification's template lookup no longer throws.
    $order->refresh();
    expect($order->status)->toBe('cancelled');
    expect($order->cancelled_at)->not->toBeNull();

    // The real order-cancelled email genuinely landed in email_sends this time.
    $email = EmailSend::where('template_key', 'order-cancelled')
        ->where('recipient_email', $customer->email)
        ->first();

    expect($email)->not->toBeNull();
    expect($email->status)->not->toBe('failed');
    expect($email->error_message)->toBeNull();
});

it('does not show the cancel button on an order that is no longer pending payment', function () {
    Cache::flush();

    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

    $organization = Organization::factory()->equipmentRental()->create(['slug' => 'grent']);

    $password = 'e2e-customer-password';
    $customer = User::factory()->create([
        'password' => Hash::make($password),
    ]);
    $customer->assignRole('customer');
    $customer->organizations()->attach($organization->id, ['role' => 'customer']);

    $order = Order::factory()->paid()->create([
        'organization_id' => $organization->id,
        'user_id' => $customer->id,
        'customer_email' => $customer->email,
        'customer_first_name' => $customer->first_name,
        'customer_last_name' => $customer->last_name,
    ]);
    OrderItem::factory()->create(['order_id' => $order->id]);

    $port = ServerManager::instance()->http()->port;
    $baseUrl = "http://grent.registro.local:{$port}";

    app('url')->useAssetOrigin($baseUrl);

    $page = visit("{$baseUrl}/login");

    $page->fill('email', $customer->email)
        ->fill('password', $password)
        ->click('button[type="submit"]')
        ->waitForEvent('load')
        ->assertDontSee('Zaloguj się');

    $page->navigate("{$baseUrl}/moje-zamowienia/{$order->id}")
        ->assertDontSee('Anuluj zamówienie');
});
