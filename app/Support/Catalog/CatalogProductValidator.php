<?php

declare(strict_types=1);

namespace App\Support\Catalog;

use App\Enums\ServiceUnitStatus;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceUnit;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Validates the products of an import file with the SAME rules the app enforces on the
 * Filament forms (ServiceResource.php / RentalCategoryResource.php — line references in
 * app/docs/features/catalog-json-importer.md), plus the database constraints the forms
 * leave to the DB (services_org_name_unique has NO form rule, so the panel 500s on a
 * duplicate name — here it is a clean error).
 *
 * Intentional deviations: prices are stricter than the form (>= 0, <= 99999999.99, max 2
 * decimals — the form accepts negatives); unknown keys are errors (typo guard);
 * `icon` defaults to null (the form's 'sparkles' default is a Filament preselect);
 * `area_served` defaults to null (the form's 'Poznań' is wrong for other tenants).
 *
 * Nothing here touches the network or the filesystem; image checks live in ImportCatalog.
 */
final class CatalogProductValidator
{
    public const MAX_GALLERY_IMAGES = 10;

    /** Signed INT column limit (services.sort_order, rental_categories.sort_order). */
    public const INT_MAX = 2147483647;

    /** Sane per-product caps (the form has none): a DB row per unit, a table line per spec. */
    public const MAX_UNITS = 500;

    public const MAX_SPECS = 30;

    /** A MySQL TEXT column holds 65535 BYTES (not characters): "Zażółć" is 10 bytes. */
    public const TEXT_MAX_BYTES = 65535;

    private const PRICE = 'numeric|min:0|max:99999999.99|decimal:0,2';

    private const PRODUCT_KEYS = [
        'external_id', 'name', 'slug', 'brand', 'category', 'excerpt', 'description', 'body_html', 'icon',
        'price_per_day', 'price_per_hour', 'price_per_week', 'price_per_day_long', 'price_threshold_days',
        'deposit_amount', 'price_on_request', 'is_active', 'sort_order', 'published_at',
        'stock', 'units', 'specs', 'features', 'seo', 'featured_image', 'gallery',
    ];

    private const IMAGE_KEYS = ['url', 'path', 'alt', 'caption', 'credit', 'license', 'source_page'];

    /**
     * heroicon names the admin forms offer (BaseResource::getHeroiconOptions — `s-*.svg`).
     *
     * @return array<int, string>
     */
    public static function heroiconNames(): array
    {
        $files = glob(base_path('vendor/blade-ui-kit/blade-heroicons/resources/svg').'/s-*.svg') ?: [];

        if ($files === []) {
            return ['sparkles', 'rectangle-stack', 'paint-brush', 'sun', 'squares-plus', 'swatch', 'beaker', 'shield-check'];
        }

        return array_map(fn (string $f) => substr(basename($f, '.svg'), 2), $files);
    }

    /**
     * Canonical shape used by validation AND by ImportCatalog (so both see identical data).
     *
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    public static function normalize(array $p): array
    {
        // What the panel form gets from TrimStrings + ConvertEmptyStringsToNull.
        $p = self::cleanStrings($p);

        $category = $p['category'] ?? null;

        if (is_string($category)) {
            $category = ['name' => $category];
        }

        if (is_array($category)) {
            $category['slug'] = $category['slug'] ?? (isset($category['name']) && is_string($category['name']) ? Str::slug($category['name']) : null);
        }

        $p['category'] = $category;

        if (! isset($p['slug']) && isset($p['name']) && is_string($p['name'])) {
            $p['slug'] = Str::slug($p['name']);
        }

        return $p;
    }

    private static function cleanStrings(mixed $value): mixed
    {
        if (is_string($value)) {
            $trimmed = preg_replace('~^[\s\x{FEFF}\x{200B}\x{200E}]+|[\s\x{FEFF}\x{200B}\x{200E}]+$~u', '', $value) ?? $value;

            return $trimmed === '' ? null : $trimmed;
        }

        return is_array($value) ? array_map(self::cleanStrings(...), $value) : $value;
    }

    /** Case/space-insensitive key, like the utf8mb4_unicode_ci unique indexes. */
    private static function fold(string $value): string
    {
        $value = trim($value);

        // Accent folding (Zażółć = zazolc) is only safe for text a transliterator can read LOSSLESSLY:
        // Latin letters, digits, spaces, punctuation and ASCII symbols. Str::ascii() turns emoji into
        // '' and many different CJK characters into the same pinyin — "different" names would then
        // collide as false duplicates. Anything else is compared as itself, only lower-cased.
        if (preg_match('/^[\p{Latin}\p{N}\p{Mn}\p{Zs}\p{P}\x20-\x7E]*$/u', $value) === 1) {
            return Str::lower(Str::ascii($value));
        }

        return mb_strtolower($value);
    }

    /**
     * @param  array<int, mixed>  $products  raw `products` list
     * @param  array<int, int>  $skipIndexes  indexes already imported (--skip-existing): schema-validated only
     */
    public function validate(array $products, Organization $org, array $skipIndexes = []): ValidationReport
    {
        $report = new ValidationReport;
        $ctx = $this->context($org);

        $seenKeys = [];
        $seenNames = [];
        $seenSlugs = [];
        $seenIdentifiers = [];
        $categorySlugs = [];

        foreach ($products as $i => $raw) {
            if (! is_array($raw) || array_is_list($raw)) {
                $report->error($i, null, '(produkt)', 'produkt musi być obiektem JSON');

                continue;
            }

            $p = self::normalize($raw);
            $ext = isset($p['external_id']) && is_string($p['external_id']) ? $p['external_id'] : null;

            $this->checkKeys($report, $i, $ext, $raw, self::PRODUCT_KEYS, '');

            $this->validateShape($report, $i, $ext, $p, $ctx);

            if ($ext !== null) {
                $key = self::fold($ext);

                if (isset($seenKeys[$key])) {
                    $report->error($i, $ext, 'external_id', "zduplikowany w pliku (też produkt #{$seenKeys[$key]})");
                }
                $seenKeys[$key] ??= $i;
            }

            if (in_array($i, $skipIndexes, true)) {
                continue;
            }

            $this->checkCollisions($report, $i, $ext, $p, $ctx, $seenNames, $seenSlugs);
            $this->checkStockAndUnits($report, $i, $ext, $p, $ctx, $seenIdentifiers);

            $category = $p['category'] ?? null;

            if (is_array($category) && isset($category['slug'], $category['name'])) {
                $categorySlugs[$category['slug']][$category['name']] = true;
            }
        }

        foreach ($categorySlugs as $slug => $names) {
            if (count($names) > 1) {
                $report->warning(null, null, 'category', "slug '{$slug}' używany dla różnych nazw (".implode(' / ', array_keys($names)).') — pierwsza definicja wygra');
            }
        }

        return $report;
    }

    /**
     * @param  array<string, mixed>  $p
     * @param  array<string, mixed>  $ctx
     */
    private function validateShape(ValidationReport $report, int $i, ?string $ext, array $p, array $ctx): void
    {
        $rules = [
            'external_id' => 'required|string|max:100',
            'name' => 'required|string|max:255',
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'brand' => 'nullable|string|max:255',
            'category' => 'required|array',
            'category.name' => 'required|string|max:255',
            'category.slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'category.icon' => ['nullable', 'string', Rule::in($ctx['icons'])],
            'category.description' => 'nullable|string',
            'category.sort_order' => 'nullable|integer|min:0|max:'.self::INT_MAX,
            'excerpt' => 'nullable|string|max:500',
            'description' => 'nullable|string',
            'body_html' => 'nullable|string',
            'icon' => ['nullable', 'string', Rule::in($ctx['icons'])],
            'price_per_day' => 'required|'.self::PRICE,
            'price_per_hour' => 'nullable|'.self::PRICE,
            'price_per_week' => 'nullable|'.self::PRICE,
            'price_per_day_long' => 'nullable|'.self::PRICE.'|required_with:price_threshold_days|lte:price_per_day',
            'price_threshold_days' => 'nullable|integer|min:1|max:365|required_with:price_per_day_long',
            'deposit_amount' => 'nullable|'.self::PRICE,
            'price_on_request' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:'.self::INT_MAX,
            'published_at' => 'nullable|date',
            'specs' => 'nullable|array|max:'.self::MAX_SPECS,
            'specs.*.label' => 'required|string|max:100',
            'specs.*.value' => 'required',
            'specs.*.unit' => 'nullable|string|max:20',
            'features' => 'nullable|array|max:4',
            'features.*' => 'required|string|max:100',
            'seo' => 'nullable|array',
            'seo.meta_title' => 'nullable|string|max:60',
            'seo.meta_description' => 'nullable|string|max:160',
            'featured_image' => 'nullable|array',
            'gallery' => 'nullable|array|max:'.self::MAX_GALLERY_IMAGES,
            'gallery.*' => 'required|array',
        ];

        foreach (['featured_image', 'gallery.*'] as $prefix) {
            $rules["{$prefix}.url"] = ['nullable', 'string', 'url:http,https', 'max:2048'];
            $rules["{$prefix}.path"] = 'nullable|string|max:500';
            $rules["{$prefix}.alt"] = 'nullable|string|max:255';
            $rules["{$prefix}.caption"] = 'nullable|string|max:255';
            $rules["{$prefix}.credit"] = 'nullable|string|max:255';
            $rules["{$prefix}.license"] = 'nullable|string|max:255';
            $rules["{$prefix}.source_page"] = ['nullable', 'string', 'url:http,https', 'max:2048'];
        }

        $validator = Validator::make($p, $rules);

        $validator->after(function ($v) use ($p): void {
            // TEXT columns: a byte cap here, not a phase-6 "Data too long" after the images were fetched.
            foreach (['description' => $p['description'] ?? null, 'category.description' => $p['category']['description'] ?? null] as $field => $text) {
                if (is_string($text) && strlen($text) > self::TEXT_MAX_BYTES) {
                    $v->errors()->add($field, 'tekst dłuższy niż '.self::TEXT_MAX_BYTES.' bajtów (kolumna TEXT)');
                }
            }

            // Measured on what is actually stored: the purified HTML.
            if (is_string($p['body_html'] ?? null) && strlen(trim((string) clean($p['body_html'], 'catalog_import'))) > self::TEXT_MAX_BYTES) {
                $v->errors()->add('body_html', 'po oczyszczeniu dłuższe niż '.self::TEXT_MAX_BYTES.' bajtów (kolumna TEXT)');
            }

            // string keys would collapse into one slot downstream
            foreach (['gallery', 'features', 'specs'] as $list) {
                if (isset($p[$list]) && is_array($p[$list]) && ! array_is_list($p[$list])) {
                    $v->errors()->add($list, 'musi być listą [ … ], a nie obiektem');
                }
            }

            foreach ($p['specs'] ?? [] as $n => $spec) {
                if (is_array($spec) && isset($spec['value']) && (! is_scalar($spec['value']) || mb_strlen((string) $spec['value']) > 100)) {
                    $v->errors()->add("specs.{$n}.value", 'wartość musi być tekstem/liczbą do 100 znaków');
                }
            }

            foreach (array_merge(isset($p['featured_image']) ? ['featured_image' => $p['featured_image']] : [], $this->galleryWithKeys($p)) as $field => $img) {
                if (! is_array($img)) {
                    continue;
                }
                $hasUrl = ! empty($img['url']);
                $hasPath = ! empty($img['path']);
                if ($hasUrl === $hasPath) {
                    $v->errors()->add($field, 'podaj dokładnie jedno z: url, path');
                }
            }
        });

        foreach ($validator->errors()->messages() as $field => $messages) {
            foreach ($messages as $message) {
                $report->error($i, $ext, $field, $message);
            }
        }

        foreach (['stock', 'seo', 'category'] as $nested) {
            if (isset($p[$nested]) && is_array($p[$nested])) {
                $allowed = match ($nested) {
                    'stock' => ['quantity', 'locations'],
                    'seo' => ['meta_title', 'meta_description'],
                    'category' => ['name', 'slug', 'icon', 'description', 'sort_order'],
                };
                $this->checkKeys($report, $i, $ext, $p[$nested], $allowed, $nested.'.');
            }
        }

        foreach (array_merge(isset($p['featured_image']) && is_array($p['featured_image']) ? ['featured_image' => $p['featured_image']] : [], $this->galleryWithKeys($p)) as $field => $img) {
            if (is_array($img)) {
                $this->checkKeys($report, $i, $ext, $img, self::IMAGE_KEYS, $field.'.');
            }
        }

        foreach ($p['specs'] ?? [] as $n => $spec) {
            if (is_array($spec)) {
                $this->checkKeys($report, $i, $ext, $spec, ['label', 'value', 'unit'], "specs.{$n}.");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function galleryWithKeys(array $p): array
    {
        $out = [];

        foreach ((array) ($p['gallery'] ?? []) as $n => $img) {
            $out["gallery.{$n}"] = $img;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $allowed
     */
    private function checkKeys(ValidationReport $report, int $i, ?string $ext, array $data, array $allowed, string $prefix): void
    {
        foreach (array_diff(array_keys($data), $allowed) as $key) {
            $report->error($i, $ext, $prefix.$key, 'nieznane pole');
        }
    }

    /**
     * @param  array<string, mixed>  $p
     * @param  array<string, mixed>  $ctx
     * @param  array<string, int>  $seenNames
     * @param  array<string, int>  $seenSlugs
     */
    private function checkCollisions(ValidationReport $report, int $i, ?string $ext, array $p, array $ctx, array &$seenNames, array &$seenSlugs): void
    {
        if (isset($p['name']) && is_string($p['name'])) {
            // Fold case AND diacritics: the column collation (utf8mb4_unicode_ci) does both, and
            // a false positive here is only an extra error, never a corrupt write.
            $folded = self::fold($p['name']);

            if (isset($ctx['names'][$folded])) {
                $report->error($i, $ext, 'name', "istnieje już produkt o tej nazwie w organizacji ('{$ctx['names'][$folded]}') — uniemożliwia to unikalność services_org_name_unique");
            }

            if (isset($seenNames[$folded])) {
                $report->error($i, $ext, 'name', "ta sama nazwa co produkt #{$seenNames[$folded]} w pliku");
            }

            $seenNames[$folded] ??= $i;
        }

        if (isset($p['slug']) && is_string($p['slug']) && $p['slug'] !== '') {
            $slug = self::fold($p['slug']);

            if (isset($ctx['slugs'][$slug])) {
                $report->error($i, $ext, 'slug', 'slug jest już zajęty w organizacji');
            }

            if (isset($seenSlugs[$slug])) {
                $report->error($i, $ext, 'slug', "ten sam slug co produkt #{$seenSlugs[$slug]} w pliku");
            }

            $seenSlugs[$slug] ??= $i;
        }
    }

    /**
     * @param  array<string, mixed>  $p
     * @param  array<string, mixed>  $ctx
     * @param  array<string, int>  $seenIdentifiers
     */
    private function checkStockAndUnits(ValidationReport $report, int $i, ?string $ext, array $p, array $ctx, array &$seenIdentifiers): void
    {
        $hasStock = isset($p['stock']);
        $hasUnits = isset($p['units']);

        if ($hasStock === $hasUnits) {
            $report->error($i, $ext, 'stock', 'podaj dokładnie jedno z: stock (ilość), units (egzemplarze) — jeden zapis stanu na produkt');

            return;
        }

        if ($hasStock) {
            $this->checkStock($report, $i, $ext, $p['stock'], $ctx);

            return;
        }

        $units = $p['units'];

        if (! is_array($units) || ! array_is_list($units) || $units === []) {
            $report->error($i, $ext, 'units', 'musi być niepustą listą');

            return;
        }

        if (count($units) > self::MAX_UNITS) {
            $report->error($i, $ext, 'units', 'maksymalnie '.self::MAX_UNITS.' egzemplarzy na produkt');

            return;
        }

        foreach ($units as $n => $unit) {
            $f = "units.{$n}";

            if (! is_array($unit)) {
                $report->error($i, $ext, $f, 'egzemplarz musi być obiektem');

                continue;
            }

            $this->checkKeys($report, $i, $ext, $unit, ['identifier', 'inventory_number', 'status', 'acquired_at', 'notes', 'location'], $f.'.');

            $v = Validator::make($unit, [
                'identifier' => 'nullable|string|max:255',
                'inventory_number' => 'nullable|string|max:255',
                'status' => ['nullable', Rule::in(array_column(ServiceUnitStatus::cases(), 'value'))],
                'acquired_at' => 'nullable|date',
                'notes' => 'nullable|string',
            ]);

            foreach ($v->errors()->messages() as $field => $messages) {
                foreach ($messages as $message) {
                    $report->error($i, $ext, "{$f}.{$field}", $message);
                }
            }

            if (is_string($unit['notes'] ?? null) && strlen($unit['notes']) > self::TEXT_MAX_BYTES) {
                $report->error($i, $ext, "{$f}.notes", 'tekst dłuższy niż '.self::TEXT_MAX_BYTES.' bajtów (kolumna TEXT)');
            }

            $location = $unit['location'] ?? null;

            if ($location !== null && ! is_string($location) && ! is_int($location)) {
                // an array/object would otherwise reach isset($ctx['locations'][$location]) as a TypeError
                $report->error($i, $ext, "{$f}.location", 'musi być tekstem (slugiem oddziału)');
            } elseif ($location === null && $ctx['activeLocationCount'] !== 1) {
                $report->error($i, $ext, "{$f}.location", 'wymagane, gdy organizacja nie ma dokładnie jednego aktywnego oddziału');
            } elseif ($location !== null && ! isset($ctx['locations'][(string) $location])) {
                $report->error($i, $ext, "{$f}.location", "brak aktywnego oddziału o slugu '{$location}'");
            }

            $identifier = $unit['identifier'] ?? null;

            if (is_string($identifier) && $identifier !== '') {
                $key = self::fold($identifier);

                if (isset($ctx['identifiers'][$key])) {
                    $report->error($i, $ext, "{$f}.identifier", 'numer jest już zajęty w organizacji (service_units_org_identifier_unique)');
                }

                if (isset($seenIdentifiers[$key])) {
                    $report->error($i, $ext, "{$f}.identifier", 'zduplikowany w pliku');
                }

                $seenIdentifiers[$key] ??= $i;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private function checkStock(ValidationReport $report, int $i, ?string $ext, mixed $stock, array $ctx): void
    {
        if (! is_array($stock) || array_is_list($stock)) {
            $report->error($i, $ext, 'stock', 'musi być obiektem {"quantity":N} lub {"locations":{"slug":N}}');

            return;
        }

        $hasQuantity = array_key_exists('quantity', $stock);
        $hasLocations = array_key_exists('locations', $stock);

        if ($hasQuantity === $hasLocations) {
            $report->error($i, $ext, 'stock', 'podaj dokładnie jedno z: quantity, locations');

            return;
        }

        if ($hasQuantity) {
            // ServiceResource.php:277-306 — the single "Ilość w magazynie" field exists only for
            // a tenant with exactly ONE active location (min 1).
            if ($ctx['activeLocationCount'] !== 1) {
                $report->error($i, $ext, 'stock.quantity', 'wymaga dokładnie jednego aktywnego oddziału — użyj stock.locations');
            }

            if (! is_int($stock['quantity']) || $stock['quantity'] < 1 || $stock['quantity'] > 1_000_000) {
                $report->error($i, $ext, 'stock.quantity', 'musi być liczbą całkowitą >= 1');
            }

            return;
        }

        $locations = $stock['locations'];

        if (! is_array($locations) || $locations === [] || array_is_list($locations)) {
            $report->error($i, $ext, 'stock.locations', 'musi być obiektem {"slug-oddzialu": ilość}');

            return;
        }

        $sum = 0;

        foreach ($locations as $slug => $qty) {
            if (! isset($ctx['locations'][$slug])) {
                $report->error($i, $ext, "stock.locations.{$slug}", 'brak aktywnego oddziału o tym slugu');
            }

            if (! is_int($qty) || $qty < 0 || $qty > 1_000_000) {
                $report->error($i, $ext, "stock.locations.{$slug}", 'musi być liczbą całkowitą >= 0');

                continue;
            }

            $sum += $qty;
        }

        if ($sum < 1) {
            $report->error($i, $ext, 'stock.locations', 'łączna ilość musi wynosić co najmniej 1');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function context(Organization $org): array
    {
        $locations = Location::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->where('is_active', true)
            ->get(['id', 'slug', 'primary_slot']);

        $names = [];
        $slugs = [];

        Service::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->get(['name', 'slug'])
            ->each(function (Service $s) use (&$names, &$slugs): void {
                $names[self::fold($s->name)] = $s->name;
                $slugs[self::fold((string) $s->slug)] = true;
            });

        $identifiers = ServiceUnit::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->whereNotNull('identifier')
            ->pluck('identifier')
            ->mapWithKeys(fn (string $identifier) => [self::fold($identifier) => true])
            ->all();

        return [
            'icons' => self::heroiconNames(),
            'locations' => $locations->keyBy('slug')->all(),
            'activeLocationCount' => $locations->count(),
            'names' => $names,
            'slugs' => $slugs,
            'identifiers' => $identifiers,
        ];
    }
}
