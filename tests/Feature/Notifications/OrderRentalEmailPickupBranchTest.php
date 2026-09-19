<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\EmailSend;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\OrderAcceptedOfflineNotification;
use App\Notifications\OrderPaidNotification;
use App\Services\Email\EmailGatewayInterface;
use App\Support\Settings\SettingsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Faza 6 krok 6.5 (plan-wdrozenia.md, ClickUp 86cbahqhb) — the
 * {{pickup_address}}/{{pickup_phone}} tokens already rendered in
 * ORDER_ACCEPTED_OFFLINE and ORDER_PAID bodies (the only two templates that
 * use them — see EmailTemplateSeeder) now resolve to the order's own
 * checkout-time pickup-location snapshot when one exists, via
 * BuildsOrderRentalEmailVariables::buildRentalVariables() ->
 * SettingsManager::pickupDetailsFor(). No template body edit — the tokens
 * were already there (see OrderPaidNotificationPickupAddressTest for the
 * pre-existing Settings-only coverage this extends, unchanged).
 *
 * Both notification classes share the SAME trait method, so behaviour is
 * asserted once against OrderPaidNotification (the older, more-covered
 * path) and once against OrderAcceptedOfflineNotification (the "customer
 * most needs the address here" path named explicitly in the task) to prove
 * the shared resolver actually reaches both, not just one.
 */
class OrderRentalEmailPickupBranchTest extends TestCase
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

    private function createOrderWithBranchSnapshot(Organization $org, User $customer, string $status): Order
    {
        $location = Location::factory()->for($org)->create([
            'name' => 'Oddział Gdańsk',
            'street' => 'ul. Portowa 8',
            'building' => null,
            'postal_code' => '80-001',
            'city' => 'Gdańsk',
        ]);

        $order = Order::factory()->{$status}()->create([
            'organization_id' => $org->id,
            'user_id' => $customer->id,
            'customer_email' => $customer->email,
            'pickup_location_id' => $location->id,
            'pickup_location_name' => $location->name,
            'pickup_location_address' => $location->formattedAddress(),
        ]);
        OrderItem::factory()->create(['order_id' => $order->id, 'service_name' => 'Betoniarka 150L']);

        return $order;
    }

    // -------------------------------------------------------------------------
    // OrderPaidNotification
    // -------------------------------------------------------------------------

    public function test_paid_confirmation_email_shows_branch_address_when_snapshot_exists(): void
    {
        $this->serviceWithFakeGateway();

        $org = Organization::factory()->equipmentRental()->create();
        $customer = User::factory()->create(['preferred_language' => 'pl']);
        $this->assertNoAmbientTenant();

        $order = $this->createOrderWithBranchSnapshot($org, $customer, 'paid');
        $order->user->notify(new OrderPaidNotification($order, 'customer'));

        $send = EmailSend::where('recipient_email', $order->user->email)->firstOrFail();

        $this->assertStringContainsString('ul. Portowa 8, 80-001 Gdańsk', $send->body_html);
        $this->assertStringContainsString('Miejsce odbioru: ul. Portowa 8, 80-001 Gdańsk', $send->body_text);
    }

    /**
     * The `qatest`-shaped case explicitly named in the task: Settings
     * address is empty, but the order has a real branch snapshot — the
     * email must still show an address, not blank/"null".
     */
    public function test_paid_confirmation_email_shows_branch_address_even_when_settings_address_is_empty(): void
    {
        $this->serviceWithFakeGateway();

        // Deliberately no contact.* settings written for this tenant.
        $org = Organization::factory()->equipmentRental()->create();
        $customer = User::factory()->create(['preferred_language' => 'pl']);
        $this->assertNoAmbientTenant();

        $order = $this->createOrderWithBranchSnapshot($org, $customer, 'paid');
        $order->user->notify(new OrderPaidNotification($order, 'customer'));

        $send = EmailSend::where('recipient_email', $order->user->email)->firstOrFail();

        $this->assertStringContainsString('ul. Portowa 8, 80-001 Gdańsk', $send->body_html);
        $this->assertStringNotContainsString('null', $send->body_html);
    }

    /**
     * Fallback — an order without a pickup snapshot (legacy order, or a
     * tenant with no locations) must render EXACTLY like before this
     * feature: the tenant's Settings contact address, nothing else.
     */
    public function test_paid_confirmation_email_falls_back_to_settings_address_without_a_snapshot(): void
    {
        $this->serviceWithFakeGateway();

        $org = Organization::factory()->equipmentRental()->create();
        app('request')->attributes->set('tenant', $org);
        app(SettingsManager::class)->set('contact.address_line', 'ul. Testowa 5');
        app(SettingsManager::class)->set('contact.postal_code', '00-100');
        app(SettingsManager::class)->set('contact.city', 'Warszawa');
        app('request')->attributes->remove('tenant');

        $customer = User::factory()->create(['preferred_language' => 'pl']);
        $this->assertNoAmbientTenant();

        $order = Order::factory()->paid()->create([
            'organization_id' => $org->id,
            'user_id' => $customer->id,
            'customer_email' => $customer->email,
            'pickup_location_id' => null,
            'pickup_location_name' => null,
            'pickup_location_address' => null,
        ]);
        OrderItem::factory()->create(['order_id' => $order->id, 'service_name' => 'Betoniarka 150L']);

        $order->user->notify(new OrderPaidNotification($order, 'customer'));

        $send = EmailSend::where('recipient_email', $order->user->email)->firstOrFail();

        $this->assertStringContainsString('ul. Testowa 5, 00-100 Warszawa', $send->body_html);
    }

    // -------------------------------------------------------------------------
    // OrderAcceptedOfflineNotification — "the customer most needs the address
    // here" (pay-at-pickup, no payment confirmation email will follow soon).
    // -------------------------------------------------------------------------

    public function test_accepted_offline_email_shows_branch_address_when_snapshot_exists(): void
    {
        $this->serviceWithFakeGateway();

        $org = Organization::factory()->equipmentRental()->create();
        $customer = User::factory()->create(['preferred_language' => 'pl']);
        $this->assertNoAmbientTenant();

        $order = $this->createOrderWithBranchSnapshot($org, $customer, 'pendingPayment');
        $order->update(['settlement_method' => 'offline']);
        $order->user->notify(new OrderAcceptedOfflineNotification($order));

        $send = EmailSend::where('recipient_email', $order->user->email)->firstOrFail();

        $this->assertStringContainsString('ul. Portowa 8, 80-001 Gdańsk', $send->body_html);
        $this->assertStringContainsString('Miejsce odbioru: ul. Portowa 8, 80-001 Gdańsk', $send->body_text);
    }

    // -------------------------------------------------------------------------
    // New template variables (instruction #3) — exposed, empty when absent,
    // safe to add to a FUTURE template edit without touching any stored row.
    // -------------------------------------------------------------------------

    public function test_new_pickup_location_variables_are_built_but_unused_by_todays_templates(): void
    {
        $this->serviceWithFakeGateway();

        $org = Organization::factory()->equipmentRental()->create();
        $customer = User::factory()->create(['preferred_language' => 'pl']);
        $order = $this->createOrderWithBranchSnapshot($org, $customer, 'paid');

        $trait = new class
        {
            use \App\Notifications\Concerns\BuildsOrderRentalEmailVariables;

            /** @return array<string, mixed> */
            public function build(Order $order): array
            {
                $method = new \ReflectionMethod($this, 'buildRentalVariables');
                $method->setAccessible(true);

                return $method->invoke($this, $order);
            }
        };

        $variables = $trait->build($order);

        $this->assertSame('Oddział Gdańsk', $variables['pickup_location_name']);
        $this->assertSame('ul. Portowa 8, 80-001 Gdańsk', $variables['pickup_location_address']);

        // Today's seeded ORDER_PAID/ORDER_ACCEPTED_OFFLINE bodies reference
        // neither token — confirms adding them to buildRentalVariables()
        // changed nothing about what a customer sees today.
        $order->user->notify(new OrderPaidNotification($order, 'customer'));
        $send = EmailSend::where('recipient_email', $order->user->email)->firstOrFail();
        $this->assertStringNotContainsString('{{pickup_location_name}}', $send->body_html);
        $this->assertStringNotContainsString('pickup_location_name', $send->body_html);
    }

    public function test_new_pickup_location_variables_are_empty_strings_without_a_snapshot(): void
    {
        $org = Organization::factory()->equipmentRental()->create();
        $order = Order::factory()->paid()->create([
            'organization_id' => $org->id,
            'pickup_location_id' => null,
            'pickup_location_name' => null,
            'pickup_location_address' => null,
        ]);

        $trait = new class
        {
            use \App\Notifications\Concerns\BuildsOrderRentalEmailVariables;

            /** @return array<string, mixed> */
            public function build(Order $order): array
            {
                $method = new \ReflectionMethod($this, 'buildRentalVariables');
                $method->setAccessible(true);

                return $method->invoke($this, $order);
            }
        };

        $variables = $trait->build($order);

        $this->assertSame('', $variables['pickup_location_name']);
        $this->assertSame('', $variables['pickup_location_address']);
    }
}
