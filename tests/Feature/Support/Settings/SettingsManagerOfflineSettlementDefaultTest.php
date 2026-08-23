<?php

declare(strict_types=1);

namespace Tests\Feature\Support\Settings;

use App\Models\Organization;
use App\Support\Settings\SettingsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards the code-level default from app/docs/features/payment-settlement-modes.md:
 * isOfflineSettlementEnabled() defaults to true, unconditionally — including for
 * organizations that were never provisioned through SeedOrganizationDefaults (there
 * is no seeder step involved at all here; the row simply never exists and the
 * SettingsManager::get() fallback carries the whole guarantee).
 *
 * Without this, isOnlineSettlementEnabled() is false on any machine with no P24
 * credentials, and availableSettlementMethods()'s fail-safe still falls back to
 * ['online'] — a method the customer cannot actually complete. Asserts the
 * customer-visible effect (availableSettlementMethods()), not the raw setting.
 *
 * Lives in Feature/, not Unit/: it uses RefreshDatabase and Organization::factory(),
 * so it genuinely hits the database — tests.md forbids DB access from Unit tests.
 *
 * For the companion regression that this fix silently reverses on save (SystemSettings'
 * Toggle field default disagreeing with this one), see
 * SystemSettingsCheckoutOfflineDefaultTest in tests/Feature/Filament/.
 */
class SettingsManagerOfflineSettlementDefaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_without_any_checkout_settings_offers_offline_settlement_without_p24_configured(): void
    {
        config([
            'przelewy24.merchant_id' => null,
            'przelewy24.reports_key' => null,
            'przelewy24.crc' => null,
        ]);

        $org = Organization::factory()->equipmentRental()->create();

        app('request')->attributes->set('tenant', $org);

        $methods = app(SettingsManager::class)->availableSettlementMethods();

        $this->assertSame(['offline'], $methods);
    }

    public function test_organization_without_any_checkout_settings_offers_offline_settlement_for_time_slot_bookings_too(): void
    {
        config([
            'przelewy24.merchant_id' => null,
            'przelewy24.reports_key' => null,
            'przelewy24.crc' => null,
        ]);

        $org = Organization::factory()->autoDetailing()->create();

        app('request')->attributes->set('tenant', $org);

        $methods = app(SettingsManager::class)->availableSettlementMethods();

        $this->assertSame(['offline'], $methods);
    }
}
