<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Events\OrderAcceptedOffline;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use App\Notifications\OrderAcceptedOfflineNotification;
use App\Notifications\OrderPaidNotification;
use App\Services\Payment\Przelewy24Service;
use App\Support\Settings\SettingsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Faza 1 of app/docs/features/payment-settlement-modes.md — "pay at pickup"
 * checkout. Complements OfflineSettlement unit coverage in
 * CartServiceTest/RentalAvailabilityServiceTest/OrderServiceTest with the
 * end-to-end HTTP flow.
 */
class OfflineSettlementCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([ThrottleRequests::class]);

        $this->org = Organization::factory()->equipmentRental()->create();
        $this->user = User::factory()->create();
    }

    private function actingAsTenant(Organization $org): static
    {
        $this->app->bind(\App\Http\Middleware\ResolveTenant::class, function () use ($org) {
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

    /**
     * Writes checkout settlement settings through SettingsManager::set() — the
     * exact call SystemSettings' Checkout tab makes — while impersonating the
     * given tenant.
     */
    private function enableOfflineSettlement(Organization $org, ?int $holdHours = null): void
    {
        app('request')->attributes->set('tenant', $org);

        $settings = app(SettingsManager::class);
        $settings->set('checkout.settlement_offline_enabled', true);
        if ($holdHours !== null) {
            $settings->set('checkout.offline_reservation_hold_hours', $holdHours);
        }

        app('request')->attributes->remove('tenant');
    }

    private function validOfflinePayload(): array
    {
        return [
            'customer_type' => 'natural_person',
            'settlement_method' => 'offline',
            'customer_first_name' => 'Jan',
            'customer_last_name' => 'Kowalski',
            'customer_email' => 'jan.kowalski@test.pl',
            'customer_phone' => '500100200',
            'customer_pesel' => '44051401458',
            'customer_street' => 'Marszałkowska',
            'customer_building' => '1',
            'customer_apartment' => null,
            'customer_city' => 'Warszawa',
            'customer_postal_code' => '00-001',
            'invoice_requested' => false,
            'terms_accepted' => true,
            'rodo_accepted' => true,
            'withdrawal_exclusion_accepted' => true,
        ];
    }

    private function cartWithItem(): Cart
    {
        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 5,
        ]);

        $cart = Cart::factory()->active()->create([
            'user_id' => $this->user->id,
            'organization_id' => $this->org->id,
        ]);

        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'service_id' => $service->id,
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'quantity' => 1,
            'rental_days' => 3,
            'unit_price' => 100.00,
            'total_price' => 300.00,
        ]);

        return $cart;
    }

    // -------------------------------------------------------------------------
    // Gated by tenant settings
    // -------------------------------------------------------------------------

    public function test_offline_settlement_is_rejected_when_tenant_has_not_enabled_it(): void
    {
        // Default: checkout.settlement_offline_enabled = false — never explicitly enabled.
        $this->mock(Przelewy24Service::class, fn ($mock) => $mock->shouldReceive('registerTransaction')->never());

        $this->cartWithItem();

        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $this->validOfflinePayload());

        $response->assertSessionHasErrors('settlement_method');
        $this->assertDatabaseCount('orders', 0);
    }

    // -------------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------------

    public function test_offline_checkout_creates_pending_payment_order_without_calling_p24(): void
    {
        $this->enableOfflineSettlement($this->org);
        $this->mock(Przelewy24Service::class, fn ($mock) => $mock->shouldReceive('registerTransaction')->never());

        $this->cartWithItem();

        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $this->validOfflinePayload());

        $response->assertRedirect();

        $this->assertDatabaseHas('orders', [
            'user_id' => $this->user->id,
            'organization_id' => $this->org->id,
            'status' => 'pending_payment',
            'settlement_method' => 'offline',
        ]);
    }

    public function test_offline_checkout_redirects_to_the_return_page_scoped_to_the_new_order(): void
    {
        $this->enableOfflineSettlement($this->org);
        $this->mock(Przelewy24Service::class, fn ($mock) => $mock->shouldReceive('registerTransaction')->never());

        $this->cartWithItem();

        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $this->validOfflinePayload());

        $order = Order::where('user_id', $this->user->id)->firstOrFail();

        $response->assertRedirect(route('checkout.return', ['order' => $order->id]));
    }

    public function test_offline_checkout_dispatches_order_accepted_offline_event(): void
    {
        $this->enableOfflineSettlement($this->org);
        Event::fake([OrderAcceptedOffline::class]);

        $this->cartWithItem();

        $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $this->validOfflinePayload());

        Event::assertDispatched(OrderAcceptedOffline::class);
    }

    public function test_offline_checkout_sends_accepted_offline_notification_not_order_paid(): void
    {
        $this->enableOfflineSettlement($this->org);
        Notification::fake();

        $this->cartWithItem();

        $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $this->validOfflinePayload());

        Notification::assertSentTo($this->user, OrderAcceptedOfflineNotification::class);
        Notification::assertNotSentTo($this->user, OrderPaidNotification::class);
    }

    public function test_offline_checkout_sets_expires_at_using_configured_hold_hours(): void
    {
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-04-01 09:00:00'));

        $this->enableOfflineSettlement($this->org, holdHours: 72);

        $this->cartWithItem();

        $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $this->validOfflinePayload());

        $order = Order::where('user_id', $this->user->id)->firstOrFail();

        $this->assertTrue($order->expires_at->equalTo(\Illuminate\Support\Carbon::parse('2026-04-04 09:00:00')));

        \Illuminate\Support\Carbon::setTestNow();
    }

    // -------------------------------------------------------------------------
    // Online still works unaffected when both methods are enabled
    // -------------------------------------------------------------------------

    public function test_online_checkout_still_works_when_tenant_has_both_methods_enabled(): void
    {
        $this->enableOfflineSettlement($this->org);

        $fakePaymentUrl = 'https://sandbox.przelewy24.pl/trnRequest/fake-token';
        $this->mock(Przelewy24Service::class, function ($mock) use ($fakePaymentUrl) {
            $mock->shouldReceive('registerTransaction')->once()->andReturn($fakePaymentUrl);
        });

        $this->cartWithItem();

        $payload = $this->validOfflinePayload();
        $payload['settlement_method'] = 'online';

        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $payload);

        $response->assertRedirect($fakePaymentUrl);

        $this->assertDatabaseHas('orders', [
            'user_id' => $this->user->id,
            'settlement_method' => 'online',
        ]);
    }
}
