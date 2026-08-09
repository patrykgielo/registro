<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Events\AdminCreatedUser;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ResendPasswordSetupLinkCommandTest extends TestCase
{
    use RefreshDatabase;

    private function runCommand(string $email, array $options = []): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('registro:password-setup-link', array_merge([
            'email' => $email,
        ], $options));
    }

    public function test_it_generates_a_new_token_for_a_passwordless_account(): void
    {
        $user = User::factory()->create(['email' => 'owner@acme.test', 'password' => null]);

        $this->runCommand('owner@acme.test')->assertSuccessful();

        $user->refresh();
        $this->assertNotNull($user->password_setup_token);
        $this->assertNotNull($user->password_setup_expires_at);
    }

    public function test_it_dispatches_the_admin_created_user_event_by_default(): void
    {
        Event::fake();

        $user = User::factory()->create(['email' => 'owner@acme.test', 'password' => null]);

        $this->runCommand('owner@acme.test')->assertSuccessful();

        Event::assertDispatched(AdminCreatedUser::class, fn (AdminCreatedUser $event) => $event->user->is($user));
    }

    public function test_no_email_flag_skips_the_dispatch(): void
    {
        Event::fake();

        User::factory()->create(['email' => 'owner@acme.test', 'password' => null]);

        $this->runCommand('owner@acme.test', ['--no-email' => true])->assertSuccessful();

        Event::assertNotDispatched(AdminCreatedUser::class);
    }

    public function test_it_fails_for_an_unknown_email(): void
    {
        $this->runCommand('nobody@example.test')->assertFailed();
    }

    public function test_it_refuses_an_account_that_already_has_a_password_without_force(): void
    {
        $user = User::factory()->create(['email' => 'owner@acme.test', 'password' => 'irrelevant-hash']);
        $originalToken = $user->password_setup_token;

        $this->runCommand('owner@acme.test')->assertFailed();

        $user->refresh();
        $this->assertSame($originalToken, $user->password_setup_token);
    }

    public function test_force_allows_generating_a_link_for_an_account_that_already_has_a_password(): void
    {
        $user = User::factory()->create(['email' => 'owner@acme.test', 'password' => 'irrelevant-hash']);

        $this->runCommand('owner@acme.test', ['--force' => true])->assertSuccessful();

        $this->assertNotNull($user->fresh()->password_setup_token);
    }

    public function test_the_printed_link_contains_the_generated_token(): void
    {
        $user = User::factory()->create(['email' => 'owner@acme.test', 'password' => null]);

        \Artisan::call('registro:password-setup-link', ['email' => 'owner@acme.test']);
        $output = \Artisan::output();

        $token = $user->fresh()->password_setup_token;
        $this->assertNotNull($token);
        $this->assertStringContainsString(route('password.setup', ['token' => $token]), $output);
    }

    public function test_it_shows_the_owners_organizations(): void
    {
        $user = User::factory()->create(['email' => 'owner@acme.test', 'password' => null]);
        $org = Organization::factory()->equipmentRental()->create(['name' => 'Acme Rentals']);
        $org->members()->attach($user);

        $this->runCommand('owner@acme.test')
            ->expectsOutputToContain('Acme Rentals')
            ->assertSuccessful();
    }
}
