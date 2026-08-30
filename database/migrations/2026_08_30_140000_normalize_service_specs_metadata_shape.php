<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One-time data fix for ClickUp 123k99ct3j1: `services.metadata->specs` was
 * historically seeded by SeedEquipmentRental as a dict (`{"power_w": 800}`),
 * but ServiceResource's Repeater on `metadata.specs` only understands a list
 * (`[{"label": ..., "value": ..., "unit": ...}]`) — resources/views/services/
 * show.blade.php:105-110 already distinguishes the two explicitly ("new
 * format" vs "legacy") and renders both, which is how this shipped unnoticed
 * for months: reading a dict is harmless. Saving one through the panel is
 * not — the Repeater rewrites an unrecognized dict to a list of empty rows,
 * silently destroying the specs. Measured on dev MySQL 2026-08-30: 24
 * services with dict-shaped specs, 2 already correct (list), 30 with no
 * `specs` key at all.
 *
 * Uses DB::table(), never Eloquent — bypasses BelongsToOrganization's global
 * scope (this must touch every tenant, not just whichever one context
 * resolves to when the migration runs — see
 * RecalculateDailyStatisticsJob for the same reasoning) and, per
 * migrations.md, keeps this migration's behavior pinned to the schema, not
 * to app/Models/Concerns/NormalizesSpecsShape.php (which exists
 * separately, as the standing defense against a FUTURE write reintroducing
 * a dict — see that file's docblock). The two files intentionally carry
 * their own independent copies of the same key/label/unit map.
 *
 * Key mapping applied (source: keys actually observed in SeedEquipmentRental
 * and dev MySQL — every dict-shaped row in this project's history uses only
 * these 14 keys; nothing else has ever written to metadata->specs, confirmed
 * by grepping app/** for writes to 'specs'/'metadata.specs' outside
 * ServiceResource.php and SeedEquipmentRental.php):
 *
 * | key               | label (PL)            | unit    |
 * |-------------------|------------------------|---------|
 * | power_w           | Moc                    | W       |
 * | power_kw          | Moc                    | kW      |
 * | power_hp          | Moc                    | KM      |
 * | weight_kg         | Waga                   | kg      |
 * | disc_mm           | Średnica tarczy        | mm      |
 * | fuel_type         | Rodzaj paliwa          | (none)  |
 * | capacity_l        | Pojemność              | l       |
 * | capacity_l_day    | Wydajność              | l/dobę  |
 * | voltage           | Napięcie               | V       |
 * | height_m          | Wysokość               | m       |
 * | width_m           | Szerokość              | m       |
 * | working_width_cm  | Szerokość robocza      | cm      |
 * | max_branch_cm     | Maks. średnica gałęzi  | cm      |
 * | pressure_bar      | Ciśnienie              | bar     |
 *
 * A key NOT in this table (none exist today, but a future dict written by
 * something other than SeedEquipmentRental before this migration ships
 * everywhere could have one) falls back to
 * `ucfirst(str_replace('_', ' ', $key))` with an empty unit — identical to
 * the legacy-format humanization show.blade.php already applies when
 * reading a dict today, so an unmapped key degrades to today's existing
 * display, never to a blank row.
 *
 * `voltage`'s stored value already embeds its own unit as a string
 * (`"230V"`), unlike every other key (plain numbers). If the value is a
 * string ending with the row's own unit, that suffix is stripped before
 * building the {value, unit} pair — otherwise the panel would render
 * "230V V".
 */
return new class extends Migration
{
    private const KEY_LABELS = [
        'power_w' => ['Moc', 'W'],
        'power_kw' => ['Moc', 'kW'],
        'power_hp' => ['Moc', 'KM'],
        'weight_kg' => ['Waga', 'kg'],
        'disc_mm' => ['Średnica tarczy', 'mm'],
        'fuel_type' => ['Rodzaj paliwa', ''],
        'capacity_l' => ['Pojemność', 'l'],
        'capacity_l_day' => ['Wydajność', 'l/dobę'],
        'voltage' => ['Napięcie', 'V'],
        'height_m' => ['Wysokość', 'm'],
        'width_m' => ['Szerokość', 'm'],
        'working_width_cm' => ['Szerokość robocza', 'cm'],
        'max_branch_cm' => ['Maks. średnica gałęzi', 'cm'],
        'pressure_bar' => ['Ciśnienie', 'bar'],
    ];

    public function up(): void
    {
        DB::table('services')
            ->whereNotNull('metadata')
            ->orderBy('id')
            ->select(['id', 'metadata'])
            ->chunkById(200, function ($services) {
                foreach ($services as $service) {
                    $metadata = json_decode($service->metadata, true);

                    if (! is_array($metadata) || ! isset($metadata['specs']) || ! is_array($metadata['specs'])) {
                        continue;
                    }

                    // Rows already in list shape (correctly saved through the
                    // panel, or a service with no specs at all) are left
                    // completely untouched — including empty `[]`, which
                    // array_is_list() also treats as a list.
                    if (array_is_list($metadata['specs'])) {
                        continue;
                    }

                    $metadata['specs'] = self::dictToList($metadata['specs']);

                    DB::table('services')->where('id', $service->id)->update([
                        'metadata' => json_encode($metadata),
                    ]);
                }
            });
    }

    /**
     * Reverses list shape back to dict shape for EVERY service currently
     * holding a non-empty list in metadata->specs — not only the rows up()
     * converted. There is no column or marker that distinguishes "this list
     * came from up()" from "this list was typed by hand into the Repeater
     * after up() ran" or "this list was already correct before up() ever
     * ran" — all three are indistinguishable JSON by the time down() runs.
     * This is a deliberately blunt, best-effort rollback for a data-only
     * migration, not a scoped undo: rolling back after real panel edits
     * have happened will fold those edits' order and units away too.
     *
     * Even for a row genuinely converted by up(), the reversal is lossy on
     * three axes, unavoidably: order (dict key order is not guaranteed
     * preserved through MySQL's JSON storage in the first place — verified
     * empirically before writing this migration: SeedEquipmentRental
     * inserted `capacity_l` before `voltage` for one row, dev MySQL returned
     * `voltage` first), unit (dropped — a dict has no unit slot), and the
     * original snake_case key (Str::slug() of the label is a best-effort
     * approximation, e.g. "Średnica tarczy" -> "srednica_tarczy", not the
     * original "disc_mm" — Polish diacritics do not round-trip through
     * ASCII transliteration).
     */
    public function down(): void
    {
        DB::table('services')
            ->whereNotNull('metadata')
            ->orderBy('id')
            ->select(['id', 'metadata'])
            ->chunkById(200, function ($services) {
                foreach ($services as $service) {
                    $metadata = json_decode($service->metadata, true);

                    if (! is_array($metadata) || ! isset($metadata['specs']) || ! is_array($metadata['specs'])) {
                        continue;
                    }

                    if (! array_is_list($metadata['specs']) || $metadata['specs'] === []) {
                        continue;
                    }

                    $metadata['specs'] = self::listToDict($metadata['specs']);

                    DB::table('services')->where('id', $service->id)->update([
                        'metadata' => json_encode($metadata),
                    ]);
                }
            });
    }

    /**
     * @param  array<string, mixed>  $dict
     * @return array<int, array{label: string, value: mixed, unit: string}>
     */
    private static function dictToList(array $dict): array
    {
        $list = [];

        foreach ($dict as $key => $value) {
            [$label, $unit] = self::KEY_LABELS[$key] ?? [ucfirst(str_replace('_', ' ', (string) $key)), ''];

            if ($unit !== '' && is_string($value) && str_ends_with($value, $unit)) {
                $value = trim(substr($value, 0, -strlen($unit)));
            }

            $list[] = ['label' => $label, 'value' => $value, 'unit' => $unit];
        }

        return $list;
    }

    /**
     * @param  array<int, array<string, mixed>>  $list
     * @return array<string, mixed>
     */
    private static function listToDict(array $list): array
    {
        $dict = [];

        foreach ($list as $entry) {
            $label = (string) ($entry['label'] ?? '');
            $key = $label !== '' ? Str::slug($label, '_') : 'param_'.count($dict);

            // Collision between two rows whose labels slug to the same key
            // (e.g. two "Moc" entries with different units) — last one
            // wins, same as any PHP array literal with a repeated key.
            $dict[$key] = $entry['value'] ?? null;
        }

        return $dict;
    }
};
