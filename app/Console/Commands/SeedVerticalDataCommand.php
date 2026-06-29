<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Onboarding\Seeders\VerticalSeeder;
use App\Enums\Industry;
use App\Models\Organization;
use App\Models\RentalCategory;
use App\Models\Service;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SeedVerticalDataCommand extends Command
{
    protected $signature = 'onboarding:seed-vertical
        {organization : ID lub slug organizacji}
        {--industry= : Nadpisz industry (equipment_rental|auto_detailing|general_services)}
        {--force : Seeduj mimo istniejących usług — wymagane gdy org ma już dane}
        {--dry-run : Pokaż co zostanie usunięte/zaseedowane bez zmian}';

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

        // Validate seeder BEFORE any destructive operation.
        // A missing or broken seeder (e.g. a new Industry case without a VerticalSeeder impl)
        // must never trigger purge of existing catalogue data.
        $seederClass = $industry->seederClass();
        $seeder = app($seederClass);

        if (! $seeder instanceof VerticalSeeder) {
            $this->error("Seeder {$seederClass} nie implementuje VerticalSeeder.");

            return self::FAILURE;
        }

        // Audit log — operacja kasująca dane biznesowe wymaga śladu (GDPR art. 5(1)(f))
        Log::info('onboarding:seed-vertical start', [
            'org_id' => $org->id,
            'org_name' => $org->name,
            'industry' => $industry->value,
            'force' => (bool) $this->option('force'),
            'dry_run' => (bool) $this->option('dry-run'),
        ]);

        if ($this->option('dry-run')) {
            return $this->runDryRun($org, $industry);
        }

        $needsPurge = false;

        if ($this->hasExistingData($org)) {
            if (! $this->option('force')) {
                $this->error("Organizacja \"{$org->name}\" ma już usługi lub kategorie.");
                $this->line('Użyj --force aby usunąć istniejące dane i seedować od nowa.');

                return self::FAILURE;
            }

            $needsPurge = true;
            $existingServices = Service::withoutGlobalScope('organization')->where('organization_id', $org->id)->count();
            $existingCategories = RentalCategory::withoutGlobalScope('organization')->where('organization_id', $org->id)->count();

            Log::warning('onboarding:seed-vertical purge', [
                'org_id' => $org->id,
                'org_name' => $org->name,
                'services_to_delete' => $existingServices,
                'categories_to_delete' => $existingCategories,
                'interactive' => $this->input->isInteractive(),
            ]);

            $this->warn("Zostanie usuniętych: {$existingServices} usług i {$existingCategories} kategorii dla \"{$org->name}\" (ID: {$org->id}).");

            if ($this->input->isInteractive() && ! $this->confirm('To NIEODWRACALNE. Kontynuować?')) {
                $this->line('Anulowano.');

                return self::FAILURE;
            }
        }

        // Purge (if needed) and seed in a single transaction.
        // Prevents a failed seed from leaving the org with an empty catalogue and no retry path.
        try {
            DB::transaction(function () use ($org, $seeder, $needsPurge) {
                if ($needsPurge) {
                    $this->purgeExistingData($org);
                }
                $seeder->seed($org);
            });
        } catch (\Throwable $e) {
            Log::error('onboarding:seed-vertical transaction failed — rolled back', [
                'org_id' => $org->id,
                'exception' => $e->getMessage(),
            ]);
            $this->error('Seed nie powiódł się (rollback): '.$e->getMessage());

            return self::FAILURE;
        }

        Log::info('onboarding:seed-vertical completed', [
            'org_id' => $org->id,
            'purge_done' => $needsPurge,
        ]);

        if ($needsPurge) {
            $this->warn('Usunięto istniejące usługi i kategorie (--force).');
        }

        $serviceCount = Service::withoutGlobalScope('organization')->where('organization_id', $org->id)->count();
        $categoryCount = RentalCategory::withoutGlobalScope('organization')->where('organization_id', $org->id)->count();

        $this->info("Branża: {$industry->label()} ({$industry->value})");
        $this->info("Organizacja: {$org->name} (ID: {$org->id})");
        $this->info("Dodano usług: {$serviceCount}");

        if ($categoryCount > 0) {
            $this->info("Dodano kategorii: {$categoryCount}");
        }

        return self::SUCCESS;
    }

    private function runDryRun(Organization $org, Industry $industry): int
    {
        $this->info("[DRY-RUN] Org: {$org->name} (ID: {$org->id})");
        $this->info("[DRY-RUN] Branża: {$industry->label()} ({$industry->value})");

        if (! $this->option('force') && $this->hasExistingData($org)) {
            $this->warn('[DRY-RUN] Prawdziwe uruchomienie ZAKOŃCZYŁOBY SIĘ BŁĘDEM — org ma dane, brak --force.');

            return self::FAILURE;
        }

        if ($this->option('force') && $this->hasExistingData($org)) {
            $services = Service::withoutGlobalScope('organization')->where('organization_id', $org->id)->count();
            $categories = RentalCategory::withoutGlobalScope('organization')->where('organization_id', $org->id)->count();
            $this->warn("[DRY-RUN] Zostałoby usuniętych: {$services} usług, {$categories} kategorii.");
        }

        $this->info('[DRY-RUN] Brak zmian — tryb podglądu.');

        return self::SUCCESS;
    }

    private function resolveOrganization(string $identifier): ?Organization
    {
        // ctype_digit rejects float strings ("1.5"), hex ("0x1A"), and negative ("-1")
        // that is_numeric() would incorrectly treat as numeric IDs.
        if (ctype_digit($identifier)) {
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

    /**
     * Purges all vertical-seed data for the given organization.
     *
     * NOTE: If you add a seeder that creates models other than Service / RentalCategory
     * (e.g. Page, Post, Promotion), update BOTH this method AND hasExistingData() to
     * include those models — otherwise --force will leave orphaned records behind.
     */
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
