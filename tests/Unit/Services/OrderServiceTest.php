<?php

namespace Tests\Unit\Services;

use App\Models\Order;
use App\Notifications\OrderCancelledNotification;
use App\Services\Order\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class OrderServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeService(): OrderService
    {
        return app(OrderService::class);
    }

    // -------------------------------------------------------------------------
    // cancel()
    // -------------------------------------------------------------------------

    public function test_cancel_transitions_pending_payment_order_to_cancelled(): void
    {
        $order = Order::factory()->pendingPayment()->create();

        $svc = $this->makeService();
        $result = $svc->cancel($order, 'Test cancellation');

        $result->refresh();

        $this->assertEquals('cancelled', $result->status);
        $this->assertNotNull($result->cancelled_at);
    }

    public function test_cancel_transitions_paid_order_to_cancelled(): void
    {
        $order = Order::factory()->paid()->create();

        $svc = $this->makeService();
        $result = $svc->cancel($order, 'Customer request');

        $result->refresh();

        $this->assertEquals('cancelled', $result->status);
        $this->assertNotNull($result->cancelled_at);
    }

    public function test_cancel_sets_cancelled_at_timestamp(): void
    {
        $order = Order::factory()->pendingPayment()->create();

        $svc = $this->makeService();
        $svc->cancel($order, 'TTL');

        $order->refresh();

        $this->assertNotNull($order->cancelled_at);
        // Should be a recent timestamp
        $this->assertTrue($order->cancelled_at->diffInSeconds(now()) < 5);
    }

    public function test_cancel_transitions_confirmed_order_to_cancelled(): void
    {
        $order = Order::factory()->confirmed()->create();

        $svc = $this->makeService();
        $result = $svc->cancel($order, 'Admin cancellation of confirmed order');

        $result->refresh();

        $this->assertEquals('cancelled', $result->status);
        $this->assertNotNull($result->cancelled_at);
    }

    public function test_cancel_in_progress_order_succeeds_with_warning(): void
    {
        \Illuminate\Support\Facades\Notification::fake();

        $order = Order::factory()->inProgress()->create();

        $svc = $this->makeService();
        $result = $svc->cancel($order, 'Offboarding: tenant closing');

        $this->assertEquals('cancelled', $result->status);
        $this->assertNotNull($result->cancelled_at);
    }

    public function test_cancel_throws_for_completed_order(): void
    {
        $order = Order::factory()->completed()->create();

        $svc = $this->makeService();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Zamówienie o statusie 'completed' nie może zostać anulowane");

        $svc->cancel($order, 'Cannot cancel completed');
    }

    public function test_cancel_throws_for_already_cancelled_order(): void
    {
        $order = Order::factory()->cancelled()->create();

        $svc = $this->makeService();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Zamówienie o statusie 'cancelled' nie może zostać anulowane");

        $svc->cancel($order, 'Already cancelled');
    }

    public function test_cancel_returns_the_order_instance(): void
    {
        $order = Order::factory()->pendingPayment()->create();

        $svc = $this->makeService();
        $result = $svc->cancel($order, 'Test');

        $this->assertInstanceOf(Order::class, $result);
        $this->assertEquals($order->id, $result->id);
    }

    // -------------------------------------------------------------------------
    // cancel() — notify flag (HIGH fix: internal compensation must not send
    // the customer-facing cancellation email)
    // -------------------------------------------------------------------------

    public function test_cancel_notifies_customer_by_default(): void
    {
        Notification::fake();

        $order = Order::factory()->pendingPayment()->create();

        $svc = $this->makeService();
        $svc->cancel($order, 'Customer request');

        Notification::assertSentTo($order->user, OrderCancelledNotification::class);
    }

    public function test_cancel_with_notify_false_does_not_send_customer_notification(): void
    {
        Notification::fake();

        $order = Order::factory()->pendingPayment()->create();

        $svc = $this->makeService();
        $svc->cancel($order, 'P24 registration failed', notify: false);

        Notification::assertNotSentTo($order->user, OrderCancelledNotification::class);
    }

    public function test_cancel_with_notify_false_still_cancels_the_order(): void
    {
        Notification::fake();

        $order = Order::factory()->pendingPayment()->create();

        $svc = $this->makeService();
        $result = $svc->cancel($order, 'P24 registration failed', notify: false);

        $this->assertEquals('cancelled', $result->status);
        $this->assertNotNull($result->cancelled_at);
    }

    // -------------------------------------------------------------------------
    // cleanupExpired()
    // -------------------------------------------------------------------------

    public function test_cleanup_expired_cancels_expired_orders_and_returns_count(): void
    {
        Order::factory()->expired()->create();
        Order::factory()->expired()->create();
        Order::factory()->expired()->create();

        $svc = $this->makeService();
        $count = $svc->cleanupExpired();

        $this->assertEquals(3, $count);
        $this->assertEquals(3, Order::where('status', 'cancelled')->count());
    }

    public function test_cleanup_expired_does_not_cancel_non_expired_orders(): void
    {
        $active = Order::factory()->pendingPayment()->create(); // not expired yet
        $paid = Order::factory()->paid()->create();
        $expired = Order::factory()->expired()->create();

        $svc = $this->makeService();
        $count = $svc->cleanupExpired();

        $this->assertEquals(1, $count);

        // The non-expired pending_payment should remain pending_payment
        $active->refresh();
        $this->assertEquals('pending_payment', $active->status);

        // Paid stays paid
        $paid->refresh();
        $this->assertEquals('paid', $paid->status);
    }

    public function test_cleanup_expired_returns_zero_when_no_expired_orders(): void
    {
        Order::factory()->pendingPayment()->create(); // active TTL
        Order::factory()->paid()->create();

        $svc = $this->makeService();
        $count = $svc->cleanupExpired();

        $this->assertEquals(0, $count);
    }

    public function test_cleanup_expired_records_cancelled_at_on_each_order(): void
    {
        Order::factory()->expired()->create();
        Order::factory()->expired()->create();

        $svc = $this->makeService();
        $svc->cleanupExpired();

        Order::where('status', 'cancelled')->each(function (Order $order): void {
            $this->assertNotNull($order->cancelled_at);
        });
    }
}
