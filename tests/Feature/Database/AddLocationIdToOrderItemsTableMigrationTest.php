<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\Location;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Executes migrate:rollback for
 * 2026_09_09_090001_add_location_id_to_order_items_table.php — the
 * wycofywalność requirement in plan-wdrozenia.md. See
 * AddLocationIdToRentalsTableMigrationTest's docblock for the same caveats
 * (SQLite locally vs the real MySQL release gate; no dependent migration of
 * its own).
 */
class AddLocationIdToOrderItemsTableMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_09_09_090001_add_location_id_to_order_items_table.php';

    public function test_up_adds_the_nullable_location_id_column(): void
    {
        $this->assertTrue(Schema::hasColumn('order_items', 'location_id'));
    }

    public function test_an_order_item_can_be_created_without_a_location(): void
    {
        $item = OrderItem::factory()->create(['location_id' => null]);

        $this->assertNull($item->fresh()->location_id);
    }

    public function test_an_order_item_can_be_assigned_to_a_location(): void
    {
        $org = Organization::factory()->create();
        $location = Location::factory()->for($org, 'organization')->create();
        $order = Order::factory()->paid()->create(['organization_id' => $org->id]);

        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'location_id' => $location->id,
        ]);

        $this->assertSame($location->id, $item->fresh()->location_id);
        $this->assertTrue($item->location->is($location));
    }

    /**
     * order_items is the protected legal record on this side of the FK
     * (migrations.md's classification table) — deleting a Location must
     * not be blocked by, nor cascade-delete, an order item. Same nullOnDelete
     * precedent as `order_items.service_unit_id`'s own migration.
     */
    public function test_deleting_a_location_nulls_the_order_items_location_id_instead_of_deleting_the_row(): void
    {
        $org = Organization::factory()->create();
        $location = Location::factory()->for($org, 'organization')->create();
        $order = Order::factory()->paid()->create(['organization_id' => $org->id]);

        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'location_id' => $location->id,
        ]);

        DB::table('locations')->where('id', $location->id)->delete();

        $this->assertDatabaseHas('order_items', ['id' => $item->id]);
        $this->assertNull($item->fresh()->location_id);
    }

    public function test_rollback_drops_the_column_and_migrating_again_recreates_it_empty(): void
    {
        $item = OrderItem::factory()->create();
        $this->assertTrue(Schema::hasColumn('order_items', 'location_id'));

        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();

        $this->assertFalse(Schema::hasColumn('order_items', 'location_id'));
        $this->assertDatabaseHas('order_items', ['id' => $item->id]);

        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();

        $this->assertTrue(Schema::hasColumn('order_items', 'location_id'));
        $this->assertNull(OrderItem::find($item->id)->location_id);
    }
}
