<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Organization;
use App\Models\User;
use App\Notifications\PasswordResetNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * POST /password/email must not say whether an address has an account. users.email is globally
 * unique and User has no tenant scope, so the stock answers ("no such user" vs "sent" vs
 * "throttled") let any tenant host — and the /admin and /platform login screens that link here —
 * probe the whole platform, admin and super-admin addresses included.
 */
class PasswordResetEnumerationTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'http://demo.registro.local';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.domain' => 'registro.local']);
        $this->withoutMiddleware([ThrottleRequests::class]);

        Organization::create([
            'name' => 'Demo Rental',
            'slug' => 'demo',
            'booking_type' => 'item_rental',
            'owner_id' => User::factory()->create()->id,
        ]);
    }

    /**
     * @return array{int, ?string, mixed, mixed}
     */
    private function observe(string $email): array
    {
        $response = $this->from(self::HOST.'/password/reset')->post(self::HOST.'/password/email', ['email' => $email]);

        return [
            $response->getStatusCode(),
            $response->headers->get('Location'),
            session('status'),
            session('errors')?->getBag('default')->all(),
        ];
    }

    public function test_existing_unknown_and_throttled_addresses_are_indistinguishable(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        Notification::fake();

        $existing = $this->observe($admin->email);
        $again = $this->observe($admin->email); // the broker's per-account cooldown: "throttled"
        $unknown = $this->observe('nobody-'.uniqid().'@example.test');

        $this->assertSame(302, $existing[0]);
        $this->assertSame($existing, $again, 'a throttled repeat must look like the first request');
        $this->assertSame($existing, $unknown, 'an unknown address must look like an existing one');
        $this->assertSame(__('passwords.link_requested'), $existing[2]);
        $this->assertEmpty($existing[3], 'no validation error may leak the lookup result');
    }

    public function test_the_json_answer_is_identical_too(): void
    {
        $user = User::factory()->create();
        Notification::fake();

        $known = $this->postJson(self::HOST.'/password/email', ['email' => $user->email]);
        $unknown = $this->postJson(self::HOST.'/password/email', ['email' => 'nobody@example.test']);

        $this->assertSame(200, $known->getStatusCode());
        $this->assertSame($known->getStatusCode(), $unknown->getStatusCode());
        $this->assertSame($known->getContent(), $unknown->getContent());
    }

    public function test_the_generic_answer_is_translated_and_the_mail_still_goes_only_to_real_accounts(): void
    {
        $user = User::factory()->create();
        Notification::fake();

        app()->setLocale('en');
        $this->assertStringStartsWith('If an account exists', (string) $this->observe('ghost@example.test')[2]);
        app()->setLocale('pl');
        $this->assertStringStartsWith('Jeśli konto', (string) $this->observe('ghost@example.test')[2]);

        $this->post(self::HOST.'/password/email', ['email' => $user->email]);

        Notification::assertSentTo($user, PasswordResetNotification::class);
        Notification::assertCount(1);
    }
}
