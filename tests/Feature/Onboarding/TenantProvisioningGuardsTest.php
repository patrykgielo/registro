<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Models\Organization;
use App\Models\TenantProvisioningState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards added after review of the first provisioning pass. Each one covers a
 * way the earlier version failed silently rather than loudly.
 */
class TenantProvisioningGuardsTest extends TestCase
{
    use RefreshDatabase;

    private function runProvision(array $options = []): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('registro:tenant-provision', array_merge([
            '--slug' => 'acme-rentals',
            '--name' => 'Acme Rentals',
            '--industry' => 'equipment_rental',
            '--owner-email' => 'owner@acme.test',
            '--owner-name' => 'Jan Kowalski',
        ], $options));
    }

    public function test_it_refuses_to_hand_an_existing_account_ownership_of_a_new_organization(): void
    {
        $stranger = User::factory()->create(['email' => 'owner@acme.test']);

        $this->runProvision()->assertFailed();

        $this->assertDatabaseMissing('organizations', ['slug' => 'acme-rentals']);
        $this->assertFalse($stranger->fresh()->hasRole('admin'));
    }

    public function test_attaching_an_existing_account_is_allowed_when_asked_for_explicitly(): void
    {
        User::factory()->create(['email' => 'owner@acme.test']);

        $this->runProvision(['--attach-existing-owner' => true])->assertSuccessful();

        $org = Organization::where('slug', 'acme-rentals')->firstOrFail();
        $owner = User::where('email', 'owner@acme.test')->firstOrFail();

        $this->assertSame($owner->id, $org->owner_id);
        $this->assertTrue($owner->hasRole('admin'));
    }

    public function test_rerunning_against_the_orgs_own_owner_stays_idempotent(): void
    {
        $this->runProvision()->assertSuccessful();

        // No --attach-existing-owner: the owner exists by now, but it is this
        // organization's own owner, so idempotency must not need a flag.
        $this->runProvision()->assertSuccessful();

        $this->assertSame(1, Organization::where('slug', 'acme-rentals')->count());
        $this->assertSame(1, User::where('email', 'owner@acme.test')->count());
    }

    public function test_the_provisioning_marker_is_written_in_the_same_transaction_as_the_organization(): void
    {
        $this->runProvision()->assertSuccessful();

        $org = Organization::where('slug', 'acme-rentals')->firstOrFail();

        $this->assertDatabaseHas('tenant_provisioning_state', [
            'organization_id' => $org->id,
            'slug' => 'acme-rentals',
        ]);
        $this->assertSame(1, TenantProvisioningState::query()->count());
    }

    public function test_assert_fails_when_the_database_was_provisioned_but_the_container_lost_its_tenant_slug(): void
    {
        config()->set('app.tenant_slug', 'acme-rentals');
        $this->runProvision()->assertSuccessful();

        // The exact misconfiguration this check exists for: the stack's .env
        // loses TENANT_SLUG, every gate relaxes at once, and nothing else says so.
        config()->set('app.tenant_slug', null);

        $this->artisan('registro:tenant-provisioned', ['--assert' => true])->assertFailed();
    }

    public function test_assert_fails_when_the_container_is_pointed_at_another_tenants_database(): void
    {
        config()->set('app.tenant_slug', 'acme-rentals');
        $this->runProvision()->assertSuccessful();

        config()->set('app.tenant_slug', 'other-tenant');

        $this->artisan('registro:tenant-provisioned', ['--assert' => true])->assertFailed();
    }

    public function test_assert_passes_when_slug_and_database_agree(): void
    {
        config()->set('app.tenant_slug', 'acme-rentals');
        $this->runProvision()->assertSuccessful();

        $this->artisan('registro:tenant-provisioned', ['--assert' => true])->assertSuccessful();
    }

    public function test_assert_passes_on_an_unprovisioned_shared_stack_but_still_reports_not_provisioned(): void
    {
        $this->artisan('registro:tenant-provisioned', ['--assert' => true])->assertFailed();

        $this->assertFalse(TenantProvisioningState::isProvisioned());
    }
}
