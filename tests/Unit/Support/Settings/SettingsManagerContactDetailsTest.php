<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Settings;

use App\Models\Organization;
use App\Support\Settings\SettingsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SettingsManager::contactDetailsFor() — the single canonical accessor introduced
 * (2026-08-14, feature/settings-store-disconnect, code review round 3) to replace
 * three independent hand-rolled copies of this same five-key lookup across
 * OrderPaidNotification, OrderProtocolPdfService and OrderController. Two of those
 * three copies had independently reached for the WRONG store
 * ($organization->settings, the JSON column) — this accessor exists so the store
 * decision is made once, correctly, in one place, rather than being a convention
 * each new caller has to remember. See tenant-branding.md's "two settings stores"
 * section for the full incident.
 */
class SettingsManagerContactDetailsTest extends TestCase
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

    public function test_returns_all_five_keys_from_the_tenants_own_row(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $this->setTenantContactSettings($org, [
            'address_line' => 'ul. Testowa 5',
            'postal_code' => '00-100',
            'city' => 'Warszawa',
            'phone' => '+48123123123',
            'email' => 'kontakt@example.test',
        ]);

        $contact = app(SettingsManager::class)->contactDetailsFor($org);

        $this->assertSame([
            'address_line' => 'ul. Testowa 5',
            'postal_code' => '00-100',
            'city' => 'Warszawa',
            'phone' => '+48123123123',
            'email' => 'kontakt@example.test',
        ], $contact);
    }

    public function test_returns_empty_strings_not_null_when_nothing_is_configured(): void
    {
        $org = Organization::factory()->equipmentRental()->create();

        $contact = app(SettingsManager::class)->contactDetailsFor($org);

        $this->assertSame(['address_line' => '', 'postal_code' => '', 'city' => '', 'phone' => '', 'email' => ''], $contact);
    }

    public function test_null_organization_returns_empty_strings_without_throwing(): void
    {
        $contact = app(SettingsManager::class)->contactDetailsFor(null);

        $this->assertSame(['address_line' => '', 'postal_code' => '', 'city' => '', 'phone' => '', 'email' => ''], $contact);
    }

    public function test_reads_via_the_settings_table_never_the_organizations_json_column(): void
    {
        $org = Organization::factory()->equipmentRental()->create([
            // Deliberately poison the JSON column with the shape the bug used to read —
            // contactDetailsFor() must never surface this.
            'settings' => ['contact' => ['address_line' => 'JSON COLUMN — WRONG STORE']],
        ]);
        $this->setTenantContactSettings($org, ['address_line' => 'ul. Prawidlowa 1']);

        $contact = app(SettingsManager::class)->contactDetailsFor($org);

        $this->assertSame('ul. Prawidlowa 1', $contact['address_line']);
    }
}
