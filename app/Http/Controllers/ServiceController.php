<?php

namespace App\Http\Controllers;

use App\Enums\ServiceType;
use App\Models\Service;
use App\Services\RentalAvailabilityService;
use App\Support\LocationContext;
use App\Support\Seo\MetaTagBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ServiceController extends Controller
{
    public function __construct(
        private readonly RentalAvailabilityService $availability,
        private readonly LocationContext $locations,
    ) {}

    /**
     * Display a listing of all published services.
     */
    public function index()
    {
        $services = Service::active()
            ->where(function ($query) {
                // time_slot: must be published
                // item_rental: only needs is_active (no published_at workflow)
                $query->where(function ($q) {
                    $q->bookable()->published();
                })->orWhere(function ($q) {
                    $q->rentable();
                });
            })
            ->ordered()
            ->paginate(24);

        return view('services.index', [
            'services' => $services,
            // Faza 5.3 code review: $services is a LengthAwarePaginator, and
            // collect($paginator) is a trap — Paginator is Arrayable, and
            // getArrayableItems() checks Arrayable BEFORE Traversable, so it
            // calls ->toArray() and collects the pagination META shape
            // (current_page, per_page, data: [...]) instead of the rows.
            // ->getCollection() is the actual Collection of models.
            'locationAvailability' => $this->locationAvailabilityFor($services->getCollection()),
        ]);
    }

    /**
     * Faza 5.3 (86cbahqgb) — mirrors RentalController::locationAvailabilityFor().
     * `/uslugi` mixes item_rental and time_slot services on the same page
     * (see the query above); only the item_rental subset has a location
     * dimension at all, so only those go into the bulk call — passing
     * time_slot rows through would waste query time counting reservations
     * against `quantity_total = null` for services the tile never renders a
     * quantity badge for anyway (`$isRental` gates that in
     * service-card.blade.php AND in this view's own inline markup). Still
     * exactly ONE `availabilityForServices()` call regardless of how many
     * rentable services are on the paginated page.
     *
     * @return array<int, int> serviceId => quantity to display on that tile
     */
    private function locationAvailabilityFor(Collection $services): array
    {
        $rentable = $services->filter(
            fn (Service $service) => $service->service_type === ServiceType::ItemRental
        )->values();

        if ($rentable->isEmpty()) {
            return [];
        }

        $today = Carbon::today();
        $bulk = $this->availability->availabilityForServices($rentable, $today, $today);
        $locationId = $this->locations->selectedId();

        return $rentable->mapWithKeys(fn (Service $service) => [
            $service->id => $this->availability->availableQuantityFor(
                $bulk[$service->id] ?? ['total' => 0, 'locations' => []],
                $locationId
            ),
        ])->all();
    }

    /**
     * Display the specified service.
     */
    public function show(Service $service)
    {
        // time_slot: must be published. item_rental: only needs is_active.
        if ($service->service_type === ServiceType::TimeSlot) {
            abort_unless($service->isPublished(), 404);
        } else {
            abort_unless($service->is_active, 404);
        }

        // Get related services (same type, active)
        $relatedServices = Service::active()
            ->where('service_type', $service->service_type)
            ->where('id', '!=', $service->id)
            ->when(
                $service->service_type === ServiceType::TimeSlot,
                fn ($q) => $q->published()
            )
            ->ordered()
            ->limit(3)
            ->get();

        // Prepare Schema.org structured data
        $schemaService = $this->buildServiceSchema($service);
        $schemaBreadcrumbs = $this->buildBreadcrumbSchema($service);

        return view('services.show', [
            'service' => $service,
            'relatedServices' => $relatedServices,
            'schemaService' => $schemaService,
            'schemaBreadcrumbs' => $schemaBreadcrumbs,
            'pageType' => 'service',
            ...$this->rentalAvailabilityFor($service),
            ...MetaTagBuilder::forModel($service),
        ]);
    }

    /**
     * Faza 5.4 (86cbahqgh) — the product page's own badge/calendar/AJAX
     * fetches MUST agree with the tile the customer clicked from
     * (`locationAvailabilityFor()` above) for the SAME selected location,
     * or the customer gets two disagreeing numbers for the same piece of
     * equipment — exactly the bug the ticket names. Same "today" window,
     * same `availableQuantityFor()` read, same `LocationContext::selectedId()`
     * source of truth; the only difference is a single-service call instead
     * of a bulk one (a product page renders exactly one tile).
     *
     * `selectedLocationId` also goes to the view so its Alpine calendar can
     * put `location_id` on its own AJAX calls (`rental.calendar` /
     * `rental.availability`) — those already accept the param
     * (kontrakt-dostepnosci.md Zasada 3, `RentalBookingController`), they
     * just were not being sent one. `null` for a non-rental service or a
     * tenant/selection with nothing chosen — both routes already treat
     * `location_id` as optional (`locationIdRules()`), so an absent value
     * degrades to today's global behaviour, not an error.
     *
     * @return array{availableQuantity: ?int, selectedLocationId: ?int, availableElsewhere: array<int, array{location: \App\Models\Location, quantity: int}>}
     */
    private function rentalAvailabilityFor(Service $service): array
    {
        $locationId = $this->locations->selectedId();

        if ($service->service_type !== ServiceType::ItemRental) {
            return ['availableQuantity' => null, 'selectedLocationId' => $locationId, 'availableElsewhere' => []];
        }

        $today = Carbon::today();
        $bulk = $this->availability->availabilityForServices(collect([$service]), $today, $today);
        $entry = $bulk[$service->id] ?? ['total' => 0, 'locations' => []];

        return [
            'availableQuantity' => $this->availability->availableQuantityFor($entry, $locationId),
            'selectedLocationId' => $locationId,
            'availableElsewhere' => $this->availableElsewhere($entry, $locationId),
        ];
    }

    /**
     * Faza 5.5 (86cbahqgn) — "Dostępne też w: Gdańsk (2 szt.)" list for the
     * product page's sidebar. Reads the SAME $entry rentalAvailabilityFor()
     * already fetched for the badge above via availabilityForServices() —
     * adding a second availability query here would waste exactly the work
     * Faza 4.7 collapsed to one (kontrakt-dostepnosci.md). Location NAMES
     * come from LocationContext::activeLocations() — the single source of
     * truth for the tenant's location list (its own docblock) — which, since
     * AppServiceProvider now binds LocationContext `scoped()` (this phase),
     * is the SAME cached instance/list header.blade.php's switcher already
     * queried for this request, so this line costs zero additional queries
     * on any page where the switcher is visible.
     *
     * Deliberately EMPTY when no branch is selected ($locationId === null):
     * `availableQuantityFor()`'s own docblock explains the badge above shows
     * the COMBINED total across every location in that case, so there is no
     * single "here" to contrast an "elsewhere" against — every other
     * location's stock is already folded into the number already on screen.
     * A tenant with 0 or 1 active locations reaches this branch with
     * `$locationId === null` OR ends up with an empty `reject()` result
     * (the one location IS the selected one) either way — never a section.
     *
     * Shown for every OTHER active location with stock > 0, regardless of
     * whether the SELECTED branch itself has any (86cbahqgn's acceptance
     * criterion reads "wolny gdzie indziej", not "wolny gdzie indziej I
     * brak tutaj" — a customer seeing 1 unit here may still want to know 4
     * are waiting at another branch; a hidden threshold here would bury
     * information the criterion asks to surface). No cap on the list, same
     * choice header.blade.php's own switcher already made for the identical
     * data (its code review comment: no cap on locations per tenant) —
     * ordered by `activeLocations()`'s own `->ordered()` query, not
     * re-sorted by quantity. A location without a service_location_stocks
     * anchor row for this service is ABSENT from `$entry['locations']` and
     * reads as zero via `?? 0` — kontrakt-dostepnosci.md's "brak klucza =
     * zero, nie brak ograniczenia" — so it is filtered out here exactly like
     * a real zero would be, never shown as available.
     *
     * @param  array{total: int, locations: array<int, int>}  $entry
     * @return array<int, array{location: \App\Models\Location, quantity: int}>
     */
    private function availableElsewhere(array $entry, ?int $locationId): array
    {
        if ($locationId === null) {
            return [];
        }

        return $this->locations->activeLocations()
            ->reject(fn ($location) => $location->id === $locationId)
            ->map(fn ($location) => ['location' => $location, 'quantity' => $entry['locations'][$location->id] ?? 0])
            ->filter(fn (array $row) => $row['quantity'] > 0)
            ->values()
            ->all();
    }

    /**
     * Build Schema.org Service structured data.
     */
    private function buildServiceSchema(Service $service): string
    {
        $isRental = $service->service_type === ServiceType::ItemRental;

        $schema = [
            '@context' => 'https://schema.org',
            '@type' => $isRental ? 'Product' : 'Service',
            'name' => $service->name,
            'description' => $service->excerpt ?? $service->name,
            'provider' => [
                '@type' => 'LocalBusiness',
                'name' => config('app.name'),
                'areaServed' => [
                    '@type' => 'City',
                    'name' => $service->area_served ?? 'Poznań',
                ],
            ],
            'url' => route('service.show', $service),
        ];

        // Add offers if price exists
        if ($service->price) {
            $schema['offers'] = [
                '@type' => 'Offer',
                'price' => $service->price,
                'priceCurrency' => 'PLN',
            ];

            // Add price specification if price_from exists
            if ($service->price_from) {
                $schema['offers']['priceSpecification'] = [
                    '@type' => 'UnitPriceSpecification',
                    'minPrice' => $service->price_from,
                ];
            }
        }

        // Add image if exists
        if ($service->featured_image) {
            $schema['image'] = \Storage::url($service->featured_image);
        }

        return json_encode($schema, JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Build Schema.org BreadcrumbList structured data.
     */
    private function buildBreadcrumbSchema(Service $service): string
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                [
                    '@type' => 'ListItem',
                    'position' => 1,
                    'name' => 'Strona główna',
                    'item' => route('home'),
                ],
                [
                    '@type' => 'ListItem',
                    'position' => 2,
                    'name' => 'Usługi',
                    'item' => route('services.index'),
                ],
                [
                    '@type' => 'ListItem',
                    'position' => 3,
                    'name' => $service->name,
                    'item' => route('service.show', $service),
                ],
            ],
        ];

        return json_encode($schema, JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
    }
}
