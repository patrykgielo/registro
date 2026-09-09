<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Enums\RentalStatus;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Rental;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Executes migrate:rollback (not just a static `down()` regex) for
 * 2026_09_09_090000_add_location_id_to_rentals_table.php — the
 * wycofywalność requirement in plan-wdrozenia.md. Runs on SQLite locally
 * (.env.testing); the MySQL 8.0 release gate is what actually exercises the
 * FK onDelete + drop-order behaviour with real InnoDB semantics — see
 * CreateLocationsTableMigrationTest's docblock for the same caveat. This
 * migration has no dependent migration of its own (nothing FKs to
 * rentals.location_id), so a `--path`-isolated rollback here is safe and
 * needs no dependent chain, unlike CreateLocationsTableMigrationTest's own.
 */
class AddLocationIdToRentalsTableMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_09_09_090000_add_location_id_to_rentals_table.php';

    public function test_up_adds_the_nullable_location_id_column(): void
    {
        $this->assertTrue(Schema::hasColumn('rentals', 'location_id'));
    }

    public function test_a_rental_can_be_created_without_a_location(): void
    {
        $rental = Rental::factory()->create(['location_id' => null]);

        $this->assertNull($rental->fresh()->location_id);
    }

    public function test_a_rental_can_be_assigned_to_a_location(): void
    {
        $org = Organization::factory()->create();
        $location = Location::factory()->for($org, 'organization')->create();

        $rental = Rental::factory()->create([
            'organization_id' => $org->id,
            'location_id' => $location->id,
        ]);

        $this->assertSame($location->id, $rental->fresh()->location_id);
        $this->assertTrue($rental->location->is($location));
    }

    /**
     * The onDelete choice this migration's own docblock argues for:
     * deleting a Location must not be blocked by, nor cascade-delete, a
     * rental — `rentals` is the protected legal record on this side of the
     * FK (migrations.md's classification table), matching the
     * `appointments.staff_id -> nullOnDelete` precedent.
     */
    public function test_deleting_a_location_nulls_the_rentals_location_id_instead_of_deleting_the_rental(): void
    {
        $org = Organization::factory()->create();
        $location = Location::factory()->for($org, 'organization')->create();

        $rental = Rental::factory()->create([
            'organization_id' => $org->id,
            'service_id' => Service::factory()->itemRental()->create(['organization_id' => $org->id])->id,
            'customer_id' => User::factory()->create()->id,
            'location_id' => $location->id,
            'status' => RentalStatus::Confirmed,
        ]);

        DB::table('locations')->where('id', $location->id)->delete();

        $this->assertDatabaseHas('rentals', ['id' => $rental->id]);
        $this->assertNull($rental->fresh()->location_id);
    }

    public function test_rollback_drops_the_column_and_migrating_again_recreates_it_empty(): void
    {
        $rental = Rental::factory()->create();
        $this->assertTrue(Schema::hasColumn('rentals', 'location_id'));

        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();

        $this->assertFalse(Schema::hasColumn('rentals', 'location_id'));
        // The rental row itself must survive — only the column is dropped.
        $this->assertDatabaseHas('rentals', ['id' => $rental->id]);

        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();

        $this->assertTrue(Schema::hasColumn('rentals', 'location_id'));
        $this->assertNull(Rental::find($rental->id)->location_id);
    }
}
