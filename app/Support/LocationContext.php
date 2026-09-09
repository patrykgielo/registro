<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Location;
use Illuminate\Database\Eloquent\Collection;

/**
 * Faza 5.1 (app/docs/features/lokalizacje/plan-wdrozenia.md) — the single
 * source of truth the ticket (86cbahqg3) demands: header switcher, product
 * page, cart and checkout all read THIS instead of re-deriving their own
 * "does this tenant even have locations" check. That rule already burned this
 * project once (see `filament-resources.md` / the tenant-scoped-slug incident
 * chain) — do not let a future step inline its own `Location::active()->count()`.
 *
 * Tenant is derived via `TenantFeature::currentTenant()` — the same VULN-003
 * -hardened mechanism every other tenant-aware component uses. This class
 * NEVER reads `session('tenant_id')` itself; the only session key it owns is
 * the *selected location*, and even that is re-validated against the CURRENT
 * request's tenant on every read (see selected()), not trusted blindly.
 *
 * Every Location lookup filters by `organization_id` explicitly (see
 * find()/activeLocations()) rather than trusting Location::BelongsToOrganization's
 * ambient global scope — that scope is fail-closed for HTTP but does ZERO
 * filtering in a real console/queue context (code review, 2026-09-09; see
 * models.md). Do not "simplify" these two methods back to a bare
 * `Location::active()->...` call.
 *
 * NEVER bind this class as a `singleton()`. Since Faza 5.5 it IS bound as
 * `scoped()` (AppServiceProvider), so the header switcher and the product
 * page's "available elsewhere" section share one `$activeLocationsCache`
 * instead of issuing the same `locations` query twice per request.
 *
 * The difference is the whole point, not a nuance. `$activeLocationsCache` is
 * per-instance TENANT state; a `singleton()` outlives the process and would
 * leak tenant A's locations into tenant B's request — the same shape of bug
 * PR #251 fixed for the navigation cache. `scoped()` is reset by the framework
 * before every queue job (Worker::daemon() calls forgetScopedInstances()),
 * and each HTTP request builds a fresh container, so the cache can never
 * outlive the tenant it was built for.
 *
 * That guarantee has ONE boundary worth stating plainly: `scoped()` protects
 * against the WORKER outliving a tenant, not against a tenant CHANGING inside
 * one process. If a future command or job ever iterates several tenants in a
 * single process, it MUST call `app()->forgetInstance(self::class)` on every
 * switch — otherwise this cache follows it across the tenant boundary. No such
 * caller exists today (verified: nothing under app/Console, app/Jobs,
 * app/Listeners or app/Notifications touches this class).
 *
 * See architecture-models.md.
 */
class LocationContext
{
    private const SESSION_KEY = 'selected_location_id';

    private ?Collection $activeLocationsCache = null;

    /**
     * The effective location for the current tenant.
     *
     * - A session value that still resolves (belongs to the current tenant,
     *   still exists, still active) wins.
     * - Otherwise, a tenant with EXACTLY ONE active location gets it for
     *   free — "kontekst ustawia się sam, bez udziału klienta"
     *   (tryb-jednooddzialowy.md) — computed live, never written to session,
     *   so it self-heals the moment a second location is added instead of
     *   freezing whatever was true when the session started.
     * - Otherwise null (no tenant, zero locations, or 2+ with nothing
     *   selected yet — Faza 5.2's switcher is what sets one).
     *
     * Always re-validates from the database rather than trusting the raw
     * session value — safe to call even when ShareSelectedLocation never ran
     * for this request (console, a bare Unit test, a future Livewire
     * component off the 'web' middleware group), AND safe in a genuine
     * console/queue context specifically because find()/activeLocations()
     * filter by tenant themselves (see their docblocks) rather than trusting
     * BelongsToOrganization's ambient scope, which does not filter at all
     * there.
     */
    public function selected(): ?Location
    {
        $id = $this->rawSelectedId();

        if ($id !== null) {
            $location = $this->find($id);

            if ($location) {
                return $location;
            }
        }

        $active = $this->activeLocations();

        return $active->count() === 1 ? $active->first() : null;
    }

    public function selectedId(): ?int
    {
        return $this->selected()?->id;
    }

    /**
     * Tenant-level property, independent of whether anything is currently
     * selected. 0 or 1 active location → nothing for a client to choose
     * (tryb-jednooddzialowy.md: "Przełącznik oddziału w headerze nie
     * renderuje się"); 2+ → the future switcher must show and Faza 6's
     * checkout gate must block until something is selected.
     */
    public function selectionRequired(): bool
    {
        return $this->activeLocations()->count() > 1;
    }

    /**
     * Persists an explicit choice. Only ever meant to be called with a
     * Location the caller already resolved from THIS tenant's own active
     * list (e.g. a future switcher built from `activeLocations()`-shaped
     * data validated the same way RentalBookingController's
     * `locationIdRules()` validates the public API's `location_id`) — so a
     * mismatch here is a caller bug, not user input to fail gracefully on.
     *
     * @throws \InvalidArgumentException when $location does not belong to
     *                                   the current tenant, or is not active.
     */
    public function set(Location $location): void
    {
        $tenant = TenantFeature::currentTenant();

        if (! $tenant || $location->organization_id !== $tenant->id || ! $location->is_active) {
            throw new \InvalidArgumentException(
                'LocationContext::set() requires an active Location belonging to the current tenant.'
            );
        }

        session([self::SESSION_KEY => $location->id]);
    }

    public function clear(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    /**
     * Called by ShareSelectedLocation on every request — clears a session
     * value that no longer resolves (another/no tenant, deleted, or
     * deactivated). Kept separate from selected() so the middleware's intent
     * ("keep session honest") reads directly at the call site rather than as
     * a side effect of a getter. selected() stays correct on its own even if
     * this is never called — see its docblock — this only prevents a stale
     * raw id from lingering for any future code that reads the session key
     * directly instead of going through this class.
     */
    public function pruneStaleSelection(): void
    {
        $id = $this->rawSelectedId();

        if ($id !== null && $this->find($id) === null) {
            $this->clear();
        }
    }

    private function rawSelectedId(): ?int
    {
        $id = session(self::SESSION_KEY);

        return is_numeric($id) ? (int) $id : null;
    }

    /**
     * Explicit tenant guard — does NOT rely on Location::BelongsToOrganization's
     * global scope for isolation (code review, 2026-09-09). That scope is
     * fail-closed for a real HTTP request (VULN-003 Layer 2) but is NOT
     * fail-closed for a real console/queue context: `BelongsToOrganization`'s
     * `app()->runningInConsole() && ! app()->runningUnitTests()` branch
     * (app/Traits/BelongsToOrganization.php:36-38) returns with ZERO
     * filtering — not "no tenant found", but "scope never ran at all". A
     * future queue job or artisan command touching this class, with exactly
     * ONE active location existing anywhere across ALL tenants, would
     * otherwise auto-select a DIFFERENT tenant's location.
     *
     * Mirrors the precedent already in this codebase: Location's own
     * `isOnlyLocationForOrganization()`/`promoteToPrimary()` (same file)
     * deliberately do not trust the ambient scope either, for the identical
     * reason — both use `withoutGlobalScope('organization')` plus an
     * explicit `where('organization_id', ...)` so the answer is correct
     * regardless of which context (HTTP, console, queue) called them.
     */
    private function find(int $id): ?Location
    {
        $tenant = TenantFeature::currentTenant();

        if (! $tenant) {
            return null;
        }

        return Location::withoutGlobalScope('organization')
            ->where('organization_id', $tenant->id)
            ->active()
            ->find($id);
    }

    /**
     * Public since Faza 5.2 (86cbahqg8) — the header/drawer switcher needs
     * the actual rows to render as options, not just the yes/no answer
     * `selectionRequired()` gives. Deliberately reuses THIS method rather
     * than a second `Location::active()->...` query in the view layer, which
     * is exactly the duplication this class's own top-of-file docblock warns
     * against ("do not let a future step inline its own
     * `Location::active()->count()` check"). Per-instance cache still
     * applies (see property docblock) — safe under the current per-request
     * `app(LocationContext::class)` resolution, never a singleton.
     *
     * @see find() for why this does not rely on the ambient global scope.
     */
    public function activeLocations(): Collection
    {
        if ($this->activeLocationsCache !== null) {
            return $this->activeLocationsCache;
        }

        $tenant = TenantFeature::currentTenant();

        if (! $tenant) {
            return $this->activeLocationsCache = new Collection;
        }

        return $this->activeLocationsCache = Location::withoutGlobalScope('organization')
            ->where('organization_id', $tenant->id)
            ->active()
            ->ordered()
            ->get();
    }
}
