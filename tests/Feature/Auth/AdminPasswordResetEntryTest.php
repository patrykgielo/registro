<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\EmailSend;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * ClickUp 86cbb28m7 — the panel login screens had no way into the password-reset flow.
 *
 * The flow itself (routes/web.php password.*) was always role-agnostic; only the entry point was
 * missing, so a tenant admin who forgot the password phoned the owner. The link goes into the
 * EXISTING flow, not Filament's own passwordReset(), so the mail keeps going through EmailTemplate.
 *
 * Real ResolveTenant, real hosts, real e-mail row — nothing here is a middleware double, because a
 * double never calls URL::forceRootUrl(), which is what decides the host of the link.
 */
class AdminPasswordResetEntryTest extends TestCase
{
    use RefreshDatabase;

    private const ROOT = 'http://registro.local';

    private const TENANT = 'http://demo.registro.local';

    private const NEW_PASSWORD = 'NoweHaslo123!';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.domain' => 'registro.local']);
        $this->withoutMiddleware([ThrottleRequests::class]);
    }

    private function tenant(): Organization
    {
        return Organization::create([
            'name' => 'Demo Rental',
            'slug' => 'demo',
            'booking_type' => 'item_rental',
            'owner_id' => User::factory()->create()->id,
        ]);
    }

    private function tenantAdmin(Organization $org): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $admin->organizations()->attach($org->id, ['role' => 'owner']);

        return $admin;
    }

    /**
     * The tokenised URL exactly as the recipient would click it, from the stored e-mail.
     */
    private function resetUrlFromEmail(User $user, string $host): string
    {
        $send = EmailSend::withoutGlobalScope('organization')
            ->where('recipient_email', $user->email)
            ->where('template_key', 'password-reset')
            ->latest('id')
            ->first();

        $this->assertNotNull($send, 'no password-reset row in email_sends — the mail bypassed EmailService');

        $this->assertSame(1, preg_match(
            '~'.preg_quote($host, '~').'/password/reset/[A-Za-z0-9]+\?email=[^\s"\'<]+~',
            html_entity_decode((string) $send->body_html),
            $match
        ), 'the e-mail carries no reset link on '.$host);

        return $match[0];
    }

    public function test_the_tenant_admin_login_screen_links_to_the_reset_flow_on_the_tenant_host(): void
    {
        $this->tenant();

        $this->get(self::TENANT.'/admin/login')
            ->assertOk()
            ->assertSee('href="'.self::TENANT.'/password/reset"', false)
            ->assertSee(__('account.login.forgot'));
    }

    public function test_the_platform_login_screen_links_to_the_reset_flow_on_the_root_host(): void
    {
        $this->get(self::ROOT.'/platform/login')
            ->assertOk()
            ->assertSee('href="'.self::ROOT.'/password/reset"', false)
            ->assertSee(__('account.login.forgot'));
    }

    public function test_the_whole_reset_for_a_tenant_admin_ends_in_their_panel(): void
    {
        $org = $this->tenant();
        $admin = $this->tenantAdmin($org);

        // 1. the link on the login screen opens the request form
        $this->get(self::TENANT.'/admin/login')->assertSee('/password/reset', false);
        $this->get(self::TENANT.'/password/reset')->assertOk();

        // 2. request -> the mail goes through our pipeline, link on the tenant's own host
        $this->post(self::TENANT.'/password/email', ['email' => $admin->email])->assertRedirect();
        $url = $this->resetUrlFromEmail($admin, self::TENANT);

        // 3. the tokenised URL opens the form, the new password is accepted
        $this->get($url)->assertOk();
        $token = explode('?', basename($url))[0];

        $response = $this->post(self::TENANT.'/password/reset', [
            'token' => $token,
            'email' => $admin->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ]);

        // 4. ...and the admin lands in /admin, signed in, on the tenant host
        $response->assertRedirect('/admin');
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $admin->fresh()->password));
        $this->assertAuthenticatedAs($admin);
        $this->get(self::TENANT.'/admin')->assertOk();
    }

    public function test_the_whole_reset_for_a_super_admin_ends_in_the_platform_panel(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super-admin');

        $this->get(self::ROOT.'/password/reset')->assertOk();
        $this->post(self::ROOT.'/password/email', ['email' => $superAdmin->email])->assertRedirect();
        $url = $this->resetUrlFromEmail($superAdmin, self::ROOT);

        $this->get($url)->assertOk();

        $this->post(self::ROOT.'/password/reset', [
            'token' => explode('?', basename($url))[0],
            'email' => $superAdmin->email,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertRedirect('/platform');

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $superAdmin->fresh()->password));
        $this->get(self::ROOT.'/platform')->assertOk();
    }
}
