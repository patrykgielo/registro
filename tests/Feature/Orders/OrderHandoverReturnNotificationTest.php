<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Notifications\OrderCancelledNotification;
use App\Notifications\OrderConfirmedNotification;
use App\Notifications\OrderHandedOverNotification;
use App\Notifications\OrderReturnedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Handover ("Wydano klientowi", confirmed -> in_progress) and return
 * ("Sprzęt zwrócony", in_progress -> completed) previously fired no customer
 * email at all — see OrderStatusStateMachine::afterTransitionHooks() and
 * tests/Browser/OrderLifecycleEmailTest.php's former assertion of exactly
 * that absence. These tests pin the new behaviour and prove it did not
 * exist before: run against the pre-fix state machine, every "is sent"
 * assertion below fails.
 */
class OrderHandoverReturnNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_transitioning_to_in_progress_notifies_customer_of_handover(): void
    {
        Notification::fake();

        $order = Order::factory()->confirmed()->create();
        OrderItem::factory()->create(['order_id' => $order->id]);

        $order->status()->transitionTo('in_progress');

        Notification::assertSentTo($order->user, OrderHandedOverNotification::class);
    }

    public function test_transitioning_to_completed_notifies_customer_of_return(): void
    {
        Notification::fake();

        $order = Order::factory()->inProgress()->create();
        OrderItem::factory()->create(['order_id' => $order->id]);

        $order->status()->transitionTo('completed');

        Notification::assertSentTo($order->user, OrderReturnedNotification::class);
    }

    public function test_transitioning_to_in_progress_does_not_send_the_return_notification(): void
    {
        Notification::fake();

        $order = Order::factory()->confirmed()->create();
        OrderItem::factory()->create(['order_id' => $order->id]);

        $order->status()->transitionTo('in_progress');

        Notification::assertNotSentTo($order->user, OrderReturnedNotification::class);
    }

    public function test_transitioning_to_completed_does_not_send_the_handover_notification_again(): void
    {
        Notification::fake();

        $order = Order::factory()->inProgress()->create();
        OrderItem::factory()->create(['order_id' => $order->id]);

        $order->status()->transitionTo('completed');

        Notification::assertNotSentTo($order->user, OrderHandedOverNotification::class);
    }

    public function test_transitioning_to_confirmed_does_not_send_handover_or_return_notifications(): void
    {
        Notification::fake();

        $order = Order::factory()->paid()->create();

        $order->status()->transitionTo('confirmed');

        Notification::assertSentTo($order->user, OrderConfirmedNotification::class);
        Notification::assertNotSentTo($order->user, OrderHandedOverNotification::class);
        Notification::assertNotSentTo($order->user, OrderReturnedNotification::class);
    }

    public function test_cancelling_a_confirmed_order_does_not_send_handover_or_return_notifications(): void
    {
        Notification::fake();

        $order = Order::factory()->confirmed()->create();

        $order->status()->transitionTo('cancelled');

        Notification::assertSentTo($order->user, OrderCancelledNotification::class);
        Notification::assertNotSentTo($order->user, OrderHandedOverNotification::class);
        Notification::assertNotSentTo($order->user, OrderReturnedNotification::class);
    }

    /**
     * The email guard is DELIBERATELY independent from the completed_at
     * guard (see OrderStatusStateMachine::afterTransitionHooks()'s 'completed'
     * comment for the full reasoning) — completed_at could already be set by
     * something that never went through this hook at all (a backfill, a data
     * migration, an import). A genuine transitionTo('completed') call must
     * still email the customer even then; only the timestamp write itself is
     * skipped, so the pre-existing value survives untouched.
     */
    public function test_transitioning_to_completed_still_notifies_even_when_completed_at_was_already_set(): void
    {
        Notification::fake();

        $original = now()->subDay()->startOfSecond();
        $order = Order::factory()->inProgress()->create(['completed_at' => $original]);
        OrderItem::factory()->create(['order_id' => $order->id]);

        $order->status()->transitionTo('completed');
        $order->refresh();

        Notification::assertSentTo($order->user, OrderReturnedNotification::class);
        $this->assertTrue($order->completed_at->equalTo($original));
    }
}
