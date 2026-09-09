<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Location;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Executes migrate:rollback for
 * 2026_09_09_090002_add_location_id_to_cart_items_table.php — the
 * wycofywalność requirement in plan-wdrozenia.md. See
 * AddLocationIdToRentalsTableMigrationTest's docblock for the same caveats.
 */
class AddLocationIdToCartItemsTableMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_09_09_090002_add_location_id_to_cart_items_table.php';

    public function test_up_adds_the_nullable_location_id_column(): void
    {
        $this->assertTrue(Schema::hasColumn('cart_items', 'location_id'));
    }

    public function test_a_cart_item_can_be_created_without_a_location(): void
    {
        $item = CartItem::factory()->create(['location_id' => null]);

        $this->assertNull($item->fresh()->location_id);
    }

    public function test_a_cart_item_can_be_assigned_to_a_location(): void
    {
        $org = Organization::factory()->create();
        $location = Location::factory()->for($org, 'organization')->create();
        $cart = Cart::factory()->active()->create(['organization_id' => $org->id]);

        $item = CartItem::factory()->create([
            'cart_id' => $cart->id,
            'location_id' => $location->id,
        ]);

        $this->assertSame($location->id, $item->fresh()->location_id);
        $this->assertTrue($item->location->is($location));
    }

    /**
     * cart_items is "ephemeral operational data" (migrations.md's
     * classification table) — nullOnDelete here is the conservative
     * choice for the same reason as the legal-record tables (a deleted
     * Location must not cascade-delete a customer's in-progress cart item),
     * not because this table needs legal-record-grade protection.
     */
    public function test_deleting_a_location_nulls_the_cart_items_location_id_instead_of_deleting_the_row(): void
    {
        $org = Organization::factory()->create();
        $location = Location::factory()->for($org, 'organization')->create();
        $cart = Cart::factory()->active()->create(['organization_id' => $org->id]);

        $item = CartItem::factory()->create([
            'cart_id' => $cart->id,
            'location_id' => $location->id,
        ]);

        DB::table('locations')->where('id', $location->id)->delete();

        $this->assertDatabaseHas('cart_items', ['id' => $item->id]);
        $this->assertNull($item->fresh()->location_id);
    }

    public function test_rollback_drops_the_column_and_migrating_again_recreates_it_empty(): void
    {
        $item = CartItem::factory()->create();
        $this->assertTrue(Schema::hasColumn('cart_items', 'location_id'));

        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();

        $this->assertFalse(Schema::hasColumn('cart_items', 'location_id'));
        $this->assertDatabaseHas('cart_items', ['id' => $item->id]);

        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();

        $this->assertTrue(Schema::hasColumn('cart_items', 'location_id'));
        $this->assertNull(CartItem::find($item->id)->location_id);
    }
}
