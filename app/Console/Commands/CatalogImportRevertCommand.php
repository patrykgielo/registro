<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Catalog\CatalogOperationInProgressException;
use App\Actions\Catalog\RevertCatalogImport;
use App\Actions\Catalog\RevertOptions;
use App\Actions\Catalog\RevertPlan;
use App\Actions\Catalog\RevertRefusedException;
use App\Models\CatalogImportBatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CatalogImportRevertCommand extends Command
{
    protected $signature = 'catalog:import-revert
        {batch : ID paczki importu (catalog:import-status)}
        {--dry-run : Pokaż, co zostałoby usunięte i co blokuje cofnięcie — bez zmian}
        {--partial : Cofnij tylko produkty bez blokad, resztę zostaw (domyślnie: wszystko albo nic)}
        {--include-modified : Cofnij także produkty edytowane po imporcie (dodane egzemplarze/stany, zmienione zdjęcia). NIE obejmuje zamówień/wypożyczeń}
        {--discard-cart-items : Usuń także pozycje koszyków tych produktów (tylko cart_items tej paczki)}
        {--reset-auto-increment : Po cofnięciu cofnij AUTO_INCREMENT, ale tylko gdy to bezpieczne (MySQL, brak wierszy >= najniższego id paczki, brak aliasowania w audit_logs)}';

    protected $description = 'Cofa JEDNĄ paczkę catalog:import: usuwa wyłącznie to, co ta paczka utworzyła (ledger), nigdy nic ponadto.';

    public function handle(RevertCatalogImport $revert): int
    {
        $batch = ctype_digit((string) $this->argument('batch')) ? CatalogImportBatch::find((int) $this->argument('batch')) : null;

        if ($batch === null) {
            $this->error('Paczka nie znaleziona: '.$this->argument('batch'));

            return self::FAILURE;
        }

        $options = new RevertOptions(
            partial: (bool) $this->option('partial'),
            includeModified: (bool) $this->option('include-modified'),
            discardCartItems: (bool) $this->option('discard-cart-items'),
            resetAutoIncrement: (bool) $this->option('reset-auto-increment'),
        );

        $dryRun = (bool) $this->option('dry-run');

        Log::info('catalog:import-revert start', [
            'batch_id' => $batch->id,
            'org_id' => $batch->organization_id,
            'dry_run' => $dryRun,
            'partial' => $options->partial,
            'include_modified' => $options->includeModified,
            'discard_cart_items' => $options->discardCartItems,
        ]);

        // Guard before purge: the whole analysis runs (read-only) before anything is deleted.
        $plan = $revert->analyse($batch, $options);
        $this->printPlan($batch, $plan, $dryRun);

        if (! $plan->canExecute()) {
            $this->error(($dryRun ? '[DRY-RUN] Prawdziwe uruchomienie ZAKOŃCZYŁOBY SIĘ ODMOWĄ. ' : 'Cofnięcie odmówione. ').'Nic nie zostało usunięte.');

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->info('[DRY-RUN] Brak zmian — tryb podglądu.');

            return self::SUCCESS;
        }

        Log::warning('catalog:import-revert before purge', [
            'batch_id' => $batch->id,
            'org_id' => $batch->organization_id,
            'services' => count($plan->serviceIds),
            'units' => count($plan->unitIds) + count($plan->foreignUnitIds),
            'categories_candidates' => count($plan->categoryCandidates),
            'files' => count($plan->fileItemIds),
            'interactive' => $this->input->isInteractive(),
        ]);

        if ($this->input->isInteractive() && ! $this->confirm('To NIEODWRACALNE. Cofnąć import?')) {
            $this->line('Anulowano.');

            return self::FAILURE;
        }

        try {
            $result = $revert->execute($batch, $options);
        } catch (CatalogOperationInProgressException $e) {
            $this->error($e->getMessage().' Nic nie zostało usunięte.');

            return self::FAILURE;
        } catch (RevertRefusedException $e) {
            $this->printPlan($batch, $e->plan, false);
            $this->error('Cofnięcie odmówione — stan zmienił się od analizy. Nic nie zostało usunięte.');

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error('Cofnięcie nie powiodło się (rollback): '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Cofnięto paczkę #{$batch->id}: produkty {$result->services}, egzemplarze {$result->units}, stany {$result->stocks}, kategorie {$result->categoriesDeleted}, pliki {$result->filesDeleted}.");

        foreach ($result->categoriesKept as $kept) {
            $this->warn("Kategoria zostawiona: {$kept}");
        }
        foreach ($result->filesKept as $kept) {
            $this->warn("Plik zostawiony: {$kept}");
        }
        foreach ($result->autoIncrement as $table => $decision) {
            $this->line(($decision['reset'] ? 'AUTO_INCREMENT cofnięty: ' : 'AUTO_INCREMENT pominięty: ')."{$table} — {$decision['reason']}");
        }

        return self::SUCCESS;
    }

    private function printPlan(CatalogImportBatch $batch, RevertPlan $plan, bool $dryRun): void
    {
        $p = $dryRun ? '[DRY-RUN] ' : '';
        $this->info("{$p}Paczka #{$batch->id}, organizacja #{$batch->organization_id}, status: {$batch->status}");

        foreach ($plan->fatal as $fatal) {
            $this->error("{$p}BŁĄD KRYTYCZNY: {$fatal}");
        }

        if ($plan->blocked !== []) {
            $this->warn("{$p}Zablokowane produkty:");
            $this->table(['ID', 'Nazwa', 'Twarde blokady', 'Można nadpisać flagą'], array_map(
                fn (int $id, array $b) => [$id, $b['name'], implode('; ', $b['hard']) ?: '-', implode('; ', $b['soft']) ?: '-'],
                array_keys($plan->blocked),
                $plan->blocked
            ));
        }

        if ($plan->fatal === []) {
            $this->line("{$p}Do usunięcia: produkty ".count($plan->serviceIds)
                .', egzemplarze '.(count($plan->unitIds) + count($plan->foreignUnitIds))
                .', stany '.(count($plan->stockIds) + count($plan->foreignStockIds))
                .', pozycje koszyków '.$plan->cartItems
                .', kategorie (kandydaci) '.count($plan->categoryCandidates)
                .', pliki '.count($plan->fileItemIds));

            if ($plan->goneServiceIds !== []) {
                $this->line("{$p}Produkty z ledgera, których już nie ma (usunięte ręcznie): ".count($plan->goneServiceIds));
            }

            foreach ($plan->categoriesKept as $kept) {
                $this->line("{$p}Kategoria zostanie zostawiona: {$kept}");
            }
        }
    }
}
