<?php

namespace Tests\Unit\Services;

use App\Events\OrderPaid;
use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderCancelledNotification;
use App\Notifications\OrderPaidNotification;
use App\Services\Order\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
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

    // -------------------------------------------------------------------------
    // recordOfflinePayment()
    // -------------------------------------------------------------------------

    public function test_record_offline_payment_transitions_order_to_paid(): void
    {
        Notification::fake();

        $order = Order::factory()->offline()->pendingPayment()->create(['total_amount' => 300]);
        $staff = User::factory()->create();

        $svc = $this->makeService();
        $result = $svc->recordOfflinePayment($order, 300.0, 'cash', 'Paragon 123', $staff->id);

        $this->assertSame('paid', $result->fresh()->status);
        $this->assertNotNull($result->fresh()->paid_at);
    }

    public function test_record_offline_payment_creates_a_payment_row_with_recorded_by_and_notes(): void
    {
        Notification::fake();

        $order = Order::factory()->offline()->pendingPayment()->create(['total_amount' => 250]);
        $staff = User::factory()->create();

        $svc = $this->makeService();
        $svc->recordOfflinePayment($order, 250.0, 'bank_transfer', 'Przelew 2026-08-16', $staff->id);

        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'method' => 'bank_transfer',
            'amount' => 25000,
            'status' => 'success',
            'recorded_by' => $staff->id,
            'notes' => 'Przelew 2026-08-16',
            'p24_session_id' => null,
        ]);
    }

    public function test_record_offline_payment_dispatches_order_paid_event(): void
    {
        Event::fake([OrderPaid::class]);

        $order = Order::factory()->offline()->pendingPayment()->create(['total_amount' => 100]);
        $staff = User::factory()->create();

        $this->makeService()->recordOfflinePayment($order, 100.0, 'cash', null, $staff->id);

        Event::assertDispatched(OrderPaid::class, fn (OrderPaid $event) => $event->order->id === $order->id);
    }

    public function test_record_offline_payment_sends_order_paid_notification_to_customer(): void
    {
        Notification::fake();

        $order = Order::factory()->offline()->pendingPayment()->create(['total_amount' => 100]);
        $staff = User::factory()->create();

        $this->makeService()->recordOfflinePayment($order, 100.0, 'cash', null, $staff->id);

        Notification::assertSentTo($order->user, OrderPaidNotification::class);
    }

    public function test_record_offline_payment_throws_for_invalid_method(): void
    {
        $order = Order::factory()->offline()->pendingPayment()->create();
        $staff = User::factory()->create();

        $this->expectException(\InvalidArgumentException::class);

        $this->makeService()->recordOfflinePayment($order, 100.0, 'crypto', null, $staff->id);
    }

    public function test_record_offline_payment_throws_when_order_is_not_pending_payment(): void
    {
        $order = Order::factory()->offline()->paid()->create();
        $staff = User::factory()->create();

        $this->expectException(\LogicException::class);

        $this->makeService()->recordOfflinePayment($order, 100.0, 'cash', null, $staff->id);
    }

    public function test_record_offline_payment_does_not_persist_anything_when_it_throws(): void
    {
        $order = Order::factory()->offline()->paid()->create();
        $staff = User::factory()->create();

        try {
            $this->makeService()->recordOfflinePayment($order, 100.0, 'cash', null, $staff->id);
            $this->fail('Expected LogicException was not thrown.');
        } catch (\LogicException $e) {
            $this->assertDatabaseCount('payments', 0);
        }
    }

    // -------------------------------------------------------------------------
    // recordOfflinePayment() — amount mismatch guard: possible, never accidental
    // -------------------------------------------------------------------------

    public function test_record_offline_payment_rejects_a_mismatched_amount_without_confirmation(): void
    {
        $order = Order::factory()->offline()->pendingPayment()->create(['total_amount' => 300]);
        $staff = User::factory()->create();

        $this->expectException(\InvalidArgumentException::class);

        $this->makeService()->recordOfflinePayment($order, 250.0, 'cash', null, $staff->id);
    }

    public function test_record_offline_payment_rejects_a_mismatched_amount_confirmed_but_without_a_reason(): void
    {
        $order = Order::factory()->offline()->pendingPayment()->create(['total_amount' => 300]);
        $staff = User::factory()->create();

        $this->expectException(\InvalidArgumentException::class);

        $this->makeService()->recordOfflinePayment($order, 250.0, 'cash', null, $staff->id, amountMismatchConfirmed: true);
    }

    public function test_record_offline_payment_rejects_a_mismatched_amount_with_only_a_blank_reason(): void
    {
        $order = Order::factory()->offline()->pendingPayment()->create(['total_amount' => 300]);
        $staff = User::factory()->create();

        $this->expectException(\InvalidArgumentException::class);

        $this->makeService()->recordOfflinePayment($order, 250.0, 'cash', '   ', $staff->id, amountMismatchConfirmed: true);
    }

    public function test_record_offline_payment_accepts_a_mismatched_amount_when_confirmed_with_a_reason(): void
    {
        Notification::fake();

        $order = Order::factory()->offline()->pendingPayment()->create(['total_amount' => 300]);
        $staff = User::factory()->create();

        $result = $this->makeService()->recordOfflinePayment(
            $order,
            250.0,
            'cash',
            'Rabat 50 zł udzielony przy odbiorze — uszkodzone opakowanie.',
            $staff->id,
            amountMismatchConfirmed: true,
        );

        $this->assertSame('paid', $result->fresh()->status);
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'amount' => 25000,
        ]);
    }

    public function test_record_offline_payment_does_not_require_confirmation_when_amount_matches_exactly(): void
    {
        Notification::fake();

        $order = Order::factory()->offline()->pendingPayment()->create(['total_amount' => 300]);
        $staff = User::factory()->create();

        // No $amountMismatchConfirmed passed — defaults to false — and no notes either.
        // Must still succeed because the amount matches exactly.
        $result = $this->makeService()->recordOfflinePayment($order, 300.0, 'cash', null, $staff->id);

        $this->assertSame('paid', $result->fresh()->status);
    }

    public function test_record_offline_payment_rejects_mismatch_before_locking_the_row_no_payment_persisted(): void
    {
        $order = Order::factory()->offline()->pendingPayment()->create(['total_amount' => 300]);
        $staff = User::factory()->create();

        try {
            $this->makeService()->recordOfflinePayment($order, 1.0, 'cash', null, $staff->id);
            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (\InvalidArgumentException $e) {
            $this->assertDatabaseCount('payments', 0);
            $this->assertSame('pending_payment', $order->fresh()->status);
        }
    }
}
