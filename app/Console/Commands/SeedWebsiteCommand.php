<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Onboarding\SeedTenantWebsite;
use App\Models\Organization;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SeedWebsiteCommand extends Command
{
    protected $signature = 'onboarding:seed-website
        {organization : ID lub slug organizacji}
        {--force : Nadpisz istniejące strony — usuwa WSZYSTKIE strony CMS organizacji}
        {--dry-run : Pokaż co zostanie utworzone/usunięte bez zmian}';

    protected $description = 'Tworzy uniwersalną stronę główną i minimalne menu (dane czytane z organizacji w czasie uruchomienia). Uruchom ręcznie na każdym nowym tenancie.';

    public function __construct(private readonly SeedTenantWebsite $seeder)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $org = $this->resolveOrganization($this->argument('organization'));

        if ($org === null) {
            $this->error('Organizacja nie znaleziona: '.$this->argument('organization'));

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            return $this->runDryRun($org);
        }

        $needsPurge = false;

        if ($this->seeder->hasExistingPages($org)) {
            if (! $this->option('force')) {
                $this->error("Organizacja \"{$org->name}\" ma już strony CMS.");
                $this->line('Użyj --force aby usunąć WSZYSTKIE istniejące strony i zaseedować od nowa.');

                return self::FAILURE;
            }

            $needsPurge = true;
            $existingCount = $this->seeder->existingPageCount($org);

            Log::warning('onboarding:seed-website purge', [
                'org_id' => $org->id,
                'org_name' => $org->name,
                'pages_to_delete' => $existingCount,
                'interactive' => $this->input->isInteractive(),
            ]);

            $this->warn("Zostanie usuniętych: {$existingCount} istniejących stron (WSZYSTKICH, nie tylko wygenerowanych przez ten seeder) dla \"{$org->name}\" (ID: {$org->id}).");

            if ($this->input->isInteractive() && ! $this->confirm('To NIEODWRACALNE. Kontynuować?')) {
                $this->line('Anulowano.');

                return self::FAILURE;
            }
        }

        Log::info('onboarding:seed-website start', [
            'org_id' => $org->id,
            'org_name' => $org->name,
            'force' => (bool) $this->option('force'),
        ]);

        try {
            $homepage = DB::transaction(function () use ($org, $needsPurge) {
                if ($needsPurge) {
                    $this->seeder->purge($org);
                }

                return $this->seeder->seed($org);
            });
        } catch (\Throwable $e) {
            Log::error('onboarding:seed-website transaction failed — rolled back', [
                'org_id' => $org->id,
                'exception' => $e->getMessage(),
            ]);
            $this->error('Seed nie powiódł się (rollback): '.$e->getMessage());

            return self::FAILURE;
        }

        Log::info('onboarding:seed-website completed', [
            'org_id' => $org->id,
            'purge_done' => $needsPurge,
            'homepage_page_id' => $homepage->id,
        ]);

        if ($needsPurge) {
            $this->warn('Usunięto istniejące strony (--force).');
        }

        $this->info("Organizacja: {$org->name} (ID: {$org->id})");
        $this->info("Strona główna: \"{$homepage->title}\" (/{$homepage->slug}, ustawiona jako cms.homepage_page_id)");
        $this->info('Utworzono stronę "O nas" (/o-nas, w menu).');

        if ($org->supportsRentals()) {
            $this->info('Utworzono link menu "Wypożyczalnia" (/wypozyczalnia).');
        }

        return self::SUCCESS;
    }

    private function runDryRun(Organization $org): int
    {
        $this->info("[DRY-RUN] Org: {$org->name} (ID: {$org->id})");

        if (! $this->option('force') && $this->seeder->hasExistingPages($org)) {
            $this->warn('[DRY-RUN] Prawdziwe uruchomienie ZAKOŃCZYŁOBY SIĘ BŁĘDEM — org ma już strony, brak --force.');

            return self::FAILURE;
        }

        if ($this->option('force') && $this->seeder->hasExistingPages($org)) {
            $existingCount = $this->seeder->existingPageCount($org);
            $this->warn("[DRY-RUN] Zostałoby usuniętych: {$existingCount} istniejących stron.");
        }

        $this->info('[DRY-RUN] Zostałaby utworzona strona główna + strona "O nas" w menu.');

        if ($org->supportsRentals()) {
            $this->info('[DRY-RUN] Zostałby dodany link menu "Wypożyczalnia" (/wypozyczalnia).');
        }

        $this->info('[DRY-RUN] Brak zmian — tryb podglądu.');

        return self::SUCCESS;
    }

    private function resolveOrganization(string $identifier): ?Organization
    {
        if (ctype_digit($identifier)) {
            return Organization::find((int) $identifier);
        }

        return Organization::where('slug', $identifier)->first();
    }
}
