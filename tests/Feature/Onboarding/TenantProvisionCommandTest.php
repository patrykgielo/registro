<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Enums\TemplateKey;
use App\Models\EmailTemplate;
use App\Models\Organization;
use App\Models\OrganizationLifecycleLog;
use App\Models\TenantProvisioningState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TenantProvisionCommandTest extends TestCase
{
    use RefreshDatabase;

    private function runCommand(array $options = []): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('registro:tenant-provision', array_merge([
            '--slug' => 'acme-rentals',
            '--name' => 'Acme Rentals',
            '--industry' => 'equipment_rental',
            '--owner-email' => 'owner@acme.test',
            '--owner-name' => 'Jan Kowalski',
        ], $options));
    }

    public function test_it_provisions_an_organization_with_a_passwordless_owner(): void
    {
        $this->runCommand()->assertSuccessful();

        $org = Organization::where('slug', 'acme-rentals')->firstOrFail();
        $owner = User::where('email', 'owner@acme.test')->firstOrFail();

        $this->assertSame('Jan', $owner->first_name);
        $this->assertSame('Kowalski', $owner->last_name);
        $this->assertNull($owner->password);
        $this->assertTrue($owner->hasRole('admin'));
        $this->assertTrue($owner->canAccessTenant($org));
        $this->assertSame($owner->id, $org->owner_id);

        $this->assertTrue(TenantProvisioningState::isProvisioned());

        $this->assertDatabaseHas('organization_lifecycle_log', [
            'organization_id' => $org->id,
            'event' => 'provisioned',
        ]);
    }

    public function test_it_prints_a_password_setup_link_instead_of_emailing_one(): void
    {
        Notification::fake();

        $this->runCommand()->assertSuccessful();

        $owner = User::where('email', 'owner@acme.test')->firstOrFail();
        $this->assertNotNull($owner->password_setup_token);

        Notification::assertNothingSent();
    }

    public function test_rerunning_the_command_does_not_duplicate_the_organization_or_owner(): void
    {
        $this->runCommand()->assertSuccessful();
        $this->runCommand()->assertSuccessful();

        $this->assertSame(1, Organization::withoutGlobalScopes()->where('slug', 'acme-rentals')->count());
        $this->assertSame(1, User::where('email', 'owner@acme.test')->count());
        $this->assertSame(
            1,
            OrganizationLifecycleLog::where('event', 'provisioned')->count(),
            'the audit log entry for creation must not be duplicated on rerun'
        );
    }

    public function test_rerunning_the_command_does_not_overwrite_a_customized_email_template(): void
    {
        $this->runCommand()->assertSuccessful();

        $template = EmailTemplate::where('key', TemplateKey::USER_REGISTERED->value)
            ->where('language', 'pl')
            ->firstOrFail();
        $template->update(['subject' => 'Custom subject the tenant wrote themselves']);

        $this->runCommand()->assertSuccessful();

        $this->assertSame(
            'Custom subject the tenant wrote themselves',
            $template->fresh()->subject,
        );
    }

    public function test_global_seeders_run_only_on_first_provisioning(): void
    {
        $this->assertFalse(TenantProvisioningState::isProvisioned());

        $this->runCommand()->assertSuccessful();
        $this->assertTrue(TenantProvisioningState::isProvisioned());

        $this->assertGreaterThan(0, EmailTemplate::count());

        $marker = TenantProvisioningState::query()->sole();
        $this->assertSame('acme-rentals', $marker->slug);

        $this->runCommand()->assertSuccessful();

        $this->assertSame(1, TenantProvisioningState::count(), 'marker row must not be duplicated on rerun');
    }

    public function test_it_refuses_to_provision_the_wrong_slug_for_this_container(): void
    {
        config(['app.tenant_slug' => 'a-different-tenant']);

        $this->runCommand()->assertFailed();

        $this->assertDatabaseMissing('organizations', ['slug' => 'acme-rentals']);
        $this->assertFalse(TenantProvisioningState::isProvisioned());
    }

    public function test_it_provisions_when_slug_matches_this_containers_tenant_slug(): void
    {
        config(['app.tenant_slug' => 'acme-rentals']);

        $this->runCommand()->assertSuccessful();

        $this->assertDatabaseHas('organizations', ['slug' => 'acme-rentals']);
    }

    public function test_it_rejects_invalid_industry(): void
    {
        $this->runCommand(['--industry' => 'not-a-real-industry'])->assertFailed();

        $this->assertDatabaseMissing('organizations', ['slug' => 'acme-rentals']);
    }

    public function test_it_rejects_reserved_slug(): void
    {
        $this->runCommand(['--slug' => 'admin'])->assertFailed();

        $this->assertDatabaseMissing('organizations', ['slug' => 'admin']);
    }

    public function test_provisioning_status_command_reflects_state(): void
    {
        $this->artisan('registro:tenant-provisioned')->assertFailed();

        $this->runCommand()->assertSuccessful();

        $this->artisan('registro:tenant-provisioned')->assertSuccessful();
    }
}
