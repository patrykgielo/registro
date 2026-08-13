<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Services\Order\OrderProtocolPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderProtocolPdfServiceTest extends TestCase
{
    use RefreshDatabase;

    private OrderProtocolPdfService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(OrderProtocolPdfService::class);
    }

    // -------------------------------------------------------------------------
    // Handover protocol — status gating
    // -------------------------------------------------------------------------

    public function test_handover_protocol_throws_for_pending_payment_order(): void
    {
        $order = Order::factory()->pendingPayment()->create();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/wydania/i');

        $this->service->handoverProtocol($order);
    }

    public function test_handover_protocol_throws_for_paid_order(): void
    {
        $order = Order::factory()->paid()->create();

        $this->expectException(\DomainException::class);

        $this->service->handoverProtocol($order);
    }

    public function test_handover_protocol_throws_for_confirmed_order_not_yet_handed_over(): void
    {
        $order = Order::factory()->confirmed()->create();

        $this->expectException(\DomainException::class);

        $this->service->handoverProtocol($order);
    }

    public function test_handover_protocol_succeeds_for_in_progress_order(): void
    {
        $order = Order::factory()->inProgress()->create();

        $response = $this->service->handoverProtocol($order);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_handover_protocol_succeeds_for_completed_order(): void
    {
        $order = Order::factory()->completed()->create();

        $response = $this->service->handoverProtocol($order);

        $this->assertSame(200, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Handover protocol — forced-cancellation edge case (code review,
    // 2026-08-13): in_progress -> cancelled is a legal transition (forced
    // offboarding of a closing tenant). The handover protocol must remain
    // downloadable for equipment that genuinely left the counter before the
    // order was later force-cancelled — determined via the state machine's
    // own audit trail (state_histories), not a new column.
    // -------------------------------------------------------------------------

    public function test_handover_protocol_succeeds_for_cancelled_order_that_was_once_in_progress(): void
    {
        Notification::fake();

        $order = Order::factory()->inProgress()->create();
        $order->status()->transitionTo('cancelled');
        $order->refresh();

        $this->assertSame('cancelled', $order->status);

        $response = $this->service->handoverProtocol($order);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_handover_protocol_throws_for_cancelled_order_that_never_reached_in_progress(): void
    {
        Notification::fake();

        $order = Order::factory()->confirmed()->create();
        $order->status()->transitionTo('cancelled');
        $order->refresh();

        $this->assertSame('cancelled', $order->status);

        $this->expectException(\DomainException::class);

        $this->service->handoverProtocol($order);
    }

    public function test_can_download_handover_protocol_matches_the_throw_behavior_for_cancelled_orders(): void
    {
        Notification::fake();

        $everHandedOver = Order::factory()->inProgress()->create();
        $everHandedOver->status()->transitionTo('cancelled');
        $everHandedOver->refresh();

        $neverHandedOver = Order::factory()->confirmed()->create();
        $neverHandedOver->status()->transitionTo('cancelled');
        $neverHandedOver->refresh();

        $this->assertTrue($this->service->canDownloadHandoverProtocol($everHandedOver));
        $this->assertFalse($this->service->canDownloadHandoverProtocol($neverHandedOver));
    }

    public function test_handover_protocol_succeeds_for_refunded_order(): void
    {
        $order = Order::factory()->completed()->create(['status' => 'refunded']);

        $response = $this->service->handoverProtocol($order);

        $this->assertSame(200, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Return protocol — status gating
    // -------------------------------------------------------------------------

    public function test_return_protocol_throws_for_in_progress_order_not_yet_returned(): void
    {
        $order = Order::factory()->inProgress()->create();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessageMatches('/zwrotu/i');

        $this->service->returnProtocol($order);
    }

    public function test_return_protocol_throws_for_confirmed_order(): void
    {
        $order = Order::factory()->confirmed()->create();

        $this->expectException(\DomainException::class);

        $this->service->returnProtocol($order);
    }

    public function test_return_protocol_succeeds_for_completed_order(): void
    {
        $order = Order::factory()->completed()->create();

        $response = $this->service->returnProtocol($order);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_return_protocol_succeeds_for_refunded_order(): void
    {
        $order = Order::factory()->completed()->create(['status' => 'refunded']);

        $response = $this->service->returnProtocol($order);

        $this->assertSame(200, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Content — rendered via the underlying Blade view directly (asserting on
    // raw PDF bytes is unreliable: dompdf compresses/encodes content streams).
    // -------------------------------------------------------------------------

    public function test_handover_view_contains_order_number_and_item_names(): void
    {
        $order = Order::factory()->inProgress()->create(['order_number' => 'RG-TEST-9001']);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'service_name' => 'Betoniarka 150L',
        ]);
        $order->load(['items', 'organization']);

        $html = View::make('orders.protocols.handover', [
            'order' => $order,
            'org' => $order->organization,
            'pickup' => ['address' => '', 'phone' => '', 'email' => ''],
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        $this->assertStringContainsString('RG-TEST-9001', $html);
        $this->assertStringContainsString('Betoniarka 150L', $html);
        $this->assertStringContainsString('Protokół wydania', $html);
    }

    public function test_return_view_contains_order_number_and_item_names(): void
    {
        $order = Order::factory()->completed()->create(['order_number' => 'RG-TEST-9002']);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'service_name' => 'Wiertarka udarowa',
        ]);
        $order->load(['items', 'organization']);

        $html = View::make('orders.protocols.return', [
            'order' => $order,
            'org' => $order->organization,
            'pickup' => ['address' => '', 'phone' => '', 'email' => ''],
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        $this->assertStringContainsString('RG-TEST-9002', $html);
        $this->assertStringContainsString('Wiertarka udarowa', $html);
        $this->assertStringContainsString('Protokół zwrotu', $html);
    }

    public function test_handover_view_shows_deposit_amount_when_present(): void
    {
        $order = Order::factory()->inProgress()->create([
            'deposit_amount' => 250.00,
            'deposit_status' => 'collected',
        ]);
        $order->load(['items', 'organization']);

        $html = View::make('orders.protocols.handover', [
            'order' => $order,
            'org' => $order->organization,
            'pickup' => ['address' => '', 'phone' => '', 'email' => ''],
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        $this->assertStringContainsString('250,00', $html);
    }

    // -------------------------------------------------------------------------
    // Deposit status matrix — a reprinted protocol must describe the
    // deposit's CURRENT state, not assume the state at the moment the
    // underlying event (handover/return) originally happened. Regression
    // for the bug where handover.blade.php only handled 'collected' and
    // sent every other status (including 'returned', weeks later) to
    // "still to be collected at handover".
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function handoverDepositStatuses(): array
    {
        return [
            'pending' => ['pending', 'do pobrania przy wydaniu sprzętu.'],
            'collected' => ['collected', 'pobrana przy wydaniu sprzętu.'],
            'returned' => ['returned', 'zwrócona Najemcy po zakończeniu wynajmu.'],
            'partial_return' => ['partial_return', 'zwrócona częściowo po zakończeniu wynajmu.'],
            'forfeited' => ['forfeited', 'zatrzymana przez Wynajmującego.'],
        ];
    }

    #[DataProvider('handoverDepositStatuses')]
    public function test_handover_view_deposit_line_reflects_current_status(string $status, string $expectedFragment): void
    {
        $order = Order::factory()->completed()->create([
            'deposit_amount' => 500.00,
            'deposit_status' => $status,
        ]);
        $order->load(['items', 'organization']);

        $html = View::make('orders.protocols.handover', [
            'order' => $order,
            'org' => $order->organization,
            'pickup' => ['address' => '', 'phone' => '', 'email' => ''],
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        $this->assertStringContainsString($expectedFragment, $html);
    }

    public function test_handover_view_does_not_claim_deposit_still_to_be_collected_once_returned(): void
    {
        $order = Order::factory()->completed()->create([
            'deposit_amount' => 500.00,
            'deposit_status' => 'returned',
        ]);
        $order->load(['items', 'organization']);

        $html = View::make('orders.protocols.handover', [
            'order' => $order,
            'org' => $order->organization,
            'pickup' => ['address' => '', 'phone' => '', 'email' => ''],
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        $this->assertStringNotContainsString('— do pobrania przy wydaniu sprzętu.', $html);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function returnDepositStatuses(): array
    {
        return [
            'pending' => ['pending', 'nie została pobrana'],
            'collected' => ['collected', 'rozliczenie zwrotu kaucji w toku'],
            'returned' => ['returned', 'zwrócona Najemcy.'],
            'partial_return' => ['partial_return', 'zwrócona częściowo.'],
            'forfeited' => ['forfeited', 'zatrzymana przez Wynajmującego.'],
        ];
    }

    #[DataProvider('returnDepositStatuses')]
    public function test_return_view_deposit_line_reflects_current_status(string $status, string $expectedFragment): void
    {
        $order = Order::factory()->completed()->create([
            'deposit_amount' => 500.00,
            'deposit_status' => $status,
        ]);
        $order->load(['items', 'organization']);

        $html = View::make('orders.protocols.return', [
            'order' => $order,
            'org' => $order->organization,
            'pickup' => ['address' => '', 'phone' => '', 'email' => ''],
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->render();

        $this->assertStringContainsString($expectedFragment, $html);
    }

    // -------------------------------------------------------------------------
    // Company-identification limitation — organizations have no NIP/REGON,
    // only name + settings.contact.*. Confirms pickupDetails() does not
    // invent data when settings are empty.
    // -------------------------------------------------------------------------

    public function test_handover_protocol_does_not_fail_when_organization_settings_are_empty(): void
    {
        $org = Organization::factory()->equipmentRental()->create(['settings' => []]);
        $order = Order::factory()->inProgress()->create(['organization_id' => $org->id]);

        $response = $this->service->handoverProtocol($order);

        $this->assertSame(200, $response->getStatusCode());
    }
}
