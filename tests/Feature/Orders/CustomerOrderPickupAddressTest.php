<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Models\Location;
use App\Models\Order;
use App\Models\Organization;
use App\Models\User;
use App\Support\Settings\SettingsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Faza 6 krok 6.5 (plan-wdrozenia.md, ClickUp 86cbahqhb) — OrderController::show()'s
 * "Miejsce odbioru sprzętu" section now prefers the order's own checkout-time
 * pickup-location snapshot over the tenant's Settings contact address, via
 * SettingsManager::pickupDetailsFor(). Covers the customer-facing order page,
 * one of the three surfaces named in the task alongside the protocol PDFs and
 * order emails.
 */
class CustomerOrderPickupAddressTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->equipmentRental()->create();
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

    public function test_order_page_shows_the_branch_address_and_name_when_a_snapshot_exists(): void
    {
        $user = User::factory()->create();
        $location = Location::factory()->for($this->org)->create([
            'name' => 'Oddział Gdańsk',
            'street' => 'ul. Portowa 8',
            'building' => null,
            'postal_code' => '80-001',
            'city' => 'Gdańsk',
        ]);
        $order = Order::factory()->pendingPayment()->create([
            'user_id' => $user->id,
            'organization_id' => $this->org->id,
            'pickup_location_id' => $location->id,
            'pickup_location_name' => $location->name,
            'pickup_location_address' => $location->formattedAddress(),
        ]);

        $response = $this->actingAs($user)
            ->actingAsTenant($this->org)
            ->get(route('orders.show', $order));

        $response->assertOk();
        $response->assertSee('Oddział Gdańsk');
        $response->assertSee('ul. Portowa 8, 80-001 Gdańsk');
    }

    /**
     * The `qatest`-shaped case: Settings address is empty, but the order
     * carries a real branch snapshot — the section must still render with
     * the branch address, not disappear (contrast with
     * test_order_page_hides_the_section_entirely_without_any_pickup_info
     * below, where NEITHER source has anything).
     */
    public function test_order_page_shows_branch_address_even_when_settings_address_is_empty(): void
    {
        $user = User::factory()->create();
        // Deliberately no contact.* settings written for $this->org.
        $location = Location::factory()->for($this->org)->create([
            'name' => 'Oddział Wrocław',
            'street' => 'ul. Krucza 1',
            'postal_code' => '50-001',
            'city' => 'Wrocław',
        ]);
        $order = Order::factory()->pendingPayment()->create([
            'user_id' => $user->id,
            'organization_id' => $this->org->id,
            'pickup_location_id' => $location->id,
            'pickup_location_name' => $location->name,
            'pickup_location_address' => $location->formattedAddress(),
        ]);

        $response = $this->actingAs($user)
            ->actingAsTenant($this->org)
            ->get(route('orders.show', $order));

        $response->assertOk();
        $response->assertSee('Oddział Wrocław');
        $response->assertSee('ul. Krucza 1, 50-001 Wrocław');
    }

    /**
     * Fallback — an order without a snapshot (legacy order, or a tenant
     * without locations) must render EXACTLY today's behaviour: the
     * Settings contact address, no branch name line at all.
     */
    public function test_order_page_falls_back_to_settings_address_without_a_snapshot(): void
    {
        $user = User::factory()->create();
        app('request')->attributes->set('tenant', $this->org);
        app(SettingsManager::class)->set('contact.address_line', 'ul. Testowa 5');
        app(SettingsManager::class)->set('contact.postal_code', '00-100');
        app(SettingsManager::class)->set('contact.city', 'Warszawa');
        app('request')->attributes->remove('tenant');

        $order = Order::factory()->pendingPayment()->create([
            'user_id' => $user->id,
            'organization_id' => $this->org->id,
            'pickup_location_id' => null,
            'pickup_location_name' => null,
            'pickup_location_address' => null,
        ]);

        $response = $this->actingAs($user)
            ->actingAsTenant($this->org)
            ->get(route('orders.show', $order));

        $response->assertOk();
        $response->assertSee('ul. Testowa 5');
        $response->assertSee('Warszawa');
        $response->assertDontSee('Oddział');
    }

    public function test_order_page_hides_the_section_entirely_without_any_pickup_info(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->pendingPayment()->create([
            'user_id' => $user->id,
            'organization_id' => $this->org->id,
            'pickup_location_id' => null,
            'pickup_location_name' => null,
            'pickup_location_address' => null,
        ]);

        $response = $this->actingAs($user)
            ->actingAsTenant($this->org)
            ->get(route('orders.show', $order));

        $response->assertOk();
        $response->assertDontSee('Miejsce odbioru sprzętu');
    }

    /**
     * Code review (2026-09-19): `$hasPickupInfo` used to check only
     * address/city/phone, ignoring `$pickupLocationName` — a branch with a
     * name but an empty formatted address (all of Location's street/
     * building/postal_code/city blank, all nullable columns) on a tenant
     * with no company phone configured in Settings would hide the WHOLE
     * section, silently dropping the one piece of information that did
     * exist. Fixed by including `$pickupLocationName` in the condition.
     */
    public function test_order_page_shows_the_section_when_only_the_branch_name_is_known(): void
    {
        $user = User::factory()->create();
        // Deliberately no contact.* settings (no company phone/email either).
        $location = Location::factory()->for($this->org)->create([
            'name' => 'Oddział Bez Adresu',
            'street' => null,
            'building' => null,
            'postal_code' => null,
            'city' => null,
        ]);
        $order = Order::factory()->pendingPayment()->create([
            'user_id' => $user->id,
            'organization_id' => $this->org->id,
            'pickup_location_id' => $location->id,
            'pickup_location_name' => $location->name,
            'pickup_location_address' => $location->formattedAddress(),
        ]);

        $this->assertSame('', $location->formattedAddress());

        $response = $this->actingAs($user)
            ->actingAsTenant($this->org)
            ->get(route('orders.show', $order));

        $response->assertOk();
        $response->assertSee('Miejsce odbioru sprzętu');
        $response->assertSee('Oddział Bez Adresu');
    }
}
