<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for the `orders:cleanup-expired` Artisan command.
 *
 * The command delegates to OrderService::cleanupExpired(), which uses the
 * Order::expired() scope (status=pending_payment AND expires_at < now).
 *
 * These tests are placed in Unit/Commands/ because they exercise the command
 * in isolation via $this->artisan() without any HTTP stack. They do need the
 * database (RefreshDatabase) because the command queries real rows.
 */
class CleanupExpiredOrdersTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_is_successful(): void
    {
        $this->artisan('orders:cleanup-expired')->assertSuccessful();
    }

    public function test_cancels_expired_pending_payment_order(): void
    {
        $order = Order::factory()->expired()->create();

        $this->artisan('orders:cleanup-expired')->assertSuccessful();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_does_not_cancel_non_expired_pending_payment_order(): void
    {
        $order = Order::factory()->pendingPayment()->create([
            'expires_at' => now()->addMinutes(20),
        ]);

        $this->artisan('orders:cleanup-expired')->assertSuccessful();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'pending_payment',
        ]);
    }

    public function test_cancels_multiple_expired_orders_in_one_run(): void
    {
        $expired1 = Order::factory()->expired()->create();
        $expired2 = Order::factory()->expired()->create();

        $this->artisan('orders:cleanup-expired')->assertSuccessful();

        foreach ([$expired1, $expired2] as $order) {
            $this->assertDatabaseHas('orders', [
                'id' => $order->id,
                'status' => 'cancelled',
            ]);
        }
    }

    public function test_only_cancels_expired_orders_leaves_others_untouched(): void
    {
        $expired = Order::factory()->expired()->create();
        $pending = Order::factory()->pendingPayment()->create();
        $paid = Order::factory()->paid()->create();

        $this->artisan('orders:cleanup-expired')->assertSuccessful();

        $this->assertDatabaseHas('orders', ['id' => $expired->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('orders', ['id' => $pending->id, 'status' => 'pending_payment']);
        $this->assertDatabaseHas('orders', ['id' => $paid->id, 'status' => 'paid']);
    }

    public function test_does_not_cancel_non_pending_payment_order_even_if_past_expiry(): void
    {
        // A confirmed order with a past expires_at must not be cancelled —
        // the expired() scope requires status=pending_payment.
        $confirmed = Order::factory()->confirmed()->create([
            'expires_at' => now()->subHour(),
        ]);

        $this->artisan('orders:cleanup-expired')->assertSuccessful();

        $this->assertDatabaseHas('orders', [
            'id' => $confirmed->id,
            'status' => 'confirmed',
        ]);
    }

    public function test_outputs_cancelled_count(): void
    {
        Order::factory()->expired()->create();
        Order::factory()->expired()->create();

        $this->artisan('orders:cleanup-expired')
            ->expectsOutputToContain('Cancelled 2 expired orders.')
            ->assertSuccessful();
    }

    public function test_outputs_zero_when_no_expired_orders(): void
    {
        $this->artisan('orders:cleanup-expired')
            ->expectsOutputToContain('Cancelled 0 expired orders.')
            ->assertSuccessful();
    }

    // -------------------------------------------------------------------------
    // P24 transaction registered — extended grace period before TTL cleanup.
    // A registered p24_token means the customer is/was actively on P24's own
    // gateway; cancelling them out from under a slow bank/BLIK confirmation
    // would orphan a genuine, in-flight payment. See Order::scopeExpired().
    // -------------------------------------------------------------------------

    public function test_does_not_cancel_order_with_p24_token_past_normal_ttl(): void
    {
        $order = Order::factory()->pendingPayment()->create([
            // Well past the normal 20-minute TTL, but still within the grace period.
            'expires_at' => now()->subMinutes(30),
            'p24_token' => 'p24-token-in-flight',
        ]);

        $this->artisan('orders:cleanup-expired')->assertSuccessful();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'pending_payment',
        ]);
    }

    public function test_cancels_order_with_p24_token_once_grace_period_also_expires(): void
    {
        $graceMinutes = Order::ttlGraceMinutes();

        $order = Order::factory()->pendingPayment()->create([
            'expires_at' => now()->subMinutes($graceMinutes + 5),
            'p24_token' => 'p24-token-abandoned',
        ]);

        $this->artisan('orders:cleanup-expired')->assertSuccessful();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_cancels_order_without_p24_token_using_normal_ttl_even_though_grace_period_has_not_elapsed(): void
    {
        // No p24_token — the normal (short) TTL applies regardless of the
        // extended grace period granted to orders with a registered transaction.
        $order = Order::factory()->pendingPayment()->create([
            'expires_at' => now()->subMinutes(30),
            'p24_token' => null,
        ]);

        $this->artisan('orders:cleanup-expired')->assertSuccessful();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'cancelled',
        ]);
    }
}
