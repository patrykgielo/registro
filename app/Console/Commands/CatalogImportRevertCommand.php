<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Catalog\CatalogOperationInProgressException;
use App\Actions\Catalog\RevertCatalogImport;
use App\Actions\Catalog\RevertCommitStateException;
use App\Actions\Catalog\RevertOptions;
use App\Actions\Catalog\RevertPlan;
use App\Actions\Catalog\RevertRefusedException;
use App\Actions\Catalog\RevertResult;
use App\Models\CatalogImportBatch;
use App\Support\Catalog\ConsoleText;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CatalogImportRevertCommand extends Command
{
    protected $signature = 'catalog:import-revert
        {batch : ID paczki importu (catalog:import-status)}
        {--dry-run : Pokaż, co zostałoby usunięte i co blokuje cofnięcie — bez zmian (nie wymaga --force)}
        {--force : Bez pytania o potwierdzenie. WYMAGANE w trybie nieinteraktywnym (-n/cron/pipe) — bez niego odmowa i nic nie jest usuwane}
        {--cleanup-only : Tylko sprzątanie po już cofniętej paczce (status reverted/partially_reverted): pozostałe pliki, mapa strony, z --reset-auto-increment także AUTO_INCREMENT. Nie analizuje i nie usuwa żadnych wierszy, nie wymaga --force}
        {--partial : Cofnij tylko produkty bez blokad, resztę zostaw (domyślnie: wszystko albo nic)}
        {--include-modified : Cofnij także produkty edytowane po imporcie (dodane egzemplarze/stany, zmienione zdjęcia). NIE obejmuje zamówień/wypożyczeń}
        {--discard-cart-items : Usuń także pozycje koszyków tych produktów (tylko cart_items tej paczki)}
        {--reset-auto-increment : Po cofnięciu cofnij AUTO_INCREMENT, ale tylko gdy to bezpieczne (MySQL, brak wierszy >= najniższego id paczki, brak aliasowania w audit_logs); ALTER czeka najwyżej catalog_import.auto_increment_lock_wait_seconds}';

    protected $description = 'Cofa JEDNĄ paczkę catalog:import: usuwa wyłącznie to, co ta paczka utworzyła (ledger), nigdy nic ponadto. Na już cofniętej paczce dokańcza tylko sprzątanie (pliki, mapa strony, AUTO_INCREMENT).';

    public function handle(RevertCatalogImport $revert): int
    {
        $batch = ctype_digit((string) $this->argument('batch')) ? CatalogImportBatch::find((int) $this->argument('batch')) : null;

        if ($batch === null) {
            $this->error('Paczka nie znaleziona: '.ConsoleText::safe((string) $this->argument('batch')));

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
            'force' => (bool) $this->option('force'),
        ]);

        if ($this->option('cleanup-only')) {
            if (! in_array($batch->status, [CatalogImportBatch::STATUS_REVERTED, CatalogImportBatch::STATUS_PARTIALLY_REVERTED], true)) {
                $this->error("--cleanup-only działa tylko na paczce już cofniętej (reverted/partially_reverted); paczka #{$batch->id} ma status '{$batch->status}'. Nic nie zrobiono.");

                return self::FAILURE;
            }

            return $this->finishCleanup($revert, $batch, $options, $dryRun);
        }

        // Already fully reverted: the DB-deleting part stays fatal by design (ids may have been
        // recycled since) — only the idempotent post-commit steps run.
        if ($batch->status === CatalogImportBatch::STATUS_REVERTED) {
            return $this->finishCleanup($revert, $batch, $options, $dryRun);
        }

        // Guard before purge: the whole analysis runs (read-only) before anything is deleted.
        $plan = $revert->analyse($batch, $options);
        $this->printPlan($batch, $plan, $dryRun);

        if (! $plan->canExecute()) {
            $prefix = $dryRun ? '[DRY-RUN] Prawdziwe uruchomienie ZAKOŃCZYŁOBY SIĘ ODMOWĄ. ' : 'Cofnięcie odmówione. ';

            if ($batch->status === CatalogImportBatch::STATUS_PARTIALLY_REVERTED && $plan->fatal === []) {
                // This command does ONE thing: it did not delete anything, and says so truthfully.
                $this->error(rtrim($prefix, '. ').': pozostałe produkty zablokowane — nic z nich nie usunięto.');

                $leftover = $revert->leftoverFileItems($batch)->count();

                if ($leftover > 0) {
                    $this->warn("Po wcześniejszym cofnięciu zostało plików: {$leftover}. Dokończ samo sprzątanie: php artisan catalog:import-revert {$batch->id} --cleanup-only");
                }

                return self::FAILURE;
            }

            $this->error($prefix.'Nic nie zostało usunięte.');

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->info('[DRY-RUN] Brak zmian — tryb podglądu.');

            return self::SUCCESS;
        }

        $force = (bool) $this->option('force');

        // The prompt used to be the only gate, and -n/-q skip it: without --force a non-interactive
        // run is refused (like onboarding:seed-vertical, which needs --force to purge).
        if (! $force && ! $this->input->isInteractive()) {
            $this->error('Tryb nieinteraktywny: dodaj --force. Nic nie zostało usunięte.');
            Log::warning('catalog:import-revert refused: non-interactive without --force', ['batch_id' => $batch->id, 'org_id' => $batch->organization_id]);

            return self::FAILURE;
        }

        Log::warning('catalog:import-revert before purge', [
            'batch_id' => $batch->id,
            'org_id' => $batch->organization_id,
            'services' => count($plan->serviceIds),
            'units' => count($plan->unitIds) + count($plan->foreignUnitIds),
            'staff_assignments' => $plan->staffAssignments,
            'categories_candidates' => count($plan->categoryCandidates),
            'files' => count($plan->fileItemIds),
            'interactive' => $this->input->isInteractive(),
            'force' => $force,
        ]);

        if (! $force && ! $this->confirm('To NIEODWRACALNE. Cofnąć import?')) {
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
        } catch (RevertCommitStateException $e) {
            // The exception came from/after the COMMIT, and a fresh read says it happened (or could not
            // be ruled out): never "rollback", never "nothing was deleted".
            $this->error(ConsoleText::safe($e->getMessage()));
            $this->warn($e->committed
                ? "Cofnięcie ZATWIERDZONE — produkty paczki #{$e->batchId} są USUNIĘTE z bazy; nie wykonano kroków porządkowych. Sprawdź: php artisan catalog:import-status --batch={$e->batchId}; dokończ sprzątanie: php artisan catalog:import-revert {$e->batchId} --cleanup-only"
                : "Wynik: stan nieznany — sprawdź: php artisan catalog:import-status --batch={$e->batchId} (zanim cokolwiek ponowisz); jeśli paczka jest 'reverted'/'partially_reverted', dokończ: php artisan catalog:import-revert {$e->batchId} --cleanup-only");

            return self::FAILURE;
        } catch (\Throwable $e) {
            // A fresh read confirmed the transaction did NOT commit (everything before the commit is
            // rolled back; post-commit steps are guarded and reported below, they never land here).
            $this->error('Cofnięcie nie powiodło się (rollback, nic nie usunięto): '.ConsoleText::safe($e->getMessage()));

            return self::FAILURE;
        }

        if ($result->warnings !== []) {
            $this->warn('Cofnięcie ZATWIERDZONE — produkty paczki #'.$batch->id.' są USUNIĘTE z bazy. Nie powiodły się tylko kroki porządkowe:');
        }

        $this->info("Cofnięto paczkę #{$batch->id}: produkty {$result->services}, egzemplarze {$result->units}, stany {$result->stocks}, przypisania pracowników: {$result->staffAssignments}, kategorie {$result->categoriesDeleted}, pliki {$result->filesDeleted}.");

        $this->printOutcome($result);

        return self::SUCCESS;
    }

    private function finishCleanup(RevertCatalogImport $revert, CatalogImportBatch $batch, RevertOptions $options, bool $dryRun): int
    {
        $leftover = $revert->leftoverFileItems($batch);

        $steps = 'pliki ('.$leftover->count().'), mapa strony'.($options->resetAutoIncrement ? ', AUTO_INCREMENT' : '');
        $this->info(($dryRun ? '[DRY-RUN] ' : '')."Paczka #{$batch->id} już cofnięta — dokańczam sprzątanie: {$steps}.");

        if ($dryRun) {
            return self::SUCCESS;
        }

        try {
            // Always runs, even with no leftover files: forgetting the sitemap is idempotent, and it
            // is exactly what the retry of a failed 'sitemap' step has to do.
            $result = $revert->cleanup($batch, $options);
        } catch (CatalogOperationInProgressException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Sprzątanie paczki #{$batch->id}: usunięto plików {$result->filesDeleted}, mapa strony ".($result->sitemapForgotten ? 'odświeżona' : 'NIE odświeżona').'.');
        $this->printOutcome($result);

        // a retry that did not complete must not look like success to a script
        return $result->warnings === [] ? self::SUCCESS : self::FAILURE;
    }

    private function printOutcome(RevertResult $result): void
    {
        foreach ($result->categoriesKept as $kept) {
            $this->warn('Kategoria zostawiona: '.ConsoleText::safe($kept));
        }
        foreach ($result->filesKept as $kept) {
            $this->warn('Plik zostawiony: '.ConsoleText::safe($kept));
        }
        foreach ($result->autoIncrement as $table => $decision) {
            $line = "{$table} — ".$decision['reason'];

            $this->line(match (true) {
                ! $decision['reset'] => "AUTO_INCREMENT pominięty: {$line}",
                ($decision['clamped'] ?? false) => "AUTO_INCREMENT przycięty przez MySQL: {$line}",
                default => "AUTO_INCREMENT cofnięty: {$line}",
            });
        }
        foreach ($result->warnings as $warning) {
            $this->warn('Krok nieudany: '.ConsoleText::safe($warning['label']).' — '.ConsoleText::safe($warning['message']).'. Ponów: '.$warning['retry']);
        }
    }

    private function printPlan(CatalogImportBatch $batch, RevertPlan $plan, bool $dryRun): void
    {
        $p = $dryRun ? '[DRY-RUN] ' : '';
        $this->info("{$p}Paczka #{$batch->id}, organizacja #{$batch->organization_id}, status: {$batch->status}");

        foreach ($plan->fatal as $fatal) {
            $this->error("{$p}BŁĄD KRYTYCZNY: ".ConsoleText::safe($fatal));
        }

        if ($plan->blocked !== []) {
            $this->warn("{$p}Zablokowane produkty:");
            $this->table(['ID', 'Nazwa', 'Twarde blokady', 'Można nadpisać flagą'], array_map(
                fn (int $id, array $b) => [$id, ConsoleText::safe($b['name']), implode('; ', $b['hard']) ?: '-', implode('; ', array_map(ConsoleText::safe(...), $b['soft'])) ?: '-'],
                array_keys($plan->blocked),
                $plan->blocked
            ));
        }

        if ($plan->fatal === []) {
            $this->line("{$p}Do usunięcia: produkty ".count($plan->serviceIds)
                .', egzemplarze '.(count($plan->unitIds) + count($plan->foreignUnitIds))
                .', stany '.(count($plan->stockIds) + count($plan->foreignStockIds))
                .', przypisania pracowników: '.$plan->staffAssignments
                .', pozycje koszyków '.$plan->cartItems
                .', kategorie (kandydaci) '.count($plan->categoryCandidates)
                .', pliki '.count($plan->fileItemIds));

            if ($plan->goneServiceIds !== []) {
                $this->line("{$p}Produkty z ledgera, których już nie ma (usunięte ręcznie): ".count($plan->goneServiceIds));
            }

            foreach ($plan->categoriesKept as $kept) {
                $this->line("{$p}Kategoria zostanie zostawiona: ".ConsoleText::safe($kept));
            }
        }
    }
}
