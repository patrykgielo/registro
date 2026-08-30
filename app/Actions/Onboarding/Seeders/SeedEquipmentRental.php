<?php

declare(strict_types=1);

namespace App\Actions\Onboarding\Seeders;

use App\Enums\ServiceType;
use App\Models\Organization;
use App\Models\RentalCategory;
use App\Models\Service;

class SeedEquipmentRental implements VerticalSeeder
{
    public function seed(Organization $organization): void
    {
        $categories = $this->getCatalog();

        foreach ($categories as $sortOrder => $categoryData) {
            $category = RentalCategory::withoutGlobalScope('organization')->create([
                'organization_id' => $organization->id,
                'name' => $categoryData['name'],
                'icon' => $categoryData['icon'],
                'sort_order' => $sortOrder,
                'is_active' => true,
            ]);

            foreach ($categoryData['items'] as $itemSort => $item) {
                Service::withoutGlobalScope('organization')->create([
                    'organization_id' => $organization->id,
                    'service_type' => ServiceType::ItemRental,
                    'rental_category_id' => $category->id,
                    'name' => $item['name'],
                    'brand' => $item['brand'] ?? null,
                    'description' => $item['description'] ?? null,
                    'price' => $item['price_per_day'],
                    'quantity_total' => $item['quantity'] ?? 1,
                    'price_per_day' => $item['price_per_day'],
                    'price_per_day_long' => $item['price_per_day_long'] ?? null,
                    'price_threshold_days' => $item['price_threshold_days'] ?? null,
                    'deposit_amount' => $item['deposit'] ?? null,
                    'metadata' => $item['specifications'] ?? null,
                    'duration_minutes' => 0,
                    'is_active' => true,
                    'sort_order' => $itemSort,
                ]);
            }
        }
    }

    /**
     * @return array<int, array{name: string, icon: string, items: array<int, array<string, mixed>>}>
     */
    private function getCatalog(): array
    {
        return [
            [
                'name' => 'Elektronarzędzia',
                'icon' => 'wrench',
                'items' => [
                    [
                        'name' => 'Wiertarka udarowa',
                        'brand' => 'BOSCH',
                        'description' => 'Profesjonalna wiertarka udarowa do betonu i muru. Uchwyt SDS-Plus, regulacja obrotów.',
                        'price_per_day' => 100,
                        'price_per_day_long' => 80,
                        'price_threshold_days' => 3,
                        'deposit' => 500,
                        'specifications' => [
                            'specs' => [
                                ['label' => 'Moc', 'value' => 800, 'unit' => 'W'],
                                ['label' => 'Waga', 'value' => 4.2, 'unit' => 'kg'],
                            ],
                        ],
                    ],
                    [
                        'name' => 'Szlifierka kątowa 230mm',
                        'brand' => 'MAKITA',
                        'description' => 'Duża szlifierka kątowa do cięcia i szlifowania. Tarcza 230mm, antywibracyjna rękojeść.',
                        'price_per_day' => 80,
                        'price_per_day_long' => 70,
                        'price_threshold_days' => 3,
                        'deposit' => 400,
                        'specifications' => [
                            'specs' => [
                                ['label' => 'Moc', 'value' => 2200, 'unit' => 'W'],
                                ['label' => 'Średnica tarczy', 'value' => 230, 'unit' => 'mm'],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Sprzęt budowlany',
                'icon' => 'building-office',
                'items' => [
                    [
                        'name' => 'Zagęszczarka płytowa 90kg',
                        'brand' => null,
                        'description' => 'Zagęszczarka wibracyjna do podsypki, kostki brukowej i gruntu. Silnik spalinowy.',
                        'price_per_day' => 150,
                        'price_per_day_long' => 120,
                        'price_threshold_days' => 5,
                        'deposit' => 1000,
                        'specifications' => [
                            'specs' => [
                                ['label' => 'Waga', 'value' => 90, 'unit' => 'kg'],
                                ['label' => 'Rodzaj paliwa', 'value' => 'benzyna', 'unit' => null],
                            ],
                        ],
                    ],
                    [
                        'name' => 'Betoniarka 200L',
                        'brand' => null,
                        'description' => 'Betoniarka wolnospadowa 200L. Zasilanie 230V, stabilna podstawa z kołami.',
                        'price_per_day' => 50,
                        'price_per_day_long' => 40,
                        'price_threshold_days' => 5,
                        'deposit' => 300,
                        'specifications' => [
                            'specs' => [
                                ['label' => 'Pojemność', 'value' => 200, 'unit' => 'l'],
                                ['label' => 'Napięcie', 'value' => 230, 'unit' => 'V'],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Agregaty i zasilanie',
                'icon' => 'bolt',
                'items' => [
                    [
                        'name' => 'Agregat prądotwórczy 3kW',
                        'brand' => null,
                        'description' => 'Agregat jednofazowy 3kW. Idealny na budowę, imprezy plenerowe, awarie zasilania.',
                        'price_per_day' => 150,
                        'price_per_day_long' => 120,
                        'price_threshold_days' => 3,
                        'deposit' => 1000,
                        'specifications' => [
                            'specs' => [
                                ['label' => 'Moc', 'value' => 3000, 'unit' => 'W'],
                                ['label' => 'Rodzaj paliwa', 'value' => 'benzyna', 'unit' => null],
                            ],
                        ],
                    ],
                    [
                        'name' => 'Agregat prądotwórczy 5kW',
                        'brand' => null,
                        'description' => 'Agregat trójfazowy 5kW z AVR. Do zasilania maszyn budowlanych i narzędzi.',
                        'price_per_day' => 200,
                        'price_per_day_long' => 160,
                        'price_threshold_days' => 3,
                        'deposit' => 1500,
                        'specifications' => [
                            'specs' => [
                                ['label' => 'Moc', 'value' => 5200, 'unit' => 'W'],
                                ['label' => 'Rodzaj paliwa', 'value' => 'benzyna', 'unit' => null],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Rusztowania',
                'icon' => 'squares-2x2',
                'items' => [
                    [
                        'name' => 'Rusztowanie ramowe — sekcja',
                        'brand' => null,
                        'description' => 'Kompletna sekcja rusztowania ramowego: 2 ramy, 2 pomosty, stężenia, podstawki. Wys. 2m.',
                        'price_per_day' => 50,
                        'price_per_day_long' => 40,
                        'price_threshold_days' => 7,
                        'deposit' => 300,
                        'quantity' => 10,
                        'specifications' => [
                            'specs' => [
                                ['label' => 'Wysokość', 'value' => 2, 'unit' => 'm'],
                                ['label' => 'Szerokość', 'value' => 0.7, 'unit' => 'm'],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Sprzęt ogrodniczy',
                'icon' => 'sun',
                'items' => [
                    [
                        'name' => 'Glebogryzarka spalinowa',
                        'brand' => null,
                        'description' => 'Glebogryzarka z silnikiem spalinowym 6.5 KM. Szerokość robocza 50cm, głębokość do 30cm.',
                        'price_per_day' => 120,
                        'price_per_day_long' => 100,
                        'price_threshold_days' => 3,
                        'deposit' => 800,
                        'specifications' => [
                            'specs' => [
                                ['label' => 'Moc', 'value' => 6.5, 'unit' => 'KM'],
                                ['label' => 'Szerokość robocza', 'value' => 50, 'unit' => 'cm'],
                            ],
                        ],
                    ],
                    [
                        'name' => 'Rębak do gałęzi',
                        'brand' => null,
                        'description' => 'Rębak bębnowy do gałęzi o średnicy do 13cm. Napęd silnikiem spalinowym.',
                        'price_per_day' => 350,
                        'price_per_day_long' => 300,
                        'price_threshold_days' => 3,
                        'deposit' => 2000,
                        'specifications' => [
                            'specs' => [
                                ['label' => 'Moc', 'value' => 50, 'unit' => 'KM'],
                                ['label' => 'Maks. średnica gałęzi', 'value' => 13, 'unit' => 'cm'],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Sprzęt czyszczący',
                'icon' => 'sparkles',
                'items' => [
                    [
                        'name' => 'Myjka ciśnieniowa',
                        'brand' => null,
                        'description' => 'Myjka ciśnieniowa 180 bar z zestawem dysz. Do czyszczenia elewacji, kostki, pojazdów.',
                        'price_per_day' => 100,
                        'price_per_day_long' => 80,
                        'price_threshold_days' => 3,
                        'deposit' => 600,
                        'specifications' => [
                            'specs' => [
                                ['label' => 'Moc', 'value' => 2500, 'unit' => 'W'],
                                ['label' => 'Ciśnienie', 'value' => 180, 'unit' => 'bar'],
                            ],
                        ],
                    ],
                    [
                        'name' => 'Odkurzacz przemysłowy',
                        'brand' => null,
                        'description' => 'Odkurzacz przemysłowy sucho-mokro 30L. Do warsztatu, budowy, sprzątania po remoncie.',
                        'price_per_day' => 80,
                        'price_per_day_long' => 60,
                        'price_threshold_days' => 3,
                        'deposit' => 400,
                        'specifications' => [
                            'specs' => [
                                ['label' => 'Moc', 'value' => 1600, 'unit' => 'W'],
                                ['label' => 'Pojemność', 'value' => 30, 'unit' => 'l'],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Nagrzewnice i osuszacze',
                'icon' => 'fire',
                'items' => [
                    [
                        'name' => 'Nagrzewnica olejowa 20kW',
                        'brand' => null,
                        'description' => 'Nagrzewnica olejowa 20kW z termostatem. Do hal, warsztatów, suszenia tynków.',
                        'price_per_day' => 60,
                        'price_per_day_long' => 45,
                        'price_threshold_days' => 5,
                        'deposit' => 500,
                        'specifications' => [
                            'specs' => [
                                ['label' => 'Moc', 'value' => 20, 'unit' => 'kW'],
                                ['label' => 'Rodzaj paliwa', 'value' => 'olej', 'unit' => null],
                            ],
                        ],
                    ],
                    [
                        'name' => 'Osuszacz powietrza',
                        'brand' => null,
                        'description' => 'Osuszacz kondensacyjny o wydajności 50L/dobę. Do osuszania budynków po zalaniu.',
                        'price_per_day' => 80,
                        'price_per_day_long' => 60,
                        'price_threshold_days' => 5,
                        'deposit' => 600,
                        'specifications' => [
                            'specs' => [
                                ['label' => 'Wydajność', 'value' => 50, 'unit' => 'l/dobę'],
                                ['label' => 'Moc', 'value' => 900, 'unit' => 'W'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
