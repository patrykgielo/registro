<?php

namespace Tests\Unit\Models;

use App\Models\Order;
use Asantibanez\LaravelEloquentStateMachines\Exceptions\TransitionNotAllowedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_can_be_created(): void
    {
        $order = Order::factory()->create();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'pending_payment',
        ]);
    }

    public function test_order_default_status_is_pending_payment(): void
    {
        $order = Order::factory()->create();

        $this->assertEquals('pending_payment', $order->status);
    }

    // -------------------------------------------------------------------------
    // State machine — allowed transitions
    // -------------------------------------------------------------------------

    public function test_state_machine_allows_pending_payment_to_paid(): void
    {
        $order = Order::factory()->pendingPayment()->create();

        $order->status()->transitionTo('paid');
        $order->refresh();

        $this->assertEquals('paid', $order->status);
    }

    public function test_state_machine_allows_pending_payment_to_cancelled(): void
    {
        $order = Order::factory()->pendingPayment()->create();

        $order->status()->transitionTo('cancelled');
        $order->refresh();

        $this->assertEquals('cancelled', $order->status);
    }

    public function test_state_machine_allows_paid_to_confirmed(): void
    {
        // Create directly in paid state to bypass the state machine
        $order = Order::factory()->paid()->create();

        $order->status()->transitionTo('confirmed');
        $order->refresh();

        $this->assertEquals('confirmed', $order->status);
    }

    public function test_state_machine_allows_confirmed_to_in_progress(): void
    {
        $order = Order::factory()->confirmed()->create();

        $order->status()->transitionTo('in_progress');
        $order->refresh();

        $this->assertEquals('in_progress', $order->status);
    }

    public function test_state_machine_allows_in_progress_to_completed(): void
    {
        $order = Order::factory()->inProgress()->create();

        $order->status()->transitionTo('completed');
        $order->refresh();

        $this->assertEquals('completed', $order->status);
    }

    // -------------------------------------------------------------------------
    // State machine — forbidden transitions
    // -------------------------------------------------------------------------

    public function test_state_machine_forbids_pending_payment_to_completed(): void
    {
        $order = Order::factory()->pendingPayment()->create();

        $this->expectException(TransitionNotAllowedException::class);

        $order->status()->transitionTo('completed');
    }

    public function test_state_machine_forbids_pending_payment_to_confirmed(): void
    {
        $order = Order::factory()->pendingPayment()->create();

        $this->expectException(TransitionNotAllowedException::class);

        $order->status()->transitionTo('confirmed');
    }

    public function test_state_machine_forbids_pending_payment_to_in_progress(): void
    {
        $order = Order::factory()->pendingPayment()->create();

        $this->expectException(TransitionNotAllowedException::class);

        $order->status()->transitionTo('in_progress');
    }

    public function test_state_machine_forbids_cancelled_to_paid(): void
    {
        $order = Order::factory()->cancelled()->create();

        $this->expectException(TransitionNotAllowedException::class);

        $order->status()->transitionTo('paid');
    }

    public function test_state_machine_forbids_completed_to_paid(): void
    {
        $order = Order::factory()->completed()->create();

        $this->expectException(TransitionNotAllowedException::class);

        $order->status()->transitionTo('paid');
    }

    // -------------------------------------------------------------------------
    // scopeExpired
    // -------------------------------------------------------------------------

    public function test_scope_expired_returns_pending_payment_orders_past_expiry(): void
    {
        Order::factory()->expired()->create();

        $this->assertCount(1, Order::expired()->get());
    }

    public function test_scope_expired_does_not_return_pending_payment_orders_not_yet_expired(): void
    {
        Order::factory()->pendingPayment()->create(); // expires_at = +30 min

        $this->assertCount(0, Order::expired()->get());
    }

    public function test_scope_expired_does_not_return_paid_orders_past_expiry_date(): void
    {
        // A paid order whose expires_at is in the past should NOT appear — status is wrong
        Order::factory()->create([
            'status' => 'paid',
            'expires_at' => now()->subHour(),
        ]);

        $this->assertCount(0, Order::expired()->get());
    }

    public function test_scope_expired_does_not_return_cancelled_orders(): void
    {
        Order::factory()->cancelled()->create();

        $this->assertCount(0, Order::expired()->get());
    }

    public function test_scope_expired_only_returns_expired_from_mixed_set(): void
    {
        Order::factory()->expired()->create();
        Order::factory()->expired()->create();
        Order::factory()->pendingPayment()->create(); // not expired
        Order::factory()->paid()->create();
        Order::factory()->cancelled()->create();

        $this->assertCount(2, Order::expired()->get());
    }

    // -------------------------------------------------------------------------
    // canBe helper (via State object)
    // -------------------------------------------------------------------------

    public function test_can_be_returns_true_for_allowed_transition(): void
    {
        $order = Order::factory()->pendingPayment()->create();

        $this->assertTrue($order->status()->canBe('paid'));
        $this->assertTrue($order->status()->canBe('cancelled'));
    }

    public function test_can_be_returns_false_for_forbidden_transition(): void
    {
        $order = Order::factory()->pendingPayment()->create();

        $this->assertFalse($order->status()->canBe('completed'));
        $this->assertFalse($order->status()->canBe('confirmed'));
        $this->assertFalse($order->status()->canBe('in_progress'));
    }
}
