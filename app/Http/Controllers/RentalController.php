<?php

namespace App\Http\Controllers;

use App\Models\RentalCategory;
use App\Models\Service;
use App\Services\RentalAvailabilityService;
use App\Support\LocationContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class RentalController extends Controller
{
    public function __construct(
        private readonly RentalAvailabilityService $availability,
        private readonly LocationContext $locations,
    ) {}

    public function index(): View
    {
        $categories = RentalCategory::active()->ordered()
            ->withCount(['services' => fn ($q) => $q->active()])
            ->get();

        $featuredServices = Service::rentable()->active()
            ->ordered()
            ->limit(6)
            ->get();

        return view('rentals.index', [
            'categories' => $categories,
            'featuredServices' => $featuredServices,
            'locationAvailability' => $this->locationAvailabilityFor($featuredServices),
        ]);
    }

    public function showCategory(RentalCategory $category): View
    {
        abort_unless($category->is_active, 404);

        $services = $category->services()->active()->ordered()->get();
        $allCategories = RentalCategory::active()->ordered()->get();

        return view('rentals.category', [
            'category' => $category,
            'services' => $services,
            'allCategories' => $allCategories,
            'locationAvailability' => $this->locationAvailabilityFor($services),
        ]);
    }

    /**
     * Faza 5.3 (86cbahqgb, plan-wdrozenia.md) — ONE
     * `RentalAvailabilityService::availabilityForServices()` call per listing
     * render, however many services are on the page (constant query count is
     * the entire reason that method exists — see its own docblock and
     * `RentalAvailabilityServiceBulkTest::
     * test_query_count_is_constant_regardless_of_how_many_services_are_passed`),
     * reduced to the single number each tile shows via
     * `availableQuantityFor()` (kontrakt-dostepnosci.md's "missing key in
     * `locations` means ZERO" rule lives there, not re-derived here).
     *
     * "Today" (a single-day window) is a deliberate choice, not the
     * customer's actual rental dates — a catalog tile has no dates yet; it
     * answers "is there anything to rent RIGHT NOW", the live equivalent of
     * the static `quantity_total` badge this replaces.
     *
     * @return array<int, int> serviceId => quantity to display on that tile
     */
    private function locationAvailabilityFor(Collection $services): array
    {
        if ($services->isEmpty()) {
            return [];
        }

        $today = Carbon::today();
        $bulk = $this->availability->availabilityForServices($services, $today, $today);
        $locationId = $this->locations->selectedId();

        return $services->mapWithKeys(fn (Service $service) => [
            $service->id => $this->availability->availableQuantityFor(
                $bulk[$service->id] ?? ['total' => 0, 'locations' => []],
                $locationId
            ),
        ])->all();
    }
}
