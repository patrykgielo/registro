<?php

declare(strict_types=1);

namespace Tests\Feature\Rental;

use App\Enums\ExtensionRequestStatus;
use App\Enums\RentalStatus;
use App\Exceptions\RentalUnavailableException;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemExtensionRequest;
use App\Models\Organization;
use App\Models\Rental;
use App\Models\Service;
use App\Models\ServiceLocationStock;
use App\Models\User;
use App\Services\RentalExtensionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Faza 4 krok 4.5 (kontrakt-dostepnosci.md, "dziewiąte wywołanie") —
 * RentalExtensionService::checkAvailabilityForExtension() was the one
 * getAvailableQuantity() call site with no location parameter of its own.
 * The team lead's own framing of the bug this leaves open: "przedłużenie
 * wypożyczenia sprzedaje sprzęt z CUDZEGO oddziału, cicho" — an extension
 * could silently claim a unit sitting free in a DIFFERENT location than the
 * one the original item was actually booked against.
 *
 * Both tests below are constructed so the outcome only differs depending on
 * whether $locationId is actually forwarded, the same falsifiability shape
 * as RentalResourceLocationTest: quantity_total is set to a value that would
 * make the OLD ($locationId === null) branch answer the opposite of the
 * location-scoped answer.
 */
class RentalExtensionLocationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $customer;

    private User $admin;

    private Location $locationA;

    private Location $locationB;

    private Service $service;

    private RentalExtensionService $extensionService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->equipmentRental()->create();
        $this->customer = User::factory()->create();
        $this->admin = User::factory()->create();

        $this->service = Service::factory()->itemRental()->create([
            'organization_id' => $this->org->id,
            'price_per_day' => 100,
        ]);

        $this->locationA = Location::factory()->for($this->org, 'organization')->create();
        $this->locationB = Location::factory()->for($this->org, 'organization')->create();

        $this->extensionService = app(RentalExtensionService::class);

        app('request')->attributes->set('tenant', $this->org);
    }

    private function extendableItem(int $locationId, array $overrides = []): array
    {
        $order = Order::factory()->paid()->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->customer->id,
            'subtotal' => 300.00,
            'total_amount' => 300.00,
        ]);

        $item = OrderItem::factory()->create(array_merge([
            'order_id' => $order->id,
            'service_id' => $this->service->id,
            'location_id' => $locationId,
            'quantity' => 1,
            'start_date' => Carbon::today()->subDays(5),
            'end_date' => Carbon::today()->addDays(2),
            'rental_days' => 8,
            'unit_price' => 100.00,
            'total_price' => 800.00,
        ], $overrides));

        return [$order, $item];
    }

    public function test_checking_extension_availability_reads_capacity_at_the_items_own_location_not_the_global_total(): void
    {
        // Global total says "nothing left" — the location-scoped branch must
        // ignore it and see location A's own, real, free unit instead.
        $this->service->update(['quantity_total' => 0]);

        ServiceLocationStock::where('service_id', $this->service->id)
            ->where('location_id', $this->locationA->id)
            ->update(['quantity' => 1]);

        [, $item] = $this->extendableItem($this->locationA->id);

        $requestedEnd = $item->end_date->copy()->addDays(3);

        $available = $this->extensionService->checkAvailabilityForExtension($item, $requestedEnd, forUpdate: false, locationId: $item->location_id);

        $this->assertSame(1, $available);
    }

    /**
     * The central regression this step exists to close: the item's location
     * (A) is fully booked for the extension window by ANOTHER reservation,
     * while a DIFFERENT location (B) sitting on the SAME service has a free
     * unit. Falsifiable: dropping `locationId: $item->location_id` from
     * either call site in RentalExtensionService reverts to the null branch,
     * whose global reservedViaOrders sum only counts the ONE blocking
     * reservation at A against quantity_total=2 — giving 1 available and
     * WRONGLY letting the extension through onto capacity that is only
     * actually free at location B.
     */
    public function test_request_extension_cannot_reach_a_unit_sitting_free_in_a_different_location(): void
    {
        $this->service->update(['quantity_total' => 2]);

        ServiceLocationStock::where('service_id', $this->service->id)
            ->where('location_id', $this->locationA->id)
            ->update(['quantity' => 1]);
        ServiceLocationStock::where('service_id', $this->service->id)
            ->where('location_id', $this->locationB->id)
            ->update(['quantity' => 1]);

        [, $item] = $this->extendableItem($this->locationA->id);
        $requestedEnd = $item->end_date->copy()->addDays(3);
        $extensionStart = $item->end_date->copy()->addDay();

        // Blocks location A's only unit for the ENTIRE extension window —
        // location B is untouched and has its own free unit.
        Rental::factory()->create([
            'organization_id' => $this->org->id,
            'service_id' => $this->service->id,
            'location_id' => $this->locationA->id,
            'customer_id' => User::factory()->create()->id,
            'quantity' => 1,
            'start_date' => $extensionStart,
            'end_date' => $requestedEnd,
            'status' => RentalStatus::Confirmed,
        ]);

        $available = $this->extensionService->checkAvailabilityForExtension($item, $requestedEnd, forUpdate: false, locationId: $item->location_id);
        $this->assertSame(0, $available, 'Location A is fully booked for the extension window — must read 0, not fall through to location B\'s free unit.');

        Notification::fake();

        $this->expectException(RentalUnavailableException::class);
        $this->extensionService->requestExtension($item, $this->customer, $requestedEnd, null);
    }

    /**
     * Same regression, on the approve() path — a pending request must not be
     * approvable onto capacity that is only free in the WRONG location.
     */
    public function test_approve_cannot_reach_a_unit_sitting_free_in_a_different_location(): void
    {
        $this->service->update(['quantity_total' => 2]);

        ServiceLocationStock::where('service_id', $this->service->id)
            ->where('location_id', $this->locationA->id)
            ->update(['quantity' => 1]);
        ServiceLocationStock::where('service_id', $this->service->id)
            ->where('location_id', $this->locationB->id)
            ->update(['quantity' => 1]);

        [, $item] = $this->extendableItem($this->locationA->id);
        $requestedEnd = $item->end_date->copy()->addDays(3);
        $extensionStart = $item->end_date->copy()->addDay();

        $extensionRequest = OrderItemExtensionRequest::create([
            'organization_id' => $this->org->id,
            'order_id' => $item->order_id,
            'order_item_id' => $item->id,
            'requested_by_user_id' => $this->customer->id,
            'status' => ExtensionRequestStatus::Pending,
            'original_end_date' => $item->end_date,
            'requested_end_date' => $requestedEnd,
            'additional_days' => 3,
            'additional_amount' => 300.00,
        ]);

        // Location A becomes fully booked for the window AFTER the request
        // was submitted (approve() re-validates).
        Rental::factory()->create([
            'organization_id' => $this->org->id,
            'service_id' => $this->service->id,
            'location_id' => $this->locationA->id,
            'customer_id' => User::factory()->create()->id,
            'quantity' => 1,
            'start_date' => $extensionStart,
            'end_date' => $requestedEnd,
            'status' => RentalStatus::Confirmed,
        ]);

        Notification::fake();

        $this->expectException(RentalUnavailableException::class);
        $this->extensionService->approve($extensionRequest, $this->admin);
    }
}
