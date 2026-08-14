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
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Regression guard for the Livewire file-download bug (code review,
 * 2026-08-13): `Actions\Action::make(...)->action(fn () =>
 * $service->handoverProtocol($record))` returned `Illuminate\Http\Response`
 * (what Pdf::download() actually returns) from a Filament action closure.
 * Livewire's SupportFileDownloads::valueIsntAFileResponse() only recognizes
 * StreamedResponse/BinaryFileResponse — a plain Response is treated as the
 * component method's ordinary return VALUE and json_encode()'d, throwing on
 * the raw PDF binary ("Malformed UTF-8 characters"). All 4 buttons
 * (OrderResource table x2, EditOrder header x2) had this shape.
 *
 * Fixed by switching every one of them to ->url()->openUrlInNewTab(),
 * pointed at the same route OrderProtocolController already serves
 * (customer + staff downloads) — see order-protocols.md. This avoids the
 * Livewire action-return path entirely rather than trying to make it
 * recognize the right response type.
 *
 * Table actions (ListOrders) are used for the return_protocol regression
 * case rather than EditOrder's header action: OrderResource::canEdit()
 * returns false for 'completed'/'refunded'/'cancelled' (pre-existing,
 * unrelated to this branch), so a fresh EditOrder page mount for a
 * 'completed' order — exactly the status return_protocol needs to be
 * visible — is itself denied before any action can be tested. Table row
 * actions are not gated by canEdit() and do not have this problem; the
 * handover_protocol case below still exercises EditOrder's header action
 * directly since 'in_progress' is editable.
 *
 * The red-then-green proof (reverting the action back to
 * ->action(fn () => $pdf->download(...)) and re-running these tests to
 * confirm they throw) was done manually against temporary copies of
 * OrderResource.php/EditOrder.php, not committed — see
 * order-protocols.md's "Verification performed" for the captured exception.
 */
class OrderProtocolFilamentActionTest extends TestCase
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
    // EditOrder header action — handover_protocol (status stays editable)
    // -------------------------------------------------------------------------

    public function test_edit_order_handover_protocol_action_does_not_throw_and_points_at_the_authorized_download_route(): void
    {
        $order = Order::factory()->inProgress()->create([
            'organization_id' => $this->org->id,
        ]);

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionExists('handover_protocol')
            ->assertActionVisible('handover_protocol')
            ->assertActionHasUrl('handover_protocol', route('orders.protocol.handover', $order))
            ->assertActionShouldOpenUrlInNewTab('handover_protocol')
            ->callAction('handover_protocol')
            ->assertHasNoActionErrors();
    }

    public function test_edit_order_handover_protocol_action_is_hidden_for_ineligible_status(): void
    {
        $order = Order::factory()->confirmed()->create([
            'organization_id' => $this->org->id,
        ]);

        Livewire::test(EditOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionHidden('handover_protocol');
    }

    // -------------------------------------------------------------------------
    // ListOrders table action — return_protocol (status is 'completed', not
    // reachable via EditOrder — see class docblock)
    // -------------------------------------------------------------------------

    public function test_list_orders_return_protocol_table_action_does_not_throw_and_points_at_the_authorized_download_route(): void
    {
        $order = Order::factory()->completed()->create([
            'organization_id' => $this->org->id,
        ]);

        Livewire::test(ListOrders::class)
            ->assertTableActionExists('return_protocol')
            ->assertTableActionVisible('return_protocol', $order)
            ->assertTableActionHasUrl('return_protocol', route('orders.protocol.return', $order), $order)
            ->assertTableActionShouldOpenUrlInNewTab('return_protocol', $order)
            ->callTableAction('return_protocol', $order)
            ->assertHasNoTableActionErrors();
    }

    public function test_list_orders_return_protocol_table_action_is_hidden_for_ineligible_status(): void
    {
        $order = Order::factory()->inProgress()->create([
            'organization_id' => $this->org->id,
        ]);

        Livewire::test(ListOrders::class)
            ->assertTableActionHidden('return_protocol', $order);
    }

    public function test_list_orders_handover_protocol_table_action_does_not_throw(): void
    {
        $order = Order::factory()->inProgress()->create([
            'organization_id' => $this->org->id,
        ]);

        Livewire::test(ListOrders::class)
            ->assertTableActionExists('handover_protocol')
            ->assertTableActionVisible('handover_protocol', $order)
            ->assertTableActionHasUrl('handover_protocol', route('orders.protocol.handover', $order), $order)
            ->callTableAction('handover_protocol', $order)
            ->assertHasNoTableActionErrors();
    }
}
