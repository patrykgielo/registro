<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Demo\SeedDemoData;
use App\Models\Organization;
use Illuminate\Console\Command;

class SeedDemoDataCommand extends Command
{
    protected $signature = 'demo:seed
                            {area? : Specific area to seed (e.g. analytics). Omit to seed all enabled areas.}
                            {--org= : Organization ID. Defaults to the first organization.}
                            {--force : Overwrite existing demo data}
                            {--clear : Clear demo data instead of seeding}
                            {--status : Show demo data status per area}';

    protected $description = 'Seed or manage demo/sample data per area (requires DEMO_DATA_ENABLED=true)';

    public function handle(SeedDemoData $action): int
    {
        if (app()->isProduction()) {
            $this->error('Cannot run demo:seed in production.');

            return self::FAILURE;
        }

        if (! config('demo.enabled')) {
            $this->error('Demo data is disabled. Set DEMO_DATA_ENABLED=true in .env to continue.');

            return self::FAILURE;
        }

        $org = $this->resolveOrg();
        if (! $org) {
            return self::FAILURE;
        }

        $area = $this->argument('area');

        if ($this->option('status')) {
            return $this->showStatus($action, $org);
        }

        if ($this->option('clear')) {
            return $this->runClear($action, $org, $area);
        }

        return $this->runSeed($action, $org, $area);
    }

    private function runSeed(SeedDemoData $action, Organization $org, ?string $area): int
    {
        $this->info("Seeding demo data for org: {$org->name} (#{$org->id})");

        try {
            $results = $action->execute($org, $area, (bool) $this->option('force'));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($results as $areaKey => $status) {
            $icon = str_starts_with($status, 'seeded') ? '✓' : '–';
            $this->line("  {$icon} {$areaKey}: {$status}");
        }

        return self::SUCCESS;
    }

    private function runClear(SeedDemoData $action, Organization $org, ?string $area): int
    {
        $this->info("Clearing demo data for org: {$org->name} (#{$org->id})");

        $results = $action->clear($org, $area);

        foreach ($results as $areaKey => $status) {
            $this->line("  – {$areaKey}: {$status}");
        }

        return self::SUCCESS;
    }

    private function showStatus(SeedDemoData $action, Organization $org): int
    {
        $this->line("Demo data status for org: {$org->name} (#{$org->id})");
        $this->line('DEMO_DATA_ENABLED='.(config('demo.enabled') ? 'true' : 'false'));
        $this->newLine();

        foreach ($action->status($org) as $areaKey => $status) {
            $this->line("  {$areaKey}: {$status}");
        }

        return self::SUCCESS;
    }

    private function resolveOrg(): ?Organization
    {
        $id = $this->option('org');

        if ($id) {
            $org = Organization::find($id);
            if (! $org) {
                $this->error("Organization #{$id} not found.");

                return null;
            }

            return $org;
        }

        $org = Organization::first();
        if (! $org) {
            $this->error('No organizations found. Create one first.');

            return null;
        }

        return $org;
    }
}
