<?php

namespace Tests\Unit\Models;

use App\Models\Order;
use App\Models\Payment;
use Asantibanez\LaravelEloquentStateMachines\Exceptions\TransitionNotAllowedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
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
    // afterTransitionHooks — completed_at
    // -------------------------------------------------------------------------

    public function test_transitioning_to_completed_sets_completed_at(): void
    {
        $order = Order::factory()->inProgress()->create();
        $this->assertNull($order->completed_at);

        $order->status()->transitionTo('completed');
        $order->refresh();

        $this->assertNotNull($order->completed_at);
        $this->assertTrue($order->completed_at->greaterThan(now()->subMinute()));
    }

    public function test_transitioning_to_completed_does_not_overwrite_an_existing_completed_at(): void
    {
        // Reaching 'completed' twice is not possible through the state machine today
        // (transitions() only allows in_progress -> completed, and nothing routes back
        // into in_progress from completed) — this proves the hook's own null-guard would
        // still protect an already-set timestamp if that ever changed. Seeds the
        // precondition directly (completed_at already set) rather than trying to reach
        // it through transition history, then drives the real transitionTo('completed')
        // path (from=in_progress != to=completed, so the hook genuinely fires — this is
        // not the vendor's $to === currentState() no-op).
        // Truncated to whole seconds up front — the datetime column round-trip already
        // drops microseconds, so comparing against a microsecond-precision $original
        // would fail even when the guard correctly left the stored value untouched.
        $original = now()->subDay()->startOfSecond();
        $order = Order::factory()->inProgress()->create(['completed_at' => $original]);

        $order->status()->transitionTo('completed');
        $order->refresh();

        $this->assertTrue($order->completed_at->equalTo($original));
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

    public function test_state_machine_allows_cancelled_to_paid_when_successful_payment_exists(): void
    {
        // Reconciliation-only transition (see Przelewy24Service::handleWebhook()
        // and OrderStatusStateMachine): a genuine P24 success webhook can arrive
        // after orders:cleanup-expired already cancelled the order. 'cancelled'
        // is otherwise terminal — see test_state_machine_forbids_cancelled_to_confirmed().
        // The transition is guarded by validatorForTransition() requiring a
        // successful Payment row to already exist — see the negative test below.
        $order = Order::factory()->cancelled()->create();

        Payment::create([
            'order_id' => $order->id,
            'organization_id' => $order->organization_id,
            'p24_session_id' => 'SESSION-RECONCILE-'.$order->id,
            'p24_order_id' => (string) $order->id,
            'amount' => 10000,
            'currency' => 'PLN',
            'status' => 'success',
            'verified_at' => now(),
        ]);

        $order->status()->transitionTo('paid');

        $this->assertEquals('paid', $order->status);
    }

    public function test_state_machine_forbids_cancelled_to_paid_without_successful_payment(): void
    {
        // This is the test that actually proves the CRITICAL fix: without a
        // verified Payment row, ANY caller (admin action, support script,
        // future bug) attempting cancelled -> paid must be blocked — the
        // transitions() map alone only says the path is legal, not that it's
        // safe. See OrderStatusStateMachine::validatorForTransition().
        $order = Order::factory()->cancelled()->create();

        $this->assertSame(0, $order->payments()->where('status', 'success')->count());

        $this->expectException(ValidationException::class);

        $order->status()->transitionTo('paid');
    }

    public function test_state_machine_forbids_cancelled_to_paid_when_only_failed_payment_exists(): void
    {
        $order = Order::factory()->cancelled()->create();

        Payment::create([
            'order_id' => $order->id,
            'organization_id' => $order->organization_id,
            'p24_session_id' => 'SESSION-FAILED-'.$order->id,
            'p24_order_id' => (string) $order->id,
            'amount' => 10000,
            'currency' => 'PLN',
            'status' => 'failed',
            'verified_at' => null,
        ]);

        $this->expectException(ValidationException::class);

        $order->status()->transitionTo('paid');
    }

    public function test_state_machine_forbids_cancelled_to_confirmed(): void
    {
        $order = Order::factory()->cancelled()->create();

        $this->expectException(TransitionNotAllowedException::class);

        $order->status()->transitionTo('confirmed');
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

    // -------------------------------------------------------------------------
    // ttlGraceMinutes() — clamping (MEDIUM fix)
    // -------------------------------------------------------------------------

    public function test_ttl_grace_minutes_returns_configured_value_within_range(): void
    {
        config(['przelewy24.transaction_grace_minutes' => 90]);

        $this->assertSame(90, Order::ttlGraceMinutes());
    }

    public function test_ttl_grace_minutes_clamps_negative_value_to_zero(): void
    {
        // A negative value would invert the intent: now()->subMinutes(-30)
        // becomes now()->addMinutes(30), cancelling P24-registered orders
        // EARLY, mid-payment. Must clamp to 0, never go negative.
        config(['przelewy24.transaction_grace_minutes' => -30]);

        $this->assertSame(0, Order::ttlGraceMinutes());
    }

    public function test_ttl_grace_minutes_clamps_absurdly_large_value_to_upper_bound(): void
    {
        // An unbounded value would effectively disable expiry for
        // P24-registered orders indefinitely. Must clamp to 1440 (24h).
        config(['przelewy24.transaction_grace_minutes' => 999999]);

        $this->assertSame(1440, Order::ttlGraceMinutes());
    }
}
