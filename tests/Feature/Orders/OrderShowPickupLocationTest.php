<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Models\Order;
use App\Models\Organization;
use App\Models\User;
use App\Support\Settings\SettingsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A THIRD call site reading pickup/contact info the wrong way, found by code
 * review after OrderPaidNotification/OrderProtocolPdfService had already
 * been fixed on the same branch (feature/settings-store-disconnect,
 * 2026-08-14) — resources/views/orders/show.blade.php read
 * $order->organization->settings (the JSON column, never populated with
 * contact.*) directly in a @php block. $hasPickupInfo was therefore always
 * false: the "Miejsce odbioru sprzętu" section has never rendered for any
 * tenant, on the customer's own order page, since it was written. Moved the
 * extraction into OrderController::show() (own pickupDetails(), same
 * convention as OrderProtocolPdfService/OrderPaidNotification — see
 * order-notifications.md) reading via SettingsManager::getForOrganization().
 */
class OrderShowPickupLocationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Bind a test double for ResolveTenant — same pattern used throughout the project.
     */
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
     * Writes settings through SettingsManager::set() — the exact call
     * SystemSettings' Contact tab makes — while impersonating the given tenant.
     *
     * @param  array<string, string>  $values
     */
    private function setTenantContactSettings(Organization $org, array $values): void
    {
        app('request')->attributes->set('tenant', $org);

        $settings = app(SettingsManager::class);
        foreach ($values as $key => $value) {
            $settings->set("contact.{$key}", $value);
        }

        app('request')->attributes->remove('tenant');
    }

    public function test_customer_order_page_shows_the_tenants_pickup_address(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $this->setTenantContactSettings($org, [
            'address_line' => 'ul. Klientowska 8',
            'postal_code' => '50-000',
            'city' => 'Wrocław',
            'phone' => '+48700800900',
        ]);

        $user = User::factory()->create();
        $order = Order::factory()->paid()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        $response = $this->actingAs($user)
            ->actingAsTenant($org)
            ->get(route('orders.show', $order));

        $response->assertOk();
        $response->assertSee('Miejsce odbioru sprzętu');
        $response->assertSee('ul. Klientowska 8');
        $response->assertSee('50-000 Wrocław');
        $response->assertSee('+48700800900');
    }

    public function test_customer_order_page_hides_pickup_section_when_no_contact_settings_exist(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $user = User::factory()->create();
        $order = Order::factory()->paid()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        $response = $this->actingAs($user)
            ->actingAsTenant($org)
            ->get(route('orders.show', $order));

        $response->assertOk();
        $response->assertDontSee('Miejsce odbioru sprzętu');
    }

    public function test_customer_order_page_shows_a_tenant_scoped_override_not_the_global_default(): void
    {
        $org = Organization::factory()->equipmentRental()->create();

        \App\Models\Setting::withoutGlobalScope('organization')->create([
            'organization_id' => null,
            'group' => 'contact',
            'key' => 'address_line',
            'value' => ['Adres globalny 1'],
        ]);

        $this->setTenantContactSettings($org, ['address_line' => 'ul. Tenantowa 9']);

        $user = User::factory()->create();
        $order = Order::factory()->paid()->create([
            'user_id' => $user->id,
            'organization_id' => $org->id,
        ]);

        $response = $this->actingAs($user)
            ->actingAsTenant($org)
            ->get(route('orders.show', $order));

        $response->assertOk();
        $response->assertSee('ul. Tenantowa 9');
        $response->assertDontSee('Adres globalny 1');
    }
}
