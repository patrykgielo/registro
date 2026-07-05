<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Filament\Platform\Pages\PlatformSettings;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings\SettingsManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PlatformSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->assignRole('super-admin');

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        Cache::flush();
    }

    public function test_super_admin_can_access(): void
    {
        $this->actingAs($this->superAdmin);

        $this->assertTrue(PlatformSettings::canAccess());
    }

    public function test_non_super_admin_cannot_access(): void
    {
        $this->actingAs($this->admin);

        $this->assertFalse(PlatformSettings::canAccess());
    }

    public function test_save_persists_closure_request_email(): void
    {
        $this->actingAs($this->superAdmin);

        // Seed initial value
        Setting::updateOrCreate(
            ['group' => 'account', 'key' => 'closure_request_email', 'organization_id' => null],
            ['value' => ['initial@example.com']]
        );
        Cache::flush();

        // setGlobal() is the exact path PlatformSettings uses.
        app(SettingsManager::class)->setGlobal('account.closure_request_email', 'updated@example.com');

        $this->assertSame('updated@example.com', app(SettingsManager::class)->getGlobal('account.closure_request_email'));
        $this->assertSame('updated@example.com', app(SettingsManager::class)->closureRequestEmail());
    }

    public function test_settings_manager_returns_new_email_after_set(): void
    {
        $this->actingAs($this->superAdmin);

        app(SettingsManager::class)->setGlobal('account.closure_request_email', 'new@registro.app');

        $this->assertSame('new@registro.app', app(SettingsManager::class)->closureRequestEmail());
    }

    public function test_save_writes_global_setting_not_tenant_scoped(): void
    {
        $this->actingAs($this->superAdmin);

        app(SettingsManager::class)->setGlobal('account.closure_request_email', 'global@test.com');

        $setting = Setting::withoutGlobalScope('organization')
            ->where('group', 'account')
            ->where('key', 'closure_request_email')
            ->whereNull('organization_id')
            ->first();

        $this->assertNotNull($setting, 'Setting must be stored with organization_id = null (global).');
    }

    public function test_set_global_ignores_stale_session_tenant_id(): void
    {
        $this->actingAs($this->superAdmin);

        // Simulate a super-admin who previously visited a tenant subdomain:
        // ResolveTenant left tenant_id in the shared session and never cleared it.
        $org = \App\Models\Organization::factory()->create();
        session(['tenant_id' => $org->id]);

        app(SettingsManager::class)->setGlobal('account.closure_request_email', 'global@test.com');

        // MUST land on the global row (organization_id = null), not scoped to the stale tenant.
        $this->assertDatabaseHas('settings', [
            'group' => 'account',
            'key' => 'closure_request_email',
            'organization_id' => null,
        ]);
        $this->assertDatabaseMissing('settings', [
            'group' => 'account',
            'key' => 'closure_request_email',
            'organization_id' => $org->id,
        ]);

        // And getGlobal() reads it back regardless of the stale session.
        $this->assertSame('global@test.com', app(SettingsManager::class)->getGlobal('account.closure_request_email'));
    }

    public function test_platform_settings_page_navigation_is_registered(): void
    {
        $this->assertSame('Ustawienia platformy', PlatformSettings::getNavigationLabel());
    }
}
