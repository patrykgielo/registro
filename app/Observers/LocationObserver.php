<?php

declare(strict_types=1);

namespace App\Observers;

use App\Exceptions\LocationCannotBeDeletedException;
use App\Models\Location;
use App\Support\TenantFeature;
use Illuminate\Support\Facades\DB;

/**
 * Guarantees "exactly one primary location per organization"
 * (app/docs/features/lokalizacje/tryb-jednooddzialowy.md) around the
 * `locations.primary_slot` shadow column and its
 * UNIQUE(organization_id, primary_slot) constraint.
 *
 * Same shadow-column idea as App\Models\Cart::booted() + `carts.active_slot`,
 * but that one only ever derives its own row's value from its own `status` —
 * it never has to coordinate a SECOND row. Promoting a location to primary
 * does, which is why this lives in an Observer (registered in
 * AppServiceProvider, same convention as OrganizationObserver) rather than a
 * booted() hook.
 */
class LocationObserver
{
    /**
     * The first location a tenant ever creates always becomes primary — the
     * mechanism tryb-jednooddzialowy.md's "state domyślny" depends on. No
     * sibling row can exist yet in that branch, so there is nothing to
     * demote first.
     *
     * If a location is explicitly created already marked primary (e.g. an
     * admin ticks "make primary" while adding a second branch), the same
     * demote-then-allow ordering as updating() applies.
     */
    public function creating(Location $location): void
    {
        $organizationId = $location->organization_id ?: TenantFeature::currentTenant()?->id;

        if (! $organizationId) {
            return;
        }

        if ((int) $location->primary_slot === 1) {
            $this->demoteExistingPrimary($organizationId, excludeId: null);

            return;
        }

        $hasExisting = Location::withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->exists();

        if (! $hasExisting) {
            $location->primary_slot = 1;
        }
    }

    /**
     * Promoting a location to primary later must first demote whoever holds
     * the slot today — UNIQUE(organization_id, primary_slot) rejects the
     * UPDATE outright if both rows claim `1` at once, so the old primary's
     * `NULL` has to be committed before this row's own pending UPDATE is
     * allowed to proceed.
     *
     * This is two sequential commits, not one enclosing transaction around
     * both writes — Eloquent's own pending UPDATE for $location runs right
     * after this method returns, outside the DB::transaction() below.
     * Returning false here to cancel Eloquent's UPDATE and instead
     * saveQuietly() it ourselves inside the transaction was considered and
     * rejected: it would make the caller's `$location->save()` return false
     * despite the row having actually been persisted, which would misfire
     * anything (Filament included) that branches on that return value or
     * relies on the `saved`/`updated` events actually firing. The two-commit
     * order below is what prevents the UNIQUE rejection; true single-COMMIT
     * atomicity for the one-click "ustaw jako główny" action belongs in
     * Location::promoteToPrimary(), which callers should use instead of
     * assigning `primary_slot` directly.
     */
    public function updating(Location $location): void
    {
        if (! $location->isDirty('primary_slot') || (int) $location->primary_slot !== 1) {
            return;
        }

        $this->demoteExistingPrimary($location->organization_id, excludeId: $location->getKey());
    }

    /**
     * Model-layer backstop for "every tenant keeps at least one location,
     * and never deletes the primary while siblings exist"
     * (tryb-jednooddzialowy.md). Filament's LocationResource::guardDeletion()
     * checks the same two Location predicates first and halts the UI action
     * with a friendly notification before delete() is ever called — this is
     * what protects every OTHER caller (tinker, a future console command or
     * API endpoint, a seeder) that goes around Filament entirely.
     *
     * Does NOT fire for a location removed by `organizations.id`'s
     * cascadeOnDelete() FK when an organization itself is hard-deleted —
     * MySQL performs that DELETE on the child rows directly at the DB level,
     * never instantiating a Location model or dispatching its events. Pinned
     * by LocationCascadeDeletionTest, which forceDelete()s an organization
     * that still owns its (would-be-guarded) primary location and asserts
     * both that no exception is thrown and that the row is actually gone —
     * proving this by observation, not by reading this docblock.
     */
    public function deleting(Location $location): void
    {
        if ($location->isOnlyLocationForOrganization()) {
            throw new LocationCannotBeDeletedException(
                "Cannot delete location [{$location->id}]: it is the only location for organization [{$location->organization_id}]. ".
                'Every tenant must keep at least one (tryb-jednooddzialowy.md).'
            );
        }

        if ($location->isPrimary()) {
            throw new LocationCannotBeDeletedException(
                "Cannot delete location [{$location->id}]: it is the primary location for organization [{$location->organization_id}]. ".
                'Promote another location first via Location::promoteToPrimary().'
            );
        }
    }

    private function demoteExistingPrimary(int $organizationId, ?int $excludeId): void
    {
        DB::transaction(function () use ($organizationId, $excludeId) {
            $query = Location::withoutGlobalScope('organization')
                ->where('organization_id', $organizationId)
                ->where('primary_slot', 1)
                ->lockForUpdate();

            if ($excludeId) {
                $query->whereKeyNot($excludeId);
            }

            $query->update(['primary_slot' => null]);
        });
    }
}
