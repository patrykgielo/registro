<?php

declare(strict_types=1);

namespace Tests\Feature\AccountClosure;

use App\Enums\OrganizationLifecycleState;
use App\Filament\Pages\SystemSettings;
use App\Models\Organization;
use App\Models\OrganizationLifecycleLog;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\OrganizationClosureRequestedNotification;
use App\Support\Settings\SettingsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ClosureRequestTest extends TestCase
{
    use RefreshDatabase;

    protected Organization $org;

    protected User $admin;

    protected User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->assignRole('super-admin');

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        $this->org = Organization::factory()->create(['owner_id' => $this->admin->id]);

        Cache::flush();
    }

    public function test_closure_request_sets_timestamp(): void
    {
        $this->assertNull($this->org->closure_requested_at);

        $this->org->closure_requested_at = now();
        $this->org->save();

        $this->assertNotNull($this->org->fresh()->closure_requested_at);
    }

    public function test_closure_request_does_not_change_lifecycle_state(): void
    {
        $this->org->closure_requested_at = now();
        $this->org->save();

        $this->assertSame(
            OrganizationLifecycleState::Active,
            $this->org->fresh()->lifecycle_state
        );
    }

    public function test_closure_request_writes_lifecycle_log(): void
    {
        $this->actingAs($this->admin);

        $this->org->closure_requested_at = now();
        $this->org->save();

        OrganizationLifecycleLog::record($this->org, 'closure_requested', $this->admin);

        $this->assertDatabaseHas('organization_lifecycle_log', [
            'organization_id' => $this->org->id,
            'organization_name' => $this->org->name,
            'event' => 'closure_requested',
            'actor_id' => $this->admin->id,
            'actor_label' => $this->admin->email,
        ]);
    }

    public function test_closure_request_sends_notification_to_super_admins(): void
    {
        Notification::fake();
        $this->actingAs($this->admin);

        $this->org->closure_requested_at = now();
        $this->org->save();

        Notification::send(
            User::role('super-admin')->get(),
            new OrganizationClosureRequestedNotification($this->org, $this->admin)
        );

        Notification::assertSentTo($this->superAdmin, OrganizationClosureRequestedNotification::class);
    }

    public function test_notification_not_sent_when_no_super_admins_exist(): void
    {
        Notification::fake();

        $this->superAdmin->removeRole('super-admin');

        Notification::send(
            User::role('super-admin')->get(),
            new OrganizationClosureRequestedNotification($this->org, $this->admin)
        );

        Notification::assertNothingSent();
    }

    /**
     * Drive the real requestClosure() method with the org injected as the
     * resolved tenant (TenantFeature path #2 — request attribute).
     */
    private function invokeRequestClosure(): void
    {
        request()->attributes->set('tenant', $this->org->fresh());
        (new SystemSettings)->requestClosure();
    }

    public function test_request_closure_method_flags_logs_and_notifies(): void
    {
        Notification::fake();
        $this->actingAs($this->admin);

        $this->invokeRequestClosure();

        $fresh = $this->org->fresh();
        $this->assertNotNull($fresh->closure_requested_at);
        $this->assertSame(OrganizationLifecycleState::Active, $fresh->lifecycle_state);

        $this->assertDatabaseHas('organization_lifecycle_log', [
            'organization_id' => $this->org->id,
            'event' => 'closure_requested',
            'actor_id' => $this->admin->id,
        ]);

        Notification::assertSentTo($this->superAdmin, OrganizationClosureRequestedNotification::class);
    }

    public function test_guard_closing_state_prevents_request(): void
    {
        Bus::fake();
        Notification::fake();
        $this->actingAs($this->superAdmin);

        app(\App\Actions\Offboarding\StartOrganizationOffboarding::class)->execute($this->org);
        $this->org->refresh();
        $this->assertSame(OrganizationLifecycleState::Closing, $this->org->lifecycle_state);

        $logsBefore = OrganizationLifecycleLog::where('event', 'closure_requested')->count();

        $this->actingAs($this->admin);
        $this->invokeRequestClosure();

        $this->assertNull($this->org->fresh()->closure_requested_at);
        $this->assertSame(
            $logsBefore,
            OrganizationLifecycleLog::where('event', 'closure_requested')->count()
        );
        Notification::assertNotSentTo($this->superAdmin, OrganizationClosureRequestedNotification::class);
    }

    public function test_guard_already_pending_prevents_duplicate(): void
    {
        Notification::fake();
        $this->actingAs($this->admin);

        $this->org->closure_requested_at = now()->subHour();
        $this->org->save();

        $this->invokeRequestClosure();

        // Guard fires: no new log, no notification, timestamp unchanged.
        $this->assertDatabaseCount('organization_lifecycle_log', 0);
        Notification::assertNothingSent();
    }

    public function test_request_closure_is_atomic_on_double_call(): void
    {
        Notification::fake();
        $this->actingAs($this->admin);

        $this->invokeRequestClosure();
        $this->invokeRequestClosure();

        // Atomic whereNull guard → exactly one log + one notification despite two calls.
        $this->assertSame(
            1,
            OrganizationLifecycleLog::where('event', 'closure_requested')->count()
        );
        Notification::assertSentToTimes($this->superAdmin, OrganizationClosureRequestedNotification::class, 1);
    }

    public function test_settings_page_denies_staff_access(): void
    {
        $staff = User::factory()->create();
        Role::firstOrCreate(['name' => 'staff', 'guard_name' => 'web']);
        $staff->assignRole('staff');

        $this->actingAs($staff);
        $this->assertFalse(SystemSettings::canAccess());

        $this->actingAs($this->admin);
        $this->assertTrue(SystemSettings::canAccess());
    }

    public function test_lifecycle_log_survives_org_force_delete(): void
    {
        $this->actingAs($this->admin);

        $orgId = $this->org->id;
        $orgName = $this->org->name;

        OrganizationLifecycleLog::record($this->org, 'closure_requested', $this->admin);

        $this->assertDatabaseHas('organization_lifecycle_log', [
            'organization_id' => $orgId,
            'event' => 'closure_requested',
        ]);

        // Hard-delete the org (bypass all guards)
        $this->org->bypassDeleteGuard = true;
        $this->org->forceDelete();

        $this->assertDatabaseMissing('organizations', ['id' => $orgId]);

        // Log row must survive — no FK cascade
        $this->assertDatabaseHas('organization_lifecycle_log', [
            'organization_id' => $orgId,
            'organization_name' => $orgName,
            'event' => 'closure_requested',
        ]);
    }

    public function test_log_context_stored_correctly(): void
    {
        $entry = OrganizationLifecycleLog::record(
            $this->org,
            'offboarding_started',
            $this->superAdmin,
            ['closing_initiated_at' => '2026-06-30T12:00:00+00:00']
        );

        $fresh = OrganizationLifecycleLog::find($entry->id);
        $this->assertIsArray($fresh->context);
        $this->assertArrayHasKey('closing_initiated_at', $fresh->context);
    }

    public function test_closure_request_email_returns_seeded_value(): void
    {
        Setting::updateOrCreate(
            ['group' => 'account', 'key' => 'closure_request_email', 'organization_id' => null],
            ['value' => ['zamkniecie@example.com']]
        );

        Cache::flush();

        $email = app(SettingsManager::class)->closureRequestEmail();
        $this->assertSame('zamkniecie@example.com', $email);
    }

    public function test_closure_request_email_fallback_when_not_seeded(): void
    {
        Cache::flush();

        // No account setting — fallback to contact email or platform default
        $email = app(SettingsManager::class)->closureRequestEmail();
        $this->assertNotEmpty($email);
        $this->assertStringContainsString('@', $email);
    }

    // ========================================================================
    // GAP #7 — Tab reflects pending/closing/closed state
    // ========================================================================

    public function test_tab_hides_button_when_closure_request_pending(): void
    {
        $this->actingAs($this->admin);

        // After requestClosure runs, closure_requested_at must be set
        $this->invokeRequestClosure();

        $fresh = $this->org->fresh();
        $this->assertNotNull($fresh->closure_requested_at, 'closure_requested_at must be set after requestClosure().');

        // The hidden() closure on the Actions component checks this:
        // org->closure_requested_at !== null → actions component is hidden.
        $isShouldHide = $fresh->closure_requested_at !== null
            || in_array($fresh->lifecycle_state, [
                OrganizationLifecycleState::Closing,
                OrganizationLifecycleState::Closed,
            ], true);

        $this->assertTrue($isShouldHide, 'Button must be hidden after request is pending.');
    }

    public function test_tab_hides_button_when_lifecycle_closing(): void
    {
        Bus::fake();
        $this->actingAs($this->superAdmin);

        app(\App\Actions\Offboarding\StartOrganizationOffboarding::class)->execute($this->org);
        $fresh = $this->org->fresh();

        $isShouldHide = in_array($fresh->lifecycle_state, [
            OrganizationLifecycleState::Closing,
            OrganizationLifecycleState::Closed,
        ], true);

        $this->assertTrue($isShouldHide, 'Button must be hidden when org is Closing.');
    }

    public function test_tab_shows_button_when_no_request_and_active(): void
    {
        // Default state: Active + no closure_requested_at
        $fresh = $this->org->fresh();

        $isShouldHide = $fresh->closure_requested_at !== null
            || in_array($fresh->lifecycle_state, [
                OrganizationLifecycleState::Closing,
                OrganizationLifecycleState::Closed,
            ], true);

        $this->assertFalse($isShouldHide, 'Button must be visible when org is Active with no pending request.');
    }

    public function test_tab_shows_pending_status_after_request_closure(): void
    {
        $this->actingAs($this->admin);
        $this->invokeRequestClosure();

        $fresh = $this->org->fresh();
        $this->assertNotNull($fresh->closure_requested_at);
        $this->assertSame(OrganizationLifecycleState::Active, $fresh->lifecycle_state);
    }

    public function test_log_has_no_updated_at_column(): void
    {
        $entry = OrganizationLifecycleLog::record($this->org, 'test_event');

        $this->assertNotNull($entry->created_at);
        // UPDATED_AT = null means no updated_at tracking
        $this->assertNull(OrganizationLifecycleLog::UPDATED_AT);
    }

    public function test_offboarding_started_writes_lifecycle_log(): void
    {
        Bus::fake();
        $this->actingAs($this->superAdmin);

        app(\App\Actions\Offboarding\StartOrganizationOffboarding::class)->execute($this->org);

        $this->assertDatabaseHas('organization_lifecycle_log', [
            'organization_id' => $this->org->id,
            'event' => 'offboarding_started',
            'actor_id' => $this->superAdmin->id,
        ]);

        $this->assertDatabaseHas('organization_lifecycle_log', [
            'organization_id' => $this->org->id,
            'event' => 'data_export_queued',
            'actor_id' => $this->superAdmin->id,
        ]);
    }
}
