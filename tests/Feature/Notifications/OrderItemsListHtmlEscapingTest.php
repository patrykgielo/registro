<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\EmailSend;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Notifications\OrderHandedOverNotification;
use App\Notifications\OrderPaidNotification;
use App\Notifications\OrderReturnedNotification;
use App\Services\Email\EmailGatewayInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * EmailTemplate::render() HTML-escapes every substituted value by default, and
 * items_list_html — the rental equipment table built by OrderPaidNotification,
 * OrderHandedOverNotification and OrderReturnedNotification — used to be a plain string, so it
 * was escaped along with everything else: the paid/handover/return confirmation emails showed
 * the customer visible `<table>...` markup instead of a rendered table. Fixed by wrapping the
 * value in App\Support\Email\TrustedHtml, the one type EmailTemplate::render() inserts verbatim.
 *
 * @see \App\Models\EmailTemplate::substitutePlaceholders()
 * @see \App\Support\Email\TrustedHtml
 */
class OrderItemsListHtmlEscapingTest extends TestCase
{
    use RefreshDatabase;

    private function serviceWithFakeGateway(): void
    {
        $gateway = Mockery::mock(EmailGatewayInterface::class);
        $gateway->shouldReceive('send')->andReturnTrue();
        $this->app->instance(EmailGatewayInterface::class, $gateway);
        $this->app->forgetInstance(\App\Services\Email\EmailService::class);
    }

    private function bodyHtmlFor(Order $order): string
    {
        return EmailSend::where('recipient_email', $order->user->email)->firstOrFail()->body_html;
    }

    public function test_paid_confirmation_email_renders_an_actual_table_not_escaped_markup(): void
    {
        $this->serviceWithFakeGateway();

        $customer = User::factory()->create(['preferred_language' => 'pl']);
        $order = Order::factory()->paid()->create(['user_id' => $customer->id, 'customer_email' => $customer->email]);
        OrderItem::factory()->create(['order_id' => $order->id, 'service_name' => 'Wiertarka udarowa']);

        $order->user->notify(new OrderPaidNotification($order, 'customer'));

        $html = $this->bodyHtmlFor($order);

        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('<td', $html);
        $this->assertStringContainsString('Wiertarka udarowa', $html);
        $this->assertStringNotContainsString('&lt;table', $html);
        $this->assertStringNotContainsString('&lt;td', $html);
    }

    public function test_handover_email_renders_an_actual_table_not_escaped_markup(): void
    {
        $this->serviceWithFakeGateway();

        $customer = User::factory()->create(['preferred_language' => 'pl']);
        $order = Order::factory()->confirmed()->create(['user_id' => $customer->id, 'customer_email' => $customer->email]);
        OrderItem::factory()->create(['order_id' => $order->id, 'service_name' => 'Betoniarka 150L']);

        $order->user->notify(new OrderHandedOverNotification($order));

        $html = $this->bodyHtmlFor($order);

        $this->assertStringContainsString('<table', $html);
        $this->assertStringNotContainsString('&lt;table', $html);
    }

    public function test_return_email_renders_an_actual_table_not_escaped_markup(): void
    {
        $this->serviceWithFakeGateway();

        $customer = User::factory()->create(['preferred_language' => 'pl']);
        $order = Order::factory()->inProgress()->create(['user_id' => $customer->id, 'customer_email' => $customer->email]);
        OrderItem::factory()->create(['order_id' => $order->id, 'service_name' => 'Szlifierka kątowa']);

        $order->user->notify(new OrderReturnedNotification($order));

        $html = $this->bodyHtmlFor($order);

        $this->assertStringContainsString('<table', $html);
        $this->assertStringNotContainsString('&lt;table', $html);
    }

    /**
     * A service name is set by a tenant admin (ServiceResource), i.e. it is NOT code we
     * control — TrustedHtml only marks the surrounding table markup as safe; the interpolated
     * service name must still be neutralised, and is: buildRentalVariables() runs
     * htmlspecialchars() on it BEFORE it becomes part of the TrustedHtml-wrapped string. This
     * proves the composite is still safe rather than assuming it from the source alone.
     */
    public function test_a_script_tag_in_the_service_name_is_neutralised_inside_the_trusted_table(): void
    {
        $this->serviceWithFakeGateway();

        $customer = User::factory()->create(['preferred_language' => 'pl']);
        $order = Order::factory()->paid()->create(['user_id' => $customer->id, 'customer_email' => $customer->email]);
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'service_name' => '<script>alert(1)</script>',
        ]);

        $order->user->notify(new OrderPaidNotification($order, 'customer'));

        $html = $this->bodyHtmlFor($order);

        // The table itself still renders as markup...
        $this->assertStringContainsString('<table', $html);
        // ...but the attacker-controllable field inside it is escaped, not raw.
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    /**
     * A value that is NOT wrapped in TrustedHtml — a script tag arriving as any other template
     * variable — is still escaped exactly as before this change. The allowlist is per-value
     * (the TrustedHtml type), not a name-based exemption customer_name could ever land in.
     */
    public function test_a_script_tag_in_customer_name_is_still_escaped(): void
    {
        $this->serviceWithFakeGateway();

        $customer = User::factory()->create(['preferred_language' => 'pl']);
        $order = Order::factory()->paid()->create([
            'user_id' => $customer->id,
            'customer_email' => $customer->email,
            'customer_first_name' => '<script>alert(1)</script>',
            'customer_last_name' => '',
        ]);
        OrderItem::factory()->create(['order_id' => $order->id]);

        $order->user->notify(new OrderPaidNotification($order, 'customer'));

        $html = $this->bodyHtmlFor($order);

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }
}
