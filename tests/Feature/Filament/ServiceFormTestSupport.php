<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\ServiceResource\Pages\EditService;
use App\Models\Location;
use App\Models\Organization;
use App\Models\RentalCategory;
use App\Models\Service;
use App\Models\ServiceLocationStock;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * Shared scaffolding for the STAGE 0 "safety net" characterization tests of the admin product form
 * (ServiceFormEditCharacterizationTest, ServiceFormCreateCharacterizationTest).
 *
 * Those tests pin what ServiceResource's form + CreateService/EditService do TODAY, so a later layout
 * refactor (tabs, per-tab save) can prove it lost nothing. They deliberately assert on DB state,
 * on `data.<column>` state paths, on validation errors by field and on rendered text - never on
 * Filament internals a pure layout change would legitimately alter.
 */
trait ServiceFormTestSupport
{
    protected Organization $tenant;

    protected User $admin;

    private ?RentalCategory $defaultCategory = null;

    /** @var array{all: array<int, string>, dehydrated: array<int, string>}|null */
    private ?array $fieldInventory = null;

    /** @var array<int, string> files that must exist on the (faked) public disk - FileUpload drops state for missing files */
    private const FIXTURE_FILES = [
        'services/featured/a.jpg',
        'services/featured/b.jpg',
        'services/images/x.jpg',
        'services/galleries/a.jpg',
        'services/galleries/b.jpg',
    ];

    /**
     * Same tenant/panel/auth bootstrap as ServiceResourceQuantityFieldRoutingTest.
     * NO location is created here: the number of active locations decides how "Ilość w magazynie"
     * behaves, so every test states its own.
     */
    protected function setUpServiceFormTenant(): void
    {
        foreach (['super-admin', 'admin', 'staff'] as $name) {
            Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        $this->tenant = Organization::factory()->equipmentRental()->create();
        session(['tenant_id' => $this->tenant->id]);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
        $this->admin->organizations()->attach($this->tenant->id, ['role' => 'admin']);
        $this->actingAs($this->admin);

        Storage::fake('public');
        foreach (self::FIXTURE_FILES as $path) {
            Storage::disk('public')->put($path, 'x');
        }
    }

    /** The tenant's first location; LocationObserver promotes it to primary. Create it BEFORE any service. */
    protected function addLocation(string $name = 'Siedziba', bool $active = true): Location
    {
        return Location::factory()->for($this->tenant, 'organization')->create([
            'name' => $name,
            'slug' => Str::slug($name),
            'is_active' => $active,
        ]);
    }

    protected function category(string $name = 'Elektronarzędzia'): RentalCategory
    {
        if ($name === 'Elektronarzędzia' && $this->defaultCategory) {
            return $this->defaultCategory;
        }

        $category = RentalCategory::factory()->for($this->tenant, 'organization')->create(['name' => $name, 'slug' => Str::slug($name)]);

        if ($name === 'Elektronarzędzia') {
            $this->defaultCategory = $category;
        }

        return $category;
    }

    /**
     * A rental whose every optional column is deterministic (the factory randomises several).
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function rentalService(array $overrides = []): Service
    {
        return Service::factory()->itemRental()->create(array_merge([
            'organization_id' => $this->tenant->id,
            'rental_category_id' => $this->category()->id,
            'name' => 'Wiertarka testowa',
            'slug' => 'wiertarka-testowa',
            'description' => null,
            'price' => 100,
            'price_per_day' => 100,
            'price_per_hour' => null,
            'price_per_week' => null,
            'price_per_day_long' => 80,
            'price_threshold_days' => 5,
            'deposit_amount' => null,
            'quantity_total' => 1,
            'sort_order' => 5,
            'metadata' => ['specs' => [['label' => 'Moc', 'value' => '800', 'unit' => 'W']]],
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function timeSlotService(array $overrides = []): Service
    {
        return Service::factory()->create(array_merge([
            'organization_id' => $this->tenant->id,
            'name' => 'Mycie auta',
            'slug' => 'mycie-auta',
            'description' => null,
            'duration_minutes' => 60,
            'price' => 200,
            'sort_order' => 5,
        ], $overrides));
    }

    protected function reload(Service|int $service): Service
    {
        return Service::withoutGlobalScopes()->findOrFail($service instanceof Service ? $service->id : $service);
    }

    protected function openEdit(Service $service): Testable
    {
        return Livewire::test(EditService::class, ['record' => $service->getRouteKey()]);
    }

    /** The single way these tests press "Zapisz" - one place to change when the save mechanics change. */
    protected function saveForm(Testable $component): Testable
    {
        return $component->call('save');
    }

    protected function saveUnchanged(Service $service): Testable
    {
        return $this->saveForm($this->openEdit($service))->assertHasNoFormErrors();
    }

    // ---------------------------------------------------------------------------------------------
    // What the mounted form binds - derived, never hand-listed
    // ---------------------------------------------------------------------------------------------

    /**
     * Top-level fields of the mounted form, read from the form itself (layout-neutral: Sections, Tabs
     * and Grids do not matter), unioned over a rental and a time_slot page because the type decides
     * visibility, and a hidden field is not dehydrated.
     *
     * - `all`: every field name, whatever its visibility or dehydration (`metadata.specs`, not the
     *   per-row fields inside the repeater).
     * - `dehydrated`: the names whose value reaches the model on save for at least one service type.
     *
     * Creates two throw-away services (slugs `zz-inventory-*`) the first time it is called in a test.
     *
     * @return array{all: array<int, string>, dehydrated: array<int, string>}
     */
    protected function formFieldInventory(): array
    {
        if ($this->fieldInventory !== null) {
            return $this->fieldInventory;
        }

        $all = [];
        $dehydrated = [];

        foreach ([
            $this->rentalService(['slug' => 'zz-inventory-rental', 'name' => 'zz inventory rental']),
            $this->timeSlotService(['slug' => 'zz-inventory-slot', 'name' => 'zz inventory slot']),
        ] as $service) {
            $schema = $this->openEdit($service)->instance()->getSchema('form');

            foreach ($schema->getFlatFields(withHidden: true) as $name => $field) {
                if (preg_match('/\.[0-9a-f]{8}-[0-9a-f]{4}-/', (string) $name)) {
                    continue; // a field inside one repeater row
                }

                $all[] = (string) $name;

                if ($field->isDehydrated()) {
                    $dehydrated[] = (string) $name;
                }
            }
        }

        return $this->fieldInventory = ['all' => array_values(array_unique($all)), 'dehydrated' => array_values(array_unique($dehydrated))];
    }

    /**
     * `services` columns NO form field binds (description, booking_count_*, view_count_*, stats_reset_*, ...),
     * derived from the schema minus the form's own field names. Identity / bookkeeping columns are exempt.
     *
     * @return array<int, string>
     */
    protected function unboundColumns(): array
    {
        $bound = array_map(fn (string $name): string => explode('.', $name)[0], $this->formFieldInventory()['all']);

        return array_values(array_diff(
            Schema::getColumnListing('services'),
            $bound,
            ['id', 'organization_id', 'created_at', 'updated_at'],
        ));
    }

    /**
     * Writes a NON-default value into every unbound column, straight through the query builder (no model
     * events). A save that touches or nulls one of them then shows up in a before/after snapshot.
     */
    protected function fillUnboundColumns(Service $service): void
    {
        $types = collect(Schema::getColumns('services'))->mapWithKeys(fn (array $column): array => [$column['name'] => strtolower((string) $column['type_name'])]);
        $values = [];

        foreach ($this->unboundColumns() as $column) {
            $type = $types[$column] ?? '';

            $values[$column] = match (true) {
                str_contains($type, 'int'), str_contains($type, 'decimal') => 7,
                $type === 'date' => '2026-02-03',
                str_contains($type, 'text'), str_contains($type, 'char') => 'nie z formularza',
                default => $this->fail("`services.{$column}` ({$type}) is bound by no form field and this helper cannot build a non-default value for it: extend fillUnboundColumns()."),
            };
        }

        $this->assertNotEmpty($values, 'no unbound column found - the derivation is broken');
        DB::table('services')->where('id', $service->id)->update($values);
    }

    // ---------------------------------------------------------------------------------------------
    // Snapshots
    // ---------------------------------------------------------------------------------------------

    /**
     * Every column of the row, cast the way the model reads it, minus `updated_at`, with JSON object keys
     * sorted: MySQL normalises object key order, SQLite keeps the text literally (tests.md), and key
     * order carries no meaning here.
     *
     * @return array<string, mixed>
     */
    protected function snapshot(Service|int $service): array
    {
        $row = $this->reload($service)->attributesToArray();
        unset($row['updated_at']);

        return $this->ksortDeep($row);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $columns
     * @return array<string, mixed>
     */
    protected function except(array $row, array $columns): array
    {
        return array_diff_key($row, array_flip($columns));
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    protected function ksortDeep(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->ksortDeep($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * @return array<int, array{location_id: int, quantity: int, is_active: bool}>
     */
    protected function stockRows(Service|int $service): array
    {
        return ServiceLocationStock::withoutGlobalScope('organization')
            ->where('service_id', $service instanceof Service ? $service->id : $service)
            ->orderBy('location_id')
            ->get()
            ->map(fn (ServiceLocationStock $row): array => [
                'location_id' => $row->location_id,
                'quantity' => $row->quantity,
                'is_active' => (bool) $row->is_active,
            ])
            ->all();
    }

    protected function setStock(Service $service, Location $location, int $quantity): ServiceLocationStock
    {
        return ServiceLocationStock::withoutGlobalScope('organization')->updateOrCreate(
            ['service_id' => $service->id, 'location_id' => $location->id],
            ['organization_id' => $this->tenant->id, 'quantity' => $quantity, 'is_active' => true],
        );
    }

    // ---------------------------------------------------------------------------------------------
    // Assertions
    // ---------------------------------------------------------------------------------------------

    /**
     * Same blocks, same order, and every key the "before" data carried is PRESENT and holds the SAME value
     * (strict: `false`, `0`, `''` and null are all different from each other and from "missing"). A first
     * form save may ADD keys - it must never lose or change one.
     *
     * @param  array<int, array{type: string, data: array<string, mixed>, label?: string}>  $before
     * @param  array<int, array{type: string, data: array<string, mixed>}>  $after
     * @param  array<int, string>  $numericStringKeys  documented normalisation only: Select options with numeric keys come back as int (content_grid `columns`: '3' -> 3)
     */
    protected function assertBlocksKeepTheirData(array $before, array $after, array $numericStringKeys = []): void
    {
        $this->assertCount(count($before), $after, 'block count changed');

        foreach ($before as $i => $block) {
            $name = ($block['label'] ?? $block['type'])." (#{$i})";
            $this->assertSame($block['type'], $after[$i]['type'], "{$name} changed type");

            foreach ($block['data'] as $key => $value) {
                $this->assertArrayHasKey($key, $after[$i]['data'], "{$name} LOST the key `{$key}`");

                $expected = $value;
                $actual = $after[$i]['data'][$key];

                if (in_array($key, $numericStringKeys, true)) {
                    $expected = (string) $expected;
                    $actual = (string) $actual;
                }

                $this->assertSame(
                    is_array($expected) ? $this->ksortDeep($expected) : $expected,
                    is_array($actual) ? $this->ksortDeep($actual) : $actual,
                    "{$name} changed the value of `{$key}`",
                );
            }
        }
    }

    /**
     * True when validation failed on `$field` itself or on anything nested below it
     * (`data.metadata.specs` matches `data.metadata.specs.<uuid>.label`).
     */
    protected function hasErrorOn(Testable $component, string $field): bool
    {
        return collect($component->errors()->keys())
            ->contains(fn (string $key): bool => $key === $field || str_starts_with($key, $field.'.'));
    }
}
