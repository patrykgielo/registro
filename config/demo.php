<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Demo Data
    |--------------------------------------------------------------------------
    |
    | When enabled, the application can load realistic sample data per area.
    | This flag must be explicitly set to true — it defaults to false everywhere,
    | including production (which has an additional hard guard in SeedDemoData).
    |
    | Set DEMO_DATA_ENABLED=true in .env.local or .env.staging to activate.
    | NEVER set this in .env.production.
    |
    */

    'enabled' => env('DEMO_DATA_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Per-Area Overrides
    |--------------------------------------------------------------------------
    |
    | When demo.enabled is true, each area can still be individually toggled.
    | Add a new entry here when adding a new demo seeder.
    |
    */

    'areas' => [
        'analytics' => env('DEMO_ANALYTICS', true),
        // 'orders'  => env('DEMO_ORDERS', false),   // future
        // 'stats'   => env('DEMO_STATS', false),     // future
    ],

    /*
    |--------------------------------------------------------------------------
    | Seeder Map  (area => seeder class)
    |--------------------------------------------------------------------------
    */

    'seeders' => [
        'analytics' => \App\Actions\Demo\Seeders\AnalyticsDemoSeeder::class,
    ],

];
