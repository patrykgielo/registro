<?php

namespace App\Http\Controllers;

use App\Models\Service;

class ServiceController extends Controller
{
    /**
     * Display a listing of all published services.
     */
    public function index()
    {
        $services = Service::published()
            ->active()
            ->ordered()
            ->get();

        return view('services.index', compact('services'));
    }

    /**
     * Display the specified service.
     */
    public function show(Service $service)
    {
        // Only show published services (404 for drafts/scheduled)
        abort_unless($service->isPublished(), 404);

        // Get related services (same area, similar price, or random)
        $relatedServices = Service::published()
            ->active()
            ->where('id', '!=', $service->id)
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
        ]);
    }

    /**
     * Build Schema.org Service structured data.
     */
    private function buildServiceSchema(Service $service): string
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'Service',
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
            'serviceType' => 'Car Detailing',
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

        return json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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

        return json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
