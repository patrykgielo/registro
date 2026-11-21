<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\ServiceType;
use App\Filament\Resources\ServiceResource\Pages\CreateService;
use App\Models\Organization;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * STAGE B of the admin product form (2026-11-21): a rental's `price` follows `price_per_day`, the stale rows are
 * backfilled, the JSON-LD never claims a price for a "price on request" product, and a time_slot's emptied price is
 * a validation error.
 *
 * Fixtures that must be STALE (price != price_per_day) are written with DB::table(): the model now normalises on
 * save, so no Eloquent write can produce one - which is the point of the model hook.
 */
class ServiceFormRentalPriceTest extends TestCase
{
    use RefreshDatabase, ServiceFormTestSupport;

    private const MIGRATION_PATH = __DIR__.'/../../../database/migrations/2026_11_21_100000_backfill_rental_price_from_price_per_day.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpServiceFormTenant();
    }

    // ---------------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------------

    /** @param  array<string, mixed>  $data */
    private function submitCreate(array $data): Testable
    {
        return Livewire::test(CreateService::class)->fillForm($data)->call('create');
    }

    private function priceOf(Service|int $service): string
    {
        return $this->reload($service)->price; // the model's decimal:2 cast: SQLite hands back '150', MySQL '150.00'
    }

    /** Forces a stale `price` past the model (no events), like the rows written before stage B. */
    private function makeStale(Service $service, string|int|float $price): void
    {
        DB::table('services')->where('id', $service->id)->update(['price' => $price]);
        $this->assertSame(number_format((float) $price, 2, '.', ''), $this->priceOf($service), 'fixture is not stale');
    }

    private function migration(): object
    {
        return require self::MIGRATION_PATH;
    }

    // ---------------------------------------------------------------------------------------------
    // 1. Rental price follows price_per_day - through the panel
    // ---------------------------------------------------------------------------------------------

    public function test_a_rental_created_in_the_panel_gets_price_equal_to_price_per_day(): void
    {
        $this->addLocation();

        $this->submitCreate([
            'service_type' => ServiceType::ItemRental->value,
            'name' => 'Wiertarka cenowa',
            'slug' => 'wiertarka-cenowa',
            'price_per_day' => 123.45,
        ])->assertHasNoFormErrors();

        $service = Service::withoutGlobalScopes()->where('slug', 'wiertarka-cenowa')->firstOrFail();
        $this->assertSame('123.45', $service->price_per_day);
        $this->assertSame('123.45', $service->price);
    }

    public function test_a_rental_edit_that_changes_price_per_day_moves_price_with_it(): void
    {
        $service = $this->rentalService(['price' => 100, 'price_per_day' => 100]);

        $this->saveForm($this->openEdit($service)->set('data.price_per_day', 150))->assertHasNoFormErrors();

        $this->assertSame('150.00', $this->priceOf($service));
        $this->assertSame('150.00', $this->reload($service)->price_per_day);
    }

    public function test_a_stale_rental_price_is_repaired_by_an_unrelated_edit_save(): void
    {
        $service = $this->rentalService(['price_per_day' => 100]);
        $this->makeStale($service, 0);

        $this->saveForm($this->openEdit($service)->set('data.sort_order', 9))->assertHasNoFormErrors();

        $this->assertSame('100.00', $this->priceOf($service));
        $this->assertSame(9, $this->reload($service)->sort_order);
    }

    public function test_the_form_requires_price_per_day_so_the_panel_never_saves_a_rental_without_one(): void
    {
        // Why the null-rate cases below are model-level: `price_per_day` is a required form field, so the panel
        // blocks the save instead of writing a rental without it, and the stored price stays put.
        $this->addLocation();

        $create = $this->submitCreate(['service_type' => ServiceType::ItemRental->value, 'name' => 'Bez stawki', 'slug' => 'bez-stawki']);
        $this->assertTrue($this->hasErrorOn($create, 'data.price_per_day'));
        $this->assertSame(0, Service::withoutGlobalScopes()->where('slug', 'bez-stawki')->count());

        $service = $this->rentalService(['price' => 55, 'price_per_day' => null, 'price_per_day_long' => null, 'price_threshold_days' => null]);
        $edit = $this->saveForm($this->openEdit($service)->set('data.sort_order', 9));
        $this->assertTrue($this->hasErrorOn($edit, 'data.price_per_day'));
        $this->assertSame('55.00', $this->priceOf($service));
    }

    public function test_a_rental_without_price_per_day_gets_the_zero_default_on_create_and_keeps_its_price_on_update(): void
    {
        $created = Service::withoutGlobalScopes()->create([
            'organization_id' => $this->tenant->id,
            'service_type' => ServiceType::ItemRental,
            'name' => 'Bez stawki model',
            'slug' => 'bez-stawki-model',
            'duration_minutes' => 0,
        ]);
        $this->assertNull($this->reload($created)->price_per_day);
        $this->assertSame('0.00', $this->priceOf($created));

        $service = $this->rentalService(['price' => 55, 'price_per_day' => null, 'price_per_day_long' => null, 'price_threshold_days' => null]);
        $this->assertSame('55.00', $this->priceOf($service), 'creating it through the model must not touch price either');

        $service->update(['sort_order' => 9, 'name' => 'Inna nazwa']);

        $this->assertSame('55.00', $this->priceOf($service));
        $this->assertNull($this->reload($service)->price_per_day);
    }

    // ---------------------------------------------------------------------------------------------
    // 1b. ... and through every other writer (the model hook)
    // ---------------------------------------------------------------------------------------------

    public function test_the_model_syncs_a_rental_on_create_and_update_outside_the_panel(): void
    {
        $service = Service::withoutGlobalScopes()->create([
            'organization_id' => $this->tenant->id,
            'service_type' => ServiceType::ItemRental,
            'name' => 'Spoza panelu',
            'slug' => 'spoza-panelu',
            'duration_minutes' => 0,
            'price' => 1,
            'price_per_day' => 80,
        ]);
        $this->assertSame('80.00', $this->priceOf($service));

        $service->update(['price_per_day' => 95]);
        $this->assertSame('95.00', $this->priceOf($service));

        $service->update(['price_per_day' => null]);
        $this->assertSame('95.00', $this->priceOf($service), 'a null daily rate leaves price alone');
    }

    public function test_a_time_slot_price_is_never_touched_by_the_sync(): void
    {
        // A stray price_per_day on a time_slot must not drag its (real, editable) price along.
        $service = $this->timeSlotService(['price' => 200, 'price_per_day' => 50]);
        $this->assertSame('200.00', $this->priceOf($service));

        $this->saveForm($this->openEdit($service)->set('data.price', 321.5))->assertHasNoFormErrors();
        $this->assertSame('321.50', $this->priceOf($service));

        $service->update(['name' => 'Inna nazwa', 'price_per_day' => 60]);
        $this->assertSame('321.50', $this->priceOf($service));
    }

    public function test_an_attempt_to_turn_a_time_slot_into_a_rental_does_not_overwrite_its_price(): void
    {
        // service_type is immutable: Service::booted() reverts it in `updating`, which fires AFTER `saving`.
        // The sync must judge by the stored type, or it would overwrite the time_slot's price before the revert.
        $service = $this->timeSlotService(['price' => 200, 'price_per_day' => 50]);

        $service->update(['service_type' => ServiceType::ItemRental]);

        $fresh = $this->reload($service);
        $this->assertSame(ServiceType::TimeSlot, $fresh->service_type);
        $this->assertSame('200.00', $fresh->price);
    }

    public function test_an_attempt_to_turn_a_rental_into_a_time_slot_still_syncs_its_price(): void
    {
        $service = $this->rentalService(['price' => 100, 'price_per_day' => 100]);

        $service->update(['service_type' => ServiceType::TimeSlot, 'price_per_day' => 90]);

        $fresh = $this->reload($service);
        $this->assertSame(ServiceType::ItemRental, $fresh->service_type);
        $this->assertSame('90.00', $fresh->price);
    }

    // ---------------------------------------------------------------------------------------------
    // 2. Backfill migration
    // ---------------------------------------------------------------------------------------------

    public function test_the_backfill_repairs_only_stale_rentals_that_have_a_daily_rate(): void
    {
        $otherTenant = Organization::factory()->equipmentRental()->create();

        $staleCopy = $this->rentalService(['slug' => 'stale', 'name' => 'Stale', 'price_per_day' => 100]);
        $zero = $this->rentalService(['slug' => 'zero', 'name' => 'Zero', 'price_per_day' => 70]);
        $inSync = $this->rentalService(['slug' => 'sync', 'name' => 'Sync', 'price_per_day' => 40]);
        $noRate = $this->rentalService(['slug' => 'norate', 'name' => 'No rate', 'price_per_day' => null, 'price_per_day_long' => null, 'price_threshold_days' => null, 'price' => 55]);
        $foreign = Service::factory()->itemRental()->create(['organization_id' => $otherTenant->id, 'slug' => 'foreign', 'name' => 'Foreign', 'price_per_day' => 33]);
        $timeSlot = $this->timeSlotService(['slug' => 'slot', 'name' => 'Slot', 'price' => 200]);
        $timeSlotWithRate = $this->timeSlotService(['slug' => 'slot-rate', 'name' => 'Slot rate', 'price' => 210]);

        $this->makeStale($staleCopy, 90);
        $this->makeStale($zero, 0);
        $this->makeStale($foreign, 1);
        DB::table('services')->where('id', $timeSlotWithRate->id)->update(['price_per_day' => 5]);

        $touched = Carbon::parse('2026-01-02 03:04:05');
        DB::table('services')->update(['updated_at' => $touched]);
        $timeSlotBefore = (array) DB::table('services')->where('id', $timeSlot->id)->first();
        $noRateBefore = (array) DB::table('services')->where('id', $noRate->id)->first();

        $this->migration()->up();

        $this->assertSame('100.00', $this->priceOf($staleCopy));
        $this->assertSame('70.00', $this->priceOf($zero));
        $this->assertSame('40.00', $this->priceOf($inSync));
        $this->assertSame('33.00', $this->priceOf($foreign), 'every tenant of the database is repaired');
        $this->assertSame('55.00', $this->priceOf($noRate), 'no daily rate: price stays');
        $this->assertSame('200.00', $this->priceOf($timeSlot));
        $this->assertSame('210.00', $this->priceOf($timeSlotWithRate), 'a time_slot is never touched, even with a stray price_per_day');
        $this->assertSame($timeSlotBefore, (array) DB::table('services')->where('id', $timeSlot->id)->first());
        $this->assertSame($noRateBefore, (array) DB::table('services')->where('id', $noRate->id)->first());
        $this->assertSame(
            $touched->toDateTimeString(),
            (string) DB::table('services')->where('id', $staleCopy->id)->value('updated_at'),
            'a derived value: updated_at is not bumped',
        );
    }

    public function test_the_backfill_is_idempotent_and_its_down_changes_nothing(): void
    {
        $service = $this->rentalService(['price_per_day' => 100]);
        $this->makeStale($service, 3);

        $migration = $this->migration();
        $migration->up();
        $afterFirst = DB::table('services')->orderBy('id')->get()->toArray();

        $migration->up();
        $migration->down();

        $this->assertEquals($afterFirst, DB::table('services')->orderBy('id')->get()->toArray());
        $this->assertSame('100.00', $this->priceOf($service));
    }

    // ---------------------------------------------------------------------------------------------
    // 3. JSON-LD
    // ---------------------------------------------------------------------------------------------

    /** @return array<string, mixed> the Product/Service JSON-LD block of the public product page */
    private function schemaOf(Service $service): array
    {
        config(['app.domain' => 'registro.local']);

        $response = $this->get("http://{$this->tenant->slug}.registro.local/uslugi/{$service->slug}");
        $response->assertOk();

        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $response->getContent(), $matches);

        foreach ($matches[1] as $json) {
            $decoded = json_decode($json, true);
            if (in_array($decoded['@type'] ?? null, ['Product', 'Service'], true)) {
                return $decoded;
            }
        }

        $this->fail('no Product/Service JSON-LD block on the page');
    }

    public function test_a_rental_with_a_price_emits_an_offer_with_its_daily_rate(): void
    {
        $service = $this->rentalService(['price_per_day' => 100, 'price_on_request' => false]);

        $schema = $this->schemaOf($service);

        $this->assertSame('Product', $schema['@type']);
        $this->assertSame('Offer', $schema['offers']['@type']);
        $this->assertEquals(100, $schema['offers']['price']);
        $this->assertSame('PLN', $schema['offers']['priceCurrency']);
    }

    public function test_a_rental_on_request_emits_no_offer_at_all(): void
    {
        $service = $this->rentalService(['price_per_day' => 100, 'price_on_request' => true]);

        $schema = $this->schemaOf($service);

        $this->assertSame('Product', $schema['@type']);
        $this->assertArrayNotHasKey('offers', $schema);
        $this->assertSame($service->name, $schema['name']);
    }

    public function test_a_time_slot_with_a_price_emits_an_offer_including_the_from_price(): void
    {
        $service = $this->timeSlotService(['price' => 200, 'price_from' => 150, 'price_on_request' => false, 'published_at' => now()->subDay()]);

        $schema = $this->schemaOf($service);

        $this->assertSame('Service', $schema['@type']);
        $this->assertEquals(200, $schema['offers']['price']);
        $this->assertEquals(150, $schema['offers']['priceSpecification']['minPrice']);
    }

    public function test_a_time_slot_on_request_emits_no_offer_at_all(): void
    {
        // The model forces price_on_request = false for a time_slot (HasRentalBehavior), so only a row written past it
        // (old data, raw SQL) can carry the flag - the JSON-LD must still not trust such a row's price.
        $service = $this->timeSlotService(['price' => 200, 'price_from' => 150, 'published_at' => now()->subDay()]);
        DB::table('services')->where('id', $service->id)->update(['price_on_request' => true]);

        $schema = $this->schemaOf($service);

        $this->assertSame('Service', $schema['@type']);
        $this->assertArrayNotHasKey('offers', $schema);
    }

    // ---------------------------------------------------------------------------------------------
    // 4. A time_slot's emptied price is a validation error, a rental never gets a required price
    // ---------------------------------------------------------------------------------------------

    public function test_an_emptied_time_slot_price_is_a_validation_error_on_create_and_on_edit(): void
    {
        $this->addLocation();

        $create = $this->submitCreate(['service_type' => ServiceType::TimeSlot->value, 'name' => 'Młot', 'slug' => 'mlot', 'price' => null]);
        $this->assertTrue($this->hasErrorOn($create, 'data.price'));
        $this->assertSame(0, Service::withoutGlobalScopes()->where('slug', 'mlot')->count());

        $service = $this->timeSlotService(['price' => 100]);
        $edit = $this->saveForm($this->openEdit($service)->set('data.price', null));
        $this->assertTrue($this->hasErrorOn($edit, 'data.price'));
        $this->assertSame('100.00', $this->priceOf($service));
    }

    public function test_a_time_slot_price_of_zero_is_still_accepted(): void
    {
        $service = $this->timeSlotService(['price' => 100]);

        $this->saveForm($this->openEdit($service)->set('data.price', 0))->assertHasNoFormErrors();

        $this->assertSame('0.00', $this->priceOf($service));
    }

    public function test_a_rental_never_gets_a_required_price(): void
    {
        $service = $this->rentalService();
        $component = $this->openEdit($service);

        $component->assertFormFieldIsHidden('price');
        $this->saveForm($component->set('data.price', null))->assertHasNoFormErrors();

        $this->addLocation();
        $this->submitCreate(['service_type' => ServiceType::ItemRental->value, 'name' => 'Bez ceny bazowej', 'slug' => 'bez-ceny-bazowej', 'price_per_day' => 10])
            ->assertHasNoFormErrors();
    }
}
