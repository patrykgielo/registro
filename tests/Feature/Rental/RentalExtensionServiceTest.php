<?php

declare(strict_types=1);

namespace Tests\Feature\Rental;

use App\Enums\ExtensionRequestStatus;
use App\Exceptions\RentalUnavailableException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemExtensionRequest;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use App\Notifications\RentalExtensionApprovedNotification;
use App\Notifications\RentalExtensionRejectedNotification;
use App\Notifications\RentalExtensionRequestedNotification;
use App\Services\RentalExtensionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RentalExtensionServiceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $customer;

    private User $admin;

    private RentalExtensionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->equipmentRental()->create();
        $this->customer = User::factory()->create();
        $this->admin = User::factory()->create();
        $this->service = app(RentalExtensionService::class);

        // Set tenant on the current request so TenantFeature::currentTenant() resolves
        // inside DB transactions (service calls) without an HTTP request context.
        app('request')->attributes->set('tenant', $this->org);
    }

    // -------------------------------------------------------------------------
    // Helper: create a paid order with one item
    // -------------------------------------------------------------------------

    private function paidOrderWithItem(array $itemOverrides = []): array
    {
        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 3,
            'price_per_day' => 100.00,
        ]);

        $order = Order::factory()->paid()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->customer->id,
            'subtotal' => 300.00,
            'total_amount' => 300.00,
        ]);

        $item = OrderItem::factory()->create(array_merge([
            'order_id' => $order->id,
            'service_id' => $service->id,
            'quantity' => 1,
            'start_date' => Carbon::today()->subDays(5),
            'end_date' => Carbon::today()->addDays(2),
            'rental_days' => 8,
            'unit_price' => 100.00,
            'total_price' => 800.00,
        ], $itemOverrides));

        return [$order, $item, $service];
    }

    // =========================================================================
    // canRequestExtension
    // =========================================================================

    public function test_can_request_extension_returns_true_for_active_paid_order(): void
    {
        [$order, $item] = $this->paidOrderWithItem();

        $this->assertTrue($this->service->canRequestExtension($order, $item));
    }

    #[DataProvider('inactiveOrderStatuses')]
    public function test_cannot_request_extension_for_non_extendable_status(string $status): void
    {
        $order = Order::factory()->create([
            'status' => $status,
            'organization_id' => $this->org->id,
            'user_id' => $this->customer->id,
        ]);

        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'service_id' => Service::factory()->itemRental()->create(['organization_id' => $this->org->id])->id,
            'end_date' => Carbon::today()->addDays(3),
        ]);

        $this->assertFalse($this->service->canRequestExtension($order, $item));
    }

    public static function inactiveOrderStatuses(): array
    {
        return [
            'pending_payment' => ['pending_payment'],
            'completed' => ['completed'],
            'cancelled' => ['cancelled'],
        ];
    }

    public function test_cannot_request_extension_when_pending_request_exists(): void
    {
        [$order, $item] = $this->paidOrderWithItem();

        OrderItemExtensionRequest::create([
            'organization_id' => $this->org->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'requested_by_user_id' => $this->customer->id,
            'status' => ExtensionRequestStatus::Pending,
            'original_end_date' => $item->end_date,
            'requested_end_date' => $item->end_date->copy()->addDays(3),
            'additional_days' => 3,
            'additional_amount' => 300.00,
        ]);

        $this->assertFalse($this->service->canRequestExtension($order, $item));
    }

    public function test_can_request_extension_when_only_rejected_request_exists(): void
    {
        [$order, $item] = $this->paidOrderWithItem();

        OrderItemExtensionRequest::create([
            'organization_id' => $this->org->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'requested_by_user_id' => $this->customer->id,
            'status' => ExtensionRequestStatus::Rejected,
            'original_end_date' => $item->end_date,
            'requested_end_date' => $item->end_date->copy()->addDays(3),
            'additional_days' => 3,
            'additional_amount' => 300.00,
        ]);

        $this->assertTrue($this->service->canRequestExtension($order, $item));
    }

    // =========================================================================
    // requestExtension
    // =========================================================================

    public function test_request_extension_creates_pending_record(): void
    {
        Notification::fake();

        [$order, $item] = $this->paidOrderWithItem();

        $newEndDate = $item->end_date->copy()->addDays(5);

        $request = $this->service->requestExtension(
            $item,
            $this->customer,
            $newEndDate,
            'potrzebuję dłużej'
        );

        // SQLite stores date columns as datetime strings
        $this->assertDatabaseHas('order_item_extension_requests', [
            'order_item_id' => $item->id,
            'order_id' => $order->id,
            'status' => ExtensionRequestStatus::Pending->value,
            'additional_days' => 5,
            'customer_notes' => 'potrzebuję dłużej',
        ]);

        $this->assertEquals(ExtensionRequestStatus::Pending, $request->status);
        $this->assertEquals($newEndDate->toDateString(), $request->requested_end_date->toDateString());
    }

    public function test_request_extension_throws_when_unavailable(): void
    {
        [$order, $item, $service] = $this->paidOrderWithItem();

        // Fill all stock in the extension window
        $blockingOrder = Order::factory()->paid()->create(['organization_id' => $this->org->id]);
        OrderItem::factory()->create([
            'order_id' => $blockingOrder->id,
            'service_id' => $service->id,
            'quantity' => 3, // exhausts the 3 total
            'start_date' => $item->end_date->copy()->addDay(),
            'end_date' => $item->end_date->copy()->addDays(5),
        ]);

        $this->expectException(RentalUnavailableException::class);

        $this->service->requestExtension(
            $item,
            $this->customer,
            $item->end_date->copy()->addDays(3),
            null
        );
    }

    public function test_request_extension_throws_when_pending_request_already_exists(): void
    {
        Notification::fake();

        [$order, $item] = $this->paidOrderWithItem();

        OrderItemExtensionRequest::create([
            'organization_id' => $this->org->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'requested_by_user_id' => $this->customer->id,
            'status' => ExtensionRequestStatus::Pending,
            'original_end_date' => $item->end_date,
            'requested_end_date' => $item->end_date->copy()->addDays(3),
            'additional_days' => 3,
            'additional_amount' => 300.00,
        ]);

        $this->expectException(RentalUnavailableException::class);

        $this->service->requestExtension(
            $item,
            $this->customer,
            $item->end_date->copy()->addDays(5),
            null
        );
    }

    public function test_request_extension_notifies_org_owner(): void
    {
        Notification::fake();

        $owner = User::factory()->create();
        $this->org->update(['owner_id' => $owner->id]);
        // Refresh request tenant so the updated org is used
        app('request')->attributes->set('tenant', $this->org->fresh());

        [$order, $item] = $this->paidOrderWithItem();

        $this->service->requestExtension(
            $item,
            $this->customer,
            $item->end_date->copy()->addDays(3),
            null
        );

        Notification::assertSentTo($owner, RentalExtensionRequestedNotification::class);
    }

    // =========================================================================
    // approve
    // =========================================================================

    public function test_approve_updates_item_end_date_and_rental_days(): void
    {
        Notification::fake();

        [$order, $item] = $this->paidOrderWithItem();
        $newEndDate = $item->end_date->copy()->addDays(4);

        $extensionRequest = OrderItemExtensionRequest::create([
            'organization_id' => $this->org->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'requested_by_user_id' => $this->customer->id,
            'status' => ExtensionRequestStatus::Pending,
            'original_end_date' => $item->end_date,
            'requested_end_date' => $newEndDate,
            'additional_days' => 4,
            'additional_amount' => 400.00,
        ]);

        $this->service->approve($extensionRequest, $this->admin);

        $item->refresh();
        $this->assertEquals($newEndDate->toDateString(), $item->end_date->toDateString());
        $this->assertEquals(12, $item->rental_days); // 8 + 4
        $this->assertEquals(1200.00, (float) $item->total_price); // 800 + 400
    }

    public function test_approve_increments_order_subtotal_and_total(): void
    {
        Notification::fake();

        [$order, $item] = $this->paidOrderWithItem();
        $newEndDate = $item->end_date->copy()->addDays(4);

        $extensionRequest = OrderItemExtensionRequest::create([
            'organization_id' => $this->org->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'requested_by_user_id' => $this->customer->id,
            'status' => ExtensionRequestStatus::Pending,
            'original_end_date' => $item->end_date,
            'requested_end_date' => $newEndDate,
            'additional_days' => 4,
            'additional_amount' => 400.00,
        ]);

        $this->service->approve($extensionRequest, $this->admin);

        $order->refresh();
        $this->assertEquals(700.00, (float) $order->subtotal);     // 300 + 400
        $this->assertEquals(700.00, (float) $order->total_amount);  // 300 + 400
    }

    public function test_approve_marks_request_as_approved(): void
    {
        Notification::fake();

        [$order, $item] = $this->paidOrderWithItem();

        $extensionRequest = OrderItemExtensionRequest::create([
            'organization_id' => $this->org->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'requested_by_user_id' => $this->customer->id,
            'status' => ExtensionRequestStatus::Pending,
            'original_end_date' => $item->end_date,
            'requested_end_date' => $item->end_date->copy()->addDays(3),
            'additional_days' => 3,
            'additional_amount' => 300.00,
        ]);

        $this->service->approve($extensionRequest, $this->admin);

        $extensionRequest->refresh();
        $this->assertEquals(ExtensionRequestStatus::Approved, $extensionRequest->status);
        $this->assertEquals($this->admin->id, $extensionRequest->approved_by_user_id);
        $this->assertNotNull($extensionRequest->approved_at);
    }

    public function test_approve_auto_rejects_competing_pending_requests(): void
    {
        Notification::fake();

        [$order, $item] = $this->paidOrderWithItem();

        $requestA = OrderItemExtensionRequest::create([
            'organization_id' => $this->org->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'requested_by_user_id' => $this->customer->id,
            'status' => ExtensionRequestStatus::Pending,
            'original_end_date' => $item->end_date,
            'requested_end_date' => $item->end_date->copy()->addDays(3),
            'additional_days' => 3,
            'additional_amount' => 300.00,
        ]);

        $requestB = OrderItemExtensionRequest::create([
            'organization_id' => $this->org->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'requested_by_user_id' => $this->customer->id,
            'status' => ExtensionRequestStatus::Pending,
            'original_end_date' => $item->end_date,
            'requested_end_date' => $item->end_date->copy()->addDays(7),
            'additional_days' => 7,
            'additional_amount' => 700.00,
        ]);

        $this->service->approve($requestA, $this->admin);

        $requestB->refresh();
        $this->assertEquals(ExtensionRequestStatus::Rejected, $requestB->status);
        $this->assertNotEmpty($requestB->rejection_reason);
        // auto-reject must NOT set approved_by_user_id on the rejected competing request
        $this->assertNull($requestB->approved_by_user_id);
    }

    public function test_approve_notifies_customer(): void
    {
        Notification::fake();

        [$order, $item] = $this->paidOrderWithItem();

        $extensionRequest = OrderItemExtensionRequest::create([
            'organization_id' => $this->org->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'requested_by_user_id' => $this->customer->id,
            'status' => ExtensionRequestStatus::Pending,
            'original_end_date' => $item->end_date,
            'requested_end_date' => $item->end_date->copy()->addDays(3),
            'additional_days' => 3,
            'additional_amount' => 300.00,
        ]);

        $this->service->approve($extensionRequest, $this->admin);

        Notification::assertSentTo($this->customer, RentalExtensionApprovedNotification::class);
    }

    public function test_approve_throws_runtime_exception_when_already_approved(): void
    {
        [$order, $item] = $this->paidOrderWithItem();

        $extensionRequest = OrderItemExtensionRequest::create([
            'organization_id' => $this->org->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'requested_by_user_id' => $this->customer->id,
            'status' => ExtensionRequestStatus::Approved,
            'original_end_date' => $item->end_date,
            'requested_end_date' => $item->end_date->copy()->addDays(3),
            'additional_days' => 3,
            'additional_amount' => 300.00,
        ]);

        $this->expectException(\RuntimeException::class);

        $this->service->approve($extensionRequest, $this->admin);
    }

    // =========================================================================
    // reject
    // =========================================================================

    public function test_reject_marks_request_as_rejected_with_reason(): void
    {
        Notification::fake();

        [$order, $item] = $this->paidOrderWithItem();

        $extensionRequest = OrderItemExtensionRequest::create([
            'organization_id' => $this->org->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'requested_by_user_id' => $this->customer->id,
            'status' => ExtensionRequestStatus::Pending,
            'original_end_date' => $item->end_date,
            'requested_end_date' => $item->end_date->copy()->addDays(3),
            'additional_days' => 3,
            'additional_amount' => 300.00,
        ]);

        $this->service->reject($extensionRequest, $this->admin, 'Sprzęt już zarezerwowany.');

        $extensionRequest->refresh();
        $this->assertEquals(ExtensionRequestStatus::Rejected, $extensionRequest->status);
        $this->assertEquals('Sprzęt już zarezerwowany.', $extensionRequest->rejection_reason);
    }

    public function test_reject_does_not_modify_order_or_item(): void
    {
        Notification::fake();

        [$order, $item] = $this->paidOrderWithItem();

        $originalEndDate = $item->end_date->toDateString();
        $originalTotal = (float) $order->total_amount;

        $extensionRequest = OrderItemExtensionRequest::create([
            'organization_id' => $this->org->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'requested_by_user_id' => $this->customer->id,
            'status' => ExtensionRequestStatus::Pending,
            'original_end_date' => $item->end_date,
            'requested_end_date' => $item->end_date->copy()->addDays(3),
            'additional_days' => 3,
            'additional_amount' => 300.00,
        ]);

        $this->service->reject($extensionRequest, $this->admin, 'Powód.');

        $item->refresh();
        $order->refresh();

        $this->assertEquals($originalEndDate, $item->end_date->toDateString());
        $this->assertEquals($originalTotal, (float) $order->total_amount);
    }

    public function test_reject_notifies_customer(): void
    {
        Notification::fake();

        [$order, $item] = $this->paidOrderWithItem();

        $extensionRequest = OrderItemExtensionRequest::create([
            'organization_id' => $this->org->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'requested_by_user_id' => $this->customer->id,
            'status' => ExtensionRequestStatus::Pending,
            'original_end_date' => $item->end_date,
            'requested_end_date' => $item->end_date->copy()->addDays(3),
            'additional_days' => 3,
            'additional_amount' => 300.00,
        ]);

        $this->service->reject($extensionRequest, $this->admin, 'Brak miejsca w magazynie.');

        Notification::assertSentTo($this->customer, RentalExtensionRejectedNotification::class);
    }

    // =========================================================================
    // calculateAdditionalAmount
    // =========================================================================

    public function test_calculates_additional_amount_based_on_price_per_day(): void
    {
        $service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 5,
            'price_per_day' => 150.00,
        ]);

        $order = Order::factory()->paid()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->customer->id,
        ]);

        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'service_id' => $service->id,
            'quantity' => 2,
            'start_date' => Carbon::today()->subDays(3),
            'end_date' => Carbon::today()->addDays(2),
        ]);

        $amount = $this->service->calculateAdditionalAmount($item, 5);

        // 150 * 5 days * 2 quantity = 1500
        $this->assertEquals(1500.00, $amount);
    }
}
