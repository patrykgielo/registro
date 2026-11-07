<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Actions\Onboarding\ProvisionTenantOrganization;
use App\Enums\Industry;
use App\Filament\Pages\SystemSettings;
use App\Filament\Resources\BaseResource;
use App\Filament\Resources\RentalCategoryResource;
use App\Filament\Resources\RentalCategoryResource\Pages\EditRentalCategory;
use App\Filament\Resources\ServiceResource;
use App\Filament\Support\BuilderBlocks;
use App\Models\RentalCategory;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The three icon pickers glob the blade-heroicons svg dir and strip the file prefix ("s-", "o-").
 * A bare str_replace() removed EVERY occurrence, so "s-arrows-pointing-in" became
 * "arrowpointing-in" -- a name no renderer can resolve (ViewException -> 500 on public pages),
 * and, because Filament's Select validates against options(), a record holding the REAL name
 * ("squares-2x2", written by SeedEquipmentRental) could not be saved in the admin.
 */
class HeroiconOptionsTest extends TestCase
{
    use RefreshDatabase;

    private const SVG_DIR = 'vendor/blade-ui-kit/blade-heroicons/resources/svg';

    /**
     * @return array<string, array{0: class-string, 1: 'solid'|'outline'}>
     */
    public static function pickers(): array
    {
        return [
            'BaseResource (solid)' => [BaseResource::class, 'solid'],
            'ServiceResource (solid, inherited)' => [ServiceResource::class, 'solid'],
            'RentalCategoryResource (solid, inherited)' => [RentalCategoryResource::class, 'solid'],
            'BuilderBlocks (outline)' => [BuilderBlocks::class, 'outline'],
            'SystemSettings (outline)' => [SystemSettings::class, 'outline'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function optionsOf(string $class): array
    {
        return (new \ReflectionMethod($class, 'getHeroiconOptions'))->invoke(null);
    }

    /**
     * Svg file names for one prefix, with ONLY the leading "{prefix}-" removed -- computed
     * independently of the code under test.
     *
     * @return list<string>
     */
    private function expectedNames(string $prefix): array
    {
        $files = glob(base_path(self::SVG_DIR).'/'.$prefix.'-*.svg');
        $this->assertNotEmpty($files, "No {$prefix}-*.svg found -- the blade-heroicons directory moved?");

        return array_map(
            fn (string $file) => substr(basename($file, '.svg'), strlen($prefix) + 1),
            $files,
        );
    }

    #[DataProvider('pickers')]
    public function test_every_option_key_resolves_to_a_real_svg_for_every_prefix_the_renderers_use(string $class, string $style): void
    {
        $prefixes = $style === 'solid' ? ['m', 's'] : ['o'];

        foreach (array_keys($this->optionsOf($class)) as $key) {
            foreach ($prefixes as $prefix) {
                $this->assertFileExists(
                    base_path(self::SVG_DIR."/{$prefix}-{$key}.svg"),
                    "Option key '{$key}' has no heroicon-{$prefix}-{$key} svg -- the prefix strip mangled it.",
                );
            }
        }
    }

    #[DataProvider('pickers')]
    public function test_option_keys_equal_the_svg_names_with_only_the_leading_prefix_stripped(string $class, string $style): void
    {
        $expected = $this->expectedNames($style === 'solid' ? 's' : 'o');
        $actual = array_keys($this->optionsOf($class));

        sort($expected);
        sort($actual);

        $this->assertSame($expected, $actual);
    }

    #[DataProvider('pickers')]
    public function test_names_with_an_inner_prefix_lookalike_survive(string $class, string $style): void
    {
        $options = $this->optionsOf($class);

        if ($style === 'solid') {
            $this->assertArrayHasKey('arrows-pointing-in', $options);
            $this->assertArrayHasKey('squares-2x2', $options);
            $this->assertArrayNotHasKey('arrowpointing-in', $options);
            $this->assertSame('Arrows Pointing In', $options['arrows-pointing-in']);
        } else {
            $this->assertArrayHasKey('video-camera', $options);
            $this->assertArrayHasKey('no-symbol', $options);
            $this->assertArrayNotHasKey('videcamera', $options);
            $this->assertSame('No Symbol', $options['no-symbol']);
        }
    }

    public function test_rental_category_with_a_seeded_inner_s_icon_saves_unchanged(): void
    {
        foreach (['super-admin', 'admin', 'staff', 'customer'] as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $result = app(ProvisionTenantOrganization::class)->execute(
            slug: 'heroicon-tenant',
            name: 'Wypożyczalnia Ikon',
            industry: Industry::EquipmentRental,
            ownerEmail: 'owner@heroicon.test',
            ownerFirstName: 'Ida',
            ownerLastName: 'Ikonowa',
        );

        // "squares-2x2" is exactly what SeedEquipmentRental writes for "Rusztowania".
        $category = RentalCategory::factory()->create([
            'organization_id' => $result['organization']->id,
            'name' => 'Rusztowania testowe',
            'slug' => 'rusztowania-testowe',
            'icon' => 'squares-2x2',
        ]);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        session(['tenant_id' => $result['organization']->id]);
        $this->actingAs($result['owner']);

        $component = Livewire::test(EditRentalCategory::class, ['record' => $category->getRouteKey()])
            ->call('save');

        $this->assertTrue(
            $component->errors()->isEmpty(),
            'No-op save produced form errors: '.json_encode($component->errors()->toArray()),
        );
        $this->assertSame('squares-2x2', $category->fresh()->icon);
    }
}
