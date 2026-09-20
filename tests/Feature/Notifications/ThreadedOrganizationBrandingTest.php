<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\ExtensionRequestStatus;
use App\Models\Appointment;
use App\Models\EmailSend;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemExtensionRequest;
use App\Models\Organization;
use App\Models\Rental;
use App\Models\Service;
use App\Models\User;
use App\Notifications\AppointmentCreatedNotification;
use App\Notifications\RentalCancelledNotification;
use App\Notifications\RentalExtensionApprovedNotification;
use App\Notifications\TenantWelcomeNotification;
use App\Services\Email\EmailGatewayInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Code review (feature/maile-wlasciciel-i-logo, 2026-09-20): the ORIGINAL branded-layout
 * change wrapped every sendFromTemplate() call unconditionally, which meant every
 * notification that did NOT already pass an $organization (everything except the 8 Order
 * notifications) got PLATFORM branding stamped into a customer-facing email. Fixed by (a)
 * guarding the wrap on `$organization !== null` and (b) threading $organization through
 * every caller that has one a deterministic hop away. One representative test per
 * "how organization is obtained" family, proving the TENANT's branding (not the
 * platform's, not nothing) actually lands in body_html for each:
 *
 * - via a model's own BelongsToOrganization `organization()` relation (Appointment*,
 *   RentalCancelled, ProcessRemindersJob's email branch — same mechanism, one example)
 * - via `$request->order->organization` (RentalExtension{Approved,Rejected,Requested})
 * - via a constructor property that already WAS the organization (TenantWelcome)
 *
 * UserRegistered/AdminCreatedUser (organization threaded through an EVENT, not a direct
 * model relation) have their own dedicated tests — see
 * tests/Feature/Auth/UserRegisteredEmailBrandingTest.php and
 * tests/Feature/Notifications/AdminCreatedUserEmailBrandingTest.php.
 */
class ThreadedOrganizationBrandingTest extends TestCase
{
    use RefreshDatabase;

    private function serviceWithFakeGateway(): void
    {
        $gateway = Mockery::mock(EmailGatewayInterface::class);
        $gateway->shouldReceive('send')->andReturnTrue();
        $this->app->instance(EmailGatewayInterface::class, $gateway);
        $this->app->forgetInstance(\App\Services\Email\EmailService::class);
    }

    public function test_appointment_created_email_carries_the_tenants_logo_via_its_organization_relation(): void
    {
        $this->serviceWithFakeGateway();
        Storage::fake('public');

        $org = Organization::factory()->create(['name' => 'Detailing Studio Ala']);
        $path = UploadedFile::fake()->image('brand.png')->store('settings/logos', 'public');
        app('request')->attributes->set('tenant', $org);
        app(\App\Support\Settings\SettingsManager::class)->set('appearance.header_logo', $path);
        app('request')->attributes->remove('tenant');

        $service = Service::factory()->create(['organization_id' => $org->id]);
        $customer = User::factory()->create(['preferred_language' => 'pl']);
        $staff = User::factory()->create();
        $staff->assignRole('staff');
        $appointment = Appointment::factory()->create([
            'organization_id' => $org->id,
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'staff_id' => $staff->id,
        ]);

        $customer->notify(new AppointmentCreatedNotification($appointment, 'customer'));

        $send = EmailSend::where('recipient_email', $customer->email)
            ->where('template_key', 'appointment-created')->firstOrFail();

        $this->assertStringContainsString('<img', $send->body_html);
        $this->assertStringContainsString($path, $send->body_html);
        // NOT assertStringNotContainsString('Registro', ...) here: this template's OWN
        // {{app_name}} token resolves via SettingsManager::appName(), a SEPARATE, already
        // pre-existing ambient-tenant limitation (same class PasswordResetNotification's
        // docblock documents) -- unrelated to the branding fix under test here. The
        // <title> tag is EmailBrandedLayout's own, unambiguous brand-name slot.
        $this->assertStringContainsString('<title>'.$org->name.'</title>', $send->body_html);
    }

    public function test_rental_cancelled_email_carries_the_tenants_name_via_its_organization_relation(): void
    {
        $this->serviceWithFakeGateway();

        $org = Organization::factory()->equipmentRental()->create(['name' => 'Wypozyczalnia Rex']);
        $customer = User::factory()->create(['preferred_language' => 'pl']);
        $rental = Rental::factory()->create([
            'organization_id' => $org->id,
            'customer_id' => $customer->id,
        ]);

        $customer->notify(new RentalCancelledNotification($rental, 'Testowy powod'));

        $send = EmailSend::where('recipient_email', $customer->email)
            ->where('template_key', 'rental-cancelled')->firstOrFail();

        // See test above for why this checks the <title> slot, not a blanket
        // "Registro" absence -- {{app_name}} in this template's own body is a
        // separate, unrelated, pre-existing gap.
        $this->assertStringContainsString('<title>'.$org->name.'</title>', $send->body_html);
    }

    public function test_rental_extension_approved_email_carries_the_tenants_name_via_order_organization(): void
    {
        $this->serviceWithFakeGateway();

        $org = Organization::factory()->equipmentRental()->create(['name' => 'Sprzet Budowlany SA']);
        $customer = User::factory()->create(['preferred_language' => 'pl']);
        $order = Order::factory()->paid()->create([
            'organization_id' => $org->id,
            'user_id' => $customer->id,
            'customer_email' => $customer->email,
        ]);
        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'service_name' => 'Betoniarka 150L',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(4)->toDateString(),
        ]);
        $extensionRequest = OrderItemExtensionRequest::create([
            'organization_id' => $org->id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'requested_by_user_id' => $customer->id,
            'status' => ExtensionRequestStatus::Approved,
            'original_end_date' => $item->end_date,
            'requested_end_date' => $item->end_date->copy()->addDays(2),
            'additional_days' => 2,
            'additional_amount' => 200.00,
        ]);

        $customer->notify(new RentalExtensionApprovedNotification($extensionRequest));

        $send = EmailSend::where('recipient_email', $customer->email)
            ->where('template_key', 'rental-extension-approved')->firstOrFail();

        $this->assertStringContainsString('<title>'.$org->name.'</title>', $send->body_html);
    }

    public function test_tenant_welcome_email_carries_the_tenants_own_name_not_the_platforms(): void
    {
        $this->serviceWithFakeGateway();

        $owner = User::factory()->create(['preferred_language' => 'pl']);
        $org = Organization::factory()->create(['name' => 'Nowy Najemca Sp. z o.o.', 'owner_id' => $owner->id]);

        $owner->notify(new TenantWelcomeNotification($org));

        $send = EmailSend::where('recipient_email', $owner->email)
            ->where('template_key', 'tenant-welcome')->firstOrFail();

        $this->assertStringContainsString('<title>'.$org->name.'</title>', $send->body_html);
    }
}
