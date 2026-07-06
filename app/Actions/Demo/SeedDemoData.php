<?php

declare(strict_types=1);

namespace App\Actions\Demo;

use App\Actions\Demo\Seeders\DemoDataSeeder;
use App\Models\Organization;
use RuntimeException;

class SeedDemoData
{
    public function execute(Organization $org, ?string $area = null, bool $force = false): array
    {
        $this->guardProduction();

        $results = [];
        $areas = $this->resolveAreas($area);

        foreach ($areas as $areaKey) {
            $seederClass = config("demo.seeders.{$areaKey}");

            if (! $seederClass || ! class_exists($seederClass)) {
                $results[$areaKey] = 'skipped (no seeder)';

                continue;
            }

            /** @var DemoDataSeeder $seeder */
            $seeder = app($seederClass);

            if ($seeder->hasData($org) && ! $force) {
                $results[$areaKey] = 'skipped (data exists — use --force to overwrite)';

                continue;
            }

            if ($force) {
                $seeder->clear($org);
            }

            $seeder->seed($org);
            $results[$areaKey] = 'seeded';
        }

        return $results;
    }

    public function clear(Organization $org, ?string $area = null): array
    {
        $this->guardProduction();

        $results = [];

        foreach ($this->resolveAreas($area) as $areaKey) {
            $seederClass = config("demo.seeders.{$areaKey}");

            if (! $seederClass || ! class_exists($seederClass)) {
                $results[$areaKey] = 'skipped (no seeder)';

                continue;
            }

            /** @var DemoDataSeeder $seeder */
            $seeder = app($seederClass);
            $seeder->clear($org);
            $results[$areaKey] = 'cleared';
        }

        return $results;
    }

    /**
     * @return array<string, string> area => status
     */
    public function status(Organization $org): array
    {
        $status = [];

        foreach (array_keys(config('demo.seeders', [])) as $areaKey) {
            $seederClass = config("demo.seeders.{$areaKey}");

            if (! $seederClass || ! class_exists($seederClass)) {
                $status[$areaKey] = 'no seeder';

                continue;
            }

            /** @var DemoDataSeeder $seeder */
            $seeder = app($seederClass);
            $areaEnabled = config("demo.areas.{$areaKey}", true);
            $hasData = $seeder->hasData($org);

            $status[$areaKey] = match (true) {
                ! config('demo.enabled') => 'disabled (DEMO_DATA_ENABLED=false)',
                ! $areaEnabled => 'disabled (per-area flag)',
                $hasData => 'active (data present)',
                default => 'enabled (no data yet)',
            };
        }

        return $status;
    }

    private function guardProduction(): void
    {
        if (app()->isProduction() && ! config('demo.allow_in_production', false)) {
            throw new RuntimeException(
                'Demo data seeding is disabled in production. Set demo.allow_in_production=true to override (not recommended).'
            );
        }
    }

    /**
     * @return list<string>
     */
    private function resolveAreas(?string $area): array
    {
        $configured = array_keys(config('demo.seeders', []));

        if ($area !== null) {
            if (! in_array($area, $configured, true)) {
                throw new \InvalidArgumentException("Unknown demo area: '{$area}'. Available: ".implode(', ', $configured));
            }

            return [$area];
        }

        // Only areas enabled in config
        return array_filter(
            $configured,
            fn (string $key) => config("demo.areas.{$key}", true)
        );
    }
}
