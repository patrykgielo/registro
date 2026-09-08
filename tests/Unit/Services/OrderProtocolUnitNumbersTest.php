<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\ServiceUnitStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceUnit;
use App\Services\Order\OrderProtocolPdfService;
use App\Services\Order\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * Faza 3 krok 3.8 (ClickUp 123k99cu2b5) — the physical unit number on the
 * handover/return protocol PDFs. Source is
 * `order_items.service_unit_identifier_snapshot`, never the `serviceUnit`
 * relation (nullOnDelete — see that column's own migration docblock and
 * OrderService::handOver()'s "same call as service_unit_id" comment).
 */
class OrderProtocolUnitNumbersTest extends TestCase
{
    use RefreshDatabase;

    private function protocolService(): OrderProtocolPdfService
    {
        return app(OrderProtocolPdfService::class);
    }

    private function orderService(): OrderService
    {
        return app(OrderService::class);
    }

    /**
     * @return array{org: Organization, service: Service}
     */
    private function orgWithRentalService(): array
    {
        $org = Organization::factory()->equipmentRental()->create();
        $service = Service::factory()->itemRental()->create(['organization_id' => $org->id]);

        return ['org' => $org, 'service' => $service];
    }

    private function unitFor(Service $service, ?string $identifier): ServiceUnit
    {
        return ServiceUnit::factory()->create([
            'organization_id' => $service->organization_id,
            'service_id' => $service->id,
            'status' => ServiceUnitStatus::Available,
            'identifier' => $identifier,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function handoverViewData(Order $order): array
    {
        $order->load(['items', 'organization']);

        return [
            'order' => $order,
            'org' => $order->organization,
            'pickup' => ['address' => '', 'phone' => '', 'email' => ''],
            'generatedAt' => now()->format('Y-m-d H:i'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function returnViewData(Order $order): array
    {
        return array_merge($this->handoverViewData($order), [
            'unitMismatches' => $this->unitMismatchesByItemId($order),
        ]);
    }

    /**
     * @return array<int, array{handed_out_label: string|null, returned_label: string|null}>
     */
    private function unitMismatchesByItemId(Order $order): array
    {
        $method = new \ReflectionMethod($this->protocolService(), 'unitMismatchesByItemId');
        $method->setAccessible(true);

        return $method->invoke($this->protocolService(), $order);
    }

    // -------------------------------------------------------------------------
    // Handover protocol — plain unit number
    // -------------------------------------------------------------------------

    public function test_handover_view_prints_the_unit_number_when_snapshot_is_present(): void
    {
        $order = Order::factory()->inProgress()->create();
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'service_name' => 'Betoniarka 150L',
            'quantity' => 1,
            'service_unit_identifier_snapshot' => 'KOP-04',
        ]);

        $html = View::make('orders.protocols.handover', $this->handoverViewData($order))->render();

        $this->assertStringContainsString('Nr egz.: KOP-04', $html);
    }

    public function test_handover_view_renders_cleanly_without_a_unit_number(): void
    {
        $order = Order::factory()->inProgress()->create();
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'service_name' => 'Betoniarka 150L',
            'quantity' => 1,
            'service_unit_identifier_snapshot' => null,
        ]);

        $html = View::make('orders.protocols.handover', $this->handoverViewData($order))->render();

        $this->assertStringContainsString('Betoniarka 150L', $html);
        $this->assertStringNotContainsString('Nr egz.', $html);
    }

    /**
     * The "quantity > 1" trap the ticket calls out explicitly: an order line
     * from before Faza 3 krok 2 ("rozwinięcie ilości") has quantity > 1, but
     * OrderService::handOver()/completeReturn() carry no guard preventing a
     * single service_unit_id assignment on such a line (verified by reading
     * both methods — no quantity check anywhere). A snapshot recorded there
     * names exactly ONE physical unit, not all N — printing it unqualified
     * would misrepresent what the signature covers, so it must be suppressed
     * entirely.
     *
     * Falsifiability: removing the `$item->quantity === 1` guard from
     * handover.blade.php makes this test fail (the raw identifier
     * 'KOP-04' would leak into the output).
     */
    public function test_handover_view_suppresses_the_unit_number_for_a_line_with_quantity_greater_than_one(): void
    {
        $order = Order::factory()->inProgress()->create();
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'service_name' => 'Betoniarka 150L',
            'quantity' => 3,
            'service_unit_identifier_snapshot' => 'KOP-04',
        ]);

        $html = View::make('orders.protocols.handover', $this->handoverViewData($order))->render();

        $this->assertStringContainsString('Betoniarka 150L', $html);
        $this->assertStringNotContainsString('Nr egz.', $html);
        $this->assertStringNotContainsString('KOP-04', $html);
    }

    /**
     * The entire reason the snapshot column exists (migration
     * 2026_09_08_110000's own docblock): service_unit_id is nullOnDelete, so
     * the FK/relation alone loses the number the instant the ServiceUnit row
     * is removed from inventory. The protocol — a document the customer
     * already signed — must not retroactively lose the number it printed.
     *
     * Falsifiability: reading `$item->serviceUnit?->identifier` instead of
     * `$item->service_unit_identifier_snapshot` in the Blade view makes this
     * test fail once the unit is deleted below.
     */
    public function test_handover_protocol_prints_the_snapshot_after_the_service_unit_is_deleted(): void
    {
        ['org' => $org, 'service' => $service] = $this->orgWithRentalService();
        $order = Order::factory()->confirmed()->create(['organization_id' => $org->id]);
        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'service_id' => $service->id,
            'service_name' => $service->name,
            'quantity' => 1,
            'start_date' => Carbon::parse('2026-10-01'),
            'end_date' => Carbon::parse('2026-10-05'),
        ]);
        $unit = $this->unitFor($service, 'KOP-04');

        $this->orderService()->handOver($order, [$item->id => $unit->id]);
        $order->refresh();

        $unit->delete();

        $response = $this->protocolService()->handoverProtocol($order);
        $this->assertSame(200, $response->getStatusCode());

        $html = View::make('orders.protocols.handover', $this->handoverViewData($order))->render();

        $this->assertStringContainsString('Nr egz.: KOP-04', $html);
        $this->assertNull($item->fresh()->service_unit_id);
    }

    // -------------------------------------------------------------------------
    // Return protocol — plain unit number (no mismatch)
    // -------------------------------------------------------------------------

    public function test_return_view_prints_the_unit_number_when_the_returned_unit_matches_the_handed_out_one(): void
    {
        ['org' => $org, 'service' => $service] = $this->orgWithRentalService();
        $order = Order::factory()->confirmed()->create(['organization_id' => $org->id]);
        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'service_id' => $service->id,
            'service_name' => $service->name,
            'quantity' => 1,
            'start_date' => Carbon::parse('2026-10-01'),
            'end_date' => Carbon::parse('2026-10-05'),
        ]);
        $unit = $this->unitFor($service, 'KOP-04');

        $this->orderService()->handOver($order, [$item->id => $unit->id]);
        $order->refresh();
        $this->orderService()->completeReturn($order, [$item->id => $unit->id]);
        $order->refresh();

        $html = View::make('orders.protocols.return', $this->returnViewData($order))->render();

        $this->assertStringContainsString('Nr egz.: KOP-04', $html);
        $this->assertStringNotContainsString('Wydano:', $html);
    }

    /**
     * Requirement #3 (ClickUp 123k99cu2b5): a CONFIRMED mismatch at return
     * overwrites `service_unit_identifier_snapshot` to describe what came
     * BACK (OrderService::completeReturn() — verified at
     * app/Services/Order/OrderService.php:413-419). Printing only that value
     * would silently erase the fact a different unit was originally handed
     * out. The return protocol must show both, sourced from the immutable
     * `state_histories.custom_properties.mismatches` row.
     */
    public function test_return_view_shows_both_units_on_a_confirmed_mismatch(): void
    {
        ['org' => $org, 'service' => $service] = $this->orgWithRentalService();
        $order = Order::factory()->confirmed()->create(['organization_id' => $org->id]);
        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'service_id' => $service->id,
            'service_name' => $service->name,
            'quantity' => 1,
            'start_date' => Carbon::parse('2026-10-01'),
            'end_date' => Carbon::parse('2026-10-05'),
        ]);
        $handedOutUnit = $this->unitFor($service, 'KOP-01');
        $returnedUnit = $this->unitFor($service, 'KOP-02');

        $this->orderService()->handOver($order, [$item->id => $handedOutUnit->id]);
        $order->refresh();
        $this->orderService()->completeReturn($order, [$item->id => $returnedUnit->id], mismatchConfirmed: true);
        $order->refresh();

        $this->assertSame('KOP-02', $item->fresh()->service_unit_identifier_snapshot);

        $response = $this->protocolService()->returnProtocol($order);
        $this->assertSame(200, $response->getStatusCode());

        $html = View::make('orders.protocols.return', $this->returnViewData($order))->render();

        $this->assertStringContainsString('Wydano: KOP-01', $html);
        $this->assertStringContainsString('Zwrócono: KOP-02', $html);
    }

    /**
     * Same "quantity > 1" trap as the handover side, pinned separately for
     * the return protocol because it goes through a second, independent
     * code path ($mismatch lookup) that could reintroduce the leak even if
     * the handover guard is correct.
     */
    public function test_return_view_suppresses_the_unit_number_for_a_line_with_quantity_greater_than_one(): void
    {
        ['org' => $org, 'service' => $service] = $this->orgWithRentalService();
        $order = Order::factory()->confirmed()->create(['organization_id' => $org->id]);
        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'service_id' => $service->id,
            'service_name' => $service->name,
            'quantity' => 4,
            'start_date' => Carbon::parse('2026-10-01'),
            'end_date' => Carbon::parse('2026-10-05'),
        ]);
        $unit = $this->unitFor($service, 'KOP-04');

        $this->orderService()->handOver($order, [$item->id => $unit->id]);
        $order->refresh();
        $this->orderService()->completeReturn($order, [$item->id => $unit->id]);
        $order->refresh();

        $html = View::make('orders.protocols.return', $this->returnViewData($order))->render();

        $this->assertStringNotContainsString('Nr egz.', $html);
        $this->assertStringNotContainsString('Wydano:', $html);
        $this->assertStringNotContainsString('KOP-04', $html);
    }

    /**
     * `unitMismatchesByItemId()` must not explode/misbehave for an order
     * whose 'completed' transition never went through the state machine at
     * all (no matching state_histories row) — see that method's own
     * docblock on backfilled/imported completed_at.
     */
    public function test_return_view_renders_with_no_unit_data_at_all(): void
    {
        $order = Order::factory()->completed()->create();
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'service_name' => 'Wiertarka udarowa',
            'quantity' => 1,
            'service_unit_id' => null,
            'service_unit_identifier_snapshot' => null,
        ]);

        $mismatches = $this->unitMismatchesByItemId($order);
        $this->assertSame([], $mismatches);

        $html = View::make('orders.protocols.return', $this->returnViewData($order))->render();

        $this->assertStringContainsString('Wiertarka udarowa', $html);
        $this->assertStringNotContainsString('Nr egz.', $html);
        $this->assertStringNotContainsString('Wydano:', $html);
    }
}
