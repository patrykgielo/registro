<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\EmailSend;
use App\Models\EmailTemplate;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\OrderPaidNotification;
use App\Services\Email\EmailGatewayInterface;
use App\Support\Settings\SettingsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * ClickUp 123k99cvc56: DB-templated order emails were sent as bare HTML
 * (SmtpMailer -> $message->html($htmlBody), no layout, no logo) even though
 * the tenant may have configured a header logo and brand color for exactly
 * this purpose (design.use_logo_in_emails / design.use_color_in_emails).
 * Fixed by EmailService::sendFromTemplate() wrapping the rendered body via
 * EmailBrandedLayout before it is stored in email_sends/sent, using an
 * EXPLICITLY passed Organization — never ambient tenant state, because this
 * runs inside a Horizon queue worker with no request context (see
 * architecture-models.md's "Kolejka nie ma kontekstu żądania").
 */
class OrderEmailBrandedLayoutTest extends TestCase
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

    /**
     * Writes a setting scoped to $org WITHOUT leaving an ambient tenant behind —
     * same technique as OrderRentalEmailPickupBranchTest's Settings-fallback test.
     */
    private function setOrgSetting(Organization $org, string $path, mixed $value): void
    {
        app('request')->attributes->set('tenant', $org);
        app(SettingsManager::class)->set($path, $value);
        app('request')->attributes->remove('tenant');
    }

    private function createPaidOrderWithItem(Organization $org, User $customer): Order
    {
        $order = Order::factory()->paid()->create([
            'organization_id' => $org->id,
            'user_id' => $customer->id,
            'customer_email' => $customer->email,
        ]);
        OrderItem::factory()->create(['order_id' => $order->id, 'service_name' => 'Betoniarka 150L']);

        return $order;
    }

    public function test_email_gets_the_tenants_logo_when_one_is_configured(): void
    {
        $this->serviceWithFakeGateway();
        Storage::fake('public');

        $org = Organization::factory()->equipmentRental()->create();
        $file = UploadedFile::fake()->image('brand.png');
        $path = $file->store('settings/logos', 'public');
        $this->setOrgSetting($org, 'appearance.header_logo', $path);

        $customer = User::factory()->create(['preferred_language' => 'pl']);
        $this->assertNoAmbientTenant();

        $order = $this->createPaidOrderWithItem($org, $customer);
        $order->user->notify(new OrderPaidNotification($order, 'customer'));

        $send = EmailSend::where('recipient_email', $customer->email)->firstOrFail();

        $this->assertStringContainsString('<img', $send->body_html);
        $this->assertStringContainsString($path, $send->body_html);
    }

    public function test_email_has_a_clean_text_header_with_no_broken_image_when_no_logo_is_configured(): void
    {
        $this->serviceWithFakeGateway();

        $org = Organization::factory()->equipmentRental()->create();
        $customer = User::factory()->create(['preferred_language' => 'pl']);
        $this->assertNoAmbientTenant();

        $order = $this->createPaidOrderWithItem($org, $customer);
        $order->user->notify(new OrderPaidNotification($order, 'customer'));

        $send = EmailSend::where('recipient_email', $customer->email)->firstOrFail();

        $this->assertStringNotContainsString('<img', $send->body_html);
        $this->assertStringContainsString($org->name, $send->body_html);
    }

    public function test_a_tenants_customised_override_body_still_renders_inside_the_branded_layout(): void
    {
        $this->serviceWithFakeGateway();

        $org = Organization::factory()->equipmentRental()->create();
        $customer = User::factory()->create(['preferred_language' => 'pl']);

        EmailTemplate::withoutGlobalScope('organization')->create([
            'organization_id' => $org->id,
            'key' => 'order-paid',
            'language' => 'pl',
            'subject' => 'Custom subject #{{order_number}}',
            'html_body' => '<p>Custom override body for order #{{order_number}}.</p>',
            'text_body' => 'Custom override body for order #{{order_number}}.',
            'variables' => ['order_number'],
            'active' => true,
        ]);

        $order = $this->createPaidOrderWithItem($org, $customer);
        $order->user->notify(new OrderPaidNotification($order, 'customer'));

        $send = EmailSend::where('recipient_email', $customer->email)->firstOrFail();

        $this->assertStringContainsString('Custom override body for order #'.$order->order_number, $send->body_html);
        // Still inside the shared branded shell, not sent bare.
        $this->assertStringContainsString('<!DOCTYPE html>', $send->body_html);
    }

    public function test_a_tenants_fully_custom_html_document_override_is_not_double_wrapped(): void
    {
        $this->serviceWithFakeGateway();

        $org = Organization::factory()->equipmentRental()->create();
        $customer = User::factory()->create(['preferred_language' => 'pl']);

        $fullDocument = '<html><head><title>Custom</title></head><body><p>Fully custom document for #{{order_number}}.</p></body></html>';

        EmailTemplate::withoutGlobalScope('organization')->create([
            'organization_id' => $org->id,
            'key' => 'order-paid',
            'language' => 'pl',
            'subject' => 'Custom subject #{{order_number}}',
            'html_body' => $fullDocument,
            'text_body' => 'Fully custom document.',
            'variables' => ['order_number'],
            'active' => true,
        ]);

        $order = $this->createPaidOrderWithItem($org, $customer);
        $order->user->notify(new OrderPaidNotification($order, 'customer'));

        $send = EmailSend::where('recipient_email', $customer->email)->firstOrFail();

        $this->assertSame(1, substr_count(strtolower($send->body_html), '<html'));
        $this->assertStringContainsString('Fully custom document for #'.$order->order_number, $send->body_html);
    }
}
