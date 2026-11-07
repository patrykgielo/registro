<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Actions\Inventory\RouteQuantityFieldToPrimaryLocationStock;
use App\Actions\Inventory\SyncServiceLocationStock;
use App\Enums\ServiceType;
use App\Enums\ServiceUnitStatus;
use App\Models\CatalogImportBatch;
use App\Models\CatalogImportItem;
use App\Models\Location;
use App\Models\Organization;
use App\Models\RentalCategory;
use App\Models\Service;
use App\Models\ServiceLocationStock;
use App\Models\ServiceUnit;
use App\Support\Catalog\CatalogDocument;
use App\Support\Catalog\CatalogImportContext;
use App\Support\Catalog\CatalogProductValidator;
use App\Support\Catalog\FetchedImage;
use App\Support\Catalog\HostResolver;
use App\Support\Catalog\ImageFetchException;
use App\Support\Catalog\ImageInspector;
use App\Support\Catalog\LocalImageLoader;
use App\Support\Catalog\SafeImageFetcher;
use App\Support\Catalog\ValidationReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * `catalog:import` engine. Phases, in this order — nothing is written before phase 5:
 *
 *  1. file structure            (CatalogDocument)
 *  2. organization preconditions (active, rentals, >= 1 active location)
 *  3. product validation         (CatalogProductValidator — the form's rules + DB constraints)
 *  4. duplicate detection        (ledger: external_id)
 *  5. image prefetch + sniffing  (SafeImageFetcher / LocalImageLoader into a scratch dir)
 *  6. ONE DB::transaction        (categories, services, stock/units, files, ledger)
 *
 * Any error in 1-5 leaves zero rows and zero files. A failure in 6 rolls back the rows
 * and deletes the files already stored. Files are written to the `public` disk with
 * the same names/dirs the admin upload uses (services/featured, services/images).
 */
final class ImportCatalog
{
    public function __construct(private readonly CatalogProductValidator $validator) {}

    public function run(Organization $org, string $file, ImportOptions $options): ImportResult
    {
        $result = new ImportResult($options->dryRun);

        $doc = CatalogDocument::load($file);

        $pre = $this->preflight($org);

        if ($pre !== []) {
            foreach ($pre as $message) {
                $result->report->error(null, null, 'organizacja', $message);
            }

            return $result;
        }

        $lock = Cache::lock("catalog-import:{$org->id}", 3600);

        if (! $lock->get()) {
            $result->report->error(null, null, 'blokada', 'inny import tej organizacji jest w toku');

            return $result;
        }

        $scratch = null;

        try {
            return CatalogImportContext::asTenant($org, function () use ($org, $doc, $file, $options, $result, &$scratch): ImportResult {
                return $this->execute($org, $doc, $file, $options, $result, $scratch);
            });
        } finally {
            if ($scratch !== null) {
                File::deleteDirectory($scratch);
            }

            $lock->release();
        }
    }

    /**
     * @return array<int, string>
     */
    private function preflight(Organization $org): array
    {
        $errors = [];

        if (! $org->lifecycle_state?->allowsNewBookings()) {
            $errors[] = 'organizacja nie jest aktywna (lifecycle_state)';
        }

        if (! $org->supportsRentals() || ! $org->hasModule('rentals')) {
            $errors[] = 'organizacja nie obsługuje wypożyczeń (booking_type / moduł rentals)';
        }

        $hasLocation = Location::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)->where('is_active', true)->exists();

        if (! $hasLocation) {
            $errors[] = 'organizacja nie ma aktywnego oddziału — produkty nie byłyby dostępne do rezerwacji';
        }

        return $errors;
    }

    /**
     * @param  array{products: array<int, mixed>, sha256: string}  $doc
     */
    private function execute(Organization $org, array $doc, string $file, ImportOptions $options, ImportResult $result, ?string &$scratch): ImportResult
    {
        $report = $result->report;
        $products = $doc['products'];

        // Phase 4 first so already-imported products are exempt from DB-collision checks.
        $skipIndexes = $this->detectAlreadyImported($org, $products, $options, $report);

        $schemaReport = $this->validator->validate($products, $org, $skipIndexes);
        foreach ($schemaReport->errors() as $e) {
            $report->error($e['index'], $e['external_id'], $e['field'], $e['message']);
        }
        foreach ($schemaReport->warnings() as $w) {
            $report->warning($w['index'], $w['external_id'], $w['field'], $w['message']);
        }

        if ($report->hasErrors()) {
            return $result;
        }

        $normalized = [];
        foreach ($products as $i => $raw) {
            if (! in_array($i, $skipIndexes, true)) {
                $normalized[$i] = CatalogProductValidator::normalize($raw);
            }
        }

        $result->counts['products'] = count($normalized);
        $result->counts['skipped'] = count($skipIndexes);

        $fetched = [];
        $fetchImages = $options->images !== 'skip' && (! $options->dryRun || $options->checkImagesInDryRun);

        if ($fetchImages) {
            $scratch = storage_path('app/tmp/catalog-import/'.Str::uuid());
            File::ensureDirectoryExists($scratch);
            $fetched = $this->prefetchImages($normalized, dirname((string) realpath($file)), $scratch, $options, $report);

            if ($report->hasErrors()) {
                return $result;
            }
        }

        $result->counts['images'] = array_sum(array_map(fn (array $f) => count($f['all']), $fetched));
        $this->countPlan($org, $normalized, $result);

        if ($options->dryRun || $normalized === []) {
            return $result;
        }

        Log::info('catalog:import write start', ['org_id' => $org->id, 'products' => count($normalized), 'file_sha256' => $doc['sha256']]);

        $stored = [];

        try {
            $batch = DB::transaction(function () use ($org, $normalized, $fetched, $doc, $file, $options, $result, &$stored): CatalogImportBatch {
                $batch = CatalogImportBatch::create([
                    'organization_id' => $org->id,
                    'source_filename' => basename($file),
                    'source_sha256' => $doc['sha256'],
                    'status' => CatalogImportBatch::STATUS_COMPLETED,
                    'actor' => $options->actor,
                ]);

                $categories = [];

                foreach ($normalized as $i => $p) {
                    $this->importProduct($org, $batch, $p, $i, $fetched[$i] ?? null, $categories, $stored, $result);
                }

                $batch->forceFill([
                    'imported_at' => now(),
                    'summary' => $result->counts,
                ])->save();

                return $batch;
            });
        } catch (\Throwable $e) {
            foreach ($stored as $path) {
                Storage::disk('public')->delete($path);
            }

            Log::error('catalog:import write failed — rolled back', ['org_id' => $org->id, 'exception' => $e->getMessage()]);

            throw $e;
        }

        Cache::forget("sitemap:{$org->id}");

        $result->batch = $batch;

        Log::info('catalog:import completed', ['org_id' => $org->id, 'batch_id' => $batch->id, 'counts' => $result->counts]);

        return $result;
    }

    /**
     * @param  array<int, mixed>  $products
     * @return array<int, int> indexes to skip
     */
    private function detectAlreadyImported(Organization $org, array $products, ImportOptions $options, ValidationReport $report): array
    {
        $keys = [];
        foreach ($products as $i => $p) {
            if (is_array($p) && isset($p['external_id']) && is_string($p['external_id'])) {
                $keys[$i] = $p['external_id'];
            }
        }

        if ($keys === []) {
            return [];
        }

        $existing = CatalogImportItem::query()
            ->where('organization_id', $org->id)
            ->where('entity_type', CatalogImportItem::TYPE_SERVICE)
            ->whereIn('external_key', array_values($keys))
            ->whereNull('reverted_at')
            ->get()
            ->keyBy('external_key');

        $skip = [];

        foreach ($keys as $i => $key) {
            $item = $existing->get($key);

            if ($item === null) {
                continue;
            }

            if (! $options->skipExisting) {
                $report->error($i, $key, 'external_id', "już zaimportowany w paczce #{$item->batch_id} (użyj --skip-existing, aby pominąć)");

                continue;
            }

            $skip[] = $i;

            $hash = hash('sha256', json_encode(CatalogProductValidator::normalize($products[$i])));

            if ($item->payload_hash !== $hash) {
                $report->warning($i, $key, 'external_id', 'pominięty, ale treść w pliku różni się od zaimportowanej (importer nie aktualizuje)');
            }
        }

        return $skip;
    }

    /**
     * @param  array<int, array<string, mixed>>  $products
     * @return array<int, array{featured: ?FetchedImage, gallery: array<int, ?FetchedImage>, all: array<int, FetchedImage>}>
     */
    private function prefetchImages(array $products, string $baseDir, string $scratch, ImportOptions $options, ValidationReport $report): array
    {
        $inspector = new ImageInspector;
        $fetcher = new SafeImageFetcher(
            app(HostResolver::class),
            $inspector,
            hostDelayMs: $options->imageDelayMs,
            maxRetries: (int) config('catalog_import.max_retries', 3),
            maxRetryWaitSeconds: (int) config('catalog_import.max_retry_wait_seconds', 60),
        );
        $local = new LocalImageLoader($inspector);

        $out = [];

        // One request per DISTINCT image per run (the target host rate-limits hard). The memo holds
        // the validated FetchedImage — or the failure message — and every occurrence reuses it:
        // storeFile() only READS tmpPath, so each product still gets its OWN stored copy (own
        // random path, own ledger file row), which is what the per-product revert relies on.
        // The scratch dir is removed once, as a whole, by run()'s finally.
        $memo = [];

        foreach ($products as $i => $p) {
            $entry = ['featured' => null, 'gallery' => [], 'all' => []];
            $ext = $p['external_id'] ?? null;

            $jobs = [];
            if (! empty($p['featured_image'])) {
                $jobs['featured_image'] = $p['featured_image'];
            }
            foreach ($p['gallery'] ?? [] as $n => $img) {
                $jobs["gallery.{$n}"] = $img;
            }

            foreach ($jobs as $field => $img) {
                $key = isset($img['path'])
                    ? self::localKey($baseDir, $img['path'])
                    : 'url:'.self::normalizeUrl($img['url']);

                if (! array_key_exists($key, $memo)) {
                    try {
                        $memo[$key] = isset($img['path'])
                            ? $local->load($img['path'], $baseDir, $scratch)
                            : $fetcher->fetch($img['url'], $scratch);
                    } catch (ImageFetchException $e) {
                        $memo[$key] = $e->getMessage();
                    }
                }

                $image = $memo[$key];

                if (is_string($image)) {
                    $message = ($img['url'] ?? $img['path']).' — '.$image;
                    $options->images === 'optional'
                        ? $report->warning($i, $ext, $field, $message)
                        : $report->error($i, $ext, $field, $message);

                    continue;
                }

                $entry['all'][] = $image;
                $field === 'featured_image' ? $entry['featured'] = $image : $entry['gallery'][(int) Str::after($field, 'gallery.')] = $image;
            }

            $out[$i] = $entry;
        }

        return $out;
    }

    /**
     * Memo key of a local image: its resolved realpath — but ONLY for a spelling LocalImageLoader
     * would accept anyway. A path with "..", an absolute path or a NUL byte gets a raw-spelling
     * key, so it is judged (and refused) by the loader itself instead of riding on an earlier,
     * clean spelling of the same file.
     */
    private static function localKey(string $baseDir, string $path): string
    {
        $normalized = str_replace('\\', '/', $path);

        $suspicious = $normalized === '' || str_contains($normalized, "\0") || str_starts_with($normalized, '/')
            || preg_match('#^[a-zA-Z]:#', $normalized) === 1 || in_array('..', explode('/', $normalized), true);

        if ($suspicious) {
            return 'rawpath:'.$path;
        }

        return 'path:'.(realpath($baseDir.DIRECTORY_SEPARATOR.$normalized) ?: $baseDir.'|'.$path);
    }

    /** Scheme and host are case-insensitive, the fragment is never sent. */
    private static function normalizeUrl(string $url): string
    {
        $url = trim(preg_replace('/#.*$/', '', trim($url)) ?? $url);

        return preg_replace_callback('#^([a-z][a-z0-9+.-]*://[^/?]*)#i', fn (array $m) => strtolower($m[1]), $url) ?? $url;
    }

    /**
     * @param  array<int, array<string, mixed>>  $products
     */
    private function countPlan(Organization $org, array $products, ImportResult $result): void
    {
        $existing = RentalCategory::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)->pluck('slug')->flip()->all();

        $created = [];
        $reused = [];

        foreach ($products as $p) {
            $slug = $p['category']['slug'];
            isset($existing[$slug]) ? $reused[$slug] = true : $created[$slug] = true;
            $result->counts['units'] += count($p['units'] ?? []);
        }

        $result->counts['categories_created'] = count($created);
        $result->counts['categories_reused'] = count($reused);
    }

    /**
     * @param  array<string, mixed>  $p
     * @param  array{featured: ?FetchedImage, gallery: array<int, ?FetchedImage>, all: array<int, FetchedImage>}|null  $images
     * @param  array<string, RentalCategory>  $categories
     * @param  array<int, string>  $stored
     */
    private function importProduct(Organization $org, CatalogImportBatch $batch, array $p, int $index, ?array $images, array &$categories, array &$stored, ImportResult $result): void
    {
        $category = $this->resolveCategory($org, $batch, $p['category'], $categories);

        $fileItems = [];
        $imageSources = [];
        $blocks = [];
        $featuredPath = null;

        if ($images !== null) {
            if ($images['featured'] !== null) {
                $featuredPath = $this->storeFile($images['featured'], 'services/featured', $stored);
                $fileItems[] = [$featuredPath, $images['featured'], 'featured', $p['featured_image']];
            }

            ksort($images['gallery']);
            foreach ($images['gallery'] as $n => $image) {
                $meta = $p['gallery'][$n];
                $path = $this->storeFile($image, 'services/images', $stored);
                $fileItems[] = [$path, $image, 'gallery', $meta];
                // Every key the Builder's `image` block (BuilderBlocks::basicBlocks + BlockStyling)
                // would write on its own is present, in its order — so opening and saving the
                // product in the panel is a byte-identical no-op (PanelWalkthroughTest's rule).
                $blocks[] = ['type' => 'image', 'data' => [
                    'image' => $path,
                    'alt' => $meta['alt'] ?? null,
                    'caption' => $meta['caption'] ?? null,
                    'size' => 'large',
                    'full_width' => false,
                    'background_type' => null,
                    'container_max_width' => null,
                    'vertical_padding' => null,
                    'css_id' => null,
                    'css_classes' => null,
                ]];
            }
        }

        foreach ($fileItems as [$path, , , $meta]) {
            $source = array_filter([
                'path' => $path,
                'credit' => $meta['credit'] ?? null,
                'license' => $meta['license'] ?? null,
                'source_page' => $meta['source_page'] ?? null,
            ], fn ($v) => $v !== null);

            if (count($source) > 1) {
                $imageSources[] = $source;
            }
        }

        $specs = array_map(fn (array $s) => [
            'label' => $s['label'],
            'value' => $s['value'],
            'unit' => filled($s['unit'] ?? null) ? $s['unit'] : null,
        ], array_values($p['specs'] ?? []));

        $metadata = array_filter([
            'specs' => $specs ?: null,
            'image_sources' => $imageSources ?: null,
        ], fn ($v) => $v !== null);

        $body = filled($p['body_html'] ?? null) ? trim((string) clean($p['body_html'])) : null;

        $service = Service::withoutGlobalScope('organization')->create([
            'organization_id' => $org->id,
            'service_type' => ServiceType::ItemRental,
            'rental_category_id' => $category->id,
            'name' => $p['name'],
            'slug' => $p['slug'],
            'brand' => $p['brand'] ?? null,
            'excerpt' => $p['excerpt'] ?? null,
            'description' => $p['description'] ?? null,
            'body' => $body !== '' ? $body : null,
            'icon' => $p['icon'] ?? null,
            // seeder parity (SeedEquipmentRental): `price` mirrors the daily rate — schema.org
            // offers and the product page's JS read it, the form's default of 0 would show 0 zł.
            'price' => $p['price_per_day'],
            'price_per_day' => $p['price_per_day'],
            'price_per_hour' => $p['price_per_hour'] ?? null,
            'price_per_week' => $p['price_per_week'] ?? null,
            'price_per_day_long' => $p['price_per_day_long'] ?? null,
            'price_threshold_days' => $p['price_threshold_days'] ?? null,
            'deposit_amount' => $p['deposit_amount'] ?? null,
            'price_on_request' => (bool) ($p['price_on_request'] ?? false),
            'is_active' => (bool) ($p['is_active'] ?? true),
            'sort_order' => $p['sort_order'] ?? $index,
            // the form defaults published_at to now(); without it the admin list says "Wersja robocza"
            'published_at' => isset($p['published_at']) ? Carbon::parse($p['published_at']) : now(),
            'meta_title' => $p['seo']['meta_title'] ?? null,
            'meta_description' => $p['seo']['meta_description'] ?? null,
            'features' => $p['features'] ?? null,
            'featured_image' => $featuredPath,
            'content' => $blocks ?: null,
            'metadata' => $metadata ?: null,
            'quantity_total' => isset($p['stock']['quantity']) ? $p['stock']['quantity'] : null,
        ]);

        $batch->items()->create([
            'organization_id' => $org->id,
            'entity_type' => CatalogImportItem::TYPE_SERVICE,
            'entity_id' => $service->id,
            'origin' => CatalogImportItem::ORIGIN_CREATED,
            'external_key' => $p['external_id'],
            'name' => $service->name,
            'slug' => $service->slug,
            'payload_hash' => hash('sha256', json_encode($p)),
            'snapshot' => ['rental_category_id' => $category->id],
        ]);

        foreach ($fileItems as [$path, $image, $role, $meta]) {
            $batch->items()->create([
                'organization_id' => $org->id,
                'entity_type' => CatalogImportItem::TYPE_FILE,
                'service_id' => $service->id,
                'path' => $path,
                'file_sha256' => $image->sha256,
                'snapshot' => array_filter([
                    'role' => $role,
                    'bytes' => $image->bytes,
                    'credit' => $meta['credit'] ?? null,
                    'license' => $meta['license'] ?? null,
                    'source_page' => $meta['source_page'] ?? null,
                ], fn ($v) => $v !== null),
            ]);
        }

        $this->writeStock($org, $batch, $service, $p);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, RentalCategory>  $categories
     */
    private function resolveCategory(Organization $org, CatalogImportBatch $batch, array $data, array &$categories): RentalCategory
    {
        $slug = $data['slug'];

        if (isset($categories[$slug])) {
            return $categories[$slug];
        }

        $existing = RentalCategory::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)->where('slug', $slug)->first();

        if ($existing !== null) {
            $batch->items()->create([
                'organization_id' => $org->id,
                'entity_type' => CatalogImportItem::TYPE_CATEGORY,
                'entity_id' => $existing->id,
                'origin' => CatalogImportItem::ORIGIN_REUSED,
                'name' => $existing->name,
                'slug' => $existing->slug,
            ]);

            return $categories[$slug] = $existing;
        }

        $category = RentalCategory::withoutGlobalScope('organization')->create([
            'organization_id' => $org->id,
            'name' => $data['name'],
            'slug' => $slug,
            'description' => $data['description'] ?? null,
            'icon' => $data['icon'] ?? null,
            'sort_order' => $data['sort_order'] ?? ((int) RentalCategory::withoutGlobalScope('organization')->where('organization_id', $org->id)->max('sort_order') + 1),
            'is_active' => true,
        ]);

        $batch->items()->create([
            'organization_id' => $org->id,
            'entity_type' => CatalogImportItem::TYPE_CATEGORY,
            'entity_id' => $category->id,
            'origin' => CatalogImportItem::ORIGIN_CREATED,
            'name' => $category->name,
            'slug' => $category->slug,
        ]);

        return $categories[$slug] = $category;
    }

    /**
     * Mirrors CreateService::afterCreate() (SyncServiceLocationStock + the single-quantity
     * routing) so a product is bookable immediately; `locations` / `units` are the
     * multi-location and per-egzemplarz equivalents.
     *
     * @param  array<string, mixed>  $p
     */
    private function writeStock(Organization $org, CatalogImportBatch $batch, Service $service, array $p): void
    {
        SyncServiceLocationStock::forService($service);

        if (isset($p['stock']['quantity'])) {
            RouteQuantityFieldToPrimaryLocationStock::handle($service);
        }

        if (isset($p['stock']['locations'])) {
            $locations = Location::withoutGlobalScope('organization')
                ->where('organization_id', $org->id)->where('is_active', true)->get()->keyBy('slug');

            foreach ($p['stock']['locations'] as $slug => $qty) {
                ServiceLocationStock::withoutGlobalScope('organization')->updateOrCreate(
                    ['service_id' => $service->id, 'location_id' => $locations[$slug]->id],
                    ['organization_id' => $org->id, 'quantity' => $qty, 'is_active' => true]
                );
            }

            $service->recalculateQuantityTotal();
        }

        if (isset($p['units'])) {
            $locations = Location::withoutGlobalScope('organization')
                ->where('organization_id', $org->id)->where('is_active', true)->get();

            foreach ($p['units'] as $unit) {
                $location = isset($unit['location'])
                    ? $locations->firstWhere('slug', $unit['location'])
                    : $locations->first();

                $created = ServiceUnit::create([
                    'organization_id' => $org->id,
                    'service_id' => $service->id,
                    'location_id' => $location->id,
                    'identifier' => $unit['identifier'] ?? null,
                    'inventory_number' => $unit['inventory_number'] ?? null,
                    'status' => $unit['status'] ?? ServiceUnitStatus::Available->value,
                    'acquired_at' => $unit['acquired_at'] ?? null,
                    'notes' => $unit['notes'] ?? null,
                ]);

                $batch->items()->create([
                    'organization_id' => $org->id,
                    'entity_type' => CatalogImportItem::TYPE_UNIT,
                    'entity_id' => $created->id,
                    'service_id' => $service->id,
                    'name' => $created->identifier,
                    'snapshot' => ['status' => $created->status?->value ?? 'available', 'location_id' => $location->id],
                ]);
            }
        }

        // The unit observer may have created unnumbered placeholders / anchors — the ledger
        // lists every stock row that exists NOW (after all writes), with its quantity.
        foreach (ServiceLocationStock::withoutGlobalScope('organization')->where('service_id', $service->id)->get() as $stock) {
            $batch->items()->create([
                'organization_id' => $org->id,
                'entity_type' => CatalogImportItem::TYPE_STOCK,
                'entity_id' => $stock->id,
                'service_id' => $service->id,
                'snapshot' => ['quantity' => $stock->quantity, 'location_id' => $stock->location_id],
            ]);
        }

        // Placeholder units materialised by ServiceUnitObserver (not impossible for a
        // quantity-less product, but ledger them if any appeared that we did not create).
        $ledgered = $batch->items()->where('entity_type', CatalogImportItem::TYPE_UNIT)->where('service_id', $service->id)->pluck('entity_id')->all();
        foreach (ServiceUnit::withoutGlobalScope('organization')->where('service_id', $service->id)->whereNotIn('id', $ledgered)->get() as $extra) {
            $batch->items()->create([
                'organization_id' => $org->id,
                'entity_type' => CatalogImportItem::TYPE_UNIT,
                'entity_id' => $extra->id,
                'service_id' => $service->id,
                'snapshot' => ['status' => $extra->status?->value ?? 'available', 'location_id' => $extra->location_id, 'placeholder' => true],
            ]);
        }
    }

    /**
     * @param  array<int, string>  $stored
     */
    private function storeFile(FetchedImage $image, string $dir, array &$stored): string
    {
        $path = $dir.'/'.Str::random(40).'.'.$image->extension;
        $handle = fopen($image->tmpPath, 'r');

        try {
            if (! Storage::disk('public')->put($path, $handle, 'public')) {
                throw new \RuntimeException("Nie udało się zapisać pliku {$path}");
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        $stored[] = $path;

        return $path;
    }
}
