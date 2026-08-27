<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Executes migrate:rollback (not just a static `down()` regex) for
 * 2026_08_27_120000_create_locations_table.php — the wycofywalność
 * requirement in plan-wdrozenia.md. Runs on SQLite locally (.env.testing);
 * `deploy-production.yml` runs the Feature suite against real MySQL, which
 * is what actually exercises the composite UNIQUE constraints below — SQLite
 * enforces UNIQUE too, but not ENUM/FK/NOT NULL the way InnoDB does, so this
 * is a preliminary signal, not the final proof (see migrations.md's
 * "Rollback Safety" + plan-wdrozenia.md's "Wycofywalność" section).
 */
class CreateLocationsTableMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_08_27_120000_create_locations_table.php';

    public function test_up_creates_the_table_with_the_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('locations'));
        $this->assertTrue(Schema::hasColumns('locations', [
            'id', 'organization_id', 'name', 'slug', 'code',
            'street', 'building', 'postal_code', 'city',
            'latitude', 'longitude', 'phone', 'email', 'opening_hours',
            'photo', 'gallery', 'description', 'is_active', 'sort_order',
            'primary_slot', 'created_at', 'updated_at',
        ]));
    }

    public function test_organization_id_and_slug_must_be_unique_per_organization_not_globally(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        DB::table('locations')->insert($this->rowFor($orgA->id, 'siedziba', primarySlot: 1));

        // Same slug, different organization — must NOT collide (locations.md
        // explicitly requires (organization_id, slug), never bare slug).
        DB::table('locations')->insert($this->rowFor($orgB->id, 'siedziba', primarySlot: 1));

        $this->assertSame(2, DB::table('locations')->where('slug', 'siedziba')->count());
    }

    public function test_slug_must_be_unique_within_the_same_organization(): void
    {
        $org = Organization::factory()->create();
        DB::table('locations')->insert($this->rowFor($org->id, 'siedziba', primarySlot: 1));

        $this->expectException(QueryException::class);

        DB::table('locations')->insert($this->rowFor($org->id, 'siedziba', primarySlot: null, name: 'Druga'));
    }

    public function test_at_most_one_primary_location_per_organization_at_the_database_level(): void
    {
        $org = Organization::factory()->create();
        DB::table('locations')->insert($this->rowFor($org->id, 'a', primarySlot: 1));

        $this->expectException(QueryException::class);

        DB::table('locations')->insert($this->rowFor($org->id, 'b', primarySlot: 1, name: 'Druga'));
    }

    public function test_two_organizations_can_each_have_their_own_primary_location(): void
    {
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        DB::table('locations')->insert($this->rowFor($orgA->id, 'a', primarySlot: 1));
        DB::table('locations')->insert($this->rowFor($orgB->id, 'b', primarySlot: 1));

        $this->assertSame(2, DB::table('locations')->where('primary_slot', 1)->count());
    }

    public function test_a_non_primary_null_slot_never_collides_with_the_unique_index(): void
    {
        $org = Organization::factory()->create();
        DB::table('locations')->insert($this->rowFor($org->id, 'a', primarySlot: 1));
        DB::table('locations')->insert($this->rowFor($org->id, 'b', primarySlot: null, name: 'Druga'));
        DB::table('locations')->insert($this->rowFor($org->id, 'c', primarySlot: null, name: 'Trzecia'));

        $this->assertSame(3, DB::table('locations')->where('organization_id', $org->id)->count());
    }

    public function test_rollback_drops_the_table_and_migrating_again_recreates_it_empty(): void
    {
        $org = Organization::factory()->create();
        DB::table('locations')->insert($this->rowFor($org->id, 'a', primarySlot: 1));
        $this->assertTrue(Schema::hasTable('locations'));

        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();

        $this->assertFalse(Schema::hasTable('locations'));

        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();

        $this->assertTrue(Schema::hasTable('locations'));
        $this->assertSame(0, DB::table('locations')->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function rowFor(int $organizationId, string $slug, ?int $primarySlot, string $name = 'Siedziba'): array
    {
        return [
            'organization_id' => $organizationId,
            'name' => $name,
            'slug' => $slug,
            'primary_slot' => $primarySlot,
            'is_active' => true,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
