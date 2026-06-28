<?php

namespace App\Http\Controllers;

use App\Enums\ServiceType;
use App\Models\Service;
use App\Support\Seo\MetaTagBuilder;

class ServiceController extends Controller
{
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

        return view('services.index', compact('services'));
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
            ...MetaTagBuilder::forModel($service),
        ]);
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
