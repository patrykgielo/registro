<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Actions\Onboarding\Seeders\SeedEquipmentRental;
use App\Models\Organization;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * ClickUp 123k99ct3j1 root cause: SeedEquipmentRental used to write
 * metadata.specs as a dict (`{"power_w": 800}`), which ServiceResource's
 * Repeater ('metadata.specs', schema: label/value/unit) cannot read — a
 * no-op save through the panel silently rewrote it to a list of empty rows.
 * Pins that every item this seeder produces is already in the shape the
 * Repeater expects, so a save-with-no-changes right after onboarding can
 * never destroy it.
 */
class SeedEquipmentRentalSpecsShapeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Reads the seeder's raw catalog data via reflection — deliberately
     * bypasses Eloquent entirely, so this proves the SEEDER SOURCE itself
     * was fixed, independent of App\Models\Concerns\NormalizesSpecsShape
     * (which would otherwise mask a still-broken seeder, since it
     * self-heals a dict at save time on the very next test below).
     */
    public function test_seeder_catalog_never_declares_a_dict_shaped_specs_field(): void
    {
        $method = new ReflectionMethod(SeedEquipmentRental::class, 'getCatalog');
        $method->setAccessible(true);
        $catalog = $method->invoke(new SeedEquipmentRental);

        $checked = 0;

        foreach ($catalog as $category) {
            foreach ($category['items'] as $item) {
                $specs = $item['specifications']['specs'] ?? null;

                if ($specs === null) {
                    continue;
                }

                $checked++;
                $this->assertTrue(array_is_list($specs), "'{$item['name']}' declares specs as a dict, not a list");

                foreach ($specs as $entry) {
                    $this->assertArrayHasKey('label', $entry);
                    $this->assertArrayHasKey('value', $entry);
                    $this->assertArrayHasKey('unit', $entry);
                }
            }
        }

        $this->assertGreaterThan(0, $checked, 'fixture assumption: at least one catalog item declares specs');
    }

    public function test_every_seeded_item_with_specs_uses_the_canonical_list_shape(): void
    {
        $org = Organization::factory()->equipmentRental()->create();

        (new SeedEquipmentRental)->seed($org);

        $services = Service::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->get();

        $this->assertGreaterThan(0, $services->count());

        $servicesWithSpecs = $services->filter(fn (Service $s) => isset($s->metadata['specs']));
        $this->assertGreaterThan(0, $servicesWithSpecs->count(), 'fixture assumption: at least one seeded item has specs');

        foreach ($servicesWithSpecs as $service) {
            $specs = $service->metadata['specs'];

            $this->assertTrue(
                array_is_list($specs),
                "service '{$service->name}' specs must be a list, not a dict"
            );

            foreach ($specs as $entry) {
                $this->assertIsArray($entry);
                $this->assertArrayHasKey('label', $entry);
                $this->assertArrayHasKey('value', $entry);
                $this->assertArrayHasKey('unit', $entry);
                $this->assertNotEmpty($entry['label']);
                $this->assertNotSame('', (string) $entry['value']);
            }
        }
    }
}
