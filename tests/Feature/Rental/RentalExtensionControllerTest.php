<?php

declare(strict_types=1);

namespace Tests\Feature\Rental;

use App\Enums\ExtensionRequestStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemExtensionRequest;
use App\Models\Organization;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RentalExtensionControllerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $customer;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->org = Organization::factory()->equipmentRental()->create();
        $this->customer = User::factory()->create();
        $this->service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'quantity_total' => 3,
            'price_per_day' => 100.00,
        ]);

        $this->withoutMiddleware([ThrottleRequests::class]);
        $this->actingAsTenant($this->org);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function actingAsTenant(Organization $org): static
    {
        $this->app->bind(\App\Http\Middleware\ResolveTenant::class, function () use ($org) {
            return new class($org)
            {
                public function __construct(private Organization $org) {}

                public function handle($request, $next)
                {
                    $request->attributes->set('tenant', $this->org);

                    return $next($request);
                }
            };
        });

        return $this;
    }

    private function enableRentalExtension(): void
    {
        \App\Models\Setting::create([
            'group' => 'rentals',
            'key' => 'rental_extension_enabled',
            'value' => [true],
            'organization_id' => $this->org->id,
        ]);
    }

    private function paidOrder(): array
    {
        $order = Order::factory()->paid()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->customer->id,
            'subtotal' => 500.00,
            'total_amount' => 500.00,
        ]);

        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'service_id' => $this->service->id,
            'quantity' => 1,
            'start_date' => Carbon::today()->subDays(3),
            'end_date' => Carbon::today()->addDays(4),
            'rental_days' => 8,
            'unit_price' => 100.00,
            'total_price' => 800.00,
        ]);

        return [$order, $item];
    }

    private function checkUrl(Order $order, OrderItem $item): string
    {
        return route('orders.extension.check', [$order, $item]);
    }

    private function storeUrl(Order $order, OrderItem $item): string
    {
        return route('orders.extension.store', [$order, $item]);
    }

    // =========================================================================
    // Feature disabled → 404
    // =========================================================================

    public function test_check_returns_404_when_extension_feature_disabled(): void
    {
        // No enableRentalExtension() call — feature disabled by default
        [$order, $item] = $this->paidOrder();

        $this->actingAs($this->customer)
            ->getJson($this->checkUrl($order, $item).'?new_end_date='.Carbon::today()->addDays(7)->toDateString())
            ->assertNotFound();
    }

    public function test_store_returns_404_when_extension_feature_disabled(): void
    {
        [$order, $item] = $this->paidOrder();

        $this->actingAs($this->customer)
            ->post($this->storeUrl($order, $item), [
                'new_end_date' => Carbon::today()->addDays(7)->toDateString(),
            ])
            ->assertNotFound();
    }

    // =========================================================================
    // Authentication
    // =========================================================================

    public function test_check_returns_401_when_unauthenticated(): void
    {
        $this->enableRentalExtension();
        [$order, $item] = $this->paidOrder();

        // getJson sets Accept: application/json → auth middleware returns 401 not redirect
        $this->getJson($this->checkUrl($order, $item).'?new_end_date='.Carbon::today()->addDays(7)->toDateString())
            ->assertUnauthorized();
    }

    public function test_store_redirects_to_login_when_unauthenticated(): void
    {
        [$order, $item] = $this->paidOrder();

        $this->post($this->storeUrl($order, $item), ['new_end_date' => Carbon::today()->addDays(7)->toDateString()])
            ->assertRedirect(route('login'));
    }

    // =========================================================================
    // IDOR — 403 when accessing another user's order
    // =========================================================================

    public function test_check_returns_403_for_another_users_order(): void
    {
        $this->enableRentalExtension();
        [$order, $item] = $this->paidOrder();
        $otherUser = User::factory()->create();

        $this->actingAs($otherUser)
            ->getJson($this->checkUrl($order, $item).'?new_end_date='.Carbon::today()->addDays(7)->toDateString())
            ->assertForbidden();
    }

    public function test_store_returns_403_for_another_users_order(): void
    {
        $this->enableRentalExtension();
        [$order, $item] = $this->paidOrder();
        $otherUser = User::factory()->create();

        $this->actingAs($otherUser)
            ->post($this->storeUrl($order, $item), ['new_end_date' => Carbon::today()->addDays(7)->toDateString()])
            ->assertForbidden();
    }

    public function test_check_returns_403_when_item_belongs_to_different_order(): void
    {
        $this->enableRentalExtension();
        [$order, $item] = $this->paidOrder();

        // Create another order and an item that belongs to it
        $otherOrder = Order::factory()->paid()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->customer->id,
        ]);
        $foreignItem = OrderItem::factory()->create([
            'order_id' => $otherOrder->id,
            'service_id' => $this->service->id,
            'end_date' => Carbon::today()->addDays(4),
        ]);

        // Use foreignItem with order (order_id mismatch → IDOR)
        $this->actingAs($this->customer)
            ->getJson($this->checkUrl($order, $foreignItem).'?new_end_date='.Carbon::today()->addDays(7)->toDateString())
            ->assertForbidden();
    }

    // =========================================================================
    // checkAvailability — validation
    // =========================================================================

    public function test_check_validates_new_end_date_required(): void
    {
        $this->enableRentalExtension();
        [$order, $item] = $this->paidOrder();

        $this->actingAs($this->customer)
            ->getJson($this->checkUrl($order, $item))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['new_end_date']);
    }

    public function test_check_validates_new_end_date_must_be_after_item_end_date(): void
    {
        $this->enableRentalExtension();
        [$order, $item] = $this->paidOrder();

        $this->actingAs($this->customer)
            ->getJson($this->checkUrl($order, $item).'?new_end_date='.$item->end_date->toDateString())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['new_end_date']);
    }

    // =========================================================================
    // checkAvailability — successful response
    // =========================================================================

    public function test_check_returns_json_with_required_fields(): void
    {
        $this->enableRentalExtension();
        [$order, $item] = $this->paidOrder();
        $newEndDate = $item->end_date->copy()->addDays(3)->toDateString();

        $this->actingAs($this->customer)
            ->getJson($this->checkUrl($order, $item).'?new_end_date='.$newEndDate)
            ->assertOk()
            ->assertJsonStructure(['available', 'additional_days', 'estimated_amount', 'can_extend']);
    }

    public function test_check_returns_correct_additional_days(): void
    {
        $this->enableRentalExtension();
        [$order, $item] = $this->paidOrder();
        $newEndDate = $item->end_date->copy()->addDays(5)->toDateString();

        $response = $this->actingAs($this->customer)
            ->getJson($this->checkUrl($order, $item).'?new_end_date='.$newEndDate)
            ->assertOk();

        $this->assertEquals(5, $response->json('additional_days'));
    }

    public function test_check_can_extend_is_false_when_service_unavailable(): void
    {
        $this->enableRentalExtension();
        [$order, $item] = $this->paidOrder();

        // Block all availability in the extension window
        $blockingOrder = Order::factory()->paid()->create(['organization_id' => $this->org->id]);
        OrderItem::factory()->create([
            'order_id' => $blockingOrder->id,
            'service_id' => $this->service->id,
            'quantity' => 3, // exhausts the 3 total
            'start_date' => $item->end_date->copy()->addDay(),
            'end_date' => $item->end_date->copy()->addDays(10),
        ]);

        $newEndDate = $item->end_date->copy()->addDays(5)->toDateString();

        $response = $this->actingAs($this->customer)
            ->getJson($this->checkUrl($order, $item).'?new_end_date='.$newEndDate)
            ->assertOk();

        $this->assertFalse($response->json('can_extend'));
        $this->assertEquals(0.0, $response->json('estimated_amount'));
    }

    // =========================================================================
    // store — order status gate
    // =========================================================================

    #[DataProvider('nonExtendableStatuses')]
    public function test_store_returns_422_for_non_extendable_order_status(string $status): void
    {
        $this->enableRentalExtension();

        $order = Order::factory()->create([
            'status' => $status,
            'organization_id' => $this->org->id,
            'user_id' => $this->customer->id,
        ]);

        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'service_id' => $this->service->id,
            'end_date' => Carbon::today()->addDays(4),
        ]);

        $this->actingAs($this->customer)
            ->post($this->storeUrl($order, $item), [
                'new_end_date' => Carbon::today()->addDays(8)->toDateString(),
            ])
            ->assertStatus(422);
    }

    public static function nonExtendableStatuses(): array
    {
        return [
            'completed' => ['completed'],
            'cancelled' => ['cancelled'],
            'pending_payment' => ['pending_payment'],
        ];
    }

    // =========================================================================
    // store — successful submission
    // =========================================================================

    public function test_store_creates_extension_request_and_redirects_to_order(): void
    {
        Notification::fake();

        $this->enableRentalExtension();
        [$order, $item] = $this->paidOrder();
        $newEndDate = $item->end_date->copy()->addDays(3)->toDateString();

        $this->actingAs($this->customer)
            ->post($this->storeUrl($order, $item), ['new_end_date' => $newEndDate])
            ->assertRedirect(route('orders.show', $order))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('order_item_extension_requests', [
            'order_item_id' => $item->id,
            'order_id' => $order->id,
            'status' => ExtensionRequestStatus::Pending->value,
            'additional_days' => 3,
        ]);
    }

    public function test_store_passes_customer_notes_to_extension_request(): void
    {
        Notification::fake();

        $this->enableRentalExtension();
        [$order, $item] = $this->paidOrder();

        $this->actingAs($this->customer)
            ->post($this->storeUrl($order, $item), [
                'new_end_date' => $item->end_date->copy()->addDays(2)->toDateString(),
                'customer_notes' => 'Potrzebuję trochę dłużej.',
            ])
            ->assertRedirect(route('orders.show', $order));

        $this->assertDatabaseHas('order_item_extension_requests', [
            'order_item_id' => $item->id,
            'customer_notes' => 'Potrzebuję trochę dłużej.',
        ]);
    }

    // =========================================================================
    // store — unavailable service
    // =========================================================================

    public function test_store_redirects_back_with_error_when_service_unavailable(): void
    {
        $this->enableRentalExtension();
        [$order, $item] = $this->paidOrder();

        // Block all availability in the extension window
        $blockingOrder = Order::factory()->paid()->create(['organization_id' => $this->org->id]);
        OrderItem::factory()->create([
            'order_id' => $blockingOrder->id,
            'service_id' => $this->service->id,
            'quantity' => 3,
            'start_date' => $item->end_date->copy()->addDay(),
            'end_date' => $item->end_date->copy()->addDays(10),
        ]);

        $this->actingAs($this->customer)
            ->post($this->storeUrl($order, $item), [
                'new_end_date' => $item->end_date->copy()->addDays(5)->toDateString(),
            ])
            ->assertRedirect()
            ->assertSessionHasErrors(['new_end_date']);

        $this->assertDatabaseMissing('order_item_extension_requests', [
            'order_item_id' => $item->id,
        ]);
    }

    // =========================================================================
    // store — duplicate pending request
    // =========================================================================

    public function test_store_redirects_back_with_error_when_pending_request_exists(): void
    {
        $this->enableRentalExtension();
        [$order, $item] = $this->paidOrder();

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

        $this->actingAs($this->customer)
            ->post($this->storeUrl($order, $item), [
                'new_end_date' => $item->end_date->copy()->addDays(5)->toDateString(),
            ])
            ->assertRedirect()
            ->assertSessionHasErrors(['new_end_date']);
    }

    // =========================================================================
    // store — validation
    // =========================================================================

    public function test_store_validates_new_end_date_required(): void
    {
        $this->enableRentalExtension();
        [$order, $item] = $this->paidOrder();

        $this->actingAs($this->customer)
            ->post($this->storeUrl($order, $item), [])
            ->assertSessionHasErrors(['new_end_date']);
    }

    public function test_store_validates_customer_notes_max_length(): void
    {
        $this->enableRentalExtension();
        [$order, $item] = $this->paidOrder();

        $this->actingAs($this->customer)
            ->post($this->storeUrl($order, $item), [
                'new_end_date' => $item->end_date->copy()->addDays(3)->toDateString(),
                'customer_notes' => str_repeat('a', 501),
            ])
            ->assertSessionHasErrors(['customer_notes']);
    }
}
