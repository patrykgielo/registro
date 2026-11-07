<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\PasswordResetNotification;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * SuperAdminPasswordResetTest on a DEDICATED tenant stack (TENANT_SLUG set).
 *
 * There PlatformPanelProvider is not registered (bootstrap/providers.php), so
 * Filament::getPanel('platform') is null and the redirect target "the platform
 * login" does not exist. The first version of the super-admin fix called
 * ->getLoginUrl() on that null: the password was changed and the user got a 500.
 * The no-sign-in rule must hold here too, with the admin panel login as the target.
 *
 * Provider registration happens once at boot, so TENANT_SLUG has to be in the process
 * environment BEFORE parent::setUp() — same mechanism as TenantSlugGatingTest.
 * TENANT_HOSTS is read per request, so config() is enough for it.
 */
class SuperAdminPasswordResetPinnedStackTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://acme.pl';

    private const NEW_PASSWORD = 'NoweHaslo123!';

    protected function setUp(): void
    {
        putenv('TENANT_SLUG=acme');
        $_ENV['TENANT_SLUG'] = 'acme';
        $_SERVER['TENANT_SLUG'] = 'acme';

        parent::setUp();

        config(['app.tenant_hosts' => ['acme.pl']]);
        Cache::flush();
        $this->withoutMiddleware([ThrottleRequests::class]);

        Organization::create([
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

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function resetFor(User $user): \Illuminate\Testing\TestResponse
    {
        Notification::fake();
        $this->post(self::HOST.'/password/email', ['email' => $user->email])->assertStatus(302);

        $token = null;
        Notification::assertSentTo($user, PasswordResetNotification::class,
            function (PasswordResetNotification $notification) use (&$token) {
                $token = $notification->token;

                return true;
            });

        return $this->post(self::HOST.'/password/reset', [
            'token' => $token,
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ]);
    }

    public function test_the_fixture_is_really_a_dedicated_stack_without_the_platform_panel(): void
    {
        // If this fails the tests below prove nothing: they would be running the shared-stack path.
        $this->assertSame('acme', config('app.tenant_slug'));
        $this->assertArrayNotHasKey('platform', Filament::getPanels());
        $this->assertArrayHasKey('admin', Filament::getPanels());
    }

    public function test_a_super_admin_reset_on_a_dedicated_stack_does_not_error_and_does_not_sign_in(): void
    {
        $super = $this->userWithRole('super-admin');

        $response = $this->resetFor($super);

        $this->assertNotSame(500, $response->getStatusCode(), 'the reset blew up after changing the password');
        $response->assertRedirect(self::HOST.'/admin/login');
        $this->assertGuest();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $super->fresh()->password));
    }

    public function test_the_dedicated_stack_super_admin_reset_is_audited_and_shows_the_message(): void
    {
        $super = $this->userWithRole('super-admin');
        $this->resetFor($super);

        $rows = AuditLog::withoutGlobalScope('organization')
            ->where('user_id', $super->id)
            ->where('event', AuditLog::EVENT_PASSWORD_RESET)
            ->get();
        $this->assertCount(1, $rows);
        $this->assertFalse($rows->first()->new_values['signed_in']);

        $this->get(self::HOST.'/admin/login')
            ->assertOk()
            ->assertSee(__('passwords.reset_sign_in'));
    }

    public function test_a_customer_reset_on_a_dedicated_stack_is_unchanged(): void
    {
        $customer = $this->userWithRole('customer');

        $response = $this->resetFor($customer);

        $this->assertAuthenticatedAs($customer);
        $this->assertStringNotContainsString('/login', (string) $response->headers->get('Location'));
    }
}
