<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Events\OrderAcceptedOffline;
use App\Models\EmailSend;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\OrderAcceptedOfflineNotification;
use App\Services\Email\EmailGatewayInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * ClickUp 123k99cvc55: an offline (pay-at-pickup) order previously reached the
 * customer only — AppServiceProvider's OrderAcceptedOffline listener notified
 * `$order->user`, never `$order->organization->owner`. The only settlement
 * method live on UAT today is offline, so the owner had NO email path for a
 * new order and had to watch the panel. Fixed by giving
 * OrderAcceptedOfflineNotification an 'admin' recipientType (mirrors
 * OrderPaidNotification's existing customer/admin split) reusing the
 * ADMIN_NEW_ORDER template — same key OrderPaidNotification's own admin
 * branch already used — enriched with a {{payment_note}} token so the owner
 * can tell a pay-at-pickup order apart from an already-paid one.
 */
class OrderAcceptedOfflineAdminNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function serviceWithFakeGateway(): void
    {
        $gateway = Mockery::mock(EmailGatewayInterface::class);
        $gateway->shouldReceive('send')->andReturnTrue();
        $this->app->instance(EmailGatewayInterface::class, $gateway);
        $this->app->forgetInstance(\App\Services\Email\EmailService::class);
    }

    private function assertNoAmbientTenant(): void
    {
        $this->assertNull(
            app('request')->attributes->get('tenant'),
            'test setup leaked an ambient tenant — this would mask the queue-worker bug being tested'
        );
    }

    private function createOfflineOrder(Organization $org, User $customer): Order
    {
        $order = Order::factory()->pendingPayment()->create([
            'organization_id' => $org->id,
            'user_id' => $customer->id,
            'customer_email' => $customer->email,
            'settlement_method' => 'offline',
        ]);
        OrderItem::factory()->create(['order_id' => $order->id, 'service_name' => 'Betoniarka 150L']);

        return $order;
    }

    public function test_owner_receives_a_new_order_email_for_an_offline_accepted_order(): void
    {
        $this->serviceWithFakeGateway();

        $org = Organization::factory()->equipmentRental()->create();
        $org->owner->update(['preferred_language' => 'pl']);
        $customer = User::factory()->create(['preferred_language' => 'pl']);
        $this->assertNoAmbientTenant();

        $order = $this->createOfflineOrder($org, $customer);

        event(new OrderAcceptedOffline($order));

        $ownerSend = EmailSend::where('recipient_email', $org->owner->email)
            ->where('template_key', 'admin-new-order')
            ->firstOrFail();

        $this->assertStringContainsString($order->order_number, $ownerSend->body_html);
        $this->assertStringContainsString('Betoniarka 150L', $ownerSend->body_html);
        $this->assertStringContainsString('Płatność przy odbiorze', $ownerSend->body_html);
        $this->assertStringContainsString(trim($order->customer_first_name.' '.$order->customer_last_name), $ownerSend->body_html);
    }

    public function test_customer_still_receives_the_pay_at_pickup_email_unchanged(): void
    {
        $this->serviceWithFakeGateway();

        $org = Organization::factory()->equipmentRental()->create();
        $customer = User::factory()->create(['preferred_language' => 'pl']);
        $this->assertNoAmbientTenant();

        $order = $this->createOfflineOrder($org, $customer);

        event(new OrderAcceptedOffline($order));

        $customerSend = EmailSend::where('recipient_email', $customer->email)
            ->where('template_key', 'order-accepted-offline')
            ->firstOrFail();

        $this->assertStringContainsString($order->order_number, $customerSend->body_html);
        $this->assertStringContainsString('Betoniarka 150L', $customerSend->body_html);

        // The two recipients got two DIFFERENT templates for the same event.
        $this->assertSame(2, EmailSend::where('metadata->order_id', $order->id)->count());
    }

    public function test_owner_notification_reuses_the_paid_flows_admin_template_with_its_own_note(): void
    {
        $this->serviceWithFakeGateway();

        $org = Organization::factory()->equipmentRental()->create();
        $customer = User::factory()->create(['preferred_language' => 'en']);
        $org->owner->update(['preferred_language' => 'en']);

        $order = $this->createOfflineOrder($org, $customer);

        $org->owner->notify(new OrderAcceptedOfflineNotification($order, 'admin'));

        $send = EmailSend::where('recipient_email', $org->owner->email)
            ->where('template_key', 'admin-new-order')
            ->firstOrFail();

        $this->assertStringContainsString('Pay at pickup', $send->body_html);
        $this->assertStringNotContainsString('{{payment_note}}', $send->body_html);
        $this->assertStringNotContainsString('{{items_list_html}}', $send->body_html);
    }
}
