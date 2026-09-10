<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\Location;
use App\Models\Order;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Executes migrate:rollback for
 * 2026_09_10_090002_add_pickup_location_to_orders_table.php — the
 * wycofywalność requirement in plan-wdrozenia.md.
 */
class AddPickupLocationToOrdersTableMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'database/migrations/2026_09_10_090002_add_pickup_location_to_orders_table.php';

    public function test_up_adds_the_three_nullable_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('orders', 'pickup_location_id'));
        $this->assertTrue(Schema::hasColumn('orders', 'pickup_location_name'));
        $this->assertTrue(Schema::hasColumn('orders', 'pickup_location_address'));
    }

    public function test_an_order_can_be_created_without_a_pickup_location(): void
    {
        $order = Order::factory()->create([
            'pickup_location_id' => null,
            'pickup_location_name' => null,
            'pickup_location_address' => null,
        ]);

        $order->refresh();
        $this->assertNull($order->pickup_location_id);
        $this->assertNull($order->pickup_location_name);
        $this->assertNull($order->pickup_location_address);
    }

    public function test_an_order_can_be_assigned_a_pickup_location_with_its_snapshot(): void
    {
        $org = Organization::factory()->create();
        $location = Location::factory()->for($org, 'organization')->create([
            'name' => 'Oddział Gdańsk',
        ]);

        $order = Order::factory()->create([
            'organization_id' => $org->id,
            'pickup_location_id' => $location->id,
            'pickup_location_name' => $location->name,
            'pickup_location_address' => $location->formattedAddress(),
        ]);

        $order->refresh();
        $this->assertSame($location->id, $order->pickup_location_id);
        $this->assertSame('Oddział Gdańsk', $order->pickup_location_name);
        $this->assertTrue($order->pickupLocation->is($location));
    }

    /**
     * nullOnDelete: orders is the protected legal record on THIS side of the
     * relationship, Location is not — a deleted Location must not block
     * (restrictOnDelete) nor cascade-delete an order. The snapshot columns
     * are what make this safe: the human-readable identity survives.
     */
    public function test_deleting_a_location_nulls_the_pickup_location_id_but_preserves_the_snapshot(): void
    {
        $org = Organization::factory()->create();
        $location = Location::factory()->for($org, 'organization')->create([
            'name' => 'Oddział Kraków',
            'street' => 'Floriańska',
            'building' => '5',
            'postal_code' => '30-001',
            'city' => 'Kraków',
        ]);

        $order = Order::factory()->create([
            'organization_id' => $org->id,
            'pickup_location_id' => $location->id,
            'pickup_location_name' => $location->name,
            'pickup_location_address' => $location->formattedAddress(),
        ]);

        DB::table('locations')->where('id', $location->id)->delete();

        $order->refresh();
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
        $this->assertNull($order->pickup_location_id);
        // The snapshot is untouched by the Location's own deletion — this is
        // the entire point of the snapshot columns existing.
        $this->assertSame('Oddział Kraków', $order->pickup_location_name);
        $this->assertStringContainsString('Floriańska', $order->pickup_location_address);
    }

    public function test_rollback_drops_all_three_columns_and_migrating_again_recreates_them_empty(): void
    {
        $order = Order::factory()->create();
        $this->assertTrue(Schema::hasColumn('orders', 'pickup_location_id'));

        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION_PATH])->run();

        $this->assertFalse(Schema::hasColumn('orders', 'pickup_location_id'));
        $this->assertFalse(Schema::hasColumn('orders', 'pickup_location_name'));
        $this->assertFalse(Schema::hasColumn('orders', 'pickup_location_address'));
        $this->assertDatabaseHas('orders', ['id' => $order->id]);

        $this->artisan('migrate', ['--path' => self::MIGRATION_PATH])->run();

        $this->assertTrue(Schema::hasColumn('orders', 'pickup_location_id'));
        $this->assertNull(Order::find($order->id)->pickup_location_id);
    }
}
