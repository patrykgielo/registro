<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\ServiceUnitStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceUnit;
use App\Services\Order\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Faza 3 kroki 3.6/3.7 — App\Services\Order\OrderService::handOver()/
 * completeReturn(), the single validation point behind all four Filament
 * call sites (see OrderResource.php / EditOrder.php's own comments on this
 * pair). Invariant A (kontrakt-dostepnosci.md Zasada 5): assigning a unit
 * NEVER changes ServiceUnit::status or ::location_id — several tests below
 * exist specifically to pin that.
 */
class OrderServiceUnitAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function makeService(): OrderService
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

    private function unitFor(Service $service, ServiceUnitStatus $status = ServiceUnitStatus::Available): ServiceUnit
    {
        return ServiceUnit::factory()->create([
            'organization_id' => $service->organization_id,
            'service_id' => $service->id,
            'status' => $status,
        ]);
    }

    private function itemFor(Order $order, Service $service, ?Carbon $start = null, ?Carbon $end = null): OrderItem
    {
        return OrderItem::factory()->create([
            'order_id' => $order->id,
            'service_id' => $service->id,
            'service_name' => $service->name,
            'start_date' => $start ?? Carbon::parse('2026-10-01'),
            'end_date' => $end ?? Carbon::parse('2026-10-05'),
        ]);
    }

    // -------------------------------------------------------------------------
    // handOver() — no regression when no assignment is made
    // -------------------------------------------------------------------------

    public function test_hand_over_without_any_assignment_transitions_normally(): void
    {
        ['org' => $org, 'service' => $service] = $this->orgWithRentalService();
        $order = Order::factory()->confirmed()->create(['organization_id' => $org->id]);
        $this->itemFor($order, $service);

        $result = $this->makeService()->handOver($order, []);

        $this->assertSame('in_progress', $result->fresh()->status);
    }

    public function test_hand_over_leaves_service_unit_id_null_when_item_omitted_from_assignments(): void
    {
        ['org' => $org, 'service' => $service] = $this->orgWithRentalService();
        $order = Order::factory()->confirmed()->create(['organization_id' => $org->id]);
        $item = $this->itemFor($order, $service);

        $this->makeService()->handOver($order, []);

        $this->assertNull($item->fresh()->service_unit_id);
    }

    // -------------------------------------------------------------------------
    // handOver() — assignment
    // -------------------------------------------------------------------------

    public function test_hand_over_assigns_the_unit_to_the_order_item(): void
    {
        ['org' => $org, 'service' => $service] = $this->orgWithRentalService();
        $order = Order::factory()->confirmed()->create(['organization_id' => $org->id]);
        $item = $this->itemFor($order, $service);
        $unit = $this->unitFor($service);

        $this->makeService()->handOver($order, [$item->id => $unit->id]);

        $this->assertSame($unit->id, $item->fresh()->service_unit_id);
    }

    /**
     * Review point #3: order_items.service_unit_id is nullOnDelete, so the
     * FK alone can't reconstruct "which number was handed out" once the
     * ServiceUnit row is gone (a real need — krok 3.8 puts the number on a
     * document the customer signs). service_unit_identifier_snapshot is the
     * point-in-time copy, same pattern as service_name/price_snapshot.
     *
     * Falsifiability: deleting the snapshot write from handOver()'s
     * $item->update() call (keeping only service_unit_id) makes this test
     * fail — the assertion sees NULL instead of 'KOP-04'.
     */
    public function test_hand_over_snapshots_the_units_identifier_onto_the_order_item(): void
    {
        ['org' => $org, 'service' => $service] = $this->orgWithRentalService();
        $order = Order::factory()->confirmed()->create(['organization_id' => $org->id]);
        $item = $this->itemFor($order, $service);
        $unit = ServiceUnit::factory()->create([
            'organization_id' => $org->id,
            'service_id' => $service->id,
            'status' => ServiceUnitStatus::Available,
            'identifier' => 'KOP-04',
        ]);

        $this->makeService()->handOver($order, [$item->id => $unit->id]);

        $this->assertSame('KOP-04', $item->fresh()->service_unit_identifier_snapshot);
    }

    /**
     * Snapshot survives the ServiceUnit row itself being deleted — this IS
     * the point of the column (nullOnDelete on service_unit_id means the FK
     * alone loses the number the moment the unit is removed).
     */
    public function test_snapshot_survives_deletion_of_the_service_unit(): void
    {
        ['org' => $org, 'service' => $service] = $this->orgWithRentalService();
        $order = Order::factory()->confirmed()->create(['organization_id' => $org->id]);
        $item = $this->itemFor($order, $service);
        $unit = ServiceUnit::factory()->create([
            'organization_id' => $org->id,
            'service_id' => $service->id,
            'status' => ServiceUnitStatus::Available,
            'identifier' => 'KOP-04',
        ]);
        $this->makeService()->handOver($order, [$item->id => $unit->id]);

        $unit->delete();

        $item->refresh();
        $this->assertNull($item->service_unit_id);
        $this->assertSame('KOP-04', $item->service_unit_identifier_snapshot);
    }

    public function test_complete_return_updates_the_snapshot_on_a_confirmed_mismatch(): void
    {
        ['org' => $org, 'service' => $service] = $this->orgWithRentalService();
        $order = Order::factory()->confirmed()->create(['organization_id' => $org->id]);
        $item = $this->itemFor($order, $service);
        $handedOutUnit = ServiceUnit::factory()->create([
            'organization_id' => $org->id,
            'service_id' => $service->id,
            'status' => ServiceUnitStatus::Available,
            'identifier' => 'KOP-01',
        ]);
        $returnedUnit = ServiceUnit::factory()->create([
            'organization_id' => $org->id,
            'service_id' => $service->id,
            'status' => ServiceUnitStatus::Available,
            'identifier' => 'KOP-02',
        ]);
        $this->makeService()->handOver($order, [$item->id => $handedOutUnit->id]);
        $order->refresh();
        $this->assertSame('KOP-01', $item->fresh()->service_unit_identifier_snapshot);

        $this->makeService()->completeReturn($order, [$item->id => $returnedUnit->id], mismatchConfirmed: true);

        $this->assertSame('KOP-02', $item->fresh()->service_unit_identifier_snapshot);
    }

    // -------------------------------------------------------------------------
    // handOver() — inline identifier assignment (plan-wdrozenia.md krok 3.6:
    // "Jeśli wybrana sztuka nie ma jeszcze numeru — pracownik wpisuje go na
    // miejscu")
    // -------------------------------------------------------------------------

    public function test_hand_over_assigns_the_submitted_identifier_to_a_unit_that_has_none(): void
    {
        ['org' => $org, 'service' => $service] = $this->orgWithRentalService();
        $order = Order::factory()->confirmed()->create(['organization_id' => $org->id]);
        $item = $this->itemFor($order, $service);
        $unit = $this->unitFor($service);
        $this->assertNull($unit->identifier);

        $this->makeService()->handOver($order, [$item->id => $unit->id], [$item->id => 'KOP-04']);

        $this->assertSame('KOP-04', $unit->fresh()->identifier);
    }

    /**
     * Falsifiability: with the `$unit->identifier !== null` guard removed
     * from assignIdentifierIfMissing(), this test fails — the pre-existing
     * 'KOP-01' would be overwritten with 'SOMETHING-ELSE'.
     */
    public function test_hand_over_does_not_overwrite_an_existing_identifier(): void
    {
        ['org' => $org, 'service' => $service] = $this->orgWithRentalService();
        $order = Order::factory()->confirmed()->create(['organization_id' => $org->id]);
        $item = $this->itemFor($order, $service);
        $unit = ServiceUnit::factory()->create([
            'organization_id' => $org->id,
            'service_id' => $service->id,
            'status' => ServiceUnitStatus::Available,
            'identifier' => 'KOP-01',
        ]);

        $this->makeService()->handOver($order, [$item->id => $unit->id], [$item->id => 'SOMETHING-ELSE']);

        $this->assertSame('KOP-01', $unit->fresh()->identifier);
    }

    public function test_hand_over_rejects_a_submitted_identifier_already_used_by_another_unit(): void
    {
        ['org' => $org, 'service' => $service] = $this->orgWithRentalService();
        $order = Order::factory()->confirmed()->create(['organization_id' => $org->id]);
        $item = $this->itemFor($order, $service);
        ServiceUnit::factory()->create([
            'organization_id' => $org->id,
            'service_id' => $service->id,
            'identifier' => 'KOP-04',
        ]);
        $unassignedUnit = $this->unitFor($service);

        $this->expectException(\InvalidArgumentException::class);

        $this->makeService()->handOver($order, [$item->id => $unassignedUnit->id], [$item->id => 'KOP-04']);
    }

    public function test_hand_over_ignores_a_blank_identifier(): void
    {
        ['org' => $org, 'service' => $service] = $this->orgWithRentalService();
        $order = Order::factory()->confirmed()->create(['organization_id' => $org->id]);
        $item = $this->itemFor($order, $service);
        $unit = $this->unitFor($service);

        $result = $this->makeService()->handOver($order, [$item->id => $unit->id], [$item->id => '   ']);

        $this->assertSame('in_progress', $result->fresh()->status);
        $this->assertNull($unit->fresh()->identifier);
    }

    /**
     * Invariant A. Falsifiability note: verified manually by temporarily
     * adding `$item->update(['... ' ])`-style status mutation into
     * OrderService::handOver() and observing this assert fail, then
     * reverting — see the task report for the exact mutation used.
     */
    public function test_hand_over_does_not_change_the_units_status_or_location(): void
    {
        ['org' => $org, 'service' => $service] = $this->orgWithRentalService();
        $order = Order::factory()->confirmed()->create(['organization_id' => $org->id]);
        $item = $this->itemFor($order, $service);
        $unit = $this->unitFor($service);
        $originalLocationId = $unit->location_id;

        $this->makeService()->handOver($order, [$item->id => $unit->id]);

        $unit->refresh();
        $this->assertSame(ServiceUnitStatus::Available, $unit->status);
        $this->assertSame($originalLocationId, $unit->location_id);
    }

    public function test_hand_over_rejects_a_unit_of_a_different_service(): void
    {
        ['org' => $org, 'service' => $service] = $this->orgWithRentalService();
        $otherService = Service::factory()->itemRental()->create(['organization_id' => $org->id]);
        $order = Order::factory()->confirmed()->create(['organization_id' => $org->id]);
        $item = $this->itemFor($order, $service);
        $unitOfOtherService = $this->unitFor($otherService);

        $this->expectException(\InvalidArgumentException::class);

        $this->makeService()->handOver($order, [$item->id => $unitOfOtherService->id]);
    }

    public function test_hand_over_rejects_a_unit_of_a_different_tenant(): void
    {
        ['org' => $org, 'service' => $service] = $this->orgWithRentalService();
        $otherOrg = Organization::factory()->equipmentRental()->create();
        $order = Order::factory()->confirmed()->create(['organization_id' => $org->id]);
        $item = $this->itemFor($order, $service);

        // A cross-tenant service sharing the same id-space is the crafted-input
        // scenario resolveUnitForItem() defends against — the unit's service_id
        // never matches $item->service_id in the first place because it belongs
        // to an entirely different service, but organization_id is checked
        // independently as documented on OrderService::resolveUnitForItem().
        $otherOrgService = Service::factory()->itemRental()->create(['organization_id' => $otherOrg->id]);
        $foreignUnit = $this->unitFor($otherOrgService);

        $this->expectException(\InvalidArgumentException::class);

        $this->makeService()->handOver($order, [$item->id => $foreignUnit->id]);
    }

    public function test_hand_over_rejects_a_unit_in_maintenance(): void
    {
        ['org' => $org, 'service' => $service] = $this->orgWithRentalService();
        $order = Order::factory()->confirmed()->create(['organization_id' => $org->id]);
        $item = $this->itemFor($order, $service);
        $unit = $this->unitFor($service, ServiceUnitStatus::Maintenance);

        $this->expectException(\InvalidArgumentException::class);

        $this->makeService()->handOver($order, [$item->id => $unit->id]);
    }

    /**
     * Falsifiability: with OrderItem::scopeAssignedToUnitOverlapping()'s
     * status filter widened to also match 'completed' orders (i.e. the guard
     * this test exists to pin removed), this test fails because the second
     * handOver() call no longer throws.
     */
    public function test_hand_over_rejects_a_unit_already_handed_out_for_an_overlapping_order(): void
    {
        ['org' => $org, 'service' => $service] = $this->orgWithRentalService();
        $unit = $this->unitFor($service);

        $firstOrder = Order::factory()->confirmed()->create(['organization_id' => $org->id]);
        $firstItem = $this->itemFor($firstOrder, $service, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-10'));
        $this->makeService()->handOver($firstOrder, [$firstItem->id => $unit->id]);

        $secondOrder = Order::factory()->confirmed()->create(['organization_id' => $org->id]);
        $secondItem = $this->itemFor($secondOrder, $service, Carbon::parse('2026-10-05'), Carbon::parse('2026-10-08'));

        $this->expectException(\InvalidArgumentException::class);

        $this->makeService()->handOver($secondOrder, [$secondItem->id => $unit->id]);
    }

    public function test_hand_over_allows_the_same_unit_for_non_overlapping_dates(): void
    {
        ['org' => $org, 'service' => $service] = $this->orgWithRentalService();
        $unit = $this->unitFor($service);

        $firstOrder = Order::factory()->confirmed()->create(['organization_id' => $org->id]);
        $firstItem = $this->itemFor($firstOrder, $service, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-05'));
        $this->makeService()->handOver($firstOrder, [$firstItem->id => $unit->id]);

        $secondOrder = Order::factory()->confirmed()->create(['organization_id' => $org->id]);
        $secondItem = $this->itemFor($secondOrder, $service, Carbon::parse('2026-10-10'), Carbon::parse('2026-10-15'));

        $result = $this->makeService()->handOver($secondOrder, [$secondItem->id => $unit->id]);

        $this->assertSame('in_progress', $result->fresh()->status);
        $this->assertSame($unit->id, $secondItem->fresh()->service_unit_id);
    }

    /**
     * CRITICAL — code review 2026-09-08 (ClickUp / team-lead review): a unit
     * handed out and then released by cancelling its 'in_progress' order
     * (OrderService::cancel() explicitly allows this — see its own docblock)
     * used to look free, because the old status filter ['confirmed',
     * 'in_progress'] excluded 'cancelled'. Two customers could receive the
     * SAME physical unit with two signed protocols. Fixed by
     * scopeAssignedToUnitOverlapping() blocking every status except
     * 'completed'/'refunded' — presence of a non-null service_unit_id on a
     * non-completed order IS the fact that the unit is still out, regardless
     * of why the order stopped moving forward.
     *
     * Falsifiability: reverting scopeAssignedToUnitOverlapping() to
     * `whereIn('orders.status', ['confirmed', 'in_progress'])` makes this
     * test fail — the second handOver() call no longer throws (verified
     * manually, see task report).
     */
    public function test_hand_over_rejects_a_unit_whose_order_was_cancelled_after_handover(): void
    {
        ['org' => $org, 'service' => $service] = $this->orgWithRentalService();
        $unit = $this->unitFor($service);

        $firstOrder = Order::factory()->confirmed()->create(['organization_id' => $org->id]);
        $firstItem = $this->itemFor($firstOrder, $service, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-10'));
        $this->makeService()->handOver($firstOrder, [$firstItem->id => $unit->id]);
        $firstOrder->refresh();

        app(\App\Services\Order\OrderService::class)->cancel($firstOrder, 'Offboarding: tenant closing');
        $this->assertSame('cancelled', $firstOrder->fresh()->status);
        $this->assertSame($unit->id, $firstItem->fresh()->service_unit_id);

        $secondOrder = Order::factory()->confirmed()->create(['organization_id' => $org->id]);
        $secondItem = $this->itemFor($secondOrder, $service, Carbon::parse('2026-10-05'), Carbon::parse('2026-10-08'));

        $this->expectException(\InvalidArgumentException::class);

        $this->makeService()->handOver($secondOrder, [$secondItem->id => $unit->id]);
    }

    /**
     * A cancelled order that was NEVER handed out (service_unit_id still
     * null) must not block anything — only presence of an assignment does.
     */
    public function test_hand_over_allows_a_unit_whose_order_was_cancelled_before_any_handover(): void
    {
        ['org' => $org, 'service' => $service] = $this->orgWithRentalService();
        $unit = $this->unitFor($service);

        $cancelledOrder = Order::factory()->confirmed()->create(['organization_id' => $org->id]);
        $this->itemFor($cancelledOrder, $service, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-10'));
        app(\App\Services\Order\OrderService::class)->cancel($cancelledOrder, 'Customer request');

        $newOrder = Order::factory()->confirmed()->create(['organization_id' => $org->id]);
        $newItem = $this->itemFor($newOrder, $service, Carbon::parse('2026-10-05'), Carbon::parse('2026-10-08'));

        $result = $this->makeService()->handOver($newOrder, [$newItem->id => $unit->id]);

        $this->assertSame('in_progress', $result->fresh()->status);
    }

    /**
     * Point #4 of the review: 'completed' (return) must still genuinely
     * release the unit — this is the guard tightened by point #1 above, so
     * it needs its own test to prove the tightening didn't over-block.
     */
    public function test_completed_return_releases_the_unit_for_an_identical_overlapping_window(): void
    {
        ['org' => $org, 'service' => $service] = $this->orgWithRentalService();
        $unit = $this->unitFor($service);

        $firstOrder = Order::factory()->confirmed()->create(['organization_id' => $org->id]);
        $firstItem = $this->itemFor($firstOrder, $service, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-10'));
        $this->makeService()->handOver($firstOrder, [$firstItem->id => $unit->id]);
        $firstOrder->refresh();
        $this->makeService()->completeReturn($firstOrder, [$firstItem->id => $unit->id]);

        $secondOrder = Order::factory()->confirmed()->create(['organization_id' => $org->id]);
        $secondItem = $this->itemFor($secondOrder, $service, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-10'));

        $result = $this->makeService()->handOver($secondOrder, [$secondItem->id => $unit->id]);

        $this->assertSame('in_progress', $result->fresh()->status);
        $this->assertSame($unit->id, $secondItem->fresh()->service_unit_id);
    }

    /**
     * Two order items in the SAME batch claiming the same unit for
     * overlapping dates — neither is visible to the other's DB check because
     * nothing has been committed yet, so this must be caught by the
     * in-memory per-batch tracking, not the database query alone.
     */
    public function test_hand_over_rejects_the_same_unit_claimed_twice_in_the_same_batch(): void
    {
        ['org' => $org, 'service' => $service] = $this->orgWithRentalService();
        $order = Order::factory()->confirmed()->create(['organization_id' => $org->id]);
        $itemA = $this->itemFor($order, $service, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-05'));
        $itemB = $this->itemFor($order, $service, Carbon::parse('2026-10-03'), Carbon::parse('2026-10-07'));
        $unit = $this->unitFor($service);

        $this->expectException(\InvalidArgumentException::class);

        $this->makeService()->handOver($order, [
            $itemA->id => $unit->id,
            $itemB->id => $unit->id,
        ]);
    }

    public function test_hand_over_throws_when_order_is_not_confirmed(): void
    {
        ['org' => $org, 'service' => $service] = $this->orgWithRentalService();
        $order = Order::factory()->paid()->create(['organization_id' => $org->id]);
        $item = $this->itemFor($order, $service);

        $this->expectException(\LogicException::class);

        $this->makeService()->handOver($order, [$item->id => null]);
    }

    public function test_hand_over_does_not_persist_anything_when_it_throws(): void
    {
        ['org' => $org, 'service' => $service] = $this->orgWithRentalService();
        $order = Order::factory()->confirmed()->create(['organization_id' => $org->id]);
        $item = $this->itemFor($order, $service);
        $unit = $this->unitFor($service, ServiceUnitStatus::Maintenance);

        try {
            $this->makeService()->handOver($order, [$item->id => $unit->id]);
            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('confirmed', $order->fresh()->status);
            $this->assertNull($item->fresh()->service_unit_id);
        }
    }

    // -------------------------------------------------------------------------
    // completeReturn() — no regression when the selection is left untouched
    // -------------------------------------------------------------------------

    public function test_complete_return_with_no_assignment_at_all_transitions_normally(): void
    {
        ['org' => $org, 'service' => $service] = $this->orgWithRentalService();
        $order = Order::factory()->inProgress()->create(['organization_id' => $org->id]);
        $this->itemFor($order, $service);

        $result = $this->makeService()->completeReturn($order, []);

        $this->assertSame('completed', $result->fresh()->status);
    }

    public function test_complete_return_defaulting_to_the_handed_out_unit_transitions_without_confirmation(): void
    {
        ['org' => $org, 'service' => $service] = $this->orgWithRentalService();
        $order = Order::factory()->confirmed()->create(['organization_id' => $org->id]);
        $item = $this->itemFor($order, $service);
        $unit = $this->unitFor($service);
        $this->makeService()->handOver($order, [$item->id => $unit->id]);
        $order->refresh();

        // Mirrors the Filament form's own default: staff submits the SAME value.
        $result = $this->makeService()->completeReturn($order, [$item->id => $unit->id]);

        $this->assertSame('completed', $result->fresh()->status);
        $this->assertSame($unit->id, $item->fresh()->service_unit_id);
    }

    // -------------------------------------------------------------------------
    // completeReturn() — mismatch guard: possible, never accidental
    // -------------------------------------------------------------------------

    public function test_complete_return_with_an_unconfirmed_mismatch_throws_and_does_not_transition(): void
    {
        ['org' => $org, 'service' => $service] = $this->orgWithRentalService();
        $order = Order::factory()->confirmed()->create(['organization_id' => $org->id]);
        $item = $this->itemFor($order, $service);
        $handedOutUnit = $this->unitFor($service);
        $returnedUnit = $this->unitFor($service);
        $this->makeService()->handOver($order, [$item->id => $handedOutUnit->id]);
        $order->refresh();

        try {
            $this->makeService()->completeReturn($order, [$item->id => $returnedUnit->id]);
            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('in_progress', $order->fresh()->status);
            $this->assertSame($handedOutUnit->id, $item->fresh()->service_unit_id);
        }
    }

    /**
     * Falsifiability: with $mismatchConfirmed's check removed from
     * completeReturn() (always allow), this test's sibling above
     * (unconfirmed mismatch) fails to throw — proving the guard exists.
     */
    public function test_complete_return_with_a_confirmed_mismatch_updates_the_assignment_and_transitions(): void
    {
        ['org' => $org, 'service' => $service] = $this->orgWithRentalService();
        $order = Order::factory()->confirmed()->create(['organization_id' => $org->id]);
        $item = $this->itemFor($order, $service);
        $handedOutUnit = $this->unitFor($service);
        $returnedUnit = $this->unitFor($service);
        $this->makeService()->handOver($order, [$item->id => $handedOutUnit->id]);
        $order->refresh();

        $result = $this->makeService()->completeReturn($order, [$item->id => $returnedUnit->id], mismatchConfirmed: true);

        $this->assertSame('completed', $result->fresh()->status);
        $this->assertSame($returnedUnit->id, $item->fresh()->service_unit_id);
    }

    /**
     * Invariant A applies just as much on return as on handover.
     */
    public function test_complete_return_does_not_change_the_units_status_or_location(): void
    {
        ['org' => $org, 'service' => $service] = $this->orgWithRentalService();
        $order = Order::factory()->confirmed()->create(['organization_id' => $org->id]);
        $item = $this->itemFor($order, $service);
        $unit = $this->unitFor($service);
        $this->makeService()->handOver($order, [$item->id => $unit->id]);
        $order->refresh();
        $originalLocationId = $unit->fresh()->location_id;

        $this->makeService()->completeReturn($order, [$item->id => $unit->id]);

        $unit->refresh();
        $this->assertSame(ServiceUnitStatus::Available, $unit->status);
        $this->assertSame($originalLocationId, $unit->location_id);
    }

    public function test_complete_return_throws_when_order_is_not_in_progress(): void
    {
        ['org' => $org, 'service' => $service] = $this->orgWithRentalService();
        $order = Order::factory()->confirmed()->create(['organization_id' => $org->id]);
        $item = $this->itemFor($order, $service);

        $this->expectException(\LogicException::class);

        $this->makeService()->completeReturn($order, [$item->id => null]);
    }

    public function test_complete_return_rejects_a_returned_unit_of_a_different_service(): void
    {
        ['org' => $org, 'service' => $service] = $this->orgWithRentalService();
        $otherService = Service::factory()->itemRental()->create(['organization_id' => $org->id]);
        $order = Order::factory()->inProgress()->create(['organization_id' => $org->id]);
        $item = $this->itemFor($order, $service);
        $unitOfOtherService = $this->unitFor($otherService);

        $this->expectException(\InvalidArgumentException::class);

        $this->makeService()->completeReturn($order, [$item->id => $unitOfOtherService->id]);
    }

    // -------------------------------------------------------------------------
    // completeReturn() — ClickUp 123k99cu2b4 acceptance criteria: the mismatch
    // fact (both unit numbers, who confirmed) lands in state_histories, not a
    // new column. responsible_id/responsible_type are stamped automatically
    // by the state machine library on every transitionTo() call — see
    // StateMachine::transitionTo() — this only proves OUR custom_properties
    // payload is actually there alongside it.
    // -------------------------------------------------------------------------

    public function test_complete_return_records_the_mismatch_fact_and_the_responsible_user_in_state_history(): void
    {
        ['org' => $org, 'service' => $service] = $this->orgWithRentalService();
        $staff = \App\Models\User::factory()->create();
        $this->actingAs($staff);

        $order = Order::factory()->confirmed()->create(['organization_id' => $org->id]);
        $item = $this->itemFor($order, $service);
        $handedOutUnit = ServiceUnit::factory()->create([
            'organization_id' => $org->id,
            'service_id' => $service->id,
            'status' => ServiceUnitStatus::Available,
            'identifier' => 'KOP-01',
        ]);
        $returnedUnit = ServiceUnit::factory()->create([
            'organization_id' => $org->id,
            'service_id' => $service->id,
            'status' => ServiceUnitStatus::Available,
            'identifier' => 'KOP-02',
        ]);
        $this->makeService()->handOver($order, [$item->id => $handedOutUnit->id]);
        $order->refresh();

        $this->makeService()->completeReturn($order, [$item->id => $returnedUnit->id], mismatchConfirmed: true);

        $history = $order->stateHistory()
            ->where('field', 'status')
            ->where('to', 'completed')
            ->latest('id')
            ->first();

        $this->assertNotNull($history);
        $this->assertSame($staff->id, $history->responsible_id);
        $this->assertSame(\App\Models\User::class, $history->responsible_type);
        $this->assertTrue($history->custom_properties['mismatch_confirmed']);
        $this->assertCount(1, $history->custom_properties['mismatches']);
        $this->assertSame('KOP-01', $history->custom_properties['mismatches'][0]['handed_out_label']);
        $this->assertSame('KOP-02', $history->custom_properties['mismatches'][0]['returned_label']);
    }

    public function test_complete_return_with_no_mismatch_records_an_empty_mismatch_list(): void
    {
        ['org' => $org, 'service' => $service] = $this->orgWithRentalService();
        $order = Order::factory()->confirmed()->create(['organization_id' => $org->id]);
        $item = $this->itemFor($order, $service);
        $unit = $this->unitFor($service);
        $this->makeService()->handOver($order, [$item->id => $unit->id]);
        $order->refresh();

        $this->makeService()->completeReturn($order, [$item->id => $unit->id]);

        $history = $order->stateHistory()->where('field', 'status')->where('to', 'completed')->latest('id')->first();

        $this->assertFalse($history->custom_properties['mismatch_confirmed']);
        $this->assertSame([], $history->custom_properties['mismatches']);
    }

    // -------------------------------------------------------------------------
    // completeReturn() — inline identifier at return (ClickUp 123k99cu2b4's
    // own open edge case: a unit handed out with no number has nothing to
    // compare — ask for one at return, but never require it)
    // -------------------------------------------------------------------------

    public function test_complete_return_assigns_the_submitted_identifier_to_a_returned_unit_that_has_none(): void
    {
        ['org' => $org, 'service' => $service] = $this->orgWithRentalService();
        $order = Order::factory()->confirmed()->create(['organization_id' => $org->id]);
        $item = $this->itemFor($order, $service);
        $unit = $this->unitFor($service); // no identifier — handed out unnumbered
        $this->makeService()->handOver($order, [$item->id => $unit->id]);
        $order->refresh();
        $this->assertNull($unit->identifier);

        $this->makeService()->completeReturn($order, [$item->id => $unit->id], unitIdentifiers: [$item->id => 'KOP-09']);

        $this->assertSame('KOP-09', $unit->fresh()->identifier);
    }

    public function test_complete_return_succeeds_without_an_identifier_when_the_returned_unit_has_none(): void
    {
        ['org' => $org, 'service' => $service] = $this->orgWithRentalService();
        $order = Order::factory()->confirmed()->create(['organization_id' => $org->id]);
        $item = $this->itemFor($order, $service);
        $unit = $this->unitFor($service);
        $this->makeService()->handOver($order, [$item->id => $unit->id]);
        $order->refresh();

        $result = $this->makeService()->completeReturn($order, [$item->id => $unit->id]);

        $this->assertSame('completed', $result->fresh()->status);
        $this->assertNull($unit->fresh()->identifier);
    }

    /**
     * Point #6 of the review: the identifier-collision guard exists in only
     * ONE place (assignIdentifierIfMissing(), shared by handOver() and
     * completeReturn()) and was only pinned by a test on the handover side
     * (test_hand_over_rejects_a_submitted_identifier_already_used_by_another_unit)
     * — the return side was exercised manually by the reviewer but had no
     * standing test. Recorded here so a future refactor that splits the two
     * call sites "because one needs something slightly different" can't
     * silently drop validation on this half without a test going red.
     *
     * Falsifiability: temporarily removing the `$taken` check from
     * assignIdentifierIfMissing() (verified manually, see task report) made
     * this test fail — completeReturn() no longer threw and the pre-existing
     * 'KOP-04' unit was left untouched while `$unassignedUnit` silently
     * failed to update anyway (blocked by the real UNIQUE constraint, but as
     * a raw QueryException instead of this test's expected
     * InvalidArgumentException).
     */
    public function test_complete_return_rejects_a_submitted_identifier_already_used_by_another_unit(): void
    {
        ['org' => $org, 'service' => $service] = $this->orgWithRentalService();
        $order = Order::factory()->confirmed()->create(['organization_id' => $org->id]);
        $item = $this->itemFor($order, $service);
        $unassignedUnit = $this->unitFor($service); // handed out with no identifier
        $this->makeService()->handOver($order, [$item->id => $unassignedUnit->id]);
        $order->refresh();
        $this->assertNull($unassignedUnit->fresh()->identifier);

        ServiceUnit::factory()->create([
            'organization_id' => $org->id,
            'service_id' => $service->id,
            'identifier' => 'KOP-04',
        ]);

        $this->expectException(\InvalidArgumentException::class);

        $this->makeService()->completeReturn($order, [$item->id => $unassignedUnit->id], unitIdentifiers: [$item->id => 'KOP-04']);
    }
}
