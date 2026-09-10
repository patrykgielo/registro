<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\Locations\Pages\EditLocation;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use App\Services\Payment\Przelewy24Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Faza 6 code review (2026-09-10) — reproduces the FULL sequence the audit
 * flagged, not just the guard's own condition in isolation:
 *
 *   1. tenant has one active location, customer already has a cart pointed
 *      at it (CartService::getOrCreateCart() stamped it automatically)
 *   2. admin tries to deactivate that location in the Filament panel
 *   3. customer attempts checkout
 *
 * Before LocationObserver's deactivation guard (this same review round),
 * step 2 would have succeeded silently, and step 3 would have hit a genuine
 * dead end: SubmitCheckoutRequest's `pickup_location_id` validation rejects
 * (Rule::exists(...)->where('is_active', true)), but LocationContext::
 * selectionRequired() is false at zero active locations, so the header
 * switcher does not even render — no way for the customer to recover. This
 * test proves step 2 itself is now rejected, so step 3 never has anything
 * to recover FROM.
 */
class LocationDeactivationCheckoutGuardTest extends TestCase
{
    use RefreshDatabase;

    private Organization $tenant;

    private User $admin;

    private User $customer;

    private Location $location;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([ThrottleRequests::class]);

        config([
            'przelewy24.merchant_id' => 12345,
            'przelewy24.reports_key' => 'reports-key',
            'przelewy24.crc' => 'crc-value',
        ]);

        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $this->tenant = Organization::factory()->equipmentRental()->create();
        $this->location = Location::factory()->for($this->tenant, 'organization')->create(['is_active' => true]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->admin->organizations()->attach($this->tenant->id, ['role' => 'admin']);

        $this->customer = User::factory()->create();
    }

    private function actingAsStorefrontTenant(Organization $org): static
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

    public function test_full_sequence_admin_cannot_strand_a_customers_existing_cart_by_deactivating_the_only_location(): void
    {
        // Step 1: customer already has a cart pointed at the tenant's only
        // location — the normal outcome of CartService::getOrCreateCart()
        // stamping it automatically for a single-location tenant.
        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->tenant->id,
            'quantity_total' => 5,
        ]);
        $cart = Cart::factory()->active()->create([
            'user_id' => $this->customer->id,
            'organization_id' => $this->tenant->id,
            'location_id' => $this->location->id,
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

        // Step 2: admin tries to deactivate the tenant's only location.
        session(['tenant_id' => $this->tenant->id]);
        $this->actingAs($this->admin);

        Livewire::test(EditLocation::class, ['record' => $this->location->slug])
            ->fillForm(['is_active' => false])
            ->call('save');

        $this->assertTrue(
            $this->location->fresh()->is_active,
            'Deactivation must have been rejected — the location is still active.'
        );

        // Step 3: customer completes checkout normally — nothing to recover
        // from, because step 2 never actually happened.
        $this->mock(Przelewy24Service::class, function ($mock) {
            $mock->shouldReceive('registerTransaction')
                ->once()
                ->andReturn('https://sandbox.przelewy24.pl/trnRequest/fake');
        });

        $response = $this->actingAs($this->customer)
            ->actingAsStorefrontTenant($this->tenant)
            ->post(route('checkout.submit'), $this->validCheckoutPayload());

        $response->assertRedirect('https://sandbox.przelewy24.pl/trnRequest/fake');
        $this->assertDatabaseHas('orders', [
            'user_id' => $this->customer->id,
            'pickup_location_id' => $this->location->id,
        ]);
    }
}
