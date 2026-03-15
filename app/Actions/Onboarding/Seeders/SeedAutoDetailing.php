<?php

declare(strict_types=1);

namespace App\Actions\Onboarding\Seeders;

use App\Models\Organization;
use App\Models\Service;

class SeedAutoDetailing implements VerticalSeeder
{
    public function seed(Organization $organization): void
    {
        $services = $this->getServices();

        foreach ($services as $sortOrder => $serviceData) {
            Service::withoutGlobalScope('organization')->create([
                'organization_id' => $organization->id,
                'name' => $serviceData['name'],
                'description' => $serviceData['description'],
                'price' => $serviceData['price'],
                'duration_minutes' => $serviceData['duration_minutes'],
                'is_active' => true,
                'sort_order' => $sortOrder,
                'metadata' => [
                    'prices_by_size' => $serviceData['prices_by_size'],
                    'durations_by_size' => $serviceData['durations_by_size'],
                    'available_for_mobile' => $serviceData['available_for_mobile'] ?? true,
                ],
            ]);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getServices(): array
    {
        return [
            [
                'name' => 'Mycie detailingowe',
                'description' => 'Profesjonalne mycie zewnętrzne: pre-wash, mycie kontaktowe metodą dwóch wiader, osuszanie, quick detailer.',
                'price' => 150,
                'duration_minutes' => 60,
                'prices_by_size' => ['A' => 150, 'B' => 180, 'C' => 220, 'D' => 270],
                'durations_by_size' => ['A' => 60, 'B' => 70, 'C' => 80, 'D' => 90],
                'available_for_mobile' => true,
            ],
            [
                'name' => 'Czyszczenie wnętrza',
                'description' => 'Odkurzanie, czyszczenie plastików, szyb wewnętrznych, konserwacja elementów gumowych.',
                'price' => 100,
                'duration_minutes' => 60,
                'prices_by_size' => ['A' => 100, 'B' => 120, 'C' => 150, 'D' => 180],
                'durations_by_size' => ['A' => 60, 'B' => 70, 'C' => 80, 'D' => 90],
                'available_for_mobile' => true,
            ],
            [
                'name' => 'Pełny detailing wnętrza',
                'description' => 'Kompleksowe czyszczenie wnętrza: pranie tapicerki/skóry, plastiki, podsufitka, bagażnik, ozonowanie.',
                'price' => 500,
                'duration_minutes' => 180,
                'prices_by_size' => ['A' => 500, 'B' => 600, 'C' => 700, 'D' => 900],
                'durations_by_size' => ['A' => 180, 'B' => 210, 'C' => 240, 'D' => 300],
                'available_for_mobile' => false,
            ],
            [
                'name' => 'Pranie tapicerki',
                'description' => 'Głębokie pranie tapicerki materiałowej: fotele, kanapa, boczki, podsufitka, dywaniki.',
                'price' => 350,
                'duration_minutes' => 180,
                'prices_by_size' => ['A' => 350, 'B' => 450, 'C' => 550, 'D' => 700],
                'durations_by_size' => ['A' => 180, 'B' => 240, 'C' => 300, 'D' => 360],
                'available_for_mobile' => false,
            ],
            [
                'name' => 'Korekta lakieru — jednoetapowa',
                'description' => 'Jednoetapowa korekta lakieru maszyną polerską. Usunięcie ok. 70% rys i hologramów.',
                'price' => 800,
                'duration_minutes' => 240,
                'prices_by_size' => ['A' => 800, 'B' => 900, 'C' => 1100, 'D' => 1400],
                'durations_by_size' => ['A' => 240, 'B' => 280, 'C' => 320, 'D' => 360],
                'available_for_mobile' => false,
            ],
            [
                'name' => 'Korekta lakieru — dwuetapowa',
                'description' => 'Dwuetapowa korekta lakieru: cięcie + polerowanie. Usunięcie do 95% defektów lakieru.',
                'price' => 1200,
                'duration_minutes' => 480,
                'prices_by_size' => ['A' => 1200, 'B' => 1400, 'C' => 1600, 'D' => 2000],
                'durations_by_size' => ['A' => 480, 'B' => 540, 'C' => 600, 'D' => 720],
                'available_for_mobile' => false,
            ],
            [
                'name' => 'Powłoka ceramiczna 12 miesięcy',
                'description' => 'Aplikacja powłoki ceramicznej z ochroną na 12 miesięcy. Hydrofobowość, łatwiejsze mycie, ochrona UV.',
                'price' => 800,
                'duration_minutes' => 240,
                'prices_by_size' => ['A' => 800, 'B' => 1000, 'C' => 1200, 'D' => 1500],
                'durations_by_size' => ['A' => 240, 'B' => 260, 'C' => 280, 'D' => 300],
                'available_for_mobile' => false,
            ],
            [
                'name' => 'Ozonowanie',
                'description' => 'Ozonowanie wnętrza pojazdu. Neutralizacja nieprzyjemnych zapachów, bakterii i grzybów.',
                'price' => 150,
                'duration_minutes' => 60,
                'prices_by_size' => ['A' => 150, 'B' => 150, 'C' => 150, 'D' => 200],
                'durations_by_size' => ['A' => 60, 'B' => 60, 'C' => 60, 'D' => 90],
                'available_for_mobile' => true,
            ],
        ];
    }
}
