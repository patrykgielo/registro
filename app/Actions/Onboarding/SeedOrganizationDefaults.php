<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Models\Location;
use App\Models\Organization;
use App\Models\Setting;

class SeedOrganizationDefaults
{
    /**
     * Settings + industry feature defaults — ONLY for a genuinely new
     * organization. `seedSettings()` uses `updateOrCreate`, which is
     * idempotent in the sense that it never duplicates a row, but it is NOT
     * safe to re-run against an EXISTING, already-provisioned tenant: it
     * would silently reset any setting an admin has since customized
     * (business hours, VAT rate, inquiry e-mail, ...) back to the
     * hardcoded default. `ProvisionTenantOrganization` therefore only calls
     * this when `$orgWasCreated`. `ensurePrimaryLocation()` below is
     * DELIBERATELY separate and NOT called from here — see its own
     * docblock for why it must run unconditionally, including for a
     * re-run against an already-existing organization.
     */
    public function execute(Organization $org): void
    {
        $this->seedSettings($org);
        $this->seedIndustryFeatures($org);
    }

    /**
     * ClickUp 123k99cvc53: a tenant provisioned without a Location has
     * nothing for RouteQuantityFieldToPrimaryLocationStock to anchor stock
     * to — ServiceResource's "Ilość w magazynie" field silently disables
     * and un-dehydrates itself (tenantHasExactlyOneActiveLocation() === 0),
     * so every product an admin creates saves with quantity_total = NULL,
     * i.e. permanently unrentable, with no error anywhere.
     *
     * Name matches the Faza 1 backfill migration's own convention
     * (2026_08_27_120001_backfill_primary_location_for_organizations.php,
     * tryb-jednooddzialowy.md) rather than the organization's own name — a
     * tenant that later adds branches ("Warszawa", "Gdańsk") would otherwise
     * end up with its own brand name duplicated as a branch name.
     * LocationObserver::creating() sets primary_slot = 1 automatically: this
     * is unconditionally the org's first location (see the idempotency
     * guard below), so there is nothing to demote.
     *
     * Address/phone/email are left blank — at provisioning time this
     * organization has no `contact.*` settings yet for
     * SettingsManager::contactDetailsFor() to read (seedSettings() only
     * ever writes booking/auth/general/checkout groups); the admin fills the
     * address in from the Lokalizacje panel afterward, same as any tenant.
     *
     * **Code review 2026-09-19 — MUST be called unconditionally, not only
     * for `$orgWasCreated`.** The original version of this fix lived inside
     * `execute()`, which `ProvisionTenantOrganization` only calls for a
     * genuinely NEW organization — so re-running `registro:tenant-provision`
     * against an EXISTING org that already had zero locations (any tenant
     * provisioned between this ticket's own regression window: after the
     * one-time Faza 1 backfill migration, before this fix shipped) could
     * never be healed by re-running the command, which is exactly the
     * documented idempotent-repair path an operator would reach for. Public
     * and called SEPARATELY from `execute()` (see `ProvisionTenantOrganization::
     * execute()`) so it runs on EVERY invocation, new org or existing.
     *
     * Idempotent via its own `Location::exists()` check — checked over ALL
     * locations regardless of `is_active`, not just active ones: an org
     * that (somehow — LocationObserver::updating() blocks reaching zero
     * ACTIVE locations through the panel, but not through a direct DB
     * write) ended up with only inactive locations already has a location
     * ENTITY for an admin to manage/reactivate; silently creating an
     * additional active one alongside it would leave the org with two
     * locations instead of healing the one it has, and — worse — would
     * NOT actually be primary-eligible in a useful way if the pre-existing
     * inactive row already held `primary_slot = 1` (LocationObserver
     * would then have to demote it, an unasked-for side effect of a
     * provisioning re-run). Re-running against an org with any location —
     * active or not — is therefore always a true no-op here; reactivating a
     * stranded location is a separate, deliberate admin action.
     */
    public function ensurePrimaryLocation(Organization $org): void
    {
        $hasLocation = Location::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->exists();

        if ($hasLocation) {
            return;
        }

        Location::withoutGlobalScope('organization')->create([
            'organization_id' => $org->id,
            'name' => 'Siedziba główna',
            'is_active' => true,
        ]);
    }

    private function seedSettings(Organization $org): void
    {
        $defaults = [
            'booking' => [
                'booking_enabled' => $org->supportsAppointments(),
                'business_hours_start' => '09:00',
                'business_hours_end' => '18:00',
                'advance_booking_hours' => 24,
                'cancellation_hours' => 24,
                'slot_interval_minutes' => 30,
            ],
            'auth' => [
                'registration_enabled' => true,
            ],
            'general' => [
                'app_name' => $org->name,
                'vat_rate' => 23,
            ],
            'checkout' => [
                'inquiry_email' => '',
            ],
        ];

        foreach ($defaults as $group => $settings) {
            foreach ($settings as $key => $value) {
                Setting::withoutGlobalScope('organization')->updateOrCreate(
                    [
                        'organization_id' => $org->id,
                        'group' => $group,
                        'key' => $key,
                    ],
                    [
                        'value' => is_array($value) ? $value : [$value],
                    ]
                );
            }
        }
    }

    private function seedIndustryFeatures(Organization $org): void
    {
        if ($org->industry === null) {
            return;
        }

        $features = $org->industry->defaultFeatures();
        $settings = $org->settings ?? [];

        foreach ($features as $feature => $enabled) {
            if ($enabled) {
                data_set($settings, "features.{$feature}", true);
            }
        }

        if (! empty($settings)) {
            $org->update(['settings' => $settings]);
        }
    }
}
