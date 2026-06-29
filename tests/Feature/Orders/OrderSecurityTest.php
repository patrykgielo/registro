<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Organization;
use App\Models\User;
use App\Services\Order\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderSecurityTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // Cross-tenant user isolation
    // -------------------------------------------------------------------------

    public function test_user_with_orders_in_tenant_a_is_not_visible_to_tenant_b(): void
    {
        $orgA = Organization::factory()->equipmentRental()->create();
        $orgB = Organization::factory()->equipmentRental()->create();

        $userA = User::factory()->create();
        Order::factory()->create(['organization_id' => $orgA->id, 'user_id' => $userA->id]);

        // orgB should NOT see userA when scoping to its own orders
        $count = User::whereHas(
            'orders',
            fn ($q) => $q->where('organization_id', $orgB->id)
        )->where('id', $userA->id)->count();

        $this->assertEquals(0, $count);
    }

    public function test_user_with_orders_in_own_tenant_is_visible(): void
    {
        $org = Organization::factory()->equipmentRental()->create();

        $user = User::factory()->create();
        Order::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id]);

        $count = User::whereHas(
            'orders',
            fn ($q) => $q->where('organization_id', $org->id)
        )->where('id', $user->id)->count();

        $this->assertEquals(1, $count);
    }

    public function test_cross_tenant_user_lookup_by_id_returns_null_for_wrong_tenant(): void
    {
        $orgA = Organization::factory()->equipmentRental()->create();
        $orgB = Organization::factory()->equipmentRental()->create();

        $userA = User::factory()->create();
        Order::factory()->create(['organization_id' => $orgA->id, 'user_id' => $userA->id]);

        // Scoped query for orgB should not find userA
        $found = User::whereHas(
            'orders',
            fn ($q) => $q->where('organization_id', $orgB->id)
        )->find($userA->id);

        $this->assertNull($found);
    }

    // -------------------------------------------------------------------------
    // Order immutability guard
    // -------------------------------------------------------------------------

    public function test_updating_total_amount_throws_logic_exception(): void
    {
        $order = Order::factory()->create(['total_amount' => 100.00]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/total_amount.*immutable/i');

        $order->update(['total_amount' => 999.00]);
    }

    public function test_updating_organization_id_throws_logic_exception(): void
    {
        $org2 = Organization::factory()->equipmentRental()->create();
        $order = Order::factory()->create();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/organization_id.*immutable/i');

        $order->update(['organization_id' => $org2->id]);
    }

    public function test_updating_order_number_throws_logic_exception(): void
    {
        $order = Order::factory()->create(['order_number' => 'OLD-1234']);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/order_number.*immutable/i');

        $order->update(['order_number' => 'TAMPERED-9999']);
    }

    public function test_updating_deposit_amount_throws_logic_exception(): void
    {
        $order = Order::factory()->create(['deposit_amount' => 200.00]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/deposit_amount.*immutable/i');

        $order->update(['deposit_amount' => 0.00]);
    }

    public function test_legal_update_of_mutable_address_field_succeeds(): void
    {
        $order = Order::factory()->create(['customer_city' => 'Warszawa']);

        $order->update(['customer_city' => 'Kraków']);
        $order->refresh();

        $this->assertEquals('Kraków', $order->customer_city);
    }

    public function test_deposit_status_update_succeeds(): void
    {
        $order = Order::factory()->create(['deposit_status' => 'pending', 'deposit_amount' => 500.00]);

        $order->update([
            'deposit_status' => 'collected',
            'deposit_collected_at' => now(),
        ]);
        $order->refresh();

        $this->assertEquals('collected', $order->deposit_status);
        $this->assertNotNull($order->deposit_collected_at);
    }

    public function test_notes_update_succeeds(): void
    {
        $order = Order::factory()->create(['notes' => null]);

        $order->update(['notes' => 'Admin note']);
        $order->refresh();

        $this->assertEquals('Admin note', $order->notes);
    }

    // -------------------------------------------------------------------------
    // Audit trail — Order PII changes are logged
    // -------------------------------------------------------------------------

    public function test_audit_log_created_when_customer_email_changes(): void
    {
        $order = Order::factory()->create(['customer_email' => 'old@example.com']);

        $order->update(['customer_email' => 'new@example.com']);

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Order::class,
            'auditable_id' => $order->id,
            'event' => 'updated',
        ]);
    }

    public function test_audit_log_created_when_status_changes(): void
    {
        $order = Order::factory()->paid()->create();

        $order->status()->transitionTo('confirmed');

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Order::class,
            'auditable_id' => $order->id,
            'event' => 'updated',
        ]);
    }

    public function test_audit_log_created_on_order_creation(): void
    {
        $order = Order::factory()->create();

        $this->assertDatabaseHas('audit_logs', [
            'auditable_type' => Order::class,
            'auditable_id' => $order->id,
            'event' => 'created',
        ]);
    }

    public function test_p24_fields_are_excluded_from_audit_log(): void
    {
        $order = Order::factory()->create();
        AuditLog::where('auditable_type', Order::class)->delete();

        $order->p24_session_id = 'sess_abc123';
        $order->save();

        // No audit log should be written for p24_ only changes
        $this->assertDatabaseMissing('audit_logs', [
            'auditable_type' => Order::class,
            'auditable_id' => $order->id,
            'event' => 'updated',
        ]);
    }

    // -------------------------------------------------------------------------
    // OrderService::cancel() — confirmed status now allowed
    // -------------------------------------------------------------------------

    public function test_cancel_service_allows_confirmed_order(): void
    {
        $order = Order::factory()->confirmed()->create();

        $result = app(OrderService::class)->cancel($order, 'Admin decision');
        $result->refresh();

        $this->assertEquals('cancelled', $result->status);
        $this->assertNotNull($result->cancelled_at);
    }

    public function test_cancel_service_rejects_in_progress_order(): void
    {
        $order = Order::factory()->inProgress()->create();

        $this->expectException(\LogicException::class);

        app(OrderService::class)->cancel($order, 'Should fail');
    }
}
