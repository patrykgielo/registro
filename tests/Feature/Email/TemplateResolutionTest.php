<?php

declare(strict_types=1);

namespace Tests\Feature\Email;

use App\Models\EmailTemplate;
use App\Models\Organization;
use App\Models\SmsTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EmailTemplate/SmsTemplate::resolveActive() — the fix for the cross-tenant template lookup bug.
 *
 * BelongsToOrganization's global scope filters to `organization_id = <current tenant>` the
 * instant a tenant is resolved, but every seeded template is global (organization_id NULL) —
 * so a plain ::where()->first() is unreachable from any tenant-scoped request. resolveActive()
 * bypasses that scope deliberately and replaces it with an explicit one: tenant override OR
 * global, NEVER another tenant's row. Mirrored for EmailTemplate and SmsTemplate — same trait,
 * same seeding pattern, same defect.
 */
class TemplateResolutionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A key that deliberately does NOT exist in EmailTemplateSeeder/SmsTemplateSeeder.
     * RefreshDatabase replays the seed-data migrations too, so a real 'order-confirmed'/pl
     * row already exists in every test in this class — using a real key would make
     * "global fallback" assertions ambiguous between the seeded row and the one built here.
     */
    private const KEY = 'qa-resolution-test-template';

    /**
     * Simulate ResolveTenant middleware setting the attribute TenantFeature::currentTenant()
     * reads — same pattern as TenantFeatureTest::test_tenant_feature_reads_from_request_attributes().
     */
    private function actingAsTenant(?Organization $org): void
    {
        $this->app['request']->attributes->set('tenant', $org);
    }

    // -------------------------------------------------------------------------
    // EmailTemplate
    // -------------------------------------------------------------------------

    public function test_email_tenant_override_wins_over_global_fallback(): void
    {
        $org = Organization::factory()->equipmentRental()->create();

        // Created before actingAsTenant() so the creating() hook has no ambient tenant to
        // auto-assign — organization_id genuinely stays NULL (global).
        EmailTemplate::create([
            'key' => self::KEY,
            'language' => 'pl',
            'subject' => 'Global subject',
            'html_body' => '<p>Global</p>',
            'variables' => [],
            'active' => true,
        ]);

        $override = EmailTemplate::create([
            'organization_id' => $org->id,
            'key' => self::KEY,
            'language' => 'pl',
            'subject' => 'Tenant override subject',
            'html_body' => '<p>Tenant override</p>',
            'variables' => [],
            'active' => true,
        ]);

        $this->actingAsTenant($org);

        $resolved = EmailTemplate::resolveActive(self::KEY, 'pl');

        $this->assertNotNull($resolved);
        $this->assertSame($override->id, $resolved->id);
        $this->assertSame('Tenant override subject', $resolved->subject);
    }

    public function test_email_falls_back_to_global_template_when_no_override_exists(): void
    {
        $org = Organization::factory()->equipmentRental()->create();

        $global = EmailTemplate::create([
            'key' => self::KEY,
            'language' => 'pl',
            'subject' => 'Global subject',
            'html_body' => '<p>Global</p>',
            'variables' => [],
            'active' => true,
        ]);

        $this->actingAsTenant($org);

        $resolved = EmailTemplate::resolveActive(self::KEY, 'pl');

        $this->assertNotNull($resolved);
        $this->assertSame($global->id, $resolved->id);
    }

    public function test_email_tenant_a_cannot_resolve_tenant_bs_override(): void
    {
        $orgA = Organization::factory()->equipmentRental()->create();
        $orgB = Organization::factory()->equipmentRental()->create();

        EmailTemplate::create([
            'organization_id' => $orgB->id,
            'key' => self::KEY,
            'language' => 'pl',
            'subject' => 'Org B private override',
            'html_body' => '<p>Org B only</p>',
            'variables' => [],
            'active' => true,
        ]);

        $this->actingAsTenant($orgA);

        // No global row exists either — org A must get nothing, never org B's row.
        $resolved = EmailTemplate::resolveActive(self::KEY, 'pl');

        $this->assertNull($resolved);
    }

    public function test_email_no_tenant_context_only_ever_resolves_the_global_template(): void
    {
        $org = Organization::factory()->equipmentRental()->create();

        $global = EmailTemplate::create([
            'key' => self::KEY,
            'language' => 'pl',
            'subject' => 'Global subject',
            'html_body' => '<p>Global</p>',
            'variables' => [],
            'active' => true,
        ]);

        EmailTemplate::create([
            'organization_id' => $org->id,
            'key' => self::KEY,
            'language' => 'pl',
            'subject' => 'Tenant override subject',
            'html_body' => '<p>Tenant override</p>',
            'variables' => [],
            'active' => true,
        ]);

        // Deliberately no actingAsTenant() — this is the queue-worker/console scenario:
        // TenantFeature::currentTenant() resolves nothing, so only the global row can match.
        $resolved = EmailTemplate::resolveActive(self::KEY, 'pl');

        $this->assertNotNull($resolved);
        $this->assertSame($global->id, $resolved->id);
    }

    // -------------------------------------------------------------------------
    // SmsTemplate — identical defect, identical fix
    // -------------------------------------------------------------------------

    public function test_sms_tenant_override_wins_over_global_fallback(): void
    {
        $org = Organization::factory()->equipmentRental()->create();

        SmsTemplate::create([
            'key' => self::KEY,
            'language' => 'pl',
            'message_body' => 'Global reminder',
            'variables' => [],
            'active' => true,
        ]);

        $override = SmsTemplate::create([
            'organization_id' => $org->id,
            'key' => self::KEY,
            'language' => 'pl',
            'message_body' => 'Tenant override reminder',
            'variables' => [],
            'active' => true,
        ]);

        $this->actingAsTenant($org);

        $resolved = SmsTemplate::resolveActive(self::KEY, 'pl');

        $this->assertNotNull($resolved);
        $this->assertSame($override->id, $resolved->id);
    }

    public function test_sms_tenant_a_cannot_resolve_tenant_bs_override(): void
    {
        $orgA = Organization::factory()->equipmentRental()->create();
        $orgB = Organization::factory()->equipmentRental()->create();

        SmsTemplate::create([
            'organization_id' => $orgB->id,
            'key' => self::KEY,
            'language' => 'pl',
            'message_body' => 'Org B private reminder',
            'variables' => [],
            'active' => true,
        ]);

        $this->actingAsTenant($orgA);

        $resolved = SmsTemplate::resolveActive(self::KEY, 'pl');

        $this->assertNull($resolved);
    }
}
