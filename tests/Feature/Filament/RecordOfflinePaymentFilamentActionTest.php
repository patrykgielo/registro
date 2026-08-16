<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Resources\OrderResource\Pages\EditOrder;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Models\Order;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Faza 1 of app/docs/features/payment-settlement-modes.md — panel action
 * "Odnotuj wpłatę" (record_offline_payment), duplicated intentionally between
 * OrderResource (table row action) and EditOrder (header action) — see
 * filament-resources.md's module gating note + OrderResource.php's own
 * comment on this duplication.
 */
class RecordOfflinePaymentFilamentActionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $this->org = Organization::factory()->equipmentRental()->create();
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($this->admin);
    }

    // -------------------------------------------------------------------------
    // Visibility
    // -------------------------------------------------------------------------

    public function test_action_is_visible_for_offline_pending_payment_orders(): void
    {
        $order = Order::factory()->offline()->pendingPayment()->create([
            'organization_id' => $this->org->id,
        ]);

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionVisible('record_offline_payment');

        Livewire::test(ListOrders::class)
            ->assertTableActionVisible('record_offline_payment', $order);
    }

    public function test_action_is_hidden_for_online_pending_payment_orders(): void
    {
        $order = Order::factory()->pendingPayment()->create([
            'organization_id' => $this->org->id,
        ]);

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionHidden('record_offline_payment');

        Livewire::test(ListOrders::class)
            ->assertTableActionHidden('record_offline_payment', $order);
    }

    public function test_action_is_hidden_once_the_offline_order_is_already_paid(): void
    {
        $order = Order::factory()->offline()->paid()->create([
            'organization_id' => $this->org->id,
        ]);

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionHidden('record_offline_payment');
    }

    // -------------------------------------------------------------------------
    // Behaviour
    // -------------------------------------------------------------------------

    public function test_calling_the_action_records_the_payment_and_transitions_to_paid(): void
    {
        Notification::fake();

        $order = Order::factory()->offline()->pendingPayment()->create([
            'organization_id' => $this->org->id,
            'total_amount' => 300,
        ]);

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('record_offline_payment', [
                'amount' => 300,
                'method' => 'cash',
                'notes' => 'Paragon 42',
            ])
            ->assertHasNoActionErrors();

        $order->refresh();

        $this->assertSame('paid', $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'method' => 'cash',
            'amount' => 30000,
            'status' => 'success',
            'recorded_by' => $this->admin->id,
            'notes' => 'Paragon 42',
        ]);
    }

    public function test_table_action_records_the_payment_and_transitions_to_paid(): void
    {
        Notification::fake();

        $order = Order::factory()->offline()->pendingPayment()->create([
            'organization_id' => $this->org->id,
            'total_amount' => 150,
        ]);

        Livewire::test(ListOrders::class)
            ->callTableAction('record_offline_payment', $order, [
                'amount' => 150,
                'method' => 'bank_transfer',
                'notes' => null,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame('paid', $order->fresh()->status);
    }

    // -------------------------------------------------------------------------
    // Amount mismatch — possible, never accidental (see OrderServiceTest for the
    // domain-layer guard this UI surfaces)
    // -------------------------------------------------------------------------

    public function test_mismatched_amount_without_confirmation_does_not_transition_the_order(): void
    {
        $order = Order::factory()->offline()->pendingPayment()->create([
            'organization_id' => $this->org->id,
            'total_amount' => 300,
        ]);

        // The action closure catches OrderService's exception and renders a Filament
        // danger notification rather than throwing back into Livewire — so this is
        // pinned by observing the order was NOT transitioned, same technique the
        // other guard-rejection cases in this class use implicitly.
        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('record_offline_payment', [
                'amount' => 250,
                'method' => 'cash',
                'notes' => null,
            ]);

        $this->assertSame('pending_payment', $order->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_mismatched_amount_confirmed_with_a_reason_transitions_the_order(): void
    {
        Notification::fake();

        $order = Order::factory()->offline()->pendingPayment()->create([
            'organization_id' => $this->org->id,
            'total_amount' => 300,
        ]);

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('record_offline_payment', [
                'amount' => 250,
                'method' => 'cash',
                'amount_mismatch_confirmed' => true,
                'notes' => 'Rabat przy odbiorze — uszkodzone opakowanie.',
            ])
            ->assertHasNoActionErrors();

        $order->refresh();
        $this->assertSame('paid', $order->status);
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'amount' => 25000,
        ]);
    }
}
