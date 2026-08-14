<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Settings;

use App\Models\Organization;
use App\Support\Settings\SettingsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * getForOrganization() caches under a TENANT-scoped key even when the value
 * returned is an INHERITED global default (no row of the tenant's own
 * exists). clearCache() — the invalidation `set()` runs — only ever clears
 * the key format `settings:tenant:{tenantId}:...`; setGlobal()'s own
 * invalidation only clears `settings:tenant:global:...`. Neither one clears
 * the tenant-scoped cache entry holding the now-stale INHERITED value, so an
 * operator who corrects an address at the platform-global level and then
 * generates a customer-facing document (order-paid email, handover/return
 * protocol) within the cache TTL gets the OLD address — until the 3600s TTL
 * expires on its own.
 *
 * Found during review of feature/settings-store-disconnect (2026-08-14),
 * pre-existing in SettingsManager, not introduced by that branch — but that
 * branch is what puts this path on the route for a signed legal document,
 * so it is fixed here. See tenant-branding.md's "two settings stores"
 * section for the full narrative.
 */
class SettingsManagerGlobalInvalidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_tenants_inherited_value_is_fresh_after_a_global_write(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $settings = app(SettingsManager::class);

        $settings->setGlobal('contact.address_line', 'ul. Stara 1');

        // No row of the tenant's own — this reads (and, pre-fix, caches) the
        // inherited global value under the TENANT's cache key.
        $this->assertSame('ul. Stara 1', $settings->getForOrganization('contact.address_line', $org));

        $settings->setGlobal('contact.address_line', 'ul. Nowa 99');

        $this->assertSame(
            'ul. Nowa 99',
            $settings->getForOrganization('contact.address_line', $org),
            'a global-level correction must be visible to a tenant that inherits it, not just after the cache TTL expires'
        );
    }

    /**
     * Regression guard for the fix itself: a tenant with NO row of its own
     * must not spuriously start seeing a value cached for a DIFFERENT
     * tenant that also has no row of its own.
     */
    public function test_two_tenants_without_their_own_row_both_see_the_fresh_global_value(): void
    {
        $orgA = Organization::factory()->equipmentRental()->create();
        $orgB = Organization::factory()->equipmentRental()->create();
        $settings = app(SettingsManager::class);

        $settings->setGlobal('contact.phone', '+48111111111');

        $this->assertSame('+48111111111', $settings->getForOrganization('contact.phone', $orgA));
        $this->assertSame('+48111111111', $settings->getForOrganization('contact.phone', $orgB));

        $settings->setGlobal('contact.phone', '+48222222222');

        $this->assertSame('+48222222222', $settings->getForOrganization('contact.phone', $orgA));
        $this->assertSame('+48222222222', $settings->getForOrganization('contact.phone', $orgB));
    }

    /**
     * Normal case, unaffected by the fix: a tenant with its OWN row is
     * invalidated correctly by set() today — pins that the restructuring
     * needed for the fix above does not regress this.
     */
    public function test_a_tenants_own_row_is_still_invalidated_by_set(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        app('request')->attributes->set('tenant', $org);
        $settings = app(SettingsManager::class);

        $settings->set('contact.city', 'Kraków');
        $this->assertSame('Kraków', $settings->getForOrganization('contact.city', $org));

        $settings->set('contact.city', 'Poznań');
        $this->assertSame('Poznań', $settings->getForOrganization('contact.city', $org));

        app('request')->attributes->remove('tenant');
    }

    /**
     * A genuine tenant-specific override must still win over the global
     * value, even after the global value has changed since the tenant row
     * was cached.
     */
    public function test_tenant_override_still_wins_after_a_later_global_write(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $settings = app(SettingsManager::class);

        app('request')->attributes->set('tenant', $org);
        $settings->set('contact.email', 'tenant@example.test');
        app('request')->attributes->remove('tenant');

        $settings->setGlobal('contact.email', 'global@example.test');

        $this->assertSame('tenant@example.test', $settings->getForOrganization('contact.email', $org));
    }
}
