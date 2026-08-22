<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Exceptions\PaymentGatewayNotConfiguredException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use App\Services\Payment\Przelewy24Service;
use App\Support\Settings\SettingsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Production 500 on checkout submit, 2026-08-16.
 *
 * An unconfigured Przelewy24 gateway made config/przelewy24.php hand the SDK
 * an int for its `?string $posId` parameter. Under this app's
 * declare(strict_types=1) that is a TypeError — an \Error, NOT an \Exception —
 * raised inside the constructor, before any network I/O. CheckoutController's
 * compensation path caught \Exception, so the \Error sailed straight through
 * it: the customer got a 500, the order was orphaned in pending_payment
 * (blocking inventory until TTL) and their cart was left 'converted', i.e.
 * empty and unusable.
 *
 * These tests pin BEHAVIOUR, not the TypeError. They never mock
 * Przelewy24Service on the paths that matter — the real service runs against a
 * deliberately empty config, which is the exact production state. A future
 * refactor that reintroduces any throwable from registration (type error,
 * missing credential, SDK change) still has to end in "graceful refusal, order
 * cancelled, cart restored" or these fail.
 */
class CheckoutGatewayUnconfiguredTest extends TestCase
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

        // The state UAT was actually in: every P24 credential absent/empty.
        // phpunit.xml sets none of them either, but pinning it here means this
        // suite keeps testing the unconfigured case even if that changes.
        config([
            'przelewy24.merchant_id' => 0,
            'przelewy24.reports_key' => '',
            'przelewy24.crc' => '',
            'przelewy24.pos_id' => null,
        ]);
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

    private function enableOfflineSettlement(Organization $org): void
    {
        app('request')->attributes->set('tenant', $org);
        app(SettingsManager::class)->set('checkout.settlement_offline_enabled', true);
        app('request')->attributes->remove('tenant');
    }

    private function disableOfflineSettlement(Organization $org): void
    {
        app('request')->attributes->set('tenant', $org);
        app(SettingsManager::class)->set('checkout.settlement_offline_enabled', false);
        app('request')->attributes->remove('tenant');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $settlementMethod): array
    {
        return [
            'customer_type' => 'natural_person',
            'settlement_method' => $settlementMethod,
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
    // Online settlement + unconfigured gateway — graceful refusal, never a 500
    // -------------------------------------------------------------------------

    public function test_online_submit_with_empty_p24_config_is_a_redirect_not_a_500(): void
    {
        // Offline is enabled by default; this test isolates the gateway's own
        // compensation path, so it needs 'online' to actually be a valid
        // submission (i.e. offline explicitly off) rather than being rejected
        // by SubmitCheckoutRequest's Rule::in before the controller runs.
        $this->disableOfflineSettlement($this->org);
        Notification::fake();
        $this->cartWithItem();

        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $this->payload('online'));

        // The precise assertion the incident needs: a 500 here is the bug.
        $this->assertSame(302, $response->getStatusCode());
        $response->assertSessionHasErrors('general');
    }

    public function test_online_submit_with_empty_p24_config_cancels_the_orphaned_order(): void
    {
        // See test_online_submit_with_empty_p24_config_is_a_redirect_not_a_500 —
        // same reason for disabling offline explicitly.
        $this->disableOfflineSettlement($this->org);
        Notification::fake();
        $this->cartWithItem();

        $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $this->payload('online'));

        $order = Order::where('user_id', $this->user->id)->first();

        $this->assertNotNull($order, 'convertToOrder() ran, so an order must exist to compensate for');
        $this->assertSame('cancelled', $order->fresh()->status);
    }

    public function test_online_submit_with_empty_p24_config_restores_the_cart_with_items_intact(): void
    {
        // See test_online_submit_with_empty_p24_config_is_a_redirect_not_a_500 —
        // same reason for disabling offline explicitly.
        $this->disableOfflineSettlement($this->org);
        Notification::fake();
        $cart = $this->cartWithItem();

        $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $this->payload('online'));

        $cart->refresh();

        $this->assertSame('active', $cart->status);
        $this->assertSame(1, $cart->items()->count());
    }

    public function test_unconfigured_gateway_message_does_not_promise_a_pointless_retry(): void
    {
        // "Spróbuj ponownie" is right for a refused/timed-out gateway and a lie
        // for one with no credentials — retrying loops the customer through
        // order-create → cancel forever. Separate copy, asserted separately.
        //
        // Offline is enabled by default; disable it explicitly so 'online' is
        // still a valid submission and this reaches the controller's own
        // compensation path rather than Rule::in.
        $this->disableOfflineSettlement($this->org);
        Notification::fake();
        $this->cartWithItem();

        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $this->payload('online'));

        $message = session('errors')->first('general');

        $this->assertStringContainsString('Płatności online są chwilowo niedostępne', $message);
        $this->assertStringNotContainsString('Spróbuj ponownie', $message);
    }

    public function test_a_tenant_with_pay_at_pickup_refuses_an_online_submit_before_any_order_exists(): void
    {
        // The cheapest possible refusal, and the reason the settings layer
        // knows about the gateway at all: with pay-at-pickup available there is
        // no reason to let 'online' through to order-create → cancel at all.
        // A stale or tampered client posting 'online' is stopped by
        // SubmitCheckoutRequest's Rule::in, so no Order is ever written.
        Notification::fake();
        $this->enableOfflineSettlement($this->org);
        $cart = $this->cartWithItem();

        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $this->payload('online'));

        $this->assertSame(302, $response->getStatusCode());
        $response->assertSessionHasErrors('settlement_method');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame('active', $cart->fresh()->status);
    }

    // -------------------------------------------------------------------------
    // The catch must be \Throwable, independent of what makes it throw
    // -------------------------------------------------------------------------

    public function test_an_error_thrown_by_registration_is_compensated_exactly_like_an_exception(): void
    {
        // The regression in its purest form: \TypeError is an \Error. Against
        // `catch (\Exception)` this test 500s and leaves the order orphaned;
        // against `catch (\Throwable)` it compensates. No config involved.
        //
        // Offline is enabled by default; disable it explicitly so 'online'
        // still passes SubmitCheckoutRequest and reaches registerTransaction().
        $this->disableOfflineSettlement($this->org);
        Notification::fake();

        $this->mock(Przelewy24Service::class, function ($mock) {
            $mock->shouldReceive('registerTransaction')
                ->once()
                ->andThrow(new \TypeError('Argument #5 ($posId) must be of type ?string, int given'));
        });

        $cart = $this->cartWithItem();

        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $this->payload('online'));

        $this->assertSame(302, $response->getStatusCode());
        $response->assertSessionHasErrors('general');

        $order = Order::where('user_id', $this->user->id)->first();
        $this->assertNotNull($order);
        $this->assertSame('cancelled', $order->fresh()->status);

        $cart->refresh();
        $this->assertSame('active', $cart->status);
    }

    // -------------------------------------------------------------------------
    // Offline settlement — P24 is not involved at all
    // -------------------------------------------------------------------------

    public function test_offline_settlement_never_calls_przelewy24(): void
    {
        Notification::fake();
        $this->enableOfflineSettlement($this->org);

        $this->mock(Przelewy24Service::class, function ($mock) {
            $mock->shouldReceive('registerTransaction')->never();
            $mock->shouldReceive('handleWebhook')->never();
        });

        $this->cartWithItem();

        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $this->payload('offline'));

        $order = Order::where('user_id', $this->user->id)->firstOrFail();

        $response->assertRedirect(route('checkout.return', ['order' => $order->id]));
        $this->assertSame('offline', $order->settlement_method);
        $this->assertSame('pending_payment', $order->status);
        $this->assertNull($order->p24_session_id);
    }

    // -------------------------------------------------------------------------
    // Early detection — the broken option is not offered in the first place
    // -------------------------------------------------------------------------

    public function test_available_settlement_methods_drop_online_when_the_gateway_is_unconfigured(): void
    {
        $this->enableOfflineSettlement($this->org);

        app('request')->attributes->set('tenant', $this->org);
        $methods = app(SettingsManager::class)->availableSettlementMethods();
        app('request')->attributes->remove('tenant');

        $this->assertSame(['offline'], $methods);
    }

    public function test_available_settlement_methods_keep_online_as_the_last_resort_when_nothing_else_exists(): void
    {
        // Offline is enabled by default (SettingsManager::isOfflineSettlementEnabled()),
        // so this scenario requires the tenant to have explicitly turned it off too.
        // With BOTH unavailable, the list must still never be empty (an empty list
        // makes checkout impossible and breaks SubmitCheckoutRequest's Rule::in).
        // Online stays as the fallback, and submitting it is the graceful-refusal
        // path pinned above.
        $this->disableOfflineSettlement($this->org);

        app('request')->attributes->set('tenant', $this->org);
        $methods = app(SettingsManager::class)->availableSettlementMethods();
        app('request')->attributes->remove('tenant');

        $this->assertSame(['online'], $methods);
    }

    public function test_online_is_offered_again_once_the_gateway_has_credentials(): void
    {
        config([
            'przelewy24.merchant_id' => 12345,
            'przelewy24.reports_key' => 'reports-key',
            'przelewy24.crc' => 'crc-value',
        ]);

        $this->enableOfflineSettlement($this->org);

        app('request')->attributes->set('tenant', $this->org);
        $methods = app(SettingsManager::class)->availableSettlementMethods();
        app('request')->attributes->remove('tenant');

        $this->assertSame(['online', 'offline'], $methods);
    }

    // -------------------------------------------------------------------------
    // The service's own guard
    // -------------------------------------------------------------------------

    public function test_register_transaction_throws_the_typed_not_configured_exception(): void
    {
        $order = Order::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
        ]);

        $this->expectException(PaymentGatewayNotConfiguredException::class);

        app(Przelewy24Service::class)->registerTransaction($order);
    }

    public function test_missing_config_names_every_absent_credential(): void
    {
        $this->assertSame(
            ['P24_MERCHANT_ID', 'P24_CRC', 'P24_REPORTS_KEY'],
            Przelewy24Service::missingConfig()
        );

        config(['przelewy24.crc' => 'crc-value']);

        $this->assertSame(
            ['P24_MERCHANT_ID', 'P24_REPORTS_KEY'],
            Przelewy24Service::missingConfig()
        );
    }

    /**
     * pos_id is NOT part of "is it configured" — the SDK falls back to the
     * merchant id for it — so a gateway with every credential but no explicit
     * POS id must still be usable, and must still not hand the SDK an int.
     */
    public function test_a_configured_gateway_with_no_pos_id_is_usable(): void
    {
        config([
            'przelewy24.merchant_id' => 12345,
            'przelewy24.reports_key' => 'reports-key',
            'przelewy24.crc' => 'crc-value',
            'przelewy24.pos_id' => null,
        ]);

        $this->assertTrue(Przelewy24Service::isConfigured());
        $this->assertSame([], Przelewy24Service::missingConfig());
    }
}
