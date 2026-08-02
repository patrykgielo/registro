<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The point of registro:create-owner is that its success message can be trusted.
 * `make:filament-user` reported success while creating an account that could not
 * log in anywhere, so these tests assert the end state -- roles, name fields,
 * panel access -- rather than the exit code alone.
 */
class CreateOwnerCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'a-sufficiently-long-password';

    private function runCommand(array $options = []): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('registro:create-owner', array_merge([
            '--first-name' => 'Anna',
            '--last-name' => 'Kowalska',
            '--email' => 'anna@example.test',
            '--password' => self::PASSWORD,
        ], $options));
    }

    public function test_it_seeds_roles_when_the_database_has_none(): void
    {
        // Tests\TestCase::setUp() seeds RolePermissionSeeder for every test, so it
        // has to be undone here to reproduce the case this command exists for: a
        // fresh production install, where nothing has ever run that seeder.
        \DB::table('role_has_permissions')->delete();
        \DB::table('model_has_roles')->delete();
        \DB::table('model_has_permissions')->delete();
        Role::query()->delete();
        Permission::query()->delete();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertSame(0, Role::count(), 'precondition: a fresh install has no roles');

        $this->runCommand()->assertSuccessful();

        $this->assertTrue(Role::where('name', 'super-admin')->exists());
        $this->assertGreaterThan(0, Permission::count());
    }

    public function test_it_creates_an_owner_that_can_actually_reach_the_platform_panel(): void
    {
        $this->runCommand()->assertSuccessful();

        $user = User::where('email', 'anna@example.test')->firstOrFail();

        $this->assertTrue($user->hasRole('super-admin'));
        $this->assertTrue($user->canAccessPanel(\Filament\Facades\Filament::getPanel('platform')));
    }

    /**
     * The specific failure of make:filament-user: it writes `name`, which this
     * schema has only as an accessor, so the account ends up nameless.
     */
    public function test_it_populates_the_name_columns_that_actually_exist(): void
    {
        $this->runCommand()->assertSuccessful();

        $user = User::where('email', 'anna@example.test')->firstOrFail();

        $this->assertSame('Anna', $user->first_name);
        $this->assertSame('Kowalska', $user->last_name);
        $this->assertSame('Anna Kowalska', $user->name);
    }

    public function test_it_marks_the_owner_verified_so_the_panel_does_not_lock_them_out(): void
    {
        $this->runCommand()->assertSuccessful();

        $this->assertNotNull(User::where('email', 'anna@example.test')->firstOrFail()->email_verified_at);
    }

    public function test_the_password_is_hashed_and_usable(): void
    {
        $this->runCommand()->assertSuccessful();

        $user = User::where('email', 'anna@example.test')->firstOrFail();

        $this->assertNotSame(self::PASSWORD, $user->password);
        $this->assertTrue(\Hash::check(self::PASSWORD, $user->password));
    }

    public function test_it_refuses_to_touch_an_existing_account_without_force(): void
    {
        $existing = User::factory()->create([
            'email' => 'anna@example.test',
            'first_name' => 'Original',
        ]);

        $this->runCommand()
            ->expectsConfirmation('Update this account?', 'no')
            ->assertFailed();

        $this->assertSame('Original', $existing->fresh()->first_name);
    }

    public function test_force_updates_an_existing_account_and_grants_the_role(): void
    {
        $existing = User::factory()->create([
            'email' => 'anna@example.test',
            'first_name' => 'Original',
        ]);

        $this->runCommand(['--force' => true])->assertSuccessful();

        $existing->refresh();
        $this->assertSame('Anna', $existing->first_name);
        $this->assertTrue($existing->hasRole('super-admin'));
    }

    public function test_it_is_idempotent(): void
    {
        $this->runCommand()->assertSuccessful();
        $this->runCommand(['--force' => true])->assertSuccessful();

        $this->assertSame(1, User::where('email', 'anna@example.test')->count());
        $this->assertSame(
            1,
            User::where('email', 'anna@example.test')->firstOrFail()->roles()->where('name', 'super-admin')->count(),
        );
    }

    public function test_it_rejects_a_short_password(): void
    {
        $this->runCommand(['--password' => 'short'])->assertFailed();

        $this->assertNull(User::where('email', 'anna@example.test')->first());
    }

    public function test_it_rejects_an_invalid_email(): void
    {
        $this->runCommand(['--email' => 'not-an-email'])->assertFailed();

        $this->assertSame(0, User::count());
    }

    /**
     * The hardcoded grant that used to live in RolePermissionSeeder was a latent
     * privilege escalation: anyone registering that address became the owner on
     * the next seed.
     */
    public function test_the_seeder_no_longer_grants_super_admin_to_a_hardcoded_address(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.com']);

        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $this->assertFalse($user->fresh()->hasRole('super-admin'));
    }
}
