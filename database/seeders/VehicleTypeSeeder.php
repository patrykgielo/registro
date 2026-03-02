<?php

namespace Database\Seeders;

use App\Models\VehicleType;
use Illuminate\Database\Seeder;

class VehicleTypeSeeder extends Seeder
{
    /**
     * Seed vehicle types for booking wizard (production lookup data).
     *
     * Creates 5 vehicle categories with Polish names and examples:
     * - Auto miejskie (City car) - Toyota Aygo, Fiat 500
     * - Auto małe (Small car) - VW Polo, Ford Fiesta
     * - Auto średnie (Medium car) - VW Golf, Toyota Corolla
     * - Auto duże (Large car) - BMW 5, Audi A6, Mercedes E
     * - SUV/Crossover - Toyota RAV4, VW Tiguan
     *
     * This seeder is idempotent - can be run multiple times safely.
     */
    public function run(): void
    {
        $types = [
            [
                'name' => 'Auto miejskie',
                'slug' => 'city_car',
                'description' => 'Najmniejsze auta przeznaczone głównie do jazdy po mieście. Zwinne, łatwe w parkowaniu, niskie spalanie.',
                'examples' => 'Toyota Aygo, Fiat 500, Hyundai i10, Kia Picanto',
                'sort_order' => 1,
                'is_active' => true,
            ],
            [
                'name' => 'Auto małe',
                'slug' => 'small_car',
                'description' => 'Małe samochody osobowe – nadal kompaktowe, ale bardziej komfortowe. Dobre na miasto i krótsze trasy.',
                'examples' => 'VW Polo, Ford Fiesta, Renault Clio, Skoda Fabia',
                'sort_order' => 2,
                'is_active' => true,
            ],
            [
                'name' => 'Auto średnie',
                'slug' => 'medium_car',
                'description' => 'Samochody klasy kompakt lub rodzinne. Więcej miejsca dla pasażerów i bagażu, uniwersalne na co dzień.',
                'examples' => 'Toyota Corolla, VW Golf, Hyundai i30, Skoda Octavia',
                'sort_order' => 3,
                'is_active' => true,
            ],
            [
                'name' => 'Auto duże / SUV / Van',
                'slug' => 'large_car',
                'description' => 'Większe samochody, SUV-y, kombi lub vany – idealne dla rodzin lub osób potrzebujących przestrzeni.',
                'examples' => 'Toyota RAV4, Kia Sportage, VW Passat, Ford S-Max',
                'sort_order' => 4,
                'is_active' => true,
            ],
            [
                'name' => 'Auto dostawcze',
                'slug' => 'delivery_van',
                'description' => 'Samochody przeznaczone do przewozu towarów lub sprzętu, często używane w działalności gospodarczej.',
                'examples' => 'Renault Trafic, Ford Transit, Mercedes Sprinter, Fiat Ducato',
                'sort_order' => 5,
                'is_active' => true,
            ],
        ];

        foreach ($types as $type) {
            VehicleType::updateOrCreate(
                ['slug' => $type['slug']],
                $type
            );
        }

        $this->command->info('✅ Vehicle types seeded successfully!');
    }
}
