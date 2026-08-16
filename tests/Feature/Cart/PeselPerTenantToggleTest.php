<?php

declare(strict_types=1);

namespace Tests\Feature\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use App\Support\Settings\SettingsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * checkout.pesel_required (SettingsManager::isPeselRequired(), default false) —
 * PESEL requirement for natural-person customers is a per-tenant opt-in, not a
 * hardcoded validation rule. See SubmitCheckoutRequest::rules() and
 * checkout/show.blade.php's `$peselRequired`-driven asterisk/aria-required/hint.
 */
class PeselPerTenantToggleTest extends TestCase
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
     * Writes checkout.pesel_required through SettingsManager::set() — the exact
     * call SystemSettings' Checkout tab makes — while impersonating the given
     * tenant, matching the pattern in OfflineSettlementCheckoutTest.
     */
    private function setPeselRequired(Organization $org, bool $required): void
    {
        app('request')->attributes->set('tenant', $org);

        app(SettingsManager::class)->set('checkout.pesel_required', $required);

        app('request')->attributes->remove('tenant');
    }

    /**
     * @return array<string, mixed>
     */
    private function validCheckoutPayload(): array
    {
        return [
            'customer_type' => 'natural_person',
            'settlement_method' => 'online',
            'customer_first_name' => 'Jan',
            'customer_last_name' => 'Kowalski',
            'customer_email' => 'jan.kowalski@test.pl',
            'customer_phone' => '500100200',
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

    private function cartWithItem(User $user, Organization $org, Service $service): Cart
    {
        $cart = Cart::factory()->active()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
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

    private function mockSuccessfulP24(): void
    {
        $this->mock(\App\Services\Payment\Przelewy24Service::class, function ($mock) {
            $mock->shouldReceive('registerTransaction')
                ->andReturn('https://sandbox.przelewy24.pl/trnRequest/fake-token');
        });
    }

    // -------------------------------------------------------------------------
    // Default: PESEL optional
    // -------------------------------------------------------------------------

    public function test_default_setting_is_pesel_not_required(): void
    {
        app('request')->attributes->set('tenant', $this->org);

        $this->assertFalse(app(SettingsManager::class)->isPeselRequired());

        app('request')->attributes->remove('tenant');
    }

    public function test_checkout_succeeds_without_pesel_when_setting_disabled(): void
    {
        $this->mockSuccessfulP24();

        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 5,
        ]);
        $this->cartWithItem($this->user, $this->org, $service);

        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $this->validCheckoutPayload());

        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('orders', [
            'user_id' => $this->user->id,
            'customer_pesel' => null,
        ]);
    }

    public function test_checkout_succeeds_with_optional_but_valid_pesel_when_setting_disabled(): void
    {
        $this->mockSuccessfulP24();

        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 5,
        ]);
        $this->cartWithItem($this->user, $this->org, $service);

        $payload = array_merge($this->validCheckoutPayload(), ['customer_pesel' => '44051401458']);

        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $payload);

        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('orders', [
            'user_id' => $this->user->id,
            'customer_pesel' => '44051401458',
        ]);
    }

    public function test_invalid_pesel_checksum_still_rejected_when_setting_disabled(): void
    {
        // Optional does not mean "unvalidated" — data minimization is about
        // whether the field is mandatory, not about accepting garbage.
        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 5,
        ]);
        $this->cartWithItem($this->user, $this->org, $service);

        $payload = array_merge($this->validCheckoutPayload(), ['customer_pesel' => '11111111111']);

        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $payload);

        $response->assertSessionHasErrors('customer_pesel');
        $this->assertDatabaseCount('orders', 0);
    }

    // -------------------------------------------------------------------------
    // Enabled: PESEL required for natural-person customers
    // -------------------------------------------------------------------------

    public function test_checkout_fails_without_pesel_when_setting_enabled(): void
    {
        $this->setPeselRequired($this->org, true);

        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 5,
        ]);
        $this->cartWithItem($this->user, $this->org, $service);

        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $this->validCheckoutPayload());

        $response->assertSessionHasErrors('customer_pesel');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_checkout_succeeds_with_valid_pesel_when_setting_enabled(): void
    {
        $this->setPeselRequired($this->org, true);
        $this->mockSuccessfulP24();

        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 5,
        ]);
        $this->cartWithItem($this->user, $this->org, $service);

        $payload = array_merge($this->validCheckoutPayload(), ['customer_pesel' => '44051401458']);

        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $payload);

        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('orders', [
            'user_id' => $this->user->id,
            'customer_pesel' => '44051401458',
        ]);
    }

    public function test_invalid_pesel_checksum_rejected_when_setting_enabled(): void
    {
        $this->setPeselRequired($this->org, true);

        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 5,
        ]);
        $this->cartWithItem($this->user, $this->org, $service);

        $payload = array_merge($this->validCheckoutPayload(), ['customer_pesel' => '11111111111']);

        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $payload);

        $response->assertSessionHasErrors('customer_pesel');
        $this->assertDatabaseCount('orders', 0);
    }

    // -------------------------------------------------------------------------
    // Per-tenant, not global — the most important test here (a settings write
    // that silently lands on organization_id=NULL would leak the requirement
    // to every other tenant).
    // -------------------------------------------------------------------------

    public function test_setting_is_scoped_per_tenant_not_global(): void
    {
        $otherOrg = Organization::factory()->equipmentRental()->create();
        $otherUser = User::factory()->create();

        $this->setPeselRequired($this->org, true);

        // The OTHER tenant, which never touched the setting, must still be optional.
        app('request')->attributes->set('tenant', $otherOrg);
        $this->assertFalse(app(SettingsManager::class)->isPeselRequired());
        app('request')->attributes->remove('tenant');

        $this->mockSuccessfulP24();

        $service = Service::factory()->itemRental()->create([
            'organization_id' => $otherOrg->id,
            'quantity_total' => 5,
        ]);
        $this->cartWithItem($otherUser, $otherOrg, $service);

        $response = $this->actingAs($otherUser)
            ->actingAsTenant($otherOrg)
            ->post(route('checkout.submit'), $this->validCheckoutPayload());

        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('orders', [
            'user_id' => $otherUser->id,
            'organization_id' => $otherOrg->id,
            'customer_pesel' => null,
        ]);

        // And the ORIGINAL tenant, which enabled it, must still enforce it.
        $service2 = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 5,
        ]);
        $this->cartWithItem($this->user, $this->org, $service2);

        $response2 = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $this->validCheckoutPayload());

        $response2->assertSessionHasErrors('customer_pesel');
    }

    // -------------------------------------------------------------------------
    // Business customers are never subject to this toggle — PESEL is a
    // natural-person field regardless of the setting.
    // -------------------------------------------------------------------------

    public function test_business_customer_never_requires_pesel_even_when_setting_enabled(): void
    {
        $this->setPeselRequired($this->org, true);
        $this->mockSuccessfulP24();

        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 5,
        ]);
        $this->cartWithItem($this->user, $this->org, $service);

        // customer_first_name/last_name/street/etc. are deliberately kept from
        // validCheckoutPayload() (not unset) — the orders table requires them
        // regardless of customer_type, a pre-existing constraint unrelated to
        // this feature. Only customer_pesel is dropped, plus the business-only
        // fields SubmitCheckoutRequest requires_if:customer_type,business.
        $payload = array_merge($this->validCheckoutPayload(), [
            'customer_type' => 'business',
            'customer_pesel' => null,
            'invoice_company_name' => 'Test Sp. z o.o.',
            'invoice_nip' => '7751001452',
            'company_regon' => '012100784',
            'company_contact_name' => 'Jan Kowalski',
            'signatory_id_number' => 'ABC123456',
            'invoice_street' => 'Marszałkowska',
            'invoice_street_number' => '1',
            'invoice_postal_code' => '00-001',
            'invoice_city' => 'Warszawa',
        ]);

        $response = $this->actingAs($this->user)
            ->actingAsTenant($this->org)
            ->post(route('checkout.submit'), $payload);

        $response->assertSessionDoesntHaveErrors();
    }
}
