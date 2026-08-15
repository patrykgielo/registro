<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\EmailSend;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\OrderPaidNotification;
use App\Services\Email\EmailGatewayInterface;
use App\Support\Settings\SettingsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Two settings stores exist ('settings' table, written by SystemSettings' Contact
 * tab; organizations.settings JSON column, written only by
 * SeedOrganizationDefaults::seedIndustryFeatures() and holding modules/features/
 * location, never contact). OrderPaidNotification::buildRentalVariables() used to
 * read the JSON column directly — always empty — so the paid-confirmation email
 * never once told a customer where to collect their equipment. Fixed to read via
 * SettingsManager::getForOrganization(), the same store the admin panel writes.
 *
 * @see \App\Notifications\OrderPaidNotification::buildRentalVariables()
 */
class OrderPaidNotificationPickupAddressTest extends TestCase
{
    use RefreshDatabase;

    private function serviceWithFakeGateway(): void
    {
        $gateway = Mockery::mock(EmailGatewayInterface::class);
        $gateway->shouldReceive('send')->andReturnTrue();
        $this->app->instance(EmailGatewayInterface::class, $gateway);
        $this->app->forgetInstance(\App\Services\Email\EmailService::class);
    }

    /**
     * Writes settings through SettingsManager::set() — the exact call
     * SystemSettings' Contact tab makes — while impersonating the given tenant
     * via the request 'tenant' attribute (what ResolveTenant sets on a real
     * request, and what Filament's tenancy resolves to on a real admin save).
     *
     * @param  array<string, string>  $values
     */
    private function setTenantContactSettings(Organization $org, array $values): void
    {
        app('request')->attributes->set('tenant', $org);

        $settings = app(SettingsManager::class);
        foreach ($values as $key => $value) {
            $settings->set("contact.{$key}", $value);
        }

        // Back to no ambient tenant — the state a queue worker actually runs in.
        app('request')->attributes->remove('tenant');
    }

    private function assertNoAmbientTenant(): void
    {
        $this->assertNull(
            app('request')->attributes->get('tenant'),
            'test setup leaked an ambient tenant — this would mask the queue-worker bug being tested'
        );
    }

    private function createPaidOrder(Organization $org): Order
    {
        $customer = User::factory()->create(['preferred_language' => 'pl']);
        $order = Order::factory()->paid()->create([
            'organization_id' => $org->id,
            'user_id' => $customer->id,
            'customer_email' => $customer->email,
        ]);
        OrderItem::factory()->create(['order_id' => $order->id, 'service_name' => 'Betoniarka 150L']);

        return $order;
    }

    public function test_paid_confirmation_email_carries_the_tenants_pickup_address(): void
    {
        $this->serviceWithFakeGateway();

        $org = Organization::factory()->equipmentRental()->create();
        $this->setTenantContactSettings($org, [
            'address_line' => 'ul. Testowa 5',
            'postal_code' => '00-100',
            'city' => 'Warszawa',
            'phone' => '+48123123123',
            'email' => 'kontakt@example.test',
        ]);

        $this->assertNoAmbientTenant();

        $order = $this->createPaidOrder($org);
        $order->user->notify(new OrderPaidNotification($order, 'customer'));

        $send = EmailSend::where('recipient_email', $order->user->email)->firstOrFail();

        // Asserted as ONE adjacent fragment, not independent substrings — the
        // template used to concatenate {{pickup_address}}{{pickup_phone}} with
        // no separator at all, so "…Warszawa+48123123123" (glued together) would
        // satisfy two assertStringContainsString() calls checked independently
        // without this ever catching it. Pin the exact junction instead.
        $this->assertStringContainsString('ul. Testowa 5, 00-100 Warszawa<br>+48123123123', $send->body_html);
        $this->assertStringNotContainsString('Warszawa+48123123123', $send->body_html);
        $this->assertStringContainsString('Miejsce odbioru: ul. Testowa 5, 00-100 Warszawa', $send->body_text);
        $this->assertStringContainsString('Telefon: +48123123123', $send->body_text);
    }

    public function test_tenant_scoped_contact_override_beats_the_global_default(): void
    {
        $this->serviceWithFakeGateway();

        $org = Organization::factory()->equipmentRental()->create();

        // Global default row (organization_id IS NULL) — same shape SettingSeeder writes.
        \App\Models\Setting::withoutGlobalScope('organization')->create([
            'organization_id' => null,
            'group' => 'contact',
            'key' => 'address_line',
            'value' => ['Adres globalny 1'],
        ]);

        $this->setTenantContactSettings($org, [
            'address_line' => 'ul. Tenantowa 9',
            'postal_code' => '11-111',
            'city' => 'Kraków',
            'phone' => '+48999888777',
        ]);

        $this->assertNoAmbientTenant();

        $order = $this->createPaidOrder($org);
        $order->user->notify(new OrderPaidNotification($order, 'customer'));

        $send = EmailSend::where('recipient_email', $order->user->email)->firstOrFail();

        $this->assertStringContainsString('ul. Tenantowa 9', $send->body_html);
        $this->assertStringNotContainsString('Adres globalny 1', $send->body_html);
    }

    /**
     * A tenant that never configured a contact tab (the state every tenant was
     * actually in before this fix, per the JSON-column audit in
     * tenant-branding.md) must not degrade into "null, null" or a stray leading
     * comma — array_filter() in buildRentalVariables() already guarantees this;
     * this pins it so it stays true.
     */
    public function test_missing_contact_settings_produce_no_null_literals_or_stray_commas(): void
    {
        $this->serviceWithFakeGateway();

        $org = Organization::factory()->equipmentRental()->create();
        $this->assertNoAmbientTenant();

        $order = $this->createPaidOrder($org);
        $order->user->notify(new OrderPaidNotification($order, 'customer'));

        $send = EmailSend::where('recipient_email', $order->user->email)->firstOrFail();

        $this->assertStringNotContainsString('null', $send->body_html);
        $this->assertStringNotContainsString(', ,', $send->body_html);
        $this->assertStringNotContainsString('null', $send->body_text);
    }

    /**
     * A tenant that filled in only some contact fields (e.g. city but no
     * street address) must not produce a leading/orphan comma either.
     */
    public function test_partial_contact_settings_produce_no_stray_comma(): void
    {
        $this->serviceWithFakeGateway();

        $org = Organization::factory()->equipmentRental()->create();
        $this->setTenantContactSettings($org, ['city' => 'Poznań']);
        $this->assertNoAmbientTenant();

        $order = $this->createPaidOrder($org);
        $order->user->notify(new OrderPaidNotification($order, 'customer'));

        $send = EmailSend::where('recipient_email', $order->user->email)->firstOrFail();

        $this->assertStringNotContainsString(', Poznań', $send->body_html);
        $this->assertStringContainsString('Poznań', $send->body_html);
    }
}
