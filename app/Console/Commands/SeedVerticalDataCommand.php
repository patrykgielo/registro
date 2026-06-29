<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Onboarding\Seeders\VerticalSeeder;
use App\Enums\Industry;
use App\Models\Organization;
use App\Models\RentalCategory;
use App\Models\Service;
use Illuminate\Console\Command;

class SeedVerticalDataCommand extends Command
{
    protected $signature = 'onboarding:seed-vertical
        {organization : ID lub slug organizacji}
        {--industry= : Nadpisz industry (equipment_rental|auto_detailing|general_services)}
        {--force : Seeduj mimo istniejących usług}';

    protected $description = 'Ładuje przykładowe dane branżowe (usługi/kategorie) dla organizacji. Tylko ręcznie — NIE jest wywoływane automatycznie podczas onboardingu.';

    public function handle(): int
    {
        $org = $this->resolveOrganization($this->argument('organization'));

        if ($org === null) {
            $this->error('Organizacja nie znaleziona: '.$this->argument('organization'));

            return self::FAILURE;
        }

        $industry = $this->resolveIndustry($org);

        if ($industry === null) {
            $this->error('Brak industry dla organizacji. Ustaw industry lub podaj --industry=...');

            return self::FAILURE;
        }

        if ($this->hasExistingData($org)) {
            if (! $this->option('force')) {
                $this->error("Organizacja \"{$org->name}\" ma już usługi lub kategorie.");
                $this->line('Użyj --force aby usunąć istniejące dane i seedować od nowa.');

                return self::FAILURE;
            }

            $this->purgeExistingData($org);
            $this->warn('Usunięto istniejące usługi i kategorie (--force).');
        }

        $seederClass = $industry->seederClass();
        $seeder = app($seederClass);

        if (! $seeder instanceof VerticalSeeder) {
            $this->error("Seeder {$seederClass} nie implementuje VerticalSeeder.");

            return self::FAILURE;
        }

        $servicesBefore = Service::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->count();

        $categoriesBefore = RentalCategory::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->count();

        $seeder->seed($org);

        $servicesAdded = Service::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->count() - $servicesBefore;

        $categoriesAdded = RentalCategory::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->count() - $categoriesBefore;

        $this->info("Branża: {$industry->label()} ({$industry->value})");
        $this->info("Organizacja: {$org->name} (ID: {$org->id})");
        $this->info("Dodano usług: {$servicesAdded}");

        if ($categoriesAdded > 0) {
            $this->info("Dodano kategorii: {$categoriesAdded}");
        }

        return self::SUCCESS;
    }

    private function resolveOrganization(string $identifier): ?Organization
    {
        if (is_numeric($identifier)) {
            return Organization::find((int) $identifier);
        }

        return Organization::where('slug', $identifier)->first();
    }

    private function resolveIndustry(Organization $org): ?Industry
    {
        $override = $this->option('industry');

        if ($override !== null) {
            $industry = Industry::tryFrom($override);

            if ($industry === null) {
                $this->error("Nieprawidłowa wartość industry: {$override}");

                return null;
            }

            return $industry;
        }

        return $org->industry;
    }

    private function purgeExistingData(Organization $org): void
    {
        Service::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->delete();

        RentalCategory::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->delete();
    }

    private function hasExistingData(Organization $org): bool
    {
        return Service::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->exists()
            || RentalCategory::withoutGlobalScope('organization')
                ->where('organization_id', $org->id)
                ->exists();
    }
}
