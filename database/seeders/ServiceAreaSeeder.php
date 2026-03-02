<?php

namespace Database\Seeders;

use App\Models\ServiceArea;
use Illuminate\Database\Seeder;

class ServiceAreaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $areas = [
            [
                'city_name' => 'Warszawa',
                'latitude' => 52.2297,
                'longitude' => 21.0122,
                'radius_km' => 50,
                'description' => 'Greater Warsaw metropolitan area including suburbs',
                'color_hex' => '#4CAF50',
                'sort_order' => 1,
                'is_active' => true,
            ],
            [
                'city_name' => 'Kraków',
                'latitude' => 50.0647,
                'longitude' => 19.9450,
                'radius_km' => 30,
                'description' => 'Kraków city and surrounding areas',
                'color_hex' => '#2196F3',
                'sort_order' => 2,
                'is_active' => true,
            ],
            [
                'city_name' => 'Gdańsk',
                'latitude' => 54.3520,
                'longitude' => 18.6466,
                'radius_km' => 40,
                'description' => 'Tri-City area (Gdańsk, Gdynia, Sopot)',
                'color_hex' => '#FF9800',
                'sort_order' => 3,
                'is_active' => true,
            ],
        ];

        foreach ($areas as $area) {
            ServiceArea::updateOrCreate(
                [
                    'city_name' => $area['city_name'],
                ],
                $area
            );
        }
    }
}
