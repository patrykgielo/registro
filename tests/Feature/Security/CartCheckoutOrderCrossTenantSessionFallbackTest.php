<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Http\Middleware\ResolveTenant;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * VULN-003 "Layer 4": cart.*, checkout.*, orders.* routes sat behind
 * ['auth', ResolveTenant::class, CheckRentalEnabled::class] only (no
 * RequireTenant) — the identical root cause already fixed for booking/
 * appointments in Layer 3. ResolveTenant writes session()->put('tenant_id', ...)
 * on EVERY successful subdomain visit — even an anonymous, unauthenticated one
 * — before any authorization check runs. An authenticated customer of Org A
 * could visit orgB.<domain>/ (any public page) to poison their own session
 * with tenant_id = orgB.id, then hit the root-domain cart/checkout/orders flow
 * while authenticated. TenantFeature::currentTenant() resolves Org B via its
 * 3rd fallback branch (session), which is non-null — so every controller's
 * `abort_unless($org !== null, 404)` guard passes with the WRONG tenant.
 * Net effect: cross-tenant READ + WRITE of e-commerce data (Cart/CartItem/
 * Order rows created or modified under a tenant the customer never legitimately
 * resolved on this request).
 *
 * Fix: routes/web.php's cart.*, checkout.*, orders.* group now also carries
 * RequireTenant::class, which gates on the `tenant` request attribute
 * directly — never on the session fallback — closing this regardless of
 * stale session content. See
 * app/docs/security/vulnerabilities/VULN-003-root-domain-tenant-bypass.md
 * (Layer 4).
 */
class CartCheckoutOrderCrossTenantSessionFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.domain' => 'registro.local']);
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    /**
     * Bind a test double for ResolveTenant — same pattern used throughout the
     * project (e.g. BookingCrossTenantSessionFallbackTest::actingAsTenant()).
     */
    private function actingAsTenant(Organization $org): static
    {
        $this->app->bind(ResolveTenant::class, function () use ($org) {
            return new class($org)
            {
                public function __construct(private Organization $org) {}

                public function handle($request, $next)
                {
                    $request->attributes->set('tenant', $this->org);

                    return $next($request);
                }
            };
        });

        return $this;
    }

    // -------------------------------------------------------------------------
    // Negative: root domain + poisoned session must 404, no controller logic runs
    // -------------------------------------------------------------------------

    public function test_cart_show_returns_404_on_root_domain_with_poisoned_session(): void
    {
        $orgA = Organization::factory()->equipmentRental()->create();
        $orgB = Organization::factory()->equipmentRental()->create();

        $customer = User::factory()->create();
        $customer->assignRole('customer');
        $customer->organizations()->attach($orgA->id);

        $response = $this->actingAs($customer)
            ->withSession(['tenant_id' => $orgB->id])
            ->get('http://registro.local/koszyk');

        $response->assertNotFound();
    }

    public function test_checkout_show_returns_404_on_root_domain_with_poisoned_session(): void
    {
        $orgA = Organization::factory()->equipmentRental()->create();
        $orgB = Organization::factory()->equipmentRental()->create();

        $customer = User::factory()->create();
        $customer->assignRole('customer');
        $customer->organizations()->attach($orgA->id);

        $response = $this->actingAs($customer)
            ->withSession(['tenant_id' => $orgB->id])
            ->get('http://registro.local/koszyk/zamowienie');

        $response->assertNotFound();
    }

    public function test_orders_index_returns_404_on_root_domain_with_poisoned_session(): void
    {
        $orgA = Organization::factory()->equipmentRental()->create();
        $orgB = Organization::factory()->equipmentRental()->create();

        $customer = User::factory()->create();
        $customer->assignRole('customer');
        $customer->organizations()->attach($orgA->id);

        $response = $this->actingAs($customer)
            ->withSession(['tenant_id' => $orgB->id])
            ->get('http://registro.local/moje-zamowienia');

        $response->assertNotFound();
    }

    /**
     * Write-path: cart.add. Builds a REAL, addable Service under Org B — the
     * tenant the poisoned session resolves to via TenantFeature::currentTenant()'s
     * 3rd fallback branch. Without this, `service_id` would fail its own
     * `exists:services,id` validation rule, proving nothing about RequireTenant
     * (same lesson as the booking fix's `service_id: 1`-doesn't-exist bug).
     *
     * Verified manually: with RequireTenant::class temporarily removed from
     * this route group, this exact request DOES reach CartController::add()
     * and CartService::addItem() creates a CartItem scoped to Org B — this
     * test then fails (see PHPUnit run captured during implementation).
     */
    public function test_cart_add_returns_404_on_root_domain_with_poisoned_session(): void
    {
        $orgA = Organization::factory()->equipmentRental()->create();
        $orgB = Organization::factory()->equipmentRental()->create();

        // Setting the `tenant` request attribute here (not via the actual HTTP
        // call below) only affects organization_id auto-assignment for this
        // setup-time factory create — same pattern as the booking Layer 3 fix.
        $this->app['request']->attributes->set('tenant', $orgB);
        $service = Service::factory()->itemRental()->create([
            'organization_id' => $orgB->id,
            'quantity_total' => 5,
        ]);

        $customer = User::factory()->create();
        $customer->assignRole('customer');
        $customer->organizations()->attach($orgA->id);

        $response = $this->actingAs($customer)
            ->withSession(['tenant_id' => $orgB->id])
            ->post('http://registro.local/koszyk/dodaj', [
                'service_id' => $service->id,
                'start_date' => now()->addDay()->toDateString(),
                'end_date' => now()->addDays(3)->toDateString(),
                'quantity' => 1,
            ]);

        $response->assertNotFound();

        $this->assertDatabaseMissing('cart_items', [
            'service_id' => $service->id,
        ]);
    }

    /**
     * Write-path: orders.cancel. Builds a REAL pending order under Org B,
     * owned by the attacking customer, then attempts to cancel it through
     * the poisoned root-domain session. Proves RequireTenant blocks the
     * request before OrderController::cancel() can mutate the order.
     */
    public function test_orders_cancel_returns_404_on_root_domain_with_poisoned_session(): void
    {
        $orgA = Organization::factory()->equipmentRental()->create();
        $orgB = Organization::factory()->equipmentRental()->create();

        $customer = User::factory()->create();
        $customer->assignRole('customer');
        $customer->organizations()->attach($orgA->id);

        $this->app['request']->attributes->set('tenant', $orgB);
        $order = Order::factory()->pendingPayment()->create([
            'user_id' => $customer->id,
            'organization_id' => $orgB->id,
        ]);

        // Pre-fix, this request reaches OrderController::cancel() and actually
        // transitions the order, which fires OrderCancelled -> a queued
        // notification. Fake it so a failure here reports a clean status-code
        // mismatch instead of an unrelated "email template not found" crash
        // (same email_templates gotcha noted in the Layer 3 booking fix).
        Notification::fake();

        $response = $this->actingAs($customer)
            ->withSession(['tenant_id' => $orgB->id])
            ->post("http://registro.local/moje-zamowienia/{$order->id}/anuluj");

        $response->assertNotFound();

        $order->refresh();
        $this->assertSame('pending_payment', $order->status);
        $this->assertNull($order->cancelled_at);
    }

    // -------------------------------------------------------------------------
    // Positive controls: real tenant subdomain flow unaffected
    // -------------------------------------------------------------------------

    public function test_cart_show_works_normally_on_real_tenant_subdomain(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $this->app['request']->attributes->set('tenant', $org);

        $customer = User::factory()->create();
        $customer->assignRole('customer');
        $customer->organizations()->attach($org->id);

        $response = $this->actingAs($customer)
            ->actingAsTenant($org)
            ->get(route('cart.show'));

        $response->assertOk();
        $response->assertViewIs('cart.show');
    }

    public function test_orders_index_works_normally_on_real_tenant_subdomain(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $this->app['request']->attributes->set('tenant', $org);

        $customer = User::factory()->create();
        $customer->assignRole('customer');
        $customer->organizations()->attach($org->id);

        $response = $this->actingAs($customer)
            ->actingAsTenant($org)
            ->get(route('orders.index'));

        $response->assertOk();
        $response->assertViewIs('orders.index');
    }

    public function test_cart_add_works_normally_on_real_tenant_subdomain(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $this->app['request']->attributes->set('tenant', $org);

        $service = Service::factory()->itemRental()->create([
            'organization_id' => $org->id,
            'quantity_total' => 5,
        ]);

        $customer = User::factory()->create();
        $customer->assignRole('customer');
        $customer->organizations()->attach($org->id);

        $response = $this->actingAs($customer)
            ->actingAsTenant($org)
            ->post(route('cart.add'), [
                'service_id' => $service->id,
                'start_date' => now()->addDay()->toDateString(),
                'end_date' => now()->addDays(3)->toDateString(),
                'quantity' => 1,
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('cart_items', [
            'service_id' => $service->id,
            'quantity' => 1,
        ]);
    }
}
