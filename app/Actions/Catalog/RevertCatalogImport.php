<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Enums\ServiceType;
use App\Models\CatalogImportBatch;
use App\Models\CatalogImportItem;
use App\Models\RentalCategory;
use App\Models\Service;
use App\Models\ServiceLocationStock;
use App\Models\ServiceUnit;
use App\Support\Catalog\AutoIncrementResetPlanner;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Reverts ONE import batch — and nothing else.
 *
 * Scope is the ledger (`catalog_import_items`, origin=created) and never "all rows of the
 * organization / newer than X / in category Y". Before anything is deleted analyse()
 * proves, per ledger row, that it still is what the ledger says (same organization_id,
 * name, slug, service_id) — an id re-used after an AUTO_INCREMENT reset, or a row that
 * now belongs to someone else, aborts the WHOLE revert (fatal, not overridable).
 *
 * Cascades reachable from the deleted tables (FK ON DELETE, verified on MySQL):
 *   services -> service_units, service_location_stocks, service_staff, appointments (CASCADE);
 *               cart_items, order_items, rentals (RESTRICT);
 *   service_units -> order_items.service_unit_id (SET NULL);
 *   rental_categories <- services.rental_category_id (SET NULL!).
 * Hence: dependents are hard-refused (the DB would refuse too), units/stock are deleted
 * explicitly with an affected-rows assertion, and a category is only deleted once no
 * service of ANY owner still points at it (the DB would silently un-categorise them).
 * No model events fire (query-builder deletes); the audit_logs trail is left alone.
 */
class RevertCatalogImport
{
    private const FILE_PATTERN = '#^services/(featured|images)/[A-Za-z0-9]{40}\.(jpg|png|webp)$#D';

    /**
     * @param  bool  $forUpdate  true only inside execute()'s transaction: every read that feeds a
     *                           decision becomes a LOCKING read. Under MySQL REPEATABLE READ a plain
     *                           SELECT keeps answering from the transaction's first snapshot, so a
     *                           row committed by someone else a moment ago would be invisible — and
     *                           the ON DELETE CASCADE / SET NULL that follows would destroy it.
     */
    public function analyse(CatalogImportBatch $batch, RevertOptions $options, bool $forUpdate = false): RevertPlan
    {
        $lock = fn ($query) => $forUpdate ? $query->lockForUpdate() : $query;
        $plan = new RevertPlan;
        $plan->partial = $options->partial;
        $orgId = (int) $batch->organization_id;

        if (! in_array($batch->status, [CatalogImportBatch::STATUS_COMPLETED, CatalogImportBatch::STATUS_PARTIALLY_REVERTED], true)) {
            $plan->fatal[] = "paczka ma status '{$batch->status}' — nic do cofnięcia";

            return $plan;
        }

        if ($batch->imported_at === null) {
            $plan->fatal[] = 'paczka nie została ukończona (brak imported_at)';

            return $plan;
        }

        $items = $lock($batch->items()->whereNull('reverted_at'))->get();

        foreach ($items as $item) {
            if ((int) $item->organization_id !== $orgId) {
                $plan->fatal[] = "wiersz ledgera #{$item->id} należy do innej organizacji niż paczka";
            }
        }

        $byType = $items->groupBy('entity_type');
        $importedAt = $batch->imported_at;

        // ---- services: identity ----
        $serviceItems = $byType->get(CatalogImportItem::TYPE_SERVICE, collect());
        $rows = $lock(Service::withoutGlobalScope('organization')->whereIn('id', $serviceItems->pluck('entity_id'))->orderBy('id'))->get()->keyBy('id');
        $serviceItemsById = $serviceItems->keyBy('entity_id');

        $present = [];
        foreach ($serviceItems as $item) {
            $row = $rows->get($item->entity_id);

            if ($row === null) {
                $plan->goneServiceIds[] = (int) $item->entity_id;

                continue;
            }

            // Identity = "this row existed when the import finished and belongs to the batch's
            // organization". A recycled id always has a LATER created_at (and name/slug alone prove
            // nothing: delete + recreate with the same name passes that test). Name/slug drift is
            // only an edit — a soft block, see modifications().
            if ($this->isForeignRow($row, $orgId, $importedAt) || $row->service_type !== ServiceType::ItemRental) {
                $plan->fatal[] = "produkt #{$item->entity_id} nie pochodzi z tej paczki (inna organizacja albo utworzony po imporcie) — id mogło zostać użyte ponownie";

                continue;
            }

            $present[(int) $row->id] = $row;
        }

        // ---- units / stock / created categories: identity ----
        $unitItems = $byType->get(CatalogImportItem::TYPE_UNIT, collect());
        $stockItems = $byType->get(CatalogImportItem::TYPE_STOCK, collect());
        $unitRows = $lock(ServiceUnit::withoutGlobalScope('organization')->whereIn('id', $unitItems->pluck('entity_id'))->orderBy('id'))->get()->keyBy('id');
        $stockRows = $lock(ServiceLocationStock::withoutGlobalScope('organization')->whereIn('id', $stockItems->pluck('entity_id'))->orderBy('id'))->get()->keyBy('id');

        foreach ([[$unitItems, $unitRows, 'egzemplarz'], [$stockItems, $stockRows, 'stan magazynowy']] as [$list, $found, $label]) {
            foreach ($list as $item) {
                $row = $found->get($item->entity_id);

                if ($row !== null && ($this->isForeignRow($row, $orgId, $importedAt) || (int) $row->service_id !== (int) $item->service_id)) {
                    $plan->fatal[] = "{$label} #{$item->entity_id} nie pochodzi z tej paczki (inna organizacja/produkt albo utworzony po imporcie)";
                }
            }
        }

        $createdCategories = $byType->get(CatalogImportItem::TYPE_CATEGORY, collect())
            ->where('origin', CatalogImportItem::ORIGIN_CREATED);
        $categoryRows = $lock(RentalCategory::withoutGlobalScope('organization')->whereIn('id', $createdCategories->pluck('entity_id'))->orderBy('id'))->get()->keyBy('id');

        foreach ($createdCategories as $item) {
            $row = $categoryRows->get($item->entity_id);

            if ($row === null) {
                continue;
            }

            if ($this->isForeignRow($row, $orgId, $importedAt)) {
                $plan->fatal[] = "kategoria #{$item->entity_id} nie pochodzi z tej paczki (inna organizacja albo utworzona po imporcie)";

                continue;
            }

            if ($row->updated_at->gt($importedAt)) {
                $plan->categoriesKept[] = "{$row->name} (edytowana po imporcie)";

                continue;
            }

            $plan->categoryCandidates[(int) $row->id] = (string) $row->slug;
        }

        if ($plan->fatal !== []) {
            return $plan;
        }

        // ---- dependents + "touched after import" per present service ----
        $ids = array_keys($present);
        $dependents = $this->dependentCounts($ids, $forUpdate);
        $foreignUnits = $lock(ServiceUnit::withoutGlobalScope('organization')->whereIn('service_id', $ids)
            ->whereNotIn('id', $unitItems->pluck('entity_id'))->orderBy('id'))->get()->groupBy('service_id');
        $foreignStocks = $lock(ServiceLocationStock::withoutGlobalScope('organization')->whereIn('service_id', $ids)
            ->whereNotIn('id', $stockItems->pluck('entity_id'))->orderBy('id'))->get()->groupBy('service_id');
        $fileItemsByService = $byType->get(CatalogImportItem::TYPE_FILE, collect())->groupBy('service_id');

        foreach ($present as $id => $service) {
            $hard = [];
            $soft = [];

            foreach (['order_items' => 'zamówienia', 'rentals' => 'wypożyczenia', 'appointments' => 'rezerwacje terminów', 'extension_requests' => 'wnioski o przedłużenie'] as $key => $label) {
                if (($dependents[$key][$id] ?? 0) > 0) {
                    $hard[] = "{$label}: {$dependents[$key][$id]}";
                }
            }

            $carts = $dependents['cart_items'][$id] ?? 0;

            if ($carts > 0 && ! $options->discardCartItems) {
                $soft[] = "pozycje koszyków: {$carts}";
            }

            if (! $options->includeModified) {
                $soft = array_merge($soft, $this->modifications($service, $serviceItemsById->get($id), $importedAt, $unitItems, $unitRows, $stockItems, $stockRows, $foreignUnits->get($id, collect()), $foreignStocks->get($id, collect()), $fileItemsByService->get($id, collect())));
            }

            if ($hard !== [] || $soft !== []) {
                $plan->blocked[$id] = ['name' => $service->name, 'hard' => $hard, 'soft' => $soft];

                continue;
            }

            $plan->serviceIds[] = $id;
            $plan->cartItems += $carts;
        }

        // ---- what exactly goes ----
        $deleting = array_merge($plan->serviceIds, $plan->goneServiceIds);

        foreach ($unitItems->whereIn('service_id', $plan->serviceIds) as $item) {
            if ($unitRows->has($item->entity_id)) {
                $plan->unitIds[] = (int) $item->entity_id;
            }
        }
        foreach ($stockItems->whereIn('service_id', $plan->serviceIds) as $item) {
            if ($stockRows->has($item->entity_id)) {
                $plan->stockIds[] = (int) $item->entity_id;
            }
        }
        foreach ($plan->serviceIds as $id) {
            foreach ($foreignUnits->get($id, collect()) as $u) {
                $plan->foreignUnitIds[] = (int) $u->id;
            }
            foreach ($foreignStocks->get($id, collect()) as $s) {
                $plan->foreignStockIds[] = (int) $s->id;
            }
        }
        foreach ($byType->get(CatalogImportItem::TYPE_FILE, collect())->whereIn('service_id', $deleting) as $file) {
            $plan->fileItemIds[] = (int) $file->id;
        }

        return $plan;
    }

    /**
     * @throws CatalogOperationInProgressException another import/revert of this organization is running
     * @throws RevertRefusedException the state changed between the caller's analyse() and now
     */
    public function execute(CatalogImportBatch $batch, RevertOptions $options): RevertResult
    {
        $result = new RevertResult;
        $orgId = (int) $batch->organization_id;

        // The same lock catalog:import takes: an import and a revert of one organization never overlap.
        $lock = Cache::lock("catalog-import:{$orgId}", 3600);

        if (! $lock->get()) {
            throw new CatalogOperationInProgressException("Inny import lub cofnięcie tej organizacji jest w toku (blokada catalog-import:{$orgId}).");
        }

        try {
            $plan = $this->executeLocked($batch, $options, $result, $orgId);
        } finally {
            $lock->release();
        }

        Log::info('catalog:import-revert completed', ['batch_id' => $batch->id, 'org_id' => $orgId, 'services' => $result->services, 'files_deleted' => $result->filesDeleted]);

        return $result;
    }

    private function executeLocked(CatalogImportBatch $batch, RevertOptions $options, RevertResult $result, int $orgId): RevertPlan
    {
        try {
            $plan = DB::transaction(function () use ($batch, $options, $result, $orgId): RevertPlan {
                $locked = CatalogImportBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();

                // 1. Lock the batch's services FIRST, in id order (the codebase's lock order,
                //    rental-availability.md), before any consistent read the decisions rest on.
                //    A child row (appointment, cart item, order item…) cannot be inserted while
                //    this X lock is held: its FK check wants an S lock on the parent.
                $ledgerServiceIds = CatalogImportItem::query()
                    ->where('batch_id', $locked->id)->where('entity_type', CatalogImportItem::TYPE_SERVICE)
                    ->whereNull('reverted_at')->lockForUpdate()->pluck('entity_id')->map(fn ($i) => (int) $i)->sort()->values()->all();

                Service::withoutGlobalScope('organization')->whereIn('id', $ledgerServiceIds)
                    ->orderBy('id')->lockForUpdate()->get();

                // 2. Re-run the whole analysis with LOCKING reads: anything that appeared since the
                //    caller's own (stale-snapshot) analyse() turns into a refusal.
                $plan = $this->analyse($locked, $options, forUpdate: true);

                if (! $plan->canExecute()) {
                    throw new RevertRefusedException($plan);
                }

                $ids = $plan->serviceIds;

                if ($ids !== []) {
                    if ($options->discardCartItems) {
                        $result->cartItems = DB::table('cart_items')->whereIn('service_id', $ids)->delete();
                    }

                    $result->units = DB::table('service_units')->whereIn('service_id', $ids)->where('organization_id', $orgId)->delete();
                    $this->assertCount($result->units, count($plan->unitIds) + count($plan->foreignUnitIds), 'egzemplarzy');

                    $result->stocks = DB::table('service_location_stocks')->whereIn('service_id', $ids)->where('organization_id', $orgId)->delete();
                    $this->assertCount($result->stocks, count($plan->stockIds) + count($plan->foreignStockIds), 'stanów magazynowych');

                    $result->services = DB::table('services')->whereIn('id', $ids)->where('organization_id', $orgId)->delete();
                    $this->assertCount($result->services, count($ids), 'produktów');
                }

                // 3. Categories: lock the candidate rows (a new child needs an S lock on its parent,
                //    so none can slip in), THEN look for remaining users with a locking read.
                //    ON DELETE SET NULL would otherwise silently un-categorise a product committed
                //    after our snapshot.
                if ($plan->categoryCandidates !== []) {
                    DB::table('rental_categories')->whereIn('id', array_keys($plan->categoryCandidates))
                        ->orderBy('id')->lockForUpdate()->get();
                }

                foreach ($plan->categoryCandidates as $categoryId => $slug) {
                    $stillUsed = DB::table('services')->where('rental_category_id', $categoryId)
                        ->select('id')->limit(1)->lockForUpdate()->first();

                    if ($stillUsed !== null) {
                        $result->categoriesKept[] = "{$slug} (nadal używana przez inne produkty)";

                        continue;
                    }

                    $result->categoriesDeleted += DB::table('rental_categories')
                        ->where('id', $categoryId)->where('organization_id', $orgId)->where('slug', $slug)->delete();
                }

                foreach ($plan->categoriesKept as $label) {
                    $result->categoriesKept[] = $label;
                }

                $this->markLedger($locked, $plan);

                return $plan;
            });
        } catch (RevertRefusedException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('catalog:import-revert failed — rolled back', ['batch_id' => $batch->id, 'org_id' => $orgId, 'exception' => $e->getMessage()]);

            throw $e;
        }

        // Post-commit, best effort: files are not transactional.
        $this->deleteFiles($batch, $plan, $result);
        Cache::forget("sitemap:{$orgId}");

        if ($options->resetAutoIncrement) {
            $result->autoIncrement = $this->resetAutoIncrement($batch->fresh());
        }

        return $plan;
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<string, array<int, int>> kind => [serviceId => count]
     */
    private function dependentCounts(array $ids, bool $forUpdate): array
    {
        if ($ids === []) {
            return [];
        }

        // Rows are fetched (not COUNT()ed) so the locking variant is a plain `SELECT … FOR UPDATE`.
        $count = function (string $table) use ($ids, $forUpdate): array {
            $q = DB::table($table)->whereIn('service_id', $ids)->select('service_id');

            return ($forUpdate ? $q->lockForUpdate() : $q)->get()->countBy('service_id')->map(fn ($v) => (int) $v)->all();
        };

        $extension = DB::table('order_item_extension_requests')
            ->join('order_items', 'order_items.id', '=', 'order_item_extension_requests.order_item_id')
            ->whereIn('order_items.service_id', $ids)
            ->select('order_items.service_id as service_id');

        return [
            'order_items' => $count('order_items'),
            'rentals' => $count('rentals'),
            'appointments' => $count('appointments'),
            'cart_items' => $count('cart_items'),
            'extension_requests' => ($forUpdate ? $extension->lockForUpdate() : $extension)->get()->countBy('service_id')->map(fn ($v) => (int) $v)->all(),
        ];
    }

    /**
     * Id-reuse proof for a ledgered row: it must belong to the batch's organization and must
     * have existed when the import finished. A recycled id always has a later created_at.
     */
    private function isForeignRow(\Illuminate\Database\Eloquent\Model $row, int $orgId, \Carbon\CarbonInterface $importedAt): bool
    {
        return (int) $row->organization_id !== $orgId
            || $row->created_at === null
            || $row->created_at->gt($importedAt);
    }

    /**
     * Reasons a batch product counts as "touched after import" (all overridable by --include-modified).
     *
     * @return array<int, string>
     */
    private function modifications(Service $service, ?CatalogImportItem $ledger, \Carbon\CarbonInterface $importedAt, $unitItems, $unitRows, $stockItems, $stockRows, $foreignUnits, $foreignStocks, $fileItems): array
    {
        $reasons = [];

        if ($service->updated_at->gt($importedAt)) {
            $reasons[] = 'produkt edytowany po imporcie';
        }

        // Name/slug drift is only an edit (identity is proven by organization + created_at).
        if ($ledger !== null && ($service->name !== $ledger->name || $service->slug !== $ledger->slug)) {
            $reasons[] = 'nazwa/slug zmienione po imporcie';
        }

        if ($foreignUnits->isNotEmpty()) {
            $reasons[] = 'dodane później egzemplarze: '.$foreignUnits->count();
        }

        $foreignQuantity = $foreignStocks->where('quantity', '>', 0)->count();

        if ($foreignQuantity > 0) {
            $reasons[] = 'dodany później stan magazynowy: '.$foreignQuantity;
        }

        foreach ($unitItems->where('service_id', $service->id) as $item) {
            $row = $unitRows->get($item->entity_id);

            if ($row !== null && $row->updated_at->gt($importedAt)) {
                $reasons[] = "egzemplarz #{$row->id} edytowany po imporcie";
            }
        }

        foreach ($stockItems->where('service_id', $service->id) as $item) {
            $row = $stockRows->get($item->entity_id);

            if ($row !== null && ((int) $row->quantity !== (int) ($item->snapshot['quantity'] ?? $row->quantity) || $row->updated_at->gt($importedAt))) {
                $reasons[] = "stan magazynowy #{$row->id} zmieniony po imporcie";
            }
        }

        $expectedFeatured = $fileItems->first(fn ($f) => ($f->snapshot['role'] ?? null) === 'featured')?->path;
        $expectedGallery = $fileItems->filter(fn ($f) => ($f->snapshot['role'] ?? null) === 'gallery')->pluck('path')->sort()->values()->all();
        $actualGallery = collect($service->content ?? [])
            ->filter(fn ($b) => ($b['type'] ?? null) === 'image')->pluck('data.image')->filter()->sort()->values()->all();

        if ((string) $service->featured_image !== (string) $expectedFeatured || $actualGallery !== $expectedGallery) {
            $reasons[] = 'zdjęcia zmienione po imporcie';
        }

        return $reasons;
    }

    private function assertCount(int $actual, int $expected, string $what): void
    {
        if ($actual !== $expected) {
            throw new \RuntimeException("Usunięto {$actual} {$what}, a plan zakładał {$expected} — cofnięcie przerwane (rollback).");
        }
    }

    private function markLedger(CatalogImportBatch $batch, RevertPlan $plan): void
    {
        $handled = array_merge($plan->serviceIds, $plan->goneServiceIds);
        $now = now();

        $batch->items()->whereNull('reverted_at')
            ->where(function ($q) use ($handled): void {
                $q->where(fn ($s) => $s->where('entity_type', CatalogImportItem::TYPE_SERVICE)->whereIn('entity_id', $handled))
                    ->orWhere(fn ($s) => $s->whereIn('entity_type', [CatalogImportItem::TYPE_UNIT, CatalogImportItem::TYPE_STOCK, CatalogImportItem::TYPE_FILE])->whereIn('service_id', $handled));
            })->update(['reverted_at' => $now]);

        $remainingServices = $batch->items()->whereNull('reverted_at')->where('entity_type', CatalogImportItem::TYPE_SERVICE)->exists();

        if (! $remainingServices) {
            $batch->items()->whereNull('reverted_at')->update(['reverted_at' => $now]);
        }

        $batch->forceFill([
            'status' => $remainingServices ? CatalogImportBatch::STATUS_PARTIALLY_REVERTED : CatalogImportBatch::STATUS_REVERTED,
            'reverted_at' => $now,
        ])->save();
    }

    private function deleteFiles(CatalogImportBatch $batch, RevertPlan $plan, RevertResult $result): void
    {
        if ($plan->fileItemIds === []) {
            return;
        }

        $disk = Storage::disk('public');

        foreach (CatalogImportItem::query()->whereIn('id', $plan->fileItemIds)->get() as $file) {
            $path = (string) $file->path;

            if (! preg_match(self::FILE_PATTERN, $path)) {
                $result->filesKept[] = "{$path} (ścieżka poza wzorcem importera)";

                continue;
            }

            if (! $disk->exists($path)) {
                continue;
            }

            if ($file->file_sha256 !== null && hash('sha256', (string) $disk->get($path)) !== $file->file_sha256) {
                $result->filesKept[] = "{$path} (zawartość zmieniona od importu)";

                continue;
            }

            $name = basename($path);
            $referenced = DB::table('services')->where(function ($q) use ($path, $name): void {
                $q->where('featured_image', $path)
                    ->orWhere('content', 'like', "%{$name}%")
                    ->orWhere('metadata', 'like', "%{$name}%")
                    ->orWhere('body', 'like', "%{$name}%");
            })->exists();

            if ($referenced) {
                $result->filesKept[] = "{$path} (nadal używany przez wiersz spoza cofanej paczki)";

                continue;
            }

            if ($disk->delete($path)) {
                $result->filesDeleted++;
            } else {
                $result->filesKept[] = "{$path} (nie udało się usunąć)";
            }
        }
    }

    /**
     * @return array<string, array{reset: bool, reason: string}>
     */
    private function resetAutoIncrement(CatalogImportBatch $batch): array
    {
        $driver = DB::connection()->getDriverName();
        $typeFor = [
            'services' => [CatalogImportItem::TYPE_SERVICE, CatalogImportItem::ORIGIN_CREATED],
            'rental_categories' => [CatalogImportItem::TYPE_CATEGORY, CatalogImportItem::ORIGIN_CREATED],
            'service_units' => [CatalogImportItem::TYPE_UNIT, CatalogImportItem::ORIGIN_CREATED],
            'service_location_stocks' => [CatalogImportItem::TYPE_STOCK, CatalogImportItem::ORIGIN_CREATED],
        ];

        $out = [];

        foreach (AutoIncrementResetPlanner::TABLES as $table => $auditableClass) {
            [$type, $origin] = $typeFor[$table];
            $min = $batch->items()->where('entity_type', $type)->where('origin', $origin)->whereNotNull('entity_id')->min('entity_id');

            if ($min === null) {
                continue;
            }

            $min = (int) $min;
            $remain = DB::table($table)->where('id', '>=', $min)->exists();
            $alias = $auditableClass !== null
                && DB::table('audit_logs')->where('auditable_type', $auditableClass)->where('auditable_id', '>=', $min)->exists();

            // Any still-live ledger row of this entity type at/above the lowest id (another batch,
            // or this one after --partial) means that id is still somebody's reference.
            $ledgerAlias = CatalogImportItem::query()
                ->where('entity_type', $type)->whereNull('reverted_at')->whereNotNull('entity_id')
                ->where('entity_id', '>=', $min)->exists();

            $decision = AutoIncrementResetPlanner::decide($driver, $remain, $alias, $ledgerAlias);

            if ($decision['reset']) {
                // DDL implicitly commits — which is why this runs after the revert's transaction.
                // $table comes from a constant whitelist and $min is an int.
                DB::statement("ALTER TABLE `{$table}` AUTO_INCREMENT = {$min}");
            }

            $out[$table] = $decision + ['to' => $min];
        }

        return $out;
    }
}
