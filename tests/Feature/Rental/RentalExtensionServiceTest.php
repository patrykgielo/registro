<?php

declare(strict_types=1);

namespace Tests\Feature\Rental;

use App\Enums\ExtensionRequestStatus;
use App\Exceptions\RentalUnavailableException;
use App\Models\AuditLog;
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

    /**
     * Decision #1 (Order::applyFinancialAdjustment escape hatch): the financial
     * mutation performed by approve() must still be captured in the audit
     * trail — it must NOT silently bypass Auditable via saveQuietly() or a
     * raw DB update. total_amount/subtotal are both in Order::$auditInclude.
     */
    public function test_approve_writes_an_audit_log_entry_for_the_order_financial_change(): void
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

        $log = AuditLog::where('auditable_type', Order::class)
            ->where('auditable_id', $order->id)
            ->where('event', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($log, 'Expected an AuditLog row for the Order financial adjustment.');
        $this->assertArrayHasKey('subtotal', $log->new_values);
        $this->assertArrayHasKey('total_amount', $log->new_values);
        $this->assertEquals(700.00, (float) $log->new_values['subtotal']);
        $this->assertEquals(700.00, (float) $log->new_values['total_amount']);
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

    /**
     * Concurrency-style regression test (2026-07-06, VULN follow-up): proves the
     * `forUpdate: true` fix actually closes the race, not just that *some*
     * locking happens.
     *
     * Scenario: a customer's extension request is created while the target
     * window is genuinely free (request-time check passes). Before an admin
     * gets to approve() it, ANOTHER, completely unrelated reservation commits
     * against the exact same window — exactly what a second, near-simultaneous
     * transaction committing first would look like from approve()'s point of
     * view. Because approve() re-locks the Service row and re-runs the
     * availability count as a locking read (forUpdate: true) rather than
     * trusting the stale request-time check, it must catch this and reject —
     * proving the fix closes the window between "request accepted" and
     * "admin approves", not just that a lock is *taken* somewhere.
     *
     * Before the fix (no forUpdate on the re-check), this exact sequence would
     * have silently approved on top of already-consumed capacity — an oversell,
     * with no exception raised and no test to catch it (existing tests only
     * asserted that locks are acquired, never that a genuinely-competing
     * reservation is detected at approve() time).
     */
    public function test_approve_rejects_when_a_competing_reservation_commits_after_the_request_was_created(): void
    {
        Notification::fake();

        [$order, $item, $service] = $this->paidOrderWithItem();
        $service->update(['quantity_total' => 1]);

        $newEndDate = $item->end_date->copy()->addDays(5);

        // Request-time check passes: window is genuinely free right now.
        $extensionRequest = OrderItemExtensionRequest::create([
            'organization_id' => $this->org->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'requested_by_user_id' => $this->customer->id,
            'status' => ExtensionRequestStatus::Pending,
            'original_end_date' => $item->end_date,
            'requested_end_date' => $newEndDate,
            'additional_days' => 5,
            'additional_amount' => 500.00,
        ]);

        // Simulates a second, independent transaction winning the race and
        // committing a reservation against the same window BEFORE approve()
        // runs — the exact interleaving forUpdate: true is meant to catch.
        $competingOrder = Order::factory()->paid()->create(['organization_id' => $this->org->id]);
        OrderItem::factory()->create([
            'order_id' => $competingOrder->id,
            'service_id' => $service->id,
            'quantity' => 1, // consumes the only unit in the shared window
            'start_date' => $item->end_date->copy()->addDays(2),
            'end_date' => $item->end_date->copy()->addDays(3),
        ]);

        $this->expectException(RentalUnavailableException::class);

        try {
            $this->service->approve($extensionRequest, $this->admin);
        } finally {
            // Must not have partially applied — item untouched, order totals untouched.
            $item->refresh();
            $order->refresh();
            $extensionRequest->refresh();

            $this->assertNotEquals($newEndDate->toDateString(), $item->end_date->toDateString());
            $this->assertEquals(300.00, (float) $order->subtotal);
            $this->assertEquals(300.00, (float) $order->total_amount);
            $this->assertEquals(ExtensionRequestStatus::Pending, $extensionRequest->status);
        }
    }

    /**
     * Lost-update regression (2026-07-06, code-review follow-up): approve()
     * used to do `$order = $extensionRequest->order;` — a plain lazy-loaded
     * belongsTo with no row lock — before calling
     * Order::applyFinancialAdjustment() (a read-modify-write, not an atomic
     * SQL increment). Under genuine concurrent approvals of two different
     * pending requests on items of the *same* multi-item order, both
     * transactions could read the same starting subtotal/total_amount and
     * the second save would silently overwrite the first's increment.
     *
     * The fix re-fetches the order with `lockForUpdate()` (keyed by
     * `$extensionRequest->order_id`, not the stale relation) immediately
     * before applying the adjustment, matching
     * Przelewy24Service::handleWebhook()'s pattern.
     *
     * HONEST LIMITATION: this test's assertions (both deltas summed
     * correctly) would also pass against the pre-fix code when run
     * sequentially in a single-connection SQLite test — Eloquent always
     * issues a fresh SELECT per approve() call here regardless of locking,
     * and SQLite's grammar drops `FOR UPDATE` entirely
     * (`SQLiteGrammar::compileLock()` returns ''), so this suite cannot
     * literally reproduce the two-connection race the fix protects against
     * in production (MySQL). What this test DOES verify: the new
     * order_id-keyed re-fetch path is exercised end-to-end and multi-item
     * accumulation across two separate approve() calls on the same order is
     * correct — a real gap in coverage, since every other approve() test in
     * this file uses a single-item order.
     */
    public function test_approve_accumulates_totals_correctly_across_two_items_on_the_same_order(): void
    {
        Notification::fake();

        $serviceA = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 3,
            'price_per_day' => 100.00,
        ]);
        $serviceB = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 3,
            'price_per_day' => 100.00,
        ]);

        $order = Order::factory()->paid()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->customer->id,
            'subtotal' => 600.00,
            'total_amount' => 600.00,
        ]);

        $itemA = OrderItem::factory()->create([
            'order_id' => $order->id,
            'service_id' => $serviceA->id,
            'quantity' => 1,
            'start_date' => Carbon::today()->subDays(5),
            'end_date' => Carbon::today()->addDays(2),
            'rental_days' => 8,
            'unit_price' => 100.00,
            'total_price' => 800.00,
        ]);

        $itemB = OrderItem::factory()->create([
            'order_id' => $order->id,
            'service_id' => $serviceB->id,
            'quantity' => 1,
            'start_date' => Carbon::today()->subDays(5),
            'end_date' => Carbon::today()->addDays(2),
            'rental_days' => 8,
            'unit_price' => 100.00,
            'total_price' => 800.00,
        ]);

        $requestA = OrderItemExtensionRequest::create([
            'organization_id' => $this->org->id,
            'order_id' => $order->id,
            'order_item_id' => $itemA->id,
            'requested_by_user_id' => $this->customer->id,
            'status' => ExtensionRequestStatus::Pending,
            'original_end_date' => $itemA->end_date,
            'requested_end_date' => $itemA->end_date->copy()->addDays(4),
            'additional_days' => 4,
            'additional_amount' => 400.00,
        ]);

        $requestB = OrderItemExtensionRequest::create([
            'organization_id' => $this->org->id,
            'order_id' => $order->id,
            'order_item_id' => $itemB->id,
            'requested_by_user_id' => $this->customer->id,
            'status' => ExtensionRequestStatus::Pending,
            'original_end_date' => $itemB->end_date,
            'requested_end_date' => $itemB->end_date->copy()->addDays(3),
            'additional_days' => 3,
            'additional_amount' => 300.00,
        ]);

        // "Quick succession" — both approved back-to-back, exercising the
        // order_id-keyed lockForUpdate() re-fetch on each call.
        $this->service->approve($requestA, $this->admin);
        $this->service->approve($requestB, $this->admin);

        $order->refresh();
        $this->assertEquals(1300.00, (float) $order->subtotal);     // 600 + 400 + 300
        $this->assertEquals(1300.00, (float) $order->total_amount); // 600 + 400 + 300

        // Neither request was clobbered by the other (different order_item_id
        // → auto-reject-competing-requests logic must not touch it).
        $requestB->refresh();
        $this->assertEquals(ExtensionRequestStatus::Approved, $requestB->status);
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
