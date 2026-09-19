<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Settings;

use App\Models\Location;
use App\Models\Order;
use App\Models\Organization;
use App\Support\Settings\SettingsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SettingsManager::pickupDetailsFor() — Faza 6 krok 6.5 (plan-wdrozenia.md,
 * ClickUp 86cbahqhb). The single resolver deciding whether a customer-facing
 * document/page shows the order's own checkout-time pickup-location snapshot
 * or falls back to contactDetailsFor() — used by the customer's own order
 * page, the protocol PDFs' new branch block, and the order-paid/
 * accepted-offline emails.
 */
class SettingsManagerPickupDetailsTest extends TestCase
{
    use RefreshDatabase;

    private function setTenantContactSettings(Organization $org, array $values): void
    {
        app('request')->attributes->set('tenant', $org);

        $settings = app(SettingsManager::class);
        foreach ($values as $key => $value) {
            $settings->set("contact.{$key}", $value);
        }

        app('request')->attributes->remove('tenant');
    }

    /**
     * The `qatest`-shaped case measured on dev: Settings address is empty,
     * but the order carries a real branch snapshot — falsifies the naive
     * "just call contactDetailsFor()" implementation, which would return
     * all-empty strings here.
     */
    public function test_snapshot_wins_over_empty_settings_address(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        // Settings deliberately left untouched — matches the qatest tenant's
        // real state: NULL address in settings.
        $location = Location::factory()->for($org)->create([
            'name' => 'Oddział Gdańsk',
            'street' => 'ul. Portowa 8',
            'building' => null,
            'postal_code' => '80-001',
            'city' => 'Gdańsk',
        ]);
        $order = Order::factory()->create([
            'organization_id' => $org->id,
            'pickup_location_id' => $location->id,
            'pickup_location_name' => $location->name,
            'pickup_location_address' => $location->formattedAddress(),
        ]);

        $pickup = app(SettingsManager::class)->pickupDetailsFor($order);

        $this->assertSame('Oddział Gdańsk', $pickup['location_name']);
        $this->assertSame('ul. Portowa 8, 80-001 Gdańsk', $pickup['address_line']);
        $this->assertSame('', $pickup['postal_code']);
        $this->assertSame('', $pickup['city']);
    }

    public function test_snapshot_address_wins_even_when_settings_address_is_also_configured(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $this->setTenantContactSettings($org, [
            'address_line' => 'ul. Siedziba 1',
            'postal_code' => '00-000',
            'city' => 'Warszawa',
        ]);
        $location = Location::factory()->for($org)->create([
            'name' => 'Oddział Kraków',
            'street' => 'ul. Wawelska 2',
            'postal_code' => '30-000',
            'city' => 'Kraków',
        ]);
        $order = Order::factory()->create([
            'organization_id' => $org->id,
            'pickup_location_id' => $location->id,
            'pickup_location_name' => $location->name,
            'pickup_location_address' => $location->formattedAddress(),
        ]);

        $pickup = app(SettingsManager::class)->pickupDetailsFor($order);

        $this->assertSame('ul. Wawelska 2, 30-000 Kraków', $pickup['address_line']);
        $this->assertStringNotContainsString('Siedziba', $pickup['address_line']);
    }

    /**
     * Fallback — no snapshot on the order (legacy order, or a tenant that
     * never had locations at checkout time) must behave EXACTLY like
     * contactDetailsFor() today. This is the falsifiable "zero regression"
     * claim: reverting pickupDetailsFor() to always read the snapshot would
     * turn this red (location_name would stay present via a stale read, or
     * address_line would come back empty instead of the settings value).
     */
    public function test_falls_back_to_settings_contact_details_when_no_snapshot_exists(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $this->setTenantContactSettings($org, [
            'address_line' => 'ul. Testowa 5',
            'postal_code' => '00-100',
            'city' => 'Warszawa',
            'phone' => '+48123123123',
            'email' => 'kontakt@example.test',
        ]);
        $order = Order::factory()->create([
            'organization_id' => $org->id,
            'pickup_location_id' => null,
            'pickup_location_name' => null,
            'pickup_location_address' => null,
        ]);

        $pickup = app(SettingsManager::class)->pickupDetailsFor($order);

        $this->assertSame([
            'address_line' => 'ul. Testowa 5',
            'postal_code' => '00-100',
            'city' => 'Warszawa',
            'phone' => '+48123123123',
            'email' => 'kontakt@example.test',
            'location_name' => null,
        ], $pickup);
    }

    /**
     * Company phone/email stay company-sourced even when a branch snapshot
     * exists — the snapshot never captured phone/email (migration docblock),
     * and the design explicitly keeps identity fields company-only.
     */
    public function test_phone_and_email_stay_from_settings_even_with_a_branch_snapshot(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $this->setTenantContactSettings($org, [
            'phone' => '+48000111222',
            'email' => 'biuro@example.test',
        ]);
        $location = Location::factory()->for($org)->create(['name' => 'Oddział Łódź']);
        $order = Order::factory()->create([
            'organization_id' => $org->id,
            'pickup_location_id' => $location->id,
            'pickup_location_name' => $location->name,
            'pickup_location_address' => $location->formattedAddress(),
        ]);

        $pickup = app(SettingsManager::class)->pickupDetailsFor($order);

        $this->assertSame('+48000111222', $pickup['phone']);
        $this->assertSame('biuro@example.test', $pickup['email']);
    }
}
