<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Location;
use App\Support\Auth\IntendedDestination;
use App\Support\LocationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Faza 5.2 (86cbahqg8) — write side of the header/drawer location switcher.
 *
 * `LocationContext::set()` already enforces "belongs to the current tenant
 * AND active" — but by THROWING, because its own docblock treats a mismatch
 * as a caller bug, not user input to fail gracefully on. A hand-crafted
 * `location_id` in this route's POST body IS untrusted user input, so this
 * controller validates the identical two constraints itself first, the same
 * shape `RentalBookingController::locationIdRules()` already uses for the
 * public availability endpoints (`Rule::exists(...)->where('organization_id',
 * ...)->where('is_active', true)`). That makes set() unreachable with a bad
 * id for a real request — an invalid selection gets Laravel's normal
 * redirect-back-with-errors (422 for XHR), never a 500.
 */
class LocationSelectionController extends Controller
{
    private const MAX_REDIRECT_LENGTH = 2048;

    public function store(Request $request, LocationContext $locations): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        abort_unless($tenant !== null, 404);

        // `redirect_to` is validated separately below (resolveRedirectTarget()),
        // never as a `$request->validate()` rule — an overlong or unsafe value
        // there must not abort the WHOLE request. The customer asked to change
        // location; a malformed return address is not a reason to refuse that
        // (code review, 2026-09-09).
        $validated = $request->validate([
            'location_id' => [
                'required',
                'integer',
                Rule::exists('locations', 'id')
                    ->where('organization_id', $tenant->id)
                    ->where('is_active', true),
            ],
        ]);

        // Re-fetched with an explicit organization_id filter rather than
        // trusting Location::BelongsToOrganization's ambient global scope —
        // mirrors LocationContext::find()'s own reasoning (models.md): that
        // scope does zero filtering in a console/queue context. Redundant
        // for this HTTP-only route today, cheap, and keeps this controller
        // correct if it's ever called from anywhere else.
        $location = Location::withoutGlobalScope('organization')
            ->where('organization_id', $tenant->id)
            ->active()
            ->findOrFail($validated['location_id']);

        $locations->set($location);

        return redirect($this->resolveRedirectTarget($request));
    }

    /**
     * Rendered by us into a hidden field with the CURRENT page's own URL
     * (header.blade.php) — never taken from the Referer header
     * (auth-redirects.md: Referer is client-controlled and reachable by an
     * off-site page linking straight to this endpoint).
     *
     * `IntendedDestination::isSafeUrl()` (origin AND path denylist), not
     * `isSameOrigin()` alone (code review, 2026-09-09): this route is
     * PUBLIC and unauthenticated, so `redirect_to=/admin/...` would pass an
     * origin-only check — same host, real destination — and could be handed
     * to a logged-in admin browsing the storefront in the same tab. Reusing
     * the shared check keeps this from drifting out of sync with
     * `IntendedDestination::DENYLISTED_PATH_PREFIXES` the moment that list
     * changes, instead of copying it here.
     */
    private function resolveRedirectTarget(Request $request): string
    {
        $redirectTo = $request->string('redirect_to')->toString();

        if (
            $redirectTo !== ''
            && strlen($redirectTo) <= self::MAX_REDIRECT_LENGTH
            && IntendedDestination::isSafeUrl($redirectTo, $request)
        ) {
            return $redirectTo;
        }

        return route('home');
    }
}
