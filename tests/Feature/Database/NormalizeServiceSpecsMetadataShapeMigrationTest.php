<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\Organization;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Executes migrate:rollback (not a static `down()` regex) for
 * 2026_08_30_140000_normalize_service_specs_metadata_shape.php — same
 * "wykonywanego rollbacku" pattern as
 * tests/Feature/Database/CreateLocationsTableMigrationTest.php and
 * BackfillServiceLocationStocksMigrationTest.
 *
 * Fixture rows are written via DB::table()->update() AFTER the factory
 * create, not via Service::factory()->create(['metadata' => ...]) — the
 * latter would go through Eloquent's `saving` event and
 * App\Models\Concerns\NormalizesSpecsShape would normalize the dict away
 * before it ever reached the database, defeating the point of these
 * fixtures (they simulate rows written BEFORE that trait existed, i.e. by
 * the historical, now-fixed SeedEquipmentRental).
 */
class NormalizeServiceSpecsMetadataShapeMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_08_30_140000_normalize_service_specs_metadata_shape.php';

    public function test_up_converts_a_dict_shaped_specs_field_to_the_canonical_list_shape(): void
    {
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();

        $service = $this->serviceWithRawMetadata(['specs' => ['power_w' => 800, 'weight_kg' => 4.2]]);

        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();

        $metadata = $this->rawMetadata($service->id);

        $this->assertSpecsMatch([
            ['label' => 'Moc', 'value' => 800, 'unit' => 'W'],
            ['label' => 'Waga', 'value' => 4.2, 'unit' => 'kg'],
        ], $metadata['specs']);
    }

    public function test_up_strips_the_unit_already_embedded_in_a_string_value(): void
    {
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();

        $service = $this->serviceWithRawMetadata(['specs' => ['voltage' => '230V', 'capacity_l' => 200]]);

        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();

        $metadata = $this->rawMetadata($service->id);

        $this->assertSpecsMatch([
            ['label' => 'Napięcie', 'value' => '230', 'unit' => 'V'],
            ['label' => 'Pojemność', 'value' => 200, 'unit' => 'l'],
        ], $metadata['specs']);
    }

    public function test_up_falls_back_to_humanized_label_for_an_unmapped_key(): void
    {
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();

        $service = $this->serviceWithRawMetadata(['specs' => ['color_hex' => 'red']]);

        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();

        $metadata = $this->rawMetadata($service->id);

        // Canonical empty unit is `null`, not `''` — see
        // NormalizesSpecsShape.php's docblock: Filament's own TextInput
        // coerces a blank unit to `null` on the very next save regardless,
        // so a migration emitting `''` here would just get silently
        // rewritten the first time anyone opens this service in the panel.
        $this->assertSpecsMatch([
            ['label' => 'Color hex', 'value' => 'red', 'unit' => null],
        ], $metadata['specs']);
    }

    /**
     * The core "musi zostać nietknięte" requirement: rows already in list
     * shape and rows without a `specs` key at all must not change AT ALL —
     * asserted byte-for-byte against the raw stored JSON, not just
     * structurally, so even a re-serialization with different key order
     * would fail this test.
     */
    public function test_up_leaves_list_shaped_and_specs_less_rows_completely_untouched(): void
    {
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();

        $alreadyList = $this->serviceWithRawMetadata([
            'specs' => [['label' => 'Moc', 'value' => 800, 'unit' => 'W']],
        ]);
        $noSpecs = $this->serviceWithRawMetadata(['prices_by_size' => ['A' => 150]]);
        $emptySpecs = $this->serviceWithRawMetadata(['specs' => []]);

        $rawBefore = [
            $alreadyList->id => DB::table('services')->where('id', $alreadyList->id)->value('metadata'),
            $noSpecs->id => DB::table('services')->where('id', $noSpecs->id)->value('metadata'),
            $emptySpecs->id => DB::table('services')->where('id', $emptySpecs->id)->value('metadata'),
        ];

        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();

        foreach ($rawBefore as $id => $before) {
            $after = DB::table('services')->where('id', $id)->value('metadata');
            $this->assertSpecsMatch(
                (array) json_decode($before, true),
                (array) json_decode($after, true),
                "service #{$id} metadata must be left untouched by up()"
            );
        }
    }

    /**
     * Counter-based verification across a mixed batch, mirroring the exact
     * shape distribution measured on dev MySQL (dict / list / no specs) —
     * the migration must convert precisely the dict rows and nothing else.
     */
    public function test_up_converts_exactly_the_dict_shaped_rows_in_a_mixed_batch(): void
    {
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();

        $dictA = $this->serviceWithRawMetadata(['specs' => ['power_w' => 100]]);
        $dictB = $this->serviceWithRawMetadata(['specs' => ['weight_kg' => 5]]);
        $list = $this->serviceWithRawMetadata(['specs' => [['label' => 'X', 'value' => 1, 'unit' => '']]]);
        $none = $this->serviceWithRawMetadata(['foo' => 'bar']);

        $countDictBefore = $this->countDictShapedSpecs();
        $this->assertSame(2, $countDictBefore);

        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();

        $this->assertSame(0, $this->countDictShapedSpecs());
        $this->assertTrue(array_is_list($this->rawMetadata($dictA->id)['specs']));
        $this->assertTrue(array_is_list($this->rawMetadata($dictB->id)['specs']));
        $this->assertSpecsMatch(
            [['label' => 'X', 'value' => 1, 'unit' => '']],
            $this->rawMetadata($list->id)['specs']
        );
        $this->assertArrayNotHasKey('specs', $this->rawMetadata($none->id));
    }

    /**
     * down() is a deliberately blunt, lossy, indiscriminate rollback — see
     * the migration file's own docblock. This pins that it runs without
     * error and produces a dict (order/unit no longer recoverable), not
     * that it perfectly restores the original shape.
     */
    public function test_down_converts_list_shaped_specs_back_to_a_dict(): void
    {
        $service = $this->serviceWithRawMetadata([
            'specs' => [['label' => 'Moc', 'value' => 800, 'unit' => 'W']],
        ]);

        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();

        $metadata = $this->rawMetadata($service->id);

        $this->assertIsArray($metadata['specs']);
        $this->assertFalse(array_is_list($metadata['specs']));
        $this->assertSame(800, $metadata['specs']['moc']);

        // Re-migrate so RefreshDatabase's teardown finds the expected state.
        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();
    }

    private function serviceWithRawMetadata(array $metadata): Service
    {
        $org = Organization::factory()->equipmentRental()->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id]);

        DB::table('services')->where('id', $service->id)->update([
            'metadata' => json_encode($metadata),
        ]);

        return $service;
    }

    /**
     * @return array<string, mixed>
     */
    private function rawMetadata(int $serviceId): array
    {
        return json_decode(DB::table('services')->where('id', $serviceId)->value('metadata'), true);
    }

    private function countDictShapedSpecs(): int
    {
        $count = 0;

        foreach (DB::table('services')->whereNotNull('metadata')->pluck('metadata') as $raw) {
            $metadata = json_decode($raw, true);

            if (is_array($metadata) && isset($metadata['specs']) && is_array($metadata['specs']) && ! array_is_list($metadata['specs'])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * MySQL's native json type normalizes object key order on write; SQLite keeps the
     * text verbatim. assertSame() on decoded associative arrays is therefore
     * order-sensitive in a way that has nothing to do with what these tests assert --
     * key order inside a JSON object carries no meaning and the app always reads by key.
     * Sorting both sides recursively compares the data instead of the storage engine.
     */
    private function assertSpecsMatch(array $expected, array $actual, string $message = ''): void
    {
        $sort = function (array $value) use (&$sort): array {
            foreach ($value as $k => $v) {
                if (is_array($v)) {
                    $value[$k] = $sort($v);
                }
            }
            ksort($value);

            return $value;
        };

        $this->assertSame($sort($expected), $sort($actual), $message);
    }
}
