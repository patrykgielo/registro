<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\ServiceUnitStatus;
use App\Filament\Resources\OrderResource\Pages\EditOrder;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceUnit;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Faza 3 kroki 3.6/3.7 — proves the FOUR Filament call sites (OrderResource
 * table row action `mark_in_progress`/`complete`, EditOrder header action
 * `mark_in_progress`/`complete`) all delegate to the same
 * App\Services\Order\OrderService methods and therefore behave identically —
 * the task write-up's explicit concern ("rozjazd... to jest dokładnie ten
 * rodzaj błędu, który wychodzi dopiero u klienta"). Domain-rule coverage
 * itself (overlap, tenant, maintenance, etc.) lives in
 * tests/Unit/Services/OrderServiceUnitAssignmentTest.php — this file only
 * pins that the UI wiring reaches that same code correctly, for both pages.
 */
class OrderServiceUnitAssignmentFilamentActionTest extends TestCase
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

    private function serviceAndUnit(): array
    {
        $service = Service::factory()->itemRental()->create(['organization_id' => $this->org->id]);
        $unit = ServiceUnit::factory()->create([
            'organization_id' => $this->org->id,
            'service_id' => $service->id,
            'status' => ServiceUnitStatus::Available,
        ]);

        return [$service, $unit];
    }

    private function itemFor(Order $order, Service $service): OrderItem
    {
        return OrderItem::factory()->create([
            'order_id' => $order->id,
            'service_id' => $service->id,
            'service_name' => $service->name,
            'start_date' => Carbon::parse('2026-11-01'),
            'end_date' => Carbon::parse('2026-11-05'),
        ]);
    }

    // -------------------------------------------------------------------------
    // mark_in_progress — EditOrder header action vs OrderResource table action
    // -------------------------------------------------------------------------

    public function test_edit_order_header_action_assigns_the_unit_and_hands_over(): void
    {
        Notification::fake();
        [$service, $unit] = $this->serviceAndUnit();
        $order = Order::factory()->confirmed()->create(['organization_id' => $this->org->id]);
        $item = $this->itemFor($order, $service);

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('mark_in_progress', ['unit_assignments' => [$item->id => $unit->id]])
            ->assertHasNoActionErrors();

        $this->assertSame('in_progress', $order->fresh()->status);
        $this->assertSame($unit->id, $item->fresh()->service_unit_id);
    }

    public function test_edit_order_header_action_assigns_an_inline_identifier_at_handover(): void
    {
        Notification::fake();
        [$service, $unit] = $this->serviceAndUnit();
        $this->assertNull($unit->identifier);
        $order = Order::factory()->confirmed()->create(['organization_id' => $this->org->id]);
        $item = $this->itemFor($order, $service);

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('mark_in_progress', [
                'unit_assignments' => [$item->id => $unit->id],
                'unit_identifiers' => [$item->id => 'KOP-04'],
            ])
            ->assertHasNoActionErrors();

        $this->assertSame('KOP-04', $unit->fresh()->identifier);
    }

    public function test_table_action_assigns_the_unit_and_hands_over(): void
    {
        Notification::fake();
        [$service, $unit] = $this->serviceAndUnit();
        $order = Order::factory()->confirmed()->create(['organization_id' => $this->org->id]);
        $item = $this->itemFor($order, $service);

        Livewire::test(ListOrders::class)
            ->callTableAction('mark_in_progress', $order, ['unit_assignments' => [$item->id => $unit->id]])
            ->assertHasNoTableActionErrors();

        $this->assertSame('in_progress', $order->fresh()->status);
        $this->assertSame($unit->id, $item->fresh()->service_unit_id);
    }

    public function test_both_mark_in_progress_call_sites_reject_the_same_invalid_unit_identically(): void
    {
        [$service] = $this->serviceAndUnit();
        $otherService = Service::factory()->itemRental()->create(['organization_id' => $this->org->id]);
        $unitOfOtherService = ServiceUnit::factory()->create([
            'organization_id' => $this->org->id,
            'service_id' => $otherService->id,
        ]);

        $orderA = Order::factory()->confirmed()->create(['organization_id' => $this->org->id]);
        $itemA = $this->itemFor($orderA, $service);

        $orderB = Order::factory()->confirmed()->create(['organization_id' => $this->org->id]);
        $itemB = $this->itemFor($orderB, $service);

        // EditOrder header action — rejected by Filament's OWN Select
        // validation before the action closure ever runs, NOT a caught
        // OrderService exception: `unit_assignments.{itemId}`'s options()
        // is scoped to the item's own service (ServiceUnitAssignmentForms::
        // optionsFor()), so a cross-service unit id is outside the option
        // list and Filament's single-value Select self-defends via
        // `Rule::in([])` (filament-resources.md, "Select pojedynczy broni
        // się sam") — confirmed empirically via assertHasActionErrors()
        // below, corrected after an earlier version of this comment
        // wrongly attributed the rejection to OrderService::
        // resolveUnitForItem()'s InvalidArgumentException (code review,
        // 2026-09-08). That service-layer check still exists and is still
        // exercised — see OrderServiceUnitAssignmentTest's own
        // "rejects a unit of a different service" case, which calls
        // handOver() directly and bypasses the Select entirely.
        Livewire::test(EditOrder::class, ['record' => $orderA->getRouteKey()])
            ->callAction('mark_in_progress', ['unit_assignments' => [$itemA->id => $unitOfOtherService->id]])
            ->assertHasActionErrors(["unit_assignments.{$itemA->id}"]);
        $this->assertSame('confirmed', $orderA->fresh()->status);
        $this->assertNull($itemA->fresh()->service_unit_id);

        // Table row action on the OTHER order — identical rejection.
        Livewire::test(ListOrders::class)
            ->callTableAction('mark_in_progress', $orderB, ['unit_assignments' => [$itemB->id => $unitOfOtherService->id]])
            ->assertHasTableActionErrors(["unit_assignments.{$itemB->id}"]);
        $this->assertSame('confirmed', $orderB->fresh()->status);
        $this->assertNull($itemB->fresh()->service_unit_id);
    }

    public function test_mark_in_progress_without_any_assignment_still_hands_over_no_regression(): void
    {
        Notification::fake();
        [$service] = $this->serviceAndUnit();
        $order = Order::factory()->confirmed()->create(['organization_id' => $this->org->id]);
        $this->itemFor($order, $service);

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('mark_in_progress')
            ->assertHasNoActionErrors();

        $this->assertSame('in_progress', $order->fresh()->status);
    }

    // -------------------------------------------------------------------------
    // complete — EditOrder header action vs OrderResource table action
    // -------------------------------------------------------------------------

    public function test_edit_order_header_action_confirms_the_return_without_touching_the_default(): void
    {
        Notification::fake();
        [$service, $unit] = $this->serviceAndUnit();
        $order = Order::factory()->inProgress()->create(['organization_id' => $this->org->id]);
        $item = $this->itemFor($order, $service);
        $item->update(['service_unit_id' => $unit->id]);

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('complete', ['returned_units' => [$item->id => $unit->id]])
            ->assertHasNoActionErrors();

        $this->assertSame('completed', $order->fresh()->status);
    }

    /**
     * ClickUp 123k99cu2b4 requirement #2 ("nie może dać się kliknąć dalej
     * przypadkiem") — since ServiceUnitAssignmentForms's `mismatch_confirmed`
     * checkbox got `->rule('accepted')`, submitting a mismatch without it now
     * fails Filament's OWN form validation (assertHasActionErrors), not just
     * a caught service-layer exception. Both call sites, same outcome.
     */
    public function test_table_action_rejects_an_unconfirmed_mismatch_and_edit_order_does_too(): void
    {
        [$service, $handedOutUnit] = $this->serviceAndUnit();
        $returnedUnit = ServiceUnit::factory()->create([
            'organization_id' => $this->org->id,
            'service_id' => $service->id,
            'status' => ServiceUnitStatus::Available,
        ]);

        $orderA = Order::factory()->inProgress()->create(['organization_id' => $this->org->id]);
        $itemA = $this->itemFor($orderA, $service);
        $itemA->update(['service_unit_id' => $handedOutUnit->id]);

        Livewire::test(ListOrders::class)
            ->callTableAction('complete', $orderA, ['returned_units' => [$itemA->id => $returnedUnit->id]])
            ->assertHasTableActionErrors(['mismatch_confirmed' => 'accepted']);
        $this->assertSame('in_progress', $orderA->fresh()->status);

        $orderB = Order::factory()->inProgress()->create(['organization_id' => $this->org->id]);
        $itemB = $this->itemFor($orderB, $service);
        $itemB->update(['service_unit_id' => $handedOutUnit->id]);

        Livewire::test(EditOrder::class, ['record' => $orderB->getRouteKey()])
            ->callAction('complete', ['returned_units' => [$itemB->id => $returnedUnit->id]])
            ->assertHasActionErrors(['mismatch_confirmed' => 'accepted']);
        $this->assertSame('in_progress', $orderB->fresh()->status);
    }

    public function test_confirmed_mismatch_completes_the_return_through_both_call_sites(): void
    {
        Notification::fake();
        [$service, $handedOutUnit] = $this->serviceAndUnit();
        $returnedUnit = ServiceUnit::factory()->create([
            'organization_id' => $this->org->id,
            'service_id' => $service->id,
            'status' => ServiceUnitStatus::Available,
        ]);

        $orderA = Order::factory()->inProgress()->create(['organization_id' => $this->org->id]);
        $itemA = $this->itemFor($orderA, $service);
        $itemA->update(['service_unit_id' => $handedOutUnit->id]);

        Livewire::test(ListOrders::class)
            ->callTableAction('complete', $orderA, [
                'returned_units' => [$itemA->id => $returnedUnit->id],
                'mismatch_confirmed' => true,
            ])
            ->assertHasNoTableActionErrors();
        $this->assertSame('completed', $orderA->fresh()->status);

        $orderB = Order::factory()->inProgress()->create(['organization_id' => $this->org->id]);
        $itemB = $this->itemFor($orderB, $service);
        $itemB->update(['service_unit_id' => $handedOutUnit->id]);

        Livewire::test(EditOrder::class, ['record' => $orderB->getRouteKey()])
            ->callAction('complete', [
                'returned_units' => [$itemB->id => $returnedUnit->id],
                'mismatch_confirmed' => true,
            ])
            ->assertHasNoActionErrors();
        $this->assertSame('completed', $orderB->fresh()->status);
    }

    /**
     * ClickUp 123k99cu2b4's open edge case, through the real Filament action
     * (not just the service layer, already covered in the Unit test class).
     */
    public function test_edit_order_header_action_assigns_an_inline_identifier_at_return(): void
    {
        Notification::fake();
        [$service, $unit] = $this->serviceAndUnit(); // no identifier
        $this->assertNull($unit->identifier);
        $order = Order::factory()->inProgress()->create(['organization_id' => $this->org->id]);
        $item = $this->itemFor($order, $service);
        $item->update(['service_unit_id' => $unit->id]);

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->callAction('complete', [
                'returned_units' => [$item->id => $unit->id],
                'return_identifiers' => [$item->id => 'KOP-07'],
            ])
            ->assertHasNoActionErrors();

        $this->assertSame('KOP-07', $unit->fresh()->identifier);
        $this->assertSame('completed', $order->fresh()->status);
    }
}
