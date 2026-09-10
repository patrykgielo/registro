<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\Cart;
use App\Models\Location;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Executes migrate:rollback for
 * 2026_09_10_090000_add_location_id_to_carts_table.php — the
 * wycofywalność requirement in plan-wdrozenia.md. See
 * AddLocationIdToCartItemsTableMigrationTest's docblock for the same caveats
 * (this one carries no composite index of its own — see the migration's own
 * down() docblock for why).
 */
class AddLocationIdToCartsTableMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_09_10_090000_add_location_id_to_carts_table.php';

    public function test_up_adds_the_nullable_location_id_column(): void
    {
        $this->assertTrue(Schema::hasColumn('carts', 'location_id'));
    }

    public function test_a_cart_can_be_created_without_a_location(): void
    {
        $cart = Cart::factory()->active()->create(['location_id' => null]);

        $this->assertNull($cart->fresh()->location_id);
    }

    public function test_a_cart_can_be_assigned_to_a_location(): void
    {
        $org = Organization::factory()->create();
        $location = Location::factory()->for($org, 'organization')->create();

        $cart = Cart::factory()->active()->create([
            'organization_id' => $org->id,
            'user_id' => User::factory()->create()->id,
            'location_id' => $location->id,
        ]);

        $this->assertSame($location->id, $cart->fresh()->location_id);
        $this->assertTrue($cart->location->is($location));
    }

    /**
     * carts is "ephemeral operational data" (migrations.md's classification
     * table) — nullOnDelete here is the conservative choice, not because
     * this table needs legal-record-grade protection.
     */
    public function test_deleting_a_location_nulls_the_carts_location_id_instead_of_deleting_the_row(): void
    {
        $org = Organization::factory()->create();
        $location = Location::factory()->for($org, 'organization')->create();

        $cart = Cart::factory()->active()->create([
            'organization_id' => $org->id,
            'user_id' => User::factory()->create()->id,
            'location_id' => $location->id,
        ]);

        DB::table('locations')->where('id', $location->id)->delete();

        $this->assertDatabaseHas('carts', ['id' => $cart->id]);
        $this->assertNull($cart->fresh()->location_id);
    }

    public function test_rollback_drops_the_column_and_migrating_again_recreates_it_empty(): void
    {
        $cart = Cart::factory()->active()->create();
        $this->assertTrue(Schema::hasColumn('carts', 'location_id'));

        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();

        $this->assertFalse(Schema::hasColumn('carts', 'location_id'));
        $this->assertDatabaseHas('carts', ['id' => $cart->id]);

        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();

        $this->assertTrue(Schema::hasColumn('carts', 'location_id'));
        $this->assertNull(Cart::find($cart->id)->location_id);
    }
}
