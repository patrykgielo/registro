<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Catalog\ImportCatalog;
use App\Actions\Catalog\ImportOptions;
use App\Models\Organization;
use App\Support\Catalog\ValidationReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CatalogImportCommand extends Command
{
    protected $signature = 'catalog:import
        {organization : ID lub slug organizacji}
        {file : Ścieżka do pliku JSON z katalogiem}
        {--dry-run : Waliduj cały plik (łącznie z obrazami) i pokaż, co zostałoby utworzone — bez zapisu}
        {--skip-existing : Pomiń produkty, których external_id jest już w niecofniętej paczce (domyślnie to błąd)}
        {--images=required : required (błąd obrazu = błąd importu) | optional (ostrzeżenie, produkt bez obrazu) | skip (ignoruj obrazy)}
        {--image-delay= : Minimalna przerwa (ms) między żądaniami do tego samego hosta (domyślnie z config/catalog_import.php)}
        {--no-image-check : Razem z --dry-run: nie pobieraj obrazów}
        {--actor= : Opis operatora zapisany w paczce (np. e-mail)}';

    protected $description = 'Importuje katalog sprzętu (produkty wypożyczeń) z pliku JSON — wszystko albo nic. Cofnięcie: catalog:import-revert.';

    public function handle(ImportCatalog $import): int
    {
        $org = $this->resolveOrganization((string) $this->argument('organization'));

        if ($org === null) {
            $this->error('Organizacja nie znaleziona: '.$this->argument('organization'));

            return self::FAILURE;
        }

        $images = (string) $this->option('images');

        if (! in_array($images, ['required', 'optional', 'skip'], true)) {
            $this->error('--images musi być jednym z: required, optional, skip');

            return self::INVALID;
        }

        $delay = $this->option('image-delay');

        if ($delay !== null && ! ctype_digit((string) $delay)) {
            $this->error('--image-delay musi być liczbą milisekund');

            return self::INVALID;
        }

        $dryRun = (bool) $this->option('dry-run');
        $prefix = $dryRun ? '[DRY-RUN] ' : '';

        Log::info('catalog:import start', [
            'org_id' => $org->id,
            'file' => basename((string) $this->argument('file')),
            'dry_run' => $dryRun,
            'images' => $images,
            'interactive' => $this->input->isInteractive(),
        ]);

        try {
            $result = $import->run($org, (string) $this->argument('file'), new ImportOptions(
                dryRun: $dryRun,
                skipExisting: (bool) $this->option('skip-existing'),
                images: $images,
                checkImagesInDryRun: ! $this->option('no-image-check'),
                imageDelayMs: $delay !== null ? (int) $delay : (int) config('catalog_import.image_delay_ms', 1000),
                actor: $this->option('actor') ?: null,
            ));
        } catch (\Throwable $e) {
            Log::error('catalog:import failed', ['org_id' => $org->id, 'exception' => $e->getMessage()]);
            $this->error('Import nie powiódł się (nic nie zapisano): '.$e->getMessage());

            return self::FAILURE;
        }

        $this->printReport($result->report);

        if (! $result->ok()) {
            $this->error($prefix.'Plik NIE przeszedł walidacji — nic nie zostało zapisane.');

            return self::FAILURE;
        }

        $c = $result->counts;
        $this->info("{$prefix}Organizacja: {$org->name} (ID: {$org->id})");
        $this->info("{$prefix}Produkty do dodania: {$c['products']}, pominięte: {$c['skipped']}");
        $this->info("{$prefix}Kategorie: {$c['categories_created']} nowych, {$c['categories_reused']} istniejących");
        $this->info("{$prefix}Obrazy: {$c['images']}, egzemplarze: {$c['units']}");

        if ($dryRun) {
            $this->info('[DRY-RUN] Brak zmian — tryb podglądu.');
        } elseif ($result->batch !== null) {
            $this->info("Gotowe. Paczka #{$result->batch->id} — cofnięcie: php artisan catalog:import-revert {$result->batch->id}");
        } else {
            $this->info('Nic do zaimportowania.');
        }

        return self::SUCCESS;
    }

    private function printReport(ValidationReport $report): void
    {
        if ($report->warnings() !== []) {
            $this->warn('Ostrzeżenia:');
            $this->table(['Produkt', 'external_id', 'Pole', 'Komunikat'], $report->rows($report->warnings()));
        }

        if ($report->hasErrors()) {
            $this->error('Błędy ('.count($report->errors()).'):');
            $this->table(['Produkt', 'external_id', 'Pole', 'Komunikat'], $report->rows($report->errors()));
        }
    }

    private function resolveOrganization(string $identifier): ?Organization
    {
        if (ctype_digit($identifier)) {
            return Organization::find((int) $identifier);
        }

        return Organization::where('slug', $identifier)->first();
    }
}
