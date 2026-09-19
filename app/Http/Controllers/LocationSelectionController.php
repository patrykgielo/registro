<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Location;
use App\Services\Cart\CartService;
use App\Support\Auth\IntendedDestination;
use App\Support\LocationContext;
use App\Support\Settings\SettingsManager;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Faza 5.2 (86cbahqg8) — write side of the header/drawer location switcher.
 * Faza 6 krok 6.2 (86cbahqgv) extended store() below with the non-empty-cart
 * confirmation step — deliberately the SAME route/controller, not a second
 * one (the team lead's own instruction): a second POST back to THIS action,
 * carrying `confirmed=1`, is how the customer answers the question.
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

    public function store(
        Request $request,
        LocationContext $locations,
        CartService $cart,
        SettingsManager $settings
    ): RedirectResponse|View {
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
            // Faza 6 krok 6.2 — set by the confirmation view's own form
            // (this same action, posted a second time), never by the
            // switcher itself. Absent/false on every first attempt.
            'confirmed' => ['nullable', 'boolean'],
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

        // Faza 6 krok 6.2 — only engaged for a customer who can actually HAVE
        // a cart (authenticated + rentals enabled for this tenant). A guest,
        // or an authenticated visitor on a time_slot-only tenant, has nothing
        // for a location switch to revalidate — falls straight through to
        // the unconditional set() below, identical to Faza 5.2's original
        // behaviour.
        if (auth()->check() && $settings->isRentalEnabled()) {
            $userCart = $cart->getOrCreateCart($tenant, auth()->user());

            // Cart already points at this exact location (including the
            // common case of a brand-new cart getOrCreateCart() just stamped
            // with this same selection) — nothing to revalidate or confirm.
            if ($userCart->location_id !== $location->id) {
                // "Zmiana oddziału z niepustym koszykiem to JAWNA DECYZJA
                // KLIENTA z rewalidacją" (86cbahqgv) — the prompt is about
                // moving the customer's PICKUP POINT while they have a
                // pending order, which matters even when every item still
                // fits at the new branch (a different city to collect from
                // is not a stock question). An EMPTY cart has nothing to
                // move yet — getOrCreateCart() itself already documents
                // "an empty cart needs no revalidation" for the same reason
                // — so it skips straight to setLocation() below with zero
                // items to decide on, same as the plain set() path used to.
                if (! $request->boolean('confirmed') && $userCart->items()->exists()) {
                    return view('cart.location-change-confirm', [
                        'currentLocation' => $userCart->location,
                        'newLocation' => $location,
                        'preview' => $cart->previewLocationChange($userCart, $location),
                        'redirectTo' => $this->resolveRedirectTarget($request),
                    ]);
                }

                $report = $cart->setLocation($userCart, $location);

                if ($report['reduced'] !== [] || $report['removed'] !== []) {
                    session()->flash('location_change_report', $report);
                }
            }
        }

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
