<?php

declare(strict_types=1);

namespace App\Models\Concerns;

/**
 * Collapses a legacy dict-shaped `metadata.specs` (`{"power_w": 800}`) into
 * the canonical list shape ServiceResource's Repeater actually understands
 * (`[{label, value, unit}]`) before every save — not only saves made through
 * the panel. A Repeater bound to a dict does not error; it silently rewrites
 * the field to a list of empty rows on the very next save, destroying the
 * specs (ClickUp 123k99ct3j1). This hangs on `saving`, so it closes off
 * every write path that could reintroduce a dict — API, console, a future
 * seeder — not just the one historical producer (SeedEquipmentRental, fixed
 * directly at the source) and the one-time backfill migration
 * (`2026_08_30_140000_normalize_service_specs_metadata_shape.php`) that
 * cleans up the rows that already exist.
 *
 * Deliberately scoped narrower than "fixes the panel bug": by the time
 * `saving` fires, a Livewire request that already fed a dict through the
 * Repeater has ALREADY had its state mangled client-side — this cannot
 * undo that. What it guarantees is that a DICT NEVER REACHES THE DATABASE
 * from a non-Filament write path in the first place, so the panel never
 * gets a chance to see one again once the existing rows are backfilled.
 *
 * Key labels/units mirror app/Enums/Industry.php::defaultSpecDefinitions()
 * where the key is recognized. An unmapped key falls back to the same
 * humanization the legacy blade renderer already used
 * (resources/views/services/show.blade.php: `ucfirst(str_replace('_', ' ',
 * $key))`), with a `null` unit — so a key nobody has mapped yet degrades to
 * today's existing legacy-render behavior instead of losing the row.
 *
 * CANONICAL EMPTY UNIT IS `null`, NEVER `''` (2026-08-30, RC26 MySQL gate
 * fix). Not a stylistic choice: `ServiceResource`'s `TextInput::make('unit')`
 * sits inside a Repeater, and Filament core's own `HasState::getRawState()`
 * (`vendor/filament/schemas/.../Concerns/HasState.php`) unconditionally
 * coerces any blank, non-array field state to `null` every time the form's
 * state is read for save — this is unconditional Filament framework
 * behavior, not something this codebase can opt out of per-field. A row
 * seeded/migrated with `unit: ''` therefore gets silently rewritten to
 * `unit: null` on the very next save, even with zero user-visible change —
 * `PanelWalkthroughTest`'s "existing record saved with no changes must be
 * byte-identical" check exists exactly to catch this class of drift. Seed
 * (`SeedEquipmentRental`) and backfill (`2026_08_30_140000_normalize_service_
 * specs_metadata_shape.php`) now emit `null` to match, so a no-op save is a
 * true no-op. `resources/views/services/show.blade.php:114` already reads
 * `$spec['unit'] ?? ''`, so `null` and `''` render identically — do not
 * "fix" a new empty-unit producer back to `''`.
 */
trait NormalizesSpecsShape
{
    protected static function bootNormalizesSpecsShape(): void
    {
        static::saving(function (self $model) {
            $metadata = $model->metadata;

            if (! is_array($metadata) || ! isset($metadata['specs']) || ! is_array($metadata['specs'])) {
                return;
            }

            if (array_is_list($metadata['specs'])) {
                return;
            }

            $metadata['specs'] = static::specsDictToList($metadata['specs']);
            $model->metadata = $metadata;
        });
    }

    /**
     * @param  array<string, mixed>  $dict
     * @return array<int, array{label: string, value: mixed, unit: ?string}>
     */
    public static function specsDictToList(array $dict): array
    {
        $labels = static::specsKeyLabels();

        $list = [];

        foreach ($dict as $key => $value) {
            [$label, $unit] = $labels[$key] ?? [ucfirst(str_replace('_', ' ', (string) $key)), null];

            if (filled($unit) && is_string($value) && str_ends_with($value, $unit)) {
                $value = trim(substr($value, 0, -strlen($unit)));
            }

            $list[] = ['label' => $label, 'value' => $value, 'unit' => $unit];
        }

        return $list;
    }

    /**
     * Deliberately duplicated (not shared with the migration): a migration
     * must keep producing the SAME output it produced the day it ran, even
     * if this map is edited later for the live model — see
     * migrations.md's "a migration should not depend on application state
     * that can change shape independently of the schema it's pinned to."
     *
     * @return array<string, array{0: string, 1: ?string}>
     */
    private static function specsKeyLabels(): array
    {
        return [
            'power_w' => ['Moc', 'W'],
            'power_kw' => ['Moc', 'kW'],
            'power_hp' => ['Moc', 'KM'],
            'weight_kg' => ['Waga', 'kg'],
            'disc_mm' => ['Średnica tarczy', 'mm'],
            'fuel_type' => ['Rodzaj paliwa', null],
            'capacity_l' => ['Pojemność', 'l'],
            'capacity_l_day' => ['Wydajność', 'l/dobę'],
            'voltage' => ['Napięcie', 'V'],
            'height_m' => ['Wysokość', 'm'],
            'width_m' => ['Szerokość', 'm'],
            'working_width_cm' => ['Szerokość robocza', 'cm'],
            'max_branch_cm' => ['Maks. średnica gałęzi', 'cm'],
            'pressure_bar' => ['Ciśnienie', 'bar'],
        ];
    }
}
