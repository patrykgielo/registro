<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Enums\ServiceType;
use App\Models\Location;
use App\Models\Service;
use App\Models\ServiceLocationStock;
use Illuminate\Support\Facades\DB;

/**
 * tryb-jednooddzialowy.md: ServiceResource's "Ilość w magazynie" field stays
 * editable — a tenant with exactly one active location keeps typing a plain
 * number, unaware service_location_stocks exists at all. This is where that
 * value gets routed, into the organization's primary location's stock row.
 *
 * For a tenant with a DIFFERENT number of active locations (zero — should
 * not happen after Faza 1's backfill, or more than one) ServiceResource's
 * form disables and un-dehydrates the field using the exact same
 * eligibility check as handle() below (tenantHasExactlyOneActiveLocation()),
 * so this is never reached for them with a meaningful value to route — see
 * ServiceResource.php's own docblock for why calling it unconditionally
 * would be destructive for a multi-location tenant (it would silently
 * overwrite a deliberately per-location split with quantity_total, the
 * aggregate across ALL locations). Their per-location quantities are
 * entered exclusively through LocationStocksRelationManager instead, which
 * calls Service::recalculateQuantityTotal() directly on its own inline edit.
 */
final class RouteQuantityFieldToPrimaryLocationStock
{
    public static function tenantHasExactlyOneActiveLocation(?int $organizationId): bool
    {
        if (! $organizationId) {
            return false;
        }

        return Location::withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->count() === 1;
    }

    /**
     * Single source of truth for "does this field get to write to
     * quantity_total directly" — shared by ServiceResource's form
     * (tenantEligibleForDirectQuantityField()) AND handle() below. Both
     * MUST agree, or the field could stay enabled while handle() silently
     * refuses to route its value (or vice versa) — see handle()'s own
     * comment on the specific bug (code-reviewer BLOKER 1) this closes: a
     * tenant that used to have two active locations, deactivated one,
     * leaving THIS service's stock orphaned at the now-inactive one, while
     * tenantHasExactlyOneActiveLocation() alone still says "yes, one".
     */
    public static function eligibleForDirectRouting(?int $organizationId, ?Service $service): bool
    {
        if (! self::tenantHasExactlyOneActiveLocation($organizationId)) {
            return false;
        }

        if (! $service instanceof Service || ! $service->exists) {
            // Brand new service (Create page) — nothing could have been
            // orphaned yet.
            return true;
        }

        $primaryLocation = self::primaryLocationOf($organizationId);

        return ! $primaryLocation || ! self::serviceHasStockOutsideItsPrimaryLocation($service, $primaryLocation);
    }

    public static function handle(Service $service): void
    {
        if ($service->service_type !== ServiceType::ItemRental) {
            return;
        }

        if (! self::eligibleForDirectRouting($service->organization_id, $service)) {
            return;
        }

        $primaryLocation = self::primaryLocationOf($service->organization_id);

        if (! $primaryLocation) {
            // Defensive only — every organization has had a primary
            // location since Faza 1's backfill ran. Nothing to anchor to;
            // quantity_total stays whatever the form saved directly, same
            // as before Faza 2 existed.
            return;
        }

        DB::transaction(function () use ($service, $primaryLocation): void {
            ServiceLocationStock::withoutGlobalScope('organization')->updateOrCreate(
                ['service_id' => $service->id, 'location_id' => $primaryLocation->id],
                [
                    'organization_id' => $service->organization_id,
                    'quantity' => $service->quantity_total ?? 0,
                    'is_active' => true,
                ]
            );

            $service->recalculateQuantityTotal();
        });
    }

    /**
     * Looked up by primary_slot alone, deliberately without an is_active
     * filter (unlike tenantHasExactlyOneActiveLocation() above): the
     * primary is structurally THE single anchor for a single-active-location
     * tenant even in the rare edge case where the primary itself happens to
     * be inactive while exactly one OTHER location is active — routing into
     * anything else would silently split data across two rows for a tenant
     * this field promises behaves like a single number.
     */
    private static function primaryLocationOf(?int $organizationId): ?Location
    {
        return Location::withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->where('primary_slot', 1)
            ->first();
    }

    /**
     * True when this specific SERVICE already has a service_location_stocks
     * row at a location other than the org's primary — regardless of
     * whether that other location is currently active. Deliberately
     * per-service, not per-org: two services of the same tenant can have
     * different stock footprints (only a service whose "Stany magazynowe"
     * tab was opened, or whose organization had a second location at the
     * TIME that service was created, ever gets a row there —
     * SyncServiceLocationStock only backfills existing services when a NEW
     * location is created, never retroactively for services created
     * later).
     *
     * code-reviewer BLOKER 1 (Faza 2): without this check, a tenant that
     * used to have two active locations, split a service's stock across
     * both, then deactivated the second one, "looks" single-location again
     * to tenantHasExactlyOneActiveLocation() — but the OTHER location's
     * stock row still exists (Location.is_active and
     * ServiceLocationStock.is_active are independent columns; nothing
     * cleans up the stock row when a location is deactivated). Routing
     * anyway would ABSORB that orphaned row into the primary's own
     * quantity, then re-sum it straight back into quantity_total via
     * recalculateQuantityTotal() — and repeat, unbounded, on every
     * subsequent save even with the field's value left untouched, because
     * each absorption grows the primary row itself.
     */
    private static function serviceHasStockOutsideItsPrimaryLocation(Service $service, Location $primaryLocation): bool
    {
        return ServiceLocationStock::withoutGlobalScope('organization')
            ->where('service_id', $service->id)
            ->where('location_id', '!=', $primaryLocation->id)
            ->exists();
    }
}
