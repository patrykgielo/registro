<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use App\Models\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests for the `carts:cleanup-abandoned` Artisan command.
 *
 * The command deletes abandoned carts whose updated_at is older than 7 days.
 * It does NOT use the BelongsToOrganization global scope (runs in console),
 * so no tenant stubbing is required.
 *
 * SQLite note: updated_at manipulation is done via DB::table() raw update
 * after factory creation, because Eloquent model events re-touch the timestamp.
 */
class CleanupAbandonedCartsTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_is_successful(): void
    {
        $this->artisan('carts:cleanup-abandoned')->assertSuccessful();
    }

    public function test_deletes_abandoned_cart_older_than_7_days(): void
    {
        $cart = Cart::factory()->abandoned()->create();

        // Force updated_at into the past after creation (Eloquent touches it on save).
        DB::table('carts')->where('id', $cart->id)->update([
            'updated_at' => now()->subDays(8)->toDateTimeString(),
        ]);

        $this->artisan('carts:cleanup-abandoned')->assertSuccessful();

        $this->assertDatabaseMissing('carts', ['id' => $cart->id]);
    }

    public function test_does_not_delete_recent_abandoned_cart(): void
    {
        $cart = Cart::factory()->abandoned()->create();

        DB::table('carts')->where('id', $cart->id)->update([
            'updated_at' => now()->subDays(3)->toDateTimeString(),
        ]);

        $this->artisan('carts:cleanup-abandoned')->assertSuccessful();

        $this->assertDatabaseHas('carts', ['id' => $cart->id]);
    }

    public function test_does_not_delete_abandoned_cart_exactly_7_days_old(): void
    {
        // Boundary: exactly 7 days old is NOT older-than-7 — must survive.
        $cart = Cart::factory()->abandoned()->create();

        DB::table('carts')->where('id', $cart->id)->update([
            'updated_at' => now()->subDays(7)->toDateTimeString(),
        ]);

        $this->artisan('carts:cleanup-abandoned')->assertSuccessful();

        $this->assertDatabaseHas('carts', ['id' => $cart->id]);
    }

    public function test_does_not_delete_active_cart_older_than_7_days(): void
    {
        $cart = Cart::factory()->active()->create();

        DB::table('carts')->where('id', $cart->id)->update([
            'updated_at' => now()->subDays(10)->toDateTimeString(),
        ]);

        $this->artisan('carts:cleanup-abandoned')->assertSuccessful();

        $this->assertDatabaseHas('carts', ['id' => $cart->id]);
    }

    public function test_does_not_delete_converted_cart_older_than_7_days(): void
    {
        $cart = Cart::factory()->converted()->create();

        DB::table('carts')->where('id', $cart->id)->update([
            'updated_at' => now()->subDays(10)->toDateTimeString(),
        ]);

        $this->artisan('carts:cleanup-abandoned')->assertSuccessful();

        $this->assertDatabaseHas('carts', ['id' => $cart->id]);
    }

    public function test_deletes_multiple_old_abandoned_carts(): void
    {
        $old1 = Cart::factory()->abandoned()->create();
        $old2 = Cart::factory()->abandoned()->create();
        $recent = Cart::factory()->abandoned()->create();

        DB::table('carts')->whereIn('id', [$old1->id, $old2->id])->update([
            'updated_at' => now()->subDays(9)->toDateTimeString(),
        ]);

        DB::table('carts')->where('id', $recent->id)->update([
            'updated_at' => now()->subDays(2)->toDateTimeString(),
        ]);

        $this->artisan('carts:cleanup-abandoned')->assertSuccessful();

        $this->assertDatabaseMissing('carts', ['id' => $old1->id]);
        $this->assertDatabaseMissing('carts', ['id' => $old2->id]);
        $this->assertDatabaseHas('carts', ['id' => $recent->id]);
    }

    public function test_outputs_deleted_count(): void
    {
        $cart = Cart::factory()->abandoned()->create();

        DB::table('carts')->where('id', $cart->id)->update([
            'updated_at' => now()->subDays(8)->toDateTimeString(),
        ]);

        $this->artisan('carts:cleanup-abandoned')
            ->expectsOutputToContain('Deleted 1 abandoned carts.')
            ->assertSuccessful();
    }

    public function test_outputs_zero_when_nothing_to_delete(): void
    {
        $this->artisan('carts:cleanup-abandoned')
            ->expectsOutputToContain('Deleted 0 abandoned carts.')
            ->assertSuccessful();
    }
}
