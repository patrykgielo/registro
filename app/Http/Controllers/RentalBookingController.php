<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ServiceType;
use App\Models\Service;
use App\Services\RentalAvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class RentalBookingController extends Controller
{
    public function __construct(
        protected RentalAvailabilityService $availabilityService
    ) {}

    public function checkAvailability(Request $request, Service $service): JsonResponse
    {
        abort_unless($service->service_type === ServiceType::ItemRental, 404);

        $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'location_id' => $this->locationIdRules($service),
        ]);

        $start = Carbon::parse($request->start_date);
        $end = Carbon::parse($request->end_date);
        $locationId = $this->resolveLocationId($request);
        $available = $this->availabilityService->getAvailableQuantity($service, $start, $end, locationId: $locationId);

        return response()->json([
            'available_quantity' => $available,
            'total_quantity' => $service->quantity_total,
        ]);
    }

    public function monthlyAvailability(Request $request, Service $service): JsonResponse
    {
        abort_unless($service->service_type === ServiceType::ItemRental, 404);

        $request->validate([
            'year' => ['required', 'integer', 'min:2024', 'max:2030'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'location_id' => $this->locationIdRules($service),
        ]);

        $locationId = $this->resolveLocationId($request);

        $data = $this->availabilityService->getMonthlyAvailability(
            $service, (int) $request->year, (int) $request->month, $locationId
        );

        return response()->json($data);
    }

    /**
     * Faza 4 krok 4.6/4.7 (plan-wdrozenia.md) — an optional, tenant-scoped
     * `location_id` query param on both public availability endpoints.
     *
     * WHY the query param, not waiting for Faza 5's `LocationContext`: the
     * plan's own acceptance criterion for the calendar endpoint says wiring
     * it up "must go in one step with the endpoint, otherwise the calendar
     * lies" — and kontrakt-dostepnosci.md's Zasada 3 lists checkAvailability()
     * (`:31`, now wired here too) and monthlyAvailability() (`:48`) as ONE
     * table row sharing the same "poza zakresem, krok 4.6" note. If only the
     * calendar accepted a location and the point-check didn't (or vice
     * versa), a client that already knows which location it cares about
     * would get two disagreeing answers from the same pair of endpoints —
     * exactly the "lying calendar" the criterion warns about. `LocationContext`
     * (session-based, Faza 5.1) does not need to exist for this: the param is
     * stateless, optional, and defaults to null (today's global behaviour,
     * unchanged) until a future frontend (Faza 5.2's switcher) starts
     * sending it — a single-location tenant never sends it at all.
     *
     * WHY validated against $service->organization_id, not re-resolving the
     * tenant from the request: both routes carry
     * `[ResolveTenant::class, RequireTenant::class]` (routes/web.php:147),
     * and $service is resolved via `{service:slug}` route-model binding
     * BEFORE this method runs — Service::BelongsToOrganization's fail-closed
     * global scope (models.md, VULN-003 Layer 2) means the bound $service
     * ALREADY belongs to the current tenant, or the route would have 404'd
     * before reaching here. Scoping against that already-trustworthy value
     * is simpler than re-deriving the tenant and carries the identical
     * guarantee.
     *
     * Fail-closed, NOT the ServiceAreaValidator antipattern
     * (`services.md`/`agent-usage.md` explicitly reject "brak parametru =
     * wpuszczamy wszystkich"): a `location_id` that does not belong to this
     * tenant's own `locations` table FAILS validation (422) — it is never
     * silently ignored or treated as "any location". Absence of the
     * parameter, by contrast, is the deliberate, documented "no location
     * context yet" case and stays null.
     *
     * `->where('is_active', true)` (code review, 2026-09-09): an inactive
     * location is not selling anything and has no business reporting
     * availability. The alternative — falling back to the global pool when
     * an inactive id is passed — was rejected: it would show OTHER
     * locations' stock as available "in" a branch that is closed, which is
     * worse than a 422. This only fires for a stale bookmarked link or a
     * hand-crafted parameter — Faza 5.2's switcher will only ever offer
     * active locations — so refusing here is the safe side of that gap.
     *
     * @return array<int, mixed>
     */
    private function locationIdRules(Service $service): array
    {
        return [
            'nullable',
            'integer',
            Rule::exists('locations', 'id')
                ->where('organization_id', $service->organization_id)
                ->where('is_active', true),
        ];
    }

    private function resolveLocationId(Request $request): ?int
    {
        return $request->filled('location_id') ? (int) $request->input('location_id') : null;
    }
}
