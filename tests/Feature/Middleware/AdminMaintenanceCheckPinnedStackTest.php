<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use App\Enums\MaintenanceType;
use App\Filament\Pages\MaintenanceSettings;
use App\Http\Middleware\AdminMaintenanceCheck;
use App\Models\Organization;
use App\Models\User;
use App\Services\MaintenanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * AdminMaintenanceCheck on a DEDICATED tenant stack (TENANT_SLUG set).
 *
 * A gate on `super-admin` is a dead gate there: the stack has its own database and no super-admin.
 * MaintenanceSettings lets the tenant admin switch maintenance on, so the panel middleware must
 * let that same admin back in to switch it off; before, the CLI was the only way out.
 *
 * TENANT_SLUG must be in the process environment BEFORE parent::setUp() (same mechanism as
 * TenantSlugGatingTest). The shared-stack counterpart is AdminMaintenanceCheckTest.
 */
class AdminMaintenanceCheckPinnedStackTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://acme.pl';

    private Organization $org;

    protected function setUp(): void
    {
        putenv('TENANT_SLUG=acme');
        $_ENV['TENANT_SLUG'] = 'acme';
        $_SERVER['TENANT_SLUG'] = 'acme';

        parent::setUp();

        config(['app.tenant_hosts' => ['acme.pl']]);
        Cache::flush();
        $this->withoutMiddleware([ThrottleRequests::class]);

        $this->org = Organization::create([
            'name' => 'Acme Rentals',
            'slug' => 'acme',
            'booking_type' => 'item_rental',
            'owner_id' => User::factory()->create()->id,
        ]);
    }

    protected function tearDown(): void
    {
        putenv('TENANT_SLUG');
        unset($_ENV['TENANT_SLUG'], $_SERVER['TENANT_SLUG']);

        parent::tearDown();
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        $user->organizations()->attach($this->org->id, ['role' => $role]);

        return $user;
    }

    private function maintenance(): MaintenanceService
    {
        return app(MaintenanceService::class);
    }

    private function runMiddleware(User $user): Response
    {
        $this->actingAs($user);

        return app(AdminMaintenanceCheck::class)->handle(
            Request::create('/admin'),
            fn () => new Response('panel')
        );
    }

    public function test_the_fixture_is_really_a_dedicated_stack(): void
    {
        // If this fails the tests below prove nothing: they would be running the shared-stack path.
        $this->assertSame('acme', config('app.tenant_slug'));
    }

    public function test_the_tenant_admin_keeps_panel_access_in_every_maintenance_type(): void
    {
        foreach (MaintenanceType::cases() as $type) {
            $this->maintenance()->enable($type);

            $this->assertSame(
                'panel',
                $this->runMiddleware($this->user('admin'))->getContent(),
                "admin was locked out of the panel during {$type->value}"
            );
        }
    }

    public function test_super_admin_keeps_panel_access_too(): void
    {
        $this->maintenance()->enable(MaintenanceType::DEPLOYMENT);

        $this->assertSame('panel', $this->runMiddleware($this->user('super-admin'))->getContent());
    }

    public function test_staff_are_blocked_with_a_real_503_page(): void
    {
        $staff = $this->user('staff');
        $this->maintenance()->enable(MaintenanceType::DEPLOYMENT);

        $this->actingAs($staff)
            ->get(self::HOST.'/admin')
            ->assertStatus(503)
            ->assertSee('Panel w trybie konserwacji');
    }

    /**
     * End to end through the real panel stack: enable -> staff blocked, admin still in -> disable.
     */
    public function test_an_admin_who_enables_maintenance_can_still_open_the_panel_and_disable_it(): void
    {
        $admin = $this->user('admin');
        $staff = $this->user('staff');

        $this->actingAs($admin);
        Livewire::test(MaintenanceSettings::class)
            ->fillForm(['type' => MaintenanceType::DEPLOYMENT->value])
            ->call('enableMaintenance');

        $this->assertTrue($this->maintenance()->isActive());

        $this->actingAs($admin)
            ->get(self::HOST.'/admin/maintenance-settings')
            ->assertOk();

        $this->actingAs($staff)
            ->get(self::HOST.'/admin/maintenance-settings')
            ->assertStatus(503);

        $this->actingAs($admin);
        Livewire::test(MaintenanceSettings::class)->call('disableMaintenance');

        $this->assertFalse($this->maintenance()->isActive());
    }
}
